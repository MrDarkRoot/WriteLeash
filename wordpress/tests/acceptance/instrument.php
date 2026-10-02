<?php
// Test-only request instrumentation. No cookies, nonces, credentials, URLs,
// product names or provider identifiers are retained.
if ( PHP_SAPI === 'cli-server' ) {
    $GLOBALS['wl112_started'] = microtime( true );
    $GLOBALS['wl112_queries'] = 0;
    $GLOBALS['wl112_saves'] = array();
    add_action( 'woocommerce_before_product_object_save', static function ( $p ) {
        $GLOBALS['wl112_saves'][] = $p->get_id();
    } );
    add_filter( 'query', static function ( $sql ) {
        ++$GLOBALS['wl112_queries'];
        return $sql;
    } );
    add_action( 'shutdown', static function () {
        file_put_contents( getenv( 'WL112_METRICS' ), json_encode( array(
            'seconds' => microtime( true ) - $GLOBALS['wl112_started'],
            'peak_php_bytes' => memory_get_peak_usage( true ),
            'queries_all_wpdb_connections' => $GLOBALS['wl112_queries'],
            'php' => PHP_VERSION,
            'memory_limit' => ini_get( 'memory_limit' ),
            'max_execution_time' => ini_get( 'max_execution_time' ),
            'action' => filter_input( INPUT_POST, 'action' ) ?: 'read',
            'woo_save_product_ids' => $GLOBALS['wl112_saves'],
            'fatal' => error_get_last(),
        ) ) . "\n", FILE_APPEND );
    } );
    // Deliberately impaired scheduler: neither traffic nor a loopback can run
    // queued work. These are public AS filters, not worker/lease overrides.
    add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
    add_action( 'writeleash_price_apply_checkpoint', static function ( $point, $id ) {
        $hook = get_option( 'wl112_hook', array() );
        if ( 'AFTER_COMMIT_BEFORE_RESPONSE' === $point && (int) ( $hook['id'] ?? 0 ) === $id && 'ambiguous-commit' === ( $hook['mode'] ?? '' ) ) {
            throw new WriteLeash\Price_Apply_Error( 'AMBIGUOUS_COMMIT' );
        }
        if ( 'AFTER_WOO_SAVE_BEFORE_JOURNAL' === $point && (int) get_option( 'wl112_rollback' ) === $id ) {
            throw new RuntimeException( 'synthetic-rollback-before-commit' );
        }
    }, 10, 2 );
    add_filter( 'pre_as_enqueue_async_action', static function ( $pre, $hook ) {
        if ( get_option( 'wl112_scheduler_down' ) && $hook === 'writeleash_process_job' ) { return 0; }
        return $pre;
    }, 10, 2 );
}
