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

// Per-process retention override for the initiation-vs-purge race test: one
// process legitimately considers a job in retention while another considers
// it expired, so both race for the same authoritative job row.
if ( ! empty( $spec['retention_days'] ) ) {
	add_filter( 'writeleash_history_retention_days', static function () use ( $spec ) { return (int) $spec['retention_days']; } );
}

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
// A second boundary after COMMIT lets the shutdown contender acquire the
// lifecycle row before the worker reaches its next loop/claim. This is
// separate from the in-transaction barrier above.
if ( ! empty( $spec['after_item_barrier'] ) ) {
	foreach ( array( 'writeleash_job_checkpoint', 'writeleash_undo_checkpoint' ) as $hook ) {
		add_action( $hook, static function ( $point ) use ( $spec ) {
			if ( 'AFTER_ITEM' !== $point ) { return; }
			file_put_contents( $spec['after_item_barrier'], (string) $GLOBALS['wpdb']->dbh->thread_id );
			$deadline = microtime( true ) + 30;
			while ( ! is_file( $spec['after_item_release'] ) ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'after-item-release-timeout' ); }
				usleep( 10000 );
			}
		}, 20, 6 );
	}
}
if ( ! empty( $spec['initiate_checkpoint'] ) ) {
	add_action( 'writeleash_undo_initiate_checkpoint', static function ( $point ) use ( $spec ) {
		if ( $point !== $spec['initiate_checkpoint'] ) { return; }
		file_put_contents( $spec['barrier'], $point . ':' . (int) $GLOBALS['wpdb']->dbh->thread_id );
		if ( 'throw' === ( $spec['fault'] ?? '' ) ) { throw new RuntimeException( 'controlled-initiate-fault' ); }
		if ( 'wait' === ( $spec['fault'] ?? '' ) ) {
			$deadline = microtime( true ) + 30;
			while ( ! is_file( $spec['release'] ) ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'release-timeout' ); }
				usleep( 10000 );
			}
		}
	}, 10, 2 );
}
if ( ! empty( $spec['purge_checkpoint'] ) ) {
	add_action( 'writeleash_purge_checkpoint', static function ( $point ) use ( $spec ) {
		if ( $point !== $spec['purge_checkpoint'] ) { return; }
		file_put_contents( $spec['barrier'], $point . ':' . (int) $GLOBALS['wpdb']->dbh->thread_id );
		if ( 'throw' === ( $spec['fault'] ?? '' ) ) { throw new RuntimeException( 'controlled-purge-fault' ); }
		if ( 'wait' === ( $spec['fault'] ?? '' ) ) {
			$deadline = microtime( true ) + 30;
			while ( ! is_file( $spec['release'] ) ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'release-timeout' ); }
				usleep( 10000 );
			}
		}
	}, 10, 2 );
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
} elseif ( 'initiate' === $mode ) {
	try {
		$operation = WriteLeash\Undo_Repository::initiate( (int) $spec['job_id'], (int) ( $spec['actor'] ?? 1 ) );
		$result = array( 'operation' => (int) $operation['id'], 'status' => $operation['status'] );
	} catch ( \Throwable $error ) {
		$result = array( 'error' => $error instanceof WriteLeash\Undo_Error ? $error->reason() : 'EXCEPTION' );
	}
} elseif ( 'purge' === $mode ) {
	$result = array( 'purged' => WriteLeash\Undo_Repository::purge_expired( (int) ( $spec['batch'] ?? 20 ) ) );
} else {
	$result = WriteLeash\Undo_Worker::run( (int) $spec['undo_id'], $limits, ! empty( $spec['manual'] ) );
}
file_put_contents( $spec['result'], json_encode( $result ) );
echo "undo-worker-complete\n";
