<?php
// Separate real WordPress process for deterministic deactivation/uninstall.
$spec = json_decode( file_get_contents( $args[0] ), true );
if ( ! is_array( $spec ) || ! isset( $spec['started'], $spec['result'] ) ) { throw new RuntimeException( 'lifecycle spec missing' ); }
global $wpdb;
$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 25' );
file_put_contents( $spec['started'], (string) $wpdb->dbh->thread_id );
if ( 'deactivate' === ( $spec['mode'] ?? '' ) ) {
	WriteLeash\Lifecycle::deactivate();
} elseif ( 'uninstall' === ( $spec['mode'] ?? '' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( true !== uninstall_plugin( 'writeleash/writeleash.php' ) ) { throw new RuntimeException( 'uninstall failed' ); }
} else { throw new RuntimeException( 'invalid lifecycle mode' ); }
file_put_contents( $spec['result'], json_encode( array( 'mode' => $spec['mode'], 'state' => $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, 'writeleash_runner_state' ) ) ) ) );
