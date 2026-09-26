#include "postgres.h"

#include "access/htup_details.h"
#include "access/xact.h"
#include "catalog/pg_class.h"
#include "catalog/pg_type.h"
#include "commands/trigger.h"
#include "fmgr.h"
#include "funcapi.h"
#include "miscadmin.h"
#include "utils/builtins.h"
#include "utils/guc.h"
#include "utils/lsyscache.h"
#include "utils/memutils.h"
#include "utils/numeric.h"

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
#define COMMITCAP_EXPERIMENT_NUMERIC_BUDGET "100.00"

typedef enum RowPolicy
{
    ROW_SUBSCRIPTIONS,
    ROW_USERS,
    ROW_POLICY_COUNT
} RowPolicy;

/*
 * Why the current top-level transaction was first denied. The sticky-denied
 * bit alone cannot explain the denial to a developer; the kind is backend-local
 * enforcement state and is never writable by the protected writer. It is used
 * only for human-readable error detail, never for an enforcement decision.
 */
typedef enum DenialKind
{
    DENIAL_NONE = 0,
    DENIAL_ROW_SUBSCRIPTIONS,
    DENIAL_ROW_USERS,
    DENIAL_TRANSITION,
    DENIAL_NUMERIC,
    DENIAL_PRODUCT_ROW
} DenialKind;

static int  commitcap_experiment_budget = COMMITCAP_EXPERIMENT_DEFAULT_BUDGET;
static int  commitcap_experiment_seed_consumed = -1;
static char *commitcap_experiment_numeric_budget = NULL;

/* The product policy identity is the relation OID, never the display name or
 * an argument provided by the writer. All nodes live through PRE_COMMIT. */
typedef struct ProductPolicy
{
    Oid         relid;
    Oid         trigger_oid;
    uint64      budget;
    uint64      consumed;
    struct ProductPolicy *next;
} ProductPolicy;

typedef struct ProductDelta
{
    ProductPolicy *policy;
    uint64      consumed;
    struct ProductDelta *next;
} ProductDelta;

typedef struct ConsumptionFrame
{
    SubTransactionId subid;
    uint64      delta[ROW_POLICY_COUNT];
    Numeric     positive_delta;
    ProductDelta *product_deltas;
    struct ConsumptionFrame *next;
} ConsumptionFrame;

typedef struct ExperimentState
{
    bool        active;
    bool        denied;
    DenialKind  denial_kind;
    uint64      consumed[ROW_POLICY_COUNT];
    uint64      budget[ROW_POLICY_COUNT];
    Numeric     positive_delta;
    Numeric     numeric_budget;
    ConsumptionFrame *frames;
    ProductPolicy *product_policies;
    char       *product_denial_metric;
    uint64      product_denial_budget;
    uint64      product_denial_consumed;
    bool        product_denial_has_counts;
} ExperimentState;

static ExperimentState state = {0};

void        _PG_init(void);
void        _PG_fini(void);

PG_FUNCTION_INFO_V1(commitcap_native_enforce_update_budget);
PG_FUNCTION_INFO_V1(commitcap_native_enforce_rows_updated);
PG_FUNCTION_INFO_V1(commitcap_native_enforce_role_transition);
PG_FUNCTION_INFO_V1(commitcap_native_enforce_refund_delta);
PG_FUNCTION_INFO_V1(commitcap_native_probe);
PG_FUNCTION_INFO_V1(commitcap_native_policy_probe);

static ConsumptionFrame *find_frame(SubTransactionId subid);
static ConsumptionFrame *ensure_frame(SubTransactionId subid);
static void remove_frame(SubTransactionId subid);
static ProductDelta *ensure_product_delta(ConsumptionFrame *frame, ProductPolicy *policy);
static void reset_state(void);
static void activate_state(void);
static void mark_denied(DenialKind kind);
static void mark_product_denied(Relation relation, bool has_counts,
                                uint64 budget, uint64 consumed);
static const char *denial_metric_text(DenialKind kind);
static void record_protected_event(RowPolicy policy);
static bool parse_product_budget(const char *text, uint64 *budget);
static ProductPolicy *find_product_policy(Oid relid);
static void record_product_event(TriggerData *trigger_data, Oid function_oid);
static bool check_numeric_budget(char **newval, void **extra, GucSource source);
static bool valid_refund_amount(Numeric value);
static Numeric numeric_in_top(const char *value);
static char *numeric_text(Numeric value);
static Numeric numeric_operation(Numeric a, Numeric b, bool subtract);
static void replace_numeric(Numeric *slot, Numeric replacement);
static void record_numeric_delta(TriggerData *trigger_data);
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

    DefineCustomStringVariable("commitcap_native.test_numeric_budget",
                               "Test-only exact refund positive-delta budget.",
                               "Nonnegative decimal with exactly two fractional digits and at most 16 integer digits.",
                               &commitcap_experiment_numeric_budget,
                               COMMITCAP_EXPERIMENT_NUMERIC_BUDGET,
                               PGC_SUSET,
                               0,
                               check_numeric_budget, NULL, NULL);

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
    record_protected_event(ROW_SUBSCRIPTIONS);

    PG_RETURN_POINTER(trigger_data->tg_newtuple);
}

