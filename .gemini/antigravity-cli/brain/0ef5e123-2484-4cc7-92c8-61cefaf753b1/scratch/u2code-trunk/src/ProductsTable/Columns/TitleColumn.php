<?php namespace BulkPriceEditor\ProductsTable\Columns;

use BulkPriceEditor\History\PriceLogRepository;
use BulkPriceEditor\ProductsTable\WPListProductsTable;
use WC_Product;

class TitleColumn extends Column {
	
	public function getSlug(): string {
		return 'title';
	}
	
	public function getName(): string {
		return __( 'Name', 'bulk-price-editor' );
	}
	
	public function render( WC_Product $product, WPListProductsTable $table ): string {
		$productLink = sprintf( '<a href="%s">%s</a>', get_edit_post_link( $product->get_id() ),
			esc_html( $product->get_name() ) );
		
		
		if ( $product->get_sku() ) {
			$productLink .= '<br> SKU: <code>' . esc_html( $product->get_sku() ) . '</code>';
		}

		return $productLink . $this->renderRowActions( $product );
	}

	/**
	 * "Price history (N)" as a hover row action, opening the same modal as on
	 * the product edit screen.
	 */
	protected function renderRowActions( WC_Product $product ): string {

		$count = PriceLogRepository::countForProduct( $product->get_id() );

		if ( ! $count && ! PriceLogRepository::isEnabled() ) {
			return '';
		}

		$label = $count
			/* translators: %d: number of recorded changes */
			? sprintf( __( 'Price history (%d)', 'bulk-price-editor' ), $count )
			: __( 'Price history', 'bulk-price-editor' );

		return sprintf(
			'<div class="row-actions"><span class="bpe-price-history"><a href="#" class="bpe-price-history-link" data-product-id="%d">%s</a></span></div>',
			$product->get_id(),
			esc_html( $label )
		);
	}
	
	public function sortable(): bool {
		return true;
	}
}
