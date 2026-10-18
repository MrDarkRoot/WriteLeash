<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** All additional rollback/recovery eviction lives here; no global cache flush. */
final class Price_Cache_Verifier {
	public static function invalidate( int $id ): void {
		// Cache eviction correctness never depended on the WooCommerce
		// release line, only on Woo being active: refuse a dead dependency
		// without pinning a version.
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_delete_product_transients' ) ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		if ( ! empty( $GLOBALS['_wp_suspend_cache_invalidation'] ) ) { throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' ); }
		clean_post_cache( $id ); // posts, post_meta, term cache and posts last_changed.
		wp_cache_delete( $id, 'post_meta' );
		wp_cache_delete( 'lookup_table', 'object_' . $id );
		wc_delete_product_transients( $id );
		\WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
		// clean_post_cache() also dispatches the public WordPress cache-clean
		// hook. WooCommerce attaches its optional product-instance eviction
		// there; extensions must not reach into Woo's private cache container.
		// Independent storage/Woo verification below still refuses stale reads.
	}
	/** A new independent autocommit connection through the normal WP identity. */
	public static function observer(): \wpdb {
		$db = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$db->set_prefix( $GLOBALS['wpdb']->prefix );
		$db->wc_product_meta_lookup = $db->prefix . 'wc_product_meta_lookup';
		if ( ! $db->dbh instanceof \mysqli || $db->dbh === $GLOBALS['wpdb']->dbh ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		return $db;
	}
	/**
	 * The exact core data stores this transaction class certifies: a simple
	 * product's CPT store and a variation's CPT store. Extension product
	 * classes are refused even when they reuse a core store.
	 */
	public static function core_data_store( \WC_Product $product ): bool {
		$store = $product->get_data_store();
		if ( ! $store ) { return false; }
		return in_array( $store->get_current_class_name(), array( 'WC_Product_Data_Store_CPT', 'WC_Product_Variation_Data_Store_CPT' ), true );
	}
	/**
	 * Woo's own variable-parent range computation: the sorted DISTINCT
	 * non-empty child `_price` strings; min is first, max is last. This
	 * mirrors WC_Product_Variable_Data_Store_CPT::sync_price + lookup.
	 */
	public static function parent_range( array $prices ): array {
		$distinct = array();
		foreach ( $prices as $price ) {
			if ( null === $price ) { continue; }
			$price = (string) $price;
			if ( '' === $price ) { continue; }
			if ( ! in_array( $price, $distinct, true ) ) { $distinct[] = $price; }
		}
		sort( $distinct, SORT_NUMERIC );
		return array( 'min' => $distinct ? $distinct[0] : null, 'max' => $distinct ? $distinct[ count( $distinct ) - 1 ] : null );
	}
	/**
	 * After WC_Product_Variable::sync() recompute the parent range from the
	 * visible children's `_price` values and refuse divergence from the
	 * parent lookup row (typed LOOKUP_MISMATCH => review, zero commit).
	 */
	public static function assert_parent_range( \wpdb $db, int $parent_id, array $visible_children ): array {
		if ( $parent_id < 1 ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
		$lookup = $db->get_row( $db->prepare( "SELECT min_price,max_price,onsale FROM {$db->wc_product_meta_lookup} WHERE product_id=%d", $parent_id ), ARRAY_A );
		if ( ! $lookup ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
		$prices = array();
		if ( $visible_children ) {
			$placeholders = implode( ',', array_fill( 0, count( $visible_children ), '%d' ) );
			$prices = $db->get_col( $db->prepare( "SELECT DISTINCT meta_value FROM {$db->postmeta} WHERE meta_key='_price' AND post_id IN ($placeholders)", $visible_children ) );
		}
		$range = self::parent_range( (array) $prices );
		if ( null === $range['min'] ) {
			if ( ! self::parent_range_empty( $lookup['min_price'] ?? null ) || ! self::parent_range_empty( $lookup['max_price'] ?? null ) ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
		} elseif ( ! Price_Decimal::equal( (string) ( $lookup['min_price'] ?? '' ), $range['min'] ) || ! Price_Decimal::equal( (string) ( $lookup['max_price'] ?? '' ), $range['max'] ) ) {
			throw new Price_Apply_Error( 'LOOKUP_MISMATCH' );
		}
		// sync_price deletes the parent sale meta, so a variable parent is never onsale.
		if ( '0' !== (string) ( $lookup['onsale'] ?? '' ) ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
		return $range;
	}
	/** An empty parent range may be stored as NULL, empty text or numeric zero. */
	private static function parent_range_empty( $value ): bool {
		if ( null === $value || '' === $value ) { return true; }
		try { return '0' === Price_Decimal::parse( (string) $value ); }
		catch ( \Throwable $error ) { return false; }
	}
	/**
	 * Refresh a variation's parent through Woo's public sync on the caller's
	 * pinned transaction connection, then certify the parent lookup range
	 * against the visible children. Shared by Apply and Undo.
	 */
	public static function sync_variable_parent( \wpdb $db, int $parent_id ): void {
		if ( $parent_id < 1 ) { throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' ); }
		// Serialize sibling variation mutations on the shared parent row.
		$post = $db->get_row( $db->prepare( "SELECT ID FROM {$db->posts} WHERE ID=%d FOR UPDATE", $parent_id ) );
		if ( ! $post ) { throw new Price_Apply_Error( 'CONFLICT' ); }
		self::invalidate( $parent_id );
		try { $parent = Product_Price_Snapshot::fresh_product( $parent_id ); }
		catch ( Price_Validation_Error $error ) {
			// A stable unsupported parent class restores the pre-change exact-class
			// outcome: a concurrent product-state CONFLICT. A transient read
			// failure is rethrown and stays retryable FAILED at the execution edge.
			if ( 'unsupported_product_type' !== $error->getMessage() ) { throw $error; }
			throw new Price_Apply_Error( 'CONFLICT' );
		}
		// A parent that is no longer a core variable product is a concurrent
		// product-state conflict, never an environment/state corruption.
		if ( ! $parent || 'WC_Product_Variable' !== get_class( $parent ) ) { throw new Price_Apply_Error( 'CONFLICT' ); }
		\WC_Product_Variable::sync( $parent );
		self::assert_parent_range( $db, $parent_id, (array) $parent->get_visible_children() );
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
	/** The shopper-active price implied by stored values (sale if valid and active, else regular). */
	public static function active_price( string $regular, string $sale, $from, $to, int $now ): string {
		$regular = Price_Decimal::parse( $regular );
		if ( '' !== $sale ) {
			$sale = Price_Decimal::parse( $sale );
			$from_at = self::timestamp( $from );
			$to_at = self::timestamp( $to );
			if ( Price_Decimal::compare( Price_Decimal::units( $regular ), Price_Decimal::units( $sale ) ) > 0
				&& ( null === $from_at || $from_at <= $now )
				&& ( null === $to_at || $to_at >= $now ) ) {
				return $sale;
			}
		}
		return $regular;
	}
	/** Non-empty timestamps must be integer strings; anything else is unsupported storage. */
	private static function timestamp( $value ): ?int {
		if ( null === $value || '' === $value ) { return null; }
		if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]{1,12}\z/D', $value ) ) { throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' ); }
		return (int) $value;
	}
	/** `_price`, lookup min/max and onsale must agree with the computed active price. */
	private static function assert_active( array $storage, string $active, string $sale ): void {
		if ( Price_Decimal::parse( $storage['meta']['_price'][0] ) !== $active ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
		$lookup = $storage['lookup'];
		foreach ( array( 'min_price', 'max_price' ) as $key ) {
			try { $actual = Price_Decimal::parse( $lookup[$key] ); } catch ( \Throwable $e ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
			if ( $actual !== $active ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
		}
		// WooCommerce's lookup sets onsale from PHP string truthiness after
		// wc_format_decimal(): the literal '0' is falsy, '0.00' is not.
		$onsale = ( '' !== $sale && '0' !== $sale && Price_Decimal::parse( $sale ) === $active ) ? '1' : '0';
		if ( (string) ( $lookup['onsale'] ?? '' ) !== $onsale ) { throw new Price_Apply_Error( 'LOOKUP_MISMATCH' ); }
	}
	/** Self-consistency of the stored regular/sale/date/_price/lookup state. Returns the active price. */
	public static function coherence( array $storage ): string {
		$regular = $storage['meta']['_regular_price'][0];
		$sale = $storage['meta']['_sale_price'][0] ?? '';
		if ( '' !== $sale ) {
			try { Price_Decimal::parse( $sale ); }
			catch ( Price_Validation_Error $error ) { throw new Price_Apply_Error( 'UNSUPPORTED_PRODUCT_STATE' ); }
		}
		$active = self::active_price( $regular, $sale, $storage['meta']['_sale_price_dates_from'][0] ?? '', $storage['meta']['_sale_price_dates_to'][0] ?? '', time() );
		self::assert_active( $storage, $active, $sale );
		return $active;
	}
	/**
	 * Field-aware verification of one plan/Undo write. The target field must
	 * equal the target; the other field and the sale dates must equal the
	 * preserved values; `_price`, lookup and onsale must match the computed
	 * active price. `$preserved` uses raw meta values:
	 * regular_price, sale_price, sale_from, sale_to (empty string = unset).
	 */
	public static function matches_field( array $storage, string $field, string $target, array $preserved ): void {
		Price_Operation::assert_field( $field );
		$regular = Price_Operation::FIELD_SALE === $field ? (string) ( $preserved['regular_price'] ?? '' ) : $target;
		$sale = Price_Operation::FIELD_SALE === $field ? $target : (string) ( $preserved['sale_price'] ?? '' );
		if ( '' === $regular ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
		if ( ! Price_Decimal::equal( $storage['meta']['_regular_price'][0], $regular ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
		if ( ! Price_Decimal::equal( $storage['meta']['_sale_price'][0] ?? '', $sale ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
		$from = (string) ( $preserved['sale_from'] ?? ( $storage['meta']['_sale_price_dates_from'][0] ?? '' ) );
		$to = (string) ( $preserved['sale_to'] ?? ( $storage['meta']['_sale_price_dates_to'][0] ?? '' ) );
		if ( self::timestamp( $storage['meta']['_sale_price_dates_from'][0] ?? '' ) !== self::timestamp( $from )
			|| self::timestamp( $storage['meta']['_sale_price_dates_to'][0] ?? '' ) !== self::timestamp( $to ) ) {
			throw new Price_Apply_Error( 'JOURNAL_MISMATCH' );
		}
		self::assert_active( $storage, self::active_price( $regular, $sale, $from, $to, time() ), $sale );
	}
	/** DB truth first; Woo reads use a NEW object and independent DB connection. */
	public static function observe( Change_Plan $plan, int $id ): array {
		$db = self::observer();
		$original = $GLOBALS['wpdb'];
		try {
			$row = Price_Apply_Journal::read( $db, $plan->data()['plan_id'], $id );
			$item = $plan->item( $id )->data();
			if ( ! $row || 'APPLIED' !== $row['state'] || ! $row['applied_at'] || ! $row['attempt_id'] ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			Price_Apply_Journal::assert_binding( $row, $plan, $id );
			$evidence = json_decode( $row['evidence'], true );
			if ( ! is_array( $evidence ) || ( $evidence['plan_id'] ?? '' ) !== $row['plan_id'] || ( $evidence['plan_schema_version'] ?? 0 ) !== (int) $row['plan_schema_version'] || ( $evidence['plan_hash_version'] ?? '' ) !== $row['plan_hash_version'] || ( $evidence['attempt_id'] ?? '' ) !== $row['attempt_id'] || ( $evidence['plan_hash'] ?? '' ) !== $row['plan_hash'] || ( $evidence['target'] ?? '' ) !== $row['target_price'] || ( $evidence['product_id'] ?? 0 ) !== $id || ( $evidence['connection_id'] ?? 0 ) < 1 ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			$field = $evidence['price_field'] ?? null;
			if ( null !== $field && ! in_array( $field, Price_Operation::FIELDS, true ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			if ( null === $field ) {
				foreach ( array( 'regular', 'active', 'lookup_min', 'lookup_max' ) as $key ) {
					try { $match = isset( $evidence[$key] ) && Price_Decimal::parse( $evidence[$key] ) === Price_Decimal::parse( $row['target_price'] ); }
					catch ( \Throwable $error ) { $match = false; }
					if ( ! $match ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
				}
			} else {
				foreach ( array( 'field_value', 'regular', 'sale', 'active', 'lookup_min', 'lookup_max' ) as $key ) {
					if ( ! isset( $evidence[$key] ) || ! is_string( $evidence[$key] ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
				}
				if ( ! isset( $evidence['onsale'] ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
				if ( ! Price_Decimal::equal( $evidence['field_value'], $row['target_price'] )
					|| ! Price_Decimal::equal( Price_Operation::FIELD_SALE === $field ? $evidence['sale'] : $evidence['regular'], $row['target_price'] ) ) {
					throw new Price_Apply_Error( 'JOURNAL_MISMATCH' );
				}
			}
			$truth = self::storage( $db, $id );
			$core_variation = ! empty( $item['snapshot']['core_variation'] );
			if ( $core_variation ) {
				if ( (int) ( $evidence['parent_id'] ?? 0 ) !== (int) ( $item['snapshot']['parent_id'] ?? 0 ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			}
			if ( null === $field ) {
				self::matches( $truth, $item['planned_regular_price'] );
			} else {
				self::matches_field( $truth, $field, $row['target_price'], array( 'regular_price' => $evidence['regular'], 'sale_price' => $evidence['sale'] ) );
				if ( ! Price_Decimal::equal( $truth['meta']['_price'][0], $evidence['active'] )
					|| ! Price_Decimal::equal( (string) $truth['lookup']['min_price'], $evidence['lookup_min'] )
					|| ! Price_Decimal::equal( (string) $truth['lookup']['max_price'], $evidence['lookup_max'] )
					|| (string) ( $truth['lookup']['onsale'] ?? '' ) !== (string) $evidence['onsale'] ) {
					throw new Price_Apply_Error( 'JOURNAL_MISMATCH' );
				}
			}
			$GLOBALS['wpdb'] = $db;
			self::invalidate( $id );
			$matches = static function ( $product, $core_variation, $field, $row, $evidence ): bool {
				if ( ! $product || ( $core_variation ? 'WC_Product_Variation' : 'WC_Product_Simple' ) !== get_class( $product ) || 'publish' !== $product->get_status( 'edit' ) ) { return false; }
				if ( null === $field ) {
					return ! ( '' !== $product->get_sale_price( 'edit' ) || $product->get_date_on_sale_from( 'edit' ) || $product->get_date_on_sale_to( 'edit' ) || Price_Decimal::parse( $product->get_regular_price( 'edit' ) ) !== Price_Decimal::parse( $row['target_price'] ) || Price_Decimal::parse( $product->get_price( 'edit' ) ) !== Price_Decimal::parse( $row['target_price'] ) );
				}
				$fresh_regular = (string) $product->get_regular_price( 'edit' );
				$fresh_sale = (string) $product->get_sale_price( 'edit' );
				$fresh_field = Price_Operation::FIELD_SALE === $field ? $fresh_sale : $fresh_regular;
				return Price_Decimal::equal( $fresh_regular, $evidence['regular'] )
					&& Price_Decimal::equal( $fresh_sale, $evidence['sale'] )
					&& Price_Decimal::equal( $fresh_field, $row['target_price'] )
					&& Price_Decimal::equal( (string) $product->get_price( 'edit' ), $evidence['active'] );
			};
			$product = Product_Price_Snapshot::fresh_product( $id );
			if ( ! $matches( $product, $core_variation, $field, $row, $evidence ) ) {
				// The independent storage read above already matched the committed
				// journal evidence. A mismatch here is a per-request object/meta cache
				// still serving a pre-write value: evict it and reconstruct exactly
				// once. A persistent mismatch still fails closed.
				wp_cache_delete( $id, 'post_meta' );
				wp_cache_delete( $id, 'posts' );
				if ( function_exists( 'wp_cache_delete_multiple' ) ) {
					wp_cache_delete_multiple( array( $id ), 'post_meta' );
					wp_cache_delete_multiple( array( $id ), 'posts' );
				}
				\WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
				$product = Product_Price_Snapshot::fresh_product( $id );
				if ( ! $matches( $product, $core_variation, $field, $row, $evidence ) ) { throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' ); }
			}
			// The variation's own storefront row is certified; the parent range
			// must also reflect Woo's post-sync visible-child computation.
			if ( $core_variation ) { self::observe_variable_parent( $db, (int) $item['snapshot']['parent_id'] ); }
			return array( 'journal' => $row, 'storage' => $truth, 'observer_connection' => (int) $db->dbh->thread_id );
		} catch ( \Throwable $error ) {
			if ( $error instanceof Price_Apply_Error ) { throw $error; }
			throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' );
		} finally { $GLOBALS['wpdb'] = $original; $db->close(); }
	}
	/** Live parent certification on the observer connection; Woo reads use it too. */
	public static function observe_variable_parent( \wpdb $db, int $parent_id ): void {
		if ( $parent_id < 1 ) { throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' ); }
		self::invalidate( $parent_id );
		$parent = Product_Price_Snapshot::fresh_product( $parent_id );
		if ( ! $parent || 'WC_Product_Variable' !== get_class( $parent ) ) { throw new Price_Apply_Error( 'CACHE_VERIFICATION_FAILED' ); }
		self::assert_parent_range( $db, $parent_id, (array) $parent->get_visible_children() );
	}
}
