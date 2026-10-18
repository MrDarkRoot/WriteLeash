<?php namespace BulkPriceEditor\Integrations\TieredPricing;

use BulkPriceEditor\ProductsTable\Columns\Column;
use BulkPriceEditor\ProductsTable\WPListProductsTable;
use TierPricingTable\PriceManager;
use TierPricingTable\PricingRule;
use WC_Product;

class TieredPricingTableColumn extends Column {
	
	public function getName(): string {
		return 'Tiered Pricing';
	}
	
	public function getSlug(): string {
		return 'tiered-pricing-table';
	}
	
	public function render( WC_Product $product, WPListProductsTable $table ): string {
		$fixedPricingRules = PriceManager::getFixedPriceRules( $product->get_id(), 'edit' );
		$percentageRules   = PriceManager::getPercentagePriceRules( $product->get_id(), 'edit' );
		$pricingType       = PriceManager::getPricingType( $product->get_id(), 'edit' );
		
		$pricingRule = new PricingRule( $product->get_id() );
		$pricingRule->setType( $pricingType );
		$pricingRule->setRules( 'fixed' === $pricingType ? $fixedPricingRules : $percentageRules );
		
		$updatedPricingRule = null;
		
		if ( $table->priceModifier instanceof TieredPricingModifier ) {
			$updatedPricingRule = $table->priceModifier->getUpdatedTieredPricing( $product );
		}
		ob_start();
		?>
		<div class="bpe_tiered-pricing-table-wrapper">
			
			<?php $this->renderPricingTable( $pricingRule ); ?>
			
			<?php if ( ! is_null( $updatedPricingRule ) ) : ?>
				<div class="bpe_tiered-pricing-table-arrow" style="align-self: center">
					→
				</div>
				<?php $this->renderPricingTable( $updatedPricingRule, true ); ?>
			<?php endif; ?>

		</div>
		<?php 
		return ob_get_clean();
	}
	
	public function renderPricingTable( PricingRule $pricingRule, $isUpdated = false ): void {
		
		if ( empty( $pricingRule->getRules() ) ) {
			?>
			<div style="width: 100%; padding: 0 5px; align-self: center">
				<b><?php esc_html_e( 'No tiered pricing rules', 'bulk-price-editor' ); ?></b>
			</div>
			<?php
			return;
		}
		?>

		<div class="bpe_tiered-pricing-table <?php echo esc_attr( $isUpdated ? 'bpe_tiered-pricing-table--updated' : '' ); ?>">
			<div class="bpe_tiered-pricing-table-type">
				<b><?php esc_html_e( 'Type', 'bulk-price-editor' ); ?>:</b>
				<?php 
				echo esc_html( $pricingRule->isFixed() ? __( 'Fixed', 'bulk-price-editor' ) : __( 'Percentage',
					'bulk-price-editor' ) );
				?>
			</div>
			<div class="bpe_tiered-pricing-table__header">
				<div class="bpe_tiered-pricing-table__header-cell">
					<?php esc_html_e( 'Quantity', 'bulk-price-editor' ); ?>
				</div>
				
				<?php if ( $pricingRule->isPercentage() ) : ?>
					<div class="bpe_tiered-pricing-table__header-cell">
						<?php esc_html_e( 'Discount', 'bulk-price-editor' ); ?>
					</div>
				<?php else : ?>
					<div class="bpe_tiered-pricing-table__header-cell">
						<?php esc_html_e( 'Price', 'bulk-price-editor' ); ?>
					</div>
				<?php endif; ?>
			</div>
			<?php foreach ( $pricingRule->getRules() as $quantity => $price ) : ?>
				<?php if ( $pricingRule->isPercentage() ) : ?>
					<div class="bpe_tiered-pricing-table__row">
						<div class="bpe_tiered-pricing-table__cell"><?php echo esc_html( $quantity ); ?></div>
						<div class="bpe_tiered-pricing-table__cell"><?php echo esc_html( $price . '%' ); ?></div>
					</div>
				<?php else : ?>
					<div class="bpe_tiered-pricing-table__row">
						<div class="bpe_tiered-pricing-table__cell"><?php echo esc_html( $quantity ); ?></div>
						<div class="bpe_tiered-pricing-table__cell"><?php echo wp_kses_post( wc_price( $price ) ); ?></div>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}
	
	public function sortable(): bool {
		return false;
	}
}