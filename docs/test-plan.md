# CommitCap — Security & Correctness Test Plan

Last updated: 2026-09-23

## 1. Objective

The test program exists to answer one question:

> **Can a supported mutation exceed granted authority and still become durable?**

If yes, the test has found a CommitCap security failure.

## Canonical ID namespace

The identifiers in this document are the canonical current CommitCap test IDs.
Earlier Phase 0 experiments used a legacy `CC-*` namespace whose numbers are
not equivalent to the meanings below. Historical scripts and recorded results
must retain those identifiers but label them **legacy experiment IDs**; never
infer a current test result from a same-number historical result.

---

# 2. Test principles

Every important behavior should have:

- positive test
- negative test
- adversarial test
- regression test after bugs

Tests should prefer observable durable state over trusting logs.

---

# 3. Test result terminology

Use:

```text
PASS
DENY
ABORT
UNSUPPORTED
BUG
```

Definitions:

- `PASS` — mutation is within authority and commits
- `DENY` — CommitCap blocks before commit
- `ABORT` — transaction is invalidated and protected mutation does not survive
- `UNSUPPORTED` — operation is deliberately outside product scope
- `BUG` — actual behavior violates documented semantics

---

# 4. V0 fixture

The historical fixed-table research fixture below is distinct from the
[#47](https://github.com/MrDarkRoot/CommitCap/issues/47) generic UPDATE-budget
security suite on arbitrary tables. A passing fixture case alone is not a
product-surface result; see
[`product_update_cases.sh`](../experiments/native_tx_state/product_update_cases.sh)
for the additional OID-isolation, configuration, denial and durability probes.

Protected tables:

```text
subscriptions
users
refunds
```

Suggested fields:

```text
subscriptions:
  id
  customer_id
  status
  plan

users:
  id
  tenant_id
  role

refunds:
  id
  customer_id
  amount
```

Policies:

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

# 5. Core row-budget tests

## CC-001 — Safe single-row update

Expected:

```text
1 row updated
budget = 5
COMMIT
```

---

## CC-002 — Exact budget boundary

Expected:

```text
5 rows updated
budget = 5
COMMIT
```

---

## CC-003 — Single broad statement exceeds budget

Expected:

```text
6+ rows updated
budget = 5
ABORT
```

Verify durable state is unchanged.

---

## CC-004 — Statement decomposition bypass attempt

Transaction:

```text
6 × UPDATE 1 row
```

Expected:

```text
transaction total = 6
budget = 5
ABORT
```

This is a Phase 0 critical invariant test.

---

# 6. Transaction tests

## CC-010 — Explicit ROLLBACK

Mutation occurs but transaction rolls back.

Expected:

- no durable protected change
- no durable capability consumption

---

## CC-011 — Savepoint rollback restores transaction budget

Example:

```text
consume 4
ROLLBACK TO SAVEPOINT
consume 3
COMMIT
```

Expected behavior must match spec.

Preferred:

```text
final effective consumption = 3
```

---

## CC-012 — Nested savepoints

Test:

- multiple nested savepoints
- rollback to inner
- rollback to outer
- release savepoint

Goal:

> no budget desynchronization.

---

## CC-013 — Ordinary transaction failure after partial effects

Cause ordinary SQL error after several protected writes.

Expected:

- whole transaction aborts
- no protected durable mutation
- no capability consumption finalized

---

# 7. State-transition tests

## CC-020 — Allowed role transition

Example:

```text
member -> moderator
```

if allowed.

Expected:

```text
COMMIT
```

---

## CC-021 — Forbidden admin promotion

Example:

```text
member -> admin
```

Expected:

```text
ABORT
```

---

## CC-022 — Bulk forbidden transition

Multiple rows attempt promotion.

Expected:

```text
ABORT
```

even if row-count budget would otherwise allow.

---

# 8. Numeric delta tests

The values in these tests are declared PostgreSQL relational metrics. They do
not by themselves prove that money moved, a ledger settled, a customer received
funds, or an external workflow succeeded.

## CC-030 — Refund delta below budget

Example:

```text
+$30
+$20
+$40
= +$90
```

Expected:

```text
COMMIT
```

---

## CC-031 — Refund delta exactly at budget

Example:

```text
total = +$100
```

Expected:

```text
COMMIT
```

---

## CC-032 — Refund delta above budget

Example:

```text
total = +$101
```

Expected:

```text
ABORT
```

---

## CC-033 — Mixed positive and negative deltas

Test the documented aggregate metric explicitly.

For a `total_positive_delta` budget:

```text
+80
-80
```

Expected within the same committed transaction:

> positive consumption is 80; the negative delta does not erase the positive-delta consumption.

For other metrics such as `absolute_total_delta` or an explicitly defined net metric, expected behavior must match the specification exactly.

Verify that implementation never silently switches between gross, positive-only, absolute, and net semantics.

---

## CC-036 — Concurrent numeric-delta enforcement on one contended row

Phase 0 research-fixture test (PostgreSQL 16.4, READ COMMITTED, two writer
sessions, the existing native transaction-state harness). Two sessions modify
the same protected `refunds` row; one must demonstrably wait on the row lock
(`pg_stat_activity.wait_event_type = Lock` with `pg_blocking_pids()` naming
the other backend), and the waiting session's numeric delta must be computed
from the row version visible after the lock is released, exactly once.

Scenarios:

```text
A: lock holder commits; waiter resumes and consumes the post-lock
   positive delta only (stale OLD-value accounting would consume a
   detectably different amount)
B: waiter's post-lock delta exceeds its remaining transaction-local
   numeric authority; denial is sticky through savepoint recovery and
   top-level COMMIT is rejected; the lock holder's committed effects
   remain durable
C: lock holder rolls back; waiter resumes against the last committed
   row version and consumes its actual delta
```

Expected:

```text
per-backend independent transaction-local accounting
each committed transaction stays within its own granted authority
fresh trusted-admin connections observe the exact expected rows
no deadlock; denial never transfers between sessions
```

## CC-034 — Compensating mutation must not replenish capability authority

Relevant once capability-wide budgets exist.

Example:

```text
initial positive-delta authority = 100
TX1 commits +80
remaining = 20
TX2 commits -80
remaining = 20
TX3 attempts +30
```

Expected:

```text
TX3 DENIED
```

A committed inverse mutation must not manufacture fresh authority unless an explicit future replenishment operation is defined and authorized.

---

## CC-035 — Repeated oscillation / authority laundering

Relevant once capability-wide budgets exist.

Attempt repeated cycles such as:

```text
+40
-40
+40
-40
...
```

Expected:

> Consumption follows the declared effect metric and cannot be reset merely by oscillating state.

This is a security regression target for authority-laundering attempts.

---

# 9. Alternate SQL form tests

## CC-040 — CTE UPDATE

Use writable CTE.

Expected:

> same accounting as ordinary UPDATE.

---

## CC-041 — INSERT ... ON CONFLICT DO UPDATE

Expected:

- insert effects counted as insert
- conflict updates counted correctly
- no bypass

---

## CC-042 — Multi-row INSERT

Expected:

> inserted-row budget enforced.

---

## CC-043 — DELETE ... RETURNING

Expected:

> delete budget unaffected by RETURNING.

---

# 10. High-risk PostgreSQL path tests

## CC-050 — COPY

Determine policy:

- supported and accounted; or
- denied/unsupported.

No silent bypass.

---

## CC-051 — TRUNCATE

Preferred V0:

```text
DENY / unsupported
```

for protected writer role.

---

## CC-052 — FK cascade

Scenario:

```text
DELETE 1 parent
→ cascade deletes many child rows
```

Determine and test exact supported semantics.

No misleading claim.

---

## CC-053 — Nested trigger write

One protected write triggers another.

Expected:

> supported downstream effects accounted, or path explicitly unsupported.

---

## CC-054 — Stored procedure writes

Procedure internally performs protected DML.

Expected:

> accounted or deliberately rejected/documented.

---

## CC-055 — Partition routing

Test:

- write through parent
- direct write to child partition
- update causing partition movement if applicable

No policy bypass.

---

# 11. Policy tampering tests

## CC-060 — Direct policy table UPDATE

Writer attempts to modify CommitCap policy.

Expected:

```text
permission denied
```

---

## CC-061 — DROP TRIGGER

Expected:

```text
permission denied
```

---

## CC-062 — DISABLE TRIGGER

Expected:

```text
permission denied
```

---

## CC-063 — Replace trusted function

Expected:

```text
permission denied
```

---

## CC-064 — Modify search_path / object shadowing attempt

If trusted functions use `SECURITY DEFINER`, attempt object shadowing.

Expected:

> no privilege escalation or policy bypass.

---

## CC-065 — SET ROLE escalation

Attempt to assume a bypass role.

Expected:

> deployment role model prevents it.

---

# 12. Concurrency tests

Required before capability-wide authority is considered production-ready.

## CC-070 — Two sessions overspend same capability

Initial authority:

```text
100
```

Concurrent attempts:

```text
TX-A consume 70
TX-B consume 70
```

Expected:

```text
at most one combination resulting in total <= 100 commits
```

Never:

```text
total committed consumption = 140
```

---

## CC-071 — High concurrency

Run N parallel sessions against one capability.

Invariant:

```text
sum(committed consumption)
<=
granted authority
```

---

# 13. Retry/idempotency tests

Required once cross-transaction capability support exists.

## CC-080 — Failed transaction retry

Failed transaction consumes no durable capability authority.

---

## CC-081 — Commit success but client timeout

Simulate ambiguous result.

Document expected retry behavior.

---

## CC-082 — Same idempotency key retried

If idempotency exists:

Expected:

> no double consumption.

---

# 14. Connection pooling tests

## CC-090 — Capability context reset

Reuse same physical connection for different logical tasks.

Expected:

> capability identity does not leak.

---

## CC-091 — Transaction boundary cleanup

After COMMIT/ROLLBACK, no stale transaction-local accounting remains.

---

# 15. Performance tests

Benchmark at minimum:

```text
baseline PostgreSQL write
vs
CommitCap-protected write
```

Measure:

- p50 latency
- p95 latency
- p99 latency
- transactions/sec
- rows/sec
- overhead by affected-row count

Run:

- 1-row UPDATE
- 5-row UPDATE
- 100-row UPDATE
- mixed workload

Do not publish performance claims without data.

---

# 16. Security regression rule

Every discovered bypass receives a permanent regression test.

Naming:

```text
CC-REG-YYYY-NNN
```

Example:

```text
CC-REG-2026-001
```

The test description should contain:

- root cause
- minimal exploit pattern
- expected fixed behavior

---

# 17. Phase 0 minimum pass set

Before moving to OSS V0, these must pass:

```text
CC-001
CC-002
CC-003
CC-004

CC-010
CC-011
CC-012

CC-021

CC-030
CC-031
CC-032
CC-033

CC-040
CC-041

CC-060
CC-061
CC-062
CC-063
CC-064
CC-065
```

And the project must have explicit behavior for:

```text
COPY
TRUNCATE
FK cascades
nested triggers
stored procedures
partitions
```

---

# 18. Kill test

If a broad supported mutation class can repeatedly exceed budget and commit despite reasonable architectural fixes:

```text
KILL
or
NARROW PRODUCT SCOPE
```

The test program exists to falsify CommitCap, not protect the idea.
