<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

final class Price_Selection_Spec {
	use Immutable_Price_Value;
	private array $values;
	private function __construct( array $values ) { $this->values = $values; }
	public static function ids( array $ids, ?Price_Range_Filter $range = null ): self {
		return self::identity( $ids, array(), $range );
	}
	/**
	 * Trusted preview-time factory for an IDS selection resolved from a
	 * requested selection (for example a selected variable parent expanded to
	 * its variations). The requested selection's warnings and price-range
	 * filter are preserved and the resolved IDs stay the exact frozen
	 * population. An enabled range filter may exclude every requested ID;
	 * the empty population still freezes so Preview explains the match
	 * instead of refusing it. Direct merchant IDS input still requires at
	 * least one ID (see identity()).
	 */
	public static function resolved( array $ids, array $warnings, ?Price_Range_Filter $range = null ): self {
		foreach ( $warnings as $warning ) {
			if ( ! is_string( $warning ) || '' === $warning ) { throw new Price_Validation_Error( 'invalid_selection_size' ); }
		}
		if ( ! $ids ) {
			return new self( array( 'type' => 'IDS', 'ids' => array(), 'warnings' => array_values( array_unique( $warnings ) ), 'price_range' => ( $range ?? Price_Range_Filter::disabled() )->data() ) );
		}
		return self::identity( $ids, array_values( $warnings ), $range );
	}
	private static function identity( array $ids, array $warnings, ?Price_Range_Filter $range = null ): self {
		if ( ! $ids || count( $ids ) > Product_Price_Selector::MAX_SELECTED ) { throw new Price_Validation_Error( 'invalid_selection_size' ); }
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) { throw new Price_Validation_Error( 'invalid_product_id' ); }
		}
		$unique = array_values( array_unique( $ids ) );
		sort( $unique, SORT_NUMERIC );
		if ( count( $unique ) !== count( $ids ) ) { $warnings[] = 'duplicate_selection'; }
		return new self( array( 'type' => 'IDS', 'ids' => $unique, 'warnings' => array_values( array_unique( $warnings ) ), 'price_range' => ( $range ?? Price_Range_Filter::disabled() )->data() ) );
	}
	public static function sku( string $sku, ?Price_Range_Filter $range = null ): self {
		if ( '' === $sku || strlen( $sku ) > 100 || preg_match( '/[\x00-\x20\x7f<>]/', $sku ) || '*' === $sku || ! preg_match( '//u', $sku ) ) { throw new Price_Validation_Error( 'invalid_sku' ); }
		return new self( array( 'type' => 'SKU', 'sku' => $sku, 'warnings' => array(), 'price_range' => ( $range ?? Price_Range_Filter::disabled() )->data() ) );
	}
	public static function category( int $term_id, bool $include_children = false, ?Price_Range_Filter $range = null ): self {
		if ( $term_id < 1 ) { throw new Price_Validation_Error( 'invalid_category' ); }
		return new self( array( 'type' => 'CATEGORY', 'term_id' => $term_id, 'include_children' => $include_children, 'warnings' => array(), 'price_range' => ( $range ?? Price_Range_Filter::disabled() )->data() ) );
	}
	public function data(): array { return $this->values; }
	/**
	 * The attached #234 range filter. A missing key (a spec shape from
	 * before the filter existed) reads as disabled; a present but malformed
	 * filter is refused rather than silently broadened.
	 */
	public function price_range(): array {
		$stored = $this->values['price_range'] ?? null;
		if ( null === $stored ) { return Price_Range_Filter::disabled()->data(); }
		if ( ! is_array( $stored ) ) { throw new Price_Validation_Error( 'invalid_price_range' ); }
		return Price_Range_Filter::from_data( $stored )->data();
	}
}

