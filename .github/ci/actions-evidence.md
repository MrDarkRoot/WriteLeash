# Automated native PG16 security CI evidence (#17)

Date: 2026-09-23 UTC. Initial branch base:
`fce8eb4634da4151058000153acc8be903b9d3b8` (PR #14 merge).
PostgreSQL `16.4`, pinned image
`postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`.
Environment: GitHub-hosted `ubuntu-24.04`, Docker and Compose on a disposable
runner. Exact suite command in the workflow: `./experiments/native_tx_state/run.sh`.

| Executed SHA | GitHub Actions evidence | Outcome |
| --- | --- | --- |
| [`3f2e3ca9f4cac3f89158d8d857ff046ba533db57`](https://github.com/MrDarkRoot/CommitCap/commit/3f2e3ca9f4cac3f89158d8d857ff046ba533db57) (CI implementation) | [Positive PR run #35879058921](https://github.com/MrDarkRoot/CommitCap/actions/runs/35879058921) | **SUCCESS**: integrated suite, marker check, teardown and artifact steps all passed. |
| [`0183836a92e07c99114197868389081891ef3941`](https://github.com/MrDarkRoot/CommitCap/commit/0183836a92e07c99114197868389081891ef3941) (temporary controlled assertion failure) | [Negative PR run #35879364259](https://github.com/MrDarkRoot/CommitCap/actions/runs/35879364259) | **FAILURE as intended**, exit 1: after the full suite printed `Native suite exit status: 0` (including CC-036), the CI-owned canonical CC-030 execution assertion deliberately required `FAIL` instead of `PASS`; log showed `FAIL: integrated suite execution marker absent: canonical CC-030: FAIL (intentional CI assertion check)`. Teardown and artifact still succeeded. This validates the *CI assertion/failure propagation*, not a failing native database security case. |
| [`4be367e5409858b3689dcfd148645fb551776ef6`](https://github.com/MrDarkRoot/CommitCap/commit/4be367e5409858b3689dcfd148645fb551776ef6) (reverts the temporary negative assertion) | [Restored positive PR run #35879639702](https://github.com/MrDarkRoot/CommitCap/actions/runs/35879639702) | **SUCCESS**: full suite, assertion check, teardown and artifact on final functional tree. |

The negative assertion was committed temporarily and then reverted by a normal
new commit, without a force push; its effect is **absent from the final PR
diff**. No shared experiment harness, C code, security assertion, fixture grant
or `run.sh` was changed. GitHub logs and the synthetic-only
`native-pg16-security-log` artifacts are inspectable from the runs; artifacts
are retained for **7 days**. [The final-run artifact](https://github.com/MrDarkRoot/CommitCap/actions/runs/35879639702/artifacts/10760355830)
and [negative-run artifact](https://github.com/MrDarkRoot/CommitCap/actions/runs/35879364259/artifacts/10759518618)
contain the unabridged suite transcript. These temporary artifacts are distinct
from the already archived [integrated audit evidence](../../experiments/native_tx_state/evidence/2026-09-23-integrated-audit-e97f19b.txt).

Locally, from the unchanged initial fixture and the CI implementation tree,
`COMPOSE_PROJECT_NAME=commitcap_ci_local ./experiments/native_tx_state/run.sh > /tmp/opencode/commitcap-ci-local.log 2>&1`
followed by `bash .github/ci/assert-security-log.sh /tmp/opencode/commitcap-ci-local.log`
exited **0** with the full suite and all required markers. No suite skips were
introduced. These checks show repeatability in the **documented research
envelope only**, not production support or task-wide transaction-splitting
protection.
