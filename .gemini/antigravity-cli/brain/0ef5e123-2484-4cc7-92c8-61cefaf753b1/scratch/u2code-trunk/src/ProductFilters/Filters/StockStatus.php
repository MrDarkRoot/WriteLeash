<?php

namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductQuery\ProductQuery;
class StockStatus extends Filter {
    public function getStockStatus() : string {
        $stockStatus = (string) $this->getFilterValue( 'bpe_stock_status', '' );
        return $this->filterStockStatus( $stockStatus );
    }

    public function filterQuery( ProductQuery $query ) : void {
    }

    public function getSectionArgs() : array {
        return array(
            'open' => $this->getStockStatus() !== '',
        );
    }

    public function renderFields( Widget $widget ) {
        $stockStatus = $this->getStockStatus();
        $widget->renderRadioButtons( array(
            'id'                => 'bpe_stock_status',
            'label'             => __( 'Stock status', 'bulk-price-editor' ),
            'options'           => array(
                ''            => __( 'Any stock status', 'bulk-price-editor' ),
                'instock'     => __( 'In stock', 'bulk-price-editor' ),
                'outofstock'  => __( 'Out of stock', 'bulk-price-editor' ),
                'onbackorder' => __( 'On backorder', 'bulk-price-editor' ),
            ),
            'value'             => $stockStatus,
            'custom_attributes' => array(
                'data-product-filter' => 'yes',
            ),
        ) );
    }

    public function getTitle() : string {
        return __( 'Stock Status', 'bulk-price-editor' );
    }

    protected function filterStockStatus( string $stockStatus ) : string {
        return ( in_array( $stockStatus, array('instock', 'outofstock', 'onbackorder') ) ? $stockStatus : '' );
    }

    public function isPremium() : bool {
        return true;
    }

}
