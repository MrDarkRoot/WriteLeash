<?php
// Test-only grant creation. Blocks behind an in-flight item's grant lock.
$spec = json_decode( file_get_contents( $args[0] ), true );
if ( ! is_array( $spec ) || ! isset( $spec['issuer'], $spec['actor'], $spec['started'], $spec['result'] ) ) {
	throw new RuntimeException( 'create-grant spec invalid' );
}
global $wpdb;
if ( ! empty( $spec['lock_wait_timeout'] ) ) { $wpdb->query( 'SET SESSION innodb_lock_wait_timeout = ' . (int) $spec['lock_wait_timeout'] ); }
file_put_contents( $spec['started'], (string) getmypid() );
wp_set_current_user( (int) $spec['issuer'] );
$grant = WriteLeash\Grant_Repository::create_grant( (int) $spec['issuer'], (int) $spec['actor'], 1, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
file_put_contents( $spec['result'], json_encode( array( 'public_id' => $grant['public_id'] ) ) );
echo "grant-created\n";
