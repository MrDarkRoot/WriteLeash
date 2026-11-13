# Issue #15 — PostgreSQL 16.4 local benchmark evidence (unstable host)

**Published, measured implementation:** `12375d38dd8f302593c2fcba344887fd4f58e3ee`
(base `fce8eb4634da4151058000153acc8be903b9d3b8`). All changes are in
`benchmarks/phase0/`. This is a PostgreSQL 16.4 **research-fixture** result,
not a supported or managed-service throughput claim. The two complete runs
exhibit severe, unexplained host-rate shifts; **no stable overhead estimate or
PASS decision can be made from them**.

Executed sequentially on 2026-09-23 UTC from the exact clean, published
implementation checkout (run 1, full original security suite, attempted repeat,
isolated denial diagnostic, run 2):

```sh
COMPOSE_PROJECT_NAME=commitcap_bench python3 benchmarks/phase0/run.py --output /tmp/opencode/phase0-run-12375d3
COMPOSE_PROJECT_NAME=commitcap_bench COMMITCAP_LOG=/tmp/opencode/phase0-suite-12375d3.txt ./experiments/native_tx_state/run.sh
COMPOSE_PROJECT_NAME=commitcap_bench python3 benchmarks/phase0/run.py --output /tmp/opencode/phase0-repeat-12375d3
python3 /tmp/opencode/phase0-denial-diagnostic.py
COMPOSE_PROJECT_NAME=commitcap_bench python3 benchmarks/phase0/run.py --output /tmp/opencode/phase0-repeat2-12375d3
```

Runs 1 and 2, and the **full existing security suite**, exited 0. The suite's
[complete transcript](security-suite-12375d3.txt) prints the exact tested SHA,
PG16.4, and `Native suite exit status: 0` (no suite skips), including its
fresh-admin durable-state, independent-policy, savepoint, and contention
assertions. Both completed benchmark runs verified expected protected/baseline
durable state, SQLSTATE `54000` and poisoned COMMIT failure for all 30 trials
in each of the three denied classes, and fresh-admin baselines after denial.
No unauthorized durable write was observed.

**Incomplete attempted repeat:** its accepted cells finished, but the runner
stopped at denied-transition trial 22 because the expected error message was
not present. The runner did not write raw/summary/metadata for that attempt;
there is no per-trial transcript to identify the cause, and it is **not**
counted as a passed run. A subsequent isolated 100-trial transition-denial
diagnostic at the same SHA observed 100/100 expected errors and poisoned
COMMIT rejections; its [psql output](denial-diagnostic.txt) is archived. A
fresh trusted-admin connection then reported **zero** nonmember users,
nonbaseline subscriptions and nonzero refunds. This does not retrospectively
establish the missing message's cause or validate the failed attempt.

## Raw data, setup, and comparison

| Run | Raw 7,290 observations | Full statistics | Metadata |
| --- | --- | --- | --- |
| 1 | [raw-run1.csv](raw-run1.csv) | [summary-run1.json](summary-run1.json) | [metadata-run1.json](metadata-run1.json) |
| 2 | [raw-run2.csv](raw-run2.csv) | [summary-run2.json](summary-run2.json) | [metadata-run2.json](metadata-run2.json) |

Each complete run has six accepted cases × two arms × three rounds × 200
measured transactions = **7,200 accepted transactions**, plus 30 denied trials
each for row-count, state-transition and numeric-delta (**90 denied**). Every
accepted arm/round starts with trusted-admin TRUNCATE/reseed/ANALYZE, then 50
warmup transactions (seed 20260922), then 200 measured transactions (seed
20260923). One persistent writer, one pgbench job, prepared SQL, READ COMMITTED,
10,000 synthetic rows per table. The admin's test-only subscriptions/users
budget is 100 for accepted 100-row UPDATEs; refunds numeric budget is 100.00.
The same restricted, non-owner writer, schema, primary-key btree indexes,
single PG16.4 container and preloaded native module serve both arms. The
protected path builds the **unchanged existing C**, uses the existing BEFORE
UPDATE trigger graph and server-side per-row LOG instrumentation for row-count
and allowed role updates; the baseline has no triggers. This end-to-end
trigger/logging/enforcement difference **cannot** be isolated to the C
accounting function. Constant status/role assignments may be no-ops, yet are
counted as row UPDATE effects; exact repeated-event totals come from validated
`UPDATE N` probe shapes and completed transaction counts, while fresh-admin
oracles check durable state and exact cumulative refund amounts.

