<?php

namespace BulkPriceEditor\Actions;

use BulkPriceEditor\BulkPriceEditorPlugin;
use BulkPriceEditor\History\HistoryManager;
use BulkPriceEditor\History\Run;
use BulkPriceEditor\History\RunsRepository;
use BulkPriceEditor\PriceEditorPage\Notifications\Notification;
use BulkPriceEditor\PriceEditorPage\Notifications\NotificationManager;
use BulkPriceEditor\PriceEditorPage\PriceEditorPage;
use WC_Product;
use WP_Error;
/**
 * Restores prices from a run's snapshot — the "one-click undo". A revert is
 * itself a run (type "revert") with its own snapshot, so it shows up in the
 * history with progress and can be reverted again ("redo").
 */
class RevertPricesAction {
    const ACTION_NAME = 'bulk_price_editor_revert_prices';

    const START_REVERT__ACTION_NAME = 'bulk_price_editor_start_revert';

    const AUTO_REVERT_ACTION = 'bulk_price_editor_start_scheduled_revert';

    public function __construct() {
        add_action(
            self::ACTION_NAME,
            array(self::class, 'revertChunk'),
            10,
            2
        );
        add_action( self::AUTO_REVERT_ACTION, array(self::class, 'startAutoRevert') );
        add_action( 'admin_post_' . self::START_REVERT__ACTION_NAME, function () {
            if ( !current_user_can( BulkPriceEditorPlugin::capability() ) ) {
                wp_die( 'Access denied' );
            }
            $nonce = ( isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : null );
            if ( !wp_verify_nonce( $nonce, self::START_REVERT__ACTION_NAME ) ) {
                wp_die( 'Access denied' );
            }
            $runId = ( isset( $_REQUEST['run_id'] ) ? absint( $_REQUEST['run_id'] ) : 0 );
            $run = ( $runId ? RunsRepository::getRun( $runId ) : null );
            if ( !$run ) {
                wp_die( 'Invalid request' );
            }
            $result = self::startRevert( $run );
            if ( is_wp_error( $result ) ) {
                NotificationManager::addNotification( new Notification(
                    $result->get_error_message(),
                    'error',
                    true,
                    true
                ) );
                wp_safe_redirect( PriceEditorPage::getHistoryURL() );
                exit;
            }
            // Same guard as the update path: a small revert completes inline
            // and already queued its completion notification.
            if ( SchedulePriceUpdatesAction::isRunning() ) {
                NotificationManager::addNotification( new Notification(
                    __( 'Restoring the previous prices has been started.', 'bulk-price-editor' ),
                    'success',
                    true,
                    true
                ) );
            }
            wp_safe_redirect( PriceEditorPage::getPageURL() );
            exit;
        } );
    }

