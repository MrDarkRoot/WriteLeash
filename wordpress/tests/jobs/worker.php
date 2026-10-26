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
		if ( 'throw' === ( $spec['mutator_fault'] ?? '' ) ) { throw new RuntimeException( 'controlled-mutator-fault' ); }
		if ( 'wait' === ( $spec['mutator_fault'] ?? '' ) ) {
			// Default stays 30s. One race passes a longer budget so creator
			// bootstrap cannot outlive the barrier and look like a missed lock.
			$wait_seconds = isset( $spec['mutator_wait_seconds'] ) ? (int) $spec['mutator_wait_seconds'] : 30;
			if ( $wait_seconds < 1 || $wait_seconds > 180 ) { $wait_seconds = 30; }
			$deadline = microtime( true ) + $wait_seconds;
			while ( ! is_file( $release ) ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'release-timeout' ); }
				usleep( 10000 );
			}
		}
	}, 10, 3 );
}
// #297 commit-level fault: the hook fires inside Price_Apply_Connection::commit()
// after the durable COMMIT succeeded but before ownership is released. A throw
// here models a lost acknowledgement with the write already durable; the
// worker cannot know the outcome from the exception alone.
// The commit transport is shared (claims, setup, mutations), so the fault
// arms only once the mutator reaches AFTER_JOURNAL_BEFORE_COMMIT: the next
// commit on this connection is then provably the item commit, never a claim.
if ( ! empty( $spec['commit_checkpoint'] ) ) {
	add_action( 'writeleash_price_apply_checkpoint', static function ( $point ) use ( $spec ) {
		if ( 'AFTER_JOURNAL_BEFORE_COMMIT' !== $point ) { return; }
		// One-shot: the next transport commit is provably the item commit, so
		// the fault must disarm itself to leave later claim/finish commits alone.
		$fault = null;
		$fault = static function ( $cpoint ) use ( $spec, &$fault ) {
			if ( $cpoint !== $spec['commit_checkpoint'] ) { return; }
			remove_action( 'writeleash_price_commit_checkpoint', $fault, 10 );
			file_put_contents( $spec['barrier'], $cpoint . ':' . (int) $GLOBALS['wpdb']->dbh->thread_id );
			if ( 'throw-ambiguous' === ( $spec['commit_fault'] ?? '' ) ) { throw new \WriteLeash\Price_Apply_Error( 'AMBIGUOUS_COMMIT' ); }
		};
		add_action( 'writeleash_price_commit_checkpoint', $fault, 10, 2 );
	}, 10, 3 );
}
// #298 audit-store fault: fire on the grant audit seam to prove the caller
// rolls back a mandatory evidence append rather than committing without it.
if ( ! empty( $spec['audit_fault_event'] ) ) {
	add_action( 'writeleash_grant_audit_before_insert', static function ( $event ) use ( $spec ) {
		if ( $event !== $spec['audit_fault_event'] ) { return; }
		if ( 'throw' === ( $spec['audit_fault'] ?? '' ) ) { throw new RuntimeException( 'controlled-audit-fault' ); }
	} );
	if ( 'false-insert' === ( $spec['audit_fault'] ?? '' ) ) {
		add_filter( 'query', static function ( $sql ) use ( $spec ) {
			if ( ! is_string( $sql ) || false === strpos( $sql, (string) $spec['audit_fault_event'] ) || false === stripos( $sql, 'insert' ) ) { return $sql; }
			return 'SELECT missing_column FROM writeleash_grant_audit_fault_missing';
		} );
	}
}
// #306: a failed restriction classification must not fall through to a save.
if ( ! empty( $spec['restriction_read_fault'] ) ) {
	add_filter( 'query', static function ( $sql ) {
		if ( ! is_string( $sql ) || false === strpos( $sql, 'restriction_removed_at' ) || false === strpos( $sql, 'writeleash_grants' ) ) { return $sql; }
		return 'SELECT missing_column FROM writeleash_restriction_fault_missing';
	} );
}
// Public Woo factory filter staging a transient read refusal for one product,
// exactly as the #209 durable probe does; never a mutator/production mock.
if ( ! empty( $spec['class_fault'] ) ) {
	$class_fault_id = (int) $spec['class_fault'];
	add_filter( 'woocommerce_product_class', static function ( $class, $type, $context, $id ) use ( $class_fault_id ) { return $id === $class_fault_id ? 'stdClass' : $class; }, 10, 4 );
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
