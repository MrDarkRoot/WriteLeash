# Hosted CI (live exact-head audit)

Required checks: PR_FAST + CI_COVERAGE; strict=true, enforce_admins=true.

| PR | SHA | Check | Conclusion | Start / completion (UTC) | Run |
|---|---|---|---|---|---|
| #219 | `eaad5dd0b1703997667474edb55f928fd2e75d0d` | CI_COVERAGE | failure | 2026-10-08T05:28:27Z / 2026-10-08T05:28:33Z | [job](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37732527033/job/113164727253) |
| #219 | `eaad5dd0b1703997667474edb55f928fd2e75d0d` | PR_FAST | failure | 2026-10-08T05:28:15Z / 2026-10-08T05:28:25Z | [job](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37732527033/job/113164674139) |
| #220 | `c2e3f6167879bbc5ac7ba9c798cbb43ce672608d` | CI_COVERAGE | failure | 2026-10-08T11:05:05Z / 2026-10-08T11:05:13Z | [job](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37767696741/job/113279351750) |
| #220 | `c2e3f6167879bbc5ac7ba9c798cbb43ce672608d` | PR_FAST | failure | 2026-10-08T11:04:51Z / 2026-10-08T11:05:03Z | [job](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37767696741/job/113279264496) |
| #221 | `fd0dc73e8a1598da1c894a6be24be28f860c286d` | CI_COVERAGE | failure | 2026-10-08T06:10:20Z / 2026-10-08T06:10:25Z | [job](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37736137796/job/113176026112) |
| #221 | `fd0dc73e8a1598da1c894a6be24be28f860c286d` | PR_FAST | failure | 2026-10-08T06:10:09Z / 2026-10-08T06:10:18Z | [job](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37736137796/job/113175974504) |
| #222 | `079f5dad148a8718586db05b4abc322047908710` | CI_COVERAGE | success | 2026-10-08T05:19:05Z / 2026-10-08T05:19:10Z | [job](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37731723746/job/113162250547) |
| #222 | `079f5dad148a8718586db05b4abc322047908710` | PR_FAST | success | 2026-10-08T05:18:38Z / 2026-10-08T05:19:03Z | [job](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37731723746/job/113162128001) |

All current PR heads contain the current main SHA, so the ancestry part of strict up-to-date is met. This does not satisfy failed required checks. All are Draft; #222 check eligibility does not grant review or merge authority.

Workflow is pull_request triggered (opened/synchronize/reopened/labeled/unlabeled); no read-only workflow_dispatch for PR checks. Existing Actions rerun is supported if new execution becomes necessary, but current exact-head checks already ran and source gate failure is deterministic. No dummy commit, label mutation, workflow edit or administrative override used. RELEASE_FULL dispatch is unrelated and not used.

Integration exists only locally. HOSTED_INTEGRATION_CI_BLOCKED_PENDING_AUTHORIZATION. A fifth PR has not been authorized.
