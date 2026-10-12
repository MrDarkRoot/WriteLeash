<?php
// Run with wp eval-file against an installed ZIP on a disposable real-DB site.
use WriteLeash\Price_Apply_Journal as DDLJournal;
use WriteLeash\Job_Repository as DDLJobs;
global $wpdb, $ddl_checks;
$ddl_checks = 0;
function ddl_eq( $actual, $expected, string $label ): void {
	global $ddl_checks;
	++$ddl_checks;
	if ( is_array( $actual ) && is_array( $expected ) ) { ksort( $actual ); ksort( $expected ); }
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . wp_json_encode( array( $actual, $expected ) ) ); }
}
function ddl_refused( callable $call ): void {
	try { $call(); } catch ( WriteLeash\Price_Apply_Error $e ) { ddl_eq( $e->getMessage(), 'TRANSACTION_UNAVAILABLE', 'unsafe schema refused' ); return; }
	throw new RuntimeException( 'Unsafe schema accepted' );
}
wp_set_current_user( 1 );
WriteLeash\Job_Schema::install(); DDLJournal::install();
$product = new WC_Product_Simple(); $product->set_name( 'Journal DDL fixture' ); $product->set_status( 'publish' ); $product->set_regular_price( '100' ); $product->save();
$plan = WriteLeash\Woo_Price_Planner::preview( WriteLeash\Price_Selection_Spec::ids( array( $product->get_id() ) ), new WriteLeash\Price_Operation( 'DECREASE_PERCENT', '20' ), new WriteLeash\Safety_Policy( 10, '100', '100', true, '100' ) );
$job = DDLJobs::create_from_plan( $plan, 1 ); DDLJobs::approve( (int) $job['id'], 1 );
DDLJournal::seed( $plan );
$authority = DDLJobs::read( (int) $job['id'] );
$row = DDLJournal::read( $wpdb, $plan->data()['plan_id'], $product->get_id() );
$table = DDLJournal::table( $wpdb ); $backup = $table . '_ddl_backup';
$old_option = get_option( 'writeleash_price_journal_schema' );
$prefix = $wpdb->prefix;
foreach ( array( 'bad`; DROP TABLE wp_options; --', 'bad-prefix_', 'bad prefix_', "bad\0_", 'bad.qualified_', 'bad\\_' ) as $invalid ) {
	$wpdb->prefix = $invalid;
	try { ddl_refused( static function () { DDLJournal::install(); } ); }
	finally { $wpdb->prefix = $prefix; }
}
ddl_eq( DDLJobs::read( (int) $job['id'] ), $authority, 'invalid identifiers preserve approval' );
$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $table, $backup ) );
try {
	// All eight combinations, including interruption after any earlier DDL.
	for ( $mask = 0; $mask < 8; ++$mask ) {
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $table, $backup ) );
		$legacy = $row; $legacy['schema_version'] = 2; $legacy['plan_json'] = $plan->json(); $legacy['plan_fingerprint'] = ''; $legacy['price_field'] = '';
		ddl_eq( $wpdb->insert( $table, $legacy ), 1, 'legacy row inserted' );
		if ( $mask & 1 ) { $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY plan_json longtext NOT NULL', $table ) ); }
		if ( $mask & 2 ) { $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN plan_fingerprint', $table ) ); }
		if ( $mask & 4 ) { $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN price_field', $table ) ); }
		update_option( 'writeleash_price_journal_schema', 2, false );
		DDLJournal::install(); DDLJournal::assert_schema( $wpdb, false );
		$after = DDLJournal::read( $wpdb, $plan->data()['plan_id'], $product->get_id() );
		ddl_eq( $after, $row, 'partial layout ' . $mask . ' preserves exact journal material' );
		ddl_eq( DDLJobs::read( (int) $job['id'] ), $authority, 'partial migration preserves approval' );
		// dbDelta's existing integer display-width normalization is separate.
		$ddl = array(); $watch = static function ( $q ) use ( &$ddl ) { if ( preg_match( '/^\s*ALTER\b.*\b(MODIFY|ADD)\b/i', $q ) ) { $ddl[] = $q; } return $q; };
		add_filter( 'query', $watch );
		try { DDLJournal::install(); } finally { remove_filter( 'query', $watch ); }
		ddl_eq( $ddl, array(), 'ready install performs no explicit migration DDL' );
		ddl_eq( DDLJournal::read( $wpdb, $plan->data()['plan_id'], $product->get_id() ), $after, 'retry preserves journal bytes' );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	}
	// A real SQL failure after two committed DDL steps leaves data unmigrated.
	$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $table, $backup ) );
	$wpdb->insert( $table, $legacy );
	$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN plan_fingerprint, DROP COLUMN price_field, MODIFY plan_json longtext NOT NULL', $table ) );
	update_option( 'writeleash_price_journal_schema', 2, false );
	$fail = static function ( $q ) { return str_contains( $q, 'ADD price_field' ) ? 'ALTER TABLE `writeleash_fixture_missing_table` ADD price_field varchar(16)' : $q; };
	$previous_errors = $wpdb->suppress_errors(); add_filter( 'query', $fail );
	try { ddl_refused( static function () { DDLJournal::install(); } ); }
	finally { remove_filter( 'query', $fail ); $wpdb->suppress_errors( $previous_errors ); }
	$failed = DDLJournal::read( $wpdb, $plan->data()['plan_id'], $product->get_id() );
	ddl_eq( $failed['schema_version'], '2', 'failed DDL never compacts data' );
	ddl_eq( $failed['plan_json'], $plan->json(), 'failed DDL preserves only legacy plan material' );
	ddl_eq( isset( $failed['plan_fingerprint'] ), true, 'earlier DDL committed' );
	ddl_eq( isset( $failed['price_field'] ), false, 'later DDL failed' );
	ddl_eq( (int) get_option( 'writeleash_price_journal_schema' ), 2, 'failed DDL not marked ready' );
	ddl_eq( DDLJobs::read( (int) $job['id'] ), $authority, 'failed DDL preserves approval' );
	DDLJournal::install();
	ddl_eq( DDLJournal::read( $wpdb, $plan->data()['plan_id'], $product->get_id() ), $row, 'partial DDL retry safely compacts exact material' );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	foreach ( array( 'DROP COLUMN plan_json', 'MODIFY plan_json varchar(255) NULL' ) as $malformation ) {
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $table, $backup ) );
		// Fixture-only trusted malformed DDL, never part of the plugin payload.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ', $table ) . $malformation );
		update_option( 'writeleash_price_journal_schema', 2, false );
		ddl_refused( static function () { DDLJournal::install(); } );
		ddl_eq( (int) get_option( 'writeleash_price_journal_schema' ), 2, 'malformed schema not advertised ready' );
		ddl_eq( DDLJobs::read( (int) $job['id'] ), $authority, 'malformed schema preserves approval' );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	}
} finally {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $backup, $table ) );
	WriteLeash\Price_Apply_Connection::forget_table_metadata( $wpdb, $table );
	update_option( 'writeleash_price_journal_schema', $old_option, false );
}
echo 'Journal enumerated DDL / invalid identifiers / partial layouts / approval preservation: PASS (' . $ddl_checks . " assertions)\n";
