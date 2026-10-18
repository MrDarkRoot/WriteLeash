<?php

namespace BulkPriceEditor\Actions;

use BulkPriceEditor\BulkPriceEditorPlugin;
use BulkPriceEditor\History\Run;
use BulkPriceEditor\History\RunsRepository;
use BulkPriceEditor\PriceEditorPage\Notifications\Notification;
use BulkPriceEditor\PriceEditorPage\Notifications\NotificationManager;
use BulkPriceEditor\PriceEditorPage\PriceEditorPage;
use BulkPriceEditor\ProductFilters\FilterManager;
use BulkPriceEditor\ProductQuery\ProductQuery;
use BulkPriceEditor\Request\Request;
use WP_Error;
class SchedulePriceUpdatesAction {
    const PRODUCTS_PER_RUN = 100;

    /**
     * The run currently being processed (update or revert). Progress and the
     * "is running" state are read from its history row.
     */
    const CURRENT_RUN_OPTION = 'bpe_current_run_id';

    const SCHEDULE__ACTION_NAME = 'bulk_price_editor_schedule_price_updating';

    const GET_PROGRESS__ACTION_NAME = 'bulk_price_editor_get_price_updating_progress';

    const STOP_UPDATING__ACTION_NAME = 'bulk_price_editor_stop_price_updating';

    const START_SCHEDULED_RUN_ACTION = 'bulk_price_editor_start_scheduled_run';

    const CANCEL_SCHEDULED_RUN_ACTION = 'bulk_price_editor_cancel_scheduled_run';

    const DELETE_RUN_ACTION = 'bulk_price_editor_delete_run';

    const QUEUE_GROUP = 'bulk-price-editor__prices';

    public function __construct() {
        add_action( 'wp_ajax_' . self::SCHEDULE__ACTION_NAME, array($this, 'run') );
        add_action( self::START_SCHEDULED_RUN_ACTION, array(self::class, 'startScheduledRun') );
        add_action( 'wp_ajax_' . self::GET_PROGRESS__ACTION_NAME, function () {
            check_ajax_referer( self::GET_PROGRESS__ACTION_NAME, 'nonce' );
            if ( !current_user_can( BulkPriceEditorPlugin::capability() ) ) {
                wp_send_json_error( array(
                    'message' => 'Access denied',
                ) );
            }
            wp_send_json_success( self::getProgressData() );
        } );
        add_action( 'admin_post_' . self::STOP_UPDATING__ACTION_NAME, function () {
            if ( !current_user_can( BulkPriceEditorPlugin::capability() ) ) {
                wp_die( 'Access denied' );
            }
            $nonce = ( isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : null );
            if ( !wp_verify_nonce( $nonce, self::STOP_UPDATING__ACTION_NAME ) ) {
                wp_die( 'Access denied' );
            }
            $this->stopCurrentRun();
            wp_safe_redirect( admin_url( 'admin.php?page=' . PriceEditorPage::PAGE_SLUG ) );
            exit;
        } );
        add_action( 'admin_post_' . self::CANCEL_SCHEDULED_RUN_ACTION, function () {
            if ( !current_user_can( BulkPriceEditorPlugin::capability() ) ) {
                wp_die( 'Access denied' );
            }
            $nonce = ( isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : null );
            if ( !wp_verify_nonce( $nonce, self::CANCEL_SCHEDULED_RUN_ACTION ) ) {
                wp_die( 'Access denied' );
            }
            $runId = ( isset( $_REQUEST['run_id'] ) ? absint( $_REQUEST['run_id'] ) : 0 );
            self::cancelScheduledRun( $runId );
            wp_safe_redirect( PriceEditorPage::getScheduledURL() );
            exit;
        } );
        add_action( 'admin_post_' . self::DELETE_RUN_ACTION, function () {
            if ( !current_user_can( BulkPriceEditorPlugin::capability() ) ) {
                wp_die( 'Access denied' );
            }
            $nonce = ( isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : null );
            if ( !wp_verify_nonce( $nonce, self::DELETE_RUN_ACTION ) ) {
                wp_die( 'Access denied' );
            }
            $runId = ( isset( $_REQUEST['run_id'] ) ? absint( $_REQUEST['run_id'] ) : 0 );
            $run = ( $runId ? RunsRepository::getRun( $runId ) : null );
            // Scheduled runs are canceled first; a running one must finish or be stopped.
            if ( $run && !in_array( $run->getStatus(), array(Run::STATUS_SCHEDULED, Run::STATUS_RUNNING), true ) ) {
                RunsRepository::deleteRun( $runId );
                NotificationManager::addNotification( new Notification(
                    __( 'History entry has been removed.', 'bulk-price-editor' ),
                    'success',
                    true,
                    true
                ) );
            }
            wp_safe_redirect( PriceEditorPage::getHistoryURL() );
            exit;
        } );
    }

