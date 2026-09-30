# WriteLeash PostgreSQL Research — Threat Model

Last updated: 2026-09-23

> This threat model describes the existing PostgreSQL/native research
> mechanism. It does not specify the planned public Free product: #106 governs
> safe WooCommerce bulk price changes, whose implementation is pending #107–
> #112. Public release is deferred. The advanced WordPress Guard/Doctor/
> Redirection substrate has a separate threat model in
> `wordpress/writeleash/THREAT-MODEL.md`.

## 1. Purpose

This document defines what the WriteLeash PostgreSQL research mechanism is designed to defend, who is considered trusted, and which bypass classes must be tested.

The PostgreSQL research mechanism is a security-sensitive database primitive.

The threat model is part of the product.

---

# 2. Security objective

Primary objective:

> **Prevent supported durable relational mutations from exceeding the mutation authority granted to the actor or capability that caused them.**

The attacker wins if they can make a supported mutation exceed authority and still become durable.

---

# 3. Protected assets

The PostgreSQL research mechanism protects:

- relational data in declared protected tables
- mutation-budget integrity
- capability state
- policy state
- trusted enforcement functions
- audit evidence associated with enforcement

---

# 4. Trusted components

Initially trusted:

- PostgreSQL engine
- WriteLeash-owned policy schema
- trusted enforcement functions
- trusted installation/admin role
- policy administrator
- capability issuer, when capability support exists

Trust should remain minimal.

---

# 5. Untrusted or semi-trusted actors

Treat as untrusted:

- repair scripts
- incident-response and recovery automation
- DBRE / operator scripts
- workflow workers
- admin automation
- AI agents
- support bots
- generated SQL
- application inputs
- task-specific database roles
- clients retrying or reconnecting unexpectedly

This is a threat taxonomy, not a market-priority list. AI agents and support
bots are writer classes, not the product definition.

Do not assume the writer is malicious.

Accidental behavior is sufficient to cause unsafe mutations.

---

# 6. Explicit non-goal attacker

The PostgreSQL research mechanism does not claim protection against a database superuser.

A true PostgreSQL superuser can generally bypass database-local enforcement.

Therefore:

```text
SUPERUSER
```

must be outside the protected writer model.

---

# 7. Core attacker goal

Find any path where:

```text
actual supported durable effect
>
granted mutation authority
```

and:

```text
transaction still commits
```

This is the canonical bypass.

---

# 8. Threat classes

## T1 — Broad single statement

Example:

```sql
UPDATE subscriptions
SET status = 'refunded';
```

Expected:

```text
actual rows > budget
→ abort
```

---

## T2 — Statement decomposition

Attacker splits a broad mutation into many small statements:

```text
1 row
1 row
1 row
...
```

Expected:

> Transaction-wide accounting still detects total authority consumption.

---

## T3 — Transaction decomposition

Attacker splits work into many transactions.

Relevant once capability-wide budgets exist.

Expected:

> Cross-transaction authority is consumed and does not regenerate.

---

## T4 — Savepoint manipulation

Example:

```sql
SAVEPOINT x;
UPDATE ...;
ROLLBACK TO SAVEPOINT x;
```

Questions:

- Does accounting restore correctly?
- Can rolled-back effects poison or replenish budget incorrectly?
- Can nested savepoints create negative/duplicate accounting?

---

## T5 — UPSERT path

Example:

```sql
INSERT ...
ON CONFLICT DO UPDATE ...
```

Expected:

> Actual mutation path must be accounted correctly.

---

## T6 — CTE writes

Examples:

```sql
WITH x AS (
  UPDATE ...
  RETURNING *
)
SELECT ...
```

Expected:

> Supported mutation must not escape accounting.

---

## T7 — COPY

Bulk ingestion may create many writes.

Expected:

- supported and accounted; or
- rejected/documented as unsupported.

---

## T8 — TRUNCATE

`TRUNCATE` can create catastrophic blast radius without row-level semantics.

Expected V0 strategy should be explicit:

- deny for protected writer roles; or
- mark unsupported and fail closed.

---

## T9 — FK cascades

Example:

```text
DELETE 1 parent row
→ deletes 50,000 child rows
```

Expected:

