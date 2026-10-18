<?php namespace BulkPriceEditor\PriceModifiers\Modifiers;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use WC_Product;

class NewPrices extends PriceModifier {

	const TYPE = 'new_prices';

	protected array $supports = array(
		'advanced_options' => true,
	);

	public function getType(): string {
		return self::TYPE;
	}

	public function getName(): string {
		return __( 'Enter new prices', 'bulk-price-editor' );
	}

	public function getUpdatedRegularPrice( WC_Product $product ): ?float {
		return $this->getFieldPrice( 'bpe_new_regular_price' );
	}

	public function getUpdatedSalePrice( WC_Product $product ): ?float {
		return $this->getFieldPrice( 'bpe_new_sale_price' );
	}

	/**
	 * Only a numeric value changes the price — empty input leaves it alone.
	 */
	protected function getFieldPrice( string $key ): ?float {

		$args = $this->getRawArgs();

		$value = isset( $args[ $key ] ) ? trim( (string) $args[ $key ] ) : '';

		return is_numeric( $value ) ? max( 0, (float) $value ) : null;
	}

	public function renderFields( Widget $widget ) {

		$widget->renderHint( __( 'Enter new prices for selected products.', 'bulk-price-editor' ) );

		$widget->renderTextInput( array(
			'id'                => 'bpe_new_regular_price',
			'css_class'         => 'wc_input_price',
			/* translators: %s: Currency symbol */
			'label'             => sprintf( __( 'New Regular Price (%s)', 'bulk-price-editor' ),
				get_woocommerce_currency_symbol() ),
			'custom_attributes' => array(
				'data-price-modificator' => 'yes',
			),
		) );

		$widget->renderTextInput( array(
			'id'                => 'bpe_new_sale_price',
			'css_class'         => 'wc_input_price',
			/* translators: %s: Currency symbol */
			'label'             => sprintf( __( 'New Sale Price (%s)', 'bulk-price-editor' ),
				get_woocommerce_currency_symbol() ),
			'custom_attributes' => array(
				'data-price-modificator' => 'yes',
			),
		) );
	}
}
