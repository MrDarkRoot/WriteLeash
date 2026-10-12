<?php
// Intentionally exits nonzero after proving a targeted fixture failure.
// Run only in disposable sites: WL240_DDL_CONTROL=noop|missing|nullability|rename|type.
global $wpdb, $ddl_control_hits240;
$control240 = getenv( 'WL240_DDL_CONTROL' );
if ( ! in_array( $control240, array( 'noop', 'missing', 'nullability', 'rename', 'type' ), true ) ) { throw new RuntimeException( 'Explicit DDL negative control required' ); }
$table240 = WriteLeash\Price_Apply_Journal::table( $wpdb ); $backup240 = $table240 . '_ddl_backup';
$ddl_control_hits240 = 0; $armed240 = false; $inserted240 = false; $before240 = null;
$snapshot240 = static function () use ( $wpdb, $table240 ): array { return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table240 ), ARRAY_A ); };
$inject240 = static function ( $sql ) use ( $wpdb, $control240, $table240, $backup240, &$armed240, &$inserted240, &$before240, $snapshot240 ) {
	global $ddl_control_hits240;
	if ( str_starts_with( $sql, 'RENAME TABLE ' ) && str_contains( $sql, 'TO `' . $backup240 . '`' ) ) {
		$before240 = $snapshot240();
		if ( 'rename' === $control240 ) { ++$ddl_control_hits240; return $wpdb->prepare( 'RENAME TABLE %i TO %i', $table240 . '_missing_control_source', $backup240 ); }
	}
	if ( str_starts_with( $sql, 'CREATE TABLE ' ) && str_contains( $sql, 'LIKE `' . $backup240 . '`' ) ) { $armed240 = true; $inserted240 = false; }
	if ( $armed240 && str_starts_with( $sql, 'INSERT INTO `' . $table240 . '`' ) ) { $inserted240 = true; }
	if ( $armed240 && 'noop' === $control240 && preg_match( '/^ALTER TABLE `[^`]+` (?:MODIFY plan_json longtext NOT NULL|DROP COLUMN plan_fingerprint|DROP COLUMN price_field)$/', $sql ) ) { ++$ddl_control_hits240; return 'SELECT 1'; }
	if ( $armed240 && $inserted240 && 0 === $ddl_control_hits240 && str_starts_with( $sql, 'SHOW FULL COLUMNS FROM `' . $table240 . '`' ) && in_array( $control240, array( 'missing', 'nullability', 'type' ), true ) ) {
		++$ddl_control_hits240;
		$ddl = 'missing' === $control240 ? 'DROP COLUMN price_field' : ( 'nullability' === $control240 ? 'MODIFY plan_json longtext NOT NULL' : 'MODIFY plan_fingerprint varchar(64) NOT NULL' );
		if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ', $table240 ) . $ddl ) ) { throw new RuntimeException( 'Negative-control injection SQL failed' ); }
	}
	return $sql;
};
$expected240 = array( 'noop' => 'DDL_LAYOUT plan_json.Null mask=1', 'missing' => 'DDL_LAYOUT price_field presence mask=0', 'nullability' => 'DDL_LAYOUT plan_json.Null mask=0', 'rename' => 'DDL_FIXTURE_SETUP_SQL_FAILED: RENAME TABLE', 'type' => 'DDL_LAYOUT plan_fingerprint.Type mask=0' );
$previous240 = $wpdb->suppress_errors(); add_filter( 'query', $inject240 );
$caught240 = null;
try { require __DIR__ . '/journal-ddl-integration.php'; }
catch ( RuntimeException $error240 ) { $caught240 = $error240; }
finally { remove_filter( 'query', $inject240 ); $wpdb->suppress_errors( $previous240 ); }
if ( ! $caught240 || ! str_contains( $caught240->getMessage(), $expected240[$control240] ) || 1 !== $ddl_control_hits240 ) { throw new RuntimeException( 'NEGATIVE_CONTROL_NOT_PROVEN ' . $control240 . ': ' . ( $caught240 ? $caught240->getMessage() : 'fixture unexpectedly passed' ) ); }
$after240 = $snapshot240();
if ( null === $before240 || $before240 !== $after240 ) { throw new RuntimeException( 'NEGATIVE_CONTROL_JOURNAL_NOT_PRESERVED ' . $control240 ); }
$backup_exists240 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $backup240 ) );
if ( 0 !== $backup_exists240 ) { throw new RuntimeException( 'NEGATIVE_CONTROL_BACKUP_LEAK ' . $control240 ); }
echo wp_json_encode( array( 'negative_control' => $control240, 'expected_fixture_failure' => $expected240[$control240], 'actual_failure' => $caught240->getMessage(), 'injections_observed' => $ddl_control_hits240, 'journal_preserved' => true, 'before_rows_sha256' => hash( 'sha256', wp_json_encode( $before240 ) ), 'after_rows_sha256' => hash( 'sha256', wp_json_encode( $after240 ) ), 'backup_absent' => true ) ) . "\n";
// The harness must demand exit 1 AND the proof above, not accept arbitrary failure.
WP_CLI::error( $caught240->getMessage() );
