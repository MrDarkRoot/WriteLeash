# Phase 0 local enforcement-overhead evidence — issue #15

Measured **published implementation** `00f0b29696c8180e14748370cd90b745e682c8c7`
on 2026-09-23 UTC. Base `fce8eb4634da4151058000153acc8be903b9d3b8`.
Commands (from the clean implementation checkout, sequentially):

```sh
COMPOSE_PROJECT_NAME=commitcap_bench python3 benchmarks/phase0/run.py --output /tmp/opencode/phase0-run-00f0b29
COMPOSE_PROJECT_NAME=commitcap_bench COMMITCAP_LOG=/tmp/opencode/phase0-suite-00f0b29.txt ./experiments/native_tx_state/run.sh
```

Both exited **0**. The original full security suite ran **after** measurement
at the same implementation SHA. Its [complete transcript](security-suite-00f0b29.txt)
prints that SHA, PG16.4, and `Native suite exit status: 0` (all integrated
tests, no harness skips). It checks sticky denial, fresh-connection durable
oracles, numeric and transition cases, nested rollback and two-session
contention. No unauthorized durable write was observed. Archival formatting
only: the generated CSV's CRLF record separators were normalized to LF;
trailing spaces on Docker Compose progress lines were trimmed in the suite
transcript. Numerical data and suite test output were not otherwise edited.

The [raw per-transaction observations](raw.csv), [complete run/round and
percentile statistics](summary.json), and [host/container metadata](metadata.json)
are archived here. Six accepted workloads × two arms × three rounds × 200
measured transactions = **7,200 accepted** observations; three denied classes
× 30 trials = **90 denied** observations. Each accepted cell had **50 warmup**
transactions, outside the reported distribution; seed 20260923, single
persistent client/backend, 1 job, prepared SQL, READ COMMITTED. Every arm/round
started with trusted-admin TRUNCATE/reseed/ANALYZE. Tested upstream image:
`postgres:16.4-alpine@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`;
both container stages pinned. Container had no configured CPU/memory quota
(cgroup `cpu.max=max 100000`, `memory.max=max`, cpuset 0–11); host AMD Ryzen 5
5500U, 12 logical CPUs, 15.7 GB RAM, Docker 29.5.2.

Accepted results below: latency **p50 / p95 / p99 in microseconds**, pooled
over 600 measured transactions per arm/case; TPS is the arithmetic mean of
three pgbench measured-run TPS values ± **sample standard deviation**. Rows/s
is TPS × row UPDATE effects per transaction, including repeated/no-op UPDATEs.
Per-round p50/p95/p99, TPS, rows/s and paired overhead are in `summary.json`.

| Workload (effects/tx) | Baseline p50/p95/p99 µs | Protected p50/p95/p99 µs | Baseline TPS ± SD | Protected TPS ± SD | Baseline → protected rows/s | Protected p50 change; TPS change |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| subscriptions 1 | 634 / 946 / 1109 | 675 / 979 / 1097 | 1432 ± 37 | 1385 ± 28 | 1432 → 1385 | +6.5%; −3.3% |
| subscriptions 5 | 645 / 938 / 1003 | 739 / 1296 / 2114 | 1436 ± 9 | 1183 ± 88 | 7180 → 5916 | +14.6%; −17.6% |
| subscriptions 100 | 913 / 1284 / 1561 | 1513 / 1913 / 2283 | 998 ± 42 | 647 ± 5 | 99761 → 64739 | +65.7%; −35.1% |
| users 1 (allowed role) | 619 / 995 / 1240 | 688 / 999 / 1259 | 1483 ± 83 | 1343 ± 13 | 1483 → 1343 | +11.1%; −9.4% |
| refunds 1 (+0.01) | 635 / 963 / 1200 | 674 / 992 / 1150 | 1425 ± 45 | 1367 ± 26 | 1425 → 1367 | +6.1%; −4.0% |
| mixed 3+2+2 | 872 / 1183 / 1350 | 1010 / 1449 / 2137 | 1084 ± 86 | 897 ± 74 | 7585 → 6282 | +15.8%; −17.2% |

**Denied, protected-only:** each trial attempted an UPDATE under a savepoint,
recovered statement execution, and failed the final top-level COMMIT with
SQLSTATE `54000`; independent admin sessions found zero unauthorized durable
changes. Below are **psql `\timing` client-observed command elapsed times**
(including round trips), p50/p95/p99 in µs of the *failing UPDATE* and
separately the *poisoned COMMIT*, each from 30 trials. They are neither
accepted pgbench transaction latencies nor an unprotected comparison.

| Violation | Denied UPDATE µs | Rejected COMMIT µs |
| --- | ---: | ---: |
| subscriptions row 101 with trusted budget 100 | 1112 / 2204 / 3441 | 89 / 210 / 370 |
| users `* -> admin` | 223 / 295 / 1700 | 84 / 103 / 143 |
| refunds +101.00 with numeric budget 100.00 | 246 / 427 / 1924 | 102 / 133 / 134 |

## Interpretation and limitations

The 100-row row-budget result is the largest observed relative cost in this
small single-client run: paired-round TPS changes were **−36.6%, −32.3%,
−36.3%**. Investigate trigger/logging and host scheduling in a separately
scoped effort before considering performance tuning. One-row subscriptions
paired changes were **−6.2%, −4.4%, +1.0%**, showing that small differences
are sensitive to run variability. Mixed paired changes were **−7.5%, −10.8%,
−31.3%**, also unstable. No PASS threshold was selected.

Comparison controls: same database engine/image/container, three table
shapes, btree primary keys, 10,000 rows each, non-owner restricted writer,
column-level grants, transaction boundaries, random seed, and client mode.
`experiments/native_tx_state/setup.sql` and the **unmodified existing C code**
create the protected triggers. `fixture.sql` copies column/index schema and
grants onto untriggered baseline tables. Both arms run with the module
preloaded. The protected arm necessarily has BEFORE ROW triggers, fixed-rule
checks, callback activity and **per-row server LOG lines** for subscription/
users allowed events; baseline lacks triggers. There is no dummy trigger, so
the observed delta is end-to-end fixture overhead, **not** isolated native
accounting cost. Baseline table names differ and their physical placement may
differ. Warm caches, autovacuum, filesystem buffering and CPU scheduling can
affect these short runs. This is local PostgreSQL 16.4 research only; not a
managed-PostgreSQL or multi-client throughput claim.

Trusted admin configured `ALTER ROLE commitcap_writer SET
commitcap_native.test_budget=100` for 100-row accepted writes (instead of
the harness's default five); same 100 applies to users in this test. Refunds
retains the native default `100.00`. Writer cannot alter either. The synthetic
workload performs constant assignments for status and role, so some later
changes are no-ops **but still count as protected row-update events**. The
initial allowed users transition is `member -> moderator`; the research
trigger denies only `* -> admin`. Refunds positive deltas accumulate per
transaction; repeated-row updates use actual old amount. Completed pgbench
transaction counts and validated UPDATE N shapes determine rows/s; fresh admin
confirms durable state and exact total numeric increase, but constant status/
role assignments cannot serve as exact durable *cumulative* row-event counters.
Neither cell ordering nor client parallelism models real services. Detailed
procedures and scope are in [../README.md](../README.md).
