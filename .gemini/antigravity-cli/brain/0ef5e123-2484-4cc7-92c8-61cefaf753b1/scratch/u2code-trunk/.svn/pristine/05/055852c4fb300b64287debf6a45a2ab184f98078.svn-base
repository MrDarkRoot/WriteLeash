<?php namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Services\LookupService;
use BulkPriceEditor\PriceEditorPage\Widgets\Widget;

class FilterByShippingClass extends TermsFilter {

	const TAXONOMY = 'product_shipping_class';

	public function getTaxonomy(): string {
		return self::TAXONOMY;
	}

	protected function getIncludeFilterKey(): string {
		return 'bpe_included_shipping_class_ids';
	}

	protected function getExcludeFilterKey(): string {
		return 'bpe_excluded_shipping_class_ids';
	}

	public function getTitle(): string {
		return __( 'Filter by Shipping Class', 'bulk-price-editor' );
	}

	public function renderIncludeField( Widget $widget ) {

		$widget->renderSelect2( array(
			'id'                => 'bpe_included_shipping_class_ids',
			'attributes'        => 'multiple',
			'label'             => __( 'Include shipping classes', 'bulk-price-editor' ),
			'placeholder'       => __( 'Select shipping classes', 'bulk-price-editor' ),
			'search_action'     => LookupService::SHIPPING_CLASSES_SEARCH_ACTION,
			'value'             => $this->getIncludedTerms( 'names', false ),
			'custom_attributes' => array(
				'data-product-filter' => 'yes',
			),
		) );
	}

	public function renderExcludeField( Widget $widget ) {

		$widget->renderSelect2( array(
			'id'                => 'bpe_excluded_shipping_class_ids',
			'attributes'        => 'multiple',
			'label'             => __( 'Exclude shipping classes', 'bulk-price-editor' ),
			'placeholder'       => __( 'Select shipping classes', 'bulk-price-editor' ),
			'search_action'     => LookupService::SHIPPING_CLASSES_SEARCH_ACTION,
			'value'             => $this->getExcludedTerms( 'names', false ),
			'custom_attributes' => array(
				'data-product-filter' => 'yes',
			),
		) );
	}

	public function isPremium(): bool {
		return true;
	}
}
