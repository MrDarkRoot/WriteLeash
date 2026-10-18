<?php namespace BulkPriceEditor\PriceEditorPage\Widgets\PriceModifiers;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\PriceModifiers\Dispatcher;
use BulkPriceEditor\PriceModifiers\Modifiers\AddFlatValue;
use BulkPriceEditor\PriceModifiers\Modifiers\IncreaseByPercent;
use BulkPriceEditor\PriceModifiers\Modifiers\NewPrices;
use BulkPriceEditor\PriceModifiers\Modifiers\PriceModifier;
use BulkPriceEditor\PriceModifiers\Modifiers\RemovePrice;
use BulkPriceEditor\PriceModifiers\Modifiers\DecreaseByPercent;
use BulkPriceEditor\PriceModifiers\Modifiers\SubtractFlatValue;

class PriceModifiersWidget extends Widget {
	
	public string $defaultModifier = 'new_prices';
	
	public function getTitle(): string {
		return __( 'Edit Prices', 'bulk-price-editor' );
	}
	
	public function getDescription(): string {
		return __( 'Select how you want to adjust prices for selected products.', 'bulk-price-editor' );
	}
	
	public function getStepNumber(): string {
		return '2';
	}
	
	protected function renderContent() {

		$priceModifiers = Dispatcher::getInstance()->getRegisteredPriceModifiers();

		// Templates saved with a price modification pre-fill the fields (JS
		// reads this attribute on load).
		$template         = \BulkPriceEditor\ProductFilters\Templates\TemplateManager::getInstance()->getTemplateFromURL();
		$templateModifier = $template ? $template->getModifier() : null;

		?>
		<div id="bulk-price-editor-price-adjustments"
			<?php if ( $templateModifier ) : ?>
				data-template-modifier="<?php echo esc_attr( wp_json_encode( $templateModifier ) ); ?>"
			<?php endif; ?>
		>
			<div class="bulk-price-editor-price-modificators-tabs">
				
				<?php foreach ( $priceModifiers as $modifier ) : ?>
					
					<?php
					$instance = new $modifier();
					
					$active = $instance->getType() === $this->defaultModifier;
					?>

					<div class="bulk-price-editor-price-modificator-tab <?php echo $active ? 'bulk-price-editor-price-modificator-tab--active' : ''; ?>"
						 data-target="<?php echo esc_attr( $instance->getType() ); ?>">
						<?php echo esc_html( $instance->getName() ); ?>
					</div>
				<?php endforeach; ?>

			</div>

			<div class="bulk-price-editor-price-modificators">
				<?php foreach ( $priceModifiers as $modifier ) : ?>
					
					<?php
					$instance = new $modifier();
					
					$active = $instance->getType() === $this->defaultModifier;
					?>

					<div class="bulk-price-editor-price-modificator <?php echo $active ? 'bulk-price-editor-price-modificator--active' : ''; ?>"
						 id="<?php echo esc_attr( $instance->getType() ); ?>">

						<div class="bulk-price-editor-price-modificator__main">
							<?php $instance->renderFields( $this ); ?>
						</div>
						
						<?php if ( $instance->hadAdvancedOptions() ) : ?>
							<div class="bulk-price-editor-price-modificator__advanced">
								<div class="bulk-price-editor-widget-section ">

									<div class="bulk-price-editor-widget-section__header">

										<div class="bulk-price-editor-widget-section__header-title">
											<?php esc_html_e( 'Advanced Options', 'bulk-price-editor' ); ?>
										</div>

										<div class="bulk-price-editor-widget-section-actions">
											<div class="bulk-price-editor-widget-section-actions__toggle-view">
												<span class="dashicons dashicons-arrow-down-alt2"></span>
											</div>
										</div>
									</div>

									<div class="bulk-price-editor-widget-section__content">
										<?php $instance->renderAdvancedFields( $this ); ?>
									</div>
								</div>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
	
	protected function renderFooter() {}
}