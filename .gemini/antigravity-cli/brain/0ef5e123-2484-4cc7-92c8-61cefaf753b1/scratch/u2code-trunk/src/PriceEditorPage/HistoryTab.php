<?php namespace BulkPriceEditor\PriceEditorPage;

use BulkPriceEditor\Actions\ExportCsvAction;
use BulkPriceEditor\Actions\RevertPricesAction;
use BulkPriceEditor\Actions\SchedulePriceUpdatesAction;
use BulkPriceEditor\History\Run;
use BulkPriceEditor\History\RunsRepository;
use BulkPriceEditor\PriceModifiers\Dispatcher;

/**
 * The History tab of the editor page: every bulk update (and revert) with its
 * status, scheduled/auto-revert times, snapshot CSV download and the
 * one-click revert.
 */
class HistoryTab {

	const PER_PAGE = 20;

	/**
	 * true: the Scheduled tab (upcoming runs, soonest first);
	 * false: the History tab (past runs).
	 */
	protected bool $scheduledOnly;

	public function __construct( bool $scheduledOnly = false ) {
		$this->scheduledOnly = $scheduledOnly;
	}

	protected function getBaseURL(): string {
		return $this->scheduledOnly ? PriceEditorPage::getScheduledURL() : PriceEditorPage::getHistoryURL();
	}

