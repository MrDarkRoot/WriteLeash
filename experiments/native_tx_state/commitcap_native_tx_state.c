#include "postgres.h"

#include "access/htup_details.h"
#include "access/xact.h"
#include "commands/trigger.h"
#include "fmgr.h"
#include "funcapi.h"
#include "miscadmin.h"
#include "utils/memutils.h"

PG_MODULE_MAGIC;

#define COMMITCAP_EXPERIMENT_BUDGET 5

typedef struct ConsumptionFrame
{
    SubTransactionId subid;
    uint64      delta;
    struct ConsumptionFrame *next;
} ConsumptionFrame;

typedef struct ExperimentState
{
    bool        active;
    bool        denied;
    uint64      consumed;
    ConsumptionFrame *frames;
} ExperimentState;

static ExperimentState state = {0};

void        _PG_init(void);
void        _PG_fini(void);

PG_FUNCTION_INFO_V1(commitcap_native_enforce_update_budget);
PG_FUNCTION_INFO_V1(commitcap_native_probe);

static ConsumptionFrame *find_frame(SubTransactionId subid);
static ConsumptionFrame *ensure_frame(SubTransactionId subid);
static void remove_frame(SubTransactionId subid);
static void reset_state(void);
static void xact_callback(XactEvent event, void *arg);
static void subxact_callback(SubXactEvent event, SubTransactionId mySubid,
                             SubTransactionId parentSubid, void *arg);

void
_PG_init(void)
{
    RegisterXactCallback(xact_callback, NULL);
    RegisterSubXactCallback(subxact_callback, NULL);
}

void
_PG_fini(void)
{
    UnregisterSubXactCallback(subxact_callback, NULL);
    UnregisterXactCallback(xact_callback, NULL);
}

Datum
commitcap_native_enforce_update_budget(PG_FUNCTION_ARGS)
{
    TriggerData *trigger_data;
    ConsumptionFrame *frame;

    if (!CALLED_AS_TRIGGER(fcinfo))
        ereport(ERROR,
                (errcode(ERRCODE_E_R_I_E_TRIGGER_PROTOCOL_VIOLATED),
                 errmsg("CommitCap native experiment must be called as a trigger")));

    trigger_data = (TriggerData *) fcinfo->context;
    if (!TRIGGER_FIRED_BEFORE(trigger_data->tg_event) ||
        !TRIGGER_FIRED_FOR_ROW(trigger_data->tg_event) ||
        !TRIGGER_FIRED_BY_UPDATE(trigger_data->tg_event))
        ereport(ERROR,
                (errcode(ERRCODE_E_R_I_E_TRIGGER_PROTOCOL_VIOLATED),
                 errmsg("CommitCap native experiment requires a BEFORE UPDATE row trigger")));

    if (!state.active)
    {
        state.active = true;
        state.denied = false;
        state.consumed = 0;
        state.frames = NULL;
    }

    if (state.denied)
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap top-level transaction already denied")));

    if (state.consumed >= COMMITCAP_EXPERIMENT_BUDGET)
    {
        state.denied = true;
        elog(LOG,
             "commitcap_native_tx_state denial consumed=" UINT64_FORMAT
             " attempted=" UINT64_FORMAT,
             state.consumed, state.consumed + 1);
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap mutation budget exceeded (limit %d, attempted " UINT64_FORMAT ")",
                        COMMITCAP_EXPERIMENT_BUDGET, state.consumed + 1)));
    }

    state.consumed++;
    if (IsSubTransaction())
    {
        frame = ensure_frame(GetCurrentSubTransactionId());
        frame->delta++;
    }

    elog(LOG,
         "commitcap_native_tx_state event pid=%d consumed=" UINT64_FORMAT
         " denied=%s",
         MyProcPid, state.consumed, state.denied ? "true" : "false");

    PG_RETURN_POINTER(trigger_data->tg_newtuple);
}

/*
 * Read-only experiment instrumentation. This exposes only backend-local
 * counters to the caller's own session; it cannot modify enforcement state.
 */
Datum
commitcap_native_probe(PG_FUNCTION_ARGS)
{
    TupleDesc   tupdesc;
    Datum       values[4];
    bool        nulls[4] = {false, false, false, false};
    HeapTuple   tuple;

    if (get_call_result_type(fcinfo, NULL, &tupdesc) != TYPEFUNC_COMPOSITE)
        ereport(ERROR,
                (errcode(ERRCODE_FEATURE_NOT_SUPPORTED),
                 errmsg("function returning record called in context that cannot accept type record")));

    values[0] = BoolGetDatum(state.active);
    values[1] = Int64GetDatum((int64) state.consumed);
    values[2] = BoolGetDatum(state.denied);
    values[3] = Int32GetDatum((int32) MyProcPid);

    tuple = heap_form_tuple(tupdesc, values, nulls);

    PG_RETURN_DATUM(HeapTupleGetDatum(tuple));
}

