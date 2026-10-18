<?php namespace BulkPriceEditor\PriceEditorPage\Modals;

class ConfirmUpdate {

	public function getSLUG(): string {
		return 'confirm-update';
	}

	public function render() {

		// The datetime-local inputs are interpreted in the site timezone.
		$timezone = wp_timezone_string();

		?>
		<div class="bulk-price-editor-modal-popup">

			<div class="bulk-price-editor-modal-popup__header">
				<div class="bulk-price-editor-modal-popup__title">
					<?php esc_html_e( 'Confirm update', 'bulk-price-editor' ); ?>
				</div>
				<div class="bulk-price-editor-modal-popup__actions">
					<button class="bulk-price-editor-modal-popup__close	">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
			</div>

			<div class="bulk-price-editor-modal-popup__content">
				<?php
				esc_html_e( 'Please confirm that you want to update prices for selected products.',
					'bulk-price-editor' );
				?>
			</div>

			<div class="bulk-price-editor-modal-popup__schedule" id="bulk-price-editor-schedule-options">

				<?php if ( ! bpefw_fs()->can_use_premium_code() ) : ?>

					<p class="description">
						<?php esc_html_e( 'Scheduling updates for later and automatic revert (perfect for time-limited sales) are premium features.', 'bulk-price-editor' ); ?>
						<a href="<?php echo esc_attr( bpefw_fs_upgrade_url() ); ?>" target="_blank">
							<?php esc_html_e( 'Upgrade your plan', 'bulk-price-editor' ); ?>
						</a>
					</p>

				<?php else : ?>

				<div class="bpe-schedule-modes">
					<label class="bpe-schedule-mode">
						<input type="radio" name="bpe_schedule_mode" value="now" checked>
						<?php esc_html_e( 'Apply now', 'bulk-price-editor' ); ?>
					</label>
					<label class="bpe-schedule-mode">
						<input type="radio" name="bpe_schedule_mode" value="later">
						<?php esc_html_e( 'Schedule for later', 'bulk-price-editor' ); ?>
					</label>
				</div>

				<div class="bpe-schedule-field" id="bpe-schedule-at-field" style="display: none;">
					<label for="bpe_schedule_at"><?php esc_html_e( 'Start the update at', 'bulk-price-editor' ); ?></label>
					<input type="datetime-local" id="bpe_schedule_at">
				</div>

				<div class="bpe-schedule-field">
					<label for="bpe_revert_at"><?php esc_html_e( 'Automatically revert at (optional)', 'bulk-price-editor' ); ?></label>
					<input type="datetime-local" id="bpe_revert_at">
					<p class="description">
						<?php esc_html_e( 'Perfect for sales: the previous prices are restored automatically at this time.', 'bulk-price-editor' ); ?>
					</p>
				</div>

				<p class="description bpe-schedule-timezone">
					<?php
					/* translators: %s: timezone name */
					echo esc_html( sprintf( __( 'Times are in the site timezone (%s).', 'bulk-price-editor' ), $timezone ) );
					?>
				</p>

				<?php endif; ?>
			</div>

			<div class="bulk-price-editor-modal-popup__footer">

				<div class="bulk-price-editor-buttons">

					<button class="bulk-price-editor-button bulk-price-editor-button--primary bulk-price-editor-modal-popup__confirm">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Confirm', 'bulk-price-editor' ); ?>
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