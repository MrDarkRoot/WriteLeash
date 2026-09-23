# Limitations And Support Matrix

## Current Status

CommitCap has no implementation. No database operation is currently supported
or protected. This matrix separates the intended V0 proof from unknown,
unsupported, and future behavior so that specifications are not mistaken for
evidence.

Status terms:

- **V0 TARGET:** required for the first technical proof, but not implemented.
- **REQUIRED TEST:** a bypass-relevant behavior that must be characterized
  before a support claim expands.
- **UNKNOWN:** PostgreSQL or implementation behavior has not been established.
- **UNSUPPORTED:** outside the accepted V0 support envelope. Deployment must
  not imply protection. Bypass-relevant unsupported paths MUST be denied or
  excluded from the documented deployment.
- **FUTURE:** intentionally deferred beyond V0.
- **NOT IMPLEMENTED:** specified behavior has no implementation evidence.
- **KNOWN V0 LIMIT:** intentional behavior that narrows V0's security claim.

## Operation And Behavior Matrix

| Operation / behavior | Current status | Security consequence | Planned handling |
| --- | --- | --- | --- |
| Direct `UPDATE` on one ordinary non-partitioned table | **V0 TARGET; NOT IMPLEMENTED** | No row-count protection exists today | Build the smallest transaction-wide experiment; `CC-001` through `CC-007` are foundational, and every Gate 1 test in `SPEC.md` is release-blocking |
| Multiple `UPDATE` statements in one transaction | **V0 TARGET; NOT IMPLEMENTED** | A statement-local counter would be trivially bypassable | Accumulate row-update events across the top-level transaction; `CC-003` |
| Repeated `UPDATE` of the same row | **V0 TARGET; NOT IMPLEMENTED** | Distinct-row counting would undercount repeated mutation | Count every row-update event; `CC-006` |
| No-op `UPDATE` assignments | **V0 TARGET; NOT IMPLEMENTED** | Value-difference counting could create ambiguous and bypassable semantics | Count every qualifying row-update event; `CC-007` |
| `DELETE` | **UNSUPPORTED** | An update-only budget does not constrain deletion | V0 role MUST lack `DELETE`; verify with `CC-034`; future Level 1 policy |
| `INSERT` | **UNSUPPORTED** | An update-only budget does not constrain row creation | V0 role MUST lack `INSERT`; verify with `CC-035`; future Level 1 policy |
| `INSERT ... ON CONFLICT DO UPDATE` | **UNKNOWN; UNSUPPORTED** | Insert and update arms may receive inconsistent accounting | Characterize both paths in `CC-012` before any support claim |
| Data-modifying CTEs | **V0 TARGET; NOT IMPLEMENTED** | A role with `UPDATE` can use this syntax, so it cannot be excluded by privilege | Require the same update accounting in `CC-011` |
| Prepared `UPDATE` statements | **V0 TARGET; NOT IMPLEMENTED** | Preparing or repeatedly executing statements could reset statement-local state | Require the same transaction-wide accounting in `CC-025` |
| `EXPLAIN (ANALYZE)` around `UPDATE` | **V0 TARGET; NOT IMPLEMENTED** | The wrapper executes the update despite looking diagnostic | Require the same transaction-wide accounting in `CC-033` |
| `MERGE` update actions | **UNKNOWN; REQUIRED TEST** | Supported server versions may expose another update form under ordinary privileges | Require identical accounting or exclude that PostgreSQL version; `CC-026` |
| `COPY` | **UNSUPPORTED** | Bulk insert is outside an update budget | Do not claim protection; test that ungranted `COPY` paths fail closed in `CC-013` |
| `TRUNCATE` | **UNSUPPORTED** | It can remove all rows without row-level delete events | Protected writer must lack `TRUNCATE`; verify with `CC-014` |
| Foreign-key cascades | **UNKNOWN; REQUIRED TEST** | One allowed mutation may induce many unaccounted mutations | Exclude the path from V0 and verify the boundary in `CC-015`; characterize before any future support |
| Nested or recursive triggers | **UNKNOWN; REQUIRED TEST** | Trigger side effects may be missed, double-counted, or recurse | Exclude user triggers from V0 and verify the boundary in `CC-016`; characterize before any future support |
| Rewrite rules and table inheritance | **UNKNOWN; UNSUPPORTED** | Updates may be redirected or expanded outside the simple row-event model | Exclude from the V0 schema and verify the boundary in `CC-016` and `CC-018` |
| Partitioned tables and partition routing | **UNKNOWN; UNSUPPORTED** | Parent/child triggers, direct child access, and row movement can alter accounting | Test parent, child, attach/detach, and row movement in `CC-018` before support |
| Stored procedures and SQL functions | **UNKNOWN; UNSUPPORTED** | Invoker/definer context and internal DML may bypass accounting or attribution | V0 role has no `EXECUTE` on user-defined routines that can mutate the table; verify in `CC-017` |
| DDL by protected writers | **UNSUPPORTED** | A writer could disable, replace, detach, or drop enforcement | Revoke ownership and DDL capability; verify the documented role model |
| Trigger disabling or replication-role changes | **UNSUPPORTED** | Accounting may be skipped entirely | Protected writer must lack all such privileges and settings |
| `SET ROLE` and privileged role membership | **UNSUPPORTED** | The writer could assume ownership or enforcement-changing authority | Remove bypass-capable memberships and verify with `CC-028` |
| Direct policy or accounting-state writes | **UNSUPPORTED** | Writer could increase or restore its own authority | Keep state under a separate trusted owner with no direct writer grants |
| Savepoint before an over-budget mutation | **FAIL** on PostgreSQL 16.4 experiment | `ROLLBACK TO` reverted the denial and event-six accounting, then the top-level transaction committed | Current transactional PL/pgSQL mechanism does not satisfy `CC-008` |
| PL/pgSQL catches the denial error | **FAIL** on PostgreSQL 16.4 experiment | Exception recovery reverted the denial and event-six accounting, then the top-level transaction committed | Current transactional PL/pgSQL mechanism does not satisfy `CC-024` |
| Mixed protected and unprotected writes before denial | **V0 TARGET; NOT IMPLEMENTED** | Rolling back only protected state would violate whole-transaction abort semantics | No transactional effect may become durable; `CC-032` |
| Rollback of allowed work to a savepoint | **UNKNOWN; REQUIRED TEST** | Counter may remain stale or may reset too much | Restore only rolled-back allowed consumption without creating fresh authority; `CC-009` |
| Top-level rollback | **V0 TARGET; NOT IMPLEMENTED** | Stale state could contaminate later transactions | Discard relational and accounting state; verify backend reuse |
| Concurrent independent writers | **UNKNOWN; REQUIRED TEST** | Shared or session state could mix, reset, or race counters | Test isolation and contention in `CC-019` and `CC-020` |
| Shared capabilities across sessions | **FUTURE** | V0 cannot prevent aggregate overspend across transactions or sessions | Design atomic durable consumption only after V0 |
| Connection pooling | **UNKNOWN; REQUIRED TEST** | Session-local state may leak across transactions, roles, or actors | Test transaction, session, and role reuse in `CC-021` |
| Transaction retries | **KNOWN V0 LIMIT; NOT IMPLEMENTED** | Every new transaction receives fresh V0 authority; repeated committed attempts are not aggregated | Document in clients; future capability scope is required for aggregate limits; `CC-022` |
| Two-phase commit | **UNSUPPORTED** | Prepared transactions can outlive the session state used for accounting or denial | Disable it in the V0 environment and verify with `CC-029` |
| Zero and maximum budgets | **UNKNOWN; REQUIRED TEST** | Invalid bounds or integer overflow could fail open | Define a finite maximum, reject unsafe values, and pass `CC-030` and `CC-031` |
| One row across many transactions | **KNOWN V0 LIMIT; NOT IMPLEMENTED** | Per-transaction budgets do not cap cumulative mutation over time | Expected to commit when every transaction is individually within budget; `CC-010` |
| External non-transactional side effects | **UNSUPPORTED** | PostgreSQL rollback may not undo external effects | Exclude from V0 claims and avoid untrusted side-effecting extensions |
| Replication and downstream consumers | **UNSUPPORTED** | Apply behavior may differ and downstream effects are outside the primary transaction boundary | Define only after the primary-write proof |
| Semantic transition budgets | **FUTURE** | One allowed row event may still make a highly privileged state change | Future Level 2; no current protection |
| Quantitative effect budgets | **FUTURE** | Row counts do not bound monetary or other numeric impact | Future Level 3; no current protection |
| Cross-transaction consumable authority | **FUTURE** | Work split across transactions regenerates V0 authority | Future Level 4 capability model |

