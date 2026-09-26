# CommitCap

**Mutation Budgets for PostgreSQL.** Put a hard limit on how much a PostgreSQL
write transaction can change *within the tested research fixture*.

A writer with permission to update a table may intend to repair two rows but
accidentally update many. A **mutation budget** is a finite limit on a declared
relational effect, such as the number of row-update events or the cumulative
positive change to a numeric column. The current mechanism measures those
effects across supported writes in **one top-level transaction**; an excess
attempt denies that entire transaction, including earlier writes in it. It is
not a limit on a whole job that uses multiple transactions.

## See the safe write and the denial

These selected lines are from the **real `./demo.sh` output** on the local
PostgreSQL 16.4 research fixture (intervening command tags, error details and
other cases omitted; order and wording of shown lines preserved):

```text
1. SAFE: subscriptions repairs + allowed role transition
writer SQL result:
BEGIN
UPDATE 2
UPDATE 1
policy=2|1|0.00|false
COMMIT
  ✓ safe mutation committed
fresh trusted-admin durable state:
subscriptions=1=repaired,2=repaired,3=baseline,4=baseline,5=baseline,6=baseline,7=baseline,8=baseline,9=baseline,10=baseline
users=1=moderator,2=member,3=member,4=member,5=member,6=member
refunds=1=0.00,2=0.00,3=0.00,4=0.00,5=0.00,6=0.00,7=0.00,8=0.00
audit=0
  ✓ fresh trusted verification: exact expected durable state confirmed

2. DENIED: six small subscriptions statements; savepoint cannot clear denial
ERROR:  CommitCap mutation budget exceeded (limit 5, attempted 6)
policy / metric: subscriptions.rows_updated
granted: 5
consumed before attempt: 5
attempted effect: 6 row-update events
result: DENIED; top-level COMMIT will be rejected
sixth_SQLSTATE 54000
ROLLBACK
policy=5|1|20.00|true
ERROR:  CommitCap top-level transaction denied after mutation authority violation
result: ABORTED
commit_SQLSTATE 54000
fresh trusted-admin durable state:
subscriptions=1=baseline,2=baseline,3=baseline,4=baseline,5=baseline,6=baseline,7=baseline,8=baseline,9=baseline,10=baseline
users=1=member,2=member,3=member,4=member,5=member,6=member
refunds=1=0.00,2=0.00,3=0.00,4=0.00,5=0.00,6=0.00,7=0.00,8=0.00
audit=0
  ✓ fresh trusted verification: no protected over-authority mutation became durable
```

The denied case first writes five subscription rows and also writes to sibling
relations. The sixth row-update attempt fails; even after rolling back to a
savepoint, the top-level `COMMIT` is rejected. A *new trusted-admin connection*
checks that none of that transaction's changes became durable. See the
[demo script](demo/phase0/run.sh) for the SQL and exact assertions; this is
fixture evidence, not a general PostgreSQL guarantee.

## Try it locally

With Docker Engine running, Docker Compose v2, Bash, and network access to pull
the pinned image, from a checkout run:

```bash
./demo.sh
```

The script builds the pinned `postgres:16.4-alpine` native research fixture,
runs three safe and three denied real writer transactions, verifies exact durable
state after each from fresh trusted-admin connections, and tears down its
disposable Compose project. Expect `Demo: PASS` and `Demo exit status: 0`.
See [demo prerequisites, output, and cleanup](demo/phase0/README.md).

## Exactly what is tested today

