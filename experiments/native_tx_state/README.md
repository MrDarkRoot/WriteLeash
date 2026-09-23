# Native Transaction-State Experiment

## Classification

`TEST FIRST`. This is a Phase 0 feasibility spike, not a production extension
and not a V0 support claim.

The experiment answers one question on PostgreSQL 16.4: can backend-local
state and transaction callbacks provide rollback-aware allowed consumption and
sticky top-level denial at the same time?

## Reproduce

From the repository root:

```bash
./experiments/native_tx_state/run.sh
```

The script builds a disposable image from `postgres:16.4-alpine`, initializes
the constrained role and table fixture, runs the tests, prints server-side
callback traces, and removes the container and volume. Every durable-state
assertion opens a fresh trusted-admin connection.

## Candidate

The C trigger function keeps two distinct kinds of backend-local state:

```text
top-level state:
    active
    consumed
    denied

subtransaction frame:
    subxid
    allowed-consumption delta
```

`consumed` records only allowed row-update events that are still live. An
allowed event in a subtransaction increments both `consumed` and that
subtransaction's delta. `SUBXACT_EVENT_ABORT_SUB` subtracts the delta, while
`SUBXACT_EVENT_COMMIT_SUB` transfers it to a subtransaction parent when one
exists. The event that would exceed five sets `denied` before raising the
immediate CommitCap error. No subtransaction callback clears `denied`.

`XACT_EVENT_PRE_COMMIT` runs while PostgreSQL can still abort the transaction.
If `denied` is true, the callback raises
`CommitCap top-level transaction denied after mutation budget violation`.
PostgreSQL then takes the top-level abort path. `XACT_EVENT_COMMIT` and
`XACT_EVENT_ABORT` perform backend-local cleanup.

The trigger lazily creates a frame when the library is first loaded inside an
already-active subtransaction. This matters because callback registration can
occur after that subtransaction's `SUBXACT_EVENT_START_SUB` event.

## Results

Observed on PostgreSQL 16.4:

| Test | Result | Evidence |
| --- | --- | --- |
| `CC-001` | **PASS** | Five events committed and a fresh connection observed five durable changes |
| `CC-002` | **PASS** | Event six denied one six-row statement and a fresh connection observed the baseline |
| `CC-003` | **PASS** | Five statements consumed one shared budget; statement six denied and nothing became durable |
| `CC-008` | **PASS** | `ROLLBACK TO` recovered statement execution but not commit authority; final `COMMIT` errored and a fresh connection observed the baseline |
| `CC-009` | **PASS** | Aborting three allowed savepoint events changed live consumption from three to zero; five replacements committed |
| `CC-011` | **PASS** | A five-row data-modifying CTE committed; a six-row CTE was denied and no protected mutation became durable |
| `CC-019` | **PASS** | Two distinct backends each consumed four events independently; denial in either backend did not affect the other; both committed within budget |
| `CC-020` | **PASS** | Waiting backends counted no event while blocked, consumed one unit for the contended event after resuming, and neither transaction exceeded its budget; no deadlock |
| `CC-021` | **PASS** | One persistent backend started every next top-level transaction at `consumed=0, denied=false` after commit, rollback, denial abort, savepoint-recovered denial, caught denial, and autocommit transactions; no reset function or disconnect |
| `CC-024` | **PASS** | PL/pgSQL caught event-six error, but final `COMMIT` errored and a fresh connection observed the baseline |
| `CC-025` | **PASS** | One prepared `UPDATE` executed five times committed; the sixth execution in one transaction was denied and no protected mutation became durable |
| `CC-026` | **PASS** | Five MERGE update actions consumed five events and committed; six were denied; mixed UPDATE+MERGE and multiple MERGE statements shared one transaction budget |
| `CC-027` | **PASS** | All 27 writer bypass attempts were denied; trusted object ownership, trigger state, and enforcement behavior were unchanged |
| `CC-028` | **PASS** | Zero memberships and zero reachable roles; `SET ROLE` denied for every other role and `SET SESSION AUTHORIZATION` denied |
| `CC-029` | **PASS** | `max_prepared_transactions=0`; `PREPARE TRANSACTION` rejected with `55000`; no prepared transaction and no durable mutation |
| `CC-032` | **PASS** | An unprotected insert before denial was rolled back with the protected changes; a fresh connection observed zero audit rows |
| `CC-033` | **PASS** | `EXPLAIN (ANALYZE, COSTS OFF)` executed the protected update, the plan reported `calls=5` on the enforcement trigger, and the six-row form was denied |

The same-backend cleanup probes also passed:

