<?php namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\BulkPriceEditorPlugin;
use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductQuery\ProductQuery;

class ProductType extends Filter {
	
	public function getProductTypes(): ?array {
		return $this->getFilterValue( 'bpe_product_type', BulkPriceEditorPlugin::getDefaultProductTypes() );
	}
	
	public function filterQuery( ProductQuery $query ): void {
		
		if ( empty( $this->getProductTypes() ) ) {
			return;
		}
		
		$query->args['type'] = $this->getProductTypes();
	}
	
	public function renderFields( Widget $widget ) {
		
		$widget->renderCheckboxGroup( array(
			'id'                => 'bpe_product_type',
			'label'             => __( 'Product type', 'bulk-price-editor' ),
			'options'           => BulkPriceEditorPlugin::getSupportedProductTypes(),
			'value'             => $this->getProductTypes(),
			'custom_attributes' => array(
				'data-product-filter' => 'yes',
			),
		) );
	}
	
	public function getSectionArgs(): array {
		return array(
			'open' => $this->getProductTypes() !== BulkPriceEditorPlugin::getDefaultProductTypes(),
		);
	}
	
	public function getTitle(): string {
		return __( 'Product Type', 'bulk-price-editor' );
	}
}
