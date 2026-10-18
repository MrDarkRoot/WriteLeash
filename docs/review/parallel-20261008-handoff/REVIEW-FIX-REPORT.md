# Review-fix report — PRs #219–#222 (Maintainer review findings, 2026-10-08)

Scope: correct the independent Maintainer review findings on the four existing draft PRs, smallest practical patches, targeted regressions, truthful evidence. **No merge, deploy, publish, issue closure, replacement PR, or fifth PR. All four PRs remain Draft. Founder retains GO / NO-GO.**

Preflight: `origin/main` unchanged at `c48e307efe7750d0fb36015c39d50a34675f9579`; no maintainer comments/reviews exist on GitHub (the findings document is the review); no third-party corrections; PR HEADs matched what we pushed. CI baseline: #219/#220/#221 have PR_FAST fail (#170 evidence gate) with CI_COVERAGE failing only as its downstream assertion; #222 green.

## 1. Per-PR result table

| PR | Finding | Fix | Evidence | Remaining risk | Verdict |
|---|---|---|---|---|---|
| **#219** (#206) P1 | `2 × MAX_SELECTED` raw bound could falsely refuse a valid selection because WP_Query does **not** DISTINCT tax joins (duplicate rows per matching descendant term) | CATEGORY query requests `posts_distinct` (scoped filter, removed in `finally`); rows keyed by post ID before the bound check and before any Woo read; sufficiency proof documented (distinct raw ≤ 2 × final ≤ 2,000 for ≤1,000 targets); tests 32→46 assertions incl. duplicate-row acceptance, DISTINCT page-crowding discriminator, 2,000/2,001 boundary, refuse-before-any-read | Commit `899a115` (+docs `eadd0c9`); `selection-count.php` **46 PASS**; full battery PASS; detect-changes complete (3 files/10 symbols/7 flows); PR_FAST fails only at #170 | Real-WP `posts_distinct` behavior and Docker admin/HTTP suites NOT_TESTED; if an environment stripped the filter, PHP keying still prevents false refusal but a duplicate-crowded page could undercount (documented discriminator) | **RESOLVED (unit/static); runtime BLOCKED** |
| **#220** (#209) P0 | Refusal classification change made stable unsupported product classes retryable (`unreadable`/PENDING) instead of the established terminal refusal | `fresh_product`: `WC_Product` subclass outside the three core classes → typed `unsupported_product_type` (never constructed); transient/broken-mapping/constructor failures → typed `unreadable_product_data`; selector records unsupported snapshots (`exists=true`) → eligibility `unsupported_product_type`; Apply/Undo entry restore terminal `UNSUPPORTED_PRODUCT_STATE`, undo recovery restores `CACHE_VERIFICATION_FAILED`, `sync_variable_parent` restores `CONFLICT`; durable terminal-vs-retryable cases added | Commit `7496874`; `cache-public` **73 PASS** (extension never constructed), `variations` **75 PASS**, `unit` 2,607; durable fixture extended (Docker NOT_TESTED); detect complete (8 files/22 symbols/35 flows); PR_FAST only #170 | Docker durable/Woo runtime NOT_TESTED; one intentional divergence below (transient unreadable is now retryable where baseline was terminal) | **RESOLVED (P0 code/tests); runtime BLOCKED** |
| **#221** (#204) P1 | Browser sync vs real DOM; focused/outdated rows; server order; auth/read-only verification depth | JS reconciles row order to the server page while never moving a focused row; explicit stale wording for focused outcome/link/support details (test-pinned); selectors verified against PHP; pager shape verified correct (unchanged); tests extended: server order, filter/page change, focused stale indicators, guessed public ID, nonce bound to a different job, read-only mutation/worker sentinels with negative controls | Commit `1e218cb`; `progress-client.cjs` PASS, `progress-unit.php` PASS; full battery PASS; detect complete (5 files/73 symbols/14 flows); PR_FAST only #170 | Real-browser focus after DOM moves, axe/keyboard, expired-session journey and the durable poll-only SQL audit NOT_TESTED; `class-free-admin.php` unchanged (verify-only lane) | **RESOLVED (deterministic); browser runtime BLOCKED** |
| **#222** (#210) P2 | ~56,000-line/1.8 MB generated inventory: non-shipped code, machine noise, Git overhead | Tool scoped to the public distribution manifest; deterministic `text` vs `machine` classification (11 documented heuristics); `baseline-literals.json` deleted; 7,020 B `inventory-summary.json` committed; full inventory reproducible on demand; docs and focused tests updated | Commit `4c7f6b2`; i18n tooling **PASS** (manifest scoping/classification/determinism/missing inputs); `ownership --audit` PASS; **PR_FAST + CI_COVERAGE green on this PR** | Locale runtime and #204/#206-dependent conversion NOT_TESTED | **RESOLVED (preparation); #210 remains PARTIAL** |