> Product claims must reflect whether downstream relational effects are fully accounted.

---

## T10 — Nested triggers

One write may invoke additional writes through triggers.

Questions:

- Are nested effects counted?
- Can trigger recursion escape policy?
- Can a user-defined trigger mutate unaccounted protected state?

---

## T11 — Stored procedures

Actor calls:

```sql
SELECT some_function(...);
```

or:

```sql
CALL some_procedure(...);
```

Procedure performs writes internally.

Expected:

- writes remain accounted if within supported path; or
- opaque paths are rejected/documented.

---

## T12 — Partition routing

Writes may route from parent table to partitions.

Questions:

- where is accounting attached?
- can direct partition writes bypass policy?
- can partition ownership weaken enforcement?

---

## T13 — Concurrency

Two or more sessions attempt to consume the same capability authority.

Expected:

> Aggregate committed consumption never exceeds granted authority.

---

## T14 — Retry ambiguity

Client retries after timeout.

Risk:

```text
first transaction committed
client did not observe success
client retries
authority consumed twice
```

This is a capability/idempotency concern.

---

## T15 — Connection pooling

Pooled connections may be reused by multiple logical tasks.

Risk:

- transaction-local actor state leaks
- capability binding leaks
- stale session state persists

Expected:

> Capability/actor association must be scoped safely.

---

## T16 — Policy tampering

Writer attempts:

```text
UPDATE writeleash.policy ...
```

or equivalent.

Expected:

> denied.

---

## T17 — Trigger removal

Writer attempts:

```text
DROP TRIGGER
ALTER TABLE ... DISABLE TRIGGER
```

Expected:

> protected role lacks authority.

---

## T18 — Function replacement / search_path attack

If trusted code resolves attacker-controlled objects, privilege escalation may occur.

Expected:

- hardened `SECURITY DEFINER`
- fixed safe `search_path`
- trusted ownership

---

## T19 — Role escalation

Writer attempts to:

- `SET ROLE`
- assume privileged role
- obtain ownership
- use inherited privileges

Expected:

> deployment guidance prevents trivial bypass.

---

## T20 — Unprotected alternate path

Same underlying data may be modified through:

- writable view
- direct partition
- alternate table
- procedure
- FDW
- extension

Expected:

> all supported write paths are covered, or limitations are explicit.

---

## T21 — Authority laundering through compensating mutations

Relevant once capability-wide consumable budgets exist.

Attacker or buggy automation attempts to consume authority and then reverse database state in order to manufacture fresh authority.

Example:

```text
initial positive-delta authority = 100
TX1 +80 COMMIT
TX2 -80 COMMIT
attacker claims authority restored to 100
```

Expected:

> Ordinary compensating mutations do not restore authority already consumed. Capability-wide authority remains monotonic unless an explicit replenishment mechanism is separately authorized.

Also test repeated oscillation:

```text
+40
-40
+40
-40
```

The writer must not be able to cycle state to create an unbounded effective budget.

---

# 9. Out of scope for initial claims

The PostgreSQL research mechanism does not claim to safely account for:

- arbitrary DDL
- PostgreSQL superuser behavior
- external HTTP calls
- filesystem writes
- remote system effects
- complete business/economic consequences that are not represented by the declared relational effect
- arbitrary extension effects
- sequence rollback semantics
- every stored procedure
- every trigger graph
- every FDW
- every PostgreSQL feature

Narrow claims are preferred over false completeness.

---

# 10. Security posture

The PostgreSQL research mechanism should prefer:

```text
fail closed
```

over:

```text
guess and allow
```

when protected state is involved.

If an operation cannot be understood safely:

```text
DENY
```

is preferable for security-sensitive deployment modes.

---

# 11. Security testing philosophy

Every security claim requires:

- positive test
- negative test
- adversarial test
- regression test after a bug is fixed

When a bypass is found:

```text
reproduce
→ minimize
→ classify
→ patch
→ regression test
→ update threat model if necessary
```

---

# 12. Kill criterion

The product should be killed or narrowed if there exists a broad, generic, practical class of supported relational mutation that:

```text
cannot be accounted reliably
```

without introducing unacceptable complexity or overhead.

Do not protect the idea from evidence.
