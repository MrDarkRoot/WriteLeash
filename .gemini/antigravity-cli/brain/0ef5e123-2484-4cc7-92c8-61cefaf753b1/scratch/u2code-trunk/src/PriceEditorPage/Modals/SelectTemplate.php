<?php namespace BulkPriceEditor\PriceEditorPage\Modals;

use BulkPriceEditor\ProductFilters\Templates\TemplateManager;

class SelectTemplate {
	
	public function getSLUG(): string {
		return 'select-template';
	}
	
	public function render() {
		?>
		<div class="bulk-price-editor-modal-popup" style="max-width: 600px">

			<div class="bulk-price-editor-modal-popup__header">
				<div class="bulk-price-editor-modal-popup__title">
					<?php esc_html_e( 'Select template', 'bulk-price-editor' ); ?>
				</div>
				<div class="bulk-price-editor-modal-popup__actions">
					<button class="bulk-price-editor-modal-popup__close	">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
			</div>

			<div class="bulk-price-editor-modal-popup__content" style="max-height: 350px; overflow: auto">
				
				<?php
					
					$templates        = TemplateManager::getInstance()->getTemplates();
					$selectedTemplate = TemplateManager::getInstance()->getTemplateFromURL();
				?>
				
				<?php if ( ! empty( $templates ) ) : ?>
					<div class="bulk-price-editor-templates">
						<?php foreach ( $templates as $template ) : ?>
							
							<?php
							
							$isSelected = $selectedTemplate && $selectedTemplate->getSlug() === $template->getSlug();
							?>

							<div class="bulk-price-editor-template <?php echo esc_attr( $isSelected ? 'bulk-price-editor-template--selected' : '' ); ?>">
								<div class="bulk-price-editor-template__name">
									<?php if ( $isSelected ) : ?>
										<span class="dashicons dashicons-yes"></span>
									<?php endif; ?>
									<?php echo esc_html( $template->getName() ); ?>
								</div>
								<div class="bulk-price-editor-template__actions">
									<div class="bulk-price-editor-buttons" style="flex-wrap: nowrap">
										
										<?php if ( ! $isSelected ) : ?>

											<a href="<?php echo esc_attr( $template->getURL() ); ?>"
											   class="bulk-price-editor-button bulk-price-editor-button--small bulk-price-editor-button--primary bulk-price-editor-button--select-template"
											   data-slug="<?php echo esc_attr( $template->getSlug() ); ?>">
												<?php esc_html_e( 'Select', 'bulk-price-editor' ); ?>
											</a>

											<a href="<?php echo esc_attr( $template->getRemoveActionURL() ); ?>"
											   onclick="return confirm('Are you sure?')"
											   class="bulk-price-editor-button bulk-price-editor-button--small bulk-price-editor-button--secondary bulk-price-editor-button--remove-template"
											   data-slug="<?php echo esc_attr( $template->getSlug() ); ?>">
												<?php esc_html_e( 'Remove', 'bulk-price-editor' ); ?>
											</a>
										
										<?php else : ?>

											<a href="<?php echo esc_attr( $template->getRemoveActionURL() ); ?>"
											   onclick="return confirm('Are you sure?')"
											   class="bulk-price-editor-button bulk-price-editor-button--small bulk-price-editor-button--primary bulk-price-editor-button--remove-template"
											   data-slug="<?php echo esc_attr( $template->getSlug() ); ?>">
												<?php esc_html_e( 'Remove', 'bulk-price-editor' ); ?>
											</a>
										<?php endif; ?>

									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<div class="bulk-price-editor-templates-not-found">
						<?php esc_html_e( 'No templates found.', 'bulk-price-editor' ); ?>
						<br>
						<br>
						<?php 
						esc_html_e( 'You can save current filters as a template and use them later.',
							'bulk-price-editor' ); 
						?>
					</div>
				<?php endif; ?>

			</div>

			<div class="bulk-price-editor-modal-popup__footer">

				<div class="bulk-price-editor-buttons">

					<button class="bulk-price-editor-button bulk-price-editor-button--secondary bulk-price-editor-modal-popup__close">
						<?php esc_html_e( 'Cancel', 'bulk-price-editor' ); ?>
					</button>

				</div>
			</div>
		</div>
		<?php
	}
	
}