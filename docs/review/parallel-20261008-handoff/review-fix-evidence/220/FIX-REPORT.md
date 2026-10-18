# PR #220 P0 review-fix report — issue #209

Clone: `/tmp/writeleash-approved-20261008/agent-B` · branch `work/209-public-cache`
Base `c48e307efe7750d0fb36015c39d50a34675f9579` · previous head `7df7baf0f61f9226d99c622345de656381f207fe`
New commit / pushed head: `7496874eb0b48989814234afb703d17a5563ace0` (`fix(cache): restore terminal refusal for stable unsupported product classes (#209)`)
Push: `git push origin work/209-public-cache` → `7df7baf..7496874` (fast-forward, no force). PR #220 still draft; body updated (gh pr edit hit the Projects-classic GraphQL deprecation; applied via REST `gh api --method PATCH repos/MrDarkRoot/WriteLeash/pulls/220 -F body=@…`, verified).

## Finding status: RESOLVED

The reviewed implementation turned the previously terminal unsupported outcome into a retryable (PENDING) one via `unreadable_product_data`.
Trace through planning, Apply, job state, Resume, History, Undo:

| Stage | Stable unsupported class | Transient unreadable |
|---|---|---|
| Planning | `unsupported_type` snapshot (exists=true, unreadable=false) → eligibility `unsupported_product_type` → plan item `UNSUPPORTED`, never CHANGING | `unreadable` snapshot → `unreadable_product_data`, needs-attention, not CHANGING |
| Apply pre-check | `Price_Apply_Error('UNSUPPORTED_PRODUCT_STATE')` → code `UNSUPPORTED_PRODUCT_STATE`, journal `FAILED` → Job item `FAILED` (terminal, no retry) | rethrown `Price_Validation_Error` → `FAILED` → journal `PENDING`, code `FAILED` (bounded retry) |
| Undo entry | same conversion → `UNSUPPORTED_PRODUCT_STATE` / `FAILED` | same rethrow → `FAILED` → `PENDING` |
| Undo recovery read | `CACHE_VERIFICATION_FAILED` → `NEEDS_REVIEW` | rethrow → outer catch → `CACHE_VERIFICATION_FAILED` |
| sync_variable_parent (apply/undo) | `CONFLICT` (pre-change exact-class outcome restored) | rethrow → retryable `FAILED` |
| Resume | `Job_Item_State::FAILED` has no outgoing transitions → never retried | `PENDING` eligible for retry |
| History | merchant copy for `UNSUPPORTED_PRODUCT_STATE` unchanged; plan reason `unsupported_product_type` message unchanged | unchanged |

No state-mapping array, conflict handling, verification strength, or write path changed. CONFLICT/NEEDS_REVIEW never reset; APPLIED never inferred from target equality.

## Exact final `fresh_product` classification

```php
public static function fresh_product( int $id ) {
    if ( $id < 1 ) { throw new Price_Validation_Error( 'invalid_product_id' ); }
    if ( ! function_exists( 'wc_get_product' ) || ! class_exists( '\WC_Product_Factory' ) || ! class_exists( '\WC_Cache_Helper' ) || ! empty( $GLOBALS['_wp_suspend_cache_invalidation'] ) ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
    clean_post_cache( $id );
    wp_cache_delete( $id, 'post_meta' );
    \WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
    $type = \WC_Product_Factory::get_product_type( $id );
    if ( ! $type ) { return false; }
    $class = \WC_Product_Factory::get_product_classname( $id, $type );
    if ( ! is_string( $class ) ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
    if ( 'WC_Product_Simple' !== $class && 'WC_Product_Variation' !== $class && 'WC_Product_Variable' !== $class ) {
        if ( is_a( $class, '\WC_Product', true ) ) { throw new Price_Validation_Error( 'unsupported_product_type' ); }
        throw new Price_Validation_Error( 'unreadable_product_data' );
    }
    try {
        if ( 'WC_Product_Simple' === $class ) { $product = new \WC_Product_Simple( $id ); }
        elseif ( 'WC_Product_Variation' === $class ) { $product = new \WC_Product_Variation( $id ); }
        else { $product = new \WC_Product_Variable( $id ); }
    } catch ( \Throwable $error ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
    if ( ! $product instanceof \WC_Product || $product->get_id() !== $id ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
    return $product;
}
```

Reconciliation note: a non-`WC_Product` string (`stdClass`) is a broken mapping, not a product type → `unreadable_product_data` (satisfies the required test case). A `WC_Product` subclass outside the three core classes → terminal `unsupported_product_type`, never instantiated. No `new $var`.

## Per-call-site mapping (baseline `c48e307` verified per site)