/* V0 product path: only a plain BEFORE UPDATE FOR EACH ROW trigger installed by
 * the trusted table owner. The writer cannot change the trigger or its args. */
Datum
commitcap_native_enforce_rows_updated(PG_FUNCTION_ARGS)
{
    TriggerData *trigger_data;

    if (!CALLED_AS_TRIGGER(fcinfo))
        ereport(ERROR,
                (errcode(ERRCODE_E_R_I_E_TRIGGER_PROTOCOL_VIOLATED),
                 errmsg("CommitCap row budget must be called as a trigger")));

    trigger_data = (TriggerData *) fcinfo->context;
    activate_state();
    if (!TRIGGER_FIRED_BEFORE(trigger_data->tg_event) ||
        !TRIGGER_FIRED_FOR_ROW(trigger_data->tg_event) ||
        !TRIGGER_FIRED_BY_UPDATE(trigger_data->tg_event))
    {
        mark_product_denied(trigger_data->tg_relation, false, 0, 0);
        ereport(ERROR,
                (errcode(ERRCODE_E_R_I_E_TRIGGER_PROTOCOL_VIOLATED),
                 errmsg("CommitCap row budget requires a BEFORE UPDATE row trigger")));
    }

    record_product_event(trigger_data, fcinfo->flinfo->fn_oid);
    PG_RETURN_POINTER(trigger_data->tg_newtuple);
}

/* Grammar: exactly 0 or a nonzero decimal without sign/leading zeroes, up to
 * INT_MAX. Checking before multiplication prevents wrap even for long input. */
static bool
parse_product_budget(const char *text, uint64 *budget)
{
    uint64      result = 0;
    const unsigned char *s = (const unsigned char *) text;

    if (s == NULL || *s < '0' || *s > '9' ||
        (*s == '0' && s[1] != '\0'))
        return false;

    for (; *s != '\0'; s++)
    {
        unsigned int digit;

        if (*s < '0' || *s > '9')
            return false;
        digit = *s - '0';
        if (result > (INT_MAX - digit) / 10)
            return false;
        result = result * 10 + digit;
    }
    *budget = result;
    return true;
}

static ProductPolicy *
find_product_policy(Oid relid)
{
    ProductPolicy *policy;

    for (policy = state.product_policies; policy != NULL; policy = policy->next)
        if (policy->relid == relid)
            return policy;
    return NULL;
}