## Deployment Limitations

Even a future V0 implementation will not protect a relation from a writer that
is a superuser, owns the relation, owns enforcement objects, can disable
triggers, can change relevant replication settings, can alter trusted
functions, or can write policy/accounting state.

The supported PostgreSQL version range, policy installation interface, denial
SQLSTATE, and performance envelope are all `UNKNOWN` until measured and
specified.

## Reading This Matrix

An operation is not supported because it happens to fire a trigger in one
manual test. Support requires normative semantics, documented privileges, and
passing regression tests for allowed behavior, denial, atomicity, and relevant
bypass paths.

When implementation evidence changes a row in this table, update `SPEC.md`, the
threat model, and release claims in the same change.

## Phase 0 Experiment Evidence

The Phase 0 `CC-001` through `CC-003` test-only SQL and PL/pgSQL experiment was
run against PostgreSQL 16.4 using the pinned `postgres:16.4-alpine` image and
the constrained schema described in `SPEC.md`. Observed results:

- `CC-001`: PASS; five protected row-update events committed and remained
  durable.
- `CC-002`: PASS; the sixth event in one `UPDATE` raised the temporary
  budget-denial message and no protected mutation became durable.
- `CC-003`: PASS; six separate `UPDATE` statements shared one transaction-wide
  count and no protected mutation became durable after denial.

