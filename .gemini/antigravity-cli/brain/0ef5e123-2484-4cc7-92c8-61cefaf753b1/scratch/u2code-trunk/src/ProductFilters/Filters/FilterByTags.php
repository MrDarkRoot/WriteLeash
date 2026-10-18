<?php namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Services\LookupService;
use BulkPriceEditor\PriceEditorPage\Widgets\Widget;

class FilterByTags extends TermsFilter {

	const TAXONOMY = 'product_tag';

	public function getTaxonomy(): string {
		return self::TAXONOMY;
	}

	protected function getIncludeFilterKey(): string {
		return 'bpe_included_tag_ids';
	}

	protected function getExcludeFilterKey(): string {
		return 'bpe_excluded_tag_ids';
	}

	public function getTitle(): string {
		return __( 'Filter by Tags', 'bulk-price-editor' );
	}

	public function renderIncludeField( Widget $widget ) {

		$widget->renderSelect2( array(
			'id'                => 'bpe_included_tag_ids',
			'attributes'        => 'multiple',
			'label'             => __( 'Include tags', 'bulk-price-editor' ),
			'placeholder'       => __( 'Select tags', 'bulk-price-editor' ),
			'search_action'     => LookupService::TAGS_SEARCH_ACTION,
			'value'             => $this->getIncludedTerms( 'names', false ),
			'custom_attributes' => array(
				'data-product-filter' => 'yes',
			),
		) );
	}

	public function renderExcludeField( Widget $widget ) {

		$widget->renderSelect2( array(
			'id'                => 'bpe_excluded_tag_ids',
			'attributes'        => 'multiple',
			'label'             => __( 'Exclude tags', 'bulk-price-editor' ),
			'placeholder'       => __( 'Select tags', 'bulk-price-editor' ),
			'search_action'     => LookupService::TAGS_SEARCH_ACTION,
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
