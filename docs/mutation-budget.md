# Mutation Budgets

This document explains current intended semantics. The repository contains
research experiments, but no released or supported CommitCap implementation.

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
irreversible denial is defined in [spec.md](spec.md). The historical
SQL/PLpgSQL experiment failed irreversible denial under savepoint and caught
exception recovery; a later native experiment established only that another
mechanism class was viable for further research, not a production support
claim.

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

## Phase 0 Target: Forbidden State Transitions

Mutation volume is not enough. A single row can exceed authority through a
sensitive transition:

```yaml
users.role:
  deny_transitions:
    - "* -> admin"
    - "* -> owner"
```

This level reasons about old and new state, not only row count. It is a current
Phase 0/V0 target, but is not implemented or supported.

## Phase 0 Target: Quantitative Effect Budgets

Some authority is expressed by a numeric effect:

```yaml
refunds.amount:
  max_total_increase: 100
```

A transaction with increases of `30`, `20`, and `40` totals `90` and may pass.
Adding `25` would total `115` and must deny the transaction. Numeric precision,
nulls, and repeated-row semantics still require explicit specification and
tests. Negative effects must follow the declared aggregate metric; they must
not silently switch a positive-only, absolute, gross, or net policy into
another semantic.

This is a declared PostgreSQL relational metric, not proof of complete business
consequence. A `refunds.amount positive_delta = 100` measurement does not by
itself prove that a payment processor transferred $100, a customer received
$100, a ledger settled, or an external workflow succeeded. The policy issuer
owns that mapping.

## Future: Mutation Capabilities

The strategic model is task-scoped consumable authority:

```yaml
capability: repair-task-817
actor: repair_worker
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

Capability-wide consumable authority is monotonic by default:

```text
initial authority = 100
TX1: +80 COMMIT -> remaining = 20
TX2: -80 COMMIT -> remaining = 20
```

A committed inverse or compensating mutation does not restore consumed
authority. Rolled-back effects remain different because they never become
durable and do not finalize consumption. Any future replenishment operation
must be explicit and separately authorized. Allowing ordinary state oscillation
to manufacture fresh capacity would enable **authority laundering**.

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

Basic row limits are useful but are not sufficient differentiation by
themselves. If PostgreSQL or a cloud provider ships `MAX ROWS UPDATED`, possible
strategic layers above it include state-transition authority, quantitative
effect budgets, task-scoped capabilities, cross-transaction consumption,
atomic concurrency, retry/idempotency semantics, and capability lifecycle.
These layers are not current implementation claims.

## Architecture Boundary

For stable known operations such as `refund_customer(customer_id, amount)` or
`cancel_subscription(subscription_id)`, prefer a narrow application API or
stored procedure. CommitCap is relevant only when legitimate mutation shape is
broad or evolving, a fixed operation catalog is impractical, multiple upstream
paths need the same database-level backstop, or actual relational effects must
be bounded independently of upstream correctness.
