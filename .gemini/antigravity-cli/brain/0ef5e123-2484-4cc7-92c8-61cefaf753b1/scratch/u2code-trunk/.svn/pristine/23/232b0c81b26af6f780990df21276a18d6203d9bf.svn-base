<?php namespace BulkPriceEditor\History;

use BulkPriceEditor\BulkPriceEditorPlugin;
use BulkPriceEditor\Core\ServiceContainer;
use BulkPriceEditor\PriceEditorPage\PriceEditorPage;
use WC_Product;

/**
 * Price history on the product edit screen: a "Price history" link next to
 * the pricing fields (and inside every variation's pricing row) that opens
 * a modal with that product's recorded price changes.
 */
class PriceLogProductScreen {

	const AJAX_ACTION = 'bpe_price_history';

	const ENTRIES = 50;

	public static function init(): void {
		add_action( 'woocommerce_product_options_pricing', array( self::class, 'renderProductLink' ) );
		add_action( 'woocommerce_variation_options_pricing', array( self::class, 'renderVariationLink' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueueAssets' ) );
		add_action( 'admin_footer', array( self::class, 'renderModal' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handleAjax' ) );
	}

	/**
	 * The modal is needed on the product edit screen and on the editor page,
	 * where the preview table offers the same link as a row action.
	 */
	protected static function isProductScreen(): bool {

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && in_array( $screen->id, array( 'product', 'woocommerce_page_' . PriceEditorPage::PAGE_SLUG ), true );
	}

	public static function renderProductLink(): void {
		global $post;

		if ( $post ) {
			self::renderLink( (int) $post->ID, 'form-field' );
		}
	}

	public static function renderVariationLink( $loop, $variationData, $variation ): void {

		if ( $variation && ! empty( $variation->ID ) ) {
			self::renderLink( (int) $variation->ID, 'form-row form-row-full' );
		}
	}

	/**
	 * The link is shown when tracking is on, or when history already exists
	 * (recorded before tracking was switched off).
	 */
	protected static function renderLink( int $productId, string $wrapperClass ): void {

		$count = PriceLogRepository::countForProducts( array( $productId ) );

		if ( ! $count && ! PriceLogRepository::isEnabled() ) {
			return;
		}

		/*
		 * Rendered as its own row as a fallback; the script moves the link
		 * inline next to WooCommerce's "Schedule" link under the sale price.
		 */
		?>
		<p class="<?php echo esc_attr( $wrapperClass ); ?> bpe-price-history-field">
			<a href="#" class="bpe-price-history-link" data-product-id="<?php echo esc_attr( $productId ); ?>">
				<?php
				if ( $count ) {
					/* translators: %d: number of recorded changes */
					echo esc_html( sprintf( __( 'Price history (%d)', 'bulk-price-editor' ), $count ) );
				} else {
					esc_html_e( 'Price history', 'bulk-price-editor' );
				}
				?>
			</a>
		</p>
		<?php
	}

	public static function enqueueAssets( $hook ): void {

		if ( ! self::isProductScreen() ) {
			return;
		}

		$fileManager = ServiceContainer::getInstance()->getFileManager();

		wp_enqueue_style( 'bpe-price-history', $fileManager->locateAsset( 'admin/price-history.css' ), array(),
			BulkPriceEditorPlugin::VERSION );

		wp_enqueue_script( 'bpe-price-history', $fileManager->locateAsset( 'admin/price-history.js' ),
			array( 'jquery' ), BulkPriceEditorPlugin::VERSION, true );

		wp_localize_script( 'bpe-price-history', 'bpePriceHistory', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::AJAX_ACTION,
			'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
			'i18n'    => array(
				'loading' => __( 'Loading…', 'bulk-price-editor' ),
				'error'   => __( 'Could not load the price history. Please try again.', 'bulk-price-editor' ),
			),
		) );
	}

	public static function renderModal(): void {

		if ( ! self::isProductScreen() ) {
			return;
		}

		?>
		<div id="bpe-price-history-modal" class="bpe-price-history-modal" hidden>
			<div class="bpe-price-history-modal__overlay"></div>
			<div class="bpe-price-history-modal__box" role="dialog" aria-modal="true" aria-labelledby="bpe-price-history-title">
				<div class="bpe-price-history-modal__header">
					<h2 id="bpe-price-history-title"><?php esc_html_e( 'Price history', 'bulk-price-editor' ); ?></h2>
					<button type="button" class="bpe-price-history-modal__close" aria-label="<?php esc_attr_e( 'Close', 'bulk-price-editor' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="bpe-price-history-modal__body"></div>
			</div>
		</div>
		<?php
	}

	public static function handleAjax(): void {

		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$productId = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$product   = $productId ? wc_get_product( $productId ) : null;

		// Whoever may edit the product may see its price history.
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_post', $productId ) ) {
			wp_send_json_error( array( 'message' => 'Access denied' ) );
		}

		wp_send_json_success( array(
			'title' => $product->get_name(),
			'html'  => self::renderTable( $product ),
		) );
	}

	public static function renderTable( WC_Product $product ): string {

		$rows  = PriceLogRepository::getForProducts( array( $product->get_id() ), self::ENTRIES );
		$total = PriceLogRepository::countForProducts( array( $product->get_id() ) );

		ob_start();
		?>

		<?php if ( ! PriceLogRepository::isEnabled() ) : ?>
			<p class="bpe-price-history__muted">
				<?php esc_html_e( 'Price change tracking is disabled in the Bulk Price Editor settings — new changes are not being recorded.', 'bulk-price-editor' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( empty( $rows ) ) : ?>
			<p class="bpe-price-history__muted">
				<?php esc_html_e( 'No recorded price changes yet. Every change — manual, import, API or bulk update — will appear here.', 'bulk-price-editor' ); ?>
			</p>
			<?php return (string) ob_get_clean(); ?>
		<?php endif; ?>

		<table class="bpe-price-history-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'bulk-price-editor' ); ?></th>
					<th><?php esc_html_e( 'Change', 'bulk-price-editor' ); ?></th>
					<th><?php esc_html_e( 'Source', 'bulk-price-editor' ); ?></th>
					<th><?php esc_html_e( 'User', 'bulk-price-editor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<?php
							$timestamp = ! empty( $row['changed_at'] ) ? strtotime( $row['changed_at'] . ' UTC' ) : 0;
							echo esc_html( $timestamp ? wp_date( 'M j, Y H:i', $timestamp ) : '—' );
							?>
						</td>
						<td><?php echo esc_html( self::changeText( $row ) ); ?></td>
						<td><?php echo wp_kses_post( self::sourceText( $row ) ); ?></td>
						<td>
							<?php
							$user = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
							echo esc_html( $user ? $user->display_name : '—' );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total > count( $rows ) ) : ?>
			<p class="bpe-price-history__footer">
				<?php
				/* translators: 1: shown entries, 2: total entries */
				echo esc_html( sprintf( __( 'Showing the latest %1$d of %2$d recorded changes.', 'bulk-price-editor' ),
					count( $rows ), $total ) );
				?>
			</p>
		<?php endif; ?>
		<?php

		return (string) ob_get_clean();
	}

	protected static function changeText( array $row ): string {

		$parts = array();

		if ( ! is_null( $row['new_regular'] ) ) {
			/* translators: 1: old price, 2: new price */
			$parts[] = sprintf( __( 'Regular: %1$s → %2$s', 'bulk-price-editor' ),
				self::priceText( $row['old_regular'] ), self::priceText( $row['new_regular'] ) );
		}

		if ( ! is_null( $row['new_sale'] ) ) {
			/* translators: 1: old price, 2: new price */
			$parts[] = sprintf( __( 'Sale: %1$s → %2$s', 'bulk-price-editor' ),
				self::priceText( $row['old_sale'] ), self::priceText( $row['new_sale'] ) );
		}

		return implode( ' · ', $parts );
	}

	protected static function priceText( $value ): string {

		if ( is_null( $value ) || '' === (string) $value ) {
			return '—';
		}

		return html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES ) . $value;
	}

	protected static function sourceText( array $row ): string {

		if ( 'run' === $row['source'] && ! empty( $row['source_id'] ) ) {

			$run   = RunsRepository::getRun( (int) $row['source_id'] );
			$label = $run && $run->isRevert()
				/* translators: %d: run id */
				? sprintf( __( 'Revert #%d', 'bulk-price-editor' ), (int) $row['source_id'] )
				/* translators: %d: run id */
				: sprintf( __( 'Bulk update #%d', 'bulk-price-editor' ), (int) $row['source_id'] );

			return '<a href="' . esc_url( PriceEditorPage::getHistoryURL() ) . '" target="_blank">' . esc_html( $label ) . '</a>';
		}

		return esc_html( PriceLogger::getSourceLabel( (string) $row['source'] ) );
	}
}
