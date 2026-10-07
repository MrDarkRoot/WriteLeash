# CommitCap Legacy Phase 0 Specification

> **Historical technical specification:** `CommitCap` references and V0
> semantics below are preserved from the earlier PostgreSQL research phase.
> They are not current WriteLeash research semantics or the WooCommerce product
> definition. The current repository is WriteLeash; see the [root
> README](README.md) for the research program and its first commercial product,
> WriteLeash for WooCommerce.

> **Historical document:** This file preserves the original row-budget Phase 0
> specification and its experiment plan. It is not the current source of truth.
> Use [docs/spec.md](docs/spec.md) for intended semantics,
> [docs/test-plan.md](docs/test-plan.md) for canonical current test IDs, and
> [docs/decisions.md](docs/decisions.md) for accepted decisions. Every `CC-*`
> identifier in this file is a **legacy experiment ID** and must not be mapped
> to a same-number current test.

**Status:** Historical Phase 0 draft\
**Implementation status at creation:** Not implemented; research experiments
were added later\
**Historical scope:** The original V0 PostgreSQL row-budget proof

This document was the canonical specification for the original experiment. It
is retained as historical decision context and does not define current product
or test-plan semantics.

The key words **MUST**, **MUST NOT**, **REQUIRED**, **SHOULD**, **SHOULD NOT**,
and **MAY** are to be interpreted as normative requirements.

## 1. Purpose

CommitCap turns database write permission into consumable mutation authority.
Its Phase 0 objective is to prove or disprove that a narrow form of mutation
authority can be enforced cleanly inside PostgreSQL.

Phase 0 is not a product-growth phase. It does not include a dashboard, SaaS
control plane, billing, AI integration, approval system, database proxy,
multi-database abstraction, or enterprise platform.

The order of priorities is:

```text
security invariant
> correctness
> bypass resistance
> transaction semantics
> performance
> developer experience
> features
> marketing polish
```

## 2. Security Invariant

The repository's governing invariant is:

> No supported durable relational mutation may exceed the mutation authority
> granted to the actor or capability that caused it.

An implementation difficulty does not permit weakening this invariant. If a
PostgreSQL behavior cannot satisfy it, the implementation MUST do one of the
following:

1. Correct the architecture.
2. Mark the behavior unsupported and fail closed where practical.
3. Narrow the supported product scope.

The primary adversarial question is:

> Can I make a supported mutation exceed authority and still become durable?

If the answer is yes, the supported security claim has failed.

## 3. Terminology

### 3.1 Actor

The human, service, job, agent, or other principal that causes a database
operation. An actor may be represented by a PostgreSQL role, an application
identity behind a shared role, or a future capability. V0 does not yet solve
application-level actor attribution behind shared database credentials.

### 3.2 Protected writer

A PostgreSQL role or session principal whose permitted mutations are subject
to CommitCap enforcement. A protected writer is untrusted or semi-trusted and
MUST NOT hold privileges that can disable or alter enforcement.

### 3.3 Protected relation

A PostgreSQL table or partitioned-table hierarchy for which a specific
CommitCap policy is declared. Protection is operation-specific. An `UPDATE`
budget does not imply protection from `INSERT`, `DELETE`, `TRUNCATE`, DDL, or
side effects on another relation.

### 3.4 Mutation

A relational state change attempted through PostgreSQL. V0 concerns row-update
events produced by `UPDATE`. Future specifications may define insert, delete,
transition, and quantitative-effect mutations.

### 3.5 Row-update event

One execution of an update against one protected row that would create a new
PostgreSQL row version but for CommitCap denial. It counts even when the
assigned values equal their existing values. Updating the same row more than
once produces more than one row-update event. V0 counts events, not distinct
row identities.

An update that qualifies zero rows produces zero row-update events.

