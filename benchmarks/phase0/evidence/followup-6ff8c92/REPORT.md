# Phase 0 Issue #15 follow-up: balanced-pair PG16.4 measurements

**Classification: STILL UNSTABLE / INCONCLUSIVE; no performance-gate PASS.**
Measured public implementation SHA: `6ff8c929dea52d4671067f05765855f67c377075`
(2026-09-24 UTC), on PR #22. Historical [raw runs, summaries and limitation
report](../REPORT.md) remain byte-for-byte unchanged. This report concerns only
the exact locally installed PostgreSQL 16.4 research fixture, not a supported
product, managed PostgreSQL, or multi-client workload.

## Predeclared protocol and outcomes

Two independent runs (`--run-id 1`, `--run-id 2`) completed **sequentially**
at one clean published implementation checkout using the pinned
`postgres:16.4-alpine@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`.
Command sequence:

```sh
python3 -m unittest discover -s benchmarks/phase0 -p test_methodology.py -v
python3 benchmarks/phase0/run.py --run-id 1 --plan-only
python3 benchmarks/phase0/run.py --run-id 2 --plan-only
COMPOSE_PROJECT_NAME=commitcap_bench python3 benchmarks/phase0/run.py --run-id 1 --output /tmp/opencode/phase0-followup-run1-6ff8c92
COMPOSE_PROJECT_NAME=commitcap_bench python3 benchmarks/phase0/run.py --run-id 2 --output /tmp/opencode/phase0-followup-run2-6ff8c92
python3 benchmarks/phase0/compare.py --run1 /tmp/opencode/phase0-followup-run1-6ff8c92 --run2 /tmp/opencode/phase0-followup-run2-6ff8c92 --output /tmp/opencode/phase0-followup-comparison-6ff8c92.json
COMPOSE_PROJECT_NAME=commitcap_bench_suite COMMITCAP_LOG=/tmp/opencode/phase0-suite-6ff8c92.txt ./experiments/native_tx_state/run.sh
```

All six commands exited 0. The six non-Docker methodology unit tests passed;
the two `--plan-only` schedules were checked before measurement. The runner
persisted the exact SHA, seeds and deterministic arm order in each metadata
file. **Each run:** three rounds per case, two sequential balanced AB/BA or
BA/AB pairs per round, 25 warmup and 100 measured transactions per arm/pair,
one client/job with prepared statements, READ COMMITTED. Every pair/arm has
trusted-admin TRUNCATE/reseed/ANALYZE of the matching 10,000-row schema.
Measured seeds match between arms within a pair; warmup has a separate seed.
Six cases: subscriptions UPDATE of 1/5/100 rows, users allowed role transition,
refunds +0.01, and mixed subscriptions/users/refunds 3+2+2. The restricted
writer's table/column grants, primary-key indexes, exact native C and trigger
graph are preserved. Trusted test-only row budget 100 permits the 100-row
UPDATE; numeric default 100.00 is unchanged. The untriggered baseline tables
have the same schema/indexes/grants; there is no dummy trigger. The observed
difference includes trigger dispatch, native callback work **and the research
fixture's server LOG instrumentation**; it cannot isolate C accounting cost.

Both runs produced **7,200 accepted observations and 90 denied trials each**:
14,400 accepted and 180 denied in total. Each denied trial saved exact SQL,
both SQLSTATE `54000` markers, combined psql output, server-log excerpt, and
fresh trusted-admin durable state **before teardown**. All 180 expected error
and poisoned-COMMIT checks matched, including transition trials 22 in both
runs. After each denial, all three protected tables remained at their 10,000-
row baselines, with zero changed rows and zero sibling audit rows. **The prior
historical trial-22 missing-message event remains unexplained**; the new
successes do not retroactively validate it. Denied command latencies are
separate from accepted pgbench transactions and have no unprotected analogue.
The original, unchanged complete native security suite ran **after** both
benchmarks at that same SHA, printed PostgreSQL 16.4 and the image digest, and
exited `0` with `Native suite exit status: 0` (no harness skips). Its full
transcript is archived.

## Measurements and uncertainty

The table shows **observed** arithmetic mean TPS over three rounds and pooled
p50 latency in µs; rows/s = TPS × UPDATE-row effects per transaction. Full
p50/p95/p99, rows/s, sample SD across rounds, six paired TPS changes and
separately denied p50/p95/p99 are in each unabridged `summary.json`; every
transaction latency is in `raw.csv.gz`. No cell or outlier was removed.

