<?php namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductFilters\Templates\Template;
use BulkPriceEditor\ProductQuery\ProductQuery;

abstract class Filter {
	
	protected array $rawFiltersData;
	protected ?Template $template;
	
	public function __construct( array $rawFiltersData, ?Template $template = null ) {
		$this->rawFiltersData = $rawFiltersData;
		$this->template       = $template;
	}
	
	public function setTemplate( ?Template $template ): void {
		$this->template = $template;
	}
	
	public function getSectionArgs(): array {
		return array(
			'open' => false,
		);
	}
	
	public function getSelectedFilters(): array {
		if ( ! empty( $this->rawFiltersData ) ) {
			return $this->rawFiltersData;
		}
		
		if ( $this->template ) {
			return $this->template->getFilters();
		}
		
		return array();
	}
	
	public function getFilterValue( $key, $default = null ) {
		return $this->getSelectedFilters()[ $key ] ?? $default;
	}
	
	protected function sanitizeArrayIds( $ids ): array {
		
		$ids = is_array( $ids ) ? $ids : array( $ids );
		
		return array_filter( array_map( 'intval', $ids ) );
	}
	
	abstract public function filterQuery( ProductQuery $query ): void;
	
	abstract public function getTitle();
	
	abstract public function renderFields( Widget $widget );
	
	public function isPremium(): bool {
		return false;
	}
}
