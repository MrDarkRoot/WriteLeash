<?php namespace BulkPriceEditor\ProductsTable\Columns;

use BulkPriceEditor\ProductsTable\WPListProductsTable;
use WC_Product;

class CategoriesColumn extends Column {
	
	public function getSlug(): string {
		return 'categories';
	}
	
	public function getName(): string {
		return __( 'Categories', 'bulk-price-editor' );
	}
	
	public function render( WC_Product $product, WPListProductsTable $table ): string {
		return implode( ', ', array_filter( array_map( function ( $categoryId ) {
			$category = get_term_by( 'id', $categoryId, 'product_cat' );

			if ( ! $category ) {
				return '';
			}

			return sprintf( '<a target="_blank" href="%s">%s</a>',
				esc_url( admin_url( 'edit-tags.php?action=edit&taxonomy=product_cat&tag_ID=' . $categoryId . '&post_type=product' ) ),
				esc_html( $category->name ) );

		}, $product->get_category_ids() ) ) );
	}
	
	public function sortable(): bool {
		return false;
	}
}