	public function render() {

		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$total  = RunsRepository::countRuns( $this->scheduledOnly ? true : false );
		$runs   = RunsRepository::getRuns( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE,
			$this->scheduledOnly ? true : false );
		$pages  = (int) ceil( $total / self::PER_PAGE );

		?>
		<div class="bulk-price-editor-history">

			<?php if ( empty( $runs ) ) : ?>

				<div class="bulk-price-editor-history__empty">
					<span class="dashicons dashicons-<?php echo esc_attr( $this->scheduledOnly ? 'clock' : 'backup' ); ?>"></span>

					<?php if ( $this->scheduledOnly ) : ?>
						<div>
							<?php esc_html_e( 'No scheduled updates.', 'bulk-price-editor' ); ?>
						</div>
						<p>
							<?php esc_html_e( 'Pick "Schedule for later" in the confirmation dialog to run a price update at a future time — optionally with an automatic revert, perfect for sales.', 'bulk-price-editor' ); ?>
						</p>
					<?php else : ?>
						<div>
							<?php esc_html_e( 'No price updates yet.', 'bulk-price-editor' ); ?>
						</div>
						<p>
							<?php esc_html_e( 'Every bulk update you run will appear here with a snapshot of the previous prices, so you can review it, download it as CSV, or revert it in one click.', 'bulk-price-editor' ); ?>
						</p>
					<?php endif; ?>
				</div>

			<?php else : ?>

				<table class="bulk-price-editor-history-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Update', 'bulk-price-editor' ); ?></th>
							<th><?php esc_html_e( 'Details', 'bulk-price-editor' ); ?></th>
							<th><?php esc_html_e( 'Products', 'bulk-price-editor' ); ?></th>
							<th><?php esc_html_e( 'Status', 'bulk-price-editor' ); ?></th>
							<th class="bulk-price-editor-history-table__actions"><?php esc_html_e( 'Actions', 'bulk-price-editor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $runs as $run ) : ?>
							<?php $this->renderRow( $run ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<div class="bulk-price-editor-history__pagination">
						<?php for ( $page = 1; $page <= $pages; $page ++ ) : ?>
							<?php if ( $page === $paged ) : ?>
								<span class="bulk-price-editor-history__page bulk-price-editor-history__page--current"><?php echo esc_html( $page ); ?></span>
							<?php else : ?>
								<a class="bulk-price-editor-history__page"
								   href="<?php echo esc_url( add_query_arg( 'paged', $page, $this->getBaseURL() ) ); ?>"><?php echo esc_html( $page ); ?></a>
							<?php endif; ?>
						<?php endfor; ?>
					</div>
				<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	protected function renderRow( Run $run ) {

		$createdAt = $run->getTimestamp( 'created_at' );
		$user      = $run->getUserId() ? get_userdata( $run->getUserId() ) : null;

		?>
		<tr>
			<td>
				<div class="bulk-price-editor-history__run-id">#<?php echo esc_html( $run->getId() ); ?></div>
				<div class="bulk-price-editor-history__date">
					<?php echo esc_html( $createdAt ? wp_date( 'M j, Y H:i', $createdAt ) : '—' ); ?>
				</div>
				<?php if ( $user ) : ?>
					<div class="bulk-price-editor-history__user"><?php echo esc_html( $user->display_name ); ?></div>
				<?php endif; ?>
			</td>

			<td>
				<div class="bulk-price-editor-history__summary">
					<?php echo esc_html( $this->getRunSummary( $run ) ); ?>
				</div>

				<?php $modifierDetails = $this->getModifierDetails( $run ); ?>
				<?php if ( $modifierDetails ) : ?>
					<div class="bulk-price-editor-history__filters">
						<?php echo esc_html( implode( ' · ', $modifierDetails ) ); ?>
					</div>
				<?php endif; ?>

				<?php $filterDetails = $this->getFilterDetails( $run ); ?>
				<?php if ( $filterDetails ) : ?>
					<div class="bulk-price-editor-history__filters">
						<?php
						echo esc_html( __( 'Filters:', 'bulk-price-editor' ) . ' ' . implode( ' · ', $filterDetails ) );
						?>
					</div>
				<?php endif; ?>

				<?php $scheduledAt = $run->getTimestamp( 'scheduled_at' ); ?>
				<?php if ( $scheduledAt && Run::STATUS_SCHEDULED === $run->getStatus() ) : ?>
					<div class="bulk-price-editor-history__schedule">
						<span class="dashicons dashicons-clock"></span>
						<?php
						/* translators: %s: date and time */
						echo esc_html( sprintf( __( 'Starts %s', 'bulk-price-editor' ),
							wp_date( 'M j, Y H:i', $scheduledAt ) ) );
						?>
					</div>
				<?php endif; ?>

				<?php $revertAt = $run->getTimestamp( 'revert_at' ); ?>
				<?php if ( $revertAt && ! $run->isReverted() && in_array( $run->getStatus(), array( Run::STATUS_SCHEDULED, Run::STATUS_RUNNING, Run::STATUS_COMPLETED ), true ) ) : ?>
					<div class="bulk-price-editor-history__schedule">
						<span class="dashicons dashicons-undo"></span>
						<?php
						/* translators: %s: date and time */
						echo esc_html( sprintf( __( 'Auto-revert %s', 'bulk-price-editor' ),
							wp_date( 'M j, Y H:i', $revertAt ) ) );
						?>
					</div>
				<?php endif; ?>
			</td>

			<td>
				<?php if ( Run::STATUS_SCHEDULED === $run->getStatus() ) : ?>
					<span title="<?php esc_attr_e( 'Products matching the filters when the update was scheduled. The list is resolved again when the update starts.', 'bulk-price-editor' ); ?>">
						<?php echo $run->getTotal() ? esc_html( '~' . $run->getTotal() ) : '—'; ?>
					</span>
				<?php elseif ( $run->getProcessed() < $run->getTotal() ) : ?>
					<?php echo esc_html( $run->getProcessed() . ' / ' . $run->getTotal() ); ?>
				<?php else : ?>
					<?php echo esc_html( $run->getTotal() ); ?>
				<?php endif; ?>
			</td>

			<td>
				<?php $this->renderStatus( $run ); ?>
			</td>

			<td class="bulk-price-editor-history-table__actions">
				<?php $this->renderActions( $run ); ?>
			</td>
		</tr>
		<?php
	}

	protected function renderStatus( Run $run ) {

		$labels = array(
			Run::STATUS_SCHEDULED => __( 'Scheduled', 'bulk-price-editor' ),
			Run::STATUS_RUNNING   => __( 'In progress', 'bulk-price-editor' ),
			Run::STATUS_COMPLETED => __( 'Completed', 'bulk-price-editor' ),
			Run::STATUS_STOPPED   => __( 'Stopped', 'bulk-price-editor' ),
			Run::STATUS_CANCELED  => __( 'Canceled', 'bulk-price-editor' ),
			Run::STATUS_FAILED    => __( 'Failed', 'bulk-price-editor' ),
		);

		$status = $run->getStatus();
		$label  = $labels[ $status ] ?? $status;

		?>
		<span class="bulk-price-editor-history-status bulk-price-editor-history-status--<?php echo esc_attr( $status ); ?>">
			<?php echo esc_html( $label ); ?>
		</span>

		<?php if ( $run->isReverted() ) : ?>
			<span class="bulk-price-editor-history-status bulk-price-editor-history-status--reverted">
				<?php esc_html_e( 'Reverted', 'bulk-price-editor' ); ?>
			</span>
		<?php endif; ?>
		<?php
	}

	protected function renderActions( Run $run ) {

		$status = $run->getStatus();

		if ( Run::STATUS_SCHEDULED === $status ) {
			?>
			<a href="<?php echo esc_url( SchedulePriceUpdatesAction::getCancelScheduledRunURL( $run->getId() ) ); ?>"
			   onclick="return confirm('<?php echo esc_attr( __( 'Cancel this scheduled price update?', 'bulk-price-editor' ) ); ?>')"
			   class="bulk-price-editor-button bulk-price-editor-button--secondary bulk-price-editor-button--small">
				<?php esc_html_e( 'Cancel', 'bulk-price-editor' ); ?>
			</a>
			<?php

			return;
		}

		$finished = in_array( $status, array( Run::STATUS_COMPLETED, Run::STATUS_STOPPED ), true );

		if ( $finished && ! $run->isReverted() && RunsRepository::hasChangedRows( $run->getId() ) ) {

			if ( bpefw_fs()->can_use_premium_code() ) {
				?>
				<a href="<?php echo esc_url( RevertPricesAction::getStartRevertURL( $run->getId() ) ); ?>"
				   onclick="return confirm('<?php echo esc_attr( __( 'Restore the previous prices for all products changed by this update?', 'bulk-price-editor' ) ); ?>')"
				   class="bulk-price-editor-button bulk-price-editor-button--primary bulk-price-editor-button--small">
					<span class="dashicons dashicons-undo"></span>
					<?php esc_html_e( 'Revert', 'bulk-price-editor' ); ?>
				</a>
				<?php
			} else {
				?>
				<a href="<?php echo esc_attr( bpefw_fs_upgrade_url() ); ?>" target="_blank"
				   title="<?php esc_attr_e( 'One-click revert is a premium feature.', 'bulk-price-editor' ); ?>"
				   class="bulk-price-editor-button bulk-price-editor-button--secondary bulk-price-editor-button--small">
					<span class="dashicons dashicons-lock"></span>
					<?php esc_html_e( 'Revert', 'bulk-price-editor' ); ?>
				</a>
				<?php
			}
		}

		if ( $finished && RunsRepository::countPriceRows( $run->getId() ) > 0 ) {
			?>
			<a href="<?php echo esc_url( ExportCsvAction::getExportRunURL( $run->getId() ) ); ?>"
			   class="bulk-price-editor-button bulk-price-editor-button--secondary bulk-price-editor-button--small">
				<span class="dashicons dashicons-download"></span>
				<?php esc_html_e( 'CSV', 'bulk-price-editor' ); ?>
			</a>
			<?php
		}

		if ( Run::STATUS_RUNNING !== $status ) {
			?>
			<a href="<?php echo esc_url( SchedulePriceUpdatesAction::getDeleteRunURL( $run->getId() ) ); ?>"
			   onclick="return confirm('<?php echo esc_attr( __( 'Remove this entry from the history? Its price snapshot is deleted too, so this update can no longer be reverted.', 'bulk-price-editor' ) ); ?>')"
			   title="<?php esc_attr_e( 'Remove from history', 'bulk-price-editor' ); ?>"
			   class="bulk-price-editor-button bulk-price-editor-button--secondary bulk-price-editor-button--small">
				<span class="dashicons dashicons-trash"></span>
			</a>
			<?php
		}
	}

	protected function getRunSummary( Run $run ): string {

		if ( $run->isRevert() ) {
			/* translators: %d: original run id */
			return sprintf( __( 'Revert of update #%d', 'bulk-price-editor' ), $run->getParentId() );
		}

		$modifier = Dispatcher::getInstance()->dispatchModifier( $run->getModifierType(), $run->getModifierArgs() );

		return $modifier ? $modifier->getName() : $run->getModifierType();
	}

	/**
	 * What the update actually does, with values: "Regular +15%",
	 * "Rounding: .99", "Sale dates: 2026-09-05 – 2026-09-15", ...
	 */
	protected function getModifierDetails( Run $run ): array {

		if ( $run->isRevert() ) {
			return array();
		}

		$args     = $run->getModifierArgs();
		$currency = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES );
		$lines    = array();

		$value = function ( $key ) use ( $args ) {
			return isset( $args[ $key ] ) ? trim( (string) $args[ $key ] ) : '';
		};

		$priceArgs = array(
			/* translators: %s: amount */
			'bpe_new_regular_price'                 => array( __( 'Regular → %s', 'bulk-price-editor' ), 'money' ),
			/* translators: %s: amount */
			'bpe_new_sale_price'                    => array( __( 'Sale → %s', 'bulk-price-editor' ), 'money' ),
			/* translators: %s: percent */
			'bpe_regular_price_increase_by_percent' => array( __( 'Regular +%s%%', 'bulk-price-editor' ), 'raw' ),
			/* translators: %s: percent */
			'bpe_sale_price_increase_by_percent'    => array( __( 'Sale +%s%%', 'bulk-price-editor' ), 'raw' ),
			/* translators: %s: percent */
			'bpe_regular_price_decrease_by_percent' => array( __( 'Regular -%s%%', 'bulk-price-editor' ), 'raw' ),
			/* translators: %s: percent */
			'bpe_sale_price_decrease_by_percent'    => array( __( 'Sale -%s%%', 'bulk-price-editor' ), 'raw' ),
			/* translators: %s: amount */
			'bpe_regular_price_add_flat_value'      => array( __( 'Regular +%s', 'bulk-price-editor' ), 'money' ),
			/* translators: %s: amount */
			'bpe_sale_price_add_flat_value'         => array( __( 'Sale +%s', 'bulk-price-editor' ), 'money' ),
			/* translators: %s: amount */
			'bpe_regular_price_subtract_flat_value' => array( __( 'Regular -%s', 'bulk-price-editor' ), 'money' ),
			/* translators: %s: amount */
			'bpe_sale_price_subtract_flat_value'    => array( __( 'Sale -%s', 'bulk-price-editor' ), 'money' ),
			/* translators: %s: formula */
			'bpe_formula_regular_price'             => array( __( 'Regular = %s', 'bulk-price-editor' ), 'raw' ),
			/* translators: %s: formula */
			'bpe_formula_sale_price'                => array( __( 'Sale = %s', 'bulk-price-editor' ), 'raw' ),
		);

		foreach ( $priceArgs as $key => $format ) {
			if ( '' !== $value( $key ) ) {
				$formatted = 'money' === $format[1] ? $currency . $value( $key ) : $value( $key );
				$lines[]   = sprintf( $format[0], $formatted );
			}
		}

		$removeLabels = array(
			'both'          => __( 'Remove both prices', 'bulk-price-editor' ),
			'regular_price' => __( 'Remove regular price', 'bulk-price-editor' ),
			'sale_price'    => __( 'Remove sale price', 'bulk-price-editor' ),
		);

		if ( isset( $removeLabels[ $value( 'bpe_remove_prices' ) ] ) ) {
			$lines[] = $removeLabels[ $value( 'bpe_remove_prices' ) ];
		}

		$roundingLabels = array(
			'2'  => __( '2 decimals', 'bulk-price-editor' ),
			'1'  => __( '1 decimal', 'bulk-price-editor' ),
			'00' => '.00',
			'90' => '.90',
			'95' => '.95',
			'99' => '.99',
		);

		if ( isset( $roundingLabels[ $value( 'bpe_price_rounding' ) ] ) ) {
			/* translators: %s: rounding mode */
			$lines[] = sprintf( __( 'Rounding: %s', 'bulk-price-editor' ), $roundingLabels[ $value( 'bpe_price_rounding' ) ] );
		}

		if ( 'clear' === $value( 'bpe_sale_dates_action' ) ) {
			$lines[] = __( 'Clear sale dates', 'bulk-price-editor' );
		} elseif ( 'set' === $value( 'bpe_sale_dates_action' ) ) {

			$from = $value( 'bpe_sale_date_from' );
			$to   = $value( 'bpe_sale_date_to' );

			if ( '' !== $from || '' !== $to ) {
				/* translators: 1: start date, 2: end date */
				$lines[] = sprintf( __( 'Sale dates: %1$s – %2$s', 'bulk-price-editor' ),
					'' !== $from ? $from : '…', '' !== $to ? $to : '…' );
			}
		}

		return $lines;
	}

	/**
	 * The filters with their actual values: resolved product and term names
	 * (first few, then "+N more"), ranges, and search terms. Companion
	 * selects and the default product-type set are no-ops and are skipped.
	 */
	protected function getFilterDetails( Run $run ): array {

		$filters = $run->getFilters();
		$lines   = array();

		$productKeys = array(
			/* translators: %s: product names */
			'bpe_included_product_ids' => __( 'Products: %s', 'bulk-price-editor' ),
			/* translators: %s: product names */
			'bpe_excluded_product_ids' => __( 'Not products: %s', 'bulk-price-editor' ),
		);

		foreach ( $productKeys as $key => $format ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$lines[] = sprintf( $format, $this->namesList( (array) $filters[ $key ], function ( $id ) {
					$product = wc_get_product( $id );

					return $product ? $product->get_name() : '#' . $id;
				} ) );
			}
		}

		$termKeys = array(
			/* translators: %s: term names */
			'bpe_included_category_ids'       => __( 'Categories: %s', 'bulk-price-editor' ),
			/* translators: %s: term names */
			'bpe_included_brand_ids'          => __( 'Brands: %s', 'bulk-price-editor' ),
			/* translators: %s: term names */
			'bpe_included_tag_ids'            => __( 'Tags: %s', 'bulk-price-editor' ),
			/* translators: %s: term names */
			'bpe_included_shipping_class_ids' => __( 'Shipping classes: %s', 'bulk-price-editor' ),
			/* translators: %s: term names */
			'bpe_excluded_category_ids'       => __( 'Not categories: %s', 'bulk-price-editor' ),
			/* translators: %s: term names */
			'bpe_excluded_brand_ids'          => __( 'Not brands: %s', 'bulk-price-editor' ),
			/* translators: %s: term names */
			'bpe_excluded_tag_ids'            => __( 'Not tags: %s', 'bulk-price-editor' ),
			/* translators: %s: term names */
			'bpe_excluded_shipping_class_ids' => __( 'Not shipping classes: %s', 'bulk-price-editor' ),
		);

		foreach ( $termKeys as $key => $format ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$lines[] = sprintf( $format, $this->namesList( (array) $filters[ $key ], function ( $id ) {
					$term = get_term( (int) $id );

					return $term instanceof \WP_Term ? $term->name : '#' . $id;
				} ) );
			}
		}

		$plain = function ( $key ) use ( $filters ) {
			$values = array_filter( array_map( 'strval', (array) ( $filters[ $key ] ?? array() ) ), 'strlen' );

			return implode( ', ', array_map( function ( $item ) {
				return ucfirst( str_replace( array( '_', '-' ), ' ', $item ) );
			}, $values ) );
		};

		if ( '' !== $plain( 'bpe_stock_status' ) ) {
			/* translators: %s: stock status */
			$lines[] = sprintf( __( 'Stock: %s', 'bulk-price-editor' ), $plain( 'bpe_stock_status' ) );
		}

		if ( '' !== $plain( 'bpe_on_sale_status' ) ) {
			/* translators: %s: sale status */
			$lines[] = sprintf( __( 'Sale status: %s', 'bulk-price-editor' ), $plain( 'bpe_on_sale_status' ) );
		}

		if ( '' !== $plain( 'bpe_featured_status' ) ) {
			/* translators: %s: featured status */
			$lines[] = sprintf( __( 'Featured: %s', 'bulk-price-editor' ), $plain( 'bpe_featured_status' ) );
		}

		if ( ! empty( $filters['bpe_sku_contains'] ) ) {
			/* translators: %s: search term */
			$lines[] = sprintf( __( 'SKU contains "%s"', 'bulk-price-editor' ), (string) $filters['bpe_sku_contains'] );
		}

		if ( ! empty( $filters['bpe_title_like'] ) ) {
			/* translators: %s: search term */
			$lines[] = sprintf( __( 'Name contains "%s"', 'bulk-price-editor' ), (string) $filters['bpe_title_like'] );
		}

		$priceMin = isset( $filters['bpe_price_range_min'] ) ? trim( (string) $filters['bpe_price_range_min'] ) : '';
		$priceMax = isset( $filters['bpe_price_range_max'] ) ? trim( (string) $filters['bpe_price_range_max'] ) : '';

		if ( '' !== $priceMin || '' !== $priceMax ) {
			$type = 'sale_price' === ( $filters['bpe_price_range_type'] ?? '' )
				? __( 'Sale price', 'bulk-price-editor' ) : __( 'Regular price', 'bulk-price-editor' );

			$lines[] = $type . ' ' . ( '' !== $priceMin ? $priceMin : '…' ) . ' – ' . ( '' !== $priceMax ? $priceMax : '…' );
		}

		$dateFrom = isset( $filters['bpe_date_range_from'] ) ? trim( (string) $filters['bpe_date_range_from'] ) : '';
		$dateTo   = isset( $filters['bpe_date_range_to'] ) ? trim( (string) $filters['bpe_date_range_to'] ) : '';

		if ( '' !== $dateFrom || '' !== $dateTo ) {
			$type = 'modified' === ( $filters['bpe_date_range_type'] ?? '' )
				? __( 'Modified', 'bulk-price-editor' ) : __( 'Created', 'bulk-price-editor' );

			$lines[] = $type . ' ' . ( '' !== $dateFrom ? $dateFrom : '…' ) . ' – ' . ( '' !== $dateTo ? $dateTo : '…' );
		}

		if ( ! empty( $filters['bpe_product_type'] ) ) {

			$selected = array_map( 'strval', (array) $filters['bpe_product_type'] );
			$default  = array_map( 'strval', \BulkPriceEditor\BulkPriceEditorPlugin::getDefaultProductTypes() );

			sort( $selected );
			sort( $default );

			if ( $selected !== $default ) {
				/* translators: %s: product types */
				$lines[] = sprintf( __( 'Type: %s', 'bulk-price-editor' ), implode( ', ', $selected ) );
			}
		}

		foreach ( array_keys( $filters ) as $key ) {
			if ( 0 === strpos( (string) $key, 'bpe_product_attribute_' ) && ! empty( $filters[ $key ] ) ) {
				$lines[] = __( 'Attributes', 'bulk-price-editor' );
				break;
			}
		}

		return $lines;
	}

	/**
	 * First few resolved names, then "+N more".
	 */
	protected function namesList( array $ids, callable $resolver, int $max = 3 ): string {

		$ids   = array_values( array_filter( array_map( 'intval', $ids ) ) );
		$names = array_map( $resolver, array_slice( $ids, 0, $max ) );

		if ( count( $ids ) > $max ) {
			/* translators: %d: number of additional items */
			$names[] = sprintf( __( '+%d more', 'bulk-price-editor' ), count( $ids ) - $max );
		}

		return implode( ', ', $names );
	}
}
