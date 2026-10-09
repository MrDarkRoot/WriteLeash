# Issue #205 — Conflict to fresh Preview

Implementation base: live `main` `dc151e2e554e3fcb894041c2e8ca7de852268811` (#223 merge), fetched 2026-10-09. Branch: `work/205-conflict-fresh-preview`. Deliverable: [Draft PR #224](https://github.com/MrDarkRoot/WriteLeash/pull/224).

Merchants open **Re-preview conflicted products** on Apply results, choose explicit products (none selected by default), edit original operation/safety suggestions, and submit the existing Preview path. Approval and Apply remain unchanged. The native form works without JavaScript; its action is outside poll-managed cells. Refresh saved progress discovers conflicts created after page load. Missing products are explained explicitly, and current unsupported products receive the normal Preview explanation. Edited inputs, selection and unchecked policy settings survive validation errors.

Source reads require authentication, both mutation capabilities and creator/approver/admin job authorization before any plan/outcome disclosure. Only bound, changing Apply `CONFLICT` rows enter recovery. Applied siblings, uncertain outcomes and Undo-only conflicts are excluded. Current per-product rights and source membership are rechecked. The existing planner rereads snapshots, checks identity/eligibility/rights/policies and freezes selected IDs; expansion beyond the chosen population or a supported variation changing parent identity is refused. The 1,000/1,001 target boundary is unchanged.

The new immutable plan gets a fresh plan ID/job and optional validated `source_job` public reference in hash-covered material. Old plans retain their shape/hash. No schema change, calculator, mutation engine, dependency or new operation was added. Recovery uses current prices (120 + 10% = 132); normal Apply preconditions preserve later edits. Original plan, item outcomes, completion record and journal remain untouched by recovery/new work.

Local validation:

| Command | Result |
| --- | --- |
| `bash .github/ci/pr-fast.sh` | PASS, package/ownership/source/PHP lint gates |
| `bash .github/ci/workflow-lint.sh` | PASS |
| `php wordpress/tests/admin/recovery-unit.php` | PASS, 2,618 cumulative assertions |
| `php wordpress/tests/free/sale-price.php` / `variations.php` | PASS, 105 / 75 assertions |
| `php wordpress/tests/free/selection-count.php` / `cache-public.php` | PASS, 63 / 73 model assertions |
| `php wordpress/tests/admin/progress-unit.php` | PASS, 153 checks |
| `node wordpress/tests/admin/progress-client.cjs` | PASS, 69,481 checks across 9,996 cases |
| Admin/no-replan audits, PHP/JS/shell syntax, `git diff --check` | PASS |

Runtime reference: [`e0b8ac4` run 37877212107](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37877212107) passed PR_FAST, plan, journal, jobs, Undo, Admin and CI_COVERAGE. Recovery passed 64 real Woo/DB assertions in each MySQL/MariaDB × default/Redis profile, and 36 Chromium JS/no-JS checks per profile. #170 passed 253 checks each in Chromium and Firefox. [Browser evidence](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37877212107/artifacts/11593578618) contains separate `205-*` captures/results; inspected captures show the chosen conflict and reviewed 120 → 132 target before approval.

The final candidate adds validation-error retention and an explicit deleted-product explanation to that verified implementation. Both have regression controls in the same Admin runner. The **final exact-head verdict and browser artifact links are recorded in [PR #224's checks and description](https://github.com/MrDarkRoot/WriteLeash/pull/224/checks)**; a previous green head does not authorize another head.

The #170 fingerprint follows its existing historical-capture/current-CI contract: canonical PNGs and independent result JSONs remain unchanged, while current source hashes require actual candidate Chromium/Firefox execution. No assertion was relaxed. A local negative control demonstrated the unchecked-policy reset before its fix. The first browser run also detected the incorrect operation key (`amount` versus persisted `input`); the unchanged journey assertion verified the correction.

Limitations: local Docker is stopped and starting it requires a password; runtime evidence comes from hosted CI. Recovery is bounded to supported source populations of at most 1,000 targets; historical oversized jobs retain their existing History/CSV/Undo paths. Recovery browser coverage is Chromium JS/no-JS; existing #170 also covers Firefox. Screen-reader and Safari checks and RELEASE_FULL remain NOT_TESTED. No merge, publication, deployment, issue closure or Ready-for-Review transition is performed.
