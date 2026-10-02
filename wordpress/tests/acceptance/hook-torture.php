<?php
// Test-only synthetic Woo save-hook plugin. HTTP/mail are intercepted attempts,
// not delivery claims; queue scheduling uses the actual AS public API.
function wl112_hook_event( string $event, int $id ): void {
    file_put_contents( '/evidence/' . DB_HOST . '-hooks.jsonl', json_encode( array( 'event' => $event, 'product' => $id ) ) . "\n", FILE_APPEND );
}
add_action( 'woocommerce_before_product_object_save', static function ( $p ) {
    global $wpdb;
    $spec = get_option( 'wl112_hook', array() );
    if ( (int) ( $spec['id'] ?? 0 ) !== $p->get_id() ) { return; }
    $id = $p->get_id();
    wl112_hook_event( 'before-save', $id );
    $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}wl112_hook_events (product_id) VALUES (%d)", $id ) );
    $http = static function ( $pre, $args, $url ) use ( $id ) {
        if ( 'https://side-effect.example.test/wl112' !== $url ) { return $pre; }
        wl112_hook_event( 'http-attempt-intercepted', $id );
        return array( 'headers' => array(), 'body' => 'fixture', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
    };
    add_filter( 'pre_http_request', $http, 10, 3 );
    wp_remote_post( 'https://side-effect.example.test/wl112', array( 'body' => 'fixture' ) );
    remove_filter( 'pre_http_request', $http, 10 );
    $mail = static function () use ( $id ) { wl112_hook_event( 'mail-attempt-intercepted', $id ); return true; };
    add_filter( 'pre_wp_mail', $mail );
    wp_mail( 'fixture@example.test', 'WL112', 'fixture' );
    remove_filter( 'pre_wp_mail', $mail );
    $action = as_enqueue_async_action( 'wl112_external_queue', array( $id ), 'wl112-external', false );
    wl112_hook_event( $action ? 'queue-scheduled' : 'queue-unavailable', $id );
    switch ( $spec['mode'] ) {
        case 'property': $p->set_regular_price( '88.00' ); break;
        case 'throw': throw new RuntimeException( 'synthetic-save-hook-exception' );
        case 'error': throw new Error( 'synthetic-save-hook-error' );
        case 'query-commit': $wpdb->query( 'COMMIT' ); break;
        case 'raw-commit': $wpdb->dbh->commit(); break;
    }
}, 10 );
add_action( 'woocommerce_after_product_object_save', static function ( $p ) {
    $spec = get_option( 'wl112_hook', array() );
    if ( (int) ( $spec['id'] ?? 0 ) === $p->get_id() ) { wl112_hook_event( 'after-save', $p->get_id() ); }
}, 10 );