static void
record_product_event(TriggerData *trigger_data, Oid function_oid)
{
    Relation    relation = trigger_data->tg_relation;
    TriggerDesc *desc = relation->trigdesc;
    ProductPolicy *policy;
    ProductDelta *delta = NULL;
    Oid         relid = RelationGetRelid(relation);
    Oid         product_namespace = get_func_namespace(function_oid);
    uint64      budget;
    int         matches = 0;
    int         i;

    if (state.denied)
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap top-level transaction already denied"),
                 errdetail("CommitCap denied transaction\n"
                           "policy / metric: %s\n"
                           "result: DENIED; top-level COMMIT will be rejected",
                           denial_metric_text(state.denial_kind))));

    /* An ordinary nonpartitioned heap table with one unconditional product
     * trigger is the only supported V0 installation. The trusted installer
     * must inspect its catalog; zero-row statements cannot invoke a row trigger. */
    if (relation->rd_rel->relkind != RELKIND_RELATION ||
        relation->rd_rel->relispartition || relation->rd_rel->relhassubclass ||
        trigger_data->tg_trigger->tgnattr != 0 ||
        trigger_data->tg_trigger->tgqual != NULL ||
        !OidIsValid(relid))
    {
        mark_product_denied(relation, false, 0, 0);
        ereport(ERROR,
                (errcode(ERRCODE_INVALID_PARAMETER_VALUE),
                 errmsg("CommitCap row budget requires an unconditional trigger on an ordinary nonpartitioned table")));
    }

    if (desc != NULL)
        for (i = 0; i < desc->numtriggers; i++)
        {
            if (desc->triggers[i].tgfoid == function_oid)
                matches++;
            else if (get_func_namespace(desc->triggers[i].tgfoid) == product_namespace)
            {
                /* Never silently compose this policy with a fixed research
                 * enforcement trigger on the same relation. */
                mark_product_denied(relation, false, 0, 0);
                ereport(ERROR,
                        (errcode(ERRCODE_INVALID_PARAMETER_VALUE),
                         errmsg("CommitCap product row budget cannot share a relation with another CommitCap trigger")));
            }
        }
    if (matches != 1)
    {
        mark_product_denied(relation, false, 0, 0);
        ereport(ERROR,
                (errcode(ERRCODE_INVALID_PARAMETER_VALUE),
                 errmsg("CommitCap requires exactly one UPDATE row-budget trigger per relation")));
    }

    if (trigger_data->tg_trigger->tgnargs != 1)
    {
        mark_product_denied(relation, false, 0, 0);
        ereport(ERROR,
                (errcode(ERRCODE_INVALID_PARAMETER_VALUE),
                 errmsg("CommitCap row budget requires exactly one decimal argument")));
    }

    if (!parse_product_budget(trigger_data->tg_trigger->tgargs[0], &budget))
    {
        mark_product_denied(relation, false, 0, 0);
        ereport(ERROR,
                (errcode(ERRCODE_INVALID_PARAMETER_VALUE),
                 errmsg("CommitCap row budget must be a canonical decimal integer from 0 to %d", INT_MAX)));
    }

    policy = find_product_policy(relid);
    if (policy == NULL)
    {
        MemoryContext old_context = MemoryContextSwitchTo(TopMemoryContext);

        policy = palloc0(sizeof(*policy));
        policy->relid = relid;
        policy->trigger_oid = trigger_data->tg_trigger->tgoid;
        policy->budget = budget;
        MemoryContextSwitchTo(old_context);
        policy->next = state.product_policies;
        state.product_policies = policy;
    }
    else if (policy->trigger_oid != trigger_data->tg_trigger->tgoid ||
             policy->budget != budget)
    {
        mark_product_denied(relation, false, 0, 0);
        ereport(ERROR,
                (errcode(ERRCODE_INVALID_PARAMETER_VALUE),
                 errmsg("CommitCap row budget configuration changed inside a transaction")));
    }

    if (policy->consumed >= policy->budget)
    {
        mark_product_denied(relation, true, policy->budget, policy->consumed);
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap mutation budget exceeded (limit " UINT64_FORMAT ", attempted " UINT64_FORMAT ")",
                        policy->budget, policy->consumed + 1),
                 errdetail("CommitCap denied transaction\n"
                           "policy / metric: %s\n"
                           "granted: " UINT64_FORMAT "\n"
                           "consumed before attempt: " UINT64_FORMAT "\n"
                           "attempted effect: " UINT64_FORMAT " row-update events\n"
                           "result: DENIED; top-level COMMIT will be rejected",
                           denial_metric_text(DENIAL_PRODUCT_ROW),
                           policy->budget, policy->consumed, policy->consumed + 1)));
    }

    if (IsSubTransaction())
        delta = ensure_product_delta(ensure_frame(GetCurrentSubTransactionId()), policy);
    policy->consumed++;
    if (delta != NULL)
        delta->consumed++;
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

    /* The first denial remains authoritative even if this later row would
     * independently violate the forbidden-transition rule. */
    if (state.denied)
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap top-level transaction already denied"),
                 errdetail("CommitCap denied transaction\n"
                           "policy / metric: %s\n"
                           "result: DENIED; top-level COMMIT will be rejected",
                           denial_metric_text(state.denial_kind))));

    if (forbidden_role_transition(trigger_data, &new_role))
    {
        mark_denied(DENIAL_TRANSITION);
        elog(LOG,
             "commitcap_native_tx_state transition_denial pid=%d to=%s",
             MyProcPid, new_role);
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap forbidden state transition (* -> %s)",
                        COMMITCAP_EXPERIMENT_DENIED_ROLE),
                 errdetail("CommitCap denied transaction\n"
                           "policy / metric: %s\n"
                           "attempted effect: 1 forbidden row transition to %s\n"
                           "result: DENIED; top-level COMMIT will be rejected",
                           denial_metric_text(DENIAL_TRANSITION),
                           COMMITCAP_EXPERIMENT_DENIED_ROLE),
                 errhint("CommitCap research mechanism; see docs/limitations.md and docs/test-plan.md for the tested envelope.")));
    }

    record_protected_event(ROW_USERS);

    PG_RETURN_POINTER(trigger_data->tg_newtuple);
}

