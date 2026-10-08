<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

final class Price_Selection_Spec {
	use Immutable_Price_Value;
	private array $values;
	private function __construct( array $values ) { $this->values = $values; }
	public static function ids( array $ids ): self {
		return self::identity( $ids, array() );
	}
	/**
	 * Trusted preview-time factory for an IDS selection resolved from a
	 * requested selection (for example a selected variable parent expanded to
	 * its variations). The requested selection's warnings are preserved and
	 * the resolved IDs stay the exact frozen population.
	 */
	public static function resolved( array $ids, array $warnings ): self {
		foreach ( $warnings as $warning ) {
			if ( ! is_string( $warning ) || '' === $warning ) { throw new Price_Validation_Error( 'invalid_selection_size' ); }
		}
		return self::identity( $ids, array_values( $warnings ) );
	}
	private static function identity( array $ids, array $warnings ): self {
		if ( ! $ids || count( $ids ) > Product_Price_Selector::MAX_SELECTED ) { throw new Price_Validation_Error( 'invalid_selection_size' ); }
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) { throw new Price_Validation_Error( 'invalid_product_id' ); }
		}
		$unique = array_values( array_unique( $ids ) );
		sort( $unique, SORT_NUMERIC );
		if ( count( $unique ) !== count( $ids ) ) { $warnings[] = 'duplicate_selection'; }
		return new self( array( 'type' => 'IDS', 'ids' => $unique, 'warnings' => array_values( array_unique( $warnings ) ) ) );
	}
	public static function sku( string $sku ): self {
		if ( '' === $sku || strlen( $sku ) > 100 || preg_match( '/[\x00-\x20\x7f<>]/', $sku ) || '*' === $sku || ! preg_match( '//u', $sku ) ) { throw new Price_Validation_Error( 'invalid_sku' ); }
		return new self( array( 'type' => 'SKU', 'sku' => $sku, 'warnings' => array() ) );
	}
	public static function category( int $term_id ): self {
		if ( $term_id < 1 ) { throw new Price_Validation_Error( 'invalid_category' ); }
		return new self( array( 'type' => 'CATEGORY', 'term_id' => $term_id, 'include_children' => false, 'warnings' => array() ) );
	}
	public function data(): array { return $this->values; }
}

