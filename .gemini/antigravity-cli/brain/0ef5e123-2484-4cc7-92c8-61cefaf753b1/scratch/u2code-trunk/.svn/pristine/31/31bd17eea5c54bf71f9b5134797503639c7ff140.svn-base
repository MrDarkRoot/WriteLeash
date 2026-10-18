<?php namespace BulkPriceEditor\ProductsTable\Columns;

use BulkPriceEditor\ProductsTable\Columns\RegularPriceColumn;
use BulkPriceEditor\ProductsTable\WPListProductsTable;
use WC_Product;
use WC_Product_Variable;

class SalePriceColumn extends RegularPriceColumn {
	
	public function getSlug(): string {
		return 'sale_price';
	}
	
	public function getName(): string {
		return __( 'Sale Price', 'bulk-price-editor' );
	}
	
	public function render( WC_Product $product, WPListProductsTable $table ): string {
		
		if ( $product instanceof WC_Product_Variable ) {
			$originalPrice = $this->getVariableProductPrice( $product, 'sale' );
		} else {
			$originalPrice = $product->get_sale_price( 'edit' ) ? wc_price( $product->get_sale_price( 'edit' ) ) : 'N/A';
		}
		
		if ( ! $table->priceModifier ) {
			return $originalPrice;
		}
		
		$newRegularPrice = $table->priceModifier->getFinalSalePrice( $product );
		
		if ( ! is_null( $newRegularPrice ) ) {
			
			if ( 'N/A' !== $newRegularPrice ) {
				$newRegularPrice = wc_price( $newRegularPrice );
			}
			
			return sprintf( ' <span>%s</span>  → <span style="background: #fffcc1;padding: 3px;font-weight: 500;">%s</span>',
				$originalPrice, $newRegularPrice );
		}
		
		return $originalPrice;
	}
}