Datum
commitcap_native_enforce_refund_delta(PG_FUNCTION_ARGS)
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
                 errmsg("CommitCap refund experiment requires a BEFORE UPDATE row trigger")));

    activate_state();
    record_numeric_delta(trigger_data);
    PG_RETURN_POINTER(trigger_data->tg_newtuple);
}

/*
 * Record the first denial that poisoned this top-level transaction. Later
 * denials never overwrite the original cause: the first violated policy is the
 * authoritative explanation. This changes when evidence is reported, not when
 * enforcement denies.
 */
static void
mark_denied(DenialKind kind)
{
    if (!state.denied)
        state.denial_kind = kind;
    state.denied = true;
}

/* Snapshot the first cause in TopMemoryContext: Relation/catalog pointers and
 * subtransaction-owned strings must never be retained until PRE_COMMIT. */
static void
mark_product_denied(Relation relation, bool has_counts,
                    uint64 budget, uint64 consumed)
{
    if (!state.denied)
    {
        MemoryContext old_context;
        char       *schema;
        char       *qualified;

        mark_denied(DENIAL_PRODUCT_ROW);
        old_context = MemoryContextSwitchTo(TopMemoryContext);
        schema = get_namespace_name(RelationGetNamespace(relation));
        qualified = quote_qualified_identifier(schema, RelationGetRelationName(relation));
        state.product_denial_metric = psprintf("%s.rows_updated", qualified);
        pfree(qualified);
        pfree(schema);
        MemoryContextSwitchTo(old_context);
        state.product_denial_budget = budget;
        state.product_denial_consumed = consumed;
        state.product_denial_has_counts = has_counts;
    }
}

/*
 * Human-readable policy key for a recorded denial. These strings are static
 * literals derived from enforcement state; no user-controlled text and no
 * protected row values are interpolated. They are for error detail only.
 */
static const char *
denial_metric_text(DenialKind kind)
{
    switch (kind)
    {
        case DENIAL_ROW_SUBSCRIPTIONS:
            return "subscriptions.rows_updated";
        case DENIAL_ROW_USERS:
            return "users.rows_updated";
        case DENIAL_TRANSITION:
            return "users.role (* -> admin)";
        case DENIAL_NUMERIC:
            return "refunds.amount positive_delta";
        case DENIAL_PRODUCT_ROW:
            return state.product_denial_metric != NULL ?
                state.product_denial_metric : "unavailable relation identity";
        case DENIAL_NONE:
            break;
    }
    return "unspecified enforcement failure";
}

/* GUC input is validated before it can be captured by a writer transaction. */
static bool
check_numeric_budget(char **newval, void **extra, GucSource source)
{
    const char *s = *newval;
    int         digits = 0;

    (void) extra;
    (void) source;

    if (s == NULL || *s < '0' || *s > '9')
        return false;
    if (s[0] == '0' && s[1] != '.')
        return false;
    while (*s >= '0' && *s <= '9')
    {
        digits++;
        s++;
    }
    if (digits > 16 || *s++ != '.')
        return false;
    if (s[0] < '0' || s[0] > '9' ||
        s[1] < '0' || s[1] > '9' || s[2] != '\0')
        return false;
    return true;
}

/* PostgreSQL numeric_out preserves the input's decimal scale for unconstrained
 * numeric. Never use numeric(p,s): typmod would silently round before here. */
static bool
valid_refund_amount(Numeric value)
{
    char       *text;
    const char *s;
    int         integer_digits = 0;
    int         fractional_digits = 0;
    bool        valid;

    if (numeric_is_nan(value) || numeric_is_inf(value))
        return false;

    text = DatumGetCString(DirectFunctionCall1(numeric_out,
                                               NumericGetDatum(value)));
    s = text;
    if (*s == '-')
    {
        pfree(text);
        return false;
    }
    while (*s >= '0' && *s <= '9')
    {
        integer_digits++;
        s++;
    }
    valid = integer_digits > 0 && integer_digits <= 16;
    if (*s == '.')
    {
        s++;
        while (*s >= '0' && *s <= '9')
        {
            fractional_digits++;
            s++;
        }
        valid = valid && fractional_digits <= 2;
    }
    valid = valid && *s == '\0';
    pfree(text);
    return valid;
}

static Numeric
numeric_in_top(const char *value)
{
    MemoryContext previous = MemoryContextSwitchTo(TopMemoryContext);
    Numeric     result = DatumGetNumeric(DirectFunctionCall3(numeric_in,
                        CStringGetDatum(value), ObjectIdGetDatum(InvalidOid),
                        Int32GetDatum(-1)));

    MemoryContextSwitchTo(previous);
    return result;
}

