<?php
// Candidate: Relevanssi 4.22.1 - Admin "Build the index" operation
// (Relevanssi > Index > Build index), executed through the plugin's real
// relevanssi_build_index() entry point on a plugin-owned custom table.
require_once __DIR__ . '/helpers.php';

global $wpdb;
for ( $i = 0; $i < 5; ++$i ) {
	wp_insert_post( array(
		'post_title'   => 'CC Research ' . $i,
		'post_content' => 'CommitCap research content ' . $i,
		'post_status'  => 'publish',
		'post_type'    => 'post',
	) );
}
$table = $wpdb->prefix . 'relevanssi';
$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s", $table ) );
echo "SEED: posts=5 index_table=" . ( $exists ? 'present' : 'missing' ) . "\n";
if ( ! $exists ) {
	echo "SEED FAILURE: relevanssi index table missing after activation\n";
	return;
}

ccr_trace_start( 'relevanssi_build_index' );
relevanssi_build_index( false, true );
ccr_trace_stop();
ccr_table_verbs( $GLOBALS['ccr_root'], 'relevanssi' );
$rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' );
echo "RESULT: index_rows=$rows\n";