| Run / accepted case | Baseline → protected TPS | Baseline → protected p50 µs | Baseline → protected rows/s |
| --- | ---: | ---: | ---: |
| 1 / subscriptions 1 | 1216 → 1059 | 731 → 762 | 1216 → 1059 |
| 1 / subscriptions 5 | 1122 → 1043 | 760 → 831 | 5609 → 5217 |
| 1 / subscriptions 100 | 818 → 535 | 1071 → 1723 | 81759 → 53477 |
| 1 / users 1 | 1163 → 1046 | 742 → 818 | 1163 → 1046 |
| 1 / refunds 1 | 1146 → 1133 | 761 → 769 | 1146 → 1133 |
| 1 / mixed 3+2+2 | 793 → 757 | 1125 → 1189 | 5552 → 5298 |
| 2 / subscriptions 1 | 833 → 834 | 795 → 863 | 833 → 834 |
| 2 / subscriptions 5 | 868 → 828 | 847 → 893 | 4342 → 4142 |
| 2 / subscriptions 100 | 562 → 390 | 1116 → 1821 | 56233 → 39048 |
| 2 / users 1 | 795 → 740 | 803 → 882 | 795 → 740 |
| 2 / refunds 1 | 734 → 749 | 867 → 868 | 734 → 749 |
| 2 / mixed 3+2+2 | 854 → 753 | 1040 → 1167 | 5979 → 5269 |

The **predeclared reproducibility warnings triggered** (`comparison.json`):
baseline pair TPS max/min spread >20% in eleven of twelve run/case cells; the
baseline mean TPS differed by >20% between runs in five of six cases. Run 2
round 1, 100-row pair 2 measured baseline **26.86 TPS** vs protected **89.29
TPS**, reversing the apparent overhead despite the balanced AB/BA order.
Run 1's 100-row paired-round changes were −24.9%, −35.2%, −41.9%; run 2's
were +84.5%, −33.8%, −34.3%. These values **do not establish** a reliable
enforcement-overhead estimate. The fixed warnings are about reproducibility,
not a security or product performance PASS threshold; even if none triggered,
no supported throughput claim would follow.

## Recorded host / server context

Each run saved **152 before/after arm and round snapshots** in
`telemetry.jsonl.gz`: UTC, host load and aggregate CPU counters, per-CPU
governor/current frequency, available memory and swap, `/proc/pressure` CPU,
IO and memory, disk counters, container presence, PostgreSQL-container stats
and cgroup CPU/memory/IO counters, PG16.4 settings, bgwriter/checkpoint,
database/WAL and per-relation autovacuum/autoanalyze counters. Container CPU
and memory quotas were unset; cgroup `nr_throttled` remained **0** and no
other running Docker container was observed in either run. Host 1-minute
load ranged **2.86–6.14** (run 1) and **4.02–6.24** (run 2); observed CPU
governor was `performance`, with frequency snapshots around 1.96–4.06 GHz.
Thermal sensors and powercap energy readings were **unavailable**, not assumed
normal. Per-table autovacuum counts rose from 0 to 30 (run 1) and 0 to 36
(run 2); timed checkpoint counts rose from 0 to 1 in each run. During the
anomalous run-2 100-row baseline pair, IO pressure `some avg10` increased
from **2.39** to **13.97** (and `full avg10` from **1.60** to **11.48**), with
no autovacuum-count increment or checkpoint-count change in that pair. This
is a temporal observation, **not proof of why the baseline stalled**. Host
I/O or scheduling root cause remains unknown. No host/server settings were
changed to obtain these figures.

## Archive and verification

The [manifest](manifest.json) records SHA-256 and size for all 12 data files.
Files are compressed **without editing their payloads**, including CSV CRLF
record separators and the full security-suite transcript. No credentials,
personal data or external user records occur in the synthetic trial payloads;
other-container names are masked by the telemetry collector. Each run stores
`metadata.json`, `summary.json`, `raw.csv.gz`, `telemetry.jsonl.gz` and
`denials.tar.gz` containing exactly 90 per-trial JSON files. Offline cross-run
warnings are in [comparison.json](comparison.json). To inspect without
changing the archived original:

```sh
gzip -dc benchmarks/phase0/evidence/followup-6ff8c92/run1/raw.csv.gz > /tmp/phase0-run1-raw.csv
gzip -dc benchmarks/phase0/evidence/followup-6ff8c92/run1/telemetry.jsonl.gz > /tmp/phase0-run1-telemetry.jsonl
mkdir -p /tmp/phase0-run1-denials
tar -xzf benchmarks/phase0/evidence/followup-6ff8c92/run1/denials.tar.gz -C /tmp/phase0-run1-denials
gzip -dc benchmarks/phase0/evidence/followup-6ff8c92/security-suite.txt.gz > /tmp/phase0-native-suite.txt
```

Repeat with `run2/` to inspect the second run. Keep issue #15 open and PR #22
draft; investigate host instability before proposing any benchmark conclusion
or optimization. No shared enforcement code, security tests, policy scope,
managed deployment claim, or historical evidence was modified.