/*
 * Exact decimal spelling of a validated budget/consumption/delta value for
 * error detail. numeric_out preserves the value's scale; the value is not
 * rounded or reformatted by the enforcement path. The result is palloc'd in
 * the current context and consumed by ereport before any error longjmp.
 */
static char *
numeric_text(Numeric value)
{
    Assert(value != NULL);
    return DatumGetCString(DirectFunctionCall1(numeric_out,
                                               NumericGetDatum(value)));
}

/* Always allocate persistent arithmetic results outside subtransaction-owned
 * contexts. Bounded inputs and check-before-add prevent numeric overflow. */
static Numeric
numeric_operation(Numeric a, Numeric b, bool subtract)
{
    bool        error = false;
    MemoryContext previous = MemoryContextSwitchTo(TopMemoryContext);
    Numeric     result = subtract ? numeric_sub_opt_error(a, b, &error) :
                                  numeric_add_opt_error(a, b, &error);

    MemoryContextSwitchTo(previous);
    if (error || result == NULL)
    {
        mark_denied(DENIAL_NUMERIC);
        ereport(ERROR,
                (errcode(ERRCODE_NUMERIC_VALUE_OUT_OF_RANGE),
                 errmsg("CommitCap unsafe numeric arithmetic"),
                 errdetail("CommitCap denied transaction\n"
                           "policy / metric: %s\n"
                           "result: DENIED; top-level COMMIT will be rejected",
                           denial_metric_text(DENIAL_NUMERIC))));
    }
    return result;
}

static void
replace_numeric(Numeric *slot, Numeric replacement)
{
    if (*slot != NULL)
        pfree(*slot);
    *slot = replacement;
}

static void
record_numeric_delta(TriggerData *trigger_data)
{
    TupleDesc   tupdesc = trigger_data->tg_relation->rd_att;
    int         attnum = -1;
    Datum       old_datum;
    Datum       new_datum;
    bool        old_null;
    bool        new_null;
    Numeric     old_amount;
    Numeric     new_amount;
    Numeric     delta;
    Numeric     remaining;
    ConsumptionFrame *frame;
    int         i;

    if (state.denied)
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap top-level transaction already denied"),
                 errdetail("CommitCap denied transaction\n"
                           "policy / metric: %s\n"
                           "result: DENIED; top-level COMMIT will be rejected",
                           denial_metric_text(state.denial_kind))));

    for (i = 0; i < tupdesc->natts; i++)
    {
        Form_pg_attribute attr = TupleDescAttr(tupdesc, i);

        if (!attr->attisdropped && attr->atttypid == NUMERICOID &&
            strcmp(NameStr(attr->attname), "amount") == 0)
        {
            attnum = attr->attnum;
            break;
        }
    }
    if (attnum < 0)
    {
        mark_denied(DENIAL_NUMERIC);
        ereport(ERROR,
                (errcode(ERRCODE_INVALID_PARAMETER_VALUE),
                 errmsg("CommitCap refund fixture requires a numeric amount column"),
                 errdetail("CommitCap denied transaction\n"
                           "policy / metric: %s\n"
                           "result: DENIED; top-level COMMIT will be rejected",
                           denial_metric_text(DENIAL_NUMERIC))));
    }

    old_datum = heap_getattr(trigger_data->tg_trigtuple, attnum, tupdesc,
                             &old_null);
    new_datum = heap_getattr(trigger_data->tg_newtuple, attnum, tupdesc,
                             &new_null);
    if (old_null || new_null ||
        !valid_refund_amount(DatumGetNumeric(old_datum)) ||
        !valid_refund_amount(DatumGetNumeric(new_datum)))
    {
        mark_denied(DENIAL_NUMERIC);
        ereport(ERROR,
                (errcode(ERRCODE_INVALID_PARAMETER_VALUE),
                 errmsg("CommitCap invalid refund amount (finite, nonnegative, 16 integer and 2 fractional digits maximum)"),
                 errdetail("CommitCap denied transaction\n"
                           "policy / metric: %s\n"
                           "result: DENIED; top-level COMMIT will be rejected",
                           denial_metric_text(DENIAL_NUMERIC))));
    }

    old_amount = DatumGetNumeric(old_datum);
    new_amount = DatumGetNumeric(new_datum);
    delta = numeric_operation(new_amount, old_amount, true);
    /* Explicit zero comparison without float conversion. */
    {
        Numeric zero = numeric_in_top("0.00");
        int cmp = DatumGetInt32(DirectFunctionCall2(numeric_cmp,
                            NumericGetDatum(delta), NumericGetDatum(zero)));

        pfree(zero);
        if (cmp <= 0)
        {
            pfree(delta);
            return;
        }
    }

    remaining = numeric_operation(state.numeric_budget, state.positive_delta,
                                  true);
    if (DatumGetInt32(DirectFunctionCall2(numeric_cmp, NumericGetDatum(delta),
                                          NumericGetDatum(remaining))) > 0)
    {
        char       *granted_text = numeric_text(state.numeric_budget);
        char       *consumed_text = numeric_text(state.positive_delta);
        char       *attempted_text = numeric_text(delta);

        mark_denied(DENIAL_NUMERIC);
        pfree(remaining);
        pfree(delta);
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap numeric delta budget exceeded"),
                 errdetail("CommitCap denied transaction\n"
                           "policy / metric: %s\n"
                           "granted: %s\n"
                           "consumed before attempt: %s\n"
                           "attempted effect: +%s positive delta\n"
                           "result: DENIED; top-level COMMIT will be rejected",
                           denial_metric_text(DENIAL_NUMERIC),
                           granted_text, consumed_text, attempted_text),
                 errhint("CommitCap research mechanism; see docs/limitations.md and docs/test-plan.md for the tested envelope.")));
    }
    pfree(remaining);
    replace_numeric(&state.positive_delta,
                    numeric_operation(state.positive_delta, delta, false));
    if (IsSubTransaction())
    {
        frame = ensure_frame(GetCurrentSubTransactionId());
        if (frame->positive_delta == NULL)
            frame->positive_delta = numeric_in_top("0.00");
        replace_numeric(&frame->positive_delta,
                        numeric_operation(frame->positive_delta, delta, false));
    }
    pfree(delta);
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
        state.denial_kind = DENIAL_NONE;
        state.consumed[ROW_SUBSCRIPTIONS] = (commitcap_experiment_seed_consumed >= 0)
            ? (uint64) commitcap_experiment_seed_consumed
            : 0;
        state.consumed[ROW_USERS] = 0;
        state.budget[ROW_SUBSCRIPTIONS] = (uint64) commitcap_experiment_budget;
        state.budget[ROW_USERS] = (uint64) commitcap_experiment_budget;
        state.positive_delta = numeric_in_top("0.00");
        state.numeric_budget = numeric_in_top(commitcap_experiment_numeric_budget);
        state.frames = NULL;
    }
}

