Refs #206 — **draft for code review only. Do not merge; founder GO/NO-GO pending.**

Base: `c48e307efe7750d0fb36015c39d50a34675f9579` · Previous head: `fb2ecd4dd852cc4b394c25f3af316ced38092462` · New head: `eadd0c9505e31ee3de7197936bc617ebf5eb2d2d` (review fix `899a115e5a3b8432dd92d77eddde89062ab31b7c`, receipts `eadd0c9`)

## Review findings addressed (P1: 2 × MAX_SELECTED raw candidate bound)

WP_Query does not add SQL DISTINCT for tax_query joins, so overlapping descendant membership returns the same post row once per matching join. The old `count( $query->posts ) > 2,000` raw sentinel counted duplicates and could falsely refuse a valid ≤1,000-target category selection.

- **False refusal fixed:** `resolve()` now keys `$query->posts` by post ID before the raw-bound check and before any Woo read. 2,501 duplicate join rows for one product are accepted (refused at `fb2ecd4`); each distinct product is read once.
- **Distinct-bound proof:** every distinct raw post is either a final target or the core parent of ≥1 expanded variation; distinct core parents own disjoint child sets, so `parents ≤ final targets` and `distinct raw ≤ 2 × final targets ≤ 2,000` for ≤1,000 final targets. Any distinct raw population >2,000 therefore implies >1,000 final targets, so the bound never refuses an otherwise-valid selection. Exact boundary covered: 2,000 distinct raw rows (1,000 parents + their 1,000 directly categorized children) deduplicate to 1,000 accepted targets; 2,001 distinct rows refuse before any Woo read.
- **Truncation closed:** the CATEGORY query additionally requests SQL `DISTINCT` (`posts_distinct`, removed in `finally`), so joins collapse before `LIMIT` and the 2,001st row sentinel counts distinct posts. Without it, a 1,003-row product plus 999 simple targets would expose only 999 of 1,000 valid targets; the new 46-assertion harness proves the crowded population is complete with the guard and would truncate without it. PHP keying keeps the invariant if a cache/plugin ignores the DISTINCT request.
- **Other bullets audited, no change needed:** no double counting (ID-keyed products/unreadable, `array_unique` children, skip-existing expansion, `array_unique` final IDs); category changes cannot affect a frozen plan (Preview re-resolves and freezes, execution never reselects); a no-child/unexpandable variable parent stays one explained unsupported target. Counts stay informational: `discover_count()` keeps `{selected, unreadable, missing}`, and the existing copy already states counts are informational, Preview resolves again and freezes, and Preview explains skipped/unsupported products.
- **No limits changed:** `MAX_SELECTED` = 1,000 and `MAX_JOB_PRODUCTS` = 1,000 untouched; `posts_per_page` remains 2,001 for CATEGORY.

## Files (review fix)

- `wordpress/writeleash/includes/free/class-product-selector.php` — ID-keyed raw dedupe + `bounded_discovery_query()` DISTINCT guard with the sufficiency argument in-code.
- `wordpress/tests/free/selection-count.php` — stub now models per-membership join rows + `posts_distinct`; 32 → 46 assertions.
- `wordpress/tests/free/integration.php` — real-WP overflow fixture corrected to 1,001 distinct rows; added 1,003-duplicate-row acceptance case.
- `docs/review/206/IMPLEMENTATION.md` — review-bullet audit, sufficiency/bound statement, receipts.

## Local validation (PASS unless noted)

- `selection-count.php` 46 assertions PASS; against pre-fix `fb2ecd4` bytes the duplicate test FAILs as expected (`selection_limit_exceeded` at the old row check); against baseline `c48e307` the original opt-in negative control still FAILs as expected.
- `unit.php` 2,607 · `sale-price.php` 105 · `variations.php` 73 · admin-audit · no-replan · ownership · package/inventory/public/readme/historical/claim-matrix audits · changed-file lint · `git diff --check`.
- GitNexus pre-edit impacts LOW (`resolve` had a 3-call-site receiver-typing gap, confirmed by text search); `detect_changes --scope all` (3 files/10 symbols/7 flows, high) and `--scope staged`, no partial/truncated.
- Ownership for the 4 changed paths: `{"PR_FAST": true, "CODE_INTEGRATION": ["admin", "jobs", "journal", "plan", "undo"], ...}`.
- **`pr-fast.sh` FAILS only at the unchanged #170 UI source/capture evidence gate** — fresh approved browser capture required; no evidence was rehashed and no assertion/audit was weakened.

## NOT_TESTED (blocked) / remaining blockers

- Real Woo/Admin HTTP fixtures (`selection-http.php`, `selection-integration.php`, `subcategory-integration.php`, `integration.php`) and no-JS/browser accessibility — Docker daemon unavailable.
- PR_FAST #170 reviewed-capture fingerprint; runtime/UI acceptance. Issue #206 is not claimed closed or release-ready.
- Support claims: unchanged (manifest, screenshots and proofs untouched).

## Deviations

- Added the CATEGORY-only `posts_distinct` SQL guard beyond the minimal PHP dedupe, so the sentinel is exact rather than page-crowding-dependent; disclosed above.
- `integration.php` overflow simulation changed from 1,001 copies of one post (which the corrected semantics must accept) to 1,001 distinct rows, plus a new duplicate-row acceptance assertion — a faithful fixture correction, not a weakened check.
- Two commits (fix + review receipts) rather than one, matching the branch's existing docs-receipts pattern.

Receipts: `/tmp/writeleash-approved-20261008/evidence/pr-fix/219/fix-*.log`.
