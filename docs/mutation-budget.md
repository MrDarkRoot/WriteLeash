# Mutation Budgets

## Core Model

Database write permission is usually binary: a role may update a relation or it
may not. CommitCap explores a second dimension:

> A writer may have permission to write while possessing only finite mutation
> authority.

The long-term principle is:

> Writes consume authority.

Authority must be measurable, enforced at the database durability boundary,
and exhausted rather than advisory. A denied mutation must not become durable.

## V0: Row-Count Budgets

The initial proof limits row-update events for one protected relation in one
top-level PostgreSQL transaction.

```yaml
subscriptions:
  max_rows_updated_per_transaction: 5
```

This example grants five row-update events. It does not grant permission to run
`UPDATE`; PostgreSQL privileges remain responsible for that. The budget narrows
already-granted permission.

V0 counts events, not distinct row identities:

```sql
BEGIN;

UPDATE subscriptions SET status = 'paused' WHERE id = 42;   -- 1
UPDATE subscriptions SET status = 'active' WHERE id = 42;   -- 2
UPDATE subscriptions SET status = 'paused' WHERE id = 42;   -- 3
UPDATE subscriptions SET status = 'active' WHERE id = 42;   -- 4
UPDATE subscriptions SET status = 'paused' WHERE id = 42;   -- 5
UPDATE subscriptions SET status = 'active' WHERE id = 42;   -- 6: deny

COMMIT;
```

The sixth event must deny the entire transaction. Counting distinct row IDs
would permit repeated mutation to evade the declared volume bound.

## Why Accumulation Is Transaction-Wide

A statement-scoped limit is easy to evade:

```text
budget: 5 rows per statement

statement 1: update 1 row
statement 2: update 1 row
statement 3: update 1 row
statement 4: update 1 row
statement 5: update 1 row
statement 6: update 1 row
```

If each statement receives a fresh budget, decomposition converts a denied
six-row update into six allowed one-row updates. CommitCap therefore
accumulates consumption across all supported statements in the same top-level
transaction.

PostgreSQL transactions are the V0 durability boundary. A transaction either
commits its relational changes or does not. When its cumulative count exceeds
the budget, none of its protected mutations may become durable.

Savepoints do not create new top-level transactions and therefore must not
create fresh budgets. The required behavior for rolled-back allowed work and
irreversible denial is defined in [SPEC.md](../SPEC.md). Whether PostgreSQL can
provide that behavior cleanly with SQL/PL/pgSQL is a core Phase 0 experiment.

## One Row Across Many Transactions

These are different under V0:

```text
one transaction:
  update 1 row six times
  => cumulative count 6; deny when budget is 5

six separate transactions:
  update 1 row once in each transaction
  => each transaction consumes 1 of its own 5; each may commit
```

The second case is not prevented by a per-transaction budget. Treating every
new transaction as replenished authority is an explicit V0 boundary. It keeps
the first experiment small but cannot cap a task that deliberately spreads
work over multiple transactions.

Application retries have the same boundary. A genuinely new transaction gets
a new V0 budget. No cross-retry aggregate protection is claimed.

## Future: Forbidden State Transitions

Mutation volume is not enough. A single row can exceed authority through a
sensitive transition:

```yaml
users.role:
  deny_transitions:
    - "* -> admin"
    - "* -> owner"
```

This level would reason about old and new state, not only row count. It is
future work and has no V0 semantics.

## Future: Quantitative Effect Budgets

Some authority is expressed by a numeric effect:

```yaml
refunds.amount:
  max_total_increase: 100
```

A transaction with increases of `30`, `20`, and `40` totals `90` and may pass.
Adding `25` would total `115` and must deny the transaction. Questions about
negative effects, numeric precision, currency, nulls, updates to the same row,
and compensating writes remain unspecified. They must not be guessed from this
illustration.

## Future: Mutation Capabilities

The strategic model is task-scoped consumable authority:

```yaml
capability: support-task-817
actor: support_bot
expires_in: 10m

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

Unlike a transaction budget, capability authority would remain consumed across
transactions:

```text
TX1 refund 30 -> remaining 70
TX2 refund 40 -> remaining 30
TX3 refund 50 -> deny
```

Concurrent consumption, retries, expiration, identity binding, signatures,
revocation, and durable receipts are future design problems. V0 must not
preemptively build them.

## Product Sequence

```text
transaction mutation budgets
-> semantic transition budgets
-> quantitative effect budgets
-> task-scoped mutation capabilities
-> cross-transaction consumable authority
```

Potential later operational or commercial surfaces include expiring and signed
capabilities, concurrency-safe authority consumption, centralized policy
distribution, advanced audit receipts, Vault/KMS integration, embedded/OEM
licensing, and enterprise support.

> The OSS primitive must be technically credible before the commercial product
> is built.

No Phase 0 architecture decision should be justified only by a hypothetical
future commercial requirement.
