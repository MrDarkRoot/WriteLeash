# Agent B — #209 final report (substantive completion)

- Issue: #209 — Remove WooCommerce Internal cache dependencies
- Clone: `/tmp/writeleash-approved-20261008/agent-B` — branch `work/209-public-cache`
- Baseline: `c48e307efe7750d0fb36015c39d50a34675f9579`
- Implementation commit: `56847070d90c84e180cf81cf60eb7a659fd5277a` — `fix(cache): remove Woo Internal cache dependencies from runtime paths (#209)`
- Docs commit: `7df7baf0f61f9226d99c622345de656381f207fe` — `docs(review): record #209 validation receipts`
- Working tree after both commits: clean; post-commit/post-docs detect-changes “No changes detected”
- Implementation patch sha256 (`git diff c48e307..5684707 --binary`): `3af490ae1f7fd9013099a4cfc008097f8e8518b7ecea1fa6a96cf907f52e5bca` (`B2-implementation.patch`)
- Full lane patch sha256 (`git diff c48e307..7df7baf --binary`): `9c17d5f5cde5c348b1852913f2a36468d6b004a1dca72a09b91d10f5703bed8b` (`B2-final.patch`, `B2-final.sha256`)
- PR status: **not created**; read-only `gh`; no push/PR/merge/deploy/publish/issue update/RELEASE_FULL

## Acceptance criteria

| Issue criterion | Verdict | Evidence |
|---|---|---|
| Shipped runtime has no Woo Internal dependency incl. strings | **PASS** (static/package) | `B2-internal-grep.log` (all three greps empty); package-preflight, source-audit, inventory-audit, public-audit-cases all PASS; pure contract scans shipped `includes/**.php` for the escaped namespace |
| Concurrent supported edit → fresh Preview/Apply/observation/Undo, newer edits preserved | **PASS (pure contract)** / **NOT_TESTED (runtime)** | 72-assertion contract + durable fixture prepared; Docker blocked |
| Focused default + persistent-cache tests for simple/variation/parent/rollback/recovery/current display | **PASS (wiring + syntax)** / **NOT_TESTED (execution)** | `durable/cache-public-integration.php` wired with instance-cache option enable/restore |
| Versions before/after ProductCache boundary behave correctly | **PASS (source-verified)** / **NOT_TESTED (runtime)** | 9.9.7 / 11.0.1 / 11.1.2 factory + controller sources; no version-conditional shipped branch |
| Missing support/freshness fails visibly and conservatively | **PASS (contract)** / **NOT_TESTED (rendered/runtime)** | suspended invalidation, extension/non-core/false/mismatch refusals, absent stays absent |

## Step 1 — honest current-state reproduction

- `php wordpress/tests/free/cache-public.php` (pre-fix, old dynamic `new $class`): **PASS 69 assertions** (`B2-cache-public-initial.log`) — the pure contract alone did not expose the defect.
- The actual blocker reproduced: `php wordpress/tests/release/package-preflight.php …` → exit 255, `#120 public runtime audit: dynamic class dependency in includes/free/class-product-snapshot.php` (`B2-preflight-initial.log`).
- Baselines pre-fix: unit 2,607 PASS; sale 105 PASS; variations 73 PASS (`*-initial.log`).

## Step 2 — direction confirmed against sources and tests

- Pinned ZIPs hashed and verified against the repo’s expected values before use (Woo 11.1.2 `9de9350a…`, 11.0.1 `da189b66…`, 9.9.7 `96facedd…`, Redis 2.7.0 `0cbc41de…`).
- `WC_Product_Factory::get_product_type` / `get_product_classname` public and identical across all three Woo versions; classname fallback to `WC_Product_Simple` only when the filtered class is absent.
- 11.0.1/11.1.2 `ProductCacheController::register_hooks()` wires instance eviction to the public `clean_post_cache` action; 9.9.7 has no instance cache.
- The unchanged audit rejects `new $var(...)` / `$var::`; it was not relaxed.

## Step 3 — `fresh_product` fix (exact)

`wordpress/writeleash/includes/free/class-product-snapshot.php` now keeps input guards, returns `false` for a genuinely absent type, and constructs only:

