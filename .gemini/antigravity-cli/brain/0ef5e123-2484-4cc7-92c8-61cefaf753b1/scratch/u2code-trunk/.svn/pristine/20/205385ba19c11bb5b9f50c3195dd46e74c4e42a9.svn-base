<?php

namespace BulkPriceEditor\PriceModifiers\Modifiers;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use WC_Product_Variable;
use WC_Product;
abstract class PriceModifier {
    private array $rawArgs = array();

    protected array $supports = array(
        'advanced_options' => true,
    );

    public function updatePrices( WC_Product $product ) : void {
        if ( $product instanceof WC_Product_Variable ) {
            $children = $product->get_children();
            foreach ( $children as $child ) {
                $childProduct = wc_get_product( $child );
                if ( $childProduct instanceof WC_Product ) {
                    $this->updatePrices( $childProduct );
                }
            }
        }
        /**
         * Fired for every concrete product (variations included) right before
         * its prices change. The history snapshot is recorded here.
         *
         * @param WC_Product    $product
         * @param PriceModifier $this
         */
        do_action( 'bulk_price_editor/product/before_price_update', $product, $this );
        $regularPrice = $this->getFinalRegularPrice( $product );
        $salePrice = $this->getFinalSalePrice( $product );
        if ( !is_null( $regularPrice ) ) {
            $product->set_regular_price( $regularPrice );
        }
        if ( !is_null( $salePrice ) ) {
            $product->set_sale_price( $salePrice );
        }
        $this->applySaleDates( $product );
        $product->save();
    }

    /**
     * The shared sale-dates option: '' leaves the products' sale schedule
     * alone, otherwise returns the change to apply — 'from'/'to' as entered
     * ('' clears the date).
     */
    public function getSaleDatesChange() : ?array {
        return null;
    }

    protected function applySaleDates( WC_Product $product ) : void {
        $change = $this->getSaleDatesChange();
        if ( is_null( $change ) ) {
            return;
        }
        $product->set_date_on_sale_from( ( '' === $change['from'] ? null : $change['from'] ) );
        $product->set_date_on_sale_to( ( '' === $change['to'] ? null : $change['to'] ) );
    }

    /**
     * Modifier result with the shared rounding option applied.
     * Used for both saving and the preview table.
     */
    public function getFinalRegularPrice( WC_Product $product ) {
        return $this->applyRounding( $this->getUpdatedRegularPrice( $product ) );
    }

    public function getFinalSalePrice( WC_Product $product ) {
        return $this->applyRounding( $this->getUpdatedSalePrice( $product ) );
    }

    /**
     * The shared rounding option: either a number of decimals ('1', '2') or a
     * price ending ('00', '90', '95', '99' — the nearest price with that
     * ending, e.g. 63.74 with '99' becomes 63.99).
     */
    protected function applyRounding( $price ) {
        if ( !is_numeric( $price ) ) {
            return $price;
        }
        $rounding = (string) ($this->getRawArgs()['bpe_price_rounding'] ?? '');
        if ( in_array( $rounding, array('1', '2'), true ) ) {
            return round( (float) $price, (int) $rounding );
        }
        if ( !in_array( $rounding, array(
            '00',
            '90',
            '95',
            '99'
        ), true ) ) {
            return $price;
        }
        $cents = (int) $rounding / 100;
        // Nearest price with the requested ending.
        $value = round( (float) $price - $cents ) + $cents;
        if ( $value <= 0 ) {
            return ( $cents > 0 ? $cents : 0.0 );
        }
        return $value;
    }

    protected function renderRoundingField( Widget $widget ) {
        $widget->renderSelect( array(
            'id'                => 'bpe_price_rounding',
            'label'             => __( 'Rounding', 'bulk-price-editor' ),
            'description'       => __( 'Round the resulting prices to a number of decimals or to the nearest price with the selected ending, e.g. 63.74 becomes 63.99.', 'bulk-price-editor' ),
            'desc_tip'          => false,
            'options'           => array(
                ''   => __( 'No rounding', 'bulk-price-editor' ),
                '2'  => __( '2 decimals', 'bulk-price-editor' ),
                '1'  => __( '1 decimal', 'bulk-price-editor' ),
                '00' => __( 'Whole number (.00)', 'bulk-price-editor' ),
                '90' => __( 'Price ending .90', 'bulk-price-editor' ),
                '95' => __( 'Price ending .95', 'bulk-price-editor' ),
                '99' => __( 'Price ending .99', 'bulk-price-editor' ),
            ),
            'custom_attributes' => array(
                'data-price-modificator' => 'yes',
            ),
        ) );
    }

    public abstract function getType() : string;

    public abstract function getName();

    public abstract function getUpdatedSalePrice( WC_Product $product );

    public abstract function getUpdatedRegularPrice( WC_Product $product );

    protected function renderSaleDatesFields( Widget $widget ) {
        $widget->renderSelect( array(
            'id'                => 'bpe_sale_dates_action',
            'label'             => __( 'Sale dates', 'bulk-price-editor' ),
            'description'       => __( 'Schedule when the sale prices start and end (WooCommerce sale dates), or clear existing schedules.', 'bulk-price-editor' ),
            'desc_tip'          => false,
            'options'           => array(
                ''      => __( 'Leave unchanged', 'bulk-price-editor' ),
                'set'   => __( 'Set sale dates', 'bulk-price-editor' ),
                'clear' => __( 'Clear sale dates', 'bulk-price-editor' ),
            ),
            'custom_attributes' => array(
                'data-price-modificator' => 'yes',
                'data-bpe-sale-dates'    => 'toggle',
            ),
            'only_for_premium'  => true,
        ) );
        $widget->renderTextInput( array(
            'id'                => 'bpe_sale_date_from',
            'type'              => 'date',
            'label'             => __( 'Sale starts', 'bulk-price-editor' ),
            'custom_attributes' => array(
                'data-price-modificator' => 'yes',
                'data-bpe-sale-dates'    => 'field',
            ),
            'only_for_premium'  => true,
        ) );
        $widget->renderTextInput( array(
            'id'                => 'bpe_sale_date_to',
            'type'              => 'date',
            'label'             => __( 'Sale ends', 'bulk-price-editor' ),
            'description'       => __( 'Leave a date empty to keep that side of the schedule open.', 'bulk-price-editor' ),
            'desc_tip'          => false,
            'custom_attributes' => array(
                'data-price-modificator' => 'yes',
                'data-bpe-sale-dates'    => 'field',
            ),
            'only_for_premium'  => true,
        ) );
    }

    public abstract function renderFields( Widget $widget );

    public function renderAdvancedFields( Widget $widget ) {
        $this->renderRoundingField( $widget );
        $this->renderSaleDatesFields( $widget );
    }

    public function hadAdvancedOptions() : bool {
        return (bool) $this->supports['advanced_options'];
    }

    public function setRawArgs( array $rawArgs ) : void {
        $this->rawArgs = $rawArgs;
    }

    public function getRawArgs() : array {
        return $this->rawArgs;
    }

}
