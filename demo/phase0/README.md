# Phase 0: local mutation-budget demonstration (research only)

This is an **experimental per-top-level-transaction** PostgreSQL 16.4 native
fixture, not a release, a production support claim, or a managed-PostgreSQL
deployment. The three hard-coded test rules are independent: at most five
`subscriptions` UPDATE row events, at most five allowed `users` UPDATE row
events with `* -> admin` forbidden, and at most `100.00` cumulative positive
`refunds.amount` delta. A denied event poisons the whole top-level transaction.
The writer cannot change these test settings or own protected objects.

## Illustrative remediation, not an observed customer workflow

Imagine a restricted repair worker correcting stale subscription statuses,
assigning a permitted moderator role, and adjusting recorded refund amounts.
Its credential **could** UPDATE the granted columns on many rows across these
tables and INSERT an unprotected audit message; this particular task **should**
touch only a few selected rows and stay within a small positive-delta limit.
A SQL-level transaction budget is a backstop when repair SQL varies across tasks
and a fixed stored procedure is awkward. This synthetic example does not
establish that any real team needs such flexibility; a narrow API or stored
procedure may be simpler. `refunds.amount` is a relational number, not proof
of a transferred or settled payment.

## Quickstart

Prerequisites: a clean checkout with Docker Engine running and Docker Compose
v2 (`docker compose`), permission to run Docker, Bash, and network access to
pull the pinned PostgreSQL base image and build its native extension.
No local PostgreSQL installation or host port is needed. The existing
[`Dockerfile`](../../experiments/native_tx_state/Dockerfile) and
[`Compose fixture`](../../experiments/native_tx_state/docker-compose.yml) use
the `postgres:16.4-alpine` tag. Before Compose builds anything, the demo pulls
the exact tested manifest digest
`postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`,
tags that pulled image locally as `postgres:16.4-alpine`, and compares the
tag's Docker image ID to the pulled digest reference's image ID. A pull or ID
mismatch fails before `docker compose up`. The manifest digest and Docker image
ID are different identifiers; comparing their literal hex strings is not a
valid equality check. This preflight changes the **host-wide local tag** and
does not restore its previous target on teardown; avoid running concurrent
builds that rely on a different target for `postgres:16.4-alpine`.
From a fresh checkout of this branch (or a commit containing `demo/phase0/`):

```bash
git clone https://github.com/MrDarkRoot/CommitCap.git
cd CommitCap
git switch demo/local-phase0-research
./demo/phase0/run.sh
```

The script builds and starts an isolated Compose project
(`COMPOSE_PROJECT_NAME=commitcap_demo` by default), applies the **unchanged**
`experiments/native_tx_state/setup.sql`, then drives six real SQL transactions
as `commitcap_writer`. Each case resets *only disposable synthetic fixture
rows* through the trusted admin. Every durable assertion opens a **new**
trusted-admin connection after the writer connection exits; it compares every
row in all three protected tables and the exact sibling audit-row count.
Raw writer command tags, denial messages, SQLSTATEs, transaction-local policy
probe values, and the exact fresh-admin state are printed. Expected outcomes:

| Case | Writer result | Fresh-admin result |
| --- | --- | --- |
| Two subscription repairs + `member -> moderator` | `COMMIT`, `2|1|0.00|false` | Exactly those three row changes |
| Six one-row subscription statements (limit five), with savepoint recovery | Sixth `54000`, denied probe `5|1|20.00|true`, final `COMMIT` raises `54000` | All baseline, including sibling `users`, `refunds` and audit=0 |
| `member -> admin`, with savepoint recovery | Transition `54000`, denied probe `1|0|0.00|true`, final `COMMIT` raises `54000` | All baseline, including sibling subscription and audit=0 |
| Refunds +`30.00` and +`70.00` | `COMMIT`, `0|0|100.00|false` | Exact amounts `30.00`, `70.00`, six `0.00` |
| Refunds +`80.00` and attempted +`21.00` | Excess `54000`, denied probe `1|1|80.00|true`, final `COMMIT` raises `54000` | All baseline, including subscription, user and audit=0 |
| Five subscription rows, five user rows, +`100.00` refund | `COMMIT`, `5|5|100.00|false` | Exactly five/five/one changes, independent limits |

Expected final lines: `Demo: PASS ...` and `Demo exit status: 0`. A failed
assertion exits nonzero and prints `FAIL`; denial cases only pass when **both**
the immediate SQL error and the final top-level COMMIT rejection occur and a
fresh admin sees no durable siblings. This demo does not replace the full
[`native security suite`](../../experiments/native_tx_state/README.md) or its
separately recorded [integrated coverage evidence](../../docs/phase0-security-coverage-run.md).

The script tears down its isolated service and volume on exit (`docker compose
down -v`). It refuses to touch an existing project with that name. If another
local Compose project uses the default name, choose a free one:

```bash
COMPOSE_PROJECT_NAME=commitcap_demo_other ./demo/phase0/run.sh
```

If interrupted or Docker teardown fails, from the checkout root clean up
**only the project name you selected** (this deletes its disposable database):

```bash
COMPOSE_PROJECT_NAME=commitcap_demo docker compose -f experiments/native_tx_state/docker-compose.yml down -v
```

If `docker info` fails, start Docker / fix access to the daemon; if Compose is
missing, install its v2 plugin. If the digest pull fails, restore
registry/network access; if the ID comparison fails, inspect the local tag
before retrying. A running project-name collision
must be resolved by choosing an unused `COMPOSE_PROJECT_NAME` or explicitly
tearing down *your own* prior demo project before retrying.

## Evidence and boundary

The [current complete sanitized demo transcript](evidence/2026-09-23-pinned-demo.txt)
records the publicly reachable implementation commit, PostgreSQL version, pinned base
image digest and verified ID, SQL outcomes, all fresh-admin durable snapshots,
and exit code. The [previous transcript](evidence/2026-09-23-demo.txt) is
historical evidence for implementation `7a01f59698c70258b050fe78b3b8a49016d09382`;
that run printed the image digest but did not enforce the pin.
The demo script contains the exact SQL and assertions; it is not a simulated
output file or a policy-definition interface.

Budgets reset on each top-level transaction: splitting a task across committed
transactions is **not** prevented. Capability-wide authority, managed-PostgreSQL
compatibility, other PostgreSQL majors, real poolers, different privileges,
untested SQL/trigger topologies and external side effects are unproven or out
of scope. The native mechanism and hard-coded fixture are local research only;
see the [experiment boundaries](../../experiments/native_tx_state/README.md)
and [limitations](../../docs/limitations.md) before extrapolating.
