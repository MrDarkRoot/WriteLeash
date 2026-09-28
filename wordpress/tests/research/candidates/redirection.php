<?php
// Candidate: Redirection 5.5.2 - Admin "Bulk Actions -> Disable" applied to all
// matching redirects (global=1), through the plugin's real REST route.
// This is the plugin's own workflow: Redirection > Tools/Redirects > select
// filter > Bulk Actions > Disable (or "Disable all matching").
require_once __DIR__ . '/helpers.php';

global $wpdb;
$items_table = $wpdb->prefix . 'redirection_items';

// Redirection registers its activation hook only in admin context, so a
// WP-CLI activation leaves the schema uninstalled. Run the plugin's own schema
// installer explicitly, exactly as the activation path does.
$tables = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wp_redirection_%'" );
if ( 0 === $tables ) {
	Red_Database::get_latest_database()->install();
	Red_Flusher::clear();
	red_set_options();
	$tables = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wp_redirection_%'" );
}
echo "SETUP: redirection_tables=$tables\n";

// Module 1 is the built-in "WordPress" module in Redirection 5.x.
$group = Red_Group::create( 'CC Research', 1 );
if ( ! $group ) {
	echo "SEED FAILURE: group create failed\n";
	return;
}
$group_id = (int) $group->get_id();
for ( $i = 0; $i < 8; ++$i ) {
	$item = Red_Item::create( array(
		'url'         => '/cc-research-' . $i,
		'action_data' => 'https://example.test/target-' . $i,
		'action_type' => 'url',
		'action_code' => 301,
		'match_type'  => 'url',
		'group_id'    => $group_id,
		'status'      => 'enabled',
		'regex'       => 0,
	) );
	if ( is_wp_error( $item ) ) {
		echo 'SEED FAILURE: ' . $item->get_error_message() . "\n";
		return;
	}
}
$total   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $items_table );
$enabled = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $items_table . " WHERE status='enabled'" );
echo "SEED: total=$total enabled=$enabled\n";

wp_set_current_user( 1 );
ccr_trace_start( 'redirection_rest_bulk_disable_global' );
$request = new WP_REST_Request( 'POST', '/redirection/v1/bulk/redirect/disable' );
$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$request->set_body_params( array( 'bulk' => 'disable', 'global' => '1' ) );
$response = rest_do_request( $request );
echo 'REST: status=' . $response->get_status() . "\n";
$disabled_after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $items_table . " WHERE status='disabled'" );
ccr_trace_stop();
ccr_table_verbs( $GLOBALS['ccr_root'], 'redirection_items' );
echo "RESULT: disabled_after=$disabled_after\n";

// Simpler-control comparison: the same Admin action scoped to explicitly
// selected redirect IDs (items[]) instead of the global filter.
$ids = array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . $items_table . ' ORDER BY id LIMIT 2' ) );
ccr_trace_start( 'redirection_rest_bulk_disable_selected_items' );
$request2 = new WP_REST_Request( 'POST', '/redirection/v1/bulk/redirect/enable' );
$request2->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$request2->set_body_params( array( 'bulk' => 'enable', 'items' => $ids ) );
$response2 = rest_do_request( $request2 );
echo 'REST-SCOPED: status=' . $response2->get_status() . ' ids=' . implode( ',', $ids ) . "\n";
ccr_trace_stop();
ccr_table_verbs( $GLOBALS['ccr_root'], 'redirection_items' );