static ConsumptionFrame *
find_frame(SubTransactionId subid)
{
    ConsumptionFrame *frame;

    for (frame = state.frames; frame != NULL; frame = frame->next)
    {
        if (frame->subid == subid)
            return frame;
    }

    return NULL;
}

static ConsumptionFrame *
ensure_frame(SubTransactionId subid)
{
    ConsumptionFrame *frame;
    MemoryContext old_context;

    frame = find_frame(subid);
    if (frame != NULL)
        return frame;

    old_context = MemoryContextSwitchTo(TopMemoryContext);
    frame = palloc0(sizeof(*frame));
    MemoryContextSwitchTo(old_context);

    frame->subid = subid;
    frame->next = state.frames;
    state.frames = frame;
    return frame;
}

static void
remove_frame(SubTransactionId subid)
{
    ConsumptionFrame **link;

    for (link = &state.frames; *link != NULL; link = &(*link)->next)
    {
        ConsumptionFrame *frame = *link;

        if (frame->subid == subid)
        {
            *link = frame->next;
            pfree(frame);
            return;
        }
    }
}

static void
reset_state(void)
{
    ConsumptionFrame *frame = state.frames;

    while (frame != NULL)
    {
        ConsumptionFrame *next = frame->next;

        pfree(frame);
        frame = next;
    }

    state.active = false;
    state.denied = false;
    state.consumed = 0;
    state.frames = NULL;
}

static void
xact_callback(XactEvent event, void *arg)
{
    (void) arg;

    if (!state.active)
        return;

    switch (event)
    {
        case XACT_EVENT_PRE_COMMIT:
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=XACT_PRE_COMMIT consumed=" UINT64_FORMAT
                 " denied=%s",
                 state.consumed, state.denied ? "true" : "false");
            if (state.denied)
                ereport(ERROR,
                        (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                         errmsg("CommitCap top-level transaction denied after mutation budget violation")));
            break;

        case XACT_EVENT_COMMIT:
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=XACT_COMMIT consumed=" UINT64_FORMAT
                 " denied=%s",
                 state.consumed, state.denied ? "true" : "false");
            reset_state();
            break;

        case XACT_EVENT_ABORT:
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=XACT_ABORT consumed=" UINT64_FORMAT
                 " denied=%s",
                 state.consumed, state.denied ? "true" : "false");
            reset_state();
            break;

        default:
            break;
    }
}

static void
subxact_callback(SubXactEvent event, SubTransactionId mySubid,
                 SubTransactionId parentSubid, void *arg)
{
    ConsumptionFrame *frame;

    (void) arg;

    if (!state.active)
        return;

    switch (event)
    {
        case SUBXACT_EVENT_START_SUB:
            ensure_frame(mySubid);
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=SUBXACT_START subid=%u parent=%u consumed=" UINT64_FORMAT
                 " denied=%s",
                 mySubid, parentSubid, state.consumed,
                 state.denied ? "true" : "false");
            break;

        case SUBXACT_EVENT_PRE_COMMIT_SUB:
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=SUBXACT_PRE_COMMIT subid=%u parent=%u consumed=" UINT64_FORMAT
                 " denied=%s",
                 mySubid, parentSubid, state.consumed,
                 state.denied ? "true" : "false");
            break;

        case SUBXACT_EVENT_COMMIT_SUB:
            frame = find_frame(mySubid);
            if (frame != NULL && GetCurrentTransactionNestLevel() > 2)
                ensure_frame(parentSubid)->delta += frame->delta;
            remove_frame(mySubid);
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=SUBXACT_COMMIT subid=%u parent=%u consumed=" UINT64_FORMAT
                 " denied=%s",
                 mySubid, parentSubid, state.consumed,
                 state.denied ? "true" : "false");
            break;

        case SUBXACT_EVENT_ABORT_SUB:
            frame = find_frame(mySubid);
            if (frame != NULL)
            {
                Assert(state.consumed >= frame->delta);
                state.consumed -= frame->delta;
                remove_frame(mySubid);
            }
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=SUBXACT_ABORT subid=%u parent=%u consumed=" UINT64_FORMAT
                 " denied=%s",
                 mySubid, parentSubid, state.consumed,
                 state.denied ? "true" : "false");
            break;
    }
}
