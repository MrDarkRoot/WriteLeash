Refs #204 — **draft for code review only. Do not merge; founder GO/NO-GO pending.**

Base: `c48e307efe7750d0fb36015c39d50a34675f9579` · Previous head: `a1b7f3a317abefe92cd1fce88775579e585df312` · Head: `1e218cb8cab63d796dd4440aa17e6eeff59b5abd` (`fix(admin): tighten live progress page/filter sync and boundary tests (#204)`)

## Scope
- Authenticated read-only `wp_ajax_writeleash_free_progress` (+ explicit nopriv refusal): user `0` gets a generic 403 before any repository read; job-bound nonce, capability + creator/approver/administrator authorization, bounded offset/filter.
- Endpoint reads **only persisted** plan/job/item/Undo state — no schema install, no worker/Resume invocation, no product read, no cache eviction; missing saved evidence disables actions and polling.
- Isolated `free-progress.js`: updates counters/outcomes/action availability, preserves page-load current-price cells, labels filtered rows as needing reload, handles stalled/partial/error/session states, records last successful refresh, generation fence for out-of-order responses, single in-flight request, 15 s timeout, hidden-page pause, 10/20/40 s backoff, stops after four failures or terminal jobs; focus-preserving with concise polite announcements; native refresh/no-JS retained.
- Minimal asset additions: explicit manifest, public-asset allowlist entry, ownership registration.

## Review fix pass (HEAD `1e218cb`)
- **A — row/page sync (fixed):** `updateRows()` now reconciles rendered row order to the durable server page order via `insertBefore`; previously new rows were appended and existing rows never moved, so the visible list could disagree with the current filter/page. A focused row is never moved (moving a focused node drops focus): it keeps its place and the other rows are ordered around it; with no focus the server order is exact. Regression verified: the pre-fix client fails the new reorder test.
- **A — focused stale indicator (wording fixed):** focused outcome cells, focused removed review links and focused initial support details now explicitly state they still show the previous saved details/view until focus leaves. Mechanism unchanged (connection line as indicator; focused nodes retained until blur).
- **A — verified unchanged:** Apply/Undo counters, attention classes, generation fence, 401/403 stop + backoff, four-failure stop, hidden pause/resume, terminal poll stop, announcement de-duplication, no-JS mount, no framework.
- **B — verified unchanged:** actor/capability + job-bound nonce before reads; identical two-key refusal for anonymous/cross-actor/guessed IDs; endpoint read-only (no mutation/worker); `process_resume`/`process_undo` independently re-check capability, POST, dependency, job, action nonce, actor authorization, terminal/resumable state and plan binding; missing durable evidence fails closed.
- **Pager selector checked against `render_pager`:** the single `<p>` has the Previous control first and the Next control last, so `querySelector('p').lastElementChild` is correct for both the link and disabled-button shapes; no selector change needed (terminal shape now pinned by test).

## Fix-pass tests
- `progress-client.cjs`: server-order reconciliation (with/without focus), focused stale wording, focused removed row/link, filter/page change with no stale rows, empty-state removal, pager `next_url` create/update/hide/show + terminal disabled-button shape, 401 and 403 stop, first-failure backoff message, timeout abort + retry, hidden pause/resume, four-failure stop, announcement de-dup, PHP constant/action/field parity and every JS `data-*` selector checked against the PHP renderer, no-framework assertion.
- `progress-unit.php`: job-bound nonce mismatch, guessed public ID with a valid nonce (visibility lookup only), missing/non-string nonce, method case, offset at/over the real `MAX_EVIDENCE_ROWS` (double now mirrors `class-undo-repository.php`), extra repository-failure paths, and `__callStatic` mutation sentinels + `Job_Worker`/`Undo_Worker` sentinels asserted empty (negative controls confirmed).
- `progress-integration.php`: guessed-public-ID refusal now audited through the poll-only SQL/product-read wrapper (Docker-bound, NOT_TESTED).

## Behavior changes
1. Row-order reconciliation in `free-progress.js` (the only synchronization behavior change).
2. Explicit stale wording for focused nodes.
Justification in `docs/review/204/IMPLEMENTATION.md` §“2026-10-08 review fix pass”.

Files in the fix commit: `wordpress/writeleash/includes/free/free-progress.js`, `wordpress/tests/admin/progress-client.cjs`, `wordpress/tests/admin/progress-unit.php`, `wordpress/tests/admin/progress-integration.php`, `docs/review/204/IMPLEMENTATION.md`.

Support-claim impact: none (no listing/supported ceilings changed). Deviations: none.

## Local validation (PASS unless noted)
- `node wordpress/tests/admin/progress-client.cjs` PASS · `php wordpress/tests/admin/progress-unit.php` PASS · admin-audit · package-preflight (43-file closure) · inventory · public-audit-cases · test-artifact (10) · ownership + dependency · readme · historical-shim · guard-control (real manifest accepted; unknown/unreferenced assets and `new $class` rejected) · lint + `git diff --check` PASS.
- GitNexus `detect_changes` complete for the fix commit (5 files / 73 symbols / 14 flows, risk high — dominated by test-double and doc symbol churn; the edited production symbol `updateRows` is LOW and `progress_snapshot` LOW), no partial/truncated.
- **`pr-fast.sh` FAILS only at the unchanged #170 UI evidence gate** (`asset-audit.php:100`); log byte-identical to the prior run apart from timing.

## NOT_TESTED (blocked)
- Real Woo poll audit and real AJAX session journey (`progress-integration.php` extended, not executed), real browser keyboard/live-update/accessibility (axe), expired-session behavior — Docker/browser unavailable in this runtime. Real-browser row reorder/refocus behavior after DOM moves remains the main uncertainty the deterministic DOM tests cannot settle.

Full accounting: `docs/review/204/IMPLEMENTATION.md` (this branch). Lead report: `docs/review/parallel-20261008-handoff/FINAL-REPORT.md` (local workspace, not pushed).