This is experimental evidence for one tested PostgreSQL version, schema, role
model, and trigger mechanism. It is not a supported V0 release claim.
Concurrency, pooling, partitions, cascades, and cross-transaction authority
remain untested or unsupported.

## Irreversible Denial Falsification Evidence

The unchanged Phase 0 trigger and transactional-accounting mechanism was tested
on PostgreSQL 16.4 using `tests/cc008.sh` and `tests/cc024.sh`:

- `CC-008`: **FAIL**. Event six raised the CommitCap denial inside a savepoint.
  `ROLLBACK TO SAVEPOINT` restored the transaction, later SQL executed, and
  `COMMIT` succeeded. Five protected mutations became durable. The failed
  event-six counter increment rolled back, leaving a durable count of five.
- `CC-024`: **FAIL**. An anonymous PL/pgSQL exception block caught the event-six
  denial under the existing protected-writer privileges. Later SQL executed and
  `COMMIT` succeeded. Five protected mutations became durable. The event-six
  counter increment again rolled back, leaving a durable count of five.

No unprotected relation was mutated in either experiment. Fresh trusted-admin
connections verified protected and accounting state after each commit.

The denial exception and accounting increment occur in ordinary transactional
state inside a recoverable PostgreSQL subtransaction. Subtransaction rollback
therefore removes the event-six accounting change and leaves no denial marker
that can irrevocably poison the top-level transaction. The current SQL and
PL/pgSQL architecture does not satisfy CommitCap's required irreversible
top-level denial semantics.

This falsifies the current mechanism, not the invariant. A later design
investigation may need to evaluate mechanism classes with state outside
recoverable subtransactions, such as backend-local non-transactional state or
top-level transaction callbacks and hooks. No replacement mechanism is
implemented or selected by this experiment.

## Native Transaction-State Feasibility Evidence

A separate, research-scoped C extension experiment under
`experiments/native_tx_state/` was run on PostgreSQL 16.4. It used
backend-local top-level state, per-subtransaction allowed-consumption deltas,
`RegisterSubXactCallback`, and `RegisterXactCallback`. Observed results were:

