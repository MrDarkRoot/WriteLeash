<?php namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductQuery\ProductQuery;

/**
 * Widget-side section grouping the status radio filters (stock, sale,
 * featured). Their query logic stays in the individual filter classes
 * registered in FilterManager — this class only renders them together.
 */
class ProductStatus extends Filter {

	public function getTitle(): string {
		return __( 'Product Status', 'bulk-price-editor' );
	}

	/**
	 * @return Filter[]
	 */
	protected function getStatusFilters(): array {
		return array(
			new StockStatus( $this->rawFiltersData, $this->template ),
			new OnSaleStatus( $this->rawFiltersData, $this->template ),
			new FeaturedStatus( $this->rawFiltersData, $this->template ),
		);
	}

	public function filterQuery( ProductQuery $query ): void {}

	public function getSectionArgs(): array {

		foreach ( $this->getStatusFilters() as $filter ) {
			if ( ! empty( $filter->getSectionArgs()['open'] ) ) {
				return array( 'open' => true );
			}
		}

		return array( 'open' => false );
	}

	public function renderFields( Widget $widget ) {
		foreach ( $this->getStatusFilters() as $filter ) {
			$filter->renderFields( $widget );
		}
	}

	public function isPremium(): bool {
		return true;
	}
}