- A transaction consumed three and committed; the next transaction consumed
  five and committed.
- A transaction consumed three and rolled back; the next transaction consumed
  five and committed.

These results make this mechanism class **VIABLE FOR FURTHER TESTING**. They do
not make the experiment production-ready or any operation supported.

## Alternate Update Paths

`CC-011`, `CC-025`, and `CC-033` tested whether SQL shape can create fresh
authority or reach the protected relation outside native accounting. No test
required a privilege beyond the writer's existing column grants.

- `CC-025` executed one prepared `UPDATE` six times inside one transaction.
  Executions one through five succeeded, execution six raised the CommitCap
  denial, and the transaction did not commit. A separate five-execution
  prepared transaction committed.
- `CC-011` used a data-modifying CTE. The five-row CTE committed; the six-row
  CTE raised the denial and did not commit.
- `CC-033` wrapped `UPDATE` in `EXPLAIN (ANALYZE, COSTS OFF)`. The five-row
  form committed and its plan output showed
  `Trigger subscriptions_update_budget: ... calls=5`, proving the executor ran
  the protected update and the trigger observed every row event. The six-row
  form raised the denial and did not commit.

Every denied transaction was followed by a same-backend transaction that
consumed five events and committed. The lifecycle trace shows the denied
transactions ending in `XACT_ABORT ... denied=true`, followed by
`XACT_PRE_COMMIT ... denied=false` and `XACT_COMMIT` for the cleanup
transaction.

Because these alternate-path denials were not recovered by a savepoint or
exception block, PostgreSQL placed the top-level transaction in aborted state.
The client's following `COMMIT` therefore returned a `ROLLBACK` command tag
instead of reaching `XACT_EVENT_PRE_COMMIT`. Commit rejection at the pre-commit
callback when execution recovers after a denial remains covered by `CC-008`,
`CC-024`, and `CC-032`.

## MERGE Accounting

`CC-026` tested whether `MERGE ... WHEN MATCHED THEN UPDATE` reaches the same
backend-local row-event accounting as ordinary `UPDATE`. No MERGE support code
was added; the experiment only exercised existing behavior.

The writer's existing column grants were sufficient: `UPDATE(status)` on the
target and `SELECT(id)` for the `ON target.id = source.id` join. No privilege
was granted or broadened, and the source was an inline `VALUES` list, so no
additional relation privileges were required.

Observed on PostgreSQL 16.4:

- Instrumentation: `EXPLAIN (ANALYZE, COSTS OFF)` over a five-row MERGE
  reported `Trigger subscriptions_update_budget: ... calls=5`, proving the
  BEFORE UPDATE trigger fired once per MERGE update action.
- Five-event MERGE: command tag `MERGE 5`; probe read `consumed=5,
  denied=false`; `COMMIT` succeeded and a fresh admin connection observed
  exactly five durable changes.
- Six-event MERGE: event six raised the CommitCap denial; `COMMIT` returned
  `ROLLBACK`; the table remained at baseline.
- Mixed `UPDATE` + MERGE: an ordinary three-row `UPDATE` followed by a two-row
  MERGE read `consumed=5`; a further one-row MERGE raised the event-six denial
  and nothing became durable.
- Multiple MERGE statements: a three-row MERGE followed by a two-row MERGE
  read `consumed=5`; a further one-row MERGE was denied and nothing became
  durable.

MERGE update actions therefore shared the top-level transaction budget rather
than receiving statement-local authority. This covers only
`WHEN MATCHED THEN UPDATE` with an inline `VALUES` source; `WHEN NOT MATCHED`
actions, `DELETE` actions, conditional or multiple branches, and other source
shapes remain untested.

## Concurrent Sessions

`CC-019` and `CC-020` ran two simultaneous `commitcap_writer` sessions against
the same protected relation. Each session was a distinct PostgreSQL backend
with a recorded `pg_backend_pid()`, driven through a host named pipe and
synchronized by PostgreSQL-confirmed output markers. Lock waits were confirmed
from a separate admin connection using `pg_stat_activity` and
`pg_blocking_pids()`; no advisory locks were used. All runs used the default
`READ COMMITTED` isolation level.

`CC-019` observed:

- Two open transactions each counted four events at the same time; neither
  backend's consumption changed the other's count.
- Each backend then consumed a fifth event and committed. A fresh admin
  connection verified both five-row changes by row.
- With session A denied (`consumed=5, denied=true`) and its transaction still
  open after `ROLLBACK TO SAVEPOINT`, session B remained
  `consumed=4, denied=false`, consumed a fifth event, and committed. A's
  following `COMMIT` was rejected.
