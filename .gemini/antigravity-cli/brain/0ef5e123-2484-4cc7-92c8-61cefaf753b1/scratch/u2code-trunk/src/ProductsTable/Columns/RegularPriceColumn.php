<?php namespace BulkPriceEditor\ProductsTable\Columns;

use BulkPriceEditor\ProductsTable\WPListProductsTable;
use WC_Product;
use WC_Product_Variable;

class RegularPriceColumn extends Column {
	
	public function getSlug(): string {
		return 'regular_price';
	}
	
	public function getName(): string {
		return __( 'Regular Price', 'bulk-price-editor' );
	}
	
	public function render( WC_Product $product, WPListProductsTable $table ): string {
		
		if ( $product instanceof WC_Product_Variable ) {
			$originalPrice = $this->getVariableProductPrice( $product, 'regular' );
		} else {
			$originalPrice = $product->get_regular_price( 'edit' ) ? wc_price( $product->get_regular_price( 'edit' ) ) : 'N/A';
		}
		
		
		if ( ! $table->priceModifier ) {
			return $originalPrice;
		}
		
		$newRegularPrice = $table->priceModifier->getFinalRegularPrice( $product );
		
		if ( ! is_null( $newRegularPrice ) ) {
			
			if ( 'N/A' !== $newRegularPrice ) {
				$newRegularPrice = wc_price( $newRegularPrice );
			}
			
			return sprintf( ' <span>%s</span>  → <span style="background: #fffcc1;padding: 3px;font-weight: 500;">%s</span>',
				$originalPrice, $newRegularPrice );
		}
		
		return $originalPrice;
	}
	
	public function sortable(): bool {
		return true;
	}
	
	protected function getVariableProductPrice( \WC_Product_Variable $productVariable, $type = 'regular' ): ?string {
		
		$prices = $productVariable->get_variation_prices( false );
		
		if ( 'sale' === $type ) {
			$minPrice = current( $prices['sale_price'] );
			$maxPrice = end( $prices['sale_price'] );
		} elseif ( 'regular' === $type ) {
			$minPrice = current( $prices['regular_price'] );
			$maxPrice = end( $prices['regular_price'] );
		} else {
			$minPrice = current( $prices['price'] );
			$maxPrice = end( $prices['price'] );
		}
		
		if ( $minPrice !== $maxPrice ) {
			return wc_format_price_range( $minPrice, $maxPrice );
		} else {
			return wc_price( $minPrice );
		}
	}
}