**Research mechanism: CURRENT. Public Research Preview: PREPARING — NOT YET
RELEASED ([#31](https://github.com/MrDarkRoot/CommitCap/issues/31)). Supported
release: NO. Production-ready security control: NO.** Do not deploy this as a
production security control.

The exact tested environment is a local Docker **PostgreSQL 16.4** fixture with
a restricted, non-owner writer, trusted installer/owner roles, hard-coded test
policies, and native backend-local transaction state. Within that envelope:

- `subscriptions` and `users` have independently counted `UPDATE` row-event
  budgets of five per top-level transaction. Multiple statements share each
  budget; a denied event stays denied through tested savepoint and exception
  recovery paths and rejects the final `COMMIT`.
- The fixture denies `users.role` updates to `admin` and measures positive
  `refunds.amount` deltas with a per-transaction limit of `100.00`. These are
  declared **relational** measurements, not evidence of external money movement.
- The tested restricted writer cannot change enforcement objects or the
  test-only budgets; separate trusted-admin connections verify durable results.

For tested statement forms, concurrency/role assumptions, and untested paths,
use the [support, compatibility, performance, and security matrix](docs/support-matrix.md),
the [canonical test plan](docs/test-plan.md), and the
[native experiment evidence](experiments/native_tx_state/README.md). Research
evidence is not a supported installation or policy interface.

## What it does not protect

- **Transaction-local authority only.** Task-wide/cross-transaction authority
  is **not implemented**. Each new top-level transaction gets a new budget;
  **transaction splitting is not protected**, nor are retries across committed
  transactions bounded as one logical job.
- This does not cover all PostgreSQL writes or arbitrary SQL. Other versions,
  privileges, trigger graphs, cascades, partitions, stored procedures, INSERT /
  DELETE paths, real poolers, external side effects, and unprotected relations
  have no general protection claim. Superusers and protected-object owners are
  outside the protected-writer trust model. See the [support matrix](docs/support-matrix.md)
  and [threat model](docs/threat-model.md).
- **Actual managed PostgreSQL deployment: NOT TESTED.** Documentary feasibility
  work is not managed-service support; the unchanged native mechanism is blocked
  via standard Amazon RDS customer interfaces. See
  [managed feasibility](docs/managed-postgres-feasibility.md) and
  [#10](https://github.com/MrDarkRoot/CommitCap/issues/10).
- **Performance: INCONCLUSIVE.** Issue [#15](https://github.com/MrDarkRoot/CommitCap/issues/15)
  / [PR #22](https://github.com/MrDarkRoot/CommitCap/pull/22) did not establish a
  stable overhead estimate or PASS threshold. See the
  [performance matrix](docs/support-matrix.md#d-performance).

## Why this primitive, and where to dig deeper

Database privileges answer *what a credential may modify*. A mutation budget
asks *how much of a declared relational effect one transaction may make
durable*. This is a potential backstop for flexible repair, backfill, operator,
or background-worker writes when credentials can modify more than one intended
operation should. AI-driven automation is only one possible writer, not the
product definition. When a stable, narrow API or stored procedure expresses the
workflow cleanly, prefer it.

CommitCap is not a SQL linter, IAM/RLS replacement, generic database safety
system, or workflow engine. The [specification](docs/spec.md) defines intended
semantics; the [product thesis](docs/product.md) and [roadmap](docs/roadmap.md)
separate later hypotheses from current research. The first SQL/PLpgSQL
experiment **failed** irreversible denial after savepoint/exception recovery;
the later native transaction-state experiment passed the tested local cases.
See [research history and limitations](docs/limitations.md) and
[architecture decisions](docs/decisions.md). Historical `CC-*` labels there
are legacy experiment IDs, not equivalent to the
[canonical current test IDs](docs/test-plan.md).

## Bring a workflow or report a problem

If you have a real repair/backfill/worker workflow, describe what one run was
meant to change, what the writer could change, and which simpler guardrails you
already have. If installation fails, include the exact PostgreSQL version,
deployment type, attempted commit, documented step, and sanitized error in an
[issue](https://github.com/MrDarkRoot/CommitCap/issues/new/choose) when intake
is available. For suspected security bypasses, **do not publish sensitive
details** in an issue: follow [SECURITY.md](SECURITY.md) for private reporting
or the non-sensitive fallback. Remove secrets and production data from examples.
