<?php
// #97 DB graph boundary: the canonical WriteLeash low-level graph is the only
// trusted identity. Pre-rebrand CommitCap graphs and mixed graphs must never be
// READY and must never be migrated, renamed or auto-dropped by runtime code.

use WriteLeash\Compatibility_Doctor as Doctor;
use WriteLeash\Update_Engine as Engine;

require_once __DIR__ . '/../old-identity-fixture.php';

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
		cc97_query( $root, 'CREATE PROCEDURE `' . cc97_old_identity_name( $name ) . '` ' . Engine::routine_params( $name ) . ' SQL SECURITY DEFINER ' . cc97_old_identity_sql( $body ) );
	}
};

$cc97_drop_old_objects = static function () use ( $root, $cc97_table ): void {
	$old_trigger = cc97_old_identity_name( Engine::trigger_name( $cc97_table ) );
	cc97_query( $root, "DROP TRIGGER IF EXISTS `$old_trigger`" );
	foreach ( Engine::routine_names() as $routine ) {
		cc97_query( $root, 'DROP PROCEDURE IF EXISTS `' . cc97_old_identity_name( $routine ) . '`' );
	}
	cc97_query( $root, 'DROP TABLE IF EXISTS commitcap_v01_state' );
};

$engine->install_infrastructure();
$engine->install_policy( $cc97_table, 5, 'cc_writer' );
$cc97_reset_grants();
$cc97_baseline = Doctor::run( $cc97_table, 5, $cc97_writer, $root );
cc97_assert( 'PASS' === $cc97_baseline['overall'], 'canonical graph should PASS: ' . json_encode( $cc97_baseline ) );
echo "  #97 canonical WriteLeash graph baseline: PASS\n";

// 0. Exact pre-rebrand graph self-check. The old CommitCap graph must be a
// functioning enforcement graph under its own names, so a later NOT_READY
// cannot be attributed to malformed fixture SQL. The proof uses only the
// old objects; the WriteLeash runtime is never an authority here.
cc97_query( $root, 'DROP TRIGGER IF EXISTS `' . Engine::trigger_name( $cc97_table ) . '`' );
foreach ( Engine::routine_names() as $routine ) {
	cc97_query( $root, 'DROP PROCEDURE IF EXISTS `' . $routine . '`' );
}
cc97_query( $root, 'DROP TABLE IF EXISTS writeleash_v01_state' );
cc97_query( $root, "CREATE TABLE `commitcap_v01_state` (connection_id BIGINT UNSIGNED NOT NULL, policy_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, consumed BIGINT UNSIGNED NOT NULL, PRIMARY KEY (connection_id, policy_id)) ENGINE=InnoDB COMMENT='CommitCap V0.1 cooperative UPDATE state'" );
foreach ( Engine::routines() as $name => $body ) {
	cc97_query( $root, 'CREATE PROCEDURE `' . cc97_old_identity_name( $name ) . '` ' . Engine::routine_params( $name ) . ' SQL SECURITY DEFINER ' . cc97_old_identity_sql( $body ) );
}
$cc97_old_trigger = cc97_old_identity_name( Engine::trigger_name( $cc97_table ) );
cc97_query( $root, "CREATE TRIGGER `$cc97_old_trigger` BEFORE UPDATE ON `$cc97_table` FOR EACH ROW " . cc97_old_identity_sql( Engine::trigger_body( $cc97_table, 5, 'cc_writer' ) ) );
// Old-only grants: EXECUTE on the old routines, SELECT on the old helper,
// and the reviewed target surface. No canonical grant is left active.
cc97_query( $root, "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'cc_writer'@'%'" );
foreach ( Engine::routine_names() as $routine ) {
	cc97_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test." . cc97_old_identity_name( $routine ) . " TO 'cc_writer'@'%'" );
}
cc97_query( $root, "GRANT SELECT ON wp_test.commitcap_v01_state TO 'cc_writer'@'%'" );
cc97_query( $root, "GRANT SELECT, UPDATE ON wp_test.`$cc97_table` TO 'cc_writer'@'%'" );

