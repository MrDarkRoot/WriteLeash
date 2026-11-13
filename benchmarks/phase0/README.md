# Phase 0 enforcement-overhead experiment (#15)

Local **PostgreSQL 16.4 research fixture**, not a product or managed-service throughput claim.
After the shared native security suite has finished, run **two independent
invocations in sequence** from a clean checkout of the published implementation
commit. Requires Docker Compose v2 and Python 3 (stdlib only):

```sh
python3 benchmarks/phase0/run.py --run-id 1 --plan-only
python3 benchmarks/phase0/run.py --run-id 2 --plan-only
COMPOSE_PROJECT_NAME=commitcap_bench python3 benchmarks/phase0/run.py --run-id 1 --output /tmp/phase0-run-1
COMPOSE_PROJECT_NAME=commitcap_bench python3 benchmarks/phase0/run.py --run-id 2 --output /tmp/phase0-run-2
python3 benchmarks/phase0/compare.py --run1 /tmp/phase0-run-1 --run2 /tmp/phase0-run-2 --output /tmp/phase0-comparison.json
```

`--plan-only` prints the deterministic full order and seeds without touching
Docker, the database or the host. Review both plans and the **predeclared
measurement warning criteria below before** collecting results. Do not run
either run alongside other Docker benchmarks/suites. Keep both raw runs even
if unstable; do not drop slow pairs. A failed run stays incomplete and is not
silently retried or treated as a passed run.
The offline comparison rejects incomplete runs or mismatched implementation
SHAs/image digests; it never invokes Docker. Before the suite finishes, the
safe preparatory check is:

```sh
python3 -m unittest discover -s benchmarks/phase0 -p test_methodology.py
```

The two `--plan-only` commands above are safe as well.

The runner refuses a different Compose project, a dirty checkout, or a
non-16.4 server. It builds the **unchanged** `experiments/native_tx_state/`
source against the image digest in `Dockerfile`, reuses its `setup.sql` verbatim,
and destroys only its own disposable Compose project/volume on exit. Output
contains `raw.csv`, `summary.json`, `metadata.json`, `telemetry.jsonl`, and
`denials/<case>/trial-###.json`; archive them under new evidence paths
only after checking that the output records that exact published implementation
SHA and reviewing telemetry/logs for unrelated host details. On an incomplete
run, `raw.csv` (completed cells), `metadata.json` (recorded plan, interrupted
status), `failure.json` and per-trial denial records survive Docker teardown.
Do not overwrite the historical files under `evidence/`. All SQL, seed
derivation and scheduling are in `run.py`.

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

Accepted: `pgbench` one persistent writer connection **per invocation**, prepared
query mode, one client/job. Each run has three rounds per case; each round has
**two 100-transaction matched A/B pairs**, with **25 warmup transactions per
arm per pair**. This retains 50 warmup + 200 measured per arm/round. In each
round/case block the pairs are AB then BA, or BA then AB, chosen by the
predeclared SHA-256 seed of (run, round, case) and recorded in `metadata.json`.
Both arms in a pair use identical measured/warmup seeds and the same reset and
ANALYZE; pairs get distinct deterministic seeds. `-l` writes measured
per-transaction pgbench logs. Transactions contain
one UPDATE of 1/5/100 subscriptions rows, a separate one-row users transition,
a separate one-row refunds +0.01, or three subscriptions, two users
(`member`→`moderator` initially), and two refunds (+0.01 each) in one mixed
transaction. IDs are chosen in the valid 1..10000 range with seeded pgbench
`random()` (separate measured and warmup seeds), and all writes are inside
BEGIN/COMMIT. Each arm/pair starts with trusted-admin TRUNCATE/reseed/ANALYZE;
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
rows. Percentiles use sorted nearest-rank observations. Per-round TPS is the
200 measured transactions divided by the sum of the two pairs' elapsed times;
repeat variability is sample SD of round TPS, and all six paired TPS comparisons
remain in the summary. Setup and telemetry collection run outside pgbench's
measured interval. The pair's baseline and protected runs are sequential,
not simultaneous; AB/BA balances order but cannot remove background drift.

Denied: separate protected-only `psql` connection **per trial** with `\timing`,
30 trials per denial class. Connection setup is excluded from the two command
timings; the original security suite owns backend-reuse coverage. Each trial
begins, saves a point, attempts 101 row
effects / forbidden admin transition / +101.00 refund delta, checks SQLSTATE,
rolls back to the savepoint and attempts COMMIT; an error at COMMIT verifies
sticky denial. Client-observed psql timings of the denied UPDATE and poisoned
COMMIT are recorded separately, never combined with accepted TPS. **Each trial
is captured and checked before the next starts**: the JSON record contains
exact SQL/psql input, case, trial, UTC start/end, complete combined stdout and
stderr, both observed SQLSTATE markers, expected message/timings, a server-log
excerpt since trial start (or explicit unavailability), and fresh trusted-admin
durable state for protected tables and sibling audit. On the **first anomaly**
the record is written before teardown and the run stops; there is no automatic
retry. If the fresh admin sees any durable over-authority mutation, stop the
workstream and report the artifact as a security regression before doing any
more benchmark work. There is no unprotected denial equivalent or relative
denied-overhead figure.

The read-only `telemetry.jsonl` records UTC before/after each measured arm and
round, host load/CPU counters, governors/frequencies, thermal/power sensors
when readable, memory/swap, PSI CPU/memory/IO pressure, disk counters, other
running Docker-container status, benchmark container stats/cgroups and PG16.4
settings and checkpoint/WAL/autovacuum counters. Missing sensors are explicitly
`unavailable`; no privilege, tuning, host or server settings are changed.

**Predeclared reproducibility warnings (not product PASS thresholds):** warn
for a case when its six baseline pair TPS values differ by more than 20%
(`max/min - 1 > 0.20`), when its three baseline round TPS values have sample
coefficient of variation `> 0.10`, or when paired protected-vs-baseline TPS
changes reverse sign with a range greater than 20 percentage points. Across
the two completed runs, the offline comparison warns if baseline mean TPS
changes by more than 20% (`max/min - 1 > 0.20`), or the paired mean-TPS change
reverses sign with a span above 20 percentage points. Report
all raw observations, all warning reasons, both independent runs, and any
missing telemetry; a warning means no precise attribution until investigated.
Even a run without these warnings is **not** a release performance PASS.

Repeated runs are sensitive to CPU sharing, log output, page-cache state and
Docker/host scheduling. Report the host/container metadata and all rounds;
no PASS threshold or broad security/performance claim follows from a local run.
