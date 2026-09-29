<?php
// #61 threat-model boundary: with WriteLeash deactivated, its REST interception
// is absent and the Redirection global Disable is stock/unprotected. This proves
// the documented consequence; it does not claim protection while inactive.
require_once __DIR__ . '/helpers.php';

$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), '#61 deactivation pinned host' );
cc87_assert( ! defined( 'WRITELEASH_VERSION' ) && ! class_exists( 'WriteLeash\\Plugin' ), 'WriteLeash code still loaded while deactivated' );
cc87_assert( defined( 'REDIRECTION_VERSION' ) && '5.5.2' === REDIRECTION_VERSION, 'Redirection must be active for this boundary test' );
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
wp_set_current_user( 1 );
cc87_seed_bulk( $root, 2 );
list( $response, $threads ) = cc87_trace_all( $root, static function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 200 === $response->get_status() && ! isset( $body['writeleash'] ), 'stock response shape: ' . json_encode( $body ) );
list( $updates, $update_threads ) = cc87_disable_updates( $threads );
cc87_assert( $updates >= 1, 'stock global Disable must still run Redirection\'s own UPDATE' );
cc87_assert( array( 2, 2 ) === cc87_counts( $root ), 'stock global Disable durability' );
foreach ( $threads as $statements ) {
	foreach ( $statements as $sql ) {
		cc87_assert( false === stripos( $sql, 'commitcap_v01_' ), 'managed runtime helper SQL ran while deactivated: ' . $sql );
	}
}
echo "#61 $host: WriteLeash deactivated -> certified global Disable is stock and unprotected (documented boundary): PASS\n";