This definition applies first to the constrained schema in Section 5, which
excludes user-defined triggers, rewrite rules, table inheritance, and
partitions. Trigger suppression, replacement rows, and indirect updates remain
outside the support envelope until their event semantics are specified and
tested. The implementation hook and trigger timing MUST preserve this semantic
definition; they do not define it.

### 3.6 Durable mutation

A mutation whose enclosing top-level PostgreSQL transaction commits
successfully and whose relational effect therefore survives that transaction.
Rolled-back and aborted mutations are not durable.

Effects outside PostgreSQL's transactional durability boundary are outside
V0. An implementation MUST NOT imply that it can roll back non-transactional
external side effects.

### 3.7 Mutation authority

The maximum permitted mutation effect granted within a defined scope. In V0,
authority is an integer number of row-update events for one protected relation
and one top-level transaction.

### 3.8 Mutation budget

The machine-enforced representation of mutation authority. A V0 update budget
is a non-negative integer. Consuming the budget permits a row-update event; it
does not grant additional SQL privileges.

### 3.9 Transaction

A top-level PostgreSQL transaction. An explicit `BEGIN`/`COMMIT` block is one
transaction. In autocommit mode, each statement is normally its own top-level
transaction. Savepoints and PL/pgSQL exception blocks create subtransaction
behavior but MUST NOT create a new top-level V0 budget.

### 3.10 Enforcement boundary

The PostgreSQL database boundary at which CommitCap observes a mutation,
accounts for authority, and prevents a denied top-level transaction from
committing. The concrete mechanism is unresolved. Generated triggers and
SQL/PL/pgSQL are candidates; a native extension is not justified unless a
falsification experiment shows it is necessary.

### 3.11 Supported operation

An operation and execution context explicitly listed as supported by a
released version and covered by passing positive, negative, atomicity, and
bypass regression tests. Similar syntax or undocumented execution paths are
not implicitly supported.

At the time of this specification, there is no implementation and therefore no
supported operation.

### 3.12 Denied transaction

A top-level transaction for which CommitCap has detected an authority
violation. A denied transaction MUST be unable to commit. All of its
transactional effects, on protected and unprotected relations alike, MUST roll
back. Recovering from an error with a savepoint or exception handler MUST NOT
convert a denied top-level transaction into a successful one.

### 3.13 Capability

A future grant of mutation authority with its own identity, scope, lifetime,
and consumable balance, potentially spanning transactions. Capabilities are
not part of V0.

## 4. V0 Policy

The first proof target is equivalent to:

```yaml
subscriptions:
  max_rows_updated_per_transaction: 5
```

This YAML is illustrative. V0 configuration syntax and storage are unresolved
and MUST NOT be inferred from the example.

For each protected relation `R`, top-level transaction `T`, and configured
budget `N`, the implementation conceptually maintains a count `C(R, T)`.

1. `N` MUST be a non-negative integer no greater than an
   implementation-defined, documented maximum.
2. `C(R, T)` starts at zero for a new top-level transaction.
3. Each live row-update event on `R` proposes `C(R, T) + 1`.
4. If the proposed count is at most `N`, the event MAY proceed and the count is
   consumed.
5. If the proposed count is greater than `N`, `T` MUST become a denied
   transaction.
6. Counters MUST NOT be shared accidentally between independent transactions.
7. Splitting an update across statements, prepared statements, SQL functions,
   or equivalent syntax MUST NOT create fresh transaction authority when that
   execution path is declared supported.

Invalid, negative, out-of-range, and unrepresentable budgets MUST be rejected
before policy activation. Counter checks MUST be implemented so that integer
overflow cannot turn exhaustion into additional authority. The concrete
maximum and counter representation remain unresolved until implementation.

Budgets are per protected relation in V0. A policy on one relation does not
create a global budget or protect another relation.

### 4.1 Counting Rules

For an `UPDATE` policy with budget five:

