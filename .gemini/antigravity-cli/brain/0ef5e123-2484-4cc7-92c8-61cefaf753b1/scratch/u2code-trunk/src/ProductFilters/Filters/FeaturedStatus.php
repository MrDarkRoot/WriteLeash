<?php

namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductQuery\ProductQuery;
class FeaturedStatus extends Filter {
    public function getTitle() : string {
        return __( 'Featured Products', 'bulk-price-editor' );
    }

    public function getFeaturedStatus() : string {
        $status = (string) $this->getFilterValue( 'bpe_featured_status', '' );
        return ( in_array( $status, array('featured', 'not_featured'), true ) ? $status : '' );
    }

    /**
     * The featured flag lives on parent products only, so the clause goes
     * through the TermsFilter WHERE handler, which also matches variations
     * of featured variable products via their parent.
     */
    public function filterQuery( ProductQuery $query ) : void {
    }

    public function getSectionArgs() : array {
        return array(
            'open' => '' !== $this->getFeaturedStatus(),
        );
    }

    public function renderFields( Widget $widget ) {
        $widget->renderRadioButtons( array(
            'id'                => 'bpe_featured_status',
            'label'             => __( 'Featured', 'bulk-price-editor' ),
            'options'           => array(
                ''             => __( 'Any', 'bulk-price-editor' ),
                'featured'     => __( 'Featured only', 'bulk-price-editor' ),
                'not_featured' => __( 'Not featured', 'bulk-price-editor' ),
            ),
            'value'             => $this->getFeaturedStatus(),
            'custom_attributes' => array(
                'data-product-filter' => 'yes',
            ),
        ) );
    }

    public function isPremium() : bool {
        return true;
    }

}
