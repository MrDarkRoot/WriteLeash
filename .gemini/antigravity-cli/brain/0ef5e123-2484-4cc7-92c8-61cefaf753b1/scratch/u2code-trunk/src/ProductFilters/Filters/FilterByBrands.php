<?php namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Services\LookupService;
use BulkPriceEditor\PriceEditorPage\Widgets\Widget;

class FilterByBrands extends TermsFilter {

	const TAXONOMY = 'product_brand';

	/**
	 * Brands are available since WooCommerce 9.4 (product_brand taxonomy).
	 */
	public static function isSupported(): bool {
		return taxonomy_exists( self::TAXONOMY );
	}

	public function getTaxonomy(): string {
		return self::TAXONOMY;
	}

	protected function getIncludeFilterKey(): string {
		return 'bpe_included_brand_ids';
	}

	protected function getExcludeFilterKey(): string {
		return 'bpe_excluded_brand_ids';
	}

	public function getTitle(): string {
		return __( 'Filter by Brands', 'bulk-price-editor' );
	}

	public function renderIncludeField( Widget $widget ) {

		$widget->renderSelect2( array(
			'id'                => 'bpe_included_brand_ids',
			'attributes'        => 'multiple',
			'label'             => __( 'Include brands', 'bulk-price-editor' ),
			'placeholder'       => __( 'Select brands', 'bulk-price-editor' ),
			'search_action'     => LookupService::BRANDS_SEARCH_ACTION,
			'value'             => $this->getIncludedTerms( 'names', false ),
			'custom_attributes' => array(
				'data-product-filter' => 'yes',
			),
		) );
	}

	public function renderExcludeField( Widget $widget ) {

		$widget->renderSelect2( array(
			'id'                => 'bpe_excluded_brand_ids',
			'attributes'        => 'multiple',
			'label'             => __( 'Exclude brands', 'bulk-price-editor' ),
			'placeholder'       => __( 'Select brands', 'bulk-price-editor' ),
			'search_action'     => LookupService::BRANDS_SEARCH_ACTION,
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
