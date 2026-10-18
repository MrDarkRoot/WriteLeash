<?php namespace BulkPriceEditor\ProductsTable\Columns;

use BulkPriceEditor\ProductsTable\WPListProductsTable;
use WC_Product;

class ProductTypeColumn extends Column {
	
	public function getSlug(): string {
		return 'product_type';
	}
	
	public function getName(): string {
		return __( 'Type', 'bulk-price-editor' );
	}
	
	public function render( WC_Product $product, WPListProductsTable $table ): string {
		return ucfirst( esc_html( $product->get_type() ) );
	}
	
	public function sortable(): bool {
		return false;
	}
}
