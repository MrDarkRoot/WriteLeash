<?php namespace BulkPriceEditor\PriceEditorPage\Modals;

use BulkPriceEditor\ProductFilters\Templates\TemplateManager;

class SaveTemplate {
	
	public function getSLUG(): string {
		return 'save-template';
	}
	
	public function render() {
		
		$saveTemplateURL = add_query_arg( array(
			'action' => TemplateManager::ADD_TEMPLATE_ACTION,
			'nonce'  => wp_create_nonce( TemplateManager::ADD_TEMPLATE_ACTION ),
		), admin_url( 'admin-ajax.php' ) );
		
		?>
		<div class="bulk-price-editor-modal-popup" style="max-width: 600px"
			 data-save-action-url="<?php echo esc_attr( $saveTemplateURL ); ?>">

			<div class="bulk-price-editor-modal-popup__header">
				<div class="bulk-price-editor-modal-popup__title">
					<?php esc_html_e( 'Save template', 'bulk-price-editor' ); ?>
				</div>
				<div class="bulk-price-editor-modal-popup__actions">
					<button class="bulk-price-editor-modal-popup__close	">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
			</div>

			<div class="bulk-price-editor-modal-popup__content">
				<div class="bulk-price-editor-widget-row">

					<div class="bulk-price-editor-widget-row__name">
						<?php esc_html_e( 'Template name', 'bulk-price-editor' ); ?>
					</div>

					<div class="bulk-price-editor-widget-row__value">
						<input type="text" id="bpe_template_name" name="bpe_template_name" value="" maxlength="200" placeholder="">
					</div>

				</div>

				<div class="bulk-price-editor-widget-row">

					<div class="bulk-price-editor-widget-row__name">
						<?php esc_html_e( 'Price modifier', 'bulk-price-editor' ); ?>
					</div>

					<div class="bulk-price-editor-widget-row__value">
						<label>
							<input type="checkbox" id="bpe_template_include_modifier" checked>
							<?php esc_html_e( 'Also save the selected price modification', 'bulk-price-editor' ); ?>
						</label>
						<p class="description" style="margin: 6px 0 0;">
							<?php esc_html_e( 'The template becomes a reusable job: filters and pricing together, ready to re-run or schedule.', 'bulk-price-editor' ); ?>
						</p>
					</div>

				</div>
			</div>

			<div class="bulk-price-editor-modal-popup__footer">

				<div class="bulk-price-editor-buttons">

					<button class="bulk-price-editor-button bulk-price-editor-button--primary bulk-price-editor-modal-popup__confirm">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save', 'bulk-price-editor' ); ?>
					</button>

					<button class="bulk-price-editor-button bulk-price-editor-button--secondary bulk-price-editor-modal-popup__close">
						<?php esc_html_e( 'Cancel', 'bulk-price-editor' ); ?>
					</button>

				</div>
			</div>
		</div>
		<?php
	}
	
}