static void
record_protected_event(RowPolicy policy)
{
    ConsumptionFrame *frame;
    DenialKind  kind = (policy == ROW_SUBSCRIPTIONS) ?
        DENIAL_ROW_SUBSCRIPTIONS : DENIAL_ROW_USERS;

    if (state.denied)
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap top-level transaction already denied"),
                 errdetail("CommitCap denied transaction\n"
                           "policy / metric: %s\n"
                           "result: DENIED; top-level COMMIT will be rejected",
                           denial_metric_text(state.denial_kind))));

    /*
     * Check before increment. This comparison cannot overflow, and consumed
     * only increments while it is below the captured budget, so it can never
     * exceed the documented maximum.
     */
    if (state.consumed[policy] >= state.budget[policy])
    {
        mark_denied(kind);
        elog(LOG,
             "commitcap_native_tx_state denial pid=%d consumed=" UINT64_FORMAT
             " attempted=" UINT64_FORMAT " budget=" UINT64_FORMAT,
              MyProcPid, state.consumed[policy], state.consumed[policy] + 1,
              state.budget[policy]);
        ereport(ERROR,
                (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                 errmsg("CommitCap mutation budget exceeded (limit " UINT64_FORMAT ", attempted " UINT64_FORMAT ")",
                         state.budget[policy], state.consumed[policy] + 1),
                 errdetail("CommitCap denied transaction\n"
                           "policy / metric: %s\n"
                           "granted: " UINT64_FORMAT "\n"
                           "consumed before attempt: " UINT64_FORMAT "\n"
                           "attempted effect: " UINT64_FORMAT " row-update events\n"
                           "result: DENIED; top-level COMMIT will be rejected",
                           denial_metric_text(kind),
                           state.budget[policy], state.consumed[policy],
                           state.consumed[policy] + 1),
                 errhint("CommitCap research mechanism; see docs/limitations.md and docs/test-plan.md for the tested envelope.")));
    }

    state.consumed[policy]++;
    if (IsSubTransaction())
    {
        frame = ensure_frame(GetCurrentSubTransactionId());
        frame->delta[policy]++;
    }

    elog(LOG,
         "commitcap_native_tx_state event pid=%d consumed=" UINT64_FORMAT
         " denied=%s",
          MyProcPid, state.consumed[policy], state.denied ? "true" : "false");
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
    /* Legacy probe reports aggregate row events for existing single-table
     * tests; decisions always compare independently keyed policy counters. */
    values[1] = Int64GetDatum((int64) (state.consumed[ROW_SUBSCRIPTIONS] +
                                      state.consumed[ROW_USERS]));
    values[2] = BoolGetDatum(state.denied);
    values[3] = Int32GetDatum((int32) MyProcPid);

    tuple = heap_form_tuple(tupdesc, values, nulls);

    PG_RETURN_DATUM(HeapTupleGetDatum(tuple));
}

