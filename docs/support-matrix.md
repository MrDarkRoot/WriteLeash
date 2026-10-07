# WriteLeash PostgreSQL Research Mechanism — Support, Compatibility, Performance and Security Matrix

Last updated: 2026-09-30.

**HISTORICAL / SUPERSEDED — product identity.** This technical support matrix
covers the PostgreSQL research mechanism, not the implemented WooCommerce
bulk-pricing product. Current plugin capabilities and limitations are in the
[root README](../README.md) and
[plugin listing](../wordpress/writeleash/readme.txt). The advanced WordPress
Guard/Doctor/Redirection substrate is separate research under
`wordpress/writeleash/`.
The PostgreSQL Public Research Preview ([#31](https://github.com/MrDarkRoot/WriteLeash/issues/31))
is **not released**. This matrix summarizes behavior present in the reviewed
code and linked research evidence. The denial-evidence row requires the
implementation and tests from [#34's PR
#39](https://github.com/MrDarkRoot/WriteLeash/pull/39) in the base history; it
must not be published without them.
[README.md](../README.md) is the project overview, [docs/spec.md](spec.md)
defines intended semantics, [docs/test-plan.md](test-plan.md) defines canonical
test IDs, and [docs/limitations.md](limitations.md) preserves the historical
evidence. Nothing here is a production-ready security control.

## Status legend

| Status | Meaning |
| --- | --- |
| **TESTED** | Demonstrated by passing assertions in the pinned PostgreSQL 16.4 research fixture, within the stated topology. **Not** a release support claim. |
| **NOT TESTED** | No evidence exists. Do not infer behavior. |
| **UNSUPPORTED** | Outside the current guarantee. Deployment must not imply protection. |
| **BLOCKED** | Denied by privileges/configuration for the exact tested topology, or blocked by a documented provider model. |
| **INCONCLUSIVE** | Evidence exists but does not support a conclusion. |

The PostgreSQL/native code described below remains **research mechanism**, not
a released Research Preview or a supported release: see [Product and release
status](#e-product-and-release-status).

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
The PostgreSQL research first-run path is [`./writeleash demo`](../writeleash);
[`./demo.sh`](../demo.sh) and [demo/phase0/README.md](../demo/phase0/README.md)
remain the deeper historical research fixture.

| Area | Status | Exact tested behavior and evidence |
| --- | --- | --- |
| PostgreSQL version | **TESTED** | Only 16.4. No other major or minor. The suite and demo print `PostgreSQL: 16.4` and verify the pinned image. |
| Authority scope | **TESTED (transaction-local)** | Per top-level transaction only. Budgets reset at each new transaction. Splitting work across committed transactions is not prevented by this mechanism. |
| Generic V0 UPDATE row-budget candidate (#47) | **TESTED locally; merged research candidate** | A trusted owner attaches one unconditional `BEFORE UPDATE FOR EACH ROW` budget trigger to an ordinary nonpartitioned PG16.4 table; backend-local relation-OID state accounts two arbitrary relations independently. Strict decimal argument 0..2147483647; a duplicate trigger or malformed/unsupported shape fails on invocation. An UPDATE affecting zero rows cannot validate a misinstalled row trigger. Restricted writer lacks ownership/trigger and trusted-schema privileges. [research security suite](../experiments/native_tx_state/product_update_run.sh), [exact limits](../experiments/native_tx_state/README.md#v0-generic-update-row-budget-47). The isolated security suite alone is not a published support claim; the separate #48 first-run PostgreSQL research demo is below. |
| V0 protection generator and catalog preflight (#27) | **Local DX only; not an installer** | `./writeleash doctor` checks Bash, Docker, the `docker compose` interface, and daemon reachability. `protect-update` emits reviewable trusted-admin SQL and a `psql` preflight; it does not connect, apply SQL, or manage credentials. The catalog query checks the PostgreSQL 16.4 version, relation kind/inheritance/partitioning, exact extension trigger/function, no other direct user-defined trigger, trigger enabled state/timing/level/operation/WHEN/UPDATE OF shape, argument count and strict budget matching the reviewed plan, plus the supplied writer role's superuser/elevated attributes, ownership, `TRIGGER` and trusted-schema `CREATE` boundaries. It rejects effective `SET` or `ALTER SYSTEM` privileges on `session_replication_role` (which can skip origin triggers), and conservatively rejects **any other SET-able role**, including transitive SET ROLE chains; SET-able group-role topologies are unsupported, and this is not a general group-role privilege verifier. Indirect trigger/cascade graphs are not recursively validated. Only local PostgreSQL 16.4 is the expected fixture; managed PostgreSQL and other versions are not validated. This command does not make the candidate a released or supported protection claim. |
| Arbitrary-table V0 first-run demo (#48) | **TESTED locally; acceptance candidate** | `./writeleash demo` creates two runtime-named ordinary relations on the pinned PG16.4 Compose fixture. It applies only `protect-update`-generated trusted-admin SQL; each generated catalog preflight must PASS before restricted-writer transactions. One safe transaction consumes 5 + 3 independently and COMMITs; a sixth event on A and a fourth on B each deny COMMIT after attempted savepoint recovery. Fresh trusted-admin sessions compare exact row values after safe and denied transactions. Dedicated project cleanup and two sequential clean CI runs are asserted. [research demo](../demo/product_update_demo.sh), [CI](../.github/workflows/native-pg16-security.yml). Not a supported release, managed installation or broader SQL guarantee. |
| Research-fixture row-count authority | **TESTED (fixed research policies)** | Independently keyed `subscriptions.rows_updated` and `users.rows_updated` limits (fixture: 5). One broad `UPDATE`, statement decomposition, repeated updates of one row, zero-row and no-op updates, writable CTE, prepared `UPDATE`, `EXPLAIN (ANALYZE)` and tested `MERGE ... WHEN MATCHED THEN UPDATE` forms share one transaction budget and abort on excess. The `writeleash_native.test_budget` GUC is **not** the generic policy interface. [evidence](../experiments/native_tx_state/README.md#row-event-definition) |
| State-transition authority | **TESTED** | One fixed rule: any `UPDATE` of the text `users.role` to `admin` is denied (`* -> admin`); `member -> moderator` is allowed. Bulk and mixed transitions, savepoint recovery and caught exceptions remain sticky. Other columns/roles/rules are not tested. [evidence](../experiments/native_tx_state/README.md#canonical-state-transition-tests-cc-020cc-022) |
| Numeric positive-delta authority | **TESTED** | `refunds.amount` exact-decimal positive delta with fixture budget `100.00`; values below/at/above budget, decomposed statements, gross-vs-net oscillation, CTE/prepared/`EXPLAIN` forms, and two-session row-lock contention scenarios A/B/C. `numeric` without typmod; server-validated finite, nonnegative, 16 integer / 2 fractional digits. [decision semantics](numeric-delta-decision-proposal.md) |
| Independent per-policy accounting | **TESTED** | Both tested execution orders reached the same independently accounted state: `5\|1\|20.00`. A single-policy violation leaves the other policies' consumption independently accounted. [coverage](phase0-security-coverage.md) |
| Sticky denial / recovery | **TESTED** | A denied event sets backend-local denial that `ROLLBACK TO SAVEPOINT` and PL/pgSQL exception handling cannot clear; the top-level `COMMIT` is rejected at `XACT_EVENT_PRE_COMMIT` with SQLSTATE `54000`. [lifecycle](../experiments/native_tx_state/README.md#observed-lifecycle) |
| Denial evidence (issue #34) | **TESTED** | Denials carry policy-scoped `DETAIL` evidence (policy/metric and, only where exactly known, granted budget, consumed-before-attempt and attempted effect) plus `result: ABORTED` at commit rejection. Reporting only; decisions and SQLSTATEs unchanged. [behavior](../experiments/native_tx_state/README.md#denial-evidence), [limitations](limitations.md#native-transaction-state-feasibility-evidence) |
| Restricted-writer boundary | **TESTED (this topology)** | `writeleash_writer` is a non-superuser, non-owner, membership-free login with column grants only. It cannot disable/drop triggers, replace functions, alter/own protected objects, change replication settings, `SET ROLE`, or change the test GUCs (SQLSTATE `42501`). [privilege boundary](../experiments/native_tx_state/README.md#privilege-boundary) |
| Concurrency | **TESTED (2 sessions, READ COMMITTED)** | Two writer backends keep independent accounting; verified lock waits; no accounting transfer between sessions; no deadlock count change. Numeric contention A/B/C verified post-lock delta accounting. Other isolation levels, more sessions and deadlock-producing workloads are not tested. [contention](../experiments/native_tx_state/README.md#concurrent-sessions) |
| Fresh trusted durable-state verification | **TESTED** | Durable-state assertions open new trusted-admin connections; the demo compares every protected row and the sibling audit count, and the suite checks case-specific durable oracles. Writer probes and logs are never the durability oracle. [suite](../experiments/native_tx_state/run.sh), [demo](../demo/phase0/run.sh) |
| Backend reuse | **TESTED (same backend, no disconnect)** | Next top-level transaction after commit, rollback, denial abort, recovered denial and caught denial starts fresh. Real poolers and role switching are not tested. [backend reuse](../experiments/native_tx_state/README.md#backend-reuse) |

**Not covered by the table above:** anything outside the precisely tested
fixture, version, role topology and statement shapes. In particular, a green
suite run is not a claim that arbitrary SQL is protected.

## B. Unproven / unsupported security semantics

| Area | Status | Detail |
| --- | --- | --- |
| Transaction splitting | **UNSUPPORTED (not protected)** | Two committed transactions each within budget are allowed; no task-wide bound exists. [ADR-005](decisions.md), [coverage note](phase0-security-coverage.md#transaction-splitting-conflict--route-a-selected-for-execution-original-checklist-unmet) |
| Task-wide / cross-transaction authority | **UNSUPPORTED** | Not implemented. No capability state spans transactions. |
| Retries across committed transactions | **NOT TESTED** | No retry or idempotency semantics exist; a retried committed transaction is a new transaction with fresh budget. |
| Capability-wide consumption | **UNSUPPORTED** | No signed, expiring or consumable capability model exists. [docs/roadmap.md](roadmap.md) |
| Arbitrary trigger graphs | **NOT TESTED / UNSUPPORTED** | Only the fixture and #47's explicit product-test BEFORE UPDATE row triggers are tested; no accounting guarantee extends to arbitrary downstream, nested, recursive or user-added graphs. |
| Generic policy installation | **Restricted V0 candidate** | Exactly one enabled unconditional product trigger per ordinary nonpartitioned relation, created by the trusted owner. Conditional, UPDATE OF, duplicate, disabled, legacy/product mixed or misconfigured triggers are not supported. Runtime detects invalid shapes when a row fires; an installation with no firing rows must be audited by the trusted installer. No self-service policy install, managed-platform or arbitrary schema/privilege guarantee. |
| Arbitrary stored procedures / `SECURITY DEFINER` helpers | **NOT TESTED / UNSUPPORTED** | The fixture has no mutating routines; writer `EXECUTE` is limited to read-only probes, not mutating helpers. Arbitrary definer writes are outside the guarantee. |
| FDWs, extensions, external side effects | **UNSUPPORTED** | No remote, filesystem, HTTP, sequence or extension effects are measured or reversed. [threat model](threat-model.md) |
| Arbitrary DDL | **UNSUPPORTED** | Protected writer lacks DDL; DDL effects are not accounted for trusted actors. |
| PostgreSQL superuser | **UNSUPPORTED** | A true superuser can bypass database-local enforcement. Superuser is outside the protected-writer model. |
| Arbitrary privilege topologies | **NOT TESTED** | Only the tested `writeleash_native_admin` / `writeleash_owner` / `writeleash_writer` separation is evidenced. |
| Other PostgreSQL versions | **NOT TESTED** | PostgreSQL provides no stable cross-major C ABI; every proposed version needs its own full proof. |
| INSERT / DELETE / COPY / TRUNCATE on protected tables | **BLOCKED (this fixture)** | The writer lacks these privileges (`42501`); no row accounting exists if they are granted. `INSERT ... ON CONFLICT` requires INSERT and is likewise blocked. |
| Partitions, inheritance, rules, FK cascades | **NOT TESTED** | No such fixture exists; accounting placement is unproven. |
| Other isolation levels, >2 sessions, deadlock workloads | **NOT TESTED** | Only two-session `READ COMMITTED` evidence exists. |
| Connection poolers, session reset, role switching | **NOT TESTED** | Same-backend reuse only; PgBouncer/session pooling behavior is unknown. |
| Two-phase commit with prepared transactions enabled | **NOT TESTED** | Fixture has `max_prepared_transactions = 0` and `PREPARE TRANSACTION` is blocked (`55000`). |
| Unprotected tables and all other relations | **UNSUPPORTED** | The WriteLeash PostgreSQL research mechanism governs only declared protected effects. An unprotected sibling mutation can share the same transaction (it is rolled back on tested denials, but it is not itself bounded). |

## C. Deployment

| Environment | Status | Evidence |
| --- | --- | --- |
| Local / self-managed research fixture (Docker Compose, PostgreSQL 16.4) | **TESTED** | [demo](../demo/phase0/README.md), [native suite](../experiments/native_tx_state/README.md), pinned image digest. |
| Amazon RDS for PostgreSQL 16, unchanged native mechanism | **BLOCKED via standard customer interfaces (documentary)** | The current native module requires server-installed files under `$libdir` and preload; current RDS customer interfaces do not offer installation of this unlisted module. This does not rule out a different future architecture or provider packaging. No RDS instance was used. [managed feasibility](managed-postgres-feasibility.md) |
| CloudNativePG (operator-managed PostgreSQL on GKE, not fully managed DBaaS) | **NOT TESTED (documented mechanism)** | A customer-built image could embed the module and set preload; no cluster, image or managed reproduction exists. Docs establish mechanisms, not WriteLeash behavior. [managed feasibility](managed-postgres-feasibility.md#candidate-a-cloudnativepg-128-on-google-kubernetes-engine-gke-standard) |
| Crunchy Bridge provider-packaged module | **NOT TESTED / unverified** | Provider packaging is a research hypothesis, not a support path. [managed feasibility](managed-postgres-feasibility.md#candidate-b-crunchy-bridge-pg16-provider-packaging-request) |
| **Actual managed PostgreSQL deployment** | **NOT TESTED** | No authorized managed instance, credentials or cloud resources were used. Issue [#10](https://github.com/MrDarkRoot/WriteLeash/issues/10) remains open. Do not claim RDS or managed support. |

## D. Performance

| Result | Status | Evidence |
| --- | --- | --- |
| Issue [#15](https://github.com/MrDarkRoot/WriteLeash/issues/15) enforcement-overhead benchmark | **INCONCLUSIVE** | PR [#22](https://github.com/MrDarkRoot/WriteLeash/pull/22) contains two complete, exit-0 measured runs on PostgreSQL 16.4. Both verified durable state and 30 denial trials per denied class, but baseline throughput shifted sharply and paired changes reversed direction across rounds. No stable overhead estimate, no PASS threshold and no production claim is derived. Raw data: `benchmarks/phase0/evidence/REPORT.md`, `raw-run*.csv`, `summary-run*.json`, `metadata-run*.json` on the PR branch. |
| Denied-path client timings | **INCONCLUSIVE** | PR #22 also reports protected-only client-observed denial durations. They are not comparable to accepted baseline transactions and are not an overhead claim. |

Do not quote a single favorable number from these runs. A controlled-host
rerun is required before any overhead statement is published.

## E. Product and release status

For the PostgreSQL/native substrate, the local research mechanism is current
technical work, not a released Public Research Preview. The separate WooCommerce
product is implemented; this table does not establish its release authorization:

| Status | Current | Reason |
| --- | --- | --- |
| Research mechanism | **CURRENT** | Native backend-local transaction-state experiment exists and passes its research suite. |
| PostgreSQL Public Research Preview ([#31](https://github.com/MrDarkRoot/WriteLeash/issues/31)) | **NOT RELEASED** | The historical research-preview issue remains open; this local evidence does not authorize publication. |
| WriteLeash WooCommerce product | **IMPLEMENTED** | Preview, Apply, newer-edit protection, Resume, History and eligible Undo are documented in the [plugin listing](../wordpress/writeleash/readme.txt). Earlier build-gate instructions are historical. |
| WooCommerce publication | **PREPARING PUBLIC SUBMISSION** | See the [root README](../README.md) for current product identity. This research matrix does not announce publication or authorize release. |
| Production-ready security control | **NO** | Do not deploy this PostgreSQL research mechanism as a security control; see [README](../README.md) and [SECURITY.md](../SECURITY.md). |

Open research gates, none of them passed and none claimed here: real managed
deployment ([#10](https://github.com/MrDarkRoot/WriteLeash/issues/10)),
reproducible enforcement-overhead measurement
([#15](https://github.com/MrDarkRoot/WriteLeash/issues/15)), real-workflow
validation ([#16](https://github.com/MrDarkRoot/WriteLeash/issues/16)), and the
local prototype acceptance/deployment gate
([#19](https://github.com/MrDarkRoot/WriteLeash/issues/19)).

## F. Trust model

| Assumption | Status | Evidence |
| --- | --- | --- |
| Protected writer is not a PostgreSQL superuser | **Required and TESTED for the fixture** | `writeleash_writer` has no elevated attributes; superuser is outside the model. [threat model](threat-model.md) |
| Writer cannot own, alter or drop protected enforcement objects | **TESTED for the fixture** | 27 bypass attempts denied `42501`, trigger/function ownership unchanged. [privilege boundary](../experiments/native_tx_state/README.md#privilege-boundary) |
| Writer cannot replace trusted enforcement functions | **TESTED for the fixture** | Function replacement/alter denied; no direct writer `EXECUTE` on the enforcement functions (only read-only probes are callable). |
| Protected tables, trigger functions and probes stay under trusted ownership | **TESTED for the fixture** | `writeleash_owner` (NOLOGIN) owns protected relations and functions; `writeleash_native_admin` is the trusted setup role. |
| No `SECURITY DEFINER` surface in the current native path | **TESTED for the fixture** | Enforcement and probe functions are `SECURITY INVOKER` C functions with no SQL bodies or `search_path` dependence; no `SECURITY DEFINER` function exists in the trusted schemas. If one is ever added, fixed `search_path`, ownership, `EXECUTE` and shadowing must be reviewed. |
| Trusted components | **Required** | PostgreSQL engine, trusted installer/admin role, and the policy issuer are trusted. [threat model](threat-model.md) |
| Writer cannot forge authoritative enforcement state | **TESTED for the fixture** | Accounting and denial evidence are backend-local C state; the writer has no writable policy or accounting relation. Issue #34's evidence is reporting-only. |

## G. Claim boundary

The correct claim is:

> **WriteLeash's PostgreSQL research mechanism governs supported transactional relational mutations and declared
> relational effects.**

Not:

> ~~WriteLeash controls all PostgreSQL side effects.~~

Also preserve that distinction: a declared relational metric such as
`refunds.amount positive_delta = 100` means the measured PostgreSQL value moved
by 100 under documented semantics. It does not prove that money moved, a
ledger settled, a customer received funds, or any external workflow succeeded.
The policy issuer owns that mapping. [ADR-012](decisions.md),
[ADR-017](decisions.md), [spec.md](spec.md).

## Evidence index

- Current suite: [experiments/native_tx_state/run.sh](../experiments/native_tx_state/run.sh)
- Fixture and privilege model: [setup.sql](../experiments/native_tx_state/setup.sql)
- Native mechanism: [writeleash_native_tx_state.c](../experiments/native_tx_state/writeleash_native_tx_state.c)
- Research results and unknowns: [experiments/native_tx_state/README.md](../experiments/native_tx_state/README.md)
- Canonical tests: [docs/test-plan.md](test-plan.md)
- Historical evidence and boundaries: [docs/limitations.md](limitations.md)
- Coverage audit: [docs/phase0-security-coverage.md](phase0-security-coverage.md)
- Managed feasibility (documentary): [docs/managed-postgres-feasibility.md](managed-postgres-feasibility.md)
- Product-facing first-run demo: [writeleash demo](../writeleash), [demo/product_update_demo.sh](../demo/product_update_demo.sh)
- Historical research/security demo: [demo.sh](../demo.sh), [demo/phase0/README.md](../demo/phase0/README.md)