```php
if ( 'WC_Product_Simple' === $class ) { $product = new \WC_Product_Simple( $id ); }
elseif ( 'WC_Product_Variation' === $class ) { $product = new \WC_Product_Variation( $id ); }
elseif ( 'WC_Product_Variable' === $class ) { $product = new \WC_Product_Variable( $id ); }
else { throw new Price_Validation_Error( 'unreadable_product_data' ); }
if ( ! $product instanceof \WC_Product || $product->get_id() !== $id ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
```

Cleanup order preserved: `clean_post_cache` → `wp_cache_delete($id, 'post_meta')` → `WC_Cache_Helper::invalidate_cache_group('product_'.$id)` → public type resolution. Non-product/mismatched-ID constructors and non-string classnames throw unreadable (never mislabeled absent).

## Step 4 — tests finished / adapted (no assertion weakened)

- `free/cache-public.php`: 72 assertions PASS; adds all three literal constructors, extension-class “never constructed” proof, `stdClass` / wrong-ID / `false`-classname refusals, suspended-invalidation no-partial-cleanup, and the shipped-includes namespace inventory.
- `free/variations.php`: 75 PASS (was 73). Non-core classes now remain explicit unreadable candidates in the SKU population and are asserted never-expanded + typed refusal; exact SKU readable match still exactly one.
- `free/dirty-catalog-stubs.php`, `free/in-container.sh` coherent; `php -l`/`sh -n` clean.

## Step 5 — durable fixture review (lead finding)

`durable/cache-public-integration.php` genuinely covers standalone Preview: a stale factory instance is primed and asserted stale (negative control), a real second-request public CRUD edit runs, then Preview executes **before any Admin/cache read** and must read the concurrent value. A later block removes all `clean_post_cache` actions and still asserts fresh Preview/Admin plus a stale-instance negative control. `durable/integration.php` replaces the private ProductCache set/get/remove with a public factory prime + `woocommerce_product_read` datastore-read probe (hook verified in 9.9.7/11.0.1/11.1.2). `durable/in-container.sh` now enables the instance-cache option for the probe and restores the prior installed value so the regular journal gate keeps its default on every pass. All durable files: `php -l` PASS, `sh -n` PASS, **execution NOT_TESTED** (Docker).

## Step 6 — no remaining shipped Internal dependency

`grep -rn "ProductCache\|Automattic\\\\WooCommerce\\\\Internal\|Internal\\\\Caches" wordpress/writeleash/` → **empty**. `grep -rn 'new \$' wordpress/writeleash/` → **empty**. `grep -rn wc_get_container wordpress/writeleash/` → **empty**. Receipt `B2-internal-grep.log`. Comments/docs in `class-free-support-contract.php` and `FREE-PRICE-APPLY-CONTRACT.md` were reworded to remove class-name strings. Writes, locks (`FOR UPDATE`), fences, transactions and verification logic are unchanged; production edits outside `fresh_product` are one-line read substitutions. Test-only `wordpress/tests/admin/integration.php:729` still primes the private cache to stage stale state (not shipped; out of lane scope, deliberately unmodified).

## Step 7 — full battery (final tree)

| Command | Result | Receipt |
|---|---|---|
| `php wordpress/tests/free/cache-public.php` | PASS — 72 assertions | B2-cache-public.log |
| `php wordpress/tests/free/unit.php` | PASS — 2,607 | B2-unit.log |
| `php wordpress/tests/free/sale-price.php` | PASS — 105 | B2-sale-price.log |
| `php wordpress/tests/free/variations.php` | PASS — 75 | B2-variations.log |
| `php wordpress/tests/admin/admin-audit.php wordpress/writeleash` | PASS | B2-admin-audit.log |
| `php wordpress/tests/jobs/no-replan-audit.php wordpress/writeleash` | PASS | B2-no-replan-audit.log |
| `php …/package-preflight.php wordpress/writeleash …distribution-files.txt` | PASS | B2-package-preflight.log |
| `php …/public-audit-cases.php …` | PASS | B2-public-audit-cases.log |
| `php …/inventory-audit.php …` | PASS | B2-inventory-audit.log |
| `php …/readme-validate.php wordpress/writeleash` | PASS | B2-readme-validate.log |
| `php …/historical-shim-cases.php wordpress/writeleash` | PASS | B2-historical-shim-cases.log |
| `php …/claim-matrix-audit.php .` / `source-audit.php` | PASS | B2-claim-matrix.log / B2-source-audit.log |
| `python3 .github/ci/ownership.py --audit` / `dependency-audit.py` | PASS | B2-ownership.log / B2-dependency-audit.log |
| PHP lint ×15 changed/new, `sh -n` ×2, `git diff --check` | PASS | inline |
| `bash .github/ci/pr-fast.sh` | **FAIL only at the known #170 gate** | B2-pr-fast.log |

