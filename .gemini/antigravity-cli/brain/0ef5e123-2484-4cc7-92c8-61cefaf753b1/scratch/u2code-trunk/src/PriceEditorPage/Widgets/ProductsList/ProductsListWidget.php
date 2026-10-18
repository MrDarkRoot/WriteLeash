<?php namespace BulkPriceEditor\PriceEditorPage\Widgets\ProductsList;

use BulkPriceEditor\Actions\SchedulePriceUpdatesAction;
use BulkPriceEditor\ProductFilters\FilterManager;
use BulkPriceEditor\ProductFilters\Templates\TemplateManager;
use BulkPriceEditor\ProductQuery\ProductQuery;
use BulkPriceEditor\ProductsTable\Services\AjaxHandler;
use BulkPriceEditor\ProductsTable\WPListProductsTable;
use BulkPriceEditor\PriceEditorPage\Widgets\Widget;

class ProductsListWidget extends Widget {
	
	public function getTitle(): string {
		return __( 'Preview and Apply changes', 'bulk-price-editor' );
	}
	
	public function getDescription(): string {
		return __( 'Review updates and confirm changes.', 'bulk-price-editor' );
	}
	
	public function getStepNumber(): string {
		return '3';
	}
	
	public function getSize(): int {
		return 100;
	}
	
	protected function renderContent() {
		
		$template = TemplateManager::getInstance()->getTemplateFromURL();
		
		$filtersManager = new FilterManager( array(), $template );
		$query          = new ProductQuery( $filtersManager );
		
		$productsTable = new WPListProductsTable( $query );
		$productsTable->addSorting();
		$productsTable->prepare_items();
		
		?>
		<div id="bulk-price-editor-product-table"
			 class="bulk-price-editor-product-table"
			 data-total-products="<?php echo esc_attr( $productsTable->get_pagination_arg( 'total_items' ) ); ?>"
			 data-nonce="<?php echo esc_attr( wp_create_nonce( AjaxHandler::AJAX_ACTION ) ); ?>"
			 data-update-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			 data-update-action="<?php echo esc_attr( AjaxHandler::AJAX_ACTION ); ?>">
			<?php $productsTable->display(); ?>
		</div>
		<?php
	}
	
	public function renderActions() {
		?>
		<div class="bulk-price-editor-widget-actions">
			<div id="bulk-price-editor-apply-changes"
				 data-action-url="<?php echo esc_attr( SchedulePriceUpdatesAction::getURL() ); ?>">

				<div class="bulk-price-editor-buttons">
					<button id="bulk-price-editor-export-csv-button"
							class="bulk-price-editor-button bulk-price-editor-button--secondary"
							title="<?php esc_attr_e( 'Download the preview as CSV: every matched product with its current and new prices.', 'bulk-price-editor' ); ?>">
						<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Export CSV', 'bulk-price-editor' ); ?>
					</button>

					<button id="bulk-price-editor-apply-changes-button"
							class="bulk-price-editor-button bulk-price-editor-button--primary">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Apply changes', 'bulk-price-editor' ); ?>
					</button>
				</div>

			</div>
		</div>
		<?php
	}
	
	protected function renderFooter() {}
}