// #97 regression assertions: old fixture bodies are the exact old identity.
$cc97_old_body_expectations = array(
	'writeleash_v01_open'   => array( 'commitcap_v01_state' ),
	'writeleash_v01_close'  => array( 'commitcap_v01_state', '@commitcap_v01_denied' ),
	'writeleash_v01_count'  => array( 'commitcap_v01_state' ),
	'writeleash_v01_policy' => array( 'commitcap_v01_state' ),
	'writeleash_v01_attest' => array( 'commitcap_v01_open' ),
);
foreach ( $cc97_old_body_expectations as $routine => $expected ) {
	$old_def = (string) $root->get_var( $root->prepare(
		"SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = %s",
		cc97_old_identity_name( $routine )
	) );
	cc97_assert( '' !== $old_def, 'old routine fixture exists: ' . $routine );
	foreach ( $expected as $token ) {
		cc97_assert( false !== strpos( $old_def, $token ), 'old routine body uses the old identity: ' . $routine . ' missing ' . $token );
	}
	cc97_assert( false === strpos( $old_def, 'writeleash_v01_' ), 'old routine body leaked the canonical identity: ' . $routine );
}
$cc97_old_trigger_body = (string) $root->get_var( $root->prepare(
	"SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s",
	$cc97_old_trigger
) );
cc97_assert( false !== strpos( $cc97_old_trigger_body, '@commitcap_v01_denied' ) && false !== strpos( $cc97_old_trigger_body, 'commitcap_v01_state' ), 'old trigger body uses the old identity' );
cc97_assert( false === strpos( $cc97_old_trigger_body, 'writeleash_v01_' ), 'old trigger body leaked the canonical identity' );

// Self-check: old open/count/close accounting and old trigger enforcement.
$cc97_policy = Engine::policy_id( $cc97_table );
$cc97_writer->query( "CALL commitcap_v01_open('$cc97_policy')" );
$cc97_writer->query( "UPDATE `$cc97_table` SET touched = touched + 1 WHERE id = 1" );
$cc97_writer->query( "CALL commitcap_v01_count('$cc97_policy', @cc97_consumed)" );
cc97_assert( 1 === (int) $cc97_writer->get_var( 'SELECT @cc97_consumed' ), 'old open/count/close accounting' );
$cc97_writer->query( "CALL commitcap_v01_close('$cc97_policy')" );
// Old trigger denial: ceiling 1, the second UPDATE must be denied by the old graph.
cc97_query( $root, "DROP TRIGGER `$cc97_old_trigger`" );
cc97_query( $root, "CREATE TRIGGER `$cc97_old_trigger` BEFORE UPDATE ON `$cc97_table` FOR EACH ROW " . cc97_old_identity_sql( Engine::trigger_body( $cc97_table, 1, 'cc_writer' ) ) );
$cc97_writer->query( "CALL commitcap_v01_open('$cc97_policy')" );
$cc97_writer->query( "UPDATE `$cc97_table` SET touched = touched + 1 WHERE id = 1" );
$cc97_second = $cc97_writer->query( "UPDATE `$cc97_table` SET touched = touched + 1 WHERE id = 2" );
$cc97_denied = false === $cc97_second && false !== strpos( (string) $cc97_writer->last_error, 'CC54_DENIED' );
cc97_assert( $cc97_denied, 'old trigger denied the over-budget UPDATE: ' . (string) $cc97_writer->last_error );
$cc97_writer->query( 'SET @commitcap_v01_denied = 0' );
$cc97_writer->query( "CALL commitcap_v01_close('$cc97_policy')" );

// WriteLeash must refuse the old-only graph.
$cc97_old_only = Doctor::run( $cc97_table, 5, $cc97_writer, $root );
cc97_assert( 'PASS' !== $cc97_old_only['overall'], 'old-only graph became PASS' );
echo "  #97 exact old graph self-check: PASS\n";

// Restore the canonical graph before the mixed matrix.
cc97_query( $root, "DROP TRIGGER `$cc97_old_trigger`" );
foreach ( Engine::routine_names() as $routine ) {
	cc97_query( $root, 'DROP PROCEDURE IF EXISTS `' . cc97_old_identity_name( $routine ) . '`' );
}
cc97_query( $root, 'DROP TABLE commitcap_v01_state' );
$engine->install_infrastructure();
$engine->install_policy( $cc97_table, 5, 'cc_writer' );
$cc97_reset_grants();

// A. canonical helper + old CommitCap routines (mixed graph).
cc97_query( $root, 'DROP PROCEDURE writeleash_v01_open' );
cc97_query( $root, 'DROP PROCEDURE writeleash_v01_close' );
cc97_query( $root, 'DROP PROCEDURE writeleash_v01_count' );
cc97_query( $root, 'DROP PROCEDURE writeleash_v01_policy' );
cc97_query( $root, 'DROP PROCEDURE writeleash_v01_attest' );
$cc97_create_old_routines();
foreach ( Engine::routine_names() as $routine ) {
	cc97_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test." . cc97_old_identity_name( $routine ) . " TO 'cc_writer'@'%'" );
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
$cc97_old_trigger = cc97_old_identity_name( Engine::trigger_name( $cc97_table ) );
cc97_query( $root, "CREATE TRIGGER `$cc97_old_trigger` BEFORE UPDATE ON `$cc97_table` FOR EACH ROW " . cc97_old_identity_sql( Engine::trigger_body( $cc97_table, 5, 'cc_writer' ) ) );
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
	cc97_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test." . cc97_old_identity_name( $routine ) . " TO 'cc_writer'@'%'" );
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