final class Product_Price_Selector {
	public const MAX_SELECTED = 1000;
	/** Public WP queries prime post/meta caches before Woo object reads. No price SQL. */
	public static function resolve( Price_Selection_Spec $spec ): array {
		Price_Store_Context::current();
		$s = $spec->data();
		$args = array(
			'post_type' => array( 'product', 'product_variation' ),
			'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'posts_per_page' => self::MAX_SELECTED + 1, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
			'update_post_meta_cache' => true, 'update_post_term_cache' => true,
		);
		if ( 'IDS' === $s['type'] ) { $args['post__in'] = $s['ids']; }
		elseif ( 'SKU' === $s['type'] ) {
			// Woo 11.1.2's sku argument uses LIKE, not exact equality. Use public WP API,
			// then byte-compare the edit-context Woo value to defeat DB collation aliases.
			$args['meta_query'] = array( array( 'key' => '_sku', 'value' => $s['sku'], 'compare' => '=' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		} else {
			$term = get_term( $s['term_id'], 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) { throw new Price_Validation_Error( 'invalid_category' ); }
			$args['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array( $s['term_id'] ), 'include_children' => false ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		$query = new \WP_Query( $args );
		if ( count( $query->posts ) > self::MAX_SELECTED ) { throw new Price_Validation_Error( 'selection_limit_exceeded' ); }
		$products = array();
		$unreadable = array();
		$unsupported_type = array();
		foreach ( $query->posts as $post ) {
			try {
				$product = Product_Price_Snapshot::fresh_product( (int) $post->ID );
				if ( 'SKU' === $s['type'] && ( ! $product instanceof \WC_Product || $product->get_sku( 'edit' ) !== $s['sku'] ) ) { continue; }
				$products[ $post->ID ] = $product instanceof \WC_Product ? $product : false;
			} catch ( \Throwable $error ) {
				// One malformed product stays in the frozen population instead of
				// aborting the plan or silently disappearing: a stable unsupported
				// product class keeps its terminal refusal, everything else stays
				// explicitly unreadable.
				if ( self::unsupported_type_error( $error ) ) {
					$unsupported_type[ $post->ID ] = (string) ( $post->post_title ?? '' );
				} else {
					$unreadable[ $post->ID ] = true;
				}
			}
		}
		if ( 'SKU' === $s['type'] && count( $products ) > 1 ) { throw new Price_Validation_Error( 'ambiguous_sku' ); }
		// #179: a selected core variable parent resolves to its exact variation
		// set at preview time. An advanced exact-SKU selection keeps its single
		// exact product semantics.
		$expanded = array();
		if ( 'SKU' !== $s['type'] ) {
			list( $products, $unreadable, $unsupported_type, $expanded ) = self::expand_variable_parents( $products, $unreadable, $unsupported_type );
		}
		if ( count( $products ) + count( $unreadable ) + count( $unsupported_type ) > self::MAX_SELECTED ) { throw new Price_Validation_Error( 'selection_limit_exceeded' ); }
		if ( 'IDS' === $s['type'] ) {
			$ids = array();
			foreach ( $s['ids'] as $requested ) {
				if ( ! empty( $expanded[ $requested ] ) ) {
					foreach ( $expanded[ $requested ] as $child_id ) { $ids[] = $child_id; }
				} else {
					$ids[] = $requested;
				}
			}
			$ids = array_values( array_unique( $ids ) );
			sort( $ids, SORT_NUMERIC );
		} else {
			$ids = array_merge( array_keys( $products ), array_keys( $unreadable ), array_keys( $unsupported_type ) );
			sort( $ids, SORT_NUMERIC );
		}
		$snapshots = array();
		foreach ( $ids as $id ) {
			if ( isset( $unsupported_type[ $id ] ) ) { $snapshots[] = Product_Price_Snapshot::unsupported_type( (int) $id, $unsupported_type[ $id ] ); continue; }
			if ( isset( $unreadable[ $id ] ) ) { $snapshots[] = Product_Price_Snapshot::unreadable( $id ); continue; }
			try {
				$snapshots[] = Product_Price_Snapshot::read( $id, $products[ $id ] ?? false, self::parent_of( $products[ $id ] ?? false ) );
			} catch ( \Throwable $error ) {
				$snapshots[] = Product_Price_Snapshot::unreadable( $id );
			}
		}
		return $snapshots;
	}
	/** The one stable refusal that must never be treated as a retryable read failure. */
	private static function unsupported_type_error( \Throwable $error ): bool {
		return $error instanceof Price_Validation_Error && 'unsupported_product_type' === $error->getMessage();
	}
	/** The exact resolved IDS selection a preview must freeze; other kinds keep their spec. */
	public static function resolved_selection( Price_Selection_Spec $spec, array $snapshots ): Price_Selection_Spec {
		if ( 'IDS' !== $spec->data()['type'] ) { return $spec; }
		$ids = array();
		foreach ( $snapshots as $snapshot ) { $ids[] = (int) $snapshot->data()['product_id']; }
		sort( $ids, SORT_NUMERIC );
		return Price_Selection_Spec::resolved( $ids, $spec->data()['warnings'] );
	}
	/** Only exact core variable parents expand; extension subclasses stay refused as-is. */
	private static function expandable_parent( $product ): bool {
		return is_object( $product ) && 'WC_Product_Variable' === get_class( $product ) && method_exists( $product, 'get_children' );
	}
	private static function parent_of( $product ) {
		if ( ! $product instanceof \WC_Product_Variation || ! function_exists( 'wc_get_product' ) ) { return null; }
		try {
			$parent_id = (int) $product->get_parent_id( 'edit' );
			return $parent_id > 0 ? Product_Price_Snapshot::fresh_product( $parent_id ) : null;
		} catch ( \Throwable $error ) { return null; }
	}
	/**
	 * Replace each readable core variable parent with its child variations
	 * (all children Keyed by child ID). A parent with no readable children
	 * stays itself so the merchant still sees one explained unsupported item.
	 * Non-core children keep the same stable-unsupported vs unreadable split
	 * as top-level reads.
	 */
	private static function expand_variable_parents( array $products, array $unreadable, array $unsupported_type ): array {
		$expanded_products = array();
		$expanded_unreadable = array();
		$expanded_unsupported = array();
		$expanded_children = array();
		foreach ( $products as $product_id => $product ) {
			if ( ! self::expandable_parent( $product ) ) {
				$expanded_products[ $product_id ] = $product;
				continue;
			}
			$children = array();
			try { $children = (array) $product->get_children(); }
			catch ( \Throwable $error ) { $children = array(); }
			$children = array_values( array_unique( array_map( 'intval', $children ) ) );
			$children = array_values( array_filter( $children, static fn( $child_id ) => $child_id > 0 && $child_id !== (int) $product_id ) );
			if ( ! $children ) {
				$expanded_products[ $product_id ] = $product;
				continue;
			}
			$expanded_children[ $product_id ] = $children;
			foreach ( $children as $child_id ) {
				if ( isset( $products[ $child_id ] ) || isset( $unreadable[ $child_id ] ) || isset( $unsupported_type[ $child_id ] ) ) { continue; }
				try {
					$child = Product_Price_Snapshot::fresh_product( $child_id );
					$expanded_products[ $child_id ] = $child instanceof \WC_Product ? $child : false;
				} catch ( \Throwable $error ) {
					if ( self::unsupported_type_error( $error ) ) {
						$expanded_unsupported[ $child_id ] = function_exists( 'get_the_title' ) ? (string) get_the_title( $child_id ) : '';
					} else {
						$expanded_unreadable[ $child_id ] = true;
					}
				}
			}
		}
		foreach ( $unreadable as $product_id => $flag ) { $expanded_unreadable[ $product_id ] = true; }
		foreach ( $unsupported_type as $product_id => $name ) { $expanded_unsupported[ $product_id ] = $name; }
		return array( $expanded_products, $expanded_unreadable, $expanded_unsupported, $expanded_children );
	}
}
