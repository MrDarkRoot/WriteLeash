<?php namespace BulkPriceEditor\PriceEditorPage;

use BulkPriceEditor\BulkPriceEditorPlugin;
use BulkPriceEditor\PriceEditorPage\Notifications\Notification;
use BulkPriceEditor\PriceEditorPage\Notifications\NotificationManager;

/**
 * The Settings tab. Currently home to the price change tracking feature
 * (the per-product price log shown on the product edit screen).
 */
class SettingsTab {

	const SAVE_ACTION = 'bulk_price_editor_save_settings';

	public static function init(): void {

		add_action( 'admin_post_' . self::SAVE_ACTION, function () {

			if ( ! current_user_can( BulkPriceEditorPlugin::capability() ) ) {
				wp_die( 'Access denied' );
			}

			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : null;

			if ( ! wp_verify_nonce( $nonce, self::SAVE_ACTION ) ) {
				wp_die( 'Access denied' );
			}

			update_option( 'bpe_price_log_enabled', empty( $_POST['bpe_price_log_enabled'] ) ? 'no' : 'yes' );

			$maxPerProduct = isset( $_POST['bpe_price_log_max_per_product'] ) ? absint( $_POST['bpe_price_log_max_per_product'] ) : 100;
			update_option( 'bpe_price_log_max_per_product', max( 1, min( 10000, $maxPerProduct ) ) );

			NotificationManager::addNotification( new Notification( __( 'Settings saved.', 'bulk-price-editor' ),
				'success', true, true ) );

			wp_safe_redirect( PriceEditorPage::getSettingsURL() );
			exit;
		} );
	}

	public function render() {

		$enabled       = 'yes' === get_option( 'bpe_price_log_enabled', 'yes' );
		$maxPerProduct = (int) get_option( 'bpe_price_log_max_per_product', 100 );

		?>
		<div class="bulk-price-editor-settings">

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>

				<div class="bulk-price-editor-settings__section">
					<div class="bulk-price-editor-settings__title">
						<?php esc_html_e( 'Price change tracking', 'bulk-price-editor' ); ?>
					</div>

					<div class="bulk-price-editor-widget-row">
						<div class="bulk-price-editor-widget-row__name">
							<?php esc_html_e( 'Track price changes', 'bulk-price-editor' ); ?>
						</div>
						<div class="bulk-price-editor-widget-row__value">
							<label>
								<input type="checkbox" name="bpe_price_log_enabled" value="yes" <?php checked( $enabled ); ?>>
								<?php esc_html_e( 'Record every product price change with its source and user', 'bulk-price-editor' ); ?>
							</label>
							<p class="description" style="margin: 6px 0 0;">
								<?php esc_html_e( 'Covers all sources — manual edits, imports, the REST API and bulk updates. A "Price history" link next to the price fields (and in every variation) opens the log on the product edit screen. Only changes made while tracking is on are recorded.', 'bulk-price-editor' ); ?>
							</p>
						</div>
					</div>

					<div class="bulk-price-editor-widget-row">
						<div class="bulk-price-editor-widget-row__name">
							<?php esc_html_e( 'Keep per product', 'bulk-price-editor' ); ?>
						</div>
						<div class="bulk-price-editor-widget-row__value">
							<input type="number" name="bpe_price_log_max_per_product" min="1" max="10000"
								   value="<?php echo esc_attr( $maxPerProduct ); ?>" style="width: 90px;">
							<p class="description" style="margin: 6px 0 0;">
								<?php esc_html_e( 'Oldest entries beyond this number are removed per product. Protects the database on stores where prices change very often.', 'bulk-price-editor' ); ?>
							</p>
						</div>
					</div>
				</div>

				<div class="bulk-price-editor-settings__footer">
					<button type="submit" class="bulk-price-editor-button bulk-price-editor-button--primary">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save settings', 'bulk-price-editor' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}
}