- `CC-001`, `CC-002`, and `CC-003`: **PASS** with the same budget-five update
  behavior and fresh-connection durable verification.
- `CC-004`: **PASS**. A denied transaction with five allowed updates and a
  denied sixth event did not commit; a fresh admin connection observed ten
  baseline rows and zero `cc004` or `cc004_excess` rows.
- `CC-005`: **PASS**. An `UPDATE` qualifying zero rows reported `UPDATE 0`,
  left native state at `consumed=0, denied=false`, and five later events
  committed with zero `cc005_zero` rows durable.
- `CC-006`: **PASS**. Five separate updates of the same row each consumed one
  unit; the sixth same-row event was denied and nothing became durable. V0
  counts row-update events, not distinct row identities.
- `CC-007`: **PASS**. Five no-op assignments fired the enforcement trigger
  five times and consumed five units; a sixth no-op assignment was denied and
  nothing became durable. Accounting does not depend on `OLD`/`NEW` value
  equality.
- `CC-008`: **PASS**. Event six set a backend-local denied flag. Savepoint
  abort preserved that flag, later `SELECT` execution was possible, and
  `XACT_EVENT_PRE_COMMIT` rejected final commit. A fresh connection observed no
  durable protected mutation.
- `CC-009`: **PASS**. Aborting three allowed savepoint events subtracted that
  subtransaction's delta from live consumption. Five replacement events then
  committed exactly at the budget.
- `CC-011`: **PASS**. A data-modifying CTE updating five protected rows
  committed; a six-row CTE raised the event-six denial and no protected
  mutation became durable.
- `CC-019`: **PASS** on PostgreSQL 16.4 with two distinct backends at
  `READ COMMITTED`. Each open transaction counted four events independently;
  neither backend's consumption, commit, abort, or denial changed the other's
  accounting. Both committed after their fifth event, and a denial in one
  backend left the other able to commit in both directions. New transactions
  in both backends started fresh afterward.
- `CC-020`: **PASS** on PostgreSQL 16.4 under row-lock contention. Waiting
  backends were confirmed blocked on the other transaction's lock, counted no
  event while blocked, and consumed exactly one unit for the contended
  row-update event once they resumed. A backend denied under contention could
  not commit, and the other backend kept its own accounting and committed.
  `pg_stat_database.deadlocks` stayed unchanged. Only two-session scenarios at
  `READ COMMITTED` were tested.
- `CC-021`: **PASS** on PostgreSQL 16.4 for the tested same-backend reuse
  paths. One persistent backend, with the same `pg_backend_pid()` before and
  after, served independent transactions following a commit, a rollback, a
  denial abort, a savepoint-recovered sticky denial, a PL/pgSQL caught denial,
  and autocommit statements. Every next top-level transaction began at
  `consumed=0, denied=false`. No reset function and no client disconnect were
  required. Real pooler behavior and role switching on one backend remain
  untested.
- `CC-024`: **PASS**. PL/pgSQL exception recovery aborted its internal
  subtransaction without clearing the denied flag. The final commit was
  rejected and a fresh connection observed no durable protected mutation.
- `CC-025`: **PASS**. One prepared `UPDATE` executed five times committed; the
  sixth execution in the same transaction raised the denial and no protected
  mutation became durable. Preparation and repeated execution did not create
  fresh authority.
- `CC-026`: **PASS** on PostgreSQL 16.4 native experiment for tested
  `MERGE ... WHEN MATCHED THEN UPDATE` forms. The existing writer grants
  (`UPDATE(status)`, `SELECT(id)`) were sufficient and no privilege was
  broadened. Five MERGE update actions consumed five events and committed; six
  were denied and nothing became durable. `EXPLAIN (ANALYZE)` reported five
  enforcement-trigger calls for five MERGE actions. An ordinary `UPDATE` plus
  MERGE, and multiple MERGE statements, shared one transaction budget.
  `WHEN NOT MATCHED` actions, `DELETE` actions, conditional branches, and other
  source shapes remain untested.
