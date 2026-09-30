<?php
// Test-only child process: direct takeover attempt used to prove that the
// generation-bumping lease UPDATE blocks behind the item transaction's job row
// lock and only succeeds after that transaction releases it.
$spec = json_decode( file_get_contents( $args[0] ), true );
global $wpdb;
if ( ! empty( $spec['lock_wait_timeout'] ) ) { $wpdb->query( 'SET SESSION innodb_lock_wait_timeout = ' . (int) $spec['lock_wait_timeout'] ); }
file_put_contents( $spec['started'], (string) getmypid() );
try {
	$lease = WriteLeash\Job_Repository::acquire_lease( (int) $spec['job_id'], (string) $spec['owner'], ! empty( $spec['manual'] ) );
	file_put_contents( $spec['result'], json_encode( array( 'lease' => $lease ) ) );
} catch ( \Throwable $error ) {
	file_put_contents( $spec['result'], json_encode( array( 'error' => $error instanceof WriteLeash\Job_Error ? $error->reason() : get_class( $error ) ) ) );
}
