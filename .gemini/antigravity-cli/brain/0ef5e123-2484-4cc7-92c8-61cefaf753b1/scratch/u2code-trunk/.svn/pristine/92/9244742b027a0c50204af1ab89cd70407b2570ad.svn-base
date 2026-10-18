<?php namespace BulkPriceEditor\ProductsTable\Columns;

use BulkPriceEditor\ProductsTable\WPListProductsTable;
use WC_Product;

class StockStatusColumn extends Column {
	
	public function getSlug(): string {
		return 'stock_status';
	}
	
	public function getName(): string {
		return __( 'Stock Status', 'bulk-price-editor' );
	}
	
	public function render( WC_Product $product, WPListProductsTable $table ): string {
		if ( $product->is_on_backorder() ) {
			$stock_html = '<mark class="onbackorder">' . __( 'On backorder', 'bulk-price-editor' ) . '</mark>';
		} elseif ( $product->is_in_stock() ) {
			$stock_html = '<mark class="instock">' . __( 'In stock', 'bulk-price-editor' ) . '</mark>';
		} else {
			$stock_html = '<mark class="outofstock">' . __( 'Out of stock', 'bulk-price-editor' ) . '</mark>';
		}
		
		if ( $product->managing_stock() ) {
			$stock_html .= ' (' . wc_stock_amount( $product->get_stock_quantity() ) . ')';
		}
		
		return $stock_html;
	}
	
	public function sortable(): bool {
		return true;
	}
}