- One statement updating five protected rows consumes five units.
- Five statements updating one protected row each consume five units.
- Updating the same protected row five times consumes five units.
- An update matching no rows consumes zero units.
- An update assigning a column its existing value still consumes one unit for
  each qualifying protected row.
- A sixth row-update event in the same top-level transaction MUST deny the
  transaction.

The SQL command tag, client-reported row count, and query text are not the
security boundary. Accounting MUST follow actual protected row-update events
within the declared support envelope.

### 4.2 Transaction And Rollback Semantics

V0 authority is transaction-scoped:

- A successful `COMMIT` ends the budget scope.
- A top-level `ROLLBACK` discards relational changes and V0 accounting state.
- A new top-level transaction receives a new V0 budget.
- Rolling back a successful subtransaction before any CommitCap denial MUST
  restore its relational changes and corresponding count. This behavior is a
  V0 requirement, but its PostgreSQL mechanism must be proven by `CC-009`.
- Crossing the budget is different from voluntarily rolling back allowed work.
  Once CommitCap detects `N + 1`, the top-level transaction MUST remain denied.
  `ROLLBACK TO SAVEPOINT`, a PL/pgSQL exception handler, or client retry inside
  the same top-level transaction MUST NOT restore commit authority.

The last requirement may be difficult with ordinary transactional trigger
state. It is a falsification target, not permission to degrade to
statement-only enforcement.

One row updated in each of six separate transactions can commit under V0 when
each transaction remains within budget. That is an explicit limit of
transaction-scoped authority, not protection against cumulative mutation over
time. Cross-transaction consumption belongs to future capabilities.

### 4.3 Failure Semantics

On the first row-update event that would exceed the budget:

1. CommitCap MUST raise a PostgreSQL error before the event can become durable.
2. The enclosing top-level transaction MUST be denied, not only the current
   statement or subtransaction.
3. A later `COMMIT` MUST NOT succeed or make any transactional effect from the
   denied transaction durable.
4. Previously executed protected and unprotected mutations in the same
   transaction MUST roll back.
5. The client MUST receive an error that is distinguishable as a CommitCap
   budget denial.

The exact SQLSTATE, message format, and diagnostic fields are unresolved. They
MUST be specified and tested before an implementation is described as V0.

CommitCap does not promise partial success. V0 denial is transaction-atomic.

## 5. V0 Support Envelope

The smallest intended proof environment is:

- PostgreSQL, with supported server versions still to be selected;
- one ordinary, non-partitioned protected table;
- one non-owner protected-writer role;
- a direct `UPDATE` row-event budget;
- only the minimum `UPDATE` privilege on the protected table, with no
  `INSERT`, `DELETE`, `TRUNCATE`, DDL, ownership, or privileged role membership;
- no user-defined triggers, rewrite rules, table inheritance, or foreign-key
  action capable of mutating the protected table;
- no `EXECUTE` access to a user-defined stored procedure or function that can
  mutate the protected table, whether it uses invoker or definer rights;
- two-phase commit disabled for the proof environment;
- no writer privilege to alter the table, policy, accounting state, or
  enforcement objects;
- no claim for other mutation operations until their tests pass.

Prepared statements and data-modifying CTEs are SQL forms available to a role
that has `UPDATE`; they cannot be excluded merely by documentation. They MUST
obey the same V0 budget. On any supported PostgreSQL version where the writer
can execute a `MERGE` update action, that action MUST also obey the budget.
Otherwise that PostgreSQL version cannot enter the support matrix.

This is a target envelope, not a current claim. The first implementation MUST
start here and expand only by explicit specification changes and adversarial
tests.

An operation outside the envelope MUST be documented as unsupported. Where an
unsupported operation could bypass the intended protection, deployment
instructions MUST remove the protected writer's privilege to invoke it or the
implementation MUST fail closed.

## 6. Legacy Experiment Tests

These IDs were the stable references for the original specification and
experiments. They are now legacy experiment IDs. Existing scripts and recorded
results preserve them as historical evidence; current work must use the
canonical IDs in `docs/test-plan.md`.

