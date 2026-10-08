<?php
// Repository-only mu observer/fault fixture. No cookie, nonce, password or URL retention.
if ( PHP_SAPI === 'cli-server' ) {
    $wl125_saves = array();
    add_action( 'woocommerce_before_product_object_save', static function ( $product ) use ( &$wl125_saves ) { $wl125_saves[] = $product->get_id(); } );
    add_action( 'shutdown', static function () use ( &$wl125_saves ) {
        $action = filter_input( INPUT_POST, 'action' );
        if ( ! is_string( $action ) || ! preg_match( '/\Awriteleash_free_(?:preview|approve|resume|undo)\z/', $action ) ) { $action = 'read'; }
        file_put_contents( getenv( 'WL112_METRICS' ), json_encode( array( 'action' => $action, 'woo_save_product_ids' => $wl125_saves, 'php' => PHP_VERSION, 'wp' => get_bloginfo( 'version' ), 'woo' => defined( 'WC_VERSION' ) ? WC_VERSION : null, 'configuration' => getenv( 'WL125_LABEL' ) ) ) . "\n", FILE_APPEND );
    } );
    add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
    add_filter( 'pre_as_enqueue_async_action', static function ( $pre, $hook ) {
        if ( get_option( 'wl112_scheduler_down' ) && $hook === 'writeleash_process_job' ) { return 0; }
        return $pre;
    }, 10, 2 );
}
// Explicit negative fixture: hostile save hook commits outside the owned fence.
// This must be NEEDS_REVIEW, never APPLIED from target equality alone.
add_action( 'writeleash_price_apply_checkpoint', static function ( $point, $id ) {
    if ( 'AFTER_WOO_SAVE_BEFORE_JOURNAL' === $point && (int) get_option( 'wl125_review_fault' ) === $id ) { $GLOBALS['wpdb']->dbh->commit(); }
}, 10, 2 );
// Side-effect attempts are intercepted; no real mail/HTTP delivery is claimed.
add_action( 'woocommerce_after_product_object_save', static function ( $p ) {
    if ( (int) get_option( 'wl125_effect_product' ) !== $p->get_id() ) { return; }
    $http = static function ( $pre ) { return array( 'headers'=>array(), 'body'=>'synthetic', 'response'=>array('code'=>200,'message'=>'OK'), 'cookies'=>array() ); };
    add_filter( 'pre_http_request', $http, 10, 1 ); wp_remote_post( 'https://synthetic.invalid/effect' ); remove_filter( 'pre_http_request', $http, 10 );
    $mail = static function () { return true; }; add_filter( 'pre_wp_mail', $mail ); wp_mail( 'synthetic@example.invalid', 'Synthetic acceptance effect', 'Synthetic' ); remove_filter( 'pre_wp_mail', $mail );
    $attempts = (int) get_option( 'wl125_effect_attempts', 0 ); update_option( 'wl125_effect_attempts', $attempts + 1, false );
}, 10, 1 );
