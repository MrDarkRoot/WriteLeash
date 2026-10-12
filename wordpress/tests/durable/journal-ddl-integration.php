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
/** Setup success is not proof of its effect: layout assertions below are separate. */
function ddl_setup_sql( string $sql ): void {
	global $wpdb;
	if ( false === $wpdb->query( $sql ) ) { throw new RuntimeException( 'DDL_FIXTURE_SETUP_SQL_FAILED: ' . $sql . ': ' . $wpdb->last_error ); }
}
function ddl_columns( string $table ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
	if ( '' !== $wpdb->last_error || ! is_array( $rows ) || ! $rows ) { throw new RuntimeException( 'DDL_FIXTURE_METADATA_UNAVAILABLE' ); }
	return array_column( $rows, null, 'Field' );
}
function ddl_layout( string $table, int $mask, array $baseline, array $storage ): string {
	global $wpdb;
	$columns = ddl_columns( $table );
	$observed = array();
	foreach ( array( 'plan_json', 'plan_fingerprint', 'price_field' ) as $name ) { $observed[$name] = $columns[$name] ?? null; }
	$physical = $wpdb->get_row( $wpdb->prepare( 'SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ), ARRAY_A );
	echo wp_json_encode( array( 'fixture_mask' => $mask, 'observed_schema' => $observed, 'storage' => $physical ) ) . "\n";
	ddl_eq( $physical, $storage, 'DDL_LAYOUT storage attributes' );
	ddl_eq( isset( $columns['plan_json'] ), true, 'DDL_LAYOUT plan_json presence' );
	foreach ( array( 'plan_json', 'plan_fingerprint', 'price_field' ) as $name ) {
		$missing = ( 'plan_fingerprint' === $name && ( $mask & 2 ) ) || ( 'price_field' === $name && ( $mask & 4 ) );
		ddl_eq( isset( $columns[$name] ), ! $missing, 'DDL_LAYOUT ' . $name . ' presence mask=' . $mask );
		if ( $missing ) { continue; }
		foreach ( array( 'Type', 'Null', 'Collation', 'Default', 'Extra' ) as $attribute ) {
			$expected = 'plan_json' === $name && 'Null' === $attribute ? ( $mask & 1 ? 'NO' : 'YES' ) : $baseline[$name][$attribute];
			ddl_eq( $columns[$name][$attribute], $expected, 'DDL_LAYOUT ' . $name . '.' . $attribute . ' mask=' . $mask );
		}
	}
	return ( $mask & 1 ? 'NO' : 'YES' ) . ':' . ( isset( $columns['plan_fingerprint'] ) ? '1' : '0' ) . ':' . ( isset( $columns['price_field'] ) ? '1' : '0' );
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
$baseline = ddl_columns( $table );
foreach ( array( 'plan_json' => 'longtext', 'plan_fingerprint' => 'char(64)', 'price_field' => 'varchar(16)' ) as $name => $type ) {
	ddl_eq( isset( $baseline[$name] ), true, 'baseline required ' . $name . ' presence' );
	ddl_eq( $baseline[$name]['Type'], $type, 'baseline required ' . $name . ' type' );
	ddl_eq( $baseline[$name]['Null'], 'plan_json' === $name ? 'YES' : 'NO', 'baseline required ' . $name . ' nullability' );
	ddl_eq( 0 === strpos( $baseline[$name]['Collation'], 'utf8mb4_' ), true, 'baseline required UTF-8 ' . $name );
	ddl_eq( $baseline[$name]['Extra'], '', 'baseline required ordinary storage ' . $name );
	ddl_eq( in_array( $baseline[$name]['Default'], 'plan_json' === $name ? array( null ) : array( null, '' ), true ), true, 'baseline required non-authoritative default ' . $name );
}
$storage = $wpdb->get_row( $wpdb->prepare( 'SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ), ARRAY_A );
ddl_eq( $storage['ENGINE'], 'InnoDB', 'baseline transactional storage' );
ddl_eq( 0 === strpos( $storage['TABLE_COLLATION'], 'utf8mb4_' ), true, 'baseline UTF-8 storage' );
foreach ( array( 'bad`; DROP TABLE wp_options; --', 'bad-prefix_', 'bad prefix_', "bad\0_", 'bad.qualified_', 'bad\\_' ) as $invalid ) {
	$wpdb->prefix = $invalid;
	try { ddl_refused( static function () { DDLJournal::install(); } ); }
	finally { $wpdb->prefix = $prefix; }
}
ddl_eq( DDLJobs::read( (int) $job['id'] ), $authority, 'invalid identifiers preserve approval' );
// If this fails, do not enter a finally block which could drop the real table.
ddl_setup_sql( $wpdb->prepare( 'RENAME TABLE %i TO %i', $table, $backup ) );
try {
	$layouts = array();
	// All eight combinations, including interruption after any earlier DDL.
	for ( $mask = 0; $mask < 8; ++$mask ) {
		ddl_setup_sql( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $table, $backup ) );
		$legacy = $row; $legacy['schema_version'] = 2; $legacy['plan_json'] = $plan->json(); $legacy['plan_fingerprint'] = ''; $legacy['price_field'] = '';
		ddl_eq( $wpdb->insert( $table, $legacy ), 1, 'legacy row inserted' );
		if ( $mask & 1 ) { ddl_setup_sql( $wpdb->prepare( 'ALTER TABLE %i MODIFY plan_json longtext NOT NULL', $table ) ); }
		if ( $mask & 2 ) { ddl_setup_sql( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN plan_fingerprint', $table ) ); }
		if ( $mask & 4 ) { ddl_setup_sql( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN price_field', $table ) ); }
		$layouts[] = ddl_layout( $table, $mask, $baseline, $storage );
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
		ddl_setup_sql( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	}
	ddl_eq( count( array_unique( $layouts ) ), 8, 'eight actually distinct partial layouts' );
	// A real SQL failure after two committed DDL steps leaves data unmigrated.
	ddl_setup_sql( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $table, $backup ) );
	ddl_eq( $wpdb->insert( $table, $legacy ), 1, 'failure fixture row inserted' );
	ddl_setup_sql( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN plan_fingerprint, DROP COLUMN price_field, MODIFY plan_json longtext NOT NULL', $table ) );
	ddl_layout( $table, 7, $baseline, $storage );
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
	ddl_setup_sql( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	foreach ( array( 'DROP COLUMN plan_json', 'MODIFY plan_json varchar(255) NULL' ) as $malformation ) {
		ddl_setup_sql( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $table, $backup ) );
		// Fixture-only trusted malformed DDL, never part of the plugin payload.
		ddl_setup_sql( $wpdb->prepare( 'ALTER TABLE %i ', $table ) . $malformation );
		$malformed = ddl_columns( $table );
		echo wp_json_encode( array( 'malformation' => $malformation, 'observed_plan_json' => $malformed['plan_json'] ?? null ) ) . "\n";
		if ( 'DROP COLUMN plan_json' === $malformation ) { ddl_eq( isset( $malformed['plan_json'] ), false, 'malformed plan_json actually absent' ); }
		else { ddl_eq( $malformed['plan_json']['Type'], 'varchar(255)', 'malformed plan_json actually wrong type' ); ddl_eq( $malformed['plan_json']['Null'], 'YES', 'malformed plan_json actually nullable' ); }
		update_option( 'writeleash_price_journal_schema', 2, false );
		ddl_refused( static function () { DDLJournal::install(); } );
		ddl_eq( (int) get_option( 'writeleash_price_journal_schema' ), 2, 'malformed schema not advertised ready' );
		ddl_eq( DDLJobs::read( (int) $job['id'] ), $authority, 'malformed schema preserves approval' );
		ddl_setup_sql( $wpdb->prepare( 'DROP TABLE %i', $table ) );
	}
} finally {
	ddl_setup_sql( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	ddl_setup_sql( $wpdb->prepare( 'RENAME TABLE %i TO %i', $backup, $table ) );
	WriteLeash\Price_Apply_Connection::forget_table_metadata( $wpdb, $table );
	update_option( 'writeleash_price_journal_schema', $old_option, false );
}
echo 'Journal enumerated DDL / invalid identifiers / partial layouts / approval preservation: PASS (' . $ddl_checks . " assertions)\n";