Datum
commitcap_native_policy_probe(PG_FUNCTION_ARGS)
{
    TupleDesc   tupdesc;
    Datum       values[4];
    bool        nulls[4] = {false, false, false, false};
    HeapTuple   tuple;

    if (get_call_result_type(fcinfo, NULL, &tupdesc) != TYPEFUNC_COMPOSITE)
        ereport(ERROR,
                (errcode(ERRCODE_FEATURE_NOT_SUPPORTED),
                 errmsg("function returning record called in context that cannot accept type record")));

    values[0] = Int64GetDatum((int64) state.consumed[ROW_SUBSCRIPTIONS]);
    values[1] = Int64GetDatum((int64) state.consumed[ROW_USERS]);
    values[2] = state.positive_delta != NULL ?
        NumericGetDatum(state.positive_delta) :
        NumericGetDatum(int64_to_numeric(0));
    values[3] = BoolGetDatum(state.denied);
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

static ProductDelta *
ensure_product_delta(ConsumptionFrame *frame, ProductPolicy *policy)
{
    ProductDelta *delta;
    MemoryContext old_context;

    for (delta = frame->product_deltas; delta != NULL; delta = delta->next)
        if (delta->policy == policy)
            return delta;

    old_context = MemoryContextSwitchTo(TopMemoryContext);
    delta = palloc0(sizeof(*delta));
    MemoryContextSwitchTo(old_context);
    delta->policy = policy;
    delta->next = frame->product_deltas;
    frame->product_deltas = delta;
    return delta;
}

static void
free_product_deltas(ProductDelta *delta)
{
    while (delta != NULL)
    {
        ProductDelta *next = delta->next;

        pfree(delta);
        delta = next;
    }
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
            if (frame->positive_delta != NULL)
                pfree(frame->positive_delta);
            free_product_deltas(frame->product_deltas);
            pfree(frame);
            return;
        }
    }
}

