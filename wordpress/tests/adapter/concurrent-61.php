<?php
// #61 bounded concurrency fixture. Two independent OS processes run one real
// certified request each; accounting is per Guard-owned transaction.
require_once WP_PLUGIN_DIR . '/commitcap-for-wordpress/commitcap-for-wordpress.php';
require_once __DIR__ . '/helpers.php';

use CommitCap\Certified_Operation as Operation;
use CommitCap\Certified_Operation_Status as Status;
use CommitCap\Operation_Config as Config;

$host = getenv( 'CC_ENGINE_HOST' );
$phase = getenv( 'CC61_PHASE' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), '#61 concurrency host' );
cc87_assert( in_array( $phase, array( 'seed', 'run', 'verify' ), true ), '#61 concurrency phase' );
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$runtime = new wpdb( 'cc87_writer', 'cc87_secret', 'wp_test', $host );
$runtime->suppress_errors( true );
$runtime->set_prefix( (string) $GLOBALS['wpdb']->prefix );
$operation = Operation::redirection_5_5_2_bulk_disable();

if ( 'seed' === $phase ) {
	cc87_seed_bulk( $root, 6 );
	Config::set_logical_budget( $operation, 10 );
	Config::set_enabled( $operation, true );
	cc87_assert( 'READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'concurrency baseline READY' );
	echo "#61 $host: concurrency fixture seeded: 6 enabled rows, L=10, READY\n";
} elseif ( 'run' === $phase ) {
	wp_set_current_user( 1 );
	$response = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
	$body = $response->get_data();
	$outcome = isset( $body['commitcap']['outcome'] ) ? (string) $body['commitcap']['outcome'] : (string) ( $body['code'] ?? 'NO_CERTIFIED_RESULT' );
	echo wp_json_encode( array(
		'status' => $response->get_status(),
		'outcome' => $outcome,
		'consumed' => isset( $body['commitcap']['consumed'] ) ? $body['commitcap']['consumed'] : null,
		'logical_budget' => isset( $body['commitcap']['logical_budget'] ) ? $body['commitcap']['logical_budget'] : null,
	) ), "\n";
} else {
	$counts = cc87_counts( $root );
	cc87_assert( array( 6, 6 ) === $counts, 'final durable state must be six disabled rows: ' . json_encode( $counts ) );
	$stale = (int) $root->get_var( 'SELECT COUNT(*) FROM commitcap_v01_state WHERE connection_id <> 0' );
	cc87_assert( 0 === $stale, 'leftover per-connection accounting rows: ' . $stale );
	cc87_assert( 'READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'concurrency left production READY' );
	echo "#61 $host: two independent certified requests; durable (6,6); zero stale accounting; READY: PASS\n";
}
