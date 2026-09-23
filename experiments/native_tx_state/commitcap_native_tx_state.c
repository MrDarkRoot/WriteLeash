#include "postgres.h"

#include "access/htup_details.h"
#include "access/xact.h"
#include "catalog/pg_type.h"
#include "commands/trigger.h"
#include "fmgr.h"
#include "funcapi.h"
#include "miscadmin.h"
#include "utils/builtins.h"
#include "utils/guc.h"
#include "utils/memutils.h"

PG_MODULE_MAGIC;

/*
 * Test-only configuration for the Phase 0 experiment. Both parameters are
 * PGC_SUSET, so only a superuser (or a role explicitly granted SET on the
 * parameter) can change them, and only a superuser can store them for the
 * protected writer with ALTER ROLE. They are not a product interface.
 *
 * The budget uses PostgreSQL's 32-bit custom integer GUC type. Counters are
 * uint64, so a budget at the documented maximum cannot overflow the
 * check-before-increment comparison or the attempted-count diagnostic
 * (which is at most INT_MAX + 1).
 */
#define COMMITCAP_EXPERIMENT_DEFAULT_BUDGET 5
#define COMMITCAP_EXPERIMENT_MAX_BUDGET INT_MAX

/*
 * Test-only state-transition rule. Any UPDATE of a text/varchar column named
 * "role" whose new value is this role is denied. This is the smallest
 * representation needed to prove the CC-020/CC-021/CC-022 semantics; it is
 * not a policy language and it is not a product interface.
 */
#define COMMITCAP_EXPERIMENT_DENIED_ROLE "admin"

static int  commitcap_experiment_budget = COMMITCAP_EXPERIMENT_DEFAULT_BUDGET;
static int  commitcap_experiment_seed_consumed = -1;

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
    uint64      budget;
    ConsumptionFrame *frames;
} ExperimentState;

static ExperimentState state = {0};

void        _PG_init(void);
void        _PG_fini(void);

PG_FUNCTION_INFO_V1(commitcap_native_enforce_update_budget);
PG_FUNCTION_INFO_V1(commitcap_native_enforce_role_transition);
PG_FUNCTION_INFO_V1(commitcap_native_probe);

static ConsumptionFrame *find_frame(SubTransactionId subid);
static ConsumptionFrame *ensure_frame(SubTransactionId subid);
static void remove_frame(SubTransactionId subid);
static void reset_state(void);
static void activate_state(void);
static void record_protected_event(void);
static bool forbidden_role_transition(TriggerData *trigger_data, char **new_role);
static void xact_callback(XactEvent event, void *arg);
static void subxact_callback(SubXactEvent event, SubTransactionId mySubid,
                             SubTransactionId parentSubid, void *arg);

void
_PG_init(void)
{
    DefineCustomIntVariable("commitcap_native.test_budget",
                            "Test-only CommitCap experiment budget.",
                            "Applies to top-level transactions in this session. "
                            "This is an experimental control, not a product interface.",
                            &commitcap_experiment_budget,
                            COMMITCAP_EXPERIMENT_DEFAULT_BUDGET,
                            0,
                            COMMITCAP_EXPERIMENT_MAX_BUDGET,
                            PGC_SUSET,
                            0,
                            NULL, NULL, NULL);

    DefineCustomIntVariable("commitcap_native.test_seed_consumed",
                            "Test-only initial consumed count for the next CommitCap experiment transaction.",
                            "-1 disables seeding. This is an experimental control, not a product interface.",
                            &commitcap_experiment_seed_consumed,
                            -1,
                            -1,
                            COMMITCAP_EXPERIMENT_MAX_BUDGET,
                            PGC_SUSET,
                            0,
                            NULL, NULL, NULL);

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

    activate_state();
    record_protected_event();

    PG_RETURN_POINTER(trigger_data->tg_newtuple);
}

/*
 * Test-only state-transition enforcement. The trigger fires on the
 * experiment's users table and rejects any row whose new "role" value is the
 * denied role before that row consumes row-update authority. The denial is
 * sticky: it is recorded in backend-local top-level state, survives
 * subtransaction recovery, and rejects commit at XACT_EVENT_PRE_COMMIT.
 */
Datum
commitcap_native_enforce_role_transition(PG_FUNCTION_ARGS)
{
    TriggerData *trigger_data;
    char       *new_role = NULL;

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

    activate_state();

    if (forbidden_role_transition(trigger_data, &new_role))
    {
        state.denied = true;
        elog(LOG,
             "commitcap_native_tx_state transition_denial pid=%d to=%s",
             MyProcPid, new_role);
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap forbidden state transition (* -> %s)",
                        COMMITCAP_EXPERIMENT_DENIED_ROLE)));
    }

    record_protected_event();

    PG_RETURN_POINTER(trigger_data->tg_newtuple);
}