static void
reset_state(void)
{
    ConsumptionFrame *frame = state.frames;
    ProductPolicy *policy = state.product_policies;

    while (frame != NULL)
    {
        ConsumptionFrame *next = frame->next;

        if (frame->positive_delta != NULL)
            pfree(frame->positive_delta);
        free_product_deltas(frame->product_deltas);
        pfree(frame);
        frame = next;
    }

    while (policy != NULL)
    {
        ProductPolicy *next = policy->next;

        pfree(policy);
        policy = next;
    }
    if (state.product_denial_metric != NULL)
        pfree(state.product_denial_metric);

    state.active = false;
    state.denied = false;
    state.denial_kind = DENIAL_NONE;
    state.consumed[ROW_SUBSCRIPTIONS] = 0;
    state.consumed[ROW_USERS] = 0;
    state.budget[ROW_SUBSCRIPTIONS] = 0;
    state.budget[ROW_USERS] = 0;
    if (state.positive_delta != NULL)
        pfree(state.positive_delta);
    if (state.numeric_budget != NULL)
        pfree(state.numeric_budget);
    state.positive_delta = NULL;
    state.numeric_budget = NULL;
    state.frames = NULL;
    state.product_policies = NULL;
    state.product_denial_metric = NULL;
    state.product_denial_has_counts = false;
    state.product_denial_budget = 0;
    state.product_denial_consumed = 0;
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
                  MyProcPid, state.consumed[ROW_SUBSCRIPTIONS] + state.consumed[ROW_USERS], state.denied ? "true" : "false");
            if (state.denied && state.denial_kind == DENIAL_PRODUCT_ROW &&
                state.product_denial_has_counts)
                ereport(ERROR,
                        (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                         errmsg("CommitCap top-level transaction denied after mutation authority violation"),
                         errdetail("CommitCap denied transaction\n"
                                   "policy / metric: %s\n"
                                   "granted: " UINT64_FORMAT "\n"
                                   "consumed before attempt: " UINT64_FORMAT "\n"
                                   "attempted effect: " UINT64_FORMAT " row-update events\n"
                                   "result: ABORTED",
                                   denial_metric_text(state.denial_kind),
                                   state.product_denial_budget,
                                   state.product_denial_consumed,
                                   state.product_denial_consumed + 1)));
            if (state.denied)
                ereport(ERROR,
                        (errcode(ERRCODE_PROGRAM_LIMIT_EXCEEDED),
                         errmsg("CommitCap top-level transaction denied after mutation authority violation"),
                         errdetail("CommitCap denied transaction\n"
                                   "policy / metric: %s\n"
                                   "result: ABORTED",
                                   denial_metric_text(state.denial_kind)),
                          errhint("CommitCap research mechanism; see docs/limitations.md and docs/test-plan.md for the tested envelope.")));
            break;

        case XACT_EVENT_COMMIT:
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=XACT_COMMIT pid=%d consumed=" UINT64_FORMAT
                 " denied=%s",
                  MyProcPid, state.consumed[ROW_SUBSCRIPTIONS] + state.consumed[ROW_USERS], state.denied ? "true" : "false");
            reset_state();
            break;

        case XACT_EVENT_ABORT:
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=XACT_ABORT pid=%d consumed=" UINT64_FORMAT
                 " denied=%s",
                  MyProcPid, state.consumed[ROW_SUBSCRIPTIONS] + state.consumed[ROW_USERS], state.denied ? "true" : "false");
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
                  MyProcPid, mySubid, parentSubid, state.consumed[ROW_SUBSCRIPTIONS] + state.consumed[ROW_USERS],
                 state.denied ? "true" : "false");
            break;

        case SUBXACT_EVENT_PRE_COMMIT_SUB:
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=SUBXACT_PRE_COMMIT pid=%d subid=%u parent=%u consumed=" UINT64_FORMAT
                 " denied=%s",
                  MyProcPid, mySubid, parentSubid, state.consumed[ROW_SUBSCRIPTIONS] + state.consumed[ROW_USERS],
                 state.denied ? "true" : "false");
            break;

        case SUBXACT_EVENT_COMMIT_SUB:
            frame = find_frame(mySubid);
            if (frame != NULL && GetCurrentTransactionNestLevel() > 2)
            {
                ConsumptionFrame *parent = ensure_frame(parentSubid);
                ProductDelta *delta;
                int i;

                for (i = 0; i < ROW_POLICY_COUNT; i++)
                    parent->delta[i] += frame->delta[i];
                for (delta = frame->product_deltas; delta != NULL; delta = delta->next)
                {
                    ProductDelta *parent_delta = ensure_product_delta(parent, delta->policy);

                    Assert(parent_delta->consumed <= delta->policy->budget);
                    Assert(delta->consumed <= delta->policy->budget - parent_delta->consumed);
                    parent_delta->consumed += delta->consumed;
                }
                if (frame->positive_delta != NULL)
                {
                    if (parent->positive_delta == NULL)
                        parent->positive_delta = numeric_in_top("0.00");
                    replace_numeric(&parent->positive_delta,
                                    numeric_operation(parent->positive_delta,
                                                      frame->positive_delta, false));
                }
            }
            remove_frame(mySubid);
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=SUBXACT_COMMIT pid=%d subid=%u parent=%u consumed=" UINT64_FORMAT
                 " denied=%s",
                  MyProcPid, mySubid, parentSubid, state.consumed[ROW_SUBSCRIPTIONS] + state.consumed[ROW_USERS],
                 state.denied ? "true" : "false");
            break;

        case SUBXACT_EVENT_ABORT_SUB:
            frame = find_frame(mySubid);
            if (frame != NULL)
            {
                ProductDelta *delta;
                int i;

                for (i = 0; i < ROW_POLICY_COUNT; i++)
                {
                    Assert(state.consumed[i] >= frame->delta[i]);
                    state.consumed[i] -= frame->delta[i];
                }
                for (delta = frame->product_deltas; delta != NULL; delta = delta->next)
                {
                    Assert(delta->policy->consumed >= delta->consumed);
                    delta->policy->consumed -= delta->consumed;
                }
                if (frame->positive_delta != NULL)
                    replace_numeric(&state.positive_delta,
                                    numeric_operation(state.positive_delta,
                                                      frame->positive_delta, true));
                remove_frame(mySubid);
            }
            elog(LOG,
                 "commitcap_native_tx_state lifecycle event=SUBXACT_ABORT pid=%d subid=%u parent=%u consumed=" UINT64_FORMAT
                 " denied=%s",
                  MyProcPid, mySubid, parentSubid, state.consumed[ROW_SUBSCRIPTIONS] + state.consumed[ROW_USERS],
                 state.denied ? "true" : "false");
            break;
    }
}
