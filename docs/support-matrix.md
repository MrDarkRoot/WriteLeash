# CommitCap Research Preview — Support, Compatibility, Performance and Security Matrix

Last updated: 2026-09-25.

This is the single public page for the current CommitCap research state. It
records only behavior supported by merged repository evidence.
[README.md](../README.md) is the project overview, [docs/spec.md](spec.md)
defines intended semantics, [docs/test-plan.md](test-plan.md) defines canonical
test IDs, and [docs/limitations.md](limitations.md) preserves the historical
evidence. Nothing here is a production-ready security control.

## Status legend

| Status | Meaning |
| --- | --- |
| **TESTED** | Demonstrated by passing assertions in the pinned PostgreSQL 16.4 research fixture, within the stated topology. **Not** a release support claim. |
| **SUPPORTED** | Reserved for a released, documented support envelope. **No entry currently qualifies.** |
| **NOT TESTED** | No evidence exists. Do not infer behavior. |
| **UNSUPPORTED** | Outside the current guarantee. Deployment must not imply protection. |
| **BLOCKED** | Denied by privileges/configuration for the exact tested topology, or blocked by a documented provider model. |
| **INCONCLUSIVE** | Evidence exists but does not support a conclusion. |

The current state is a **research mechanism / Research Preview**, not a
supported release: see [Product and release status](#e-product-and-release-status).

## A. Enforcement envelope (TESTED only within this envelope)

Everything below was demonstrated on **PostgreSQL 16.4** in the local Docker
fixture, with the `postgres:16.4-alpine` image pinned to digest
`sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`
([CI pin](../.github/workflows/native-pg16-security.yml),
[setup.sql](../experiments/native_tx_state/setup.sql)). The integrated suite is
[run.sh](../experiments/native_tx_state/run.sh); CI runs it (and the demo) on
every pull request, and an archived integrated transcript from the pre-#34
suite is
[evidence/2026-09-23-integrated-audit-e97f19b.txt](../experiments/native_tx_state/evidence/2026-09-23-integrated-audit-e97f19b.txt).
The one-command demo is [./demo.sh](../demo.sh) plus
[demo/phase0/README.md](../demo/phase0/README.md).

| Area | Status | Exact tested behavior and evidence |
| --- | --- | --- |
| PostgreSQL version | **TESTED** | Only 16.4. No other major or minor. The suite and demo print `PostgreSQL: 16.4` and verify the pinned image. |
| Authority scope | **TESTED (transaction-local)** | Per top-level transaction only. Budgets reset at each new transaction. Splitting work across committed transactions is not prevented by this mechanism. |
| Row-count authority | **TESTED** | Independently keyed `subscriptions.rows_updated` and `users.rows_updated` limits (fixture: 5). One broad `UPDATE`, statement decomposition, repeated updates of one row, zero-row and no-op updates, writable CTE, prepared `UPDATE`, `EXPLAIN (ANALYZE)` and tested `MERGE ... WHEN MATCHED THEN UPDATE` forms share one transaction budget and abort on excess. [evidence](../experiments/native_tx_state/README.md#row-event-definition) |
| State-transition authority | **TESTED** | One fixed rule: any `UPDATE` of the text `users.role` to `admin` is denied (`* -> admin`); `member -> moderator` is allowed. Bulk and mixed transitions, savepoint recovery and caught exceptions remain sticky. Other columns/roles/rules are not tested. [evidence](../experiments/native_tx_state/README.md#canonical-state-transition-tests-cc-020cc-022) |
| Numeric positive-delta authority | **TESTED** | `refunds.amount` exact-decimal positive delta with fixture budget `100.00`; values below/at/above budget, decomposed statements, gross-vs-net oscillation, CTE/prepared/`EXPLAIN` forms, and two-session row-lock contention scenarios A/B/C. `numeric` without typmod; server-validated finite, nonnegative, 16 integer / 2 fractional digits. [decision semantics](numeric-delta-decision-proposal.md) |
| Independent per-policy accounting | **TESTED** | All three policies can spend independently in one top-level transaction in either order (`5\|1\|20.00` and `5\|1\|20.00`), and a single-policy violation leaves the other policies' consumption independently accounted. [coverage](phase0-security-coverage.md) |
| Sticky denial / recovery | **TESTED** | A denied event sets backend-local denial that `ROLLBACK TO SAVEPOINT` and PL/pgSQL exception handling cannot clear; the top-level `COMMIT` is rejected at `XACT_EVENT_PRE_COMMIT` with SQLSTATE `54000`. [lifecycle](../experiments/native_tx_state/README.md#observed-lifecycle) |
| Denial evidence (issue #34) | **TESTED** | Denials carry policy-scoped `DETAIL` evidence (policy/metric and, only where exactly known, granted budget, consumed-before-attempt and attempted effect) plus `result: ABORTED` at commit rejection. Reporting only; decisions and SQLSTATEs unchanged. [behavior](../experiments/native_tx_state/README.md#denial-evidence), [limitations](limitations.md#native-transaction-state-feasibility-evidence) |
| Restricted-writer boundary | **TESTED (this topology)** | `commitcap_writer` is a non-superuser, non-owner, membership-free login with column grants only. It cannot disable/drop triggers, replace functions, alter/own protected objects, change replication settings, `SET ROLE`, or change the test GUCs (SQLSTATE `42501`). [privilege boundary](../experiments/native_tx_state/README.md#privilege-boundary) |
| Concurrency | **TESTED (2 sessions, READ COMMITTED)** | Two writer backends keep independent accounting; verified lock waits; no accounting transfer between sessions; no deadlock count change. Numeric contention A/B/C verified post-lock delta accounting. Other isolation levels, more sessions and deadlock-producing workloads are not tested. [contention](../experiments/native_tx_state/README.md#concurrent-sessions) |
| Fresh trusted durable-state verification | **TESTED** | Every durable assertion opens a new trusted-admin connection and compares protected rows plus a sibling audit count; writer probes and logs are never the oracle. [suite](../experiments/native_tx_state/run.sh) |
| Backend reuse | **TESTED (same backend, no disconnect)** | Next top-level transaction after commit, rollback, denial abort, recovered denial and caught denial starts fresh. Real poolers and role switching are not tested. [backend reuse](../experiments/native_tx_state/README.md#backend-reuse) |

**Not covered by the table above:** anything outside the precisely tested
fixture, version, role topology and statement shapes. In particular, a green
suite run is not a claim that arbitrary SQL is protected.

## B. Unproven / unsupported security semantics

| Area | Status | Detail |
| --- | --- | --- |
| Transaction splitting | **NOT TESTED / excluded** | Two committed transactions each within budget are allowed; no task-wide bound exists. [ADR-005](decisions.md), [coverage note](phase0-security-coverage.md#transaction-splitting-conflict--route-a-selected-for-execution-original-checklist-unmet) |
| Task-wide / cross-transaction authority | **UNSUPPORTED** | Not implemented. No capability state spans transactions. |
| Retries across committed transactions | **NOT TESTED** | No retry or idempotency semantics exist; a retried committed transaction is a new transaction with fresh budget. |
| Capability-wide consumption | **UNSUPPORTED** | No signed, expiring or consumable capability model exists. [docs/roadmap.md](roadmap.md) |
| Arbitrary trigger graphs | **UNSUPPORTED** | Only the fixture's BEFORE UPDATE row triggers are installed; downstream, nested, recursive or user-added trigger effects are not accounted. |
| Arbitrary stored procedures / `SECURITY DEFINER` helpers | **NOT TESTED / UNSUPPORTED** | The fixture has no mutating routines and the writer has no `EXECUTE`; arbitrary definer writes are outside the guarantee. |
| FDWs, extensions, external side effects | **UNSUPPORTED** | No remote, filesystem, HTTP, sequence or extension effects are measured or reversed. [threat model](threat-model.md) |
| Arbitrary DDL | **UNSUPPORTED** | Protected writer lacks DDL; DDL effects are not accounted for trusted actors. |
| PostgreSQL superuser | **UNSUPPORTED** | A true superuser can bypass database-local enforcement. Superuser is outside the protected-writer model. |
| Arbitrary privilege topologies | **NOT TESTED** | Only the tested `commitcap_native_admin` / `commitcap_owner` / `commitcap_writer` separation is evidenced. |
| Other PostgreSQL versions | **NOT TESTED / UNSUPPORTED** | PostgreSQL provides no stable cross-major C ABI; every proposed version needs its own full proof. |
| INSERT / DELETE / COPY / TRUNCATE on protected tables | **BLOCKED (this fixture)** | The writer lacks these privileges (`42501`); no row accounting exists if they are granted. `INSERT ... ON CONFLICT` requires INSERT and is likewise blocked. |
| Partitions, inheritance, rules, FK cascades | **NOT TESTED** | No such fixture exists; accounting placement is unproven. |
| Other isolation levels, >2 sessions, deadlock workloads | **NOT TESTED** | Only two-session `READ COMMITTED` evidence exists. |
| Connection poolers, session reset, role switching | **NOT TESTED** | Same-backend reuse only; PgBouncer/session pooling behavior is unknown. |
| Two-phase commit with prepared transactions enabled | **NOT TESTED** | Fixture has `max_prepared_transactions = 0` and `PREPARE TRANSACTION` is blocked (`55000`). |
| Unprotected tables and all other relations | **UNSUPPORTED** | CommitCap governs only declared protected effects. An unprotected sibling mutation can share the same transaction (it is rolled back on tested denials, but it is not itself bounded). |

## C. Deployment

| Environment | Status | Evidence |
| --- | --- | --- |
| Local / self-managed research fixture (Docker Compose, PostgreSQL 16.4) | **TESTED** | [demo](../demo/phase0/README.md), [native suite](../experiments/native_tx_state/README.md), pinned image digest. |
| Amazon RDS for PostgreSQL 16, unchanged native mechanism | **BLOCKED by the documented service model** | RDS does not give customers filesystem/superuser access for an unlisted native module, and the experiment requires files under `$libdir` plus `shared_preload_libraries`. Documentary analysis only; no RDS instance was used. [managed feasibility](managed-postgres-feasibility.md) |
| CloudNativePG (operator-managed PostgreSQL on GKE) | **NOT TESTED (documented mechanism)** | A customer-built image could embed the module and set preload; no cluster, image or managed reproduction exists. Docs establish mechanisms, not CommitCap behavior. [managed feasibility](managed-postgres-feasibility.md#candidate-a-cloudnativepg-128-on-google-kubernetes-engine-gke-standard) |
| Crunchy Bridge provider-packaged module | **NOT TESTED / unverified** | Provider packaging is a research hypothesis, not a support path. [managed feasibility](managed-postgres-feasibility.md#candidate-b-crunchy-bridge-pg16-provider-packaging-request) |
| **Actual managed PostgreSQL deployment** | **NOT TESTED** | No authorized managed instance, credentials or cloud resources were used. Issue [#10](https://github.com/MrDarkRoot/CommitCap/issues/10) remains open. Do not claim RDS or managed support. |

## D. Performance

| Result | Status | Evidence |
| --- | --- | --- |
| Issue [#15](https://github.com/MrDarkRoot/CommitCap/issues/15) enforcement-overhead benchmark | **INCONCLUSIVE** | PR [#22](https://github.com/MrDarkRoot/CommitCap/pull/22) contains two complete, exit-0 measured runs on PostgreSQL 16.4. Both verified durable state and 30 denial trials per denied class, but the host showed severe unexplained baseline-rate shifts (for example, baseline 1-row TPS `1346 → 379 → 101` across rounds; 100-row paired TPS changes `−37%, +66%, −49%` in run 1 and `−7%, −26%, −41%` in run 2). No stable overhead estimate, no PASS threshold and no production claim is derived. Raw data: `benchmarks/phase0/evidence/REPORT.md`, `raw-run*.csv`, `summary-run*.json`, `metadata-run*.json` on the PR branch. |
| Denied-path client timings | **INCONCLUSIVE** | PR #22 also reports protected-only client-observed denial durations. They are not comparable to accepted baseline transactions and are not an overhead claim. |

Do not quote a single favorable number from these runs. A controlled-host
rerun is required before any overhead statement is published.

## E. Product and release status

Exactly one of these is currently true:

| Status | Current | Reason |
| --- | --- | --- |
| Research mechanism | **YES** | Native backend-local transaction-state experiment exists and passes its research suite. |
| Research Preview | **YES** | The transaction-local fixture is available for external testing and inspection. |
| Supported release | **NO** | No release, support envelope or published support interface exists. |
| Production-ready security control | **NO** | Do not deploy CommitCap as a security control; see [README](../README.md) and [SECURITY.md](../SECURITY.md). |

Open research gates, none of them passed and none claimed here: real managed
deployment ([#10](https://github.com/MrDarkRoot/CommitCap/issues/10)),
reproducible enforcement-overhead measurement
([#15](https://github.com/MrDarkRoot/CommitCap/issues/15)), real-workflow
validation ([#16](https://github.com/MrDarkRoot/CommitCap/issues/16)), and the
local prototype acceptance/deployment gate
([#19](https://github.com/MrDarkRoot/CommitCap/issues/19)).

## F. Trust model

| Assumption | Status | Evidence |
| --- | --- | --- |
| Protected writer is not a PostgreSQL superuser | **Required and TESTED for the fixture** | `commitcap_writer` has no elevated attributes; superuser is outside the model. [threat model](threat-model.md) |
| Writer cannot own, alter or drop protected enforcement objects | **TESTED for the fixture** | 27 bypass attempts denied `42501`, trigger/function ownership unchanged. [privilege boundary](../experiments/native_tx_state/README.md#privilege-boundary) |
| Writer cannot replace trusted enforcement functions | **TESTED for the fixture** | Function replacement/alter denied; no writer `EXECUTE`. |
| Protected tables, trigger functions and probes stay under trusted ownership | **TESTED for the fixture** | `commitcap_owner` (NOLOGIN) owns protected relations and functions; `commitcap_native_admin` is the trusted setup role. |
| No `SECURITY DEFINER` surface in the current native path | **TESTED for the fixture** | Enforcement and probe functions are `SECURITY INVOKER` C functions with no SQL bodies or `search_path` dependence; no `SECURITY DEFINER` function exists in the trusted schemas. If one is ever added, fixed `search_path`, ownership, `EXECUTE` and shadowing must be reviewed. |
| Trusted components | **Required** | PostgreSQL engine, trusted installer/admin role, and the policy issuer are trusted. [threat model](threat-model.md) |
| Writer cannot forge authoritative enforcement state | **TESTED for the fixture** | Accounting and denial evidence are backend-local C state; the writer has no writable policy or accounting relation. Issue #34's evidence is reporting-only. |

## G. Claim boundary

The correct claim is:

> **CommitCap governs supported transactional relational mutations and declared
> relational effects.**

Not:

> ~~CommitCap controls all PostgreSQL side effects.~~

Also preserve that distinction: a declared relational metric such as
`refunds.amount positive_delta = 100` means the measured PostgreSQL value moved
by 100 under documented semantics. It does not prove that money moved, a
ledger settled, a customer received funds, or any external workflow succeeded.
The policy issuer owns that mapping. [ADR-012](decisions.md),
[ADR-017](decisions.md), [spec.md](spec.md).

## Evidence index

- Current suite: [experiments/native_tx_state/run.sh](../experiments/native_tx_state/run.sh)
- Fixture and privilege model: [setup.sql](../experiments/native_tx_state/setup.sql)
- Native mechanism: [commitcap_native_tx_state.c](../experiments/native_tx_state/commitcap_native_tx_state.c)
- Research results and unknowns: [experiments/native_tx_state/README.md](../experiments/native_tx_state/README.md)
- Canonical tests: [docs/test-plan.md](test-plan.md)
- Historical evidence and boundaries: [docs/limitations.md](limitations.md)
- Coverage audit: [docs/phase0-security-coverage.md](phase0-security-coverage.md)
- Managed feasibility (documentary): [docs/managed-postgres-feasibility.md](managed-postgres-feasibility.md)
- One-command demo: [demo.sh](../demo.sh), [demo/phase0/README.md](../demo/phase0/README.md)
