<?php
// Test-only child process: activation-style lease reaper attempted while a
// worker holds the per-item transactional fence.
$spec = json_decode( file_get_contents( $args[0] ), true );
global $wpdb;
if ( ! empty( $spec['lock_wait_timeout'] ) ) { $wpdb->query( 'SET SESSION innodb_lock_wait_timeout = ' . (int) $spec['lock_wait_timeout'] ); }
file_put_contents( $spec['started'], (string) getmypid() );
try {
	$reaped = WriteLeash\Job_Repository::reap_stalled_leases();
	file_put_contents( $spec['result'], json_encode( array( 'reaped' => $reaped ) ) );
} catch ( \Throwable $error ) {
	file_put_contents( $spec['result'], json_encode( array( 'error' => $error instanceof WriteLeash\Job_Error ? $error->reason() : get_class( $error ) ) ) );
}
