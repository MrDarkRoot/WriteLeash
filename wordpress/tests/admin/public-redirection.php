<?php
// #120 adversarial stock Redirection fixture. Ordinary WP account only.
// Same request with public WriteLeash inactive/active; normal stock callback
// and independent observer must agree. No legacy bootstrap/helpers required.
if ( '1' === getenv( 'WL120_STOCK_ONLY' ) ) {
	function wl120_assert( bool $condition, string $label ): void {
		if ( ! $condition ) { throw new RuntimeException( '#120 stock baseline: ' . $label ); }
	}
	wl120_assert( ! defined( 'WRITELEASH_VERSION' ), 'WriteLeash absent in baseline process' );
} else {
	require_once __DIR__ . '/public-boundary.php';
}
wl120_assert( '5.5.2' === REDIRECTION_VERSION, 'pinned stock Redirection' );
wp_set_current_user( 1 );
global $wpdb;
$table = $wpdb->prefix . 'redirection_items';
wl120_assert( false !== $wpdb->query( "DELETE FROM `$table`" ), 'disposable redirects cleared' );
$group = Red_Group::create( 'WL120 stock fixture', 1 );
wl120_assert( $group instanceof Red_Group, 'stock group created' );
for ( $i = 0; $i < 6; ++$i ) {
	$item = Red_Item::create( array( 'url' => '/wl120-' . $i, 'action_data' => 'https://example.test/wl120-' . $i,
		'action_type' => 'url', 'action_code' => 301, 'match_type' => 'url',
		'group_id' => $group->get_id(), 'status' => 'enabled', 'regex' => 0 ) );
	wl120_assert( ! is_wp_error( $item ), 'stock redirect created' );
}
$updates = array();
add_filter( 'query', static function ( $sql ) use ( &$updates, $table ) {
	if ( preg_match( '/^UPDATE\s+`?' . preg_quote( $table, '/' ) . '`?\s+SET\s+status=\x27disabled\x27/i', $sql ) ) { $updates[] = $sql; }
	wl120_assert( false === stripos( $sql, 'writeleash_v01_' ), 'no privileged legacy SQL' );
	return $sql;
} );
$request = new WP_REST_Request( 'POST', '/redirection/v1/bulk/redirect/disable' );
$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
// Stock Redirection checks isset(items) before its global branch, even for
// an empty array. Match its actual select-all request: OMIT items entirely.
$request->set_body_params( array( 'global' => true, 'filterBy' => array(), 'bulk' => 'disable' ) );
$response = rest_do_request( $request );
wl120_assert( 200 === $response->get_status(), 'stock bulk Disable returns 200' );
wl120_assert( ! isset( $response->get_data()['writeleash'] ), 'stock response has no certified envelope' );
wl120_assert( 1 === count( $updates ), 'stock Redirection global UPDATE executes exactly once' );
$observer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
wl120_assert( 6 === (int) $observer->get_var( "SELECT COUNT(*) FROM `$table` WHERE status='disabled'" ), 'fresh ordinary observer sees all six disabled' );
// Forged historical enable/budget options do not create runtime authority.
update_option( 'writeleash_certified_operation_state', array( 'enabled' => true, 'logical_budget' => 0, 'operation_id' => 'redirection-5.5.2-bulk-disable-global' ), false );
echo '#120 ' . ( '1' === getenv( 'WL120_STOCK_ONLY' ) ? 'inactive baseline' : 'public WriteLeash active' ) . ' + Redirection: stock callback UPDATE once, HTTP 200, fresh observer 6/6 disabled, no certified envelope/interception: UNCHANGED PASS' . "\n";
