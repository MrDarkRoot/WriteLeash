<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** Read-only merchant discovery. Matches never become planner or worker authority. */
final class Product_Discovery {
	public const ACTION = 'writeleash_free_discovery';
	public const WINDOW = 10;
	public const MAX_PAGE = 50;
	public const MAX_TERM_BYTES = 100;

	public static function boot(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
	}

	public static function authorize(): void {
		if ( ! is_user_logged_in() || ! Free_Admin::can_mutate() ) {
			throw new Price_Validation_Error( 'permission_denied' );
		}
		if ( ! Free_Admin::dependency_ok() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed dependency reason code; not HTML output.
			throw new Price_Validation_Error( (string) Free_Support_Contract::woocommerce_reason() );
		}
	}

	private static function bounds( string $term, int $page ): string {
		self::authorize();
		if ( strlen( $term ) > self::MAX_TERM_BYTES || ! preg_match( '//u', $term ) || preg_match( '/[\x00-\x1f\x7f]/', $term ) || $page < 1 || $page > self::MAX_PAGE ) {
			throw new Price_Validation_Error( 'invalid_discovery' );
		}
		return trim( $term );
	}

	private static function product_label( \WC_Product $product ): array {
		$id = $product->get_id();
		$name = $product->get_name( 'edit' );
		$sku = $product->get_sku( 'edit' );
		$eligibility = Product_Price_Eligibility::evaluate( Product_Price_Snapshot::read( $id, $product ), Price_Store_Context::current() )->data();
		$reason = $eligibility['reason'];
		$text = ( '' === $name ? 'Unnamed product' : $name ) . ( '' === $sku ? ' · No SKU' : ' · SKU: ' . $sku ) . ' · ID: ' . $id;
		if ( null !== $reason ) {
			$text .= ' · Excluded: ' . ( Price_Reason_Messages::all()[$reason] ?? 'Not supported for price changes.' );
		}
		return array( 'id' => (string) $id, 'text' => $text );
	}