- `CC-027`: **PASS** on PostgreSQL 16.4 native experiment under the tested role
  topology. Twenty-seven writer attempts to disable or drop enforcement, alter
  or replace the trusted function, create or drop objects in trusted schemas,
  transfer ownership, shadow objects, change `session_replication_role`, or
  alter/drop the extension were denied (`42501`, except the duplicate-extension
  attempt `42710`). Trusted ownership, trigger state, and enforcement behavior
  were unchanged, and a post-attack over-budget update was still denied.
- `CC-028`: **PASS**. The protected writer had no role memberships and no
  reachable roles. `SET ROLE` was denied for every other role in the cluster
  (trusted owner, setup admin, and all predefined `pg_*` roles), and
  `SET SESSION AUTHORIZATION` was denied. `SET ROLE NONE` left the writer
  identity unchanged.
- `CC-029`: **PASS**. The tested environment had
  `max_prepared_transactions = 0`. `PREPARE TRANSACTION` from the protected
  writer failed with `55000 object_not_in_prerequisite_state`
  (`prepared transactions are disabled`), `pg_prepared_xacts` remained empty,
  and no protected mutation became durable.
- `CC-032`: **PASS**. An insert into an unprotected relation executed before
  event six was rolled back with the protected changes when
  `XACT_EVENT_PRE_COMMIT` rejected the poisoned transaction. A fresh
  connection observed baseline protected rows and zero audit rows.
- `CC-033`: **PASS**. `EXPLAIN (ANALYZE, COSTS OFF)` executed the wrapped
  protected update and reported five calls on the enforcement trigger; the
  five-row form committed and the six-row form was denied with no durable
  protected mutation.
- Same-backend probes after top-level commit and top-level abort both began the
  next transaction with fresh state and allowed five events.
- Each alternate-path denial (`CC-011`, `CC-025`, `CC-033`) was followed in the
  same backend by a transaction that consumed five events and committed.
  Because those denials were not recovered with a savepoint or exception
  handler, PostgreSQL put the top-level transaction in aborted state, so the
  client's `COMMIT` returned `ROLLBACK` rather than reaching
  `XACT_EVENT_PRE_COMMIT`. Pre-commit rejection after recovery remains covered
  by `CC-008`, `CC-024`, and `CC-032`.

The experiment allocated subtransaction frames in `TopMemoryContext`, outside
the automatically deleted subtransaction context. `SUBXACT_EVENT_ABORT_SUB`
unwound only allowed consumption; denial remained sticky.
`XACT_EVENT_PRE_COMMIT` ran before PostgreSQL's commit decision and raised the
error that forced the top-level abort. Observed `XACT_EVENT_COMMIT` and
`XACT_EVENT_ABORT` callbacks cleared all experiment state.

The concurrency runs used a read-only probe function, granted to the protected
writer, that reports the calling backend's own counters. It exposes no writable
authority. The runs also showed that PostgreSQL 16.4 locks a contended row and
applies EvalPlanQual before firing the BEFORE ROW trigger, so one contended
row-update event consumed one unit. Other isolation levels, other plan shapes,
and deadlock-producing workloads remain untested. The reuse runs covered
same-backend transaction reuse without disconnect or manual reset only; real
pooler modes, session reset queries, disconnect/reconnect behavior, multi-user
mappings, and role switching on one backend remain `UNKNOWN`.

The native enforcement and probe functions are `SECURITY INVOKER` C functions
with no SQL bodies or `search_path` dependence; no `SECURITY DEFINER` function
exists in the trusted schemas. The privilege results above are limited to the
tested role topology, extension control, and `max_prepared_transactions = 0`;
other deployment topologies remain `UNKNOWN`.

This result makes backend-local callback state **viable for further testing as
a mechanism class only**. It does not replace the falsified PL/pgSQL evidence,
change any operation to supported, select a production architecture, or
establish behavior outside PostgreSQL 16.4 and the experiment's narrow test
envelope. PostgreSQL exposes these facilities to dynamically loaded modules,
but its server C API is not a stable cross-major ABI. Full research notes and
remaining unknowns are recorded in the experiment README.
