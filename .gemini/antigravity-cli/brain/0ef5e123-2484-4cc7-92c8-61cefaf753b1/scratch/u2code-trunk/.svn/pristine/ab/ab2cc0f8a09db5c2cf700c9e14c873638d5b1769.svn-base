<?php namespace BulkPriceEditor\Actions;

use BulkPriceEditor\BulkPriceEditorPlugin;
use BulkPriceEditor\History\HistoryManager;
use BulkPriceEditor\History\Run;
use BulkPriceEditor\History\RunsRepository;
use BulkPriceEditor\PriceEditorPage\Notifications\Notification;
use BulkPriceEditor\PriceEditorPage\Notifications\NotificationManager;
use BulkPriceEditor\PriceModifiers\Dispatcher;
use WC_Product;
use WC_Product_Query;

class UpdateProductPriceAction {

	const ACTION_NAME = 'bulk_price_editor_update_prices';

	public function __construct() {
		add_action( self::ACTION_NAME, array( self::class, 'updatePrices' ), 10, 2 );
	}

	/**
	 * Process one chunk of a run: snapshot and update every product, then
	 * advance the run's progress (only after the work is done, so an Action
	 * Scheduler retry cannot inflate it).
	 *
	 * @param  int|string  $runId       Run id (2.x queued a transient key here — see the legacy branch).
	 * @param  array       $productIds
	 */
	public static function updatePrices( $runId, $productIds = array() ) {

		// Chunks queued by 2.x before the upgrade carry a transient key.
		if ( ! is_numeric( $runId ) ) {
			self::updatePricesLegacy( (string) $runId );

			return;
		}

		$runId      = (int) $runId;
		$productIds = array_filter( array_map( 'intval', (array) $productIds ) );

		$run = RunsRepository::getRun( $runId );

		// Never run a modifier without explicit IDs (an empty "include" would
		// match ALL products), and never process chunks of a stopped run.
		if ( empty( $productIds ) || ! $run || Run::STATUS_RUNNING !== $run->getStatus() ) {
			return;
		}

		$priceModifier = Dispatcher::getInstance()->dispatchModifier( $run->getModifierType(), $run->getModifierArgs() );

		if ( is_null( $priceModifier ) ) {
			return;
		}

		$products = ( new WC_Product_Query( array(
			'limit'    => - 1,
			'type'     => array_keys( BulkPriceEditorPlugin::getSupportedProductTypes() ),
			'include'  => $productIds,
			'paginate' => false,
		) ) )->get_products();

		/*
		 * If this chunk is an Action Scheduler retry, some products may have
		 * been updated already. Their snapshot rows hold the true "before"
		 * values — restoring those in-memory first makes the chunk idempotent
		 * (a percent modifier must not apply twice).
		 */
		$existingSnapshots = RunsRepository::getPriceRowsForProducts( $runId, $productIds );

		HistoryManager::setCurrentRunId( $runId );

		foreach ( $products as $product ) {

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$snapshot = $existingSnapshots[ $product->get_id() ] ?? null;

			if ( $snapshot ) {
				$product->set_regular_price( (string) $snapshot['old_regular'] );
				$product->set_sale_price( (string) $snapshot['old_sale'] );
			}

			$priceModifier->updatePrices( $product );
		}

		HistoryManager::setCurrentRunId( null );

		$run = RunsRepository::incrementProcessed( $runId, count( $productIds ) );

		if ( $run && $run->getProcessed() >= $run->getTotal() ) {
			self::completeRun( $run );
		}
	}

	public static function completeRun( Run $run ): void {

		RunsRepository::updateRun( $run->getId(), array(
			'status'       => Run::STATUS_COMPLETED,
			'processed'    => $run->getTotal(),
			'completed_at' => gmdate( 'Y-m-d H:i:s' ),
		) );

		SchedulePriceUpdatesAction::clearCurrentRun( $run->getId() );

		// Translators: %d - number of products
		$notification = new Notification( sprintf( __( 'Prices for %d products have been updated successfully.',
			'bulk-price-editor' ), $run->getTotal() ), 'success', false, true );

		NotificationManager::addNotificationForUser( $run->getUserId(), $notification );

		RunsRepository::pruneOldRuns();
	}

	/**
	 * 2.x chunk format: arguments stored in a transient. Kept so a queue that
	 * was in flight during the upgrade still drains correctly.
	 */
	protected static function updatePricesLegacy( string $argsKey ): void {

		$args = (array) get_transient( $argsKey );

		$productIds = isset( $args['products'] ) ? array_filter( array_map( 'intval', (array) $args['products'] ) ) : array();

		if ( empty( $productIds ) ) {
			delete_transient( $argsKey );

			return;
		}

		$priceModifierType = isset( $args['price_modifier_type'] ) ? (string) $args['price_modifier_type'] : '';
		$priceModifierArgs = isset( $args['price_modifier_args'] ) ? (array) $args['price_modifier_args'] : array();

		$priceModifier = Dispatcher::getInstance()->dispatchModifier( $priceModifierType, $priceModifierArgs );

		if ( is_null( $priceModifier ) ) {
			delete_transient( $argsKey );

			return;
		}

		$products = ( new WC_Product_Query( array(
			'limit'    => - 1,
			'type'     => array_keys( BulkPriceEditorPlugin::getSupportedProductTypes() ),
			'include'  => $productIds,
			'paginate' => false,
		) ) )->get_products();

		foreach ( $products as $product ) {

			if ( $product instanceof WC_Product ) {
				$priceModifier->updatePrices( $product );
			}
		}

		delete_transient( $argsKey );
	}
}
