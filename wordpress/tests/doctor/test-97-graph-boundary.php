<?php
// #97 DB graph boundary: the canonical WriteLeash low-level graph is the only
// trusted identity. Pre-rebrand CommitCap graphs and mixed graphs must never be
// READY and must never be migrated, renamed or auto-dropped by runtime code.

use WriteLeash\Compatibility_Doctor as Doctor;
use WriteLeash\Update_Engine as Engine;

echo "--- Begin Gate #97 DB graph boundary tests ($host) ---\n";

function cc97_assert( $value, $label ) {
	if ( ! $value ) {
		throw new RuntimeException( 'ASSERTION FAILED: ' . $label );
	}
}

function cc97_query( $db, $sql ) {
	$res = $db->query( $sql );
	cc97_assert( false !== $res, 'SQL failed: ' . $sql . ': ' . $db->last_error );
	return $res;
}

function cc97_old_name( string $name ): string {
	return str_replace( 'writeleash_v01_', 'commitcap_v01_', $name );
}

function cc97_count( $db, string $sql ): int {
	return (int) $db->get_var( $sql );
}

// Own restricted connection: included suites in this process may have
// rebound the shared $writer variable to another fixture account.
$cc97_writer = new wpdb( 'cc_writer', 'disposable_writer_password', 'wp_test', $host );
$cc97_writer->suppress_errors( true );
cc97_assert( $cc97_writer->ready, 'canonical restricted writer unavailable' );

$cc97_table = 'cc97_target';
cc97_query( $root, "DROP TABLE IF EXISTS `$cc97_table`" );
cc97_query( $root, "CREATE TABLE `$cc97_table` (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB" );
cc97_query( $root, "INSERT INTO `$cc97_table` (id, touched) VALUES (1, 0), (2, 0)" );

/** Reset the writer to the exact canonical surface this suite declares. */
$cc97_reset_grants = static function () use ( $root, $cc97_table ): void {
	cc97_query( $root, "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'cc_writer'@'%'" );
	foreach ( Engine::routine_names() as $routine ) {
		cc97_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test.`$routine` TO 'cc_writer'@'%'" );
	}
	cc97_query( $root, 'GRANT SELECT ON wp_test.writeleash_v01_state TO \'cc_writer\'@\'%\'' );
	cc97_query( $root, "GRANT SELECT, UPDATE ON wp_test.`$cc97_table` TO 'cc_writer'@'%'" );
};

/** Create an exact pre-rebrand replica of the canonical routines. */
$cc97_create_old_routines = static function () use ( $root ): void {
	foreach ( Engine::routines() as $name => $body ) {
		cc97_query( $root, 'CREATE PROCEDURE `' . cc97_old_name( $name ) . '` ' . Engine::routine_params( $name ) . ' SQL SECURITY DEFINER ' . $body );
	}
};

$cc97_drop_old_objects = static function () use ( $root, $cc97_table ): void {
	$old_trigger = cc97_old_name( Engine::trigger_name( $cc97_table ) );
	cc97_query( $root, "DROP TRIGGER IF EXISTS `$old_trigger`" );
	foreach ( Engine::routine_names() as $routine ) {
		cc97_query( $root, 'DROP PROCEDURE IF EXISTS `' . cc97_old_name( $routine ) . '`' );
	}
	cc97_query( $root, 'DROP TABLE IF EXISTS commitcap_v01_state' );
};

$engine->install_infrastructure();
$engine->install_policy( $cc97_table, 5, 'cc_writer' );
$cc97_reset_grants();
$cc97_baseline = Doctor::run( $cc97_table, 5, $cc97_writer, $root );
cc97_assert( 'PASS' === $cc97_baseline['overall'], 'canonical graph should PASS: ' . json_encode( $cc97_baseline ) );
echo "  #97 canonical WriteLeash graph baseline: PASS\n";

// A. canonical helper + old CommitCap routines (mixed graph).
cc97_query( $root, 'DROP PROCEDURE writeleash_v01_open' );
cc97_query( $root, 'DROP PROCEDURE writeleash_v01_close' );
cc97_query( $root, 'DROP PROCEDURE writeleash_v01_count' );
cc97_query( $root, 'DROP PROCEDURE writeleash_v01_policy' );
cc97_query( $root, 'DROP PROCEDURE writeleash_v01_attest' );
$cc97_create_old_routines();
foreach ( Engine::routine_names() as $routine ) {
	cc97_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test." . cc97_old_name( $routine ) . " TO 'cc_writer'@'%'" );
}
$mixed_a = Doctor::run( $cc97_table, 5, $cc97_writer, $root );
cc97_assert( 'PASS' !== $mixed_a['overall'], 'new helper + old routines became PASS' );
$cc97_drop_old_objects();
$engine->install_infrastructure();
$cc97_reset_grants();
echo "  #97 mixed A (new helper + old routines): NOT_READY PASS\n";