| Call site | unsupported_product_type now | transient unreadable now | baseline outcome |
|---|---|---|---|
| `Woo_Price_Mutator::apply` pre-check | `Price_Apply_Error('UNSUPPORTED_PRODUCT_STATE')` | rethrow → `FAILED` → PENDING | `core_data_store` false → UNSUPPORTED_PRODUCT_STATE |
| `Woo_Undo_Mutator::restore` entry | `Price_Apply_Error('UNSUPPORTED_PRODUCT_STATE')` | rethrow | same |
| `Woo_Undo_Mutator::observe_undo` read | `Price_Apply_Error('CACHE_VERIFICATION_FAILED')` | rethrow → catch-all → CACHE_VERIFICATION_FAILED | exact-class comparison → CACHE_VERIFICATION_FAILED |
| `Price_Cache_Verifier::sync_variable_parent` (ADDITIONAL restoration) | `Price_Apply_Error('CONFLICT')` | rethrow | exact-class comparison → CONFLICT |
| `Price_Cache_Verifier::observe` | catch-all → CACHE_VERIFICATION_FAILED (unchanged) | same | exact class check |
| `Price_Cache_Verifier::observe_variable_parent` | propagates to its two callers (`observe` / `observe_undo`) whose catch-all converts (unchanged) | same | exact class check / callers |
| Free_Admin `product_observation` | catch-all → `Unavailable` (unchanged) | same | — |
| snapshot `variation_facts` parent read | catch Throwable → null (unchanged) | same | — |
| selector `resolve` / `expand_variable_parents` | `unsupported_type` collection → unsupported snapshot | `unreadable` collection | extension snapshot → eligibility `unsupported_product_type` |
| selector `parent_of` | catch Throwable → null (unchanged) | same | parent core flag false |

## Files changed (8)

```
wordpress/writeleash/includes/free/class-product-snapshot.php
wordpress/writeleash/includes/free/class-product-selector.php
wordpress/writeleash/includes/free/class-woo-price-mutator.php
wordpress/writeleash/includes/free/class-woo-undo-mutator.php
wordpress/writeleash/includes/free/class-price-cache-verifier.php
wordpress/tests/free/cache-public.php
wordpress/tests/free/variations.php
wordpress/tests/durable/cache-public-integration.php
```

## Test deltas

- `free/cache-public.php` (72 → 73): extension class → `unsupported_product_type` (never constructed); `stdClass` and non-product classname → `unreadable_product_data`; core class constructor-result mismatch via ID override → `unreadable_product_data`; suspended invalidation and absent unchanged. No assertion weakened.
- `free/variations.php` (75): extension variable parent eligibility restored to `unsupported_product_type`; snapshot `exists/unreadable` now `1/`; SKU admission filter changed from `exists` to `Eligibility::...state === ELIGIBLE`; byte-match population assertion (11, 40, 50, 51, 52) unchanged; exactly one admissible variation.
- `durable/cache-public-integration.php` (Docker-only): new Apply-time cases — live class mapping → `WC_Product_External` gives code `UNSUPPORTED_PRODUCT_STATE`, reason `UNSUPPORTED_PRODUCT_STATE`, durable journal `FAILED`, no write; `stdClass` classname gives `FAILED`/`FAILED`, durable `PENDING`, no write. Fixture keeps public-API-only style. Runtime NOT_TESTED (Docker unavailable). Pure mutator-mapping coverage: durable/Docker NOT_TESTED.

## Commands / results

All PASS except the expected #170 gate (logs in this directory):
`cache-public` 73 · `unit` 2,607 · `sale-price` 105 · `variations` 75 · `admin-audit` · `no-replan-audit` · `package-preflight` · `inventory-audit` · `public-audit-cases` · `readme-validate` · `historical-shim-cases` · `claim-matrix` · `source-audit` · `ownership --audit` · `dependency-audit` · `test-artifact` · PHP lint (8/8) · JS `node --check` · shell `bash -n`/`sh -n` · `git diff --check` (clean).
GitNexus: impacts run pre-edit (`fresh_product` CRITICAL 11 direct; `resolve` LOW; `expand_variable_parents` LOW; `apply` HIGH lower-bound with 1 production caller confirmed by text search; `restore` HIGH; `observe_undo` HIGH; `sync_variable_parent` CRITICAL 2). `detect-changes --scope all` and `--scope staged`: 8 files / 22 symbols / 35 flows, risk critical, no partial/truncated; post-commit clean.
`bash .github/ci/pr-fast.sh`: exit 255, 22 PASS lines then first and only failure `#122 assets: #170 UI source differs from reviewed capture` at `.github/ci/asset-audit.php:6`. `asset-audit-cases.php` fails identically at the pre-existing head (`asset-audit-cases-preexisting.log`). No evidence/assertion/fingerprint edited.

## Problem B verification (report only)

- Shipped `Internal` references incl. escaped strings: none; `new $`: none; `wc_get_container`: none; global cache flush: none.
- Targeted invalidation only (`clean_post_cache`, post-meta key, lookup key, `wc_delete_product_transients`, `invalidate_cache_group('product_<id>')`).
- Test-only residual: `wordpress/tests/admin/integration.php:729` primes Woo's private `ProductCache` to stage stale state (not shipped; deliberately untouched).

## Blockers / deviations

- Blockers: #170 UI evidence gate (pre-existing on this branch, caused by the prior implementation commit touching `class-free-admin.php`); Docker runtime durable/Woo/Redis/browser NOT_TESTED.
- Deviations requiring founder approval: none. The prior deviation is resolved. Implementation notes: (1) non-`WC_Product` resolved strings stay unreadable while `WC_Product` subclasses are terminal; (2) `sync_variable_parent` is an additional restoration site required to preserve CONFLICT.
- Support-claim impact: none (`WOOCOMMERCE_MIN`/`MAX_EXCLUSIVE` unchanged).
- No merge/deploy/publish/issue closure; issue #209 untouched. No rebase/force-push/amend; PR remains draft.
