<?php
/**
 * Run the grant job Action Scheduler already queued.
 * WP-Cron is off in this lab, so this invokes the same writeleash_process_job
 * hook the scheduler would call. It does not write a price itself.
 */
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "run-job.php must run under wp eval-file\n" );
	exit( 1 );
}
$public = getenv( 'WL327_JOB_PUBLIC_ID' );
if ( ! is_string( $public ) || '' === $public ) {
	fwrite( STDERR, "WL327_JOB_PUBLIC_ID is required\n" );
	exit( 1 );
}
$job = WriteLeash\Job_Repository::read_by_public_id( $public );
if ( ! is_array( $job ) || (int) ( $job['id'] ?? 0 ) < 1 ) {
	fwrite( STDERR, "job was not stored\n" );
	exit( 1 );
}
$before = (string) ( $job['status'] ?? '' );
do_action( 'writeleash_process_job', (int) $job['id'] );
$after = WriteLeash\Job_Repository::read( (int) $job['id'] );
$status = is_array( $after ) ? (string) ( $after['status'] ?? '' ) : '';
$reason = is_array( $after ) ? (string) ( $after['status_reason'] ?? '' ) : '';
fwrite( STDOUT, wp_json_encode( array( 'before' => $before, 'after' => $status, 'reason' => $reason, 'invocation' => 'writeleash_process_job' ) ) . "\n" );
if ( 'COMPLETED' !== $status && 'COMPLETED_WITH_ISSUES' !== $status ) {
	fwrite( STDERR, "job finished as {$status} {$reason}\n" );
	exit( 1 );
}
