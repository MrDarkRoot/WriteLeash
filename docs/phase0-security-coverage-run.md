# Phase 0 audit runtime evidence (issue #11)

## Superseding verified run (current evidence)

Date: 2026-09-23 UTC. **Publicly accessible tested commit:** `e97f19b030db1d9f537e7d542c02279c0481922f` — the published integration merge commit on this branch (integrates `origin/main` `3d68fe6f6058baf82f3f84d5498f55d729095f23` / PR #12). GitHub resolves this SHA: https://github.com/MrDarkRoot/CommitCap/commit/e97f19b030db1d9f537e7d542c02279c0481922f (verified via the GitHub commits API on 2026-09-23).

Execution method: an **exact detached-HEAD worktree checkout** of `e97f19b030db1d9f537e7d542c02279c0481922f` with a **clean working tree** (`git status` empty, HEAD equal to the tested SHA). The commit contains the actual integrated test harness and native enforcement source; the executed files have these blob hashes at the tested commit:

```text
run.sh                                3f8792d51893c0769063a7f14ce7d4a79c6db6bd
numeric_cases.sh                      60cd78fc93cd6a26505e4c4597d428622d129565
commitcap_native_tx_state.c           c6f88b60805898e0cc03e22a8d98a291f18798f6
setup.sql                             c4a0c27659e42bc431c39ec2606fed27bce2cec4
commitcap_native_tx_state--0.0.1.sql  0a753b54a655f43e98b5670eb131cf787d1b28f9
```

Exact command from the checkout root: `./experiments/native_tx_state/run.sh`. Exit status **0**. Docker image built from `postgres:16.4-alpine` digest `sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`; server reports `PostgreSQL 16.4`. Disposable Compose service and volume removed by the `run.sh` exit trap; the trap also prints `Native suite exit status: 0`. No cloud resources used.

**Archived sanitized transcript (direct link):** [evidence/2026-09-23-integrated-audit-e97f19b.txt](../experiments/native_tx_state/evidence/2026-09-23-integrated-audit-e97f19b.txt). A provenance header was added by the evidence commit; the suite output below that header is **verbatim** and verified byte-identical (`cmp`) to the suite's own `COMMITCAP_LOG` recording. It prints `Tested commit: e97f19b030db1d9f537e7d542c02279c0481922f`.

Verbatim excerpt from the transcript:

```text
Command: ./experiments/native_tx_state/run.sh
Tested commit: e97f19b030db1d9f537e7d542c02279c0481922f
Base image: postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c
PostgreSQL: 16.4
```

Independent-policy acceptance and canonical numeric cases (all with fresh trusted-admin durable oracles):

```text
independence subscriptions→users→refunds: PASS (5|1|20.00, three fresh-admin durable oracles)
independence refunds→users→subscriptions: PASS (5|1|20.00, three fresh-admin durable oracles)
canonical CC-030: PASS (90.00 COMMIT, exact fresh-admin rows)
canonical CC-031: PASS (100.00 COMMIT, exact fresh-admin rows)
canonical CC-032 single: PASS (denied; fresh admin observed all eight baseline amounts)
canonical CC-032 decomposed: PASS (denied; fresh admin observed all eight baseline amounts)
canonical CC-033: PASS (+80/-80 consumed 80.00 in committed transaction; fresh admin amount 0.00)
numeric-delta concurrent row-lock contention: scenarios A, B and C PASS
  writer state:  CC_POLICY_VIOLATION_STATE:3|1|40.00|t
  writer final COMMIT: ERROR:  CommitCap top-level transaction denied after mutation authority violation
independent-policy individual violation: PASS (3|1|40.00 independently accounted; sticky denial after ROLLBACK TO; poisoned COMMIT rejected; fresh admin saw all protected baselines and sibling audit=0)
native transaction-state experiment: all required tests PASS including independent per-policy acceptance in both orders and individual-violation recovery (PG16.4 research fixture)
Native suite exit status: 0
```

The full integrated suite executed, including canonical numeric CC-030–033, CC-036 (numeric-delta concurrent row-lock contention scenarios A/B/C), CC-012 nested-savepoint probes (allowed rollback and sticky nested denial), and independent-policy acceptance in both operation orders plus the individual-violation savepoint-recovery case. Skipped tests: **none**; every assertion in the integrated harness executed. Outside the tested envelope (out of scope, not harness skips): capability-wide CC-034/035, CC-070–082, CC-090; managed deployment; ungranted INSERT/DELETE/COPY/TRUNCATE accounting; numeric-delta enforcement through `MERGE`; other PostgreSQL majors; real poolers.

Repeated runs: **4** executions at this exact tested commit/tree (2 during evidence collection, 2 during verification, including one full rerun after the first collection run); **4 of 4** exited 0 with no nondeterministic failures. **1** complete sanitized transcript is archived (the run described above); the other three runs' outputs were observed but not archived.

## Superseded historical evidence (do not use for review)

[evidence/2026-09-23-integrated-audit.txt](../experiments/native_tx_state/evidence/2026-09-23-integrated-audit.txt) is an earlier complete exit-0 integrated transcript whose suite output printed `Tested commit: 186c8eb7543b7f0b51c9f9134fd97ff20317f912`. That commit exists locally as a documentation-only child of `e97f19b030db1d9f537e7d542c02279c0481922f` (parent verified via `git log`), but it is **not reachable from any published ref and GitHub returns HTTP 422 for it**, so it is not publicly reproducible. Its harness blobs are byte-identical to the tested commit's (blob hashes listed above), so the executed code was the same; the superseding run above re-executes the identical suite at the publicly resolvable merge commit rather than relabeling this log. The historical transcript is retained verbatim as lineage.

## Pre-#12 background (superseded)

The earliest recorded run on base `2538963` plus this branch's audit probe reproduced the shared-counter known failure (`CC_MULTI_TABLE_STATE:5|t`, poisoned COMMIT rejected, fresh admin saw `subscriptions=10:0; users=6:0`). That reproduction is retained in git history and in the [coverage matrix](phase0-security-coverage.md); the merged PR #12 keyed counters per protected table and the regression now asserts the opposite, positive property. Historical same-number legacy CC-030–033 are row cases, not the canonical numeric tests.
