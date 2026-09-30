<?php
// Test-only Undo worker process. Fault injection uses production checkpoint hooks.
require __DIR__ . '/../durable/hook-torture.php';
$spec = json_decode( file_get_contents( $args[0] ), true );
if ( ! is_array( $spec ) || ! isset( $spec['started'], $spec['result'] ) ) {
	throw new RuntimeException( 'undo worker spec invalid' );
}
$GLOBALS['wl108_active'] = ! empty( $spec['hook_log'] );
$GLOBALS['wl108_log'] = (string) ( $spec['hook_log'] ?? '' );
global $wpdb;
if ( ! empty( $spec['lock_wait_timeout'] ) ) { $wpdb->query( 'SET SESSION innodb_lock_wait_timeout = ' . (int) $spec['lock_wait_timeout'] ); }
file_put_contents( $spec['started'], (string) getmypid() );

if ( ! empty( $spec['undo_checkpoint'] ) ) {
	add_action( 'writeleash_undo_checkpoint', static function ( $point ) use ( $spec ) {
		if ( $point !== $spec['undo_checkpoint'] ) { return; }
		file_put_contents( $spec['barrier'], $point . ':' . (int) $GLOBALS['wpdb']->dbh->thread_id );
		if ( 'throw' === ( $spec['fault'] ?? '' ) ) { throw new RuntimeException( 'controlled-undo-fault' ); }
		if ( 'wait' === ( $spec['fault'] ?? '' ) ) {
			$deadline = microtime( true ) + 30;
			while ( ! is_file( $spec['release'] ) ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'release-timeout' ); }
				usleep( 10000 );
			}
		}
	}, 10, 6 );
}
if ( ! empty( $spec['job_checkpoint'] ) ) {
	add_action( 'writeleash_job_checkpoint', static function ( $point ) use ( $spec ) {
		if ( $point !== $spec['job_checkpoint'] ) { return; }
		file_put_contents( $spec['barrier'], $point . ':' . (int) $GLOBALS['wpdb']->dbh->thread_id );
		if ( 'wait' === ( $spec['fault'] ?? '' ) ) {
			$deadline = microtime( true ) + 30;
			while ( ! is_file( $spec['release'] ) ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'release-timeout' ); }
				usleep( 10000 );
			}
		}
	}, 10, 5 );
}
if ( ! empty( $spec['apply_checkpoint'] ) ) {
	add_action( 'writeleash_price_apply_checkpoint', static function ( $point ) use ( $spec ) {
		if ( $point !== $spec['apply_checkpoint'] ) { return; }
		file_put_contents( $spec['barrier'], $point . ':' . (int) $GLOBALS['wpdb']->dbh->thread_id );
		if ( 'wait' === ( $spec['fault'] ?? '' ) ) {
			$deadline = microtime( true ) + 30;
			while ( ! is_file( $spec['release'] ) ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'release-timeout' ); }
				usleep( 10000 );
			}
		}
	}, 10, 3 );
}

$limits = isset( $spec['limits'] ) && is_array( $spec['limits'] ) ? $spec['limits'] : array( 'max_items' => 10, 'budget_seconds' => 15 );
$mode = (string) ( $spec['mode'] ?? 'undo' );
if ( 'undo-callback' === $mode ) {
	do_action( WriteLeash\Undo_Scheduler::HOOK, (int) $spec['undo_id'] );
	$result = array( 'callback' => true );
} elseif ( 'apply' === $mode ) {
	$result = WriteLeash\Job_Worker::run( (int) $spec['job_id'], $limits, ! empty( $spec['manual'] ) );
} elseif ( 'purge' === $mode ) {
	$result = array( 'purged' => WriteLeash\Undo_Repository::purge_expired( (int) ( $spec['batch'] ?? 20 ) ) );
} else {
	$result = WriteLeash\Undo_Worker::run( (int) $spec['undo_id'], $limits, ! empty( $spec['manual'] ) );
}
file_put_contents( $spec['result'], json_encode( $result ) );
echo "undo-worker-complete\n";