Gate 1 has two kinds of release-blocking tests:

- **Behavior tests** prove allowed, denied, rollback, concurrency, session
  reuse, alternate SQL form, and privilege behavior. These are `CC-001` through
  `CC-009`, `CC-011`, `CC-019` through `CC-021`, `CC-024` through `CC-033`.
- **Boundary tests** prove known V0 limits or fail-closed exclusions. These are
  `CC-010`, `CC-012` through `CC-018`, `CC-022`, `CC-034`, and `CC-035`.

`CC-023` is a future capability test and does not block V0. Every other listed
test MUST pass with its specified result before Gate 1 passes. A boundary test
passes by demonstrating the documented exclusion, not by adding support.

| ID | Scenario | Required result or disposition |
| --- | --- | --- |
| `CC-001` | Direct `UPDATE` produces at most `N` row-update events | `COMMIT`; all allowed changes durable |
| `CC-002` | One direct `UPDATE` produces more than `N` row-update events | Entire top-level transaction `ABORT`; no protected change durable |
| `CC-003` | Multiple statements cumulatively produce more than `N` row-update events | Entire top-level transaction `ABORT` |
| `CC-004` | Inspect protected state after any denied transaction | `PASS`: no protected mutation from that transaction is durable |
| `CC-005` | `UPDATE` qualifies zero rows | `COMMIT`; zero authority consumed |
| `CC-006` | The same row is updated `N + 1` times in one transaction | Entire top-level transaction `ABORT` |
| `CC-007` | A no-op assignment qualifies `N + 1` rows | Entire top-level transaction `ABORT` |
| `CC-008` | Budget is exceeded inside a savepoint, then client attempts `ROLLBACK TO` and `COMMIT` | Top-level transaction remains denied; no protected mutation durable |
| `CC-009` | Allowed updates occur after a savepoint, are rolled back without a denial, then replacement updates remain within `N` | `COMMIT`; rolled-back events do not create stale over-counting |
| `CC-010` | One allowed row-update occurs in each of `N + 1` separate transactions | Each transaction may `COMMIT`; documents the V0 boundary |
| `CC-011` | Data-modifying CTE performs `N + 1` protected updates | Entire top-level transaction `ABORT` |
| `CC-012` | `INSERT ... ON CONFLICT DO UPDATE` targets the protected table | Permission denied under the V0 role; no mutation durable |
| `CC-013` | `COPY FROM` targets the protected relation | Permission denied under the V0 role; no mutation durable |
| `CC-014` | `TRUNCATE` targets a protected relation | Protected writer MUST be unable to invoke it in V0 |
| `CC-015` | Candidate schema has a foreign-key action capable of mutating the protected relation | Deployment is rejected or explicitly outside support; no cascade protection claim |
| `CC-016` | Candidate schema has a user trigger or rewrite rule affecting protected updates | Deployment is rejected or explicitly outside support; no nested-trigger or rule claim |
| `CC-017` | Protected writer invokes a stored procedure or function that could mutate the protected relation | `EXECUTE` or underlying mutation is denied under the V0 role; no mutation durable |
| `CC-018` | Candidate relation uses inheritance or partition routing | Deployment is rejected or explicitly outside support; no partition protection claim |
| `CC-019` | Two sessions independently approach their per-transaction budgets | Each transaction MUST be isolated; neither may consume or reset the other's count |
| `CC-020` | Concurrent sessions contend on protected rows | No committed transaction may exceed its own budget; characterize deadlocks and isolation levels |
| `CC-021` | A pooled backend is reused for a new top-level transaction or writer | No stale count or authority may cross the transaction boundary |
| `CC-022` | Application retries a failed transaction | Each new V0 transaction has a fresh budget; no cross-retry aggregate protection is claimed |
| `CC-023` | Multiple sessions present one future shared capability | Future work; no V0 claim |
| `CC-024` | An anonymous PL/pgSQL `DO` block under the protected-writer role catches the budget error | Top-level transaction remains denied; no transactional effect is durable |
| `CC-025` | Prepared `UPDATE` statements cumulatively produce `N + 1` row-update events | Entire top-level transaction `ABORT` |
| `CC-026` | `MERGE` executes an update action on a PostgreSQL version proposed for support | The update action obeys the same budget, or that server version is not supported |
| `CC-027` | Protected writer attempts ownership, DDL, trigger disabling, policy writes, or trusted-function replacement | Every attempt is denied under the documented role grants |
| `CC-028` | Protected writer attempts `SET ROLE` or membership-based escalation | No reachable role can bypass enforcement or alter trusted state |
| `CC-029` | Protected writer attempts `PREPARE TRANSACTION` | Operation is unavailable in the V0 environment; no transaction is left prepared |
| `CC-030` | Budget is zero and one protected row-update event is attempted | Entire top-level transaction `ABORT`; no protected change durable |
| `CC-031` | Budget input is negative, out of range, or near the counter maximum | Invalid policies are rejected and no counter overflow can grant authority |
| `CC-032` | A transaction mutates an unprotected relation, then exceeds the protected budget | Entire top-level transaction `ABORT`; neither protected nor unprotected mutation is durable |
| `CC-033` | `EXPLAIN (ANALYZE)` executes an update producing `N + 1` protected row-update events | Entire top-level transaction `ABORT` |
| `CC-034` | Protected writer attempts direct `DELETE` on the protected relation | Permission denied under the V0 role; no mutation durable |
| `CC-035` | Protected writer attempts direct `INSERT` on the protected relation | Permission denied under the V0 role; no mutation durable |

