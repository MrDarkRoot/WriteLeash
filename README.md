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

CommitCap explores whether PostgreSQL write permission can be constrained by
finite, measurable, enforceable, and consumable mutation authority.

> No supported durable relational mutation may exceed the mutation authority
> granted to the actor or capability that caused it.

## Project Status

CommitCap is in **Phase 0: security proof / falsification**.

- This repository currently contains specifications, not an implementation.
- No PostgreSQL operation is currently claimed to be protected.
- The initial platform is PostgreSQL only.
- The initial proof is a transaction-wide row-update budget on one protected
  relation.
- Security claims will be limited to operations covered by adversarial
  regression tests.

Do not deploy CommitCap as a security control until an implementation exists
and its documented support envelope has passed the required tests.

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

The broad target is semi-trusted database writers, including repair jobs,
workflow engines, support bots, operator scripts, internal tools, and AI
agents. The security primitive is about database mutation authority, not the
technology that generated the SQL.

CommitCap is not primarily an AI firewall, SQL linter, SQL-generation
assistant, approval workflow, IAM replacement, RLS replacement, database
proxy, MCP firewall, dashboard product, or Bytebase competitor.

## Intended Evolution

This sequence is conceptual. Only its first step belongs to the initial proof.

```text
transaction mutation budgets
-> semantic transition budgets
-> quantitative effect budgets
-> task-scoped mutation capabilities
-> cross-transaction consumable authority
```

The later levels must not be implemented until the transaction-scoped
primitive is technically credible.

## Source Of Truth

- [SPEC.md](SPEC.md) is the normative product and behavior specification.
- [docs/threat-model.md](docs/threat-model.md) defines actors, assets, trust
  boundaries, and bypass surfaces.
- [docs/mutation-budget.md](docs/mutation-budget.md) explains the authority
  model and its intended evolution.
- [docs/limitations.md](docs/limitations.md) records unsupported and unresolved
  behavior.
- [SECURITY.md](SECURITY.md) defines vulnerability reporting and security-bug
  criteria.
- [CONTRIBUTING.md](CONTRIBUTING.md) defines the required engineering workflow.

When prose conflicts, `SPEC.md` controls. A specification is not evidence that
an implementation satisfies it.
