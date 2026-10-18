<?php

namespace BulkPriceEditor\ProductFilters\Filters;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductQuery\ProductQuery;
class SKUAndName extends Filter {
    public function getTitle() : string {
        return __( 'SKU and Name', 'bulk-price-editor' );
    }

    public function getSKU() : ?string {
        return (string) $this->getFilterValue( 'bpe_sku_contains', '' );
    }

    public function getProductTitle() : ?string {
        return (string) $this->getFilterValue( 'bpe_title_like', '' );
    }

    public function filterQuery( ProductQuery $query ) : void {
    }

    public function getSectionArgs() : array {
        return array(
            'open' => !empty( $this->getProductTitle() ) || !empty( $this->getSKU() ),
        );
    }

    public function renderFields( Widget $widget ) {
        $widget->renderTextInput( array(
            'id'                => 'bpe_sku_contains',
            'label'             => __( 'SKU contains', 'bulk-price-editor' ),
            'desc'              => __( 'You can input full SKU to match exact product.', 'bulk-price-editor' ),
            'value'             => $this->getSKU(),
            'custom_attributes' => array(
                'data-product-filter' => 'yes',
            ),
        ) );
        $widget->renderTextInput( array(
            'id'                => 'bpe_title_like',
            'label'             => __( 'Product name contains', 'bulk-price-editor' ),
            'desc_tip'          => false,
            'value'             => $this->getProductTitle(),
            'custom_attributes' => array(
                'data-product-filter' => 'yes',
            ),
        ) );
    }

    public function isPremium() : bool {
        return true;
    }

}