- The reverse direction (B denied, A committing) produced the same result.
- After every case, a new transaction in each backend started at
  `consumed=0, denied=false` and committed five events.

`CC-020` intentionally overlapped row `id = 1` (scenario A) and row `id = 5`
(scenario B). Observed behavior:

- In both scenarios the waiting backend reported `wait_event_type=Lock`,
  `wait_event=transactionid`, and `pg_blocking_pids()` named the other backend.
- While the waiting backend was confirmed blocked, no trigger event was
  attributed to it. Exactly one event was counted when it resumed, and that
  contended row-update event consumed exactly one unit.
- PostgreSQL 16.4's `ExecBRUpdateTriggers` locks the target tuple and runs
  EvalPlanQual before firing the BEFORE ROW trigger, so the trigger observed
  the latest row version once. No double-counting from an EvalPlanQual
  re-fire was observed in these scenarios.
- Scenario A: A committed, B then applied its contended update, consumed its
  remaining budget, and committed.
- Scenario B: A exceeded its own budget under contention, its `COMMIT` was
  rejected as `ROLLBACK`, and B then applied its update, consumed its remaining
  budget, and committed without inheriting A's accounting or denial.
- `pg_stat_database.deadlocks` was unchanged (0 before and after each
  scenario); no deadlock occurred.
- After each scenario, durable protected state matched the expected rows
  exactly, verified from a fresh admin connection, and new transactions in both
  backends committed five events.

These results are specific to PostgreSQL 16.4, `READ COMMITTED`, two sessions,
and the tested statement shapes. Other isolation levels, deadlock-producing
workloads, and more than two sessions remain untested.

## Backend Reuse

`CC-021` reused one persistent protected-writer backend across independent
top-level transactions. `pg_backend_pid()` was recorded before and after the
sequence and stayed the same. No reset function was called between
transactions, and the connection was never closed. Every scenario was followed
by a probe read and a durable-state check from a fresh admin connection.

Observed on PostgreSQL 16.4:

- **Scenario A (COMMIT then reuse):** TX1 committed five events. TX2 started at
  `consumed=0, denied=false`, consumed five events, and committed. Callbacks
  for both transactions were `XACT_PRE_COMMIT` then `XACT_COMMIT`.
- **Scenario B (ROLLBACK then reuse):** TX1 consumed four events and rolled
  back (`XACT_ABORT consumed=4 denied=false`). TX2 started fresh and committed
  five events.
- **Scenario C (denied abort then reuse):** TX1 consumed five events; event six
  set `denied=true` and aborted (`XACT_ABORT consumed=5 denied=true`). TX2
  started fresh and committed five events.
- **Scenario D (savepoint sticky denial then reuse):** TX1 recovered with
  `ROLLBACK TO SAVEPOINT` while `denied=true`, then
  `XACT_EVENT_PRE_COMMIT` rejected its `COMMIT` and `XACT_ABORT` cleaned up.
  TX2 started fresh and committed five events.
- **Scenario E (PL/pgSQL caught denial then reuse):** Same lifecycle as D
  through a PL/pgSQL exception block.
- **Autocommit reuse:** Two consecutive five-event autocommit statements each
  received a full budget and committed. A six-event autocommit statement was
  denied and left no durable change. The next autocommit statement received a
  fresh budget.
- The backend PID was identical from the first probe to the final probe. No
  manual reset and no disconnect were required.

Actor-switch-on-same-backend: **NOT TESTED**. The protected writer holds no
role memberships, and adding one solely to exercise `SET ROLE` would alter the
tested privilege envelope. Real pooler behavior (PgBouncer modes, application
poolers, session reset queries, disconnect/reconnect, multi-user mappings)
also remains untested; `CC-021` emulates only the same-backend reuse property.

## Privilege Boundary

`CC-027`, `CC-028`, and `CC-029` tested whether the protected writer can escape
enforcement through DDL, role escalation, or two-phase commit. The runs also
audited effective privileges instead of relying only on explicit grants.

Role topology observed in the experiment environment:

```text
commitcap_native_admin   LOGIN, SUPERUSER (trusted setup administrator)
commitcap_owner          NOLOGIN, no elevated attributes; owns the protected
                         table, trusted schemas, enforcement function, and probe
commitcap_writer         LOGIN, no elevated attributes, no role memberships
```

