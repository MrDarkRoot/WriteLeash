<?php

namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductQuery\ProductQuery;
class OnSaleStatus extends Filter {
    public function getTitle() : string {
        return __( 'Sale Status', 'bulk-price-editor' );
    }

    public function getOnSaleStatus() : string {
        $status = (string) $this->getFilterValue( 'bpe_on_sale_status', '' );
        return ( in_array( $status, array('on_sale', 'not_on_sale'), true ) ? $status : '' );
    }

    /**
     * Must run after IncludedProducts/ExcludedProducts (registration order),
     * because it intersects/extends the include and exclude args they set.
     */
    public function filterQuery( ProductQuery $query ) : void {
    }

    public function getSectionArgs() : array {
        return array(
            'open' => '' !== $this->getOnSaleStatus(),
        );
    }

    public function renderFields( Widget $widget ) {
        $widget->renderRadioButtons( array(
            'id'                => 'bpe_on_sale_status',
            'label'             => __( 'Sale status', 'bulk-price-editor' ),
            'options'           => array(
                ''            => __( 'Any', 'bulk-price-editor' ),
                'on_sale'     => __( 'On sale', 'bulk-price-editor' ),
                'not_on_sale' => __( 'Not on sale', 'bulk-price-editor' ),
            ),
            'value'             => $this->getOnSaleStatus(),
            'custom_attributes' => array(
                'data-product-filter' => 'yes',
            ),
        ) );
    }

    public function isPremium() : bool {
        return true;
    }

}
