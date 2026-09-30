# Mutation Budgets

This document preserves PostgreSQL/native research semantics. It does not
define the intended public Free product: #106 governs planned safe WooCommerce
bulk price changes, which remain unimplemented pending #107–#112. Public release
is deferred. The advanced WordPress Guard/Doctor/Redirection substrate is
separate and is not changed by this research document.

## Core Model

Database write permission is usually binary: a role may update a relation or it
may not. The WriteLeash PostgreSQL research mechanism explores a second dimension:

> A writer may have permission to write while possessing only finite mutation
> authority.

The long-term principle is:

> Writes consume authority.

Authority must be measurable, enforced at the database durability boundary,
and exhausted rather than advisory. A denied mutation must not become durable.

## Phase 0: Row-Count Budgets

Row-count authority was the first proof mechanism: the local PostgreSQL 16.4
V0 candidate attaches an `UPDATE` row-event budget **per ordinary protected
relation, per top-level transaction**. The generic trigger and #27 plan/preflight
are exercised on two independently budgeted, runtime-named tables by
[`./writeleash demo`](../README.md#run-the-arbitrary-table-postgresql-research-demo-locally).
State transitions and numeric deltas are also implemented and tested as
**fixed research-fixture rules**, not as generic product-facing policy APIs.

```yaml
subscriptions:
  max_rows_updated_per_transaction: 5
```

This YAML illustrates a finite row-update budget; it is **not** the V0 policy
installation syntax. The reviewed V0 surface is `./writeleash protect-update`
and a trusted-owner `CREATE TRIGGER` with a canonical decimal budget.
Neither representation grants `UPDATE` permission; PostgreSQL privileges remain
responsible for that. The budget narrows already-granted permission.

[spec.md](spec.md) defines cumulative transaction-wide accounting and
rollback/denial expectations. The **tested PG16.4 V0 UPDATE row-event surface**
counts each BEFORE UPDATE row trigger invocation, including repeated updates
of the same row and no-op assignments; a zero-row UPDATE consumes none. The
[#47 product security suite](../experiments/native_tx_state/product_update_cases.sh)
tests this on arbitrary tables. This is an explicit local research behavior,
not a released or cross-version guarantee for other effect classes or SQL paths.

### Repeated-row behavior: tested local UPDATE row events

The native PostgreSQL 16.4 research fixture and generic V0 trigger count
repeated updates of the same row as separate row-update events:

```sql
BEGIN;

UPDATE subscriptions SET status = 'paused' WHERE id = 42;   -- 1
UPDATE subscriptions SET status = 'active' WHERE id = 42;   -- 2
UPDATE subscriptions SET status = 'paused' WHERE id = 42;   -- 3
UPDATE subscriptions SET status = 'active' WHERE id = 42;   -- 4
UPDATE subscriptions SET status = 'paused' WHERE id = 42;   -- 5
UPDATE subscriptions SET status = 'active' WHERE id = 42;   -- 6: denied

COMMIT;
```

Both fixtures observed the sixth event denying the top-level transaction under
a budget of five. The generic product candidate uses **row events, not distinct
row identities**, within its documented PG16.4 trust and table envelope. No
unsupported PostgreSQL version or broader operation inherits that evidence.

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
six-row update into six allowed one-row updates. The research mechanism therefore
accumulates consumption across all supported statements in the same top-level
transaction.

PostgreSQL transactions are the V0 durability boundary. A transaction either
commits its relational changes or does not. When its cumulative count exceeds
the budget, none of its protected mutations may become durable.

Savepoints do not create new top-level transactions and therefore must not
create fresh budgets. The required behavior for rolled-back allowed work and
irreversible denial is defined in [spec.md](spec.md). The historical
SQL/PLpgSQL experiment failed irreversible denial under savepoint and caught
exception recovery. The later PG16.4 native fixture and the #47/#48 product
tests demonstrate sticky denial and rejected COMMIT for the tested local
paths; neither establishes a production or other-version support claim.

## One Row Across Many Transactions

A transaction-local budget does not aggregate across transactions:

```text
one transaction:
  repeated updates of one row
  => the tested PostgreSQL 16.4 V0 row-event budget counts each UPDATE event;
     the sixth event under budget 5 denies the transaction

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

## Phase 0 Research Fixture: Forbidden State Transitions

Mutation volume is not enough. A single row can exceed authority through a
sensitive transition:

```yaml
users.role:
  deny_transitions:
    - "* -> admin"
```

This level reasons about old and new state, not only row count. The local
PG16.4 research fixture tests one fixed `users.role` rule (`* -> admin` denied;
`member -> moderator` allowed). Other rules such as `* -> owner` and a generic
transition-policy interface are **not implemented**. No supported
release or general state-transition protection is claimed.

## Phase 0 Research Fixture: Quantitative Effect Budgets

Some authority is expressed by a numeric effect:

```yaml
refunds.amount:
  max_total_increase: 100
```

A transaction with increases of `30`, `20`, and `40` totals `90` and may pass.
Adding `25` would total `115` and must deny the transaction. The fixed
`refunds.amount` **research fixture** tests exact positive-delta accumulation,
repeated rows, NULL/special values and a narrow finite-decimal grammar on
PG16.4 ([fixture-only decision](numeric-delta-decision-proposal.md)). It does
not provide a generic numeric policy API. Other metrics must explicitly define
precision, NULL, and negative-effect handling; a positive-only, absolute, gross
or net metric must not silently switch semantics.

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
The later generic interfaces and task-wide layers are not current product
implementation claims; the transition and numeric rules above are fixed local
research fixtures only.

## Architecture Boundary

For stable known operations such as `refund_customer(customer_id, amount)` or
`cancel_subscription(subscription_id)`, prefer a narrow application API or
stored procedure. The PostgreSQL research mechanism is relevant only when legitimate mutation shape is
broad or evolving, a fixed operation catalog is impractical, multiple upstream
paths need the same database-level backstop, or actual relational effects must
be bounded independently of upstream correctness.
