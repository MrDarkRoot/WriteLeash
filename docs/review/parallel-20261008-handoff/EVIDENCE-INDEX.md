# Evidence index for independent review

Everything below is local on this machine under `/home/vanta/Projects/WriteLeash/docs/review/parallel-20261008-handoff/` (workspace, not pushed). No GitHub-accessible copy exists because push was not authorized; if you want one, authorize pushing the review branch `review/approved-four` (HEAD `7f47309740b4572fdf30dee8f333046b01b5a956`) from `/tmp/writeleash-approved-20261008/combined-review` — no PR needed.

| # | Requested artifact | Location | Notes |
|---|---|---|---|
| 1 | Final report | `FINAL-REPORT.md` | Lead report: lane table, independent-review findings, combined validation, blockers, founder decisions |
| 2 | Machine-readable state | `FINAL-STATE.json` | Base, per-lane commits/patch hashes, combined identity, deviations, pending decisions |
| 3 | Lane reports A/B/C/D | `lane-reports/` | `AGENT-A-FINAL-REPORT.md`, `AGENT-B-FINAL-REPORT.md`, `AGENT-C-FINAL-REPORT.md`, `AGENT-D-FINAL-REPORT.md` + final `IMPLEMENTATION-{204,206,209,210}-final.md` per-lane acceptance accounting |
| 4 | Combined evidence | `combined-evidence/` | Patch, per-file hashes, GitNexus receipts, PR_FAST/failure logs, full local battery logs |
| 5 | Exact combined candidate diff | `combined-evidence/combined-candidate.patch` | `git diff c48e307..7f47309` (39 files, binary-safe); SHA-256 `a6a2f95726c82235349c3070943fd2bd1ab182d2387adad775b8f64d13c09717` |
| 6 | Conflict resolution content | `combined-evidence/INTEGRATION-NOTES.md` + `combined-evidence/union-provenance.txt` | Exact baseline/A/B/resolved content of the single conflicted file (`tests/free/in-container.sh`, additive union); per-file provenance proving unions vs lane-exact matches |
| 7 | Stub integration | `combined-evidence/integration-commit-7f47309.patch` | Full `git show 7f47309`: the one test-only commit adding the `WC_Cache_Helper` stub to `selection-count.php` (32 assertions unchanged) |
| 8 | #209 semantic deviations | `combined-evidence/209-deviations.md` | Exact diffs + code-path citations: `fresh_product` literal constructors, extension refusal classification (`unreadable_product_data`, retryable FAILED/PENDING), doc-comment-only files outside the frozen manifest, residual test-only reference |

## Combined validation receipts (in `combined-evidence/`)

- `combined-pr-fast.log` — PR_FAST fails **only** at the unchanged #170 UI source/capture evidence gate; all preceding gates PASS.
- `combined-detect-compare.json` — GitNexus compare vs `c48e307`: complete (39 files / 302 symbols / 55 processes), no `partial`/`truncated`.
- `combined-detect-all.json` — clean tree after commits.
- `combined-file-hashes.txt`, `combined-head.txt`, `combined-changed-files.txt`, `combined-candidate.patch.sha256`.
- Battery logs: `combined-unit.log`, `combined-sale-price.log`, `combined-variations.log`, `combined-selection-count.log`, `combined-cache-public.log`, plus admin/release/ownership logs (see filenames).
- `combined-index.log` — GitNexus index refresh for the combined tree.

## Lane clone/branch identities (working trees clean; not merged anywhere)

| Lane | Clone | Branch | HEAD |
|---|---|---|---|
| A (#206) | `/tmp/writeleash-approved-20261008/agent-A` | `work/206-selection` | `fb2ecd4dd852cc4b394c25f3af316ced38092462` |
| B (#209) | `/tmp/writeleash-approved-20261008/agent-B` | `work/209-public-cache` | `7df7baf0f61f9226d99c622345de656381f207fe` |
| C (#204) | `/tmp/writeleash-approved-20261008/agent-C` | `work/204-live-progress` | `a1b7f3a317abefe92cd1fce88775579e585df312` |
| D (#210) | `/tmp/writeleash-approved-20261008/agent-D` | `work/210-i18n-preparation` | `b09fd5f226ea45e711352b8654e3ab3f09d0d1f3` |
| Combined (disposable) | `/tmp/writeleash-approved-20261008/combined-review` | `review/approved-four` | `7f47309740b4572fdf30dee8f333046b01b5a956` |

## Review-fix round (PRs #219–#222, 2026-10-08)

- `REVIEW-FIX-REPORT.md` — Maintainer-review fix cycle: per-PR table, new HEAD SHAs, tests, blockers, semantics, founder decisions.
- `review-fix-evidence/` — per-PR fix logs/reports (`219/`, `220/`, `221/`, `222/`), fetched PR bodies (`PR-2xx-body.md`), and the rebuilt disposable combined candidate evidence (`combined2/`: patch, detect receipt, PR_FAST and battery logs).

## Round 3 (PRs #219–#222, 2026-10-08)

- `ROUND-3-REPORT.md` — Round-3 verification/fix cycle: dual verdicts, HEAD SHAs, completeness fix, recovery matrix, #170/runtime blockers, combined candidate.
- `round3-evidence/` — per-PR evidence (`pr-fix3/219..222/`), fetched PR bodies, and the re-validated disposable combined candidate (`combined3/`).

## Round 4 (PRs #219–#222, 2026-10-08)

- `RUNTIME-ACCEPTANCE-REPORT.md` — real Woo/MySQL/MariaDB/Redis/browser acceptance: environment, per-PR results, #170 capture PASS on the integrated candidate, recovery assessment, defect fix, founder recommendations.
- `runtime-acceptance-evidence/` — `environment.txt`, runner logs (`logs/`), #170 captures/results (`web170/`), live-polling proof (`live/`), defect reproduction (`defect/`), #221 fix receipts (`fix-221/`), candidate patch hash and local PR_FAST PASS.

## Round 5 (PRs #219–#222, 2026-10-08)

- `FINAL-MERGE-READINESS.md` — final verification: evidence integrity + source mapping, #109 flake analysis, #170 acceptance, integration procedure, #220 decision sheet, GO/HOLD verdicts.
- `final-merge-evidence/` — manifest verification, staged-source mapping, rebuilt-candidate identity (byte-identical tree), branch-protection/CI state, race evidence (`race/`: B2/B4 FAIL logs, B3/diag PASS logs, failing assertion + durable failure state, instrumented plugin copies, focused reproducer), local PR_FAST receipt.

## Round 6 (PRs #219–#222, 2026-10-08)

- `ROUND-6-CORRECTNESS-REPORT.md` — issue-based closure: #109 root cause (stale per-request meta blob served to the post-COMMIT public read), fix `c2e3f61`, deterministic regression, integrated-candidate gates, verdicts.
- `round6-evidence/` — instrumentation traces/value diagnostics, before/after regression receipts, fix commit identity, runner validation PASS logs, combined candidate identity and integrated jobs/durable PASS logs.

Integrity manifest for this folder: `SHA256SUMS`.
