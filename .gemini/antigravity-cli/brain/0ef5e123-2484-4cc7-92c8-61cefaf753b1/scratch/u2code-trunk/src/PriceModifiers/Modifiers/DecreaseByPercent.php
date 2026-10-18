<?php

namespace BulkPriceEditor\PriceModifiers\Modifiers;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use WC_Product;
class DecreaseByPercent extends PriceModifier {
    const TYPE = 'decrease_by_percent';

    protected array $supports = array(
        'advanced_options' => true,
    );

    public function getType() : string {
        return self::TYPE;
    }

    public function getName() : string {
        return __( 'Decrease by percent', 'bulk-price-editor' );
    }

    public function getUpdatedRegularPrice( WC_Product $product ) : ?float {
        $args = $this->getRawArgs();
        $oldRegularPrice = $product->get_regular_price( 'edit' );
        if ( !is_numeric( $oldRegularPrice ) ) {
            return null;
        }
        $decreaseByPercent = ( isset( $args['bpe_regular_price_decrease_by_percent'] ) ? (float) $args['bpe_regular_price_decrease_by_percent'] : null );
        if ( !is_numeric( $decreaseByPercent ) ) {
            return null;
        }
        return max( 0, (float) $oldRegularPrice - $oldRegularPrice * $decreaseByPercent / 100 );
    }

    public function getUpdatedSalePrice( WC_Product $product ) : ?float {
        return null;
    }

    public function renderFields( Widget $widget ) {
        $widget->renderHint( __( 'Enter a percent by which to decrease the prices of the selected products.', 'bulk-price-editor' ) );
        $widget->renderTextInput( array(
            'id'                => 'bpe_regular_price_decrease_by_percent',
            'type'              => 'number',
            'label'             => __( 'Decrease regular price by percent', 'bulk-price-editor' ),
            'custom_attributes' => array(
                'data-price-modificator' => 'yes',
                'min'                    => 0,
                'max'                    => 100,
            ),
        ) );
        $widget->renderTextInput( array(
            'id'                => 'bpe_sale_price_decrease_by_percent',
            'type'              => 'number',
            'label'             => __( 'Decrease sale price by percent', 'bulk-price-editor' ),
            'custom_attributes' => array(
                'data-price-modificator' => 'yes',
                'min'                    => 0,
                'max'                    => 100,
            ),
            'only_for_premium'  => true,
        ) );
    }

}