## 2. Exact SHA for each updated branch

| Branch | Previous HEAD | New HEAD | Commits added |
|---|---|---|---|
| `work/206-selection` (#219) | `fb2ecd4dd852cc4b394c25f3af316ced38092462` | **`eadd0c9505e31ee3de7197936bc617ebf5eb2d2d`** | `899a115e…` (fix), `eadd0c9` (docs) |
| `work/209-public-cache` (#220) | `7df7baf0f61f9226d99c622345de656381f207fe` | **`7496874eb0b48989814234afb703d17a5563ace0`** | `7496874e…` |
| `work/204-live-progress` (#221) | `a1b7f3a317abefe92cd1fce88775579e585df312` | **`1e218cb8cab63d796dd4440aa17e6eeff59b5abd`** | `1e218cb8…` |
| `work/210-i18n-preparation` (#222) | `b09fd5f226ea45e711352b8654e3ab3f09d0d1f3` | **`4c7f6b2fd919b0c2a84922f15dd1b9bfdaca26fd`** | `4c7f6b2f…` |

All pushed fast-forward (no force, no amend, no rebase); all four PRs verified Draft with matching `headRefOid`; PR descriptions updated with previous/new HEAD, findings addressed, behavior changes, files, tests, blockers, support-claim impact and deviations.

## 3. Tests that genuinely passed (lead re-ran)

- **#219**: `selection-count.php` 46 · `unit.php` 2,607 · `sale-price.php` 105 · `variations.php` 73 · admin-audit, no-replan, ownership, release audits · lint (`git diff --check`).
- **#220**: `cache-public.php` 73 · `unit.php` 2,607 · `variations.php` 75 · `sale-price.php` 105 · admin-audit · full release/ownership battery.
- **#221**: `progress-client.cjs` PASS (order/filter/focus/backoff/out-of-order) · `progress-unit.php` PASS (auth/nonce/guessed-ID/read-only sentinels) · release/ownership battery.
- **#222**: `i18n-inventory-cases.php` PASS · deterministic regeneration byte-identical · `pr-fast.sh` **PASS** (green CI).
- **Combined disposable candidate** (`dd95c17…`, B→A→C→D, one test-stub integration commit from round 1 + two selector union resolutions): full local battery PASS — unit 2,607 · sale 105 · variations 75 · selection-count 46 · cache-public 73 · progress-client · progress-unit · admin-audit · no-replan · package-preflight 43-file · inventory/public/readme/historical/claim-matrix · i18n tooling · ownership · dependency · test-artifact · lint + `git diff --check`; PR_FAST fails only at #170; GitNexus compare complete (40 files/354 symbols/56 flows, no partial/truncated). Patch `combined2-candidate.patch` SHA-256 `9b342d2eaae6f6447a087a2784c520ed6eff99fc0161fc49d538e578c979905a`.

## 4. Tests blocked or not run

- **Docker/Woo runtime** (socket absent, daemon inactive, no bypass): `tests/durable/cache-public-integration.php` (incl. new terminal-vs-retryable extension cases), `tests/admin/progress-integration.php`, `tests/admin/selection-http.php`, `selection-integration.php`, `subcategory-integration.php`, full `admin/run.sh` matrix, `tests/free/integration.php` (new duplicate-row + overflow case).
- **Real browser**: #204 live Apply/Undo, focus/order after DOM moves, keyboard, axe/accessibility tree, expired-session journey; #206 no-JS/browser flow.
- **PR_FAST #170 UI evidence gate** on #219/#220/#221 (and combined): fresh repository-approved UI source/capture evidence cannot be produced without an authorized runtime/browser environment. The gate, fingerprints and assertions were **not** modified; CI_COVERAGE on those PRs fails only by its `PR_FAST success` precondition.
- **RELEASE_FULL** not authorized, not dispatched. #210 locale conversion NOT_TESTED by design.

## 5. Findings resolved vs unresolved

Resolved in code + tests: all four review findings (#219 bound/dedupe, #220 P0 recovery semantics + #209 verification, #221 sync/auth depth, #222 artifact size/scope). Unresolved/blocked: runtime acceptance of #206/#209/#204 (Docker/browser), #170 evidence gate, and the intentional transient-retry divergence below.

## 6. Source collisions remaining

- `wordpress/writeleash/includes/free/class-product-selector.php` — A/B overlap. The combined candidate resolved two hunks as true unions: the final ID merge includes `$unsupported_type`, and the resolve loop iterates A's deduped `$raw_posts` while keeping B's unsupported/unreadable split. **One integration-only guard** was added in the candidate (`isset($expanded_unsupported[$child_id])` in the child skip) to avoid duplicate reads; it is documented here and is not required for correctness.
- `class-free-admin.php` — three-way method-level split unchanged (A selection methods, B `product_observation`, C progress/job/undo hooks). No concurrent working-tree edits.
- `tests/free/in-container.sh` — additive union (A + B entries).
- Recommended merge order remains **B → A → C → D** with hunk-level review; the round-1 `WC_Cache_Helper` test stub in `selection-count.php` must be carried if #209 merges before #206.

## 7. Changes to recovery/conflict semantics

Restored to baseline by #220: stable unsupported product class → **terminal** `UNSUPPORTED_PRODUCT_STATE` at Apply/Undo entry (durable FAILED, never PENDING); undo verification → `CACHE_VERIFICATION_FAILED`; `sync_variable_parent` → `CONFLICT`. Never resets `CONFLICT`/`NEEDS_REVIEW`; no APPLIED-from-equality; no write on refusal; extension classes never constructed.

One **intentional, documented divergence**: a *transient* unreadable read (missing dependency function/class, suspended invalidation, constructor failure, broken non-product mapping) now throws typed `unreadable_product_data` and ends retryable `FAILED`/PENDING; baseline made any unreadable read terminal `UNSUPPORTED_PRODUCT_STATE`. This follows the review's "distinguish transient unreadable from stable unsupported" requirement; it is conservative (no write) and retry is explicit. Flagged for founder awareness — if strict baseline is required, make transient unreadable terminal before merge.

## 8. Combined integration considerations

The disposable candidate was rebuilt on the fixes and revalidated (identity above); it lives only at `/tmp/writeleash-approved-20261008/combined-review` (branch `review/approved-four`, HEAD `dd95c17ab5c1faef487ff056c4b5e9206dc5644a`) and was **not pushed and not converted into a PR**. The two selector union resolutions are the exact merge hazards for the eventual integration; no other cross-lane conflict exists. Rerun the owning selection/cache/progress suites plus the durable harness after any real merge, and regenerate #170 evidence through the approved process before CI can go green on #219/#220/#221.

## 9. Decisions requiring founder approval

1. **GO/NO-GO** on any merge; all PRs stay Draft.
2. Accept the transient-unreadable → retryable behavior (documented divergence), or require strict-baseline terminal treatment.
3. Authorize a runtime environment (Docker daemon/VPN-permitted host) for Woo/Redis/MySQL and browser acceptance, and the repository-approved #170 UI evidence recapture.
4. Keep #210 as **PARTIAL preparation** until conversion follows the accepted #204–#208 UI.

Artifacts: `review-fix-evidence/` (per-PR fix logs/reports, PR body snapshots, combined2 patch/detect/battery, `FINAL-REPORT.md` round-1 context). Integrity manifest: `SHA256SUMS`.
