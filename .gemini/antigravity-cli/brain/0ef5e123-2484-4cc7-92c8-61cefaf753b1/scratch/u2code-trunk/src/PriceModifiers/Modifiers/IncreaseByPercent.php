<?php

namespace BulkPriceEditor\PriceModifiers\Modifiers;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use WC_Product;
class IncreaseByPercent extends PriceModifier {
    const TYPE = 'increase_by_percent';

    protected array $supports = array(
        'advanced_options' => true,
    );

    public function getType() : string {
        return self::TYPE;
    }

    public function getName() : string {
        return __( 'Increase by percent', 'bulk-price-editor' );
    }

    public function getUpdatedRegularPrice( WC_Product $product ) : ?float {
        $args = $this->getRawArgs();
        $oldRegularPrice = $product->get_regular_price( 'edit' );
        if ( !is_numeric( $oldRegularPrice ) ) {
            return null;
        }
        $increaseByPercent = ( isset( $args['bpe_regular_price_increase_by_percent'] ) ? (float) $args['bpe_regular_price_increase_by_percent'] : null );
        if ( !is_numeric( $increaseByPercent ) ) {
            return null;
        }
        return (float) $oldRegularPrice + $oldRegularPrice * $increaseByPercent / 100;
    }

    public function getUpdatedSalePrice( WC_Product $product ) : ?float {
        return null;
    }

    public function renderFields( Widget $widget ) {
        $widget->renderHint( __( 'Enter a percent by which to increase the prices of the selected products.', 'bulk-price-editor' ) );
        $widget->renderTextInput( array(
            'id'                => 'bpe_regular_price_increase_by_percent',
            'label'             => __( 'Increase regular price by percent', 'bulk-price-editor' ),
            'custom_attributes' => array(
                'data-price-modificator' => 'yes',
            ),
        ) );
        $widget->renderTextInput( array(
            'id'                => 'bpe_sale_price_increase_by_percent',
            'label'             => __( 'Increase sale price by percent', 'bulk-price-editor' ),
            'custom_attributes' => array(
                'data-price-modificator' => 'yes',
            ),
            'only_for_premium'  => true,
        ) );
    }

}
