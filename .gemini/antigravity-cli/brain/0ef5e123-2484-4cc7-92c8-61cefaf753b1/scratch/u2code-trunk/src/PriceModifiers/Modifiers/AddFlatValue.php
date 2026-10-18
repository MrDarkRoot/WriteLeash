<?php

namespace BulkPriceEditor\PriceModifiers\Modifiers;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use WC_Product;
class AddFlatValue extends PriceModifier {
    const TYPE = 'add_flat_value';

    protected array $supports = array(
        'advanced_options' => true,
    );

    public function getType() : string {
        return self::TYPE;
    }

    public function getName() : string {
        return __( 'Add flat amount', 'bulk-price-editor' );
    }

    public function getUpdatedRegularPrice( WC_Product $product ) : ?float {
        $args = $this->getRawArgs();
        $oldRegularPrice = $product->get_regular_price( 'edit' );
        if ( !is_numeric( $oldRegularPrice ) ) {
            return null;
        }
        $addFlatValue = ( isset( $args['bpe_regular_price_add_flat_value'] ) ? (float) $args['bpe_regular_price_add_flat_value'] : null );
        if ( !is_numeric( $addFlatValue ) ) {
            return null;
        }
        return max( 0, (float) $oldRegularPrice + $addFlatValue );
    }

    public function getUpdatedSalePrice( WC_Product $product ) : ?float {
        return null;
    }

    public function renderFields( Widget $widget ) {
        $widget->renderHint( 'Input a flat value to add to the regular and sale prices of selected products.' );
        $widget->renderTextInput( array(
            'id'                => 'bpe_regular_price_add_flat_value',
            'css_class'         => 'wc_input_price',
            'label'             => __( 'Add value to regular price', 'bulk-price-editor' ),
            'custom_attributes' => array(
                'data-price-modificator' => 'yes',
            ),
        ) );
        $widget->renderTextInput( array(
            'id'                => 'bpe_sale_price_add_flat_value',
            'css_class'         => 'wc_input_price',
            'label'             => __( 'Add value to sale price', 'bulk-price-editor' ),
            'custom_attributes' => array(
                'data-price-modificator' => 'yes',
            ),
            'only_for_premium'  => true,
        ) );
    }

}
