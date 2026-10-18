<?php namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Services\LookupService;
use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductQuery\ProductQuery;
use WC_Product_Variable;
use WP_Term;

class IncludedProducts extends Filter {

	/**
	 * When both products and categories are selected the result is their UNION
	 * ("these products plus everything in these categories"), built as a WHERE
	 * clause — WC_Product_Query would intersect them otherwise.
	 */
	const UNION_QUERY_VAR = 'bpe_included_union';

	protected static bool $unionQueryFilterRegistered = false;

	public function getTitle(): string {
		return __( 'Included Products', 'bulk-price-editor' );
	}
	
	public function getIncludedProducts( string $return = 'ids', bool $includeChild = false ): array {
		
		$productIds = $this->sanitizeArrayIds( $this->getFilterValue( 'bpe_included_product_ids', array() ) );
		
		if ( $includeChild ) {
			$productIds = $this->flattenArray( array_map( function ( $productId ) {
				$product = wc_get_product( $productId );
				
				if ( $product instanceof WC_Product_Variable ) {
					return array( $product->get_id(), ...$product->get_children() );
				}
				
				return $productId;
				
			}, $productIds ) );
		}
		
		if ( 'ids' === $return ) {
			return $productIds;
		}
		
		$products = array();
		
		foreach ( $this->sanitizeArrayIds( $productIds ) as $productId ) {
			$product = wc_get_product( $productId );
			
			if ( $product ) {
				$products[ $product->get_id() ] = $product->get_name() . ' (#' . $product->get_id() . ')';
			}
		}
		
		return $products;
	}
	
	public function getIncludedCategories( string $return = 'ids', bool $includeChild = false ): ?array {
		$categoriesIds = $this->sanitizeArrayIds( $this->getFilterValue( 'bpe_included_category_ids', array() ) );

		if ( $includeChild ) {
			$categoriesIds = $this->flattenArray( array_map( function ( $categoryId ) {
				$children = get_term_children( $categoryId, 'product_cat' );
				
				if ( is_array( $children ) ) {
					return array( $categoryId, ...$children );
				}
				
				return $categoryId;
			}, $categoriesIds ) );
		}
		
		if ( 'ids' === $return ) {
			return $categoriesIds;
		}
		
		$categories = array();
		
		foreach ( $categoriesIds as $categoryId ) {
			$category = get_term( $categoryId, 'product_cat' );
			
			if ( $category instanceof WP_Term ) {
				$categories[ $category->term_id ] = $category->name;
			}
		}
		
		return $categories;
	}
	
	public function filterQuery( ProductQuery $query ): void {

		$products   = $this->getIncludedProducts( 'ids', true );
		$categories = $this->getIncludedCategories( 'ids', true );

		if ( ! empty( $products ) && ! empty( $categories ) ) {

			$query->args[ self::UNION_QUERY_VAR ] = array(
				'product_ids' => $products,
				'tt_ids'      => $this->getCategoryTermTaxonomyIds( $categories ),
			);

			self::registerUnionQueryFilter();

			return;
		}

		if ( ! empty( $products ) ) {
			$query->args['include'] = $products;
		}

		if ( empty( $categories ) ) {
			return;
		}

		/*
		 * Categories only: matched through the variation-aware clause (same as
		 * brands/tags) — a plain tax_query would miss the variations of
		 * variable products, since variations carry no terms themselves.
		 */
		$clauses = isset( $query->args[ TermsFilter::QUERY_VAR ] ) ? (array) $query->args[ TermsFilter::QUERY_VAR ] : array();

		$clauses[] = array(
			'type'   => 'include',
			'tt_ids' => $this->getCategoryTermTaxonomyIds( $categories ),
		);

		$query->args[ TermsFilter::QUERY_VAR ] = $clauses;

		TermsFilter::registerQueryFilter();
	}

	protected function getCategoryTermTaxonomyIds( array $termIds ): array {
		return TermsFilter::termTaxonomyIds( 'product_cat', $termIds );
	}

	protected static function registerUnionQueryFilter(): void {

		if ( self::$unionQueryFilterRegistered ) {
			return;
		}

		self::$unionQueryFilterRegistered = true;

		add_filter( 'posts_where', function ( $where, $query ) {
			global $wpdb;

			$union = isset( $query->query[ self::UNION_QUERY_VAR ] ) ? (array) $query->query[ self::UNION_QUERY_VAR ] : null;

			if ( empty( $union ) ) {
				return $where;
			}

			$productIds = implode( ',', array_map( 'intval', ! empty( $union['product_ids'] ) ? (array) $union['product_ids'] : array( 0 ) ) );
			$ttIds      = implode( ',', array_map( 'intval', ! empty( $union['tt_ids'] ) ? (array) $union['tt_ids'] : array( 0 ) ) );

			// Variations carry no terms themselves — match via post_parent as well.
			$where .= " AND ( {$wpdb->posts}.ID IN ({$productIds})";
			$where .= " OR {$wpdb->posts}.ID IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$ttIds}) )";
			$where .= " OR {$wpdb->posts}.post_parent IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$ttIds}) ) )";

			return $where;
		}, 10, 2 );
	}
	
	public function getSectionArgs(): array {

		$hasTermSelections = false;

		foreach ( $this->getTermFilters() as $termFilter ) {
			if ( ! empty( $termFilter->getIncludedTerms() ) ) {
				$hasTermSelections = true;
				break;
			}
		}

		return array(
			'open' => ! empty( $this->getIncludedCategories() ) || ! empty( $this->getIncludedProducts() ) || $hasTermSelections,
		);
	}

	/**
	 * Term-based filters (brands, tags) whose include fields render in this section.
	 * Their query logic stays in the filter classes registered in FilterManager.
	 *
	 * @return TermsFilter[]
	 */
	protected function getTermFilters(): array {

		$filters = array();

		if ( FilterByBrands::isSupported() ) {
			$filters[] = new FilterByBrands( $this->rawFiltersData, $this->template );
		}

		$filters[] = new FilterByTags( $this->rawFiltersData, $this->template );
		$filters[] = new FilterByShippingClass( $this->rawFiltersData, $this->template );

		return $filters;
	}

	public function renderFields( Widget $widget ) {

		$widget->renderSelect2( array(
			'id'                => 'bpe_included_category_ids',
			'attributes'        => 'multiple',
			'label'             => __( 'Include product categories', 'bulk-price-editor' ),
			'placeholder'       => __( 'Select product categories', 'bulk-price-editor' ),
			'search_action'     => LookupService::CATEGORIES_SEARCH_ACTION,
			'value'             => $this->getIncludedCategories( 'array', false ),
			'custom_attributes' => array(
				'data-product-filter' => 'yes',
			),
		) );

		$widget->renderSelect2( array(
			'id'                => 'bpe_included_product_ids',
			'label'             => __( 'Include products', 'bulk-price-editor' ),
			'placeholder'       => __( 'Select products', 'bulk-price-editor' ),
			'search_action'     => 'woocommerce_json_search_products_and_variations',
			'value'             => $this->getIncludedProducts( 'array', false ),
			'custom_attributes' => array(
				'data-product-filter' => 'yes',
			),
		) );

		foreach ( $this->getTermFilters() as $termFilter ) {
			$termFilter->renderIncludeField( $widget );
		}
	}
	
	protected function flattenArray( array $array ): array {
		$result = [];
		foreach ( $array as $item ) {
			if ( is_array( $item ) ) {
				$result = array_merge( $result, $this->flattenArray( $item ) );
			} else {
				$result[] = $item;
			}
		}
		
		return $result;
	}
}
