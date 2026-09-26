# CommitCap — Technical Specification

Last updated: 2026-09-23

## Status

This document defines the intended semantics of CommitCap.

Research experiments exist, but no released or supported implementation is
claimed by this specification.

It is the source of truth for:

- core terminology
- security invariant
- supported effect model
- mutation-budget semantics
- enforcement expectations
- implementation boundaries

Implementation may evolve.

The invariant must not silently weaken.

---

# 1. Core invariant

> **No supported durable relational mutation may exceed the mutation authority granted to the actor or capability that caused it.**

A supported mutation that exceeds remaining authority MUST NOT become durable.

If CommitCap cannot safely account for a mutation class, that class must be:

1. explicitly unsupported;
2. rejected where practical; or
3. removed from product claims.

---

# 2. Definitions

This specification defines the enforcement primitive. It does not replace a
narrow API or stored procedure when a stable operation can be encoded cleanly.
CommitCap is relevant where legitimate mutation shape is broad or evolving, a
fixed operation catalog is impractical, multiple upstream paths need the same
database-level backstop, or relational effects must be bounded independently
of upstream correctness.

## Actor

The database principal or logical automation identity causing a mutation.

Examples:

```text
repair_worker
support_bot
agent_42
admin_script
```

An actor may hold one or more mutation capabilities.

---

## Mutation

A supported relational state change.

Initial examples:

```text
INSERT
UPDATE
DELETE
```

Future support may include:

```text
MERGE
COPY-generated writes
additional relational write paths
```

---

## Durable mutation

A supported relational mutation that remains visible after successful transaction commit.

Rolled-back mutations are not durable.

---

## Effect

A measurable **relational consequence** of a supported mutation according to explicitly documented CommitCap semantics.

Initial effect types:

```text
rows_inserted
rows_updated
rows_deleted
column_transition
numeric_delta
tenant_set
```

An effect is not automatically a complete model of external business consequence.

Example:

```text
refunds.amount positive_delta = 30
```

means the declared relational metric increased by 30. It does not by itself prove that a payment processor transferred 30 units of currency, that a ledger settled, or that an external side effect succeeded.

The policy issuer is responsible for choosing relational measurements that are meaningful for the protected workflow.

CommitCap's correctness claim stops at the declared relational effect unless an external system is explicitly brought into a future supported enforcement model.

---

## Mutation budget

A finite limit placed on one or more effects.

Examples:

```yaml
max_rows_updated: 5
max_rows_deleted: 0
max_total_increase: 100
admin_promotions: 0
```

---

## Mutation capability

A scoped authority object granting finite mutation authority to an actor or task.

Example:

```yaml
id: repair-task-817
actor: repair_worker
expires_in: 10m

authority:
  subscriptions.rows_updated: 3
  refunds.amount.total_increase: 100
```

A capability may be consumed across one or multiple transactions.

---

## Authority consumption

The amount of mutation budget consumed by supported durable effects.

Example:

```text
initial refund authority = $100
committed effect = +$30
remaining authority = $70
```

Capability-wide consumable authority is **monotonic by default**:

- successful durable effects may consume authority;
- rolled-back effects do not finalize consumption;
- a later inverse or compensating committed mutation does not automatically restore authority already consumed;
- explicit replenishment, if ever supported, must be a separate authority operation with its own semantics and authorization.

Example:

```text
initial positive-delta authority = 100
TX1 commits +80
remaining = 20
TX2 commits -80
remaining = 20
```

This prevents ordinary state oscillation from manufacturing fresh authority.

---

## Transaction-local budget

A budget that resets when a new transaction begins.

Example:

```yaml
max_rows_updated_per_transaction: 5
```

---

## Capability-wide budget

A budget that persists across transactions.

Example:

```yaml
capability:
  refunds.amount.total_increase: 100
```

Splitting work across transactions MUST NOT regenerate this authority.

---

# 3. Supported V0 effect model

V0 should focus on explicit protected tables.