- `CC-027`: 27 bypass attempts were denied. Trigger disable, trigger drop,
  ownership transfer, table drop/truncate/column drop, function drop/alter/
  replace, trusted-schema create/drop/alter, shadow object creation,
  `session_replication_role` changes, and extension update/set-schema/drop all
  returned `42501 insufficient_privilege`. `CREATE EXTENSION pgcrypto` was
  denied the same way. `CREATE EXTENSION commitcap_native_tx_state` returned
  `42710 duplicate_object` because the extension already exists. An insert
  attempt against trusted accounting state returned `42501` (schema access
  denied). After the attempts, catalog checks showed the protected table owner,
  extension owner, trusted schema owners, trigger state, and enforcement
  function unchanged, and a six-event over-budget update was still denied.
- `CC-028`: the writer had zero direct memberships and zero reachable roles.
  `SET ROLE` was attempted for all 16 other roles in the cluster (trusted
  owner, setup admin, and the predefined `pg_*` roles) and returned `42501`
  each time. `SET SESSION AUTHORIZATION commitcap_owner` returned `42501`.
  `SET ROLE NONE` succeeded and left `session_user` and `current_user` as
  `commitcap_writer`.
- `CC-029`: `max_prepared_transactions` was `0`. A writer transaction that
  updated one protected row and then attempted
  `PREPARE TRANSACTION 'cc029_test'` failed with
  `55000 object_not_in_prerequisite_state` and the server message
  `prepared transactions are disabled`. `pg_prepared_xacts` stayed empty and
  the protected table stayed at baseline.

The default/public privilege audit found: writer has database `CONNECT` but
not `CREATE` or `TEMPORARY`; no `USAGE` on trusted schemas; no memberships or
reachable roles; extension owned by the trusted setup admin; trusted schemas
owned by `commitcap_owner`; no relational accounting or policy state exists in
the trusted schemas (native state is backend-local); and no `SECURITY DEFINER`
functions exist in the trusted schemas. The native enforcement and probe
functions are `SECURITY INVOKER` C functions with no SQL bodies and no
`search_path` dependence, so there is no unqualified-name or definer-owner
surface in the native path. The baseline PL/pgSQL falsification experiment
outside this directory still uses a `SECURITY DEFINER` trigger function, but
it is separate from the native path and was not modified here.

## Instrumentation

The trigger emits one `LOG` line per allowed event with the backend PID and
consumed count so that lock-wait ordering can be reconstructed from server
logs. Lifecycle callback lines also include the backend PID so callback
sequences can be attributed to one reused backend. A read-only SQL probe
reports the calling backend's own `active`, `consumed`, `denied`, and
`backend_pid` values. Neither is part of the candidate mechanism; both exist
only to make the experiment observable. The probe exposes no writable
accounting state and grants no mutation authority.

## Observed Lifecycle

Server `LOG` traces showed these callback orders:

```text
normal COMMIT:
    XACT_PRE_COMMIT
    XACT_COMMIT

normal ROLLBACK:
    XACT_ABORT

SAVEPOINT RELEASE:
    SUBXACT_START
    SUBXACT_PRE_COMMIT
    SUBXACT_COMMIT

ROLLBACK TO SAVEPOINT after denial (CC-008):
    SUBXACT_START                 -- original savepoint
    event six sets denied
    SUBXACT_ABORT                 -- denied remains true
    SUBXACT_START                 -- PostgreSQL restarts the savepoint with a new subxid
    SUBXACT_PRE_COMMIT
    SUBXACT_COMMIT
    XACT_PRE_COMMIT               -- sees denied=true and raises ERROR
    XACT_ABORT

ROLLBACK TO SAVEPOINT for allowed work (CC-009):
    SUBXACT_ABORT                 -- consumed 3 -> 0, denied=false
    SUBXACT_START                 -- restarted savepoint
    SUBXACT_PRE_COMMIT
    SUBXACT_COMMIT
    XACT_PRE_COMMIT
    XACT_COMMIT

PL/pgSQL exception recovery (CC-024):
    SUBXACT_START
    event six sets denied
    SUBXACT_ABORT                 -- denied remains true
    XACT_PRE_COMMIT               -- sees denied=true and raises ERROR
    XACT_ABORT

uncaught top-level denial:
    XACT_ABORT
```

For `CC-009`, the extension library was first loaded by an update inside the
original savepoint, so its original `SUBXACT_START` occurred before callback
registration and was not logged. Lazy frame creation still let the observed
`SUBXACT_ABORT` unwind all three allowed events.

After `CC-008`'s `ROLLBACK TO` and after `CC-024`'s exception handler, an
ordinary `SELECT` executed successfully. The poisoned state remained and the
final `COMMIT` failed. The trigger also rejects any later protected update
while `denied` is true.

## Memory Lifetime

