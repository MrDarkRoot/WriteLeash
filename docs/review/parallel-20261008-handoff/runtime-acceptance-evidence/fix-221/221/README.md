# PR #221 / issue #204 — round-4 runtime defect fix evidence

Clone: `/tmp/writeleash-approved-20261008/agent-C`, branch `work/204-live-progress`.
Previous HEAD: `19e2c6892c5887bb53a4a470979a44d3822e3480`; origin/main `c48e307efe7750d0fb36015c39d50a34675f9579` (untouched).

## Defect (runtime-reproduced)

Real-browser acceptance against the integrated candidate (WP 7.1.2, WooCommerce 11.1.2, MySQL
8.0.44, host Chrome via Playwright 1.61) showed the polled Apply/Undo conflict cell text flipping
from the long server-rendered guidance to the short `reason_message()` variants after the first
successful poll (`free-progress.js` rewrites the cell text). Browser journey failed at
`readable Undo preservation` (`wordpress/tests/admin/regression-browser.cjs:275`); the Apply check
at ~`:259` passed only by pre-poll timing. Host dump:
`/tmp/wlruntime/evidence/round4-defect/live-undo-page-dump.txt`. DB outcomes were correct
(`UNDO_CONFLICT` recorded, externally edited prices preserved).

## Root cause

Two spellings of the same guidance: `render_item_outcome()` embedded the long sentences locally,
while `progress_snapshot()` (rows at old `:255-256`) composed `item_label(...) . ' · ' .
reason_message($reason)` and picked up the short `ITEM_CONFLICT` / `UNDO_CONFLICT` reason strings
from `Job_Reason::messages()` / `Undo_Reason::messages()`.

## Fix

`class-free-admin.php` only (see `production.diff`):

- new `private static function outcome_conflict_copy( ?string $state ): string` — the two long
  sentences for `CONFLICT` / `UNDO_CONFLICT` (U+2019 preserved), `''` otherwise;
- `render_item_outcome()` takes its `<p>` copy from the helper (no behavior change);
- `progress_snapshot()` computes `$apply_conflict` / `$undo_conflict` per row; non-empty → append
  `' · ' . <copy>`; empty → existing reason-message path (including the `null ===
  $item['undo_state']` / `undo_item_label()` branch). No other text, labels, attention flags or
  read-only behavior changed.

Post-fix impact re-run on `outcome_conflict_copy`: CRITICAL / 68 (render-community transitive —
auto-derived for a helper on the render path; its change surface is message text only).
`progress_snapshot` post-edit: LOW / 3 (handle_progress + AJAX flows).

## Pre-edit impact receipts (required order)

| Target | Risk | Impacted | Direct | Flows |
|---|---|---|---|---|
| `render_item_outcome` (upstream) | **HIGH** | 5 | 1 (`render_job_view`) | render, render_job_view, render_preview_view |
| `progress_snapshot` (upstream) | LOW | 1 | 1 (`handle_progress`) | — |

Callers of `render_item_outcome` text-confirmed at `class-free-admin.php:1678` and `:1681`
(inside `render_job_view`) because the post-edit incremental index returned UNKNOWN/0 for it.

## Tests

- `php wordpress/tests/admin/progress-unit.php` — PASS, **148 checks** (`progress-unit.log`):
  exact long copy for CONFLICT (null reason and `ITEM_CONFLICT`), exact long copy for
  `UNDO_CONFLICT` (+ attention flag), short variants absent, each long sentence stored exactly once
  in the production file. All prior assertions retained.
- `node wordpress/tests/admin/progress-client.cjs` — PASS, 9,996 enumerated cases / 69,481 checks
  (`progress-client.log`): conflict copy extracted from the PHP source is written into Apply/Undo
  cells verbatim; focused outcome keeps its previous saved copy with the documented stale-detail
  message.
- Negative discriminator (`prefix-harness.log`, `prefix-harness-reasonarm.log`): the updated unit
  suite run against
  `git show 19e2c68:wordpress/writeleash/includes/free/class-free-admin.php` fails immediately at
  `CONFLICT row keeps the long guidance with a null reason` (exit 255); the scratch arm that skips
  that check fails at `CONFLICT row prefers the long guidance over a reason code`. Post-fix both
  values pass. (Reason-arm caveat: pre-fix also throws in `reason_message()` because the unit
  harness does not load `Price_Reason_Messages`; the null-reason arm is the clean discriminator.)
- `php wordpress/tests/admin/admin-audit.php wordpress/writeleash` — PASS.
- Release battery (package-preflight, inventory-audit, public-audit-cases, readme-validate,
  historical-shim-cases), ownership audit + selection (`CODE_INTEGRATION: admin`),
  dependency-audit — PASS (logs in this directory).
- Lint: `php -l` changed PHP, `node --check` changed JS + client, `git diff --check` — PASS.
- `bash .github/ci/pr-fast.sh` — FAIL only at the preserved `#170 UI source differs from reviewed
  capture` (`asset-audit.php:100`); byte-identical to the prior `C2-pr-fast.log` after timing
  normalization. No earlier/new failure.

## Graph change gates

`detect-changes --scope all` and `--scope staged`: 19 symbols / 4 files, complete (no
`partial`/`truncated`), `risk_level: critical` dominated by name-collision over-attribution — the
test double method `history_items` and the `Undo_Repository` double share names with production
call sites, so hundreds of flows are attributed to the test file. Production change surface is the
helper + two row-string expressions.

## Residual risk / NOT_TESTED

Real-browser re-verification of the polled conflict rows is performed by the lead on the integrated
candidate after this push. Docker and a browser runtime are unavailable in this clone, so the
Docker-bound `progress-integration.php` and the full `regression-browser.cjs` journey remain
NOT_TESTED here; unit DOM/repository doubles establish no Woo runtime claim.