Tests for unsupported operations are still valuable. They validate that the
documented privilege boundary fails closed rather than merely omitting a
feature.

## 7. PostgreSQL Trust And Privilege Requirements

The protected writer MUST NOT have any authority that trivially bypasses
enforcement. In a conforming deployment, it MUST NOT be:

- a PostgreSQL superuser;
- the owner of a protected relation;
- the owner of CommitCap policy, accounting, trigger, or function objects;
- able to `ALTER` or drop protected relations or enforcement objects;
- able to disable enforcement triggers;
- able to change replication or session settings that suppress relevant
  triggers;
- able to write policy or accounting state directly;
- able to replace or shadow trusted functions or referenced objects;
- able to `SET ROLE` to any role with such authority;
- able to grant itself any of these privileges.

The implementation SHOULD use a dedicated, non-login owner role for trusted
objects, subject to a concrete privilege design and tests. Exact role topology
remains an implementation design decision.

If `SECURITY DEFINER` functions are introduced, every such function MUST be
reviewed for:

- a fixed, safe `search_path`;
- trusted ownership;
- minimum `EXECUTE` grants;
- dynamic SQL and SQL injection;
- object shadowing;
- caller-controlled identifiers;
- privilege escalation;
- row-security and role-context behavior where applicable.

The trusted administrator can bypass CommitCap and is outside the protected
writer threat model. Documentation MUST state this clearly; it MUST NOT market
CommitCap as protection from PostgreSQL superusers, the server operator, or a
compromised database host.

## 8. Unsupported And Unresolved Behavior

The historical status matrix is [docs/limitations.md](docs/limitations.md).
In summary:

- Only transaction-wide `UPDATE` row-event accounting is a V0 proof target.
- `INSERT`, `DELETE`, `UPSERT`, `COPY`, `TRUNCATE`, partition routing, cascades,
  nested triggers, and stored procedures are not currently supported.
- Savepoint denial semantics, concurrency, pooling cleanup, and indirect update
  paths are required adversarial investigations.
- Cross-transaction and shared authority require future capabilities.

