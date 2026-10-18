<?php namespace BulkPriceEditor\ProductsTable\Columns;

use BulkPriceEditor\ProductsTable\WPListProductsTable;
use WC_Product;

class DateColumn extends Column {
	
	public function getSlug(): string {
		return 'date';
	}
	
	public function getName(): string {
		return __( 'Date', 'bulk-price-editor' );
	}
	
	public function render( WC_Product $product, WPListProductsTable $table ): string {

		$dateCreated = $product->get_date_created();

		// translators: %1$s: date, %2$s: time
		$date = $dateCreated ? sprintf( __( '%1$s at %2$s', 'bulk-price-editor' ), $dateCreated->format( 'Y/m/d' ),
			$dateCreated->format( 'g:i a' ) ) : '—';
		
		switch ( $product->get_status() ) {
			case 'publish':
				$status = __( 'Published', 'bulk-price-editor' );
				break;
			case 'future':
				$status = __( 'Scheduled', 'bulk-price-editor' );
				break;
			case 'draft':
				$status = __( 'Draft', 'bulk-price-editor' );
				break;
			case 'pending':
				$status = __( 'Pending Review', 'bulk-price-editor' );
				break;
			case 'private':
				$status = __( 'Private', 'bulk-price-editor' );
				break;
			case 'trash':
				$status = __( 'Trash', 'bulk-price-editor' );
				break;
			default:
				$status = __( 'Unknown', 'bulk-price-editor' );
		}
		
		return $status . '<br>' . $date;
	}
	
	public function sortable(): bool {
		return true;
	}
}