The fixed state record is C static storage and therefore private to one
PostgreSQL backend. Subtransaction frames are explicitly allocated under
`TopMemoryContext`, not `CurTransactionContext`, so PostgreSQL does not delete
them as part of a subtransaction abort. The callbacks explicitly merge,
subtract, and free frames.

Both top-level terminal paths reset all scalar fields and free every remaining
frame:

- successful top-level completion: `XACT_EVENT_COMMIT`;
- top-level failure or rollback: `XACT_EVENT_ABORT`.

An error raised at `XACT_EVENT_PRE_COMMIT` is followed by the observed
`XACT_EVENT_ABORT`, which performs the same cleanup.

## Why The Table Mechanism Failed

The existing PL/pgSQL experiment stores event-six accounting in an ordinary
transactional table and raises its denial error in the same recoverable
subtransaction. Savepoint or PL/pgSQL exception rollback removes both the
increment and the error state. Nothing remains to prevent top-level commit.

This experiment instead keeps the sticky bit outside subtransaction-owned
memory while using explicit per-subtransaction deltas for the state that must
roll back. It does not make all accounting irreversible.

## Callback Interface

The experiment uses declarations from PostgreSQL 16.4 installed server
headers:

- `RegisterXactCallback` and `RegisterSubXactCallback` are explicitly described
  in PostgreSQL source as callbacks for dynamically loaded modules.
- `TopMemoryContext` is exported for loadable modules.
- `GetCurrentSubTransactionId`, `GetCurrentTransactionNestLevel`, and
  `IsSubTransaction` are exported accessors; the experiment does not access
  private transaction-state structures.

PostgreSQL's `CommitTransaction()` invokes `XACT_EVENT_PRE_COMMIT` before the
commit decision and describes that region as able to raise an error and enter
the abort path. PostgreSQL 16.4's in-tree `postgres_fdw` can also raise an error
from its pre-commit callback. By contrast, `XACT_EVENT_COMMIT` is after the
commit decision and is used here only for noncritical cleanup.

These are defensible server-extension interfaces, but PostgreSQL provides no
stable cross-major C ABI. The exact callback placement and behavior must be
revalidated for every proposed supported major version.

## Privilege Model

The protected writer remains a non-superuser, non-owner role. It receives
database `CONNECT`, `public` schema `USAGE`, `SELECT(id)`, and `UPDATE(status)`.
It has no trusted-schema access, direct trigger-function `EXECUTE`, table-wide
`SELECT` or `UPDATE`, unsupported DML, DDL, trigger, owner-role membership, or
mutable accounting object. The protected relation and trigger function are
owned by the separate `NOLOGIN` `commitcap_owner` role.

The concurrency experiments added one instrumentation grant: `USAGE` on the
`commitcap_probe` schema and `EXECUTE` on the read-only
`commitcap_probe.cc_native_probe()` function. The function reports only the
calling backend's own state. It cannot write accounting state, reach the
enforcement schema, or bypass the trigger.

## Known Risks And Unknowns

- Only the seventeen requested tests, lifecycle cleanup, savepoint-release,
  and concurrency lifecycle probes were run. This is not the complete Gate 1
  matrix.
- Privilege results are specific to the tested role topology and extension
  control. Deployments with different memberships, ownership, helper roles, or
  a different `max_prepared_transactions` setting remain `UNKNOWN`.
- Nested savepoint accounting beyond the tested paths remains uncharacterized.
- Interactions with other transaction callbacks, callback ordering between
  extensions, and errors from later pre-commit work remain uncharacterized.
- PostgreSQL majors other than 16.4 remain untested.
- Isolation levels other than `READ COMMITTED`, more than two concurrent
  sessions, deadlock-producing workloads, and other row-event plan shapes
  remain uncharacterized.
- Real pooler behavior is `UNKNOWN`: PgBouncer session and transaction modes,
  application poolers, session reset queries, pooler disconnect/reconnect
  behavior, and multi-user mappings were not tested. `CC-021` covers only
  same-backend reuse without disconnect or manual reset.
- Role switching on one backend was not tested; the protected writer has no
  role memberships and adding one would alter the tested envelope.
- Parallel execution, two-phase commit (`PREPARE TRANSACTION`), triggers beyond
  the one generated trigger, cascades, partitions, and `INSERT ... ON CONFLICT`
  remain untested or out of scope. MERGE is covered only for the tested
  `WHEN MATCHED THEN UPDATE` forms; other MERGE actions and source shapes
  remain untested.
- Backend termination and out-of-memory behavior were not fault-injected.
- The experiment hard-codes one relation's update budget of five and contains
  no policy, configuration, shared authority, or production installation
  design.
