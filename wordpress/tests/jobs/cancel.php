<?php
// Test-only child process: operator cancel attempted while a worker holds the
// per-item transactional fence. Bounded session lock wait keeps CI honest.
$spec = json_decode( file_get_contents( $args[0] ), true );
global $wpdb;
if ( ! empty( $spec['lock_wait_timeout'] ) ) { $wpdb->query( 'SET SESSION innodb_lock_wait_timeout = ' . (int) $spec['lock_wait_timeout'] ); }
file_put_contents( $spec['started'], (string) getmypid() );
try {
	$result = WriteLeash\Job_Repository::cancel( (int) $spec['job_id'], (int) $spec['actor'] );
	file_put_contents( $spec['result'], json_encode( array( 'result' => $result ) ) );
} catch ( \Throwable $error ) {
	file_put_contents( $spec['result'], json_encode( array( 'error' => $error instanceof WriteLeash\Job_Error ? $error->reason() : get_class( $error ) ) ) );
}
