# Native Transaction-State Experiment

> **Test-ID notice:** The historical row-event results in this file use
> **legacy experiment IDs**, which are not equivalent to the canonical current
> IDs in [`docs/test-plan.md`](../../docs/test-plan.md). The canonical
> state-transition section added later (`CC-020`–`CC-022`) is explicitly marked
> and is distinct from the legacy `CC-020`/`CC-021` concurrency and
> backend-reuse labels recorded elsewhere in this historical file.

## Classification

`TEST FIRST`. This is a Phase 0 feasibility spike, not a production extension
and not a V0 support claim.

This experiment follows ADR-006's SQL/PLpgSQL-first sequence. Ordinary
transactional PL/pgSQL denial state was tested first and failed the savepoint
and caught-exception falsification cases because subtransaction rollback erased
the denial marker. Native callbacks were investigated only after that evidence.
This work does not select a production extension architecture.

It is also not managed-PostgreSQL feasibility evidence. Extension installation,
trusted privileges, `shared_preload_libraries`, native module availability,
deployment, upgrades, and operational constraints require a separate check in
at least one realistic managed environment.

The experiment answers one question on PostgreSQL 16.4: can backend-local
state and transaction callbacks provide rollback-aware allowed consumption and
sticky top-level denial at the same time?

A later addition to the same mechanism answers a second question: can a
state-transition denial share the same sticky top-level denial while allowed
transitions still consume the transaction-wide row-update budget?

The issue #9 addition tests independent subscriptions/users row counters and
one fixed-rule `refunds.amount` positive-delta budget, in the **same research
mechanism**. The two row policies each have a test-only limit of five; refunds
has a separate test-only numeric limit of 100.00. Only the sticky-denied bit is
shared. A passing local run does not establish supported production behavior.

## Reproduce

From the repository root:

```bash
./experiments/native_tx_state/run.sh
```

The script builds a disposable image from `postgres:16.4-alpine`, initializes
the constrained role and table fixture, runs the tests, prints server-side
callback traces, and removes the container and volume. Every durable-state
assertion opens a fresh trusted-admin connection.

## V0 generic UPDATE row budget (#47)

The separate `commitcap_native.enforce_rows_updated('5')` trigger function is a
review candidate for ordinary nonpartitioned PostgreSQL **16.4** heap tables
not named in the C source. A trusted table owner, not the protected writer,
installs **one unconditional enabled `BEFORE UPDATE FOR EACH ROW` trigger**:

```sql
CREATE TRIGGER commitcap_rows_updated
BEFORE UPDATE ON public.repair_items
FOR EACH ROW
EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');
```

The argument is exactly one canonical unsigned base-10 integer in the range
`0..2147483647` (no spaces, sign, leading zeroes, decimal point or exponent).
Zero denies the first UPDATE row event. Each successful BEFORE UPDATE row
trigger invocation consumes one event, including repeated or no-op updates;
a zero-row UPDATE consumes none. The captured budget and count are keyed by
relation OID, not a table-name argument or the test-only
`commitcap_native.test_budget` GUC. An over-budget row sets backend-local
sticky denial before raising SQLSTATE `54000`. Savepoint/PLpgSQL exception
recovery cannot clear it; the top-level COMMIT is rejected and no writes in
that transaction become durable. Only permitted consumption rolls back with
a subtransaction. The first violated policy is retained as a catalog-derived,
qualified display label in long-lived memory; it contains no row values.

The trusted installer must verify the trigger is enabled, unique for that
relation, **not combined with another CommitCap trigger on that relation**,
unconditional (no `WHEN` or `UPDATE OF`), and that the restricted
writer cannot own/alter the table, disable the trigger, manage the trusted
function/schema, set replication bypass parameters or access another mutation
path (including INSERT, DELETE and TRUNCATE). Runtime rejects malformed,
duplicate, legacy/product mixtures or unsupported product triggers **when
they fire**; a zero-row UPDATE
cannot check a trigger that did not fire. Conditional/disabled triggers can
skip rows entirely and are **not** supported configurations. Ordinary schema
DDL by a trusted admin, partition/inheritance routing, arbitrary user trigger
graphs, and alternate actor/grant models have no coverage claim.

