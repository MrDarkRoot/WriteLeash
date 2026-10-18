Refs #206 — **draft for code review only. Do not merge; founder GO/NO-GO pending.**

Base: `c48e307efe7750d0fb36015c39d50a34675f9579` · Previous head: `eadd0c9505e31ee3de7197936bc617ebf5eb2d2d` · New head: `eaad5dd0b1703997667474edb55f928fd2e75d0d` (round-3 fix `5cf39c1027c41a8d02a7725deffc34d2e93f63c2`, docs receipts `eaad5dd`)

## Round-3 review finding addressed (P1: bounded CATEGORY discovery completeness)

Round 2 deduplicated raw rows in PHP and asked for SQL `DISTINCT`, but PHP keying cannot restore rows SQL `LIMIT` already removed, the `posts_distinct` guard was global while the constructor ran, and a full duplicate page was accepted as if complete.

- **Token-scoped DISTINCT:** `bounded_discovery_query()` now mints a private token query var and registers `add_filter( 'posts_distinct', $guard, 10, 2 )`; the 2-arg callback returns `'DISTINCT'` only when the second argument is a `\WP_Query` whose `writeleash_discovery_token` matches this query, otherwise returns the incoming clause unchanged. An unrelated or nested query built while the guard is registered keeps its own value; the guard is removed in `finally` on normal and exceptional exits.
- **Fail-closed completeness (CATEGORY only, before the sentinel and before any Woo read):**
  - window rewrite — `(int) $query->get('posts_per_page') !== $raw_limit + 1` → refuse;
  - full duplicate page — `count($query->posts) > count($raw_posts)` AND `count($query->posts) >= actual posts_per_page` → refuse (SQL may have truncated distinct rows before PHP dedupe could see them);
  - short duplicate page — proceed; every matching row was returned and keying is exact (each distinct product read once).
- **Refusal code unchanged:** existing `selection_limit_exceeded`; the Admin native count action surfaces the existing over-limit message (`selection_count = { over_limit: true }`, `class-free-admin.php` ~344-347) and Preview maps it to the existing `supported_job_limit_exceeded` wording. No new reason code or UI.
- **Conservatism disclosed:** in a DISTINCT-stripping environment (cache/plugin/filter) some otherwise-valid selections are now refused rather than resolved possibly-truncated — e.g. the 2,501-duplicate-row fixture that round 2 accepted now refuses (full duplicate window).
- **Unchanged:** `MAX_SELECTED` / `MAX_JOB_PRODUCTS` = 1,000; `posts_per_page = raw_limit + 1 = 2,001`; direct-members default; explicit opt-in; dedupe; Preview freeze; old-plan hydration; IDS/SKU ceilings.

## Files (round 3)

- `wordpress/writeleash/includes/free/class-product-selector.php` — token-scoped `posts_distinct` guard + fail-closed completeness in `resolve()`.
- `wordpress/tests/free/selection-count.php` — stub now models `WP_Query::get()`, 2-arg filter callbacks, construction throw, `posts_per_page` override, `posts_results`-style injection and a nested/unrelated query probe; 46 → 63 assertions.
- `wordpress/tests/free/variations.php` — stub `query_vars`/`get()` for the CATEGORY resolve path (73 assertions unchanged).
- `wordpress/tests/free/integration.php` — kept the IDS short duplicate acceptance; added a CATEGORY full-window duplicate refusal via `posts_results` injection.
- `docs/review/206/IMPLEMENTATION.md` — completeness argument, fail-closed behavior, receipts.

## Local validation (PASS unless noted)

- `selection-count.php` 63 assertions PASS (fail-closed matrix: full/short window × duplicates/no-duplicates × tamper × results-path injection, plus token scoping, exceptional cleanup, 2,000/2,001 distinct boundaries, 1,000/1,001 final targets, overlap, count/Preview divergence, old-plan hydration).
- Negative control: the same harness against round-2 bytes (`eadd0c95`) FAILs as expected at the 2,501 full-window duplicate refusal; the baseline `c48e307` opt-in negative control still FAILs as expected.
- `unit.php` 2,607 · `sale-price.php` 105 · `variations.php` 73 · admin-audit · no-replan · ownership `--audit` · package/inventory/public/readme/historical/claim-matrix audits · changed-file lint · `git diff --check`.
- GitNexus pre-edit impacts LOW (`bounded_discovery_query` exact; `resolve` 3-call-site receiver-typing gap confirmed by text search); `detect_changes --scope all` (4 files / 16 symbols / 6 flows, high) and `--scope staged`, no partial/truncated.
- Ownership for the changed paths still `{"PR_FAST": true, "CODE_INTEGRATION": ["admin", "jobs", "journal", "plan", "undo"], ...}`.
- **`pr-fast.sh` FAILS only at the unchanged #170 UI source/capture evidence gate** — fresh approved browser capture required; no evidence was rehashed and no assertion/audit was weakened.

## NOT_TESTED (blocked) / remaining blockers

- Real Woo/Admin HTTP fixtures (`integration.php`, `selection-http.php`, `selection-integration.php`, `subcategory-integration.php`) and no-JS/browser accessibility — Docker daemon unavailable (`/var/run/docker.sock` absent).
- PR_FAST #170 reviewed-capture fingerprint; runtime/UI acceptance. Issue #206 is not claimed closed or release-ready.
- Support claims: unchanged (manifest, screenshots and proofs untouched).

## Deviations

- Fail-closed completeness can refuse some genuinely valid selections in a DISTINCT-stripping environment; chosen over resolving a possibly truncated population and disclosed with the Admin/Preview message mapping.
- Added `query_vars`/`get()` to the `variations.php` stub so the CATEGORY resolve path mirrors `WP_Query::get()`; assertion count unchanged.
- Two commits (fix + docs receipts) rather than one, matching the branch's existing pattern.

Receipts: `/tmp/writeleash-approved-20261008/evidence/pr-fix3/219/fix3-*.log`.