// B. old CommitCap helper + canonical WriteLeash routines (mixed graph).
cc97_query( $root, 'DROP TABLE writeleash_v01_state' );
cc97_query( $root, "CREATE TABLE `commitcap_v01_state` (connection_id BIGINT UNSIGNED NOT NULL, policy_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, consumed BIGINT UNSIGNED NOT NULL, PRIMARY KEY (connection_id, policy_id)) ENGINE=InnoDB COMMENT='CommitCap V0.1 cooperative UPDATE state'" );
$mixed_b = Doctor::run( $cc97_table, 5, $cc97_writer, $root );
cc97_assert( 'PASS' !== $mixed_b['overall'], 'old helper + new routines became PASS' );
$cc97_drop_old_objects();
$engine->install_infrastructure();
$cc97_reset_grants();
echo "  #97 mixed B (old helper + canonical routines): NOT_READY PASS\n";

// C. canonical graph + old CommitCap trigger on the target table.
$engine->remove_owned_policy( $cc97_table, 5, 'cc_writer' );
$cc97_old_trigger = cc97_old_name( Engine::trigger_name( $cc97_table ) );
cc97_query( $root, "CREATE TRIGGER `$cc97_old_trigger` BEFORE UPDATE ON `$cc97_table` FOR EACH ROW " . str_replace( array( 'writeleash_v01_', '@writeleash_v01_' ), array( 'commitcap_v01_', '@commitcap_v01_' ), Engine::trigger_body( $cc97_table, 5, 'cc_writer' ) ) );
$mixed_c = Doctor::run( $cc97_table, 5, $cc97_writer, $root );
cc97_assert( 'PASS' !== $mixed_c['overall'], 'new graph + old trigger became PASS' );
cc97_query( $root, "DROP TRIGGER `$cc97_old_trigger`" );
$engine->install_policy( $cc97_table, 5, 'cc_writer' );
echo "  #97 mixed C (canonical graph + old trigger): NOT_READY PASS\n";

// D. canonical helper name + old CommitCap TABLE_COMMENT.
cc97_query( $root, "ALTER TABLE writeleash_v01_state COMMENT = 'CommitCap V0.1 cooperative UPDATE state'" );
$mixed_d = Doctor::run( $cc97_table, 5, $cc97_writer, $root );
cc97_assert( 'PASS' !== $mixed_d['overall'], 'old helper comment became PASS' );
cc97_query( $root, "ALTER TABLE writeleash_v01_state COMMENT = 'WriteLeash V0.1 cooperative UPDATE state'" );
cc97_assert( 'PASS' === Doctor::run( $cc97_table, 5, $cc97_writer, $root )['overall'], 'canonical comment did not restore PASS' );
echo "  #97 mixed D (canonical helper + old comment): NOT_READY PASS\n";

// E. canonical objects plus old CommitCap routines, with the writer holding
// only the old EXECUTE grants: pre-rebrand grants never satisfy readiness, and
// the adapter suite additionally proves EXECUTE on the old family alone fails.
$cc97_create_old_routines();
foreach ( Engine::routine_names() as $routine ) {
	cc97_query( $root, "REVOKE EXECUTE ON PROCEDURE wp_test.`$routine` FROM 'cc_writer'@'%'" );
	cc97_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test." . cc97_old_name( $routine ) . " TO 'cc_writer'@'%'" );
}
$mixed_e = Doctor::run( $cc97_table, 5, $cc97_writer, $root );
cc97_assert( 'PASS' !== $mixed_e['overall'], 'old-only routine grants became PASS' );
$cc97_drop_old_objects();
$cc97_reset_grants();
echo "  #97 mixed E (canonical objects + old-only routine grants): NOT_READY PASS\n";

// F. canonical graph + stale pre-rebrand rotation marker row.
cc97_query( $root, $root->prepare(
	'INSERT INTO writeleash_v01_state (connection_id, policy_id, consumed) VALUES (0, %s, 1)',
	hash( 'sha256', 'commitcap_v01_rotation:cc_writer@%' )
) );
$mixed_f = Doctor::run( $cc97_table, 5, $cc97_writer, $root );
cc97_assert( 'PASS' !== $mixed_f['overall'], 'stale pre-rebrand rotation marker became PASS' );
cc97_query( $root, 'DELETE FROM writeleash_v01_state WHERE connection_id = 0' );
cc97_assert( 'PASS' === Doctor::run( $cc97_table, 5, $cc97_writer, $root )['overall'], 'marker cleanup did not restore PASS' );
echo "  #97 mixed F (canonical graph + old rotation marker): NOT_READY PASS\n";

// Cleanup: canonical graph remains, suite-owned target is removed.
$engine->remove_owned_policy( $cc97_table, 5, 'cc_writer' );
cc97_query( $root, "REVOKE ALL PRIVILEGES ON wp_test.`$cc97_table` FROM 'cc_writer'@'%'" );
cc97_query( $root, "DROP TABLE `$cc97_table`" );
cc97_query( $root, 'DELETE FROM writeleash_v01_state' );
echo "--- End Gate #97 DB graph boundary tests ($host): ALL PASS ---\n";
