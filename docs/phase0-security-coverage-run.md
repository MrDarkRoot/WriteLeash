# Phase 0 audit runtime evidence (issue #11)

## Integrated post-#12 run (current evidence)

Date: 2026-09-23 UTC. **Integrated tested commit:** the exact tested HEAD is recorded verbatim in the archived transcript's `Tested commit` line and pinned in the follow-up evidence commit; harness files are byte-identical to merge commit `e97f19b030db1d9f537e7d542c02279c0481922f`, which integrates `origin/main` `3d68fe6f6058baf82f3f84d5498f55d729095f23` / PR #12 into this branch. Command from repo root: `./experiments/native_tx_state/run.sh`. Exit status **0**. The historical shared-counter KNOWN FAIL was removed and replaced by a positive independent-policy acceptance: forward order and reverse order (from `numeric_cases.sh`, merged from PR #12) plus a cross-policy individual-violation case with savepoint recovery added on this branch. Docker image built from `postgres:16.4-alpine` digest `sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`; server reports `PostgreSQL 16.4`. Disposable Compose service and volume removed by the `run.sh` exit trap; the trap also prints `Native suite exit status: 0`. No cloud resources used.

Skipped tests: none; every assertion in the integrated harness executed. Outside the tested envelope (not skipped-by-design gaps of the harness but explicitly out of scope): capability-wide CC-034/035, CC-070–082, CC-090; managed deployment; ungranted INSERT/DELETE/COPY/TRUNCATE accounting; other PostgreSQL majors; real poolers.

Verbatim sanitized excerpt from the integrated transcript:

```text
Command: ./experiments/native_tx_state/run.sh
Tested commit: (recorded verbatim in the archived transcript)
Base image: postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c
PostgreSQL: 16.4
```

Independent-policy acceptance (all three cases, exact probe values and fresh trusted-admin durable oracles):

```text
independence subscriptions→users→refunds: PASS (5|1|20.00, three fresh-admin durable oracles)
independence refunds→users→subscriptions: PASS (5|1|20.00, three fresh-admin durable oracles)
canonical CC-030: PASS (90.00 COMMIT, exact fresh-admin rows)
canonical CC-031: PASS (100.00 COMMIT, exact fresh-admin rows)
canonical CC-032 single: PASS (denied; fresh admin observed all eight baseline amounts)
canonical CC-032 decomposed: PASS (denied; fresh admin observed all eight baseline amounts)
canonical CC-033: PASS (+80/-80 consumed 80.00 in committed transaction; fresh admin amount 0.00)
numeric-delta concurrent row-lock contention: scenarios A, B and C PASS
--- independent-policy individual violation with sibling audit ---
  writer state:  CC_POLICY_VIOLATION_STATE:3|1|40.00|t
  writer final COMMIT: ERROR:  CommitCap top-level transaction denied after mutation authority violation
independent-policy individual violation: PASS (3|1|40.00 independently accounted; sticky denial after ROLLBACK TO; poisoned COMMIT rejected; fresh admin saw all protected baselines and sibling audit=0)
```

The individual-violation case performs permitted operations across all three policies (3 subscriptions events, 1 users transition, 40.00 numeric positive delta), exceeds the row policy inside a savepoint, recovers with `ROLLBACK TO SAVEPOINT`, and verifies `CC_POLICY_VIOLATION_STATE:3|1|40.00|t`: independently accounted consumption, sticky `denied=true`, rejected top-level COMMIT, and a fresh-admin check that all protected tables remain at baseline and the sibling unprotected audit insert is absent.

Other integrated sections that ran and passed: privilege-envelope and privilege audit (including the post-#12 three extension-owned functions check), legacy row-budget CC-001–009/011/019–021/024–033, canonical state-transition CC-020/021/022, CC-012 nested-savepoint probes (allowed rollback and sticky nested denial), unsupported-path privilege probes (COPY FROM, DELETE RETURNING, UPSERT → 42501), canonical numeric CC-030–033, numeric boundary/invalid-value/zero-budget/next-transaction cases, CC-036 numeric-delta concurrent row-lock contention scenarios A/B/C, and the lifecycle trace check. The harness reported **0 FAIL lines** and printed the final PASS line: `native transaction-state experiment: all required tests PASS including independent per-policy acceptance in both orders and individual-violation recovery (PG16.4 research fixture)`.

Repeated runs: the suite was executed **5** times across harness-identical commits of this integration (2 against the merge commit `e97f19b`, 2 and then 1 more against the documentation commit); **5 of 5** runs exited 0 with no nondeterministic failures. **1** complete sanitized transcript is archived at [evidence/2026-09-23-integrated-audit.txt](../experiments/native_tx_state/evidence/2026-09-23-integrated-audit.txt) from the final run on the implementation commit; the other four runs' terminal outputs were observed but not archived.

Pre-#12 background (superseded): the earlier run on base `2538963` plus this branch's audit probe reproduced the shared-counter known failure (`CC_MULTI_TABLE_STATE:5|t`, poisoned COMMIT rejected, fresh admin saw `subscriptions=10:0; users=6:0`). That reproduction is retained in git history and in the [coverage matrix](phase0-security-coverage.md); the merged PR #12 keyed counters per protected table and the regression now asserts the opposite, positive property. Historical same-number legacy CC-030–033 are row cases, not the canonical numeric tests.