	/** Two capped WP queries: title discovery and literal partial SKU, never a broad saved selector. */
	public static function products( string $term, int $page = 1 ): array {
		$term = self::bounds( $term, $page );
		if ( '' === $term ) { return array( 'results' => array(), 'more' => false, 'capped' => false ); }
		$args = array(
			'post_type' => 'product', 'post_status' => 'publish',
			'posts_per_page' => self::WINDOW + 1, 'offset' => ( $page - 1 ) * self::WINDOW,
			'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
			'update_post_meta_cache' => true, 'update_post_term_cache' => true,
		);
		$title = new \WP_Query( array_merge( $args, array( 's' => $term, 'search_columns' => array( 'post_title' ) ) ) );
		$sku = new \WP_Query( array_merge( $args, array( 'meta_query' => array( array( 'key' => '_sku', 'value' => $term, 'compare' => 'LIKE' ) ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded discovery, not execution.
		$more = count( $title->posts ) > self::WINDOW || count( $sku->posts ) > self::WINDOW;
		$posts = array_merge( array_slice( $title->posts, 0, self::WINDOW ), array_slice( $sku->posts, 0, self::WINDOW ) );
		$results = array();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( 'read_post', $post->ID ) ) { continue; }
			$product = wc_get_product( $post->ID );
			if ( $product instanceof \WC_Product ) { $results[$post->ID] = self::product_label( $product ); }
		}
		ksort( $results, SORT_NUMERIC );
		return array( 'results' => array_values( $results ), 'more' => $more && $page < self::MAX_PAGE, 'capped' => $more && self::MAX_PAGE === $page );
	}

	/** IDs are untrusted input; resolve only caller-readable/editable published products. */
	public static function selected( array $ids ): array {
		self::authorize();
		if ( count( $ids ) > Free_Support_Contract::MAX_JOB_PRODUCTS ) { throw new Price_Validation_Error( 'invalid_selection_size' ); }
		foreach ( $ids as $id ) { if ( ! is_int( $id ) || $id < 1 ) { throw new Price_Validation_Error( 'invalid_product_id' ); } }
		if ( ! $ids ) { return array(); }
		$posts = new \WP_Query( array(
			'post_type' => 'product', 'post_status' => 'publish', 'post__in' => $ids,
			'posts_per_page' => Free_Support_Contract::MAX_JOB_PRODUCTS, 'no_found_rows' => true,
			'update_post_meta_cache' => true, 'update_post_term_cache' => true,
		) );
		$results = array();
		foreach ( $posts->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( 'read_post', $post->ID ) ) { continue; }
			$product = wc_get_product( $post->ID );
			if ( $product instanceof \WC_Product ) { $results[$post->ID] = self::product_label( $product ); }
		}
		if ( count( $results ) !== count( $ids ) ) { throw new Price_Validation_Error( 'permission_denied' ); }
		return $results;
	}

	private static function category_label( \WP_Term $term ): array {
		$names = array( $term->name );
		$parent = (int) $term->parent;
		$seen = array( $term->term_id => true );
		// A damaged/cyclic or unusually deep taxonomy cannot cause an unbounded walk.
		for ( $depth = 0; $parent && $depth < 20; ++$depth ) {
			if ( isset( $seen[$parent] ) ) { break; }
			$seen[$parent] = true;
			$ancestor = get_term( $parent, 'product_cat' );
			if ( ! $ancestor instanceof \WP_Term ) { break; }
			array_unshift( $names, $ancestor->name );
			$parent = (int) $ancestor->parent;
		}
		if ( $parent ) { array_unshift( $names, '…' ); }
		return array( 'id' => (string) $term->term_id, 'text' => implode( ' › ', $names ) . ' · Category ID: ' . $term->term_id );
	}

	public static function category( int $id ): ?array {
		self::authorize();
		$term = get_term( $id, 'product_cat' );
		return $term instanceof \WP_Term ? self::category_label( $term ) : null;
	}

	public static function categories( string $term = '', int $page = 1 ): array {
		$term = self::bounds( $term, $page );
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'hierarchical' => false, 'search' => $term, 'number' => 21, 'offset' => ( $page - 1 ) * 20, 'orderby' => 'term_id', 'order' => 'ASC' ) );
		if ( is_wp_error( $terms ) ) { throw new Price_Validation_Error( 'discovery_unavailable' ); }
		$more = count( $terms ) > 20;
		$results = array_map( array( __CLASS__, 'category_label' ), array_slice( $terms, 0, 20 ) );
		return array( 'results' => $results, 'more' => $more && $page < self::MAX_PAGE, 'capped' => $more && self::MAX_PAGE === $page );
	}

	/** Testable transport core. Nonce is additional session protection, never authorization. */
	public static function request( array $input, string $method ): array {
		try {
			self::authorize();
			if ( 'GET' !== $method || ! isset( $input['nonce'] ) || ! is_string( $input['nonce'] ) || ! wp_verify_nonce( $input['nonce'], self::ACTION ) ) {
				throw new Price_Validation_Error( 'invalid_nonce' );
			}
			$term = $input['term'] ?? '';
			$page = $input['page'] ?? '1';
			if ( ! is_string( $term ) || ! is_string( $page ) || ! preg_match( '/\A[0-9]{1,2}\z/', $page ) ) { throw new Price_Validation_Error( 'invalid_discovery' ); }
			$kind = $input['kind'] ?? 'products';
			if ( ! in_array( $kind, array( 'products', 'categories' ), true ) ) { throw new Price_Validation_Error( 'invalid_discovery' ); }
			$data = 'categories' === $kind ? self::categories( $term, (int) $page ) : self::products( $term, (int) $page );
			return array( 'status' => 'OK', 'data' => $data );
		} catch ( \Throwable $error ) {
			return array( 'status' => 'INVALID', 'reason' => $error instanceof Price_Validation_Error ? $error->reason() : 'discovery_unavailable' );
		}
	}

	public static function ajax(): void {
		$input = array();
		foreach ( array( 'nonce', 'term', 'page', 'kind' ) as $key ) { $input[$key] = filter_input( INPUT_GET, $key ); }
		$result = self::request( $input, Free_Admin::request_method() );
		if ( 'OK' === $result['status'] ) { wp_send_json_success( $result['data'] ); }
		wp_send_json_error( array( 'message' => Free_Admin::reason_message( $result['reason'] ) ), 403 );
	}
}
