<?php
// Test-only job worker process. Fault injection uses production checkpoint hooks.
require __DIR__ . '/../durable/hook-torture.php';
$spec = json_decode( file_get_contents( $args[0] ), true );
if ( ! is_array( $spec ) || ! isset( $spec['job_id'], $spec['started'], $spec['result'] ) ) {
	throw new RuntimeException( 'worker spec invalid' );
}
$GLOBALS['wl108_active'] = ! empty( $spec['hook_log'] );
$GLOBALS['wl108_log'] = (string) ( $spec['hook_log'] ?? '' );
global $wpdb;
if ( ! empty( $spec['lock_wait_timeout'] ) ) { $wpdb->query( 'SET SESSION innodb_lock_wait_timeout = ' . (int) $spec['lock_wait_timeout'] ); }
file_put_contents( $spec['started'], (string) getmypid() );

if ( ! empty( $spec['checkpoint'] ) ) {
	add_action( 'writeleash_job_checkpoint', static function ( $point, $job_id, $item_id, $generation, $token ) use ( $spec ) {
		if ( $point !== $spec['checkpoint'] ) { return; }
		file_put_contents( $spec['barrier'], $point . ':' . $generation . ':' . (int) $GLOBALS['wpdb']->dbh->thread_id );
		if ( 'wait' === ( $spec['fault'] ?? '' ) ) {
			$deadline = microtime( true ) + 30;
			while ( ! is_file( $spec['release'] ) ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'release-timeout' ); }
				usleep( 10000 );
			}
		}
		if ( 'throw' === ( $spec['fault'] ?? '' ) ) { throw new RuntimeException( 'controlled-worker-fault' ); }
	}, 10, 5 );
}
if ( ! empty( $spec['mutator_checkpoint'] ) ) {
	add_action( 'writeleash_price_apply_checkpoint', static function ( $point, $item_id, $attempt ) use ( $spec ) {
		if ( $point !== $spec['mutator_checkpoint'] ) { return; }
		// Staged fault tests may pause an outer job checkpoint and a mutator
		// checkpoint in the same process; distinct paths keep them independent.
		$barrier = (string) ( $spec['mutator_barrier'] ?? $spec['barrier'] );
		$release = (string) ( $spec['mutator_release'] ?? $spec['release'] );
		file_put_contents( $barrier, $point . ':' . (int) $GLOBALS['wpdb']->dbh->thread_id );
		if ( 'wait' === ( $spec['mutator_fault'] ?? '' ) ) {
			$deadline = microtime( true ) + 30;
			while ( ! is_file( $release ) ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'release-timeout' ); }
				usleep( 10000 );
			}
		}
	}, 10, 3 );
}

$limits = isset( $spec['limits'] ) && is_array( $spec['limits'] ) ? $spec['limits'] : array( 'max_items' => 10, 'budget_seconds' => 15 );
if ( 'callback' === ( $spec['mode'] ?? 'run' ) ) {
	do_action( WriteLeash\Job_Scheduler::HOOK, (int) $spec['job_id'] );
	$result = array( 'callback' => true );
} else {
	$result = WriteLeash\Job_Worker::run( (int) $spec['job_id'], $limits, ! empty( $spec['manual'] ) );
}
file_put_contents( $spec['result'], json_encode( $result ) );
echo "worker-complete\n";
