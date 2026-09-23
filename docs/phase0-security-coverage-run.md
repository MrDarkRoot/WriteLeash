# Phase 0 audit runtime evidence (issue #11)

Date: 2026-09-23 UTC. Base under test: `origin/main` `2538963906967f2a7d7ae79e6dfdc992a3b7d199` **plus this branch's audit probe**, before the documentation commit; see the exact `run.sh` diff in this PR. Command from repo root: `./experiments/native_tx_state/run.sh`. Exit status **0**: the harness asserts the previously observed known failure *still reproduces*, not that all intended independent-policy semantics pass. Docker image built from `postgres:16.4-alpine` digest `sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`; server reports `PostgreSQL 16.4`. Disposable Compose service and volume removed by `run.sh` exit trap. No cloud resources used.

Relevant verbatim sanitized terminal excerpt from the new probe:

```text
--- CC-012 nested savepoints (coverage audit) ---
CC-012 nested allowed rollback: PASS (inner RELEASE + outer ROLLBACK restored all four events; five replacement updates committed)
CC-012 nested denial: PASS (inner and outer recovery kept denied=true; COMMIT rejected; admin saw baseline and audit=0)

--- multi-table policy-isolation reproduction (known FAIL) ---
Additional unsupported-path privilege probes:
  coverage_copy_from                           SQLSTATE 42501 insufficient_privilege
  coverage_delete_returning                    SQLSTATE 42501 insufficient_privilege
  coverage_upsert                              SQLSTATE 42501 insufficient_privilege
  writer state:  CC_MULTI_TABLE_STATE:5|t
  writer final COMMIT: ERROR:  CommitCap top-level transaction denied after mutation authority violation
  fresh admin durable: subscriptions=10:0; users=6:0
multi-table per-policy isolation: FAIL against spec (5 subscriptions + 1 allowed users transition denied; both tables unchanged after rejected COMMIT)
native transaction-state experiment: existing required tests PASS; multi-table per-policy isolation KNOWN FAIL
```

For the unchanged regression harness, privilege-envelope/audit, legacy row-budget CC-001/002/003/004–009/011/019–021/024–033, canonical state-transition CC-020/021/022, and their fresh-admin durable checks ran to completion; no harness assertions failed. Historical same-number CC-030–033 **are not canonical numeric-delta tests**. Full pre-audit regression output with callback traces is the repository's [PR #8 evidence log](../experiments/native_tx_state/evidence/2026-09-23-cc020-cc022-a996eed.txt), tested at `a996eed` (not this audit branch). Current audit's new deterministic probes and privilege attempts are fully specified in `run.sh` and excerpted above; no complete new terminal transcript has been checked in. Untested/skipped by design: canonical numeric CC-030–033, mixed/deeper subtransaction combinations, full CC-040–055/060–065, capabilities CC-034/035/070–082/090, real pooler, alternative isolation levels and benchmark. The 0 exit status must not be interpreted as a complete Phase 0 PASS.