final class Product_Price_Selector {
	public const MAX_SELECTED = 1000;
	/**
	 * Public WP queries prime post/meta caches before Woo object reads. No price SQL.
	 *
	 * Returns the frozen matched snapshots: with an enabled #234 range
	 * filter, only final targets satisfying the inclusive range. Use
	 * resolve_with_outcome() when the range counts or the complete
	 * pre-filter population (for authorization) is needed.
	 */
	public static function resolve( Price_Selection_Spec $spec ): array {
		return self::apply_price_range( $spec, self::resolve_snapshots( $spec ) )['snapshots'];
	}
	/**
	 * #234 full resolution: the frozen matched snapshots, the range outcome
	 * counts and the complete pre-filter population.
	 *
	 * The population is complete-or-refused by the bounded discovery below,
	 * so filtering it is exact: no silent truncation, no wider query, no
	 * hidden targets. Authorization callers must inspect `population` (the
	 * whole source), never just the matched subset, so an excluded product
	 * can never become a price/count oracle.
	 *
	 * @return array{snapshots: Product_Price_Snapshot[], outcome: array{matched: int, excluded_by_range: int, unsupported: int}, population: Product_Price_Snapshot[]}
	 */
	public static function resolve_with_outcome( Price_Selection_Spec $spec ): array {
		$population = self::resolve_snapshots( $spec );
		return self::apply_price_range( $spec, $population ) + array( 'population' => $population );
	}
	/** The bounded discovery-to-snapshot pipeline; range-agnostic. */
	private static function resolve_snapshots( Price_Selection_Spec $spec ): array {
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
			$args['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array( $s['term_id'] ), 'include_children' => $s['include_children'] ?? false ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		// A supported variation has one core parent. At most 1000 final targets
		// can therefore include 1000 extra parent rows before expansion. Keep
		// discovery bounded without rejecting parent + directly categorized child overlap.
		$raw_limit = 'CATEGORY' === $s['type'] ? 2 * self::MAX_SELECTED : self::MAX_SELECTED;
		$args['posts_per_page'] = $raw_limit + 1;
		$query = self::bounded_discovery_query( $args, 'CATEGORY' === $s['type'] );
		// WP_Query does not add SQL DISTINCT for tax_query joins, so a post in
		// several overlapping descendant terms is returned once per matching
		// join. Key rows by post ID before the raw-bound check and before any
		// Woo read: duplicate rows must not inflate the count or be resolved
		// twice. Sufficiency: every distinct raw post is either a final target
		// or the core parent of at least one expanded variation; distinct core
		// parents own disjoint child sets, so the parent count never exceeds
		// the final-target count and the distinct raw population is at most
		// 2 x final targets <= 2,000 for <=1,000 final targets. Any distinct
		// raw population above 2,000 therefore implies more than 1,000 final
		// targets, so the sentinel refusal is never a refusal of an otherwise
		// valid selection.
		$raw_posts = array();
		foreach ( $query->posts as $post ) { $raw_posts[ (int) $post->ID ] = $post; }
		if ( 'CATEGORY' === $s['type'] ) {
			// Fail-closed completeness. The sentinel above can bound the
			// population only when the raw page provably holds every matching
			// row. Two observable rewrites break that: a filter rewrote the
			// bounded window (posts_per_page no longer equals the requested
			// sentinel), or the page is full and carries duplicate join rows,
			// so the SQL LIMIT may have truncated distinct posts before the
			// PHP keying could see them. Duplicates on a short (not-full) page
			// are safe: LIMIT was not reached, every matching row was returned
			// and keying is exact. In an environment where DISTINCT cannot be
			// guaranteed (a cache, plugin or filter stripped it) this refuses
			// some otherwise-valid selections by design instead of resolving a
			// possibly incomplete population.
			$observed_rows = count( $query->posts );
			$window = (int) $query->get( 'posts_per_page' );
			if ( $window !== $raw_limit + 1 || ( $observed_rows > count( $raw_posts ) && $observed_rows >= $window ) ) {
				throw new Price_Validation_Error( 'selection_limit_exceeded' );
			}
		}
		if ( count( $raw_posts ) > $raw_limit ) { throw new Price_Validation_Error( 'selection_limit_exceeded' ); }
		$products = array();
		$unreadable = array();
		$unsupported_type = array();
		foreach ( $raw_posts as $post ) {
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
			$ids = array_values( array_unique( array_merge( array_keys( $products ), array_keys( $unreadable ), array_keys( $unsupported_type ) ) ) );
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
	/**
	 * Partition fresh snapshots through the spec's range filter.
	 * Unreadable/missing rows (no snapshot to compare) are `unsupported`.
	 * Among readable rows the filter verdict decides: `matched` rows freeze,
	 * `excluded` rows count as excluded_by_range, and `unevaluable` rows
	 * (blank regular price or malformed stored price, both
	 * domain-unsupported) count as unsupported. Membership is predicate-only;
	 * eligibility typing of matched items happens later in the plan.
	 *
	 * @return array{snapshots: Product_Price_Snapshot[], outcome: array{matched: int, excluded_by_range: int, unsupported: int}}
	 */
	private static function apply_price_range( Price_Selection_Spec $spec, array $snapshots ): array {
		$filter = Price_Range_Filter::from_data( $spec->price_range() );
		$outcome = array( 'matched' => 0, 'excluded_by_range' => 0, 'unsupported' => 0 );
		if ( ! $filter->data()['enabled'] ) {
			$outcome['matched'] = count( $snapshots );
			return array( 'snapshots' => $snapshots, 'outcome' => $outcome );
		}
		$matched = array();
		foreach ( $snapshots as $snapshot ) {
			$row = $snapshot->data();
			if ( empty( $row['exists'] ) || ! empty( $row['unreadable'] ) ) { ++$outcome['unsupported']; continue; }
			$verdict = $filter->verdict( $row );
			if ( 'matched' === $verdict ) { $matched[] = $snapshot; ++$outcome['matched']; continue; }
			if ( 'unevaluable' === $verdict ) { ++$outcome['unsupported']; continue; }
			++$outcome['excluded_by_range'];
		}
		return array( 'snapshots' => $matched, 'outcome' => $outcome );
	}
	/**
	 * Run the bounded discovery query. The tax_query join returns one row per
	 * matching descendant term, so a CATEGORY query asks the database for
	 * DISTINCT to make the posts_per_page sentinel count distinct posts. The
	 * posts_distinct guard is scoped to this exact query through a private
	 * token query var: an unrelated or nested query built while the guard is
	 * registered (for example from pre_get_posts) keeps its own clause. The
	 * guard is removed in finally so both normal and exceptional exits leave
	 * no global query change behind. A minimal harness without the WP filter
	 * API falls back to the plain query; resolve() then fails closed when the
	 * page shows duplicate rows it may have truncated. Rows are keyed by ID
	 * in resolve() so the bound is exact whenever completeness holds.
	 */
	private static function bounded_discovery_query( array $args, bool $distinct ): \WP_Query {
		if ( ! $distinct || ! function_exists( 'add_filter' ) || ! function_exists( 'remove_filter' ) ) {
			return new \WP_Query( $args );
		}
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'writeleash_discovery_', true );
		$args['writeleash_discovery_token'] = $token;
		$guard = static function ( $requested, $query ) use ( $token ) {
			if ( $query instanceof \WP_Query && $token === $query->get( 'writeleash_discovery_token' ) ) { return 'DISTINCT'; }
			return $requested;
		};
		add_filter( 'posts_distinct', $guard, 10, 2 );
		try { return new \WP_Query( $args ); }
		finally { remove_filter( 'posts_distinct', $guard, 10 ); }
	}
	/**
	 * Informational read only. Preview independently resolves and freezes its
	 * own population.
	 *
	 * With an enabled #234 range filter, `selected` counts only the matched
	 * targets while `unreadable`/`missing` still describe the whole source
	 * population; `excluded_by_range` counts healthy readable targets outside
	 * the range (including blank sale prices under a sale basis) and `range`
	 * echoes the frozen filter. Readable targets that cannot supply a
	 * comparable basis price stay inside the review provenance's unsupported
	 * count. Authorization is checked over the complete pre-filter population
	 * first, so the counts can never leak prices of products the actor cannot
	 * inspect.
	 */
	public static function discover_count( Price_Selection_Spec $spec ): array {
		if ( ! get_current_user_id() || ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_products' ) ) { throw new Price_Validation_Error( 'permission_denied' ); }
		$resolved = self::resolve_with_outcome( $spec );
		$snapshots = $resolved['snapshots'];
		$unreadable = 0;
		$missing = 0;
		foreach ( $resolved['population'] as $snapshot ) {
			$row = $snapshot->data();
			// Do not disclose even a count for a population this actor cannot inspect.
			if ( ! current_user_can( 'edit_post', $row['product_id'] ) ) { throw new Price_Validation_Error( 'permission_denied' ); }
			if ( ! empty( $row['unreadable'] ) ) { ++$unreadable; }
			elseif ( ! $row['exists'] ) { ++$missing; }
		}
		$count = array( 'selected' => count( $snapshots ), 'unreadable' => $unreadable, 'missing' => $missing );
		if ( $spec->price_range()['enabled'] ) {
			$count['excluded_by_range'] = $resolved['outcome']['excluded_by_range'];
			$count['range'] = $spec->price_range();
		}
		return $count;
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
		return Price_Selection_Spec::resolved( $ids, $spec->data()['warnings'], Price_Range_Filter::from_data( $spec->price_range() ) );
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
				if ( isset( $products[ $child_id ] ) || isset( $unreadable[ $child_id ] ) || isset( $unsupported_type[ $child_id ] ) || array_key_exists( $child_id, $expanded_products ) || isset( $expanded_unreadable[ $child_id ] ) || isset( $expanded_unsupported[ $child_id ] ) ) { continue; }
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
				// Stop at the sentinel instead of reading every variation in an oversized parent.
				if ( count( $expanded_products ) + count( $expanded_unreadable ) > self::MAX_SELECTED ) { throw new Price_Validation_Error( 'selection_limit_exceeded' ); }
			}
		}
		foreach ( $unreadable as $product_id => $flag ) { $expanded_unreadable[ $product_id ] = true; }
		foreach ( $unsupported_type as $product_id => $name ) { $expanded_unsupported[ $product_id ] = $name; }
		return array( $expanded_products, $expanded_unreadable, $expanded_unsupported, $expanded_children );
	}
}
