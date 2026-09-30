<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** All additional rollback/recovery eviction lives here; no global cache flush. */
final class Price_Cache_Verifier {
	public static function invalidate( int $id ): void {
		if ( ! empty( $GLOBALS['_wp_suspend_cache_invalidation'] ) ) { throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' ); }
		clean_post_cache( $id ); // posts, post_meta, term cache and posts last_changed.
		wp_cache_delete( $id, 'post_meta' );
		// Exact Woo 11.1.2 WC_Data_Store_WP::update_lookup_table key/group.
		wp_cache_delete( 'lookup_table', 'object_' . $id );
		wc_delete_product_transients( $id );
		\WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
		// Version-gated by the attempt, including optional product instance cache.
		wc_get_container()->get( \Automattic\WooCommerce\Internal\Caches\ProductCache::class )->remove( $id );
	}
	/** A new independent autocommit connection through the normal WP identity. */
	public static function observer(): \wpdb {
		$db = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$db->set_prefix( $GLOBALS['wpdb']->prefix );
		$db->wc_product_meta_lookup = $db->prefix . 'wc_product_meta_lookup';
		if ( ! $db->dbh instanceof \mysqli || $db->dbh === $GLOBALS['wpdb']->dbh ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		return $db;
	}
	public static function storage( \wpdb $db, int $id, bool $lock = false ): array {
		$rows = $db->get_results( $db->prepare( "SELECT meta_key,meta_value FROM {$db->postmeta} WHERE post_id=%d ORDER BY meta_id" . ( $lock ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );
		$meta = array();
		foreach ( $rows as $row ) { $meta[$row['meta_key']][] = $row['meta_value']; }
		foreach ( array( '_regular_price', '_price' ) as $key ) {
			if ( 1 !== count( $meta[$key] ?? array() ) ) { throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' ); }
			try { Price_Decimal::parse( $meta[$key][0] ); } catch ( Price_Validation_Error $e ) { throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' ); }
		}
		foreach ( array( '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to' ) as $key ) {
			if ( count( $meta[$key] ?? array() ) > 1 ) { throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' ); }
		}
		$lookup_rows = $db->get_results( $db->prepare( "SELECT * FROM {$db->wc_product_meta_lookup} WHERE product_id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );
		if ( 1 !== count( $lookup_rows ) ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
		return array( 'meta' => $meta, 'lookup' => $lookup_rows[0] );
	}
	public static function matches( array $storage, string $price ): void {
		$price = Price_Decimal::parse( $price );
		foreach ( array( '_regular_price', '_price' ) as $key ) {
			if ( Price_Decimal::parse( $storage['meta'][$key][0] ) !== $price ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
		}
		$lookup = $storage['lookup'];
		if ( ! $lookup || '0' !== (string) $lookup['onsale'] ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
		foreach ( array( 'min_price', 'max_price' ) as $key ) {
			try { $actual = Price_Decimal::parse( $lookup[$key] ); } catch ( \Throwable $e ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
			if ( $actual !== $price ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
		}
	}
	/** DB truth first; Woo reads use a NEW object and independent DB connection. */
	public static function observe( Change_Plan $plan, int $id ): array {
		$db = self::observer();
		$original = $GLOBALS['wpdb'];
		try {
			$row = Price_Apply_Journal::read( $db, $plan->hash(), $id );
			$item = $plan->item( $id )->data();
			if ( ! $row || 'APPLIED' !== $row['state'] || $row['plan_json'] !== $plan->json() || $row['expected_price'] !== $item['expected_regular_price'] || $row['target_price'] !== $item['planned_regular_price'] || ! $row['applied_at'] || ! $row['attempt_id'] ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			$evidence = json_decode( $row['evidence'], true );
			if ( ! is_array( $evidence ) || ( $evidence['attempt_id'] ?? '' ) !== $row['attempt_id'] || ( $evidence['plan_hash'] ?? '' ) !== $plan->hash() || ( $evidence['target'] ?? '' ) !== $row['target_price'] || ( $evidence['product_id'] ?? 0 ) !== $id || ( $evidence['connection_id'] ?? 0 ) < 1 ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			foreach ( array( 'regular', 'active', 'lookup_min', 'lookup_max' ) as $key ) {
				try { $match = isset( $evidence[$key] ) && Price_Decimal::parse( $evidence[$key] ) === Price_Decimal::parse( $row['target_price'] ); }
				catch ( \Throwable $error ) { $match = false; }
				if ( ! $match ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			}
			$truth = self::storage( $db, $id );
			self::matches( $truth, $item['planned_regular_price'] );
			$GLOBALS['wpdb'] = $db;
			self::invalidate( $id );
			$product = wc_get_product( $id );
			if ( ! $product || 'WC_Product_Simple' !== get_class( $product ) || 'publish' !== $product->get_status( 'edit' ) || '' !== $product->get_sale_price( 'edit' ) || $product->get_date_on_sale_from( 'edit' ) || $product->get_date_on_sale_to( 'edit' ) || Price_Decimal::parse( $product->get_regular_price( 'edit' ) ) !== Price_Decimal::parse( $row['target_price'] ) || Price_Decimal::parse( $product->get_price( 'edit' ) ) !== Price_Decimal::parse( $row['target_price'] ) ) { throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' ); }
			return array( 'journal' => $row, 'storage' => $truth, 'observer_connection' => (int) $db->dbh->thread_id );
		} catch ( \Throwable $error ) {
			if ( $error instanceof Price_Apply_Error ) { throw $error; }
			throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' );
		} finally { $GLOBALS['wpdb'] = $original; $db->close(); }
	}
}