PR_FAST exact first failure: `PHP Fatal error: Uncaught RuntimeException: #122 assets: #170 UI source differs from reviewed capture in .github/ci/asset-audit.php:6` (stack `asset-audit.php:100 → :6`). 21 PASS lines precede it; no assertion/evidence was edited.

## Step 8 — GitNexus gates

- Pre-edit `impact fresh_product --direction upstream` = **CRITICAL**: 11 direct callers — `resolve`, `parent_of`, `expand_variable_parents`, `observe`, `observe_variable_parent`, `sync_variable_parent`, `apply`, `restore`, `observe_undo`, `product_observation`, `variation_facts`; 23 impacted; 31 flows. Text search confirms the same call sites. Warning surfaced before editing.
- `Free_Support_Contract` (comment-only edit) upstream walk = **UNKNOWN/0** → unresolved, not an all-clear; text search confirms 51 usages; no semantic change.
- Index: prior index was PDG-mode; initial refresh without `--pdg` removed the layer, so it was rebuilt with `analyze --index-only --pdg` and re-verified (`B2-index-refresh-pdg.log`); `status` up-to-date, 355/355 files.
- `detect-changes --scope all` and `--scope staged` = complete: 15 files, 72 symbols, 31 flows, risk critical; no partial/truncated/error (`B2-detect-all.json`, `B2-detect-staged.json`). Post-commit and post-docs = “No changes detected” (`B2-detect-postcommit.json`, `B2-detect-postdocs.json`); `--scope compare --base-ref c48e307` = complete (`B2-detect-compare.json`).

## Step 9–11 — commits, evidence

- Commit gate passed on all four conditions; implementation commit `5684707`; docs commit `7df7baf`; no amend/force.
- Patch hashes: implementation `3af490ae…5bca`; full lane `9c17d5f5…ed8b` (`B2-final.sha256`).
- File hashes: `B2-file-hashes.txt` (16 files). Key production hashes: `class-product-snapshot.php 86647822…1c05`, `class-price-cache-verifier.php d0e60b72…27d7`, `class-product-selector.php 5faeebc9…6e01`, `class-free-admin.php 6084561d…bf3b`, `class-woo-price-mutator.php a4ed806f…0a83`, `class-woo-undo-mutator.php 85232b4c…45d7`, `class-free-support-contract.php 9723c2ff…be73`.
- Evidence package: `/tmp/writeleash-approved-20261008/evidence/agent-B/` (final report, all logs, detect receipts, patches, hashes).

## Deviations

- **Unsupported → unreadable classification** (explicit): extension/grouped/filtered/non-core resolved classes are refused by `fresh_product` as `unreadable_product_data`; at the selector this replaces `unsupported_product_type`, and at Apply/Undo the validation error maps to `FAILED` (PENDING) instead of `UNSUPPORTED_PRODUCT_STATE` (FAILED). Conservative: no write, no success, item stays visible; no extension class can become eligible (only three exact core names construct; the extension constructor is asserted never invoked; `core_simple`/`core_variation`/`core_data_store` checks unchanged).
- No other deviations.

## Integration notes

- A/B share `class-product-selector.php`: B edits only the three read sites (`resolve`/`parent_of`/`expand_variable_parents`); A’s category scope/bounds/dedup semantics are preserved. Integrate **B → A → C → D**, semantic hunk review, then re-run selection/cache/progress owners.
- `class-free-admin.php` is a method-level split: B = `product_observation` only; A = selection/post_input methods; C = boot/assets/progress/Undo hooks.
- Runtime limitations: Docker socket absent (`B2-docker-probe.log`); durable Woo/MySQL/MariaDB/Redis harness, per-version matrix, Redis profiles, cross-process fixture, browser/Admin rendering = **NOT_TESTED / BLOCKED**; no workaround attempted.
