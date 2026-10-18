<?php namespace BulkPriceEditor\Integrations;

use BulkPriceEditor\Integrations\TieredPricing\TieredPricingIntegration;

class Integrations {
	
	protected array $integrations = array();
	
	public function __construct() {
		
		$integrations = apply_filters( 'bulk_price_editor/integrations', array(
			TieredPricingIntegration::class,
		) );
		
		$this->integrations = array_filter( $integrations, function ( $integration ) {
			return is_subclass_of( $integration, Integration::class );
		} );
		
		$this->initIntegrations();
	}
	
	protected function initIntegrations() {
		foreach ( $this->integrations as $integration ) {
			$integration = new $integration();
			
			$integration->run();
		}
	}
}
