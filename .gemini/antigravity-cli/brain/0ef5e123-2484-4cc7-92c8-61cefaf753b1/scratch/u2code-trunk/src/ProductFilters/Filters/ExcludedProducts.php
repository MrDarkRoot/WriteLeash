<?php namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Services\LookupService;
use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductQuery\ProductQuery;
use WC_Product_Variable;
use WP_Term;

class ExcludedProducts extends Filter {
	
	public function getTitle(): string {
		return __( 'Excluded Products', 'bulk-price-editor' );
	}
	
	public function getExcludedProducts( string $return = 'ids', bool $includeChild = false ): array {
		
		$productIds = $this->sanitizeArrayIds( $this->getFilterValue( 'bpe_excluded_product_ids', array() ) );
		
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
	
	public function getExcludedCategories( string $return = 'ids', bool $includeChild = false ): ?array {
		$categoriesIds = $this->sanitizeArrayIds( $this->getFilterValue( 'bpe_excluded_category_ids', array() ) );
		
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
		
		$products = $this->getExcludedProducts( 'ids', true );
		
		if ( ! empty( $products ) ) {
			$query->args['exclude'] = $products;
		}
		
		$categories = $this->getExcludedCategories( 'ids', true );

		if ( empty( $categories ) ) {
			return;
		}

		/*
		 * Variation-aware exclusion (same as brands/tags): a plain NOT IN
		 * tax_query would keep the variations of variable products from the
		 * excluded categories, since variations carry no terms themselves.
		 */
		$clauses = isset( $query->args[ TermsFilter::QUERY_VAR ] ) ? (array) $query->args[ TermsFilter::QUERY_VAR ] : array();

		$clauses[] = array(
			'type'   => 'exclude',
			'tt_ids' => TermsFilter::termTaxonomyIds( 'product_cat', $categories ),
		);

		$query->args[ TermsFilter::QUERY_VAR ] = $clauses;

		TermsFilter::registerQueryFilter();
	}
	
	/**
	 * Term-based filters (brands, tags) whose exclude fields render in this section.
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
			'id'                => 'bpe_excluded_category_ids',
			'attributes'        => 'multiple',
			'label'             => __( 'Exclude product categories', 'bulk-price-editor' ),
			'placeholder'       => __( 'Select product categories', 'bulk-price-editor' ),
			'search_action'     => LookupService::CATEGORIES_SEARCH_ACTION,
			'value'             => $this->getExcludedCategories( 'array', false ),
			'custom_attributes' => array(
				'data-product-filter' => 'yes',
			),
		) );

		$widget->renderSelect2( array(
			'id'                => 'bpe_excluded_product_ids',
			'label'             => __( 'Exclude products', 'bulk-price-editor' ),
			'placeholder'       => __( 'Select products', 'bulk-price-editor' ),
			'search_action'     => 'woocommerce_json_search_products_and_variations',
			'value'             => $this->getExcludedProducts( 'array', false ),
			'custom_attributes' => array(
				'data-product-filter' => 'yes',
			),
		) );

		foreach ( $this->getTermFilters() as $termFilter ) {
			$termFilter->renderExcludeField( $widget );
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
	
	public function getSectionArgs(): array {

		$hasTermSelections = false;

		foreach ( $this->getTermFilters() as $termFilter ) {
			if ( ! empty( $termFilter->getExcludedTerms() ) ) {
				$hasTermSelections = true;
				break;
			}
		}

		return array(
			'open' => ! empty( $this->getExcludedProducts() ) || ! empty( $this->getExcludedCategories() ) || $hasTermSelections,
		);
	}
}