    /**
     * @param  Run       $run          The run whose snapshot to restore.
     * @param  int|null  $userId       Who gets the completion notification (defaults to the current user).
     * @param  bool      $synchronous  Process everything inline (WP-CLI) instead of queueing.
     *
     * @return true|WP_Error
     */
    public static function startRevert( Run $run, ?int $userId = null, bool $synchronous = false ) {
        $canRevert = false;
        if ( !$canRevert ) {
            return new WP_Error('bpe_premium', __( 'Reverting price updates is a premium feature.', 'bulk-price-editor' ));
        }
        if ( !in_array( $run->getStatus(), array(Run::STATUS_COMPLETED, Run::STATUS_STOPPED), true ) ) {
            return new WP_Error('bpe_revert_status', __( 'Only finished updates can be reverted.', 'bulk-price-editor' ));
        }
        if ( $run->isReverted() ) {
            return new WP_Error('bpe_already_reverted', __( 'This update has already been reverted.', 'bulk-price-editor' ));
        }
        if ( SchedulePriceUpdatesAction::isRunning() ) {
            return new WP_Error('bpe_busy', __( 'A price update is already in progress. Please wait for it to finish.', 'bulk-price-editor' ));
        }
        $rowIds = RunsRepository::getChangedRowIds( $run->getId() );
        if ( empty( $rowIds ) ) {
            return new WP_Error('bpe_nothing_to_revert', __( 'This run did not change any prices, so there is nothing to revert.', 'bulk-price-editor' ));
        }
        $revertRunId = RunsRepository::insertRun( array(
            'type'       => Run::TYPE_REVERT,
            'status'     => Run::STATUS_RUNNING,
            'parent_id'  => $run->getId(),
            'user_id'    => ( is_null( $userId ) ? get_current_user_id() : $userId ),
            'total'      => count( $rowIds ),
            'started_at' => gmdate( 'Y-m-d H:i:s' ),
        ) );
        update_option( SchedulePriceUpdatesAction::CURRENT_RUN_OPTION, $revertRunId );
        $chunks = array_chunk( $rowIds, SchedulePriceUpdatesAction::getProductsPerRun() );
        if ( $synchronous ) {
            foreach ( $chunks as $chunk ) {
                self::revertChunk( $revertRunId, $chunk );
            }
            return true;
        }
        $firstChunk = array_shift( $chunks );
        foreach ( $chunks as $chunk ) {
            WC()->queue()->add( self::ACTION_NAME, array($revertRunId, $chunk), SchedulePriceUpdatesAction::QUEUE_GROUP );
        }
        self::revertChunk( $revertRunId, $firstChunk );
        return true;
    }

    /**
     * Restore one chunk of snapshot rows. Only the fields the original run
     * actually changed (non-NULL new_* columns) are touched.
     */
    public static function revertChunk( $revertRunId, $rowIds = array() ) : void {
        $revertRunId = (int) $revertRunId;
        $rowIds = array_filter( array_map( 'intval', (array) $rowIds ) );
        $revertRun = RunsRepository::getRun( $revertRunId );
        if ( empty( $rowIds ) || !$revertRun || Run::STATUS_RUNNING !== $revertRun->getStatus() ) {
            return;
        }
        $rows = RunsRepository::getPriceRowsByIds( $revertRun->getParentId(), $rowIds );
        // So the price log attributes these changes to this revert run.
        HistoryManager::setCurrentRunId( $revertRun->getId() );
        foreach ( $rows as $row ) {
            $product = wc_get_product( (int) $row['product_id'] );
            if ( !$product instanceof WC_Product ) {
                continue;
            }
            self::restoreProduct( $revertRun, $product, $row );
        }
        HistoryManager::setCurrentRunId( null );
        $revertRun = RunsRepository::incrementProcessed( $revertRunId, count( $rowIds ) );
        if ( $revertRun && $revertRun->getProcessed() >= $revertRun->getTotal() ) {
            self::completeRevert( $revertRun );
        }
    }

    protected static function restoreProduct( Run $revertRun, WC_Product $product, array $row ) : void {
        $changedRegular = !is_null( $row['new_regular'] );
        $changedSale = !is_null( $row['new_sale'] );
        $changedSaleFrom = !is_null( $row['new_sale_from'] );
        $changedSaleTo = !is_null( $row['new_sale_to'] );
        /*
         * The revert gets its own snapshot (current values as "old", the
         * restored values as "new") so it can be reverted again. Skip if this
         * chunk is an Action Scheduler retry that already recorded it.
         */
        if ( !RunsRepository::getPriceRow( $revertRun->getId(), $product->get_id() ) ) {
            RunsRepository::insertPriceRow( $revertRun->getId(), $product->get_id(), array(
                'old_regular'   => (string) $product->get_regular_price( 'edit' ),
                'old_sale'      => (string) $product->get_sale_price( 'edit' ),
                'new_regular'   => ( $changedRegular ? (string) $row['old_regular'] : null ),
                'new_sale'      => ( $changedSale ? (string) $row['old_sale'] : null ),
                'old_sale_from' => HistoryManager::dateToTimestampString( $product->get_date_on_sale_from( 'edit' ) ),
                'old_sale_to'   => HistoryManager::dateToTimestampString( $product->get_date_on_sale_to( 'edit' ) ),
                'new_sale_from' => ( $changedSaleFrom ? (string) $row['old_sale_from'] : null ),
                'new_sale_to'   => ( $changedSaleTo ? (string) $row['old_sale_to'] : null ),
            ) );
        }
        if ( $changedRegular ) {
            $product->set_regular_price( (string) $row['old_regular'] );
        }
        if ( $changedSale ) {
            $product->set_sale_price( (string) $row['old_sale'] );
        }
        if ( $changedSaleFrom ) {
            $product->set_date_on_sale_from( ( '' === $row['old_sale_from'] ? null : (int) $row['old_sale_from'] ) );
        }
        if ( $changedSaleTo ) {
            $product->set_date_on_sale_to( ( '' === $row['old_sale_to'] ? null : (int) $row['old_sale_to'] ) );
        }
        $product->save();
    }

