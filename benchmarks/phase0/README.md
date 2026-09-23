# Phase 0 enforcement-overhead experiment (#15)

Local **PostgreSQL 16.4 research fixture**, not a product or managed-service throughput claim.
Run from a clean checkout at the implementation commit, with Docker Compose v2
and Python 3 (stdlib only):

```sh
COMPOSE_PROJECT_NAME=commitcap_bench python3 benchmarks/phase0/run.py --output /tmp/phase0-run
```

The runner refuses a different Compose project, a dirty checkout, or a
non-16.4 server. It builds the **unchanged** `experiments/native_tx_state/`
source against the image digest in `Dockerfile`, reuses its `setup.sql` verbatim,
and destroys only its own disposable Compose project/volume on exit. Do not
run concurrent Docker benchmarks on the same host. Output contains `raw.csv`,
`summary.json`, and `metadata.json`; archive them under `evidence/`
only after checking that the output records that exact published implementation
SHA. All SQL and random seeds are in `run.py`.

Each run compares separately reset protected and ordinary tables. Both have
10,000 synthetic rows, same columns and primary-key btree indexes, the same
restricted writer and `READ COMMITTED`, on one container with the extension
preloaded. Only the protected tables have the **existing** BEFORE UPDATE C
triggers. Its row-event callback logs every allowed subscriptions/users event;
logging, extra transition checks, callbacks, and numeric validation are part of
this measured research setup. The ordinary tables have no equivalent dummy
trigger; this is an end-to-end trigger-and-enforcement comparison, **not** an
isolated C-function or intrinsic overhead estimate. Configuration is a trusted
test-only row budget of 100 for both row policies (instead of fixture default
5); refunds retains its default numeric budget of 100.00. Writer has no GUC
setting authority. No artificial delay, optimization or alternate security
mechanism is used.

Accepted: `pgbench` single persistent writer connection, prepared query mode,
one client/job, 50 warmup then 200 measured transactions per cell, three
rounds, alternating first arm, `-l` per-transaction logs. Transactions contain
one UPDATE of 1/5/100 subscriptions rows, a separate one-row users transition,
a separate one-row refunds +0.01, or three subscriptions, two users
(`member`→`moderator` initially), and two refunds (+0.01 each) in one mixed
transaction. IDs are chosen in the valid 1..10000 range with seeded pgbench
`random()` (measured seed 20260923 per arm/round; separate warmup seed 20260922),
and all writes are inside BEGIN/COMMIT.
Each cell starts with trusted-admin TRUNCATE/reseed and ANALYZE on both tables;
those operations and warmup are excluded from the measured interval. The
runner checks row counts, trigger graph, writer grants, durable changed
subscription/allowed users state and exact cumulative refunds sum, and
independent fresh-admin durable oracles for denied transactions. Repeated
subscription/role assignments may be no-ops; they still fire UPDATE row events.
Because the fixture's restricted writer cannot SELECT `status` or `role`,
exact *cumulative* subscription/users event totals come from the validated
fixed-range SQL shapes and successful pgbench transaction counts, not from a
durable length counter. Each 1/5/100-row SQL shape is first checked for its
actual `UPDATE N` command tag; the two-row users/refunds shapes are also probed.
Any mismatch aborts the run. Accepted latency is pgbench per-transaction
client elapsed microseconds (including SQL, round trips and COMMIT); TPS and
rows/sec use pgbench measured elapsed time and **effect counts**, not distinct
rows. Percentiles use sorted nearest-rank observations; repeated-run variability
is sample standard deviation of per-round TPS plus per-round values.

Denied: separate protected-only `psql` persistent connection with `\timing`,
30 trials per denial class. Each trial begins, saves a point, attempts 101 row
effects / forbidden admin transition / +101.00 refund delta, checks SQLSTATE,
rolls back to the savepoint and attempts COMMIT; an error at COMMIT verifies
sticky denial. Client-observed psql timings of the denied UPDATE and poisoned
COMMIT are recorded separately, never combined with accepted TPS. Fresh-admin
baseline and audit checks follow each batch. There is no unprotected denial
equivalent or relative denied-overhead figure.

Repeated runs are sensitive to CPU sharing, log output, page-cache state and
Docker/host scheduling. Report the host/container metadata and all rounds;
no PASS threshold or broad security/performance claim follows from a local run.
