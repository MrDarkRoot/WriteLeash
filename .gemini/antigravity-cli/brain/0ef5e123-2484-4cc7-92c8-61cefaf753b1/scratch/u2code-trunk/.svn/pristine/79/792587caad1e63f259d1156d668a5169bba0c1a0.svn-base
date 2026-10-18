<?php

namespace BulkPriceEditor\PriceModifiers\Modifiers;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\PriceModifiers\PriceFormula;
use WC_Product;
/**
 * Calculates new prices from a formula over each product's current values,
 * e.g. "{regular_price} * 0.8" or "{cost} * 1.5".
 */
class UpdateByFormula extends PriceModifier {
    const TYPE = 'update_by_formula';

    protected array $supports = array(
        'advanced_options' => true,
    );

    public function getType() : string {
        return self::TYPE;
    }

    public function getName() : string {
        return __( 'Update by formula', 'bulk-price-editor' );
    }

    public function getUpdatedRegularPrice( WC_Product $product ) : ?float {
        return $this->evaluateField( 'bpe_formula_regular_price', $product );
    }

    public function getUpdatedSalePrice( WC_Product $product ) : ?float {
        return $this->evaluateField( 'bpe_formula_sale_price', $product );
    }

    /**
     * Empty or inapplicable input (a formula referencing a value the product
     * does not have) leaves the price unchanged.
     */
    protected function evaluateField( string $key, WC_Product $product ) : ?float {
        return null;
    }

    /**
     * Per-product values the formula variables resolve to.
     */
    protected function getVariables( WC_Product $product ) : array {
        $variables = array(
            'regular_price' => $product->get_regular_price( 'edit' ),
            'sale_price'    => $product->get_sale_price( 'edit' ),
            'price'         => $product->get_price( 'edit' ),
            'cost'          => self::getProductCost( $product ),
        );
        return apply_filters( 'bulk_price_editor/formula/variables', $variables, $product );
    }

    /**
     * Product cost from WooCommerce core Cost of Goods Sold (WC 9.5+) or the
     * popular Cost of Goods plugins. Zero counts as "no cost" so a store
     * without cost data can never be priced to 0 by a {cost} formula.
     */
    public static function getProductCost( WC_Product $product ) {
        if ( method_exists( $product, 'get_cogs_value' ) ) {
            $cost = $product->get_cogs_value();
            if ( is_numeric( $cost ) && $cost > 0 ) {
                return $cost;
            }
        }
        foreach ( array('_wc_cog_cost', '_alg_wc_cog_cost') as $metaKey ) {
            $cost = $product->get_meta( $metaKey );
            if ( is_numeric( $cost ) && $cost > 0 ) {
                return $cost;
            }
        }
        return '';
    }

    public function renderFields( Widget $widget ) {
        if ( !bpefw_fs()->can_use_premium_code() ) {
            ?>
			<div class="bulk-price-editor-widget-premium-notice">
				<?php 
            esc_html_e( 'This price modifier is available in the premium version.', 'bulk-price-editor-for-woocommerce' );
            ?>
				<a href="<?php 
            echo esc_attr( bpefw_fs_upgrade_url() );
            ?>" target="_blank">
					<?php 
            esc_html_e( 'Upgrade your plan', 'bulk-price-editor-for-woocommerce' );
            ?>
				</a>
			</div>
			<?php 
        }
        $widget->renderHint( __( 'Calculate new prices from each product\'s current values. Use min() and max() for price floors and caps.', 'bulk-price-editor' ) );
        ?>
		<div class="bulk-price-editor-widget-row">
			<div class="bulk-price-editor-widget-row__name">
				<?php 
        esc_html_e( 'Variables', 'bulk-price-editor' );
        ?>
			</div>
			<div class="bulk-price-editor-widget-row__value">

				<?php 
        foreach ( array(
            '{regular_price}',
            '{sale_price}',
            '{price}',
            '{cost}'
        ) as $variable ) {
            ?>
					<button type="button"
							class="bpe-formula-variable"
							title="<?php 
            esc_attr_e( 'Click to insert into a formula field', 'bulk-price-editor' );
            ?>"
							style="cursor: pointer; margin-right: 4px; padding: 3px 8px; font-family: monospace; font-size: 12px; color: #2c3338; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 3px;"
							data-variable="<?php 
            echo esc_attr( $variable );
            ?>"><?php 
            echo esc_html( $variable );
            ?></button>
				<?php 
        }
        ?>

			</div>
		</div>
		<?php 
        $widget->renderTextInput( array(
            'id'                => 'bpe_formula_regular_price',
            'label'             => __( 'New Regular Price', 'bulk-price-editor' ),
            'placeholder'       => __( 'e.g. {regular_price} * 1.1', 'bulk-price-editor' ),
            'description'       => __( 'Leave empty to keep the regular price unchanged.', 'bulk-price-editor' ),
            'only_for_premium'  => true,
            'custom_attributes' => array(
                'data-price-modificator' => 'yes',
            ),
        ) );
        $widget->renderTextInput( array(
            'id'                => 'bpe_formula_sale_price',
            'label'             => __( 'New Sale Price', 'bulk-price-editor' ),
            'placeholder'       => __( 'e.g. max({regular_price} * 0.7, 9.99)', 'bulk-price-editor' ),
            'description'       => __( 'Example: max({regular_price} * 0.7, 9.99) gives 30% off, but never below 9.99.', 'bulk-price-editor' ),
            'only_for_premium'  => true,
            'custom_attributes' => array(
                'data-price-modificator' => 'yes',
            ),
        ) );
    }

}