Image `postgres:16.4-alpine@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`
is pinned in both build stages. Host: Ryzen 5 5500U, 12 logical CPUs, 15.7 GB
RAM, Docker Engine 29.5.2. Container had no configured CPU/memory quota;
cgroup `cpu.max=max 100000`, `memory.max=max`, cpuset `0-11`. Per-run host
memory and timestamps are in metadata. Runs were not overlapped with another
Docker workload. Background host contention, I/O, thermal behavior, page
caches, autovacuum and scheduling were not controlled or root-caused.

## Observed measurements — **do not use as a precise overhead estimate**

All latencies below are **pooled p50/p95/p99 µs** over 600 measured
transactions per arm/case. TPS is mean over three measured pgbench rounds;
rows/sec = TPS × row effects/transaction. Full per-round p50/p95/p99, TPS,
rows/sec, paired changes and TPS sample standard deviation are stored in each
summary; raw per-transaction latencies are in the CSV files. Values below are
observations, not accepted baseline/protected performance claims.

| Run / case (effects/tx) | Baseline latency µs | Protected latency µs | Baseline → protected TPS | Baseline → protected rows/s |
| --- | ---: | ---: | ---: | ---: |
| 1 / subscriptions 1 | 891 / 33077 / 48845 | 867 / 32380 / 34645 | 608 → 636 | 608 → 636 |
| 1 / subscriptions 5 | 925 / 33181 / 34592 | 913 / 31737 / 32541 | 560 → 596 | 2799 → 2982 |
| 1 / subscriptions 100 | 1177 / 31046 / 32418 | 1705 / 31801 / 56320 | 439 → 332 | 43876 → 33207 |
| 1 / users 1 | 846 / 32248 / 35166 | 781 / 8287 / 8532 | 677 → 971 | 677 → 971 |
| 1 / refunds 1 | 679 / 7928 / 8509 | 661 / 7912 / 8457 | 1080 → 1089 | 1080 → 1089 |
| 1 / mixed 3+2+2 | 939 / 11568 / 16607 | 1198 / 7804 / 9263 | 704 → 531 | 4928 → 3720 |
| 2 / subscriptions 1 | 721 / 7609 / 8307 | 796 / 8241 / 8475 | 910 → 945 | 910 → 945 |
| 2 / subscriptions 5 | 746 / 7991 / 8292 | 843 / 7978 / 8192 | 1069 → 908 | 5345 → 4539 |
| 2 / subscriptions 100 | 991 / 7828 / 8323 | 1593 / 8489 / 8692 | 738 → 513 | 73796 → 51280 |
| 2 / users 1 | 662 / 34138 / 38189 | 823 / 36945 / 54038 | 1037 → 841 | 1037 → 841 |
| 2 / refunds 1 | 756 / 35779 / 40743 | 848 / 36512 / 41661 | 940 → 826 | 940 → 826 |
| 2 / mixed 3+2+2 | 1021 / 1463 / 1905 | 1164 / 2081 / 2928 | 944 → 797 | 6609 → 5577 |

Example **within-run instability**: run 1's baseline 1-row TPS by round was
1346, 379, 101; protected 1-row was 1422, 374, 111. The 100-row paired TPS
changes were **−37%, +66%, −49%** in run 1 and **−7%, −26%, −41%** in run 2.
The 100-row protected p50 exceeded baseline p50 in both runs, but the changes
cannot be interpreted as a reliable general overhead figure in this environment.
No PASS threshold is set; repeat in an isolated, controlled host after
investigating the extreme baseline rate collapse, rather than tuning security
assertions or extrapolating these numbers to production.

Denied costs are **separate, protected-only** psql `\timing` client-observed
command durations (including round trips), p50/p95/p99 µs. They are neither
accepted pgbench transaction latencies nor comparable to an unprotected
denial. UPDATE and poisoned COMMIT are reported separately:

| Run / denied class (30 trials) | Failed UPDATE µs | Rejected COMMIT µs |
| --- | ---: | ---: |
| 1 / row 101 | 1285 / 1705 / 2602 | 108 / 138 / 162 |
| 1 / `* -> admin` | 154 / 532 / 1533 | 73 / 137 / 195 |
| 1 / refunds +101.00 | 205 / 443 / 3970 | 88 / 206 / 1061 |
| 2 / row 101 | 1397 / 1580 / 2819 | 119 / 151 / 162 |
| 2 / `* -> admin` | 229 / 339 / 1434 | 94 / 139 / 143 |
| 2 / refunds +101.00 | 272 / 795 / 1867 | 106 / 171 / 220 |

Archival formatting only: generated CSV CRLF separators were normalized to LF;
trailing spaces on Docker Compose progress lines were trimmed in the security
suite transcript. Measured numbers and suite assertion output were not edited.
The scripts, reset rules, privilege checks and reproduction instructions are
in [../README.md](../README.md) and [../run.py](../run.py).
