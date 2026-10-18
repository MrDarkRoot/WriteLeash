<?php namespace BulkPriceEditor\PriceModifiers\Modifiers;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use WC_Product;

class RemovePrice extends PriceModifier {

	const TYPE = 'remove_prices';

	/**
	 * Sentinel returned by getUpdated*Price() meaning "clear this price".
	 * The preview columns render it literally.
	 */
	const REMOVE = 'N/A';

	public function getName(): string {
		return __( 'Remove prices', 'bulk-price-editor' );
	}
	
	public function getType(): string {
		return self::TYPE;
	}
	
	public function getUpdatedRegularPrice( WC_Product $product ): ?string {
		
		$removePrices = $this->getRawArgs()['bpe_remove_prices'] ?? '';
		
		if ( 'both' === $removePrices || 'regular_price' === $removePrices ) {
			return self::REMOVE;
		}

		return null;
	}

	public function getUpdatedSalePrice( WC_Product $product ): ?string {
		$removePrices = $this->getRawArgs()['bpe_remove_prices'] ?? '';

		if ( 'both' === $removePrices || 'sale_price' === $removePrices ) {
			return self::REMOVE;
		}
		
		return null;
	}
	
	public function renderFields( Widget $widget ) {
		
		$widget->renderHint( __( 'Select what prices you want to remove (regular, sale, or both) from the selected products.',
			'bulk-price-editor' ) );
		
		$widget->renderSelect( array(
			'id'                => 'bpe_remove_prices',
			'label'             => __( 'Remove price', 'bulk-price-editor' ),
			'options'           => array(
				'both'          => __( 'Both regular and sale prices', 'bulk-price-editor' ),
				'regular_price' => __( 'Regular price', 'bulk-price-editor' ),
				'sale_price'    => __( 'Sale price', 'bulk-price-editor' ),
			),
			'custom_attributes' => array(
				'data-price-modificator' => 'yes',
			),
		) );
	}
	
	public function renderAdvancedFields( Widget $widget ) {
		$this->renderSaleDatesFields( $widget );
	}

	public function updatePrices( WC_Product $product ): void {

		if ( $product instanceof \WC_Product_Variable ) {
			$children = $product->get_children();

			foreach ( $children as $child ) {
				$childProduct = wc_get_product( $child );

				if ( $childProduct instanceof WC_Product ) {
					$this->updatePrices( $childProduct );
				}
			}
		}

		/** Documented in PriceModifier::updatePrices(). */
		do_action( 'bulk_price_editor/product/before_price_update', $product, $this );

		$regularPrice = $this->getUpdatedRegularPrice( $product );
		$salePrice    = $this->getUpdatedSalePrice( $product );

		if ( self::REMOVE === $regularPrice ) {
			$product->set_regular_price( '' );
		}

		if ( self::REMOVE === $salePrice ) {
			$product->set_sale_price( '' );
		}

		$this->applySaleDates( $product );

		$product->save();
	}
}
