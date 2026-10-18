# #209 semantic deviations — exact evidence (combined candidate)

All references are to the combined candidate tree (`combined-review`, HEAD `7f47309740b4572fdf30dee8f333046b01b5a956`).
The version-conditional `Internal\Caches\ProductCache::remove()` calls were removed in all shipped paths. Because the unchanged release audit (`wordpress/tests/release/public-runtime-audit.php:108-111`) rejects any `new $var(...)` / `$var::` "dynamic class dependency", the replacement read boundary constructs only the three exact core classes literally and refuses every other resolved class **without instantiating it**.

## 1. `Product_Price_Snapshot::fresh_product` (final code, `class-product-snapshot.php:41-59`)

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
    if ( 'WC_Product_Simple' === $class ) { $product = new \WC_Product_Simple( $id ); }
    elseif ( 'WC_Product_Variation' === $class ) { $product = new \WC_Product_Variation( $id ); }
    elseif ( 'WC_Product_Variable' === $class ) { $product = new \WC_Product_Variable( $id ); }
    else { throw new Price_Validation_Error( 'unreadable_product_data' ); }
    if ( ! $product instanceof \WC_Product || $product->get_id() !== $id ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
    return $product;
}
```

Contract assertions (cache-public.php): extension class never constructed (`external209_constructed === false`), `stdClass`/wrong-ID mapped class refused, absent product still `false`, suspended invalidation performs no partial cleanup. 72/72 PASS.

## 2. Classification change (vs baseline `c48e307`)

| Surface | Baseline behavior | Combined behavior |
|---|---|---|
| Selector `resolve()` | `wc_get_product()` returned the extension object, later eligibility reason `unsupported_product_type` | `fresh_product` throws → catch at `class-product-selector.php:82-87` marks the ID explicitly unreadable; reason maps at `class-product-snapshot.php:183` to `unreadable_product_data` |
| `discover_count()` | n/a (new action, #206) | Counts unreadable entries explicitly; merchant message at `class-product-snapshot.php:225` |
| Apply | extension object → `core_data_store()` false → `Price_Apply_Error('UNSUPPORTED_PRODUCT_STATE')` → terminal `FAILED` | `Price_Validation_Error` → caught by `\Throwable` at `class-woo-price-mutator.php:166-167` → reason `FAILED` (not a `Price_Apply_Error`) → state mapping at line 185 puts `FAILED` in **PENDING** (retryable) |
| Undo | same as Apply via `UNSUPPORTED_PRODUCT_STATE` | same path at `class-woo-undo-mutator.php:230-231` → **UNDO_PENDING** per state mapping at ~250 |
| Verifier `verify()` | extension read returned object then class check → `CACHE_VERIFICATION_FAILED` | `fresh_product` throw → generic catch `class-price-cache-verifier.php:267-269` converts to `Price_Apply_Error('CACHE_VERIFICATION_FAILED')` — unchanged outcome |
| `observe_variable_parent()` | extension parent → class check → `CACHE_VERIFICATION_FAILED` | same, via the same conversion path |

**Net effect:** no write can occur for a non-core product (the refusal happens before any mutation; the constructor is never invoked), but a permanently unsupported extension product that was previously terminally `FAILED` is now retryable `FAILED`/`PENDING` instead of terminal. This is conservative about data integrity but is a merchant-visible state/classification change. It is the exact deviation the handoff anticipated ("If diagnostic classification of unsupported extension objects becomes unreadable instead of unsupported, report the exact deviation and verify no extension can become eligible"). Founder decision requested in `FINAL-REPORT.md` §7.2.

Evidence that extension refusal is preserved at the eligibility layer: `wordpress/tests/free/variations.php` now asserts the extension parent is one explicit unreadable candidate and its eligibility reason is the typed conservative refusal (`variations.php`, 75/75 PASS).

## 3. Doc-comment-only edits outside the frozen B manifest

Both files are comments-only; no constant, limit, or code line changed. `package-preflight` (43-file closure), `inventory-audit`, source audit and all support tests PASS with these edits.

### 3.1 `wordpress/writeleash/FREE-PRICE-APPLY-CONTRACT.md`

```diff
@@ -115,9 +115,9 @@
-- [Product factory](...class-wc-product-factory.php)
-  and [ProductCache](.../src/Internal/Caches/ProductCache.php):
-  optional object caching is evicted through `ProductCache::remove()`, not
+- [Product factory](...class-wc-product-factory.php):
+  the public type/classname lookup drives fresh reads, and optional object
+  caching is evicted through WordPress's public cache-clean action, not
   invented internal cache keys.
@@ -225,11 +225,13 @@
-- `ProductCache::remove(id)` also covers optional instance caching where the
-  class exists (Woo 10.5+); older supported releases skip this step. ...
+- Woo's optional product-instance caching (Woo 10.5+) is evicted by the public
+  WordPress cache-clean action Woo attaches, and fresh reads construct the
+  exact core product class directly instead of reusing a factory instance, so
+  older and newer supported releases behave identically. ...
```

### 3.2 `wordpress/writeleash/includes/free/class-free-support-contract.php`

```diff
@@ -10,11 +10,12 @@ final class Free_Support_Contract {
 	 * Supported WooCommerce range. ...
-	 * ... ProductCache::remove() (used for the optional instance cache)
-	 * exists since Woo 10.5 and is additionally guarded at the call site, so
-	 * the earlier 10.x releases in this span stay safe. ...
+	 * ... Fresh reads
+	 * construct the exact core product class directly and clean caches
+	 * through public WordPress/Woo helpers only, so no version-conditional
+	 * cache class is required anywhere in this span. ...
 	 */
 	public const WOOCOMMERCE_MIN = '10.0.0';
 	public const WOOCOMMERCE_MAX_EXCLUSIVE = '12.0.0';
```

Constants verified unchanged across baseline and combined: `WOOCOMMERCE_MIN='10.0.0'`, `WOOCOMMERCE_MAX_EXCLUSIVE='12.0.0'`, `MAX_JOB_PRODUCTS=1000`, `WORDPRESS_MIN='7.0'`, `WORDPRESS_MAX_EXCLUSIVE='8.0'`.

## 4. Residual test-only reference (not shipped, out of #209 runtime scope)

`wordpress/tests/admin/integration.php:729` still primes the private cache in a pre-existing test fixture (`wc_get_container()->get( ...ProductCache::class )->set( $old_product )`). It is not in the distribution manifest and is not part of the shipped runtime; #209's acceptance criterion covers shipped runtime dependencies only. Documented for transparency in `FINAL-REPORT.md` §6.