The first product-surface candidate in [#47](https://github.com/MrDarkRoot/CommitCap/issues/47)
is deliberately narrower than the effect classes below: one transaction-local
`UPDATE` row-event budget per ordinary, nonpartitioned PG16.4 table, installed
by a trusted owner. State-transition and numeric-delta implementations remain
fixed research fixtures; the other effect classes below describe intended
semantics, **not** features of this candidate.

Minimum effect classes:

## Row-count effects

```text
rows_inserted
rows_updated
rows_deleted
```

Per protected table.

---

## State-transition effects

Example protected column:

```text
users.role
```

Transition policy:

```text
member -> admin
```

may be denied even if only one row changes.

---

## Numeric delta effects

Given OLD and NEW values:

```text
delta = NEW.amount - OLD.amount
```

Supported aggregate examples:

```text
total_positive_delta
total_negative_delta
absolute_total_delta
```

V0 should use the smallest set required by tests.

---

# 4. Transaction semantics

## 4.1 Statement decomposition must not bypass transaction budget

Policy:

```text
max_rows_updated_per_transaction = 5
```

This must fail:

```sql
BEGIN;

UPDATE t SET x = 1 WHERE id = 1;
UPDATE t SET x = 1 WHERE id = 2;
UPDATE t SET x = 1 WHERE id = 3;
UPDATE t SET x = 1 WHERE id = 4;
UPDATE t SET x = 1 WHERE id = 5;
UPDATE t SET x = 1 WHERE id = 6;

COMMIT;
```

Even though each statement changes one row.

---

## 4.2 Transaction rollback

If a transaction exceeds budget:

```text
transaction state
→ abort
```

No protected supported mutation from that transaction may become durable.

---

## 4.3 Savepoints

Budget accounting must follow transaction semantics correctly.

Example:

```sql
BEGIN;

SAVEPOINT a;
UPDATE ... -- consumes 4
ROLLBACK TO SAVEPOINT a;

UPDATE ... -- consumes 3

COMMIT;
```

The implementation must define whether rolled-back effects restore transaction-local budget.

Preferred semantic:

> **Rolled-back supported effects do not consume final transaction-local authority.**

This behavior must be proven with tests.

Capability-wide authority consumption MUST only finalize for durable committed effects.

---

# 5. Capability-wide semantics

This is not required for the earliest technical proof, but the model must remain compatible with it.

Example:

```text
capability remaining = $100
```

TX1 commits:

```text
+$30
```

Remaining:

```text
$70
```

TX2 commits:

```text
+$40
```

Remaining:

```text
$30
```

TX3 proposes:

```text
+$50
```

Result:

```text
DENY
```

The failed transaction must not consume `$50`.

Compensating committed mutations do not replenish capability-wide authority by default.

Example:

```text
initial positive-delta authority = $100
TX1 commits +$80
remaining = $20
TX2 commits -$80
remaining = $20
TX3 attempts +$30
DENY
```

The exact effect metric still controls what is counted. For example, a `total_positive_delta` budget ignores negative deltas for consumption; an `absolute_total_delta` budget may consume both directions. What must not happen is accidental authority regeneration from a committed inverse write.

---

# 6. Concurrency requirement

Capability consumption must be atomic.

Unsafe implementation:

```text
TX-A reads remaining = 100
TX-B reads remaining = 100

TX-A commits 70
TX-B commits 70
```

Result:

```text
consumed = 140
```

This violates the invariant.

Any capability-wide design must guarantee:

```text
committed consumption <= granted authority
```

under concurrent sessions.

---

# 7. Retry and idempotency semantics

Client and workflow retries are expected.

CommitCap must eventually define how repeated logical tasks are distinguished from new authority consumption.

Questions to resolve before capability-wide production use:

- Is authority consumed per committed transaction?
- Can a transaction carry an idempotency key?
- Can retried logical work reuse previous consumption?
- How are failed transactions distinguished from successful-but-client-timeout cases?

Do not overclaim until semantics are implemented.

---

# 8. Policy model

Illustrative format:

```yaml
actor: repair_worker

transaction:
  subscriptions:
    max_rows_updated: 5

  customers:
    max_rows_deleted: 0

transitions:
  users.role:
    deny:
      - "* -> admin"
      - "* -> owner"

effects:
  refunds.amount:
    max_total_increase: 100
```

Future capability example:

```yaml
capability:
  id: repair-task-817
  actor: repair_worker
  ttl: 10m

  authority:
    subscriptions:
      rows_updated: 3

    refunds.amount:
      total_increase: 100

    users.role:
      admin_promotions: 0

    tenants:
      max_touched: 1
```

Policy format is not stable during Phase 0.

Semantics matter more than YAML shape.

---

# 9. Enforcement boundary

CommitCap protects only when the actor cannot trivially bypass enforcement.

Protected actor roles must not be able to:

- become superuser
- alter protected tables to disable enforcement
- drop enforcement triggers
- modify policy storage
- replace trusted functions
- assume a bypass role
- take ownership of protected objects

CommitCap must clearly document required database role separation.

---

# 10. Trusted functions

If `SECURITY DEFINER` is used:

Required hardening includes:

- safe fixed `search_path`
- trusted ownership
- restricted `EXECUTE`
- no unsafe dynamic SQL
- no user-controlled object resolution
- no privilege escalation path through arguments

---

# 11. Unsupported effects

V0 does not claim universal PostgreSQL effect control.

Potentially unsupported or separately handled:

- sequence state changes
- external HTTP side effects
- filesystem effects
- remote procedure effects
- arbitrary extension behavior
- opaque procedural effects
- DDL
- superuser actions
- unprotected tables

Claims must say:

> **CommitCap governs supported transactional relational mutations and declared relational effects.**

Not:

> **CommitCap makes every PostgreSQL side effect reversible.**

or:

> **CommitCap automatically understands complete external business consequence.**

---

# 12. Fail-safe behavior

Where practical, unsupported write paths touching protected state should fail closed.

If fail-closed behavior is not possible:

- document the limitation
- exclude the path from security claims
- add a regression test proving current behavior
- consider whether the scope must be narrowed

---

# 13. Performance requirement

CommitCap must benchmark:

```text
baseline workload
vs
protected workload
```

At minimum measure:

- transaction latency
- write throughput
- trigger/accounting overhead
- cost scaling with affected-row count

No performance guarantee should be published before measurement.

---

# 14. V0 protected model

Initial demo schema should include:

```text
subscriptions
users
refunds
```

Required rules:

```text
subscriptions:
UPDATE <= 5 rows per transaction

users:
DELETE = 0

users.role:
* -> admin = denied

refunds.amount:
total positive increase <= 100 per transaction
```

---

# 15. V0 success criteria

The technical proof passes only if:

- one safe write commits
- broad write aborts
- many small statements cannot bypass transaction-wide budget
- state-transition denial works
- quantitative delta budget works
- rollback and savepoint semantics are correct
- obvious supported UPSERT/cascade cases cannot bypass accounting
- policy objects are protected from the writer role
- unsupported operations are documented and handled safely
- benchmark data exists

---

# 16. Strategic endpoint

The long-term specification target is:

> **A mutation capability grants a finite amount of authority. Every supported durable effect consumes that authority atomically.**

This is the core abstraction CommitCap should protect.
