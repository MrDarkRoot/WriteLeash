<?php namespace BulkPriceEditor\ProductsTable\Columns;

use BulkPriceEditor\ProductsTable\WPListProductsTable;
use WC_Product;

class ImageColumn extends Column {
	
	public function getSlug(): string {
		return 'image';
	}
	
	public function getName(): string {
		return '<span class="wc-image tips">Image</span>';
	}
	
	public function render( WC_Product $product, WPListProductsTable $table ): string {
		$productLink = get_edit_post_link( $product->get_id() );
		ob_start();
		?>
		
		<a href="<?php echo esc_attr( $productLink ); ?>">
			<?php echo wp_kses_post( $product->get_image() ); ?>
		</a>
		
		<?php
		return ob_get_clean();
	}
	
	public function sortable(): bool {
		return false;
	}
}