Run the focused tests with `bash experiments/native_tx_state/product_update_run.sh`
(optionally `PRODUCT_REPETITIONS=20`); the full existing suite also sources
these assertions. The tests create `cc_product_alpha`, `cc_product_beta`, and
same-named tables in two schemas, use different limits, verify denial and
same-backend lifecycle, and inspect denied durable state from **fresh trusted
admin connections**. This is a security-review fixture, not the product
first-run demo (tracked in #48). The older `enforce_update_budget()` test GUC,
fixed `users.role` transition and `refunds.amount` numeric experiments are
research-only; they have not become arbitrary-table product policies. No
cross-transaction, managed-service, other-version or production claim follows.

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
`CommitCap top-level transaction denied after mutation authority violation`.
PostgreSQL then takes the top-level abort path. `XACT_EVENT_COMMIT` and
`XACT_EVENT_ABORT` perform backend-local cleanup.

A second trigger function, `enforce_role_transition`, enforces one test-only
state-transition rule on the `users` table: an `UPDATE` whose new value for the
text `role` column is `admin` is denied (`* -> admin`). The denied event sets
the same sticky `denied` flag and raises
`CommitCap forbidden state transition (* -> admin)` before consuming
row-update authority. Allowed transitions fall through to the shared
`record_protected_event` accounting, so they consume the normal
transaction-wide row-update budget and are not double-counted.

The experiment environment preloads the extension
(`shared_preload_libraries` in the compose service) so its callbacks and
test-only configuration parameters are defined in every backend. A
subtransaction frame is still created lazily on the first protected event
inside that subtransaction, and subtransaction callbacks are not logged while
the transaction has no protected events yet.

## Results

All `CC-*` labels in the legacy row-event table below are **legacy experiment
IDs**; see the notice at the top of this file. The separately marked canonical
state-transition tests use current `docs/test-plan.md` IDs.

Observed on PostgreSQL 16.4:

| Test | Result | Evidence |
| --- | --- | --- |
| `CC-001` | **PASS** | Five events committed and a fresh connection observed five durable changes |
| `CC-002` | **PASS** | Event six denied one six-row statement and a fresh connection observed the baseline |
| `CC-003` | **PASS** | Five statements consumed one shared budget; statement six denied and nothing became durable |
| `CC-004` | **PASS** | A denied transaction with five allowed updates and a denied sixth event left a fresh admin connection ten baseline rows and zero `cc004`/`cc004_excess` rows |
| `CC-005` | **PASS** | `UPDATE ... WHERE id = 999999` reported `UPDATE 0`, left `consumed=0, denied=false`, and five later events committed |
| `CC-006` | **PASS** | Five updates of the same row each consumed one unit; the sixth same-row event was denied and row 1 stayed baseline |
| `CC-007` | **PASS** | Five no-op assignments fired the trigger five times and consumed five units; a sixth no-op assignment was denied |
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
| `CC-030` | **PASS** | Test-only budget 0 denied the first protected event (`limit 0, attempted 1`); the transaction aborted and the restored default budget still denied a sixth event |
| `CC-031` | **PASS** | Negative, max+1, huge, and non-numeric inputs rejected with `22023`; maximum accepted; near-maximum counter denied at `attempted 2147483648` without wrap |
| `CC-032` | **PASS** | An unprotected insert before denial was rolled back with the protected changes; a fresh connection observed zero audit rows |
| `CC-033` | **PASS** | `EXPLAIN (ANALYZE, COSTS OFF)` executed the protected update, the plan reported `calls=5` on the enforcement trigger, and the six-row form was denied |

### Canonical state-transition tests (`CC-020`–`CC-022`)

These three labels are canonical [`docs/test-plan.md`](../../docs/test-plan.md)
IDs, not legacy labels. They are distinct from the legacy `CC-020`/`CC-021`
concurrency and backend-reuse results in the table above.

| Test | Result | Evidence |
| --- | --- | --- |
| `CC-020` | **PASS** | `member -> moderator` committed; one row-update event consumed; sibling rows stayed `member` |
| `CC-021` | **PASS** | `member -> admin` denied; savepoint and PL/pgSQL exception recovery kept `denied=true`; `COMMIT` was rejected at `XACT_EVENT_PRE_COMMIT`; no durable change |
| `CC-022` | **PASS** | A three-row `UPDATE` with one `admin` row was denied by transition authority while within the row-count budget; allowed sibling rows did not become durable |

Composition checks also passed on PostgreSQL 16.4:

- An allowed transition consumed exactly one row-update event and shared the
  transaction-wide budget: five allowed transitions consumed the budget and a
  sixth event was denied with the budget message, aborting the whole
  transaction with no durable change.
- A forbidden transition was denied with the full budget remaining, and a later
  protected event after recovery was rejected as already denied.
- After a transition denial, the next top-level transaction started at
  `active=false, consumed=0, denied=false`.

The same-backend cleanup probes also passed:

- A transaction consumed three and committed; the next transaction consumed
  five and committed.
- A transaction consumed three and rolled back; the next transaction consumed
  five and committed.

These results make this mechanism class **VIABLE FOR FURTHER TESTING**. They do
not make the experiment production-ready or any operation supported.

### Canonical numeric-delta suite and independent policies (issue #9)

The regression and canonical numeric cases live in `numeric_cases.sh`, sourced
by `run.sh` **after** the original tests. In this section alone `CC-030` through
`CC-033` mean the canonical [test-plan](../../docs/test-plan.md) numeric cases;
the same labels printed by older row-budget portions of `run.sh` remain
**legacy experiment IDs**. The sourced script checks writer outcomes, probes
the three independent backend-local counters, and checks committed/aborted rows
from separate trusted-admin connections. The complete sanitized
[tested-commit transcript](evidence/2026-09-23-cc030-cc033-7c1eb35.txt)
records command, image digest, server version, `7c1eb35` tested SHA, skips,
callback traces, durable results, and exit status 0. The two operation orders,
canonical CC-030–033 and approved boundary cases passed within this fixture;
they do not prove production support or managed deployment.

Approved fixture-only semantics are documented in
[`docs/numeric-delta-decision-proposal.md`](../../docs/numeric-delta-decision-proposal.md):
`delta = NEW.amount - OLD.amount` per UPDATE row effect, cumulative positive
part without netting; no numeric authority spent on negative/no-op effects;
allowed subtransaction rollback unwinds only provisional consumption; any
violation makes denial sticky through `XACT_EVENT_PRE_COMMIT`. The amount column
is unconstrained `numeric` **without typmod rounding**. The BEFORE UPDATE C
trigger rejects NULL, NaN, ±Infinity, negative, >16 integer digits or >2
fractional digits before accounting. The test-only `PGC_SUSET` numeric budget
GUC requires a nonnegative decimal spelling with exactly two fractional digits
and at most 16 integer digits (max `9999999999999999.99`). The writer cannot
change it. It is a PostgreSQL relational measurement, not proof of any funds
transfer. The experiment has no numeric INSERT/DELETE policy: the writer lacks
those privileges on `refunds`.

The original `cc_native_probe().consumed` remains a **legacy aggregate
instrumentation value**, not a budget. `cc_native_policy_probe()` reports
`subscriptions_consumed`, `users_consumed`, `refunds_positive_delta`, and
`denied` for independent accounting checks. Allowed effects from separate
policies are counted separately even when written in one transaction; the
same sticky-denied bit invalidates all of them on an over-budget attempt.

## Row-Event Definition

`CC-004` through `CC-007` closed the foundational definition of a V0
row-update event on PostgreSQL 16.4.

- `CC-004`: a transaction with five allowed updates followed by a denied sixth
  event did not commit. A fresh admin connection observed ten baseline rows and
  zero `cc004` or `cc004_excess` rows.
- `CC-005`: `UPDATE ... WHERE id = 999999` reported `UPDATE 0`, and the probe
  read `consumed=0, denied=false`. Five later events consumed the full budget,
  committed, and produced exactly five durable changes with zero `cc005_zero`
  rows.
- `CC-006`: five separate updates of row `id = 1` each consumed one unit
  (probe `consumed=5`, five `UPDATE 1` command tags). The sixth same-row update
  was denied at event six and the transaction did not commit; row 1 stayed
  baseline.
- `CC-007`: five no-op assignments (`SET status = 'baseline'` where the value
  was already `baseline`) fired the enforcement trigger five times
  (`EXPLAIN (ANALYZE)` reported `calls=5`) and consumed five units. A sixth
  no-op assignment was denied at event six and nothing became durable.

After each denied case, a fresh top-level transaction started at
`consumed=0, denied=false` and committed five events. Accounting does not
depend on `OLD`/`NEW` value equality or on distinct row identity.

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

## Numeric-Delta Row-Lock Contention

The issue #9 numeric review required a deterministic two-session refunds
contention regression in the same harness. `numeric_cases.sh` drives the two
`commitcap_writer` sessions through the same named pipes, asserts a real lock
wait from a separate admin connection (`wait_event_type=Lock`,
`pg_blocking_pids()` naming the holder), and re-verifies every durable amount
from a fresh trusted-admin connection. Three scenarios ran on
PostgreSQL 16.4 under `READ COMMITTED`:

- Scenario A (allowed): A committed +70.00 on row 1 while B waited. B's
  resumed `amount=120.00` observed the post-lock OLD value 70.00 and consumed
  exactly 50.00; a stale snapshot OLD of 0.00 would have consumed 120.00 and
  falsely denied B. Both sessions committed with independent numeric state
  (A 70.00, B 50.00), and the fresh admin observed `1=120.00`.
- Scenario B (denial): B pre-consumed 50.00, then A committed
  +90.00/-60.00 (gross +90.00, final 30.00) on the contended row. B's resumed
  `amount=100.00` computed the post-lock delta 70.00 against remaining 50.00
  and was denied; a stale OLD of 90.00 would have computed 10.00 and falsely
  allowed. `ROLLBACK TO SAVEPOINT` kept `denied=true`, a later subscription
  update was rejected with `top-level transaction already denied`, the top-level
  `COMMIT` was rejected, and the fresh admin observed A's `1=30.00` with B's
  sibling subscription unchanged.
- Scenario C (rollback): A raised row 1 to 80.00 and rolled back while B
  waited. B's resumed `amount=40.00` consumed exactly 40.00 against the last
  committed 0.00 version; the aborted 80.00 version would have produced a
  negative delta and zero consumption.

Allowed numeric effects emit no server-log event line, so exactly-once
post-lock trigger evaluation is proven by the exact resumed
`refunds_positive_delta`, single `UPDATE 1` command tags, unchanged
`pg_stat_database.deadlocks`, and exact durable rows rather than log counts.
PostgreSQL 16.4 evaluates the BEFORE ROW trigger only after the tuple lock and
EvalPlanQual, so each contended effect measured its actual locked OLD→NEW
exactly once.

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

## Budget Bounds And Overflow Safety

`CC-030` and `CC-031` tested zero budgets, configuration validation, and
counter overflow safety.

Native representation observed in the extension:

```text
budget (GUC input):    int (int32), range 0 .. 2147483647
budget (captured):     uint64
consumed:              uint64
subtransaction delta:  uint64
attempted diagnostic:  uint64 consumed + 1
```

The implementation test maximum is `INT_MAX` (2147483647). It was chosen
because PostgreSQL's custom integer GUC interface is 32-bit; counters are
`uint64`, and the decision uses check-before-increment
(`consumed >= budget`), so no addition can overflow. `consumed` only
increments while it is below the captured budget, so it can never exceed the
maximum. The attempted-count diagnostic is computed in `uint64` and is at
most `INT_MAX + 1`.

Test-only configuration is provided by two `PGC_SUSET` GUCs defined by the
preloaded extension:

- `commitcap_native.test_budget` (default 5, range 0..2147483647)
- `commitcap_native.test_seed_consumed` (default -1, range -1..2147483647)

The trusted admin stores values for the protected writer with
`ALTER ROLE commitcap_writer SET ...`. The writer cannot `SET`, `RESET`,
`ALTER ROLE`, or `ALTER DATABASE` them; all four attempts returned
`42501 insufficient_privilege`. These parameters are experiment controls, not
a product interface.

Observed on PostgreSQL 16.4:

- Budget 0: the first protected event was denied with
  `limit 0, attempted 1`; the transaction did not commit and a fresh admin
  connection observed baseline. After restoring the default budget, a new
  transaction consumed five events and committed, and a six-event transaction
  was denied.
- Negative (`-1`), maximum + 1 (`2147483648`), grossly out-of-range
  (`999999999999999999999999`), non-numeric text, and out-of-range fractional
  input (`2147483647.9`) were all rejected with SQLSTATE
  `22023 invalid_parameter_value`; the previously stored valid budget
  remained unchanged and still enforced at five.
- Maximum (`2147483647`): accepted.
- Near maximum (`seed=2147483646`, `budget=2147483647`): the first event
  consumed to `2147483647` and was allowed; the second event was denied with
  `limit 2147483647, attempted 2147483648`. No wrap occurred. A separate
  transaction committed one event at `consumed=2147483647`.

PostgreSQL's integer GUC grammar rounds in-range fractional input to an
integer (`'1.5'` activates as 2, `'1e2'` as 100, `'-0.5'` as 0), while values
that round outside the range are rejected. The activated budget is therefore
always an integer in `[0, 2147483647]`. This is PostgreSQL parameter grammar,
not counter arithmetic.

## Instrumentation

The trigger emits one `LOG` line per allowed event with the backend PID and
consumed count so that lock-wait ordering can be reconstructed from server
logs. Lifecycle callback lines also include the backend PID so callback
sequences can be attributed to one reused backend. A read-only SQL probe
reports the calling backend's own `active`, `consumed`, `denied`, and
`backend_pid` values. Neither is part of the candidate mechanism; both exist
only to make the experiment observable. The probe exposes no writable
accounting state and grants no mutation authority.

## Denial Evidence

Issue #34 added human-readable denial reporting without changing any enforcement
decision. The backend-local state records the first violated policy
(`denial_kind`); later denials never overwrite the original cause. Denial errors
keep their existing SQLSTATE `54000` and primary message, and now carry an
`errdetail` block:

```text
ERROR:  CommitCap mutation budget exceeded (limit 5, attempted 6)
DETAIL:  CommitCap denied transaction
policy / metric: subscriptions.rows_updated
granted: 5
consumed before attempt: 5
attempted effect: 6 row-update events
result: DENIED; top-level COMMIT will be rejected
```

- Row-budget denials report the independently keyed policy
  (`subscriptions.rows_updated` or `users.rows_updated`), the captured budget,
  consumption before the attempt and the attempted row-update event count.
- Transition denials report `users.role (* -> admin)` and the attempted
  forbidden transition; there is no numeric budget for that policy, so no
  `granted` or `consumed` value is invented.
- Numeric denials report `refunds.amount positive_delta` with the exact
  unrounded decimal spelling of the configured budget, the positive delta
  consumed before the attempt and the attempted event delta, produced with
  `numeric_out`.
- An invalid refund amount cannot be measured, so that path reports only the
  policy and result rather than a fabricated attempted value.
- A protected event arriving after a denial reports the original policy.
- The `XACT_EVENT_PRE_COMMIT` rejection repeats the policy and adds
  `result: ABORTED`.

The values come only from backend-local enforcement state. No protected row
value, table text, role string or other writer-controlled text is interpolated
into the detail, and the writer cannot modify the state or the messages. The
addition does not change sticky denial, the pre-commit rejection path, allowed
consumption accounting, or any SQLSTATE; the integrated suite asserts both the
evidence fields and the unchanged durable-state oracles for the row, transition
and numeric policies, including savepoint and PL/pgSQL exception recovery.

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

For `CC-009`, the original savepoint was created before the transaction had
any protected events, so its `SUBXACT_START` was not logged. The frame was
created lazily by the first event inside the savepoint, and the observed
`SUBXACT_ABORT` unwound all three allowed events.

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
database `CONNECT`, `public` schema `USAGE`, `SELECT(id)` and `UPDATE(status)`
on `subscriptions`, and `SELECT(id)` and `UPDATE(role)` on `users`. It has no
trusted-schema access, direct trigger-function `EXECUTE` for either enforcement
function, table-wide `SELECT` or `UPDATE`, unsupported DML, DDL, trigger,
owner-role membership, or mutable accounting object. Both protected relations
and both trigger functions are owned by the separate `NOLOGIN`
`commitcap_owner` role.

For the numeric test fixture, `commitcap_writer` additionally receives
`SELECT(id, amount), UPDATE(amount)` on `refunds`, not INSERT/DELETE/TRUNCATE,
DDL, trusted-schema access, enforcement-function EXECUTE, or access to change
the superuser-only numeric-budget parameter. The trusted owner owns the
`refunds` table and native trigger function. A second read-only probe reports
the caller's own three counters and denied flag.

The concurrency experiments added one instrumentation grant: `USAGE` on the
`commitcap_probe` schema and `EXECUTE` on the read-only
`commitcap_probe.cc_native_probe()` function. The function reports only the
calling backend's own state. It cannot write accounting state, reach the
enforcement schema, or bypass the trigger.

## Known Risks And Unknowns

- Only the twenty-three legacy requested tests, the canonical state-transition
  tests, lifecycle cleanup, savepoint-release, and concurrency lifecycle probes
  were run. This is not the complete Gate 1 matrix.
- The state-transition rule is a single test-only comparison: any new `admin`
  value in a text/varchar `role` column of the `users` fixture is denied. It is
  not a policy language, and other columns, roles, transition sources, and
  wildcard or multi-rule policies remain untested.
- State-transition denial was tested only on PostgreSQL 16.4, only with the
  `member -> moderator` allowed path and the `* -> admin` denied path, only on
  a single-row and a three-row `UPDATE`, and only with the tested role grants.
  Concurrent state-transition sessions and `admin -> admin` or `admin -> *`
  paths were not tested.
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
- Parallel execution, actual two-phase commit with prepared transactions
  enabled, triggers beyond the one generated trigger, cascades, partitions,
  and `INSERT ... ON CONFLICT` remain untested or out of scope. The fail-closed
  exclusion of `PREPARE TRANSACTION` was tested only with
  `max_prepared_transactions = 0`. MERGE is covered only for the tested
  `WHEN MATCHED THEN UPDATE` forms; other MERGE actions and source shapes
  remain untested.
- Backend termination and out-of-memory behavior were not fault-injected.
- This fixture implements only two fixed row-policy keys (subscriptions and
  users), one fixed numeric key (refunds.amount), and test-only configuration.
  There is no general policy engine, shared capability authority, or production
  installation design. Alternate schema/trigger graphs and numeric INSERT or
  DELETE remain untested/unsupported; do not infer safety from this fixture.
