# CommitCap

Mutation budgets for PostgreSQL.

Give automation write access.\
Cap the blast radius.

```sql
UPDATE subscriptions
SET status = 'refunded';
```

Without an effective bound, a missing predicate can become:

```text
UPDATE 182417
```

The first CommitCap proof target is:

```text
CommitCap:
182417 > budget 5

TRANSACTION ABORTED
```

CommitCap gives flexible production automation a finite loss envelope by
turning database `WRITE` permission into measurable, consumable mutation
authority.

> No supported durable relational mutation may exceed the mutation authority
> granted to the actor or capability that caused it.

## Project Status

CommitCap is in **Phase 0: security proof / falsification**.

- This repository contains specifications, research implementations, and
  experiment harnesses, but no released or supported implementation.
- No PostgreSQL operation is currently claimed to be protected.
- The initial database target is PostgreSQL only.
- The initial proof is a transaction-wide row-update budget on one protected
  relation.
- Security claims will be limited to operations covered by adversarial
  regression tests.
- Market falsification runs in parallel against concrete broad-but-bounded
  production workflows; it must not expand build scope prematurely.
- Managed-PostgreSQL feasibility is an early evidence gate for at least one
  realistic environment, not a promise to support every cloud.

Do not deploy CommitCap as a security control until a supported implementation
is released and its documented support envelope has passed the required tests.

## Initial Proof

An illustrative policy is:

```yaml
subscriptions:
  max_rows_updated_per_transaction: 5
```

The intended V0 behavior is:

```text
UPDATE touching 1-5 protected rows
=> COMMIT

UPDATE touching 6 or more protected rows
=> ABORT the entire transaction

6 statements each updating 1 protected row in one transaction
=> ABORT the entire transaction

any denied transaction
=> no protected mutation from that transaction becomes durable
```

The count is transaction-wide. Rewriting one broad update as several smaller
updates in the same transaction must not recover budget. Updating the same row
multiple times consumes one unit for each row-update event; V0 does not count
distinct row identities.

Savepoints, exception handling, cascades, nested triggers, partitions, and
other PostgreSQL execution paths are security-relevant test cases. They are
not silently assumed to work. See [the limitations](docs/limitations.md).

## What CommitCap Is

CommitCap's technical thesis is:

> Writes consume authority.

The broad target is semi-trusted database writers. Validation starts with:

1. production repair and data-remediation jobs;
2. incident-response and recovery automation;
3. DBRE and operator scripts;
4. workflow engines and internal tooling with evolving write surfaces;
5. admin automation;
6. AI agents only when flexible writes are genuinely required; and
7. support bots only where narrow operations are insufficient.

AI is a use case, not the product definition or market dependency. The security
primitive is about database mutation authority, not the technology that
generated the SQL.

CommitCap is not primarily an AI firewall, SQL linter, SQL-generation
assistant, approval workflow, IAM replacement, RLS replacement, database
proxy, MCP firewall, dashboard product, or Bytebase competitor.

For a known stable operation such as
`refund_customer(customer_id, amount)` or
`cancel_subscription(subscription_id)`, prefer a narrow application API or
stored procedure. CommitCap becomes relevant when legitimate mutation shape is
broad or evolving, a fixed operation catalog is impractical, multiple upstream
paths need the same database-level backstop, or actual relational effects must
be bounded independently of upstream correctness.

The target middle ground is useful write flexibility plus bounded durable
mutation authority.

CommitCap measures declared relational effects, not complete business
consequences. A `refunds.amount positive_delta = 100` measurement does not by
itself prove that a processor transferred $100, a customer received $100, a
ledger settled, or an external workflow succeeded. The policy issuer owns that
mapping.

## Intended Evolution

This sequence is conceptual. Existing experiments cover only narrow
transaction row-event mechanics. State-transition and numeric-delta budgets
are current Phase 0 targets but are not implemented; capability layers remain
future work.

```text
transaction mutation budgets
-> semantic transition budgets
-> quantitative effect budgets
-> task-scoped mutation capabilities
-> cross-transaction consumable authority
```

Capability-wide consumable authority is monotonic by default. If a capability
starts at 100, `+80 COMMIT` leaves 20 and a later `-80 COMMIT` still leaves 20.
Ordinary compensating mutations must not replenish authority; otherwise a
writer could oscillate state to perform **authority laundering**. Any future
replenishment operation must be explicit and separately authorized.

Basic row limits are useful but are not the long-term moat. If PostgreSQL or a
cloud provider ships `MAX ROWS UPDATED`, possible future layers still include
state-transition authority, quantitative effects, task-scoped capabilities,
cross-transaction consumption, atomic concurrency, retry/idempotency semantics,
and capability lifecycle. None of those future layers is claimed implemented.

## Source Of Truth

- [docs/role.md](docs/role.md) defines maintainer scope and operating rules.
- [docs/product.md](docs/product.md) defines the current product thesis.
- [docs/roadmap.md](docs/roadmap.md) defines evidence gates and sequencing.
- [docs/decisions.md](docs/decisions.md) records accepted decisions.
- [docs/spec.md](docs/spec.md) defines current intended semantics.
- [docs/test-plan.md](docs/test-plan.md) defines canonical current test IDs.
- [docs/threat-model.md](docs/threat-model.md) defines trust and bypass surfaces.

[SPEC.md](SPEC.md) and the detailed experiment sections in
[docs/limitations.md](docs/limitations.md) preserve the original Phase 0
row-budget specification and evidence. Their `CC-*` labels are **legacy
experiment IDs**, not current test-plan meanings.

[docs/mutation-budget.md](docs/mutation-budget.md) explains the authority model,
[SECURITY.md](SECURITY.md) defines vulnerability reporting, and
[CONTRIBUTING.md](CONTRIBUTING.md) defines engineering workflow. A specification
or passing research experiment is not a released support claim.
