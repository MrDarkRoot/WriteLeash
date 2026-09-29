<?php
// #61 deterministic concurrency fixture. A trusted row-lock barrier forces two
// real certified Redirection requests to overlap inside distinct restricted DB
// transactions. Phases are driven by the adapter CI script; no production code.
require_once WP_PLUGIN_DIR . '/commitcap-for-wordpress/commitcap-for-wordpress.php';
require_once __DIR__ . '/helpers.php';

use CommitCap\Certified_Operation as Operation;
use CommitCap\Certified_Operation_Status as Status;
use CommitCap\Operation_Config as Config;

$host = getenv( 'CC_ENGINE_HOST' );
$phase = getenv( 'CC61_PHASE' );
$phases = array( 'seed', 'lock_a', 'lock_b', 'barrier', 'run', 'await_overlap', 'verify' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), '#61 concurrency host' );
cc87_assert( in_array( $phase, $phases, true ), '#61 concurrency phase' );
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$runtime = new wpdb( 'cc87_writer', 'cc87_secret', 'wp_test', $host );
$runtime->suppress_errors( true );
$runtime->set_prefix( (string) $GLOBALS['wpdb']->prefix );
$operation = Operation::redirection_5_5_2_bulk_disable();
$table = (string) $GLOBALS['wpdb']->prefix . 'redirection_items';

/** Hold a trusted row lock until the release marker appears (bounded). */
function cc61_hold( $root, string $select, string $ready, string $release, int $rows_expected ): void {
	cc87_assert( false !== $root->query( 'START TRANSACTION' ), 'barrier START TRANSACTION' );
	$locked = $root->get_col( $select );
	cc87_assert( is_array( $locked ) && $rows_expected === count( $locked ), 'barrier locked rows: ' . json_encode( $locked ) );
	cc87_assert( false !== file_put_contents( $ready, 'locked' ), 'barrier ready marker' );
	$deadline = microtime( true ) + 90;
	while ( microtime( true ) < $deadline ) {
		if ( file_exists( $release ) ) {
			cc87_assert( false !== $root->query( 'COMMIT' ), 'barrier COMMIT' );
			return;
		}
		usleep( 100000 );
	}
	$root->query( 'ROLLBACK' );
	throw new RuntimeException( 'barrier release marker never appeared; trusted lock rolled back' );
}

if ( 'seed' === $phase ) {
	cc87_seed_bulk( $root, 6 );
	Config::set_logical_budget( $operation, 10 );
	Config::set_enabled( $operation, true );
	cc87_assert( 'READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'concurrency baseline READY' );
	echo "#61 $host: concurrency fixture seeded: 6 enabled rows, L=10, READY\n";
} elseif ( 'lock_a' === $phase ) {
	// Lowest-id row: the first row the Doctor probe LIMIT 1 and the certified
	// full-table UPDATE visit. Held only until both requests queue on it.
	cc61_hold(
		$root,
		"SELECT id FROM `$table` ORDER BY id LIMIT 1 FOR UPDATE",
		(string) getenv( 'CC61_READY' ),
		(string) getenv( 'CC61_RELEASE' ),
		1
	);
	echo "#61 $host: barrier first-row lock released\n";
} elseif ( 'lock_b' === $phase ) {
	// Remaining five rows: the certified UPDATE must park on one of these
	// while both restricted transactions are already inside Guard. The keyset
	// predicate starts after the lowest id so this session never touches the
	// Doctor probe row held by the lock_a barrier.
	$first = (int) $root->get_var( "SELECT id FROM `$table` ORDER BY id LIMIT 1" );
	cc87_assert( $first > 0, 'barrier could not read the lowest target id' );
	cc61_hold(
		$root,
		"SELECT id FROM `$table` WHERE id > $first ORDER BY id FOR UPDATE",
		(string) getenv( 'CC61_READY' ),
		(string) getenv( 'CC61_RELEASE' ),
		5
	);
	echo "#61 $host: barrier remaining-rows lock released\n";
} elseif ( 'barrier' === $phase ) {
	$nowait = $root->query( "SELECT id FROM `$table` ORDER BY id FOR UPDATE NOWAIT" );
	$held = false === $nowait && '' !== (string) $root->last_error;
	$root->last_error = '';
	cc87_assert( $held, 'trusted row-lock barrier is not active' );
	echo "#61 $host: trusted row-lock barrier active (FOR UPDATE NOWAIT refused)\n";
} elseif ( 'run' === $phase ) {
	wp_set_current_user( 1 );
	$response = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
	$body = $response->get_data();
	$outcome = isset( $body['commitcap']['outcome'] ) ? (string) $body['commitcap']['outcome'] : (string) ( $body['code'] ?? 'NO_CERTIFIED_RESULT' );
	echo wp_json_encode( array(
		'status' => $response->get_status(),
		'outcome' => $outcome,
		'consumed' => isset( $body['commitcap']['consumed'] ) ? $body['commitcap']['consumed'] : null,
		'affected_rows' => isset( $body['commitcap']['affected_rows'] ) ? $body['commitcap']['affected_rows'] : null,
		'logical_budget' => isset( $body['commitcap']['logical_budget'] ) ? $body['commitcap']['logical_budget'] : null,
	) ), "\n";
} elseif ( 'await_overlap' === $phase ) {
	$stage = (string) getenv( 'CC61_STAGE' );
	$needle = 'probe' === $stage ? 'LIMIT 1' : "status='disabled'";
	$deadline = microtime( true ) + 40;
	$last = array();
	while ( microtime( true ) < $deadline ) {
		$rows = $root->get_results( "SELECT ID, USER, STATE, INFO FROM information_schema.PROCESSLIST WHERE USER = 'cc87_writer'", ARRAY_A );
		$sessions = array();
		foreach ( (array) $rows as $row ) {
			$info = preg_replace( '/\s+/', ' ', trim( (string) $row['INFO'] ) );
			if ( false !== stripos( $info, 'UPDATE' ) && false !== stripos( $info, $table ) && false !== stripos( $info, $needle ) ) {
				$sessions[ (int) $row['ID'] ] = array(
					'id' => (int) $row['ID'],
					'state' => (string) $row['STATE'],
					'info' => $info,
				);
			}
		}
		$last = $sessions;
		if ( count( $sessions ) >= 2 ) {
			echo wp_json_encode( array( 'stage' => $stage, 'sessions' => array_values( $sessions ) ) ), "\n";
			return;
		}
		usleep( 200000 );
	}
	fwrite( STDERR, '#61 await_overlap(' . $stage . ') timed out; last=' . json_encode( array_values( $last ) ) . "\n" );
	exit( 1 );
} else {
	$counts = cc87_counts( $root );
	cc87_assert( array( 6, 6 ) === $counts, 'final durable state must be six disabled rows: ' . json_encode( $counts ) );
	$stale = (int) $root->get_var( 'SELECT COUNT(*) FROM commitcap_v01_state WHERE connection_id <> 0' );
	cc87_assert( 0 === $stale, 'leftover per-connection accounting rows: ' . $stale );
	cc87_assert( 'READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'concurrency left production READY' );
	echo "#61 $host: two independent certified requests; durable (6,6); zero stale accounting; READY: PASS\n";
}