Unknown behavior MUST be labeled `UNKNOWN`. Unsupported behavior MUST NOT be
presented as protected merely because a likely PostgreSQL mechanism appears to
cover it.

## 9. Open Design Decisions

The following decisions are intentionally unresolved:

1. Whether SQL/PL/pgSQL and generated triggers can make a denial irreversible
   across savepoint rollback and caught exceptions without a native extension.
2. Where transaction-local accounting lives and how subtransaction rollback is
   observed correctly.
3. Which PostgreSQL major versions define the first support matrix.
4. The policy declaration, validation, and installation interface.
5. The stable SQLSTATE and error diagnostics for a denial.
6. The exact trigger timing and handling of interactions with user triggers.
7. Whether cascades, procedures, partition routing, and `MERGE` on candidate
   PostgreSQL versions can enter the supported envelope.
8. How connection poolers and role changes affect identity and cleanup.
9. The performance ceiling and measurement method. No benchmark exists yet.
10. The finite maximum budget and overflow-safe counter representation.

An implementation proposal MUST resolve only the decisions needed for the
requested proof. It MUST NOT pre-build hypothetical enterprise architecture.

## 10. Future Product Model

The conceptual sequence is:

```text
transaction mutation budgets
-> semantic transition budgets
-> quantitative effect budgets
-> task-scoped mutation capabilities
-> cross-transaction consumable authority
```

Level 1 limits mutation volume. Level 2 would deny forbidden state
transitions. Level 3 would sum quantitative effects such as refund increases.
Level 4 would make authority task-scoped, expiring, and consumable across
transactions.

Levels 2 through 4 are not V0 implementation scope. Potential later commercial
surfaces include signed or expiring capabilities, concurrency-safe authority
consumption, centralized policy distribution, advanced audit receipts,
Vault/KMS integration, embedded licensing, and enterprise support.

> The OSS primitive must be technically credible before the commercial product
> is built.

Today's architecture MUST NOT be distorted for those hypothetical surfaces.

## 11. Project Decision Gates

### Gate 0: Thesis

Pass only when:

- the invariant is precise;
- scope is explicit;
- the threat model exists.

### Gate 1: Technical Proof

Pass only when:

- core transaction-wide accounting works;
- obvious bypass classes are tested;
- performance is measured;
- claims match demonstrated behavior.

### Gate 2: OSS V0

Pass only when a developer can run approximately:

```bash
docker compose up
```

and within roughly five minutes observe:

```text
safe mutation -> COMMIT
unsafe mutation -> ABORT
```

### Gate 3: Real Users

Pass when multiple relevant users attempt real integrations and ask
implementation-level questions.

### Gate 4: Business

Pass when production users demonstrate willingness to pay for operational
capabilities.

Gates MUST NOT be skipped or declared passed without evidence.

## 12. Historical Implementation Agent Contract

This was the implementation contract for the original row-budget proof. Current
contributors must instead begin with `docs/spec.md`, `docs/test-plan.md`, and
`docs/decisions.md`.

1. This legacy `SPEC.md` was normative for the original experiment.
2. The security invariant takes precedence over convenience.
3. Do not silently change semantics.
4. Do not expand scope without an explicit issue or design decision.
5. Add adversarial tests before or alongside implementation.
6. If PostgreSQL semantics make an invariant impossible, STOP and document the
   conflict instead of weakening the invariant.
7. Never claim an operation is protected until a regression test demonstrates
   it.
8. Treat unsupported operations as security-relevant documentation.
9. Do not grant the protected writer privileges that bypass enforcement.
10. Prefer the smallest implementation capable of falsifying the current
    hypothesis.

> Do not "finish the product." Implement only the requested invariant or test.

The required development sequence is:

```text
SPEC
-> adversarial test
-> implementation
-> regression test
-> diff review
-> bypass attempt
-> merge
```

Successful execution and green CI are necessary evidence, not sufficient
security evidence.