static void
activate_state(void)
{
    if (!state.active)
    {
        /*
         * The budget is captured at transaction start. The test-only seed
         * simulates an already-partly-consumed transaction for boundary
         * testing; -1 disables it. GUC bounds guarantee 0 <= budget <=
         * INT_MAX and -1 <= seed <= INT_MAX, so these casts are safe.
         */
        state.active = true;
        state.denied = false;
        state.consumed = (commitcap_experiment_seed_consumed >= 0)
            ? (uint64) commitcap_experiment_seed_consumed
            : 0;
        state.budget = (uint64) commitcap_experiment_budget;
        state.frames = NULL;
    }
}

static void
record_protected_event(void)
{
    ConsumptionFrame *frame;

    if (state.denied)
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap top-level transaction already denied")));

    /*
     * Check before increment. This comparison cannot overflow, and consumed
     * only increments while it is below the captured budget, so it can never
     * exceed the documented maximum.
     */
    if (state.consumed >= state.budget)
    {
        state.denied = true;
        elog(LOG,
             "commitcap_native_tx_state denial pid=%d consumed=" UINT64_FORMAT
             " attempted=" UINT64_FORMAT " budget=" UINT64_FORMAT,
             MyProcPid, state.consumed, state.consumed + 1, state.budget);
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap mutation budget exceeded (limit " UINT64_FORMAT ", attempted " UINT64_FORMAT ")",
                        state.budget, state.consumed + 1)));
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
}

/*
 * Locate the text/varchar "role" attribute and report whether the new tuple
 * sets it to the denied role. Tables without such a column are outside this
 * test-only rule and are not rejected here.
 */
static bool
forbidden_role_transition(TriggerData *trigger_data, char **new_role)
{
    TupleDesc   tupdesc = trigger_data->tg_relation->rd_att;
    int         attnum = -1;
    Datum       new_datum;
    bool        new_isnull;
    int         i;

    for (i = 0; i < tupdesc->natts; i++)
    {
        Form_pg_attribute attr = TupleDescAttr(tupdesc, i);

        if (!attr->attisdropped &&
            (attr->atttypid == TEXTOID || attr->atttypid == VARCHAROID) &&
            strcmp(NameStr(attr->attname), "role") == 0)
        {
            attnum = attr->attnum;
            break;
        }
    }

    if (attnum < 0)
        return false;

    new_datum = heap_getattr(trigger_data->tg_newtuple, attnum, tupdesc,
                             &new_isnull);
    if (new_isnull)
        return false;

    *new_role = TextDatumGetCString(new_datum);
    return strcmp(*new_role, COMMITCAP_EXPERIMENT_DENIED_ROLE) == 0;
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
                 "commitcap_native_tx_state lifecycle event=XACT_PRE_COMMIT pid=%d consumed=" UINT64_FORMAT
                 " denied=%s",
                 MyProcPid, state.consumed, state.denied ? "true" : "false");
            if (state.denied)
                ereport(ERROR,
                        (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                         errmsg("CommitCap top-level transaction denied after mutation authority violation")));
            break;

        case XACT_EVENT_COMMIT:
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=XACT_COMMIT pid=%d consumed=" UINT64_FORMAT
                 " denied=%s",
                 MyProcPid, state.consumed, state.denied ? "true" : "false");
            reset_state();
            break;

        case XACT_EVENT_ABORT:
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=XACT_ABORT pid=%d consumed=" UINT64_FORMAT
                 " denied=%s",
                 MyProcPid, state.consumed, state.denied ? "true" : "false");
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
                 "commitcap_native_tx_state lifecycle event=SUBXACT_START pid=%d subid=%u parent=%u consumed=" UINT64_FORMAT
                 " denied=%s",
                 MyProcPid, mySubid, parentSubid, state.consumed,
                 state.denied ? "true" : "false");
            break;

        case SUBXACT_EVENT_PRE_COMMIT_SUB:
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=SUBXACT_PRE_COMMIT pid=%d subid=%u parent=%u consumed=" UINT64_FORMAT
                 " denied=%s",
                 MyProcPid, mySubid, parentSubid, state.consumed,
                 state.denied ? "true" : "false");
            break;

        case SUBXACT_EVENT_COMMIT_SUB:
            frame = find_frame(mySubid);
            if (frame != NULL && GetCurrentTransactionNestLevel() > 2)
                ensure_frame(parentSubid)->delta += frame->delta;
            remove_frame(mySubid);
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=SUBXACT_COMMIT pid=%d subid=%u parent=%u consumed=" UINT64_FORMAT
                 " denied=%s",
                 MyProcPid, mySubid, parentSubid, state.consumed,
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
                 "commitcap_native_tx_state lifecycle event=SUBXACT_ABORT pid=%d subid=%u parent=%u consumed=" UINT64_FORMAT
                 " denied=%s",
                 MyProcPid, mySubid, parentSubid, state.consumed,
                 state.denied ? "true" : "false");
            break;
    }
}
