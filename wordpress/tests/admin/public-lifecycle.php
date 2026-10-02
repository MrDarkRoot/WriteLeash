<?php
// #120 manifest-only lifecycle smoke after the complete #111 Admin/Undo journey.
require_once __DIR__ . '/public-boundary.php';
global $wpdb;
$fingerprint = static function ( string $table ): string {
	$wpdb = $GLOBALS['wpdb'];
	$rows = $wpdb->get_results( "SELECT * FROM `$table`", ARRAY_A );
	if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Evidence snapshot failed: ' . $table ); }
	$encoded = array_map( 'wp_json_encode', $rows );
	sort( $encoded, SORT_STRING );
	return hash( 'sha256', implode( "\n", $encoded ) );
};
$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}writeleash%'" );
wl120_assert( count( $tables ) >= 5, 'durable journal/job/Undo evidence present after Admin journey' );
$before = array();
foreach ( $tables as $table ) { $before[ $table ] = $fingerprint( $table ); }
$prices = $fingerprint( $wpdb->postmeta );
deactivate_plugins( 'writeleash/writeleash.php' );
wl120_assert( 'inactive' === get_option( 'writeleash_runner_state' ), 'deactivate shuts runner down' );
$result = activate_plugin( 'writeleash/writeleash.php', '', false );
wl120_assert( ! is_wp_error( $result ) && 'active' === get_option( 'writeleash_runner_state' ), 'reactivate succeeds' );
foreach ( $before as $table => $hash ) { wl120_assert( $hash === $fingerprint( $table ), 'reactivate retains evidence: ' . $table ); }
wl120_assert( $prices === $fingerprint( $wpdb->postmeta ), 'deactivate/reactivate changes no prices' );
echo "#120 public deactivate/reactivate: PASS; durable evidence and prices unchanged\n";
$owned = array();
foreach ( array( 'writeleash-jobs', 'writeleash-undo', 'writeleash-maintenance' ) as $group ) {
	$owned[] = as_enqueue_async_action( 'wl120_uninstall_probe', array(), $group );
}
$sentinel = as_enqueue_async_action( 'wl120_uninstall_sentinel', array(), 'wl120-unrelated' );
$sql = array();
$capture = static function ( $query ) use ( &$sql ) { $sql[] = $query; return $query; };
add_filter( 'query', $capture );
wl120_assert( true === uninstall_plugin( 'writeleash/writeleash.php' ), 'real WordPress uninstall executes' );
remove_filter( 'query', $capture );
$writes = array_values( array_filter( $sql, static fn( $query ) => 1 === preg_match( '/^\s*(?:DELETE|UPDATE|INSERT|REPLACE|DROP|ALTER|CREATE|GRANT|REVOKE|TRUNCATE)\b/i', $query ) ) );
wl120_assert( isset( $writes[0] ) && false !== strpos( $writes[0], 'writeleash_runner_state' ) && 0 === stripos( $writes[0], 'DELETE' ), 'runner shutdown is FIRST write' );
foreach ( $sql as $query ) {
	wl120_assert( ! preg_match( '/^\s*(?:DROP|ALTER|CREATE|GRANT|REVOKE|TRUNCATE)\b/i', $query ), 'uninstall executes no DDL/privilege cleanup' );
	if ( preg_match( '/^\s*(?:DELETE|UPDATE|INSERT|REPLACE)\b/i', $query ) ) {
		wl120_assert( ! preg_match( '/\b' . preg_quote( $wpdb->prefix, '/' ) . '(?:posts|postmeta|wc_product_meta_lookup|redirection_[a-z_]+)\b/i', $query ), 'uninstall does not mutate Woo/Redirection' );
	}
}
wl120_assert( false === get_option( 'writeleash_runner_state' ), 'uninstall removes runner authority' );
foreach ( $before as $table => $hash ) { wl120_assert( $hash === $fingerprint( $table ), 'uninstall retains evidence: ' . $table ); }
wl120_assert( $prices === $fingerprint( $wpdb->postmeta ), 'uninstall changes no prices' );
$store = ActionScheduler::store();
foreach ( $owned as $id ) { wl120_assert( 'canceled' === $store->get_status( $id ), 'uninstall cancels only owned wake-ups' ); }
wl120_assert( 'pending' === $store->get_status( $sentinel ), 'unrelated wake-up preserved' );
echo "#120 public uninstall safety: PASS; runner-first, no DDL/privilege/product writes, owned-only cancellation, durable evidence retained\n";
