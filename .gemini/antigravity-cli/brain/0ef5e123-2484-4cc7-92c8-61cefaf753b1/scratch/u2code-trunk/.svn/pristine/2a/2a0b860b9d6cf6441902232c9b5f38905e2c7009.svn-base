<?php namespace BulkPriceEditor\ProductsTable\Services;

use BulkPriceEditor\PriceModifiers\Modifiers\PriceModifier;
use BulkPriceEditor\ProductQuery\ProductQuery;
use BulkPriceEditor\ProductsTable\WPListProductsTable;
use BulkPriceEditor\PriceModifiers\Dispatcher;
use BulkPriceEditor\ProductFilters\FilterManager;

class AjaxHandler {
	
	const AJAX_ACTION = 'bpe_load_products_table';
	
	public function __construct() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handleAJAXUpdate' ) );
	}
	
	public function handleAJAXUpdate() {

		// Check for permissions if necessary
		if ( ! current_user_can( \BulkPriceEditor\BulkPriceEditorPlugin::capability() ) ) {
			wp_die( esc_html__( 'Permission Denied', 'bulk-price-editor' ) );
		}
		
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		
		$query = new ProductQuery( $this->getFiltersManager( $_REQUEST ) );
		
		$priceModifier = $this->getPriceModifier( $_REQUEST );
		
		// Output the table
		$table = new WPListProductsTable( $query, $priceModifier );
		$table->addSorting();
		
		$table->prepare_items();
		ob_start();
		$table->display();
		$output = ob_get_clean();
		
		// Send response
		wp_send_json_success( [ 'html' => $output ] );
	}
	
	public function getFiltersManager( $request ): FilterManager {
		return new FilterManager( self::getRawFiltersFromRequest( $request ) );
	}
	
	public function getPriceModifier( $request ): ?PriceModifier {
		$priceModifiersDispatcher = Dispatcher::getInstance();
		
		$priceModifierData = self::getRawPriceModifiersDataFromRequest( $request );
		
		$type = isset( $priceModifierData['type'] ) ? (string) $priceModifierData['type'] : '';
		$args = isset( $priceModifierData['args'] ) ? (array) $priceModifierData['args'] : array();
		
		return $priceModifiersDispatcher->dispatchModifier( $type, $args );
	}
	
	public static function getRawFiltersFromRequest( $request ): array {
		// (array) cast: malformed JSON decodes to null, which would violate the return type.
		return isset( $request['filters'] ) ? (array) json_decode( stripslashes( $request['filters'] ), true ) : array();
	}
	
	public static function getRawPriceModifiersDataFromRequest( $request ): array {
		return isset( $request['priceModifiers'] ) ? json_decode( stripslashes( $request['priceModifiers'] ),
			true ) : array();
	}
}