    protected static function completeRevert( Run $revertRun ) : void {
        RunsRepository::updateRun( $revertRun->getId(), array(
            'status'       => Run::STATUS_COMPLETED,
            'processed'    => $revertRun->getTotal(),
            'completed_at' => gmdate( 'Y-m-d H:i:s' ),
        ) );
        RunsRepository::updateRun( $revertRun->getParentId(), array(
            'reverted_at' => gmdate( 'Y-m-d H:i:s' ),
        ) );
        SchedulePriceUpdatesAction::clearCurrentRun( $revertRun->getId() );
        // Translators: %d - number of products
        $notification = new Notification(
            sprintf( __( 'Previous prices for %d products have been restored.', 'bulk-price-editor' ), $revertRun->getTotal() ),
            'success',
            false,
            true
        );
        NotificationManager::addNotificationForUser( $revertRun->getUserId(), $notification );
        RunsRepository::pruneOldRuns();
    }

    /* ---------------------------------------------------------------------
     * Scheduled auto-revert ("end the sale on Monday")
     * ------------------------------------------------------------------- */
    public static function scheduleAutoRevert( int $parentRunId, int $timestamp ) : void {
        WC()->queue()->schedule_single(
            $timestamp,
            self::AUTO_REVERT_ACTION,
            array($parentRunId),
            SchedulePriceUpdatesAction::QUEUE_GROUP
        );
    }

    public static function cancelAutoRevert( int $parentRunId ) : void {
        WC()->queue()->cancel_all( self::AUTO_REVERT_ACTION, array($parentRunId), SchedulePriceUpdatesAction::QUEUE_GROUP );
    }

    public static function startAutoRevert( $parentRunId ) : void {
        $parent = RunsRepository::getRun( (int) $parentRunId );
        if ( !$parent || $parent->isReverted() ) {
            return;
        }
        // The update is still processing (or another run is busy) — try again shortly.
        if ( Run::STATUS_RUNNING === $parent->getStatus() || SchedulePriceUpdatesAction::isRunning() ) {
            WC()->queue()->schedule_single(
                time() + 2 * MINUTE_IN_SECONDS,
                self::AUTO_REVERT_ACTION,
                array((int) $parentRunId),
                SchedulePriceUpdatesAction::QUEUE_GROUP
            );
            return;
        }
        if ( !in_array( $parent->getStatus(), array(Run::STATUS_COMPLETED, Run::STATUS_STOPPED), true ) ) {
            return;
        }
        self::startRevert( $parent, $parent->getUserId() );
    }

    public static function getStartRevertURL( int $runId ) : string {
        return add_query_arg( array(
            'action'   => self::START_REVERT__ACTION_NAME,
            'run_id'   => $runId,
            '_wpnonce' => wp_create_nonce( self::START_REVERT__ACTION_NAME ),
        ), admin_url( 'admin-post.php' ) );
    }

}
