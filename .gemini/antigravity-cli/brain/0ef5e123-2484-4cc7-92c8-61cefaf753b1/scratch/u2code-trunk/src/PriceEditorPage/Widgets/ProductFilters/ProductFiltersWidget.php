<?php namespace BulkPriceEditor\PriceEditorPage\Widgets\ProductFilters;

use BulkPriceEditor\PriceEditorPage\Widgets\Widget;
use BulkPriceEditor\ProductFilters\Filters\ExcludedProducts;
use BulkPriceEditor\ProductFilters\Filters\Filter;
use BulkPriceEditor\ProductFilters\Filters\FilterByAttributes;
use BulkPriceEditor\ProductFilters\Filters\DateRange;
use BulkPriceEditor\ProductFilters\Filters\IncludedProducts;
use BulkPriceEditor\ProductFilters\Filters\PriceRange;
use BulkPriceEditor\ProductFilters\Filters\ProductStatus;
use BulkPriceEditor\ProductFilters\Filters\ProductType;
use BulkPriceEditor\ProductFilters\Filters\SKUAndName;
use BulkPriceEditor\ProductFilters\Templates\Template;
use BulkPriceEditor\ProductFilters\Templates\TemplateManager;

class ProductFiltersWidget extends Widget {
	
	/**
	 * Array of available filters
	 *
	 * @var Filter[]
	 */
	protected array $filters;
	
	protected ?Template $template;
	
	public function __construct() {
		
		$this->template = TemplateManager::getInstance()->getTemplateFromURL();
		
		// Brand and tag fields render inside the Included/Excluded Products
		// sections; their query filters are registered in FilterManager.
		$filters = array(
			new IncludedProducts( array() ),
			new ExcludedProducts( array() ),
			new ProductStatus( array() ),
			new PriceRange( array() ),
			new DateRange( array() ),
			new ProductType( array() ),
			new SKUAndName( array() ),
			new FilterByAttributes( array() ),
		);

		$this->filters = apply_filters( 'bulk_price_editor/editor_page/filters', $filters );
		
		foreach ( $this->filters as $filter ) {
			$filter->setTemplate( $this->template );
		}
	}
	
	public function getTitle(): string {
		return __( 'Filter Products', 'bulk-price-editor' );
	}
	
	public function getDescription(): string {
		return __( 'Filter products you want to apply price changes.', 'bulk-price-editor' );
	}
	
	public function getStepNumber(): string {
		return '1';
	}
	
	protected function renderContent() {
		?>
		<div id="bulk-price-editor-product-filters"
			 data-is-template="<?php echo esc_attr( $this->template ? 'yes' : 'no' ); ?>">
			<?php foreach ( $this->filters as $filter ) : ?>
				
				<?php $isOpened = $filter->getSectionArgs()['open'] ?? false; ?>

				<div class="bulk-price-editor-widget-section <?php echo esc_attr( $isOpened ? 'bulk-price-editor-widget-section--open' : '' ); ?>">

					<div class="bulk-price-editor-widget-section__header">

						<div class="bulk-price-editor-widget-section__header-title">
							<?php echo esc_html( $filter->getTitle() ); ?>
						</div>

						<div class="bulk-price-editor-widget-section-actions">
							<div class="bulk-price-editor-widget-section-actions__toggle-view">
								<span class="dashicons dashicons-arrow-down-alt2"></span>
							</div>
						</div>

					</div>

					<div class="bulk-price-editor-widget-section__content">
						
						<?php if ( $filter->isPremium() && ! bpefw_fs()->can_use_premium_code() ): ?>
							<div class="bulk-price-editor-widget-premium-notice">
								<?php esc_html_e( 'This filter is available in the premium version.',
									'bulk-price-editor-for-woocommerce' ); ?>
								<a href="<?php echo esc_attr( bpefw_fs_upgrade_url() ) ?>" target="_blank">
									<?php esc_html_e( 'Upgrade your plan', 'bulk-price-editor-for-woocommerce' ); ?>
								</a>
							</div>

							<div class="bulk-price-editor-widget-premium-paywall">
								<?php $filter->renderFields( $this ); ?>
							</div>
						<?php else: ?>
							<?php $filter->renderFields( $this ); ?>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
	
	public function renderActions() {
		?>
		<div class="bulk-price-editor-widget-actions">
			<div class="bulk-price-editor-buttons" style="flex-wrap: nowrap">
				
				<?php if ( $this->template ) : ?>
					<button class="bulk-price-editor-button bulk-price-editor-button--small bulk-price-editor-button--secondary  bulk-price-editor-button--select-template">
						<span class="dashicons dashicons-category"></span>
						<?php esc_html_e( 'Manage templates', 'bulk-price-editor' ); ?>
					</button>
				<?php else : ?>
					<button class="bulk-price-editor-button bulk-price-editor-button--small bulk-price-editor-button--secondary bulk-price-editor-button--save-template">
						<span class="dashicons dashicons-cloud-saved"></span>
						<?php esc_html_e( 'Save as template', 'bulk-price-editor' ); ?>
					</button>
					<button class="bulk-price-editor-button bulk-price-editor-button--small bulk-price-editor-button--secondary  bulk-price-editor-button--select-template">
						<span class="dashicons dashicons-category"></span>
						<?php esc_html_e( 'Select template', 'bulk-price-editor' ); ?>
					</button>
				<?php endif; ?>

			</div>
		</div>
		<?php
	}
	
	protected function renderFooter() {
		if ( ! $this->template ) {
			return;
		}
		
		$templateUpdateUrl = add_query_arg( array(
			'action'   => TemplateManager::UPDATE_TEMPLATE_ACTION,
			'_wpnonce' => wp_create_nonce( TemplateManager::UPDATE_TEMPLATE_ACTION ),
			'slug'     => $this->template->getSlug(),
		), admin_url( 'admin-ajax.php' ) );
		
		$dismissTemplateURL = remove_query_arg( array( 'template' ) );
		
		?>
		<div class="bulk-price-editor-widget__meta" style="display: flex;
					justify-content: space-between;
					align-items: center; word-break: break-word;
  flex-wrap: wrap; gap:20px;">
			<div>
				<a href="<?php echo esc_html( $dismissTemplateURL ); ?>" style="text-decoration: none; color:#333">
					<span class="dashicons dashicons-dismiss"></span>
				</a>
				<?php esc_html_e( 'Template:', 'bulk-price-editor' ); ?>
				<b><i><?php echo esc_html( $this->template->getName() ); ?></i></b>
			</div>
			<div>
				<div class="bulk-price-editor-buttons">

					<button data-slug="<?php echo esc_attr( $this->template->getSlug() ); ?>"
							data-update-url="<?php echo esc_url( $templateUpdateUrl ); ?>"
							class="bulk-price-editor-button bulk-price-editor-button--small bulk-price-editor-button--secondary bulk-price-editor-button--update-template">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Update template', 'bulk-price-editor' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}
}