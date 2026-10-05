<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

final class Price_Selection_Spec {
	use Immutable_Price_Value;
	private array $values;
	private function __construct( array $values ) { $this->values = $values; }
	public static function ids( array $ids ): self {
		if ( ! $ids || count( $ids ) > Product_Price_Selector::MAX_SELECTED ) { throw new Price_Validation_Error( 'invalid_selection_size' ); }
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) { throw new Price_Validation_Error( 'invalid_product_id' ); }
		}
		$unique = array_values( array_unique( $ids ) );
		sort( $unique, SORT_NUMERIC );
		return new self( array( 'type' => 'IDS', 'ids' => $unique, 'warnings' => count( $unique ) !== count( $ids ) ? array( 'duplicate_selection' ) : array() ) );
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
		foreach ( $query->posts as $post ) {
			try {
				$product = wc_get_product( $post->ID );
				if ( 'SKU' === $s['type'] && ( ! $product instanceof \WC_Product || $product->get_sku( 'edit' ) !== $s['sku'] ) ) { continue; }
				$products[ $post->ID ] = $product instanceof \WC_Product ? $product : false;
			} catch ( \Throwable $error ) {
				// One malformed product stays in the frozen population as explicitly
				// unreadable instead of aborting the plan or silently disappearing.
				$unreadable[ $post->ID ] = true;
			}
		}
		if ( 'SKU' === $s['type'] && count( $products ) > 1 ) { throw new Price_Validation_Error( 'ambiguous_sku' ); }
		$ids = 'IDS' === $s['type'] ? $s['ids'] : array_merge( array_keys( $products ), array_keys( $unreadable ) );
		sort( $ids, SORT_NUMERIC );
		$snapshots = array();
		foreach ( $ids as $id ) {
			if ( isset( $unreadable[ $id ] ) ) { $snapshots[] = Product_Price_Snapshot::unreadable( $id ); continue; }
			try {
				$snapshots[] = Product_Price_Snapshot::read( $id, $products[ $id ] ?? false );
			} catch ( \Throwable $error ) {
				$snapshots[] = Product_Price_Snapshot::unreadable( $id );
			}
		}
		return $snapshots;
	}
}
