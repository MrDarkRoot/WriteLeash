<?php namespace BulkPriceEditor\Integrations\TieredPricing;

use BulkPriceEditor\BulkPriceEditorPlugin;
use BulkPriceEditor\Core\ServiceContainerTrait;
use BulkPriceEditor\Integrations\Integration;
use BulkPriceEditor\PriceEditorPage\PriceEditorPage;
use BulkPriceEditor\Request\Request;
use TierPricingTable\TierPricingTablePlugin;

class TieredPricingIntegration extends Integration {
	
	use ServiceContainerTrait;
	
	public function getName(): string {
		return 'Tiered Pricing Table';
	}
	
	public function getDescription(): string {
		return __( 'Integrates with Tiered Pricing Table plugin', 'bulk-price-editor' );
	}
	
	public function getSlug(): string {
		return 'tiered-pricing-table';
	}
	
	public function run(): void {
		
		add_action( 'init', function () {
			
			if ( ! class_exists( TierPricingTablePlugin::class ) ) {
				return;
			}
			
			add_action( 'admin_enqueue_scripts', function () {
				
				if ( ! PriceEditorPage::isCurrentPage() ) {
					return;
				}
				
				$styleURL = $this->getContainer()->getFileManager()->getPluginUrl() . 'src/Integrations/TieredPricing/assets/styles.css';
				
				wp_enqueue_style( 'bulk-price-editor-tiered-pricing', $styleURL, array(),
					BulkPriceEditorPlugin::VERSION );
			} );
			
			add_filter( 'bulk_price_editor/price_modifiers', function ( $modifiers ) {
				$modifiers[] = TieredPricingModifier::class;
				
				return $modifiers;
			} );
			
			add_action( 'bulk_price_editor/products_table/columns', function ( $columns ) {
				
				$selectedModifier = Request::getPriceModifierData( $_REQUEST );
				
				if ( empty( $selectedModifier['type'] ) || TieredPricingModifier::TYPE !== $selectedModifier['type'] ) {
					return $columns;
				}
				
				$newColumns = array();
				
				for ( $i = 0; $i < count( $columns ); $i ++ ) {
					
					// If we are at the last column, add our column before
					if ( count( $columns ) - 1 === $i ) {
						$newColumns[] = new TieredPricingTableColumn();
					}
					
					if ( in_array( $columns[ $i ]->getSlug(), array( 'product_type', 'stock_status', 'date' ) ) ) {
						continue;
					}
					
					$newColumns[] = $columns[ $i ];
				}
				
				return $newColumns;
			} );
		} );
	}
}