    public static function getDeleteRunURL( int $runId ) : string {
        return add_query_arg( array(
            'action'   => self::DELETE_RUN_ACTION,
            'run_id'   => $runId,
            '_wpnonce' => wp_create_nonce( self::DELETE_RUN_ACTION ),
        ), admin_url( 'admin-post.php' ) );
    }

    public function run() : void {
        check_ajax_referer( self::SCHEDULE__ACTION_NAME, 'nonce' );
        if ( !current_user_can( BulkPriceEditorPlugin::capability() ) ) {
            wp_send_json_error( array(
                'message' => 'Invalid request',
            ) );
            return;
        }
        $priceModifier = Request::getPriceModifier( $_REQUEST );
        if ( !$priceModifier ) {
            wp_send_json_error( array(
                'message' => 'Price modifier missed',
            ) );
            return;
        }
        $filters = Request::getFilters( $_REQUEST );
        $schedule = Request::getScheduleData( $_REQUEST );
        $canSchedule = false;
        if ( !$canSchedule && ('later' === $schedule['mode'] || '' !== $schedule['revert_at']) ) {
            wp_send_json_error( array(
                'message' => __( 'Scheduling updates and automatic revert are premium features.', 'bulk-price-editor' ),
            ) );
            return;
        }
        $scheduleTimestamp = null;
        $revertTimestamp = null;
        if ( 'later' === $schedule['mode'] ) {
            $scheduleTimestamp = self::parseLocalDatetime( $schedule['schedule_at'] );
            if ( !$scheduleTimestamp || $scheduleTimestamp < time() + MINUTE_IN_SECONDS ) {
                wp_send_json_error( array(
                    'message' => __( 'Please pick a start time at least a minute in the future.', 'bulk-price-editor' ),
                ) );
                return;
            }
        }
        if ( '' !== $schedule['revert_at'] ) {
            $revertTimestamp = self::parseLocalDatetime( $schedule['revert_at'] );
            $minimum = ( $scheduleTimestamp ? $scheduleTimestamp : time() );
            if ( !$revertTimestamp || $revertTimestamp < $minimum + MINUTE_IN_SECONDS ) {
                wp_send_json_error( array(
                    'message' => __( 'The automatic revert time must be after the update starts.', 'bulk-price-editor' ),
                ) );
                return;
            }
        }
        $runData = array(
            'user_id'       => get_current_user_id(),
            'modifier_type' => $priceModifier->getType(),
            'modifier_args' => wp_json_encode( $priceModifier->getRawArgs() ),
            'filters'       => wp_json_encode( $filters ),
            'revert_at'     => ( $revertTimestamp ? gmdate( 'Y-m-d H:i:s', $revertTimestamp ) : null ),
        );
        /**
         * Scheduled for later: store the run and let Action Scheduler start it.
         */
        if ( $scheduleTimestamp ) {
            /*
             * Validate the filters now and store the current match count as an
             * estimate — the real product list is resolved again at start time.
             */
            $estimate = count( self::resolveProducts( $filters ) );
            if ( !$estimate ) {
                wp_send_json_error( array(
                    'message' => __( 'No products match the selected filters.', 'bulk-price-editor' ),
                ) );
                return;
            }
            $runData['status'] = Run::STATUS_SCHEDULED;
            $runData['scheduled_at'] = gmdate( 'Y-m-d H:i:s', $scheduleTimestamp );
            $runData['total'] = $estimate;
            $runId = RunsRepository::insertRun( $runData );
            WC()->queue()->schedule_single(
                $scheduleTimestamp,
                self::START_SCHEDULED_RUN_ACTION,
                array($runId),
                self::QUEUE_GROUP
            );
            if ( $revertTimestamp ) {
                RevertPricesAction::scheduleAutoRevert( $runId, $revertTimestamp );
            }
            /* translators: %s: date and time */
            $message = sprintf( __( 'Price update scheduled for %s.', 'bulk-price-editor' ), wp_date( 'M j, Y H:i', $scheduleTimestamp ) );
            NotificationManager::addNotification( new Notification(
                $message,
                'success',
                true,
                true
            ) );
            wp_send_json_success( array(
                'message' => $message,
            ) );
            return;
        }
        /**
         * Apply now.
         */
        if ( self::isRunning() ) {
            wp_send_json_error( array(
                'message' => __( 'A price update is already in progress. Please wait for it to finish.', 'bulk-price-editor' ),
            ) );
            return;
        }
        $runId = RunsRepository::insertRun( $runData );
        $run = RunsRepository::getRun( $runId );
        $result = self::startRun( $run );
        if ( is_wp_error( $result ) ) {
            RunsRepository::deleteRun( $runId );
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
            ) );
            return;
        }
        if ( $revertTimestamp ) {
            RevertPricesAction::scheduleAutoRevert( $runId, $revertTimestamp );
        }
        /*
         * A small run may have completed entirely within the inline first
         * chunk — the completion notification is already queued then, and a
         * "has been started" message would just duplicate it.
         */
        if ( self::isRunning() ) {
            NotificationManager::addNotification( new Notification(
                __( 'Prices updating has been started.', 'bulk-price-editor' ),
                'success',
                true,
                true
            ) );
        }
        wp_send_json_success( array(
            'message' => __( 'Prices updating has been started.', 'bulk-price-editor' ),
        ) );
    }

    /**
     * Resolve the products (at execution time — the catalog may have changed
     * since scheduling), chunk them and queue the chunks.
     *
     * @return true|WP_Error
     */
    public static function startRun( Run $run ) {
        $products = self::resolveProducts( $run->getFilters() );
        if ( empty( $products ) ) {
            RunsRepository::updateRun( $run->getId(), array(
                'status' => Run::STATUS_FAILED,
            ) );
            RevertPricesAction::cancelAutoRevert( $run->getId() );
            $message = __( 'No products match the selected filters.', 'bulk-price-editor' );
            if ( $run->getStatus() === Run::STATUS_SCHEDULED ) {
                NotificationManager::addNotificationForUser( $run->getUserId(), new Notification(
                    __( 'The scheduled price update was skipped: no products matched the selected filters.', 'bulk-price-editor' ),
                    'error',
                    false,
                    false
                ) );
            }
            return new WP_Error('bpe_no_products', $message);
        }
        RunsRepository::updateRun( $run->getId(), array(
            'status'     => Run::STATUS_RUNNING,
            'total'      => count( $products ),
            'processed'  => 0,
            'started_at' => gmdate( 'Y-m-d H:i:s' ),
        ) );
        update_option( self::CURRENT_RUN_OPTION, $run->getId() );
        $chunks = array_chunk( $products, self::getProductsPerRun() );
        /**
         * Run the first chunk immediately, queue the rest.
         */
        $firstChunk = array_shift( $chunks );
        foreach ( $chunks as $productIds ) {
            WC()->queue()->add( UpdateProductPriceAction::ACTION_NAME, array($run->getId(), $productIds), self::QUEUE_GROUP );
        }
        UpdateProductPriceAction::updatePrices( $run->getId(), $firstChunk );
        return true;
    }

    /**
     * The product ids a set of filters matches right now.
     */
    public static function resolveProducts( array $filters ) : array {
        $productQuery = new ProductQuery(new FilterManager($filters), array(
            'posts_per_page' => -1,
            'limit'          => -1,
            'return'         => 'ids',
            'paginate'       => false,
        ));
        return self::excludeVariationsCoveredByParent( $productQuery->build()->get_products() );
    }

    /**
     * Action Scheduler callback for a scheduled run.
     */
    public static function startScheduledRun( $runId ) : void {
        $run = RunsRepository::getRun( (int) $runId );
        if ( !$run || Run::STATUS_SCHEDULED !== $run->getStatus() ) {
            return;
        }
        // Another run is busy — try again shortly.
        if ( self::isRunning() ) {
            WC()->queue()->schedule_single(
                time() + 2 * MINUTE_IN_SECONDS,
                self::START_SCHEDULED_RUN_ACTION,
                array((int) $runId),
                self::QUEUE_GROUP
            );
            return;
        }
        self::startRun( $run );
    }

    public static function cancelScheduledRun( int $runId ) : void {
        $run = RunsRepository::getRun( $runId );
        if ( !$run || Run::STATUS_SCHEDULED !== $run->getStatus() ) {
            return;
        }
        WC()->queue()->cancel_all( self::START_SCHEDULED_RUN_ACTION, array($runId), self::QUEUE_GROUP );
        RevertPricesAction::cancelAutoRevert( $runId );
        RunsRepository::updateRun( $runId, array(
            'status' => Run::STATUS_CANCELED,
        ) );
        NotificationManager::addNotification( new Notification(
            __( 'Scheduled price update has been canceled.', 'bulk-price-editor' ),
            'notice',
            true,
            true
        ) );
    }

    public function stopCurrentRun() : void {
        WC()->queue()->cancel_all( UpdateProductPriceAction::ACTION_NAME );
        WC()->queue()->cancel_all( RevertPricesAction::ACTION_NAME );
        $run = self::getCurrentRun();
        if ( $run ) {
            RunsRepository::updateRun( $run->getId(), array(
                'status' => Run::STATUS_STOPPED,
            ) );
            delete_option( self::CURRENT_RUN_OPTION );
            RevertPricesAction::cancelAutoRevert( $run->getId() );
        }
        $message = __( 'Prices updating has been stopped.', 'bulk-price-editor' );
        if ( $run && !$run->isRevert() && $run->getProcessed() > 0 ) {
            $message .= ' ' . __( 'You can revert the products that were already updated from the History tab.', 'bulk-price-editor' );
        }
        NotificationManager::addNotification( new Notification(
            $message,
            'notice',
            true,
            true
        ) );
    }

    public static function getCurrentRun() : ?Run {
        $runId = (int) get_option( self::CURRENT_RUN_OPTION, 0 );
        if ( !$runId ) {
            return null;
        }
        $run = RunsRepository::getRun( $runId );
        if ( !$run ) {
            delete_option( self::CURRENT_RUN_OPTION );
            return null;
        }
        return $run;
    }

    public static function clearCurrentRun( int $runId ) : void {
        if ( (int) get_option( self::CURRENT_RUN_OPTION, 0 ) === $runId ) {
            delete_option( self::CURRENT_RUN_OPTION );
        }
    }

    public static function getProgressURL() : string {
        return add_query_arg( array(
            'action' => self::GET_PROGRESS__ACTION_NAME,
            'nonce'  => wp_create_nonce( self::GET_PROGRESS__ACTION_NAME ),
        ), admin_url( 'admin-ajax.php' ) );
    }

    public static function getURL() : string {
        return add_query_arg( array(
            'action' => self::SCHEDULE__ACTION_NAME,
            'nonce'  => wp_create_nonce( self::SCHEDULE__ACTION_NAME ),
        ), admin_url( 'admin-ajax.php' ) );
    }

    public static function getProgressData() : array {
        $run = self::getCurrentRun();
        if ( !$run ) {
            return array(
                'running'   => false,
                'total'     => 0,
                'processed' => 0,
            );
        }
        return array(
            'running'   => Run::STATUS_RUNNING === $run->getStatus(),
            'total'     => $run->getTotal(),
            'processed' => $run->getProcessed(),
            'type'      => $run->getType(),
        );
    }

    public static function isRunning() : bool {
        $run = self::getCurrentRun();
        return $run && Run::STATUS_RUNNING === $run->getStatus();
    }

    public static function getProductsPerRun() : int {
        $productsPerRun = apply_filters( 'bulk_price_editor/action/update_prices/products_pee_run', self::PRODUCTS_PER_RUN );
        return (int) apply_filters( 'bulk_price_editor/action/update_prices/products_per_run', $productsPerRun );
    }

    /**
     * A datetime-local value ('Y-m-d\TH:i', site timezone) as a Unix timestamp.
     */
    public static function parseLocalDatetime( string $value ) : ?int {
        $value = str_replace( 'T', ' ', trim( $value ) );
        if ( '' === $value ) {
            return null;
        }
        $datetime = date_create( $value, wp_timezone() );
        return ( $datetime ? $datetime->getTimestamp() : null );
    }

    public static function getCancelScheduledRunURL( int $runId ) : string {
        return add_query_arg( array(
            'action'   => self::CANCEL_SCHEDULED_RUN_ACTION,
            'run_id'   => $runId,
            '_wpnonce' => wp_create_nonce( self::CANCEL_SCHEDULED_RUN_ACTION ),
        ), admin_url( 'admin-post.php' ) );
    }

    public static function getStopURL() : string {
        return add_query_arg( array(
            'action'   => self::STOP_UPDATING__ACTION_NAME,
            '_wpnonce' => wp_create_nonce( self::STOP_UPDATING__ACTION_NAME ),
        ), admin_url( 'admin-post.php' ) );
    }

    /**
     * A variable product in the list already updates all its variations (PriceModifier::updatePrices
     * recurses into children), so scheduling those variations as well would apply the modifier twice.
     *
     * @param  array  $productIds
     *
     * @return array
     */
    public static function excludeVariationsCoveredByParent( array $productIds ) : array {
        $productIds = array_values( array_unique( array_map( 'intval', $productIds ) ) );
        $lookup = array_flip( $productIds );
        _prime_post_caches( $productIds, false, false );
        return array_values( array_filter( $productIds, function ( $productId ) use($lookup) {
            $post = get_post( $productId );
            if ( $post && 'product_variation' === $post->post_type ) {
                return !isset( $lookup[(int) $post->post_parent] );
            }
            return true;
        } ) );
    }

}
