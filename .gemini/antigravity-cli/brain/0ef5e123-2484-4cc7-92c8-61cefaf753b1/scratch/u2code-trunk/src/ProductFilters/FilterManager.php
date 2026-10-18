<?php namespace BulkPriceEditor\ProductFilters;

use BulkPriceEditor\ProductFilters\Filters\ExcludedProducts;
use BulkPriceEditor\ProductFilters\Filters\Filter;
use BulkPriceEditor\ProductFilters\Filters\FilterByAttributes;
use BulkPriceEditor\ProductFilters\Filters\DateRange;
use BulkPriceEditor\ProductFilters\Filters\FeaturedStatus;
use BulkPriceEditor\ProductFilters\Filters\FilterByBrands;
use BulkPriceEditor\ProductFilters\Filters\FilterByShippingClass;
use BulkPriceEditor\ProductFilters\Filters\FilterByTags;
use BulkPriceEditor\ProductFilters\Filters\IncludedProducts;
use BulkPriceEditor\ProductFilters\Filters\OnSaleStatus;
use BulkPriceEditor\ProductFilters\Filters\PriceRange;
use BulkPriceEditor\ProductFilters\Filters\ProductType;
use BulkPriceEditor\ProductFilters\Filters\StockStatus;
use BulkPriceEditor\ProductFilters\Filters\SKUAndName;
use BulkPriceEditor\ProductFilters\Templates\Template;
use BulkPriceEditor\ProductQuery\ProductQuery;

class FilterManager {

	protected array $rawFiltersData;

	protected array $filters = array();
	protected ?Template $template;

	protected static bool $titleQueryFilterRegistered = false;
	
	public function __construct( array $rawFiltersData = array(), ?Template $template = null ) {
		$this->rawFiltersData = $rawFiltersData;
		$this->template       = $template;
		
		$this->initFilters();
	}
	
	protected function initFilters() {

		$filters = array(
			IncludedProducts::class,
			ExcludedProducts::class,
		);

		if ( FilterByBrands::isSupported() ) {
			$filters[] = FilterByBrands::class;
		}

		$filters[] = FilterByTags::class;
		$filters[] = FilterByShippingClass::class;
		$filters[] = StockStatus::class;
		$filters[] = OnSaleStatus::class;
		$filters[] = FeaturedStatus::class;
		$filters[] = PriceRange::class;
		$filters[] = DateRange::class;
		$filters[] = ProductType::class;
		$filters[] = SKUAndName::class;
		$filters[] = FilterByAttributes::class;

		$this->filters = apply_filters( 'bulk_price_editor/query/filters', $filters );

		// Register once — a new FilterManager is built per request/widget.
		if ( ! self::$titleQueryFilterRegistered ) {
			self::$titleQueryFilterRegistered = true;

			add_filter( 'posts_where', function ( $where, $query ) {
				global $wpdb;

				if ( isset( $query->query['bpe_title_like'] ) ) {
					$term = $query->query['bpe_title_like'];

					// esc_like: a literal search — '%' and '_' are not wildcards here.
					$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s",
						'%' . $wpdb->esc_like( $term ) . '%' );
				}

				return $where;
			}, 10, 2 );
		}
	}
	
	/**
	 * Get the filters
	 *
	 * @return Filter[]
	 */
	public function getFilters(): array {
		return $this->filters;
	}
	
	public function filterQuery( ProductQuery $query ): ProductQuery {
		
		foreach ( $this->getFilters() as $filter ) {
			
			$filter = new $filter( $this->rawFiltersData, $this->template );
			
			$filter->filterQuery( $query );
		}
		
		return $query;
	}
}
