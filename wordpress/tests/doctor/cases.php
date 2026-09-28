<?php
// #57: real pinned engines and split grants. No mock database substitutes for
// object verification, effective privileges or Guard transaction behavior.
require_once WP_PLUGIN_DIR . '/commitcap-for-wordpress/commitcap-for-wordpress.php';

use CommitCap\Compatibility_Doctor as Doctor;
use CommitCap\Compatibility_Grants as Grants;
use CommitCap\Guard;
use CommitCap\Update_Engine as Engine;

function cc57_assert( $value, $label ) {
	if ( ! $value ) {
		throw new RuntimeException( $label );
	}
}
function cc57_check( $result, $id, $status ) {
	foreach ( $result['checks'] as $check ) {
		if ( $id === $check['id'] ) {
			cc57_assert( $status === $check['status'], "$id: expected $status, got {$check['status']}: {$check['detail']}" );
			return $check;
		}
	}
	throw new RuntimeException( 'Missing check: ' . $id );
}
function cc57_query( $db, $sql ) {
	cc57_assert( false !== $db->query( $sql ), 'SQL failed: ' . $sql . ': ' . $db->last_error );
}

$host = getenv( 'CC_ENGINE_HOST' );
cc57_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), 'unknown fixture' );
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$writer = new wpdb( 'cc_writer', 'disposable_writer_password', 'wp_test', $host );
$root->suppress_errors( true );
$writer->suppress_errors( true );
$engine = new Engine( $root );
// The #54/#56 suites run first in the same container and leave grants behind.
// Reset the writer to the exact surface this suite declares and proves.
cc57_query( $root, "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'cc_writer'@'%'" );
$engine->install_infrastructure();
foreach ( array( 'open', 'close', 'count', 'policy', 'attest' ) as $routine ) {
	cc57_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test.commitcap_v01_$routine TO 'cc_writer'@'%'" );
}
cc57_query( $root, "GRANT SELECT ON wp_test.commitcap_v01_state TO 'cc_writer'@'%'" );
cc57_query( $root, 'CREATE TABLE cc57_target (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
cc57_query( $root, "GRANT SELECT, UPDATE ON wp_test.cc57_target TO 'cc_writer'@'%'" );
$engine->install_policy( 'cc57_target', 5 );
$version = $root->get_var( 'SELECT VERSION()' );
cc57_assert( 0 === strpos( $version, 'mysql' === $host ? '8.0.44' : '10.11.15' ), 'fixture version drift' );
$result = Doctor::run( 'cc57_target', 5, $writer, $root );
cc57_assert( 'PASS' === $result['overall'], 'pinned restricted environment should PASS: ' . json_encode( $result ) );
foreach ( array( 'wpdb', 'database', 'transaction', 'db_user', 'runtime_grants', 'runtime_trigger_surface', 'installer_grants', 'objects', 'target_table', 'target_access' ) as $id ) {
	cc57_check( $result, $id, 'PASS' );
}
cc57_assert( false !== strpos( cc57_check( $result, 'database', 'PASS' )['detail'], 'TESTED exact fixture' ), 'pinned exact label' );
cc57_assert( false !== strpos( cc57_check( $result, 'db_user', 'PASS' )['detail'], 'cc_writer@%' ), 'effective matched DB user' );
echo "#57 $host $version: restricted fixture PASS\n";

// #57 Blocker: `wordpress` is a REQUIRED compatibility check, so PASS means
// the exact integrated fixture, not merely that a version string exists.
$wp_pass = cc57_check( $result, 'wordpress', 'PASS' );
cc57_assert( false !== strpos( $wp_pass['detail'], 'TESTED exact fixture' ), 'exact WordPress fixture label' );
cc57_assert( 'PASS' === Doctor::wordpress_status( '6.8.3' )[0], 'exact WordPress fixture is not PASS' );
cc57_assert( 'UNKNOWN' === Doctor::wordpress_status( '6.9.1' )[0] && 'UNKNOWN' === Doctor::wordpress_status( '6.8.2' )[0], 'untested WordPress version is not UNKNOWN' );
cc57_assert( 'UNKNOWN' === Doctor::wordpress_status( '' )[0], 'unavailable WordPress version is not UNKNOWN' );
$saved_wp = $GLOBALS['wp_version'];
$GLOBALS['wp_version'] = '6.9.1';
$untested = Doctor::run( 'cc57_target', 5, $writer, $root );
cc57_check( $untested, 'wordpress', 'UNKNOWN' );
cc57_assert( 'PASS' !== $untested['overall'], 'untested WordPress version became overall PASS' );
$GLOBALS['wp_version'] = '';
$unavailable = Doctor::run( 'cc57_target', 5, $writer, $root );
cc57_check( $unavailable, 'wordpress', 'UNKNOWN' );
cc57_assert( 'PASS' !== $unavailable['overall'], 'unavailable WordPress version became overall PASS' );
$GLOBALS['wp_version'] = $saved_wp;
cc57_assert( 'PASS' === Doctor::run( 'cc57_target', 5, $writer, $root )['overall'], 'fixture WordPress version did not restore PASS' );

foreach ( array( '5.7.42', '10.10.4-MariaDB', '8.0.44-TiDB', 'PostgreSQL 16', '' ) as $old ) {
	cc57_assert( 'FAIL' === Doctor::version_status( $old )[0], 'unsupported version accepted: ' . $old );
}
foreach ( array( '8.0.45' => 'MySQL', '10.11.16-MariaDB' => 'MariaDB', '10.11.15-MariaDB-other' => 'MariaDB' ) as $later => $family ) {
	$label = Doctor::version_status( $later );
	cc57_assert( 'PASS' === $label[0] && $family === $label[2] && false !== strpos( $label[1], 'UNTESTED' ), 'later version incorrectly labelled tested' );
}
// #57 Blocker 1 adversarial regression, real engines. A cross-schema writable
// table whose trigger resets CommitCap authority is invisible to the lexical
// Guard monitor. The server-side bypass is preserved as explicit negative
// evidence; the doctor must refuse to PASS this environment.
$sidecar = 'cc57sidecar';
cc57_query( $root, 'CREATE DATABASE IF NOT EXISTS `cc57sidecar`' );
cc57_query( $root, 'CREATE TABLE `cc57sidecar`.`cc57_hidden` (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
cc57_query( $root, 'INSERT INTO `cc57sidecar`.`cc57_hidden` (id, touched) VALUES (1, 0)' );
cc57_query( $root, "GRANT SELECT, UPDATE, TRIGGER ON `cc57sidecar`.* TO 'cc_writer'@'%'" );
$policy = hash( 'sha256', 'cc57_target' );
$hidden_body = "BEGIN CALL wp_test.commitcap_v01_close('$policy'); CALL wp_test.commitcap_v01_open('$policy'); END";
$writer_created = false !== $writer->query( "CREATE TRIGGER `cc57sidecar`.`cc57_reset` BEFORE UPDATE ON `cc57sidecar`.`cc57_hidden` FOR EACH ROW $hidden_body" );
if ( 'mysql' === $host ) {
	// MySQL 8.0.44 pins binary logging with log_bin_trust_function_creators=0,
	// so a non-SUPER account is refused CREATE TRIGGER (error 1419). The
	// pre-existing-trigger path below must still be exercised.
	cc57_assert( ! $writer_created && 1419 === $writer->dbh->errno, 'MySQL writer trigger creation rule changed: ' . $writer->last_error );
	cc57_query( $writer, 'SELECT 1' ); // Clear the refused statement's wpdb error state.
	cc57_query( $root, "CREATE TRIGGER `cc57sidecar`.`cc57_reset` BEFORE UPDATE ON `cc57sidecar`.`cc57_hidden` FOR EACH ROW $hidden_body" );
} else {
	cc57_assert( $writer_created, 'MariaDB writer could not create the hidden trigger: ' . $writer->last_error );
}

cc57_query( $root, 'INSERT INTO cc57_target (id, touched) VALUES (1, 0), (2, 0)' );
$fresh = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$fresh->suppress_errors( true );
$bypass = Guard::update(
	'cc57_target',
	5,
	function () use ( $writer ) {
		for ( $i = 0; $i < 5; ++$i ) {
			cc57_assert( false !== $writer->query( 'UPDATE cc57_target SET touched = touched + 1 WHERE id = ' . ( 1 + ( $i % 2 ) ) ), 'guarded target update: ' . $writer->last_error );
		}
		cc57_assert( false !== $writer->query( 'UPDATE `cc57sidecar`.`cc57_hidden` SET touched = touched + 1 WHERE id = 1' ), 'sidecar update: ' . $writer->last_error );
		for ( $i = 0; $i < 5; ++$i ) {
			cc57_assert( false !== $writer->query( 'UPDATE cc57_target SET touched = touched + 1 WHERE id = ' . ( 1 + ( $i % 2 ) ) ), 'guarded target update: ' . $writer->last_error );
		}
		return 'bypass';
	},
	$writer
);
cc57_assert( 'bypass' === $bypass, 'hidden-trigger bypass did not reach guarded COMMIT' );
cc57_assert( 10 === (int) $fresh->get_var( 'SELECT COALESCE(SUM(touched), 0) FROM cc57_target' ), 'hidden-trigger durable-event count changed' );
cc57_assert( 0 === (int) $fresh->get_var( 'SELECT COUNT(*) FROM commitcap_v01_state' ), 'hidden-trigger left accounting state' );
echo "#57 $host: cross-schema TRIGGER CLOSE→OPEN bypass: CONFIRMED, ten durable events under budget 5\n";

$refuse = Doctor::run( 'cc57_target', 5, $writer, $root );
cc57_check( $refuse, 'runtime_grants', 'FAIL' );
cc57_assert( 'PASS' !== $refuse['overall'], 'TRIGGER authority became overall PASS' );

// Without TRIGGER authority, a pre-existing trigger on a writable object is
// still an unreviewed server-side execution surface; the doctor cannot PASS it.
cc57_query( $root, "REVOKE TRIGGER ON `cc57sidecar`.* FROM 'cc_writer'@'%'" );
$preexisting = Doctor::run( 'cc57_target', 5, $writer, $root );
cc57_check( $preexisting, 'runtime_grants', 'PASS' );
cc57_check( $preexisting, 'runtime_trigger_surface', 'UNKNOWN' );
cc57_assert( 'PASS' !== $preexisting['overall'], 'pre-existing trigger surface became overall PASS' );

// Removing the trigger clears the surface: an ordinary non-target write grant
// with no trigger graph is not treated as unsafe, but was inspected first.
cc57_query( $root, "DROP TRIGGER `cc57sidecar`.`cc57_reset`" );
$cleared = Doctor::run( 'cc57_target', 5, $writer, $root );
cc57_check( $cleared, 'runtime_trigger_surface', 'PASS' );
cc57_assert( 'PASS' === $cleared['overall'], 'clean non-target write scope did not restore PASS: ' . json_encode( $cleared ) );
cc57_query( $root, "REVOKE ALL PRIVILEGES ON `cc57sidecar`.* FROM 'cc_writer'@'%'" );
cc57_query( $root, 'DROP TABLE `cc57sidecar`.`cc57_hidden`' );
cc57_query( $root, 'DROP DATABASE `cc57sidecar`' );
cc57_query( $root, 'DELETE FROM cc57_target' ); // No UPDATE: the policy trigger must stay enforced.

cc57_assert( 'UNKNOWN' === Doctor::run( 'cc57_target', 5, $writer )['overall'], 'missing installer evidence green' );
cc57_check( Doctor::run( 'cc57_target', 5, $writer ), 'objects', 'UNKNOWN' );
cc57_assert( 'FAIL' === Doctor::site_status( true, false ) && 'FAIL' === Doctor::site_status( false, true ) &&
	'UNKNOWN' === Doctor::site_status( null, null ), 'multisite/network state accepted' );
$saved = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = null;
cc57_check( Doctor::run(), 'wpdb', 'FAIL' );
$GLOBALS['wpdb'] = $saved;

cc57_query( $root, 'CREATE TABLE cc57_myisam (id INT PRIMARY KEY) ENGINE=MyISAM' );
cc57_check( Doctor::run( 'cc57_myisam', null, $writer, $root ), 'target_table', 'FAIL' );
cc57_check( Doctor::run( 'cc57_target', 4, $writer, $root ), 'target_table', 'FAIL' );
cc57_query( $root, 'CREATE TABLE cc57_conflict (id INT PRIMARY KEY) ENGINE=InnoDB' );
cc57_query( $root, 'CREATE TRIGGER cc57_other BEFORE UPDATE ON cc57_conflict FOR EACH ROW SET @cc57_fixture=1' );
cc57_check( Doctor::run( 'cc57_conflict', null, $writer, $root ), 'target_table', 'FAIL' );
$engine->remove_owned_policy( 'cc57_target', 5 );
cc57_query( $root, 'CREATE TRIGGER cc57_fake BEFORE UPDATE ON cc57_target FOR EACH ROW SET @cc57_fixture=1' );
cc57_check( Doctor::run( 'cc57_target', 5, $writer, $root ), 'target_table', 'FAIL' );
cc57_query( $root, 'DROP TRIGGER cc57_fake' );
$engine->install_policy( 'cc57_target', 5 );

cc57_query( $root, 'CREATE FUNCTION cc57_unreviewed() RETURNS INT DETERMINISTIC RETURN 1' );
cc57_query( $root, "GRANT EXECUTE ON FUNCTION wp_test.cc57_unreviewed TO 'cc_writer'@'%'" );
cc57_check( Doctor::run( 'cc57_target', 5, $writer, $root ), 'runtime_grants', 'FAIL' );
cc57_query( $root, "REVOKE EXECUTE ON FUNCTION wp_test.cc57_unreviewed FROM 'cc_writer'@'%'" );
cc57_query( $root, 'DROP FUNCTION cc57_unreviewed' );
cc57_query( $root, "GRANT EXECUTE ON *.* TO 'cc_writer'@'%'" );
cc57_check( Doctor::run( 'cc57_target', 5, $writer, $root ), 'runtime_grants', 'FAIL' );
cc57_query( $root, "REVOKE EXECUTE ON *.* FROM 'cc_writer'@'%'" );
cc57_query( $root, "GRANT ALTER ON wp_test.* TO 'cc_writer'@'%'" );
cc57_check( Doctor::run( 'cc57_target', 5, $writer, $root ), 'runtime_grants', 'FAIL' );
cc57_query( $root, "REVOKE ALTER ON wp_test.* FROM 'cc_writer'@'%'" );
cc57_query( $root, "GRANT UPDATE ON wp_test.* TO 'cc_writer'@'%'" );
cc57_check( Doctor::run( 'cc57_target', 5, $writer, $root ), 'runtime_grants', 'FAIL' );
cc57_query( $root, "REVOKE UPDATE ON wp_test.* FROM 'cc_writer'@'%'" );
cc57_check( Doctor::run( 'cc57_target', 5, $writer, $root ), 'runtime_grants', 'PASS' );
cc57_query( $root, "REVOKE SELECT ON wp_test.cc57_target FROM 'cc_writer'@'%'" );
cc57_check( Doctor::run( 'cc57_target', 5, $writer, $root ), 'target_access', 'FAIL' );
cc57_query( $root, "GRANT SELECT ON wp_test.cc57_target TO 'cc_writer'@'%'" );
cc57_assert( null === Grants::from_statements( array( "GRANT `role`@`%` TO `cc_writer`@`%`" ), 'wp_test' ), 'unexpanded role accepted' );
cc57_assert( null === Grants::from_statements( array(), 'wp_test' ), 'empty grant result accepted' );
cc57_assert( 'FAIL' === Grants::from_statements( array( "GRANT CREATE ROUTINE ON `elsewhere`.* TO 'cc_writer'@'%'" ), 'wp_test' )->runtime()[0], 'cross-schema routine creation bypass' );
cc57_assert( 'FAIL' === Grants::from_statements( array( "GRANT CREATE TEMPORARY TABLES ON `wp_test`.* TO 'cc_writer'@'%'" ), 'wp_test' )->runtime()[0], 'helper shadowing privilege bypass' );
cc57_assert( 'FAIL' === Grants::from_statements( array( "GRANT EXECUTE ON FUNCTION `elsewhere`.`evil` TO 'cc_writer'@'%'" ), 'wp_test' )->runtime()[0], 'cross-schema function EXECUTE bypass' );
cc57_assert( 'FAIL' === Grants::from_statements( array( "GRANT SYSTEM_VARIABLES_ADMIN ON *.* TO 'cc_writer'@'%'" ), 'wp_test' )->runtime()[0], 'server admin capability accepted' );
cc57_assert( 'FAIL' === Grants::from_statements( array( "GRANT TRIGGER ON `elsewhere`.* TO 'cc_writer'@'%'" ), 'wp_test' )->runtime()[0], 'cross-schema TRIGGER accepted' );
cc57_assert( 'FAIL' === Grants::from_statements( array( "GRANT UPDATE, TRIGGER ON `sidecar`.* TO 'cc_writer'@'%'" ), 'wp_test' )->runtime()[0], 'cross-schema combined TRIGGER accepted' );
cc57_assert( 'FAIL' === Grants::from_statements( array( "GRANT TRIGGER ON *.* TO 'cc_writer'@'%'" ), 'wp_test' )->runtime()[0], 'global TRIGGER accepted' );
list( $cc57_ambiguous, $cc57_scopes ) = Grants::from_statements( array( "GRANT UPDATE ON `sidecar`.* TO 'cc_writer'@'%'" ), 'wp_test' )->trigger_write_scopes( null );
cc57_assert( ! $cc57_ambiguous && 1 === count( $cc57_scopes ) && 'sidecar' === $cc57_scopes[0]['database'] && '*' === $cc57_scopes[0]['object'], 'cross-schema write surface not exposed' );
list( $cc57_ambiguous, $cc57_scopes ) = Grants::from_statements( array( "GRANT UPDATE ON `wp_test`.`cc57_target` TO 'cc_writer'@'%'" ), 'wp_test' )->trigger_write_scopes( 'cc57_target' );
cc57_assert( ! $cc57_ambiguous && array() === $cc57_scopes, 'verified target treated as unreviewed surface' );
list( $cc57_ambiguous, $cc57_scopes ) = Grants::from_statements( array( "GRANT UPDATE ON `wp_test`.* TO 'cc_writer'@'%'" ), 'wp_test' )->trigger_write_scopes( null );
cc57_assert( ! $cc57_ambiguous && array() === $cc57_scopes, 'schema-wide write repeated as an unreviewed surface' );
list( $cc57_ambiguous, $cc57_scopes ) = Grants::from_statements( array( "GRANT UPDATE ON `other_pattern%`.* TO 'cc_writer'@'%'" ), 'wp_test' )->trigger_write_scopes( null );
cc57_assert( $cc57_ambiguous && array() === $cc57_scopes, 'pattern write scope not ambiguous' );

// Malformed same-name infrastructure must not pass structural verification or
// be rewritten, even though a restricted writer can still see a routine name.
cc57_query( $root, 'DROP PROCEDURE commitcap_v01_count' );
cc57_query( $root, 'CREATE PROCEDURE commitcap_v01_count(IN p_policy CHAR(64)) SELECT 1' );
cc57_check( Doctor::run( 'cc57_target', 5, $writer, $root ), 'objects', 'FAIL' );
cc57_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'commitcap_v01_count'" ), 'doctor modified conflicting routine' );
cc57_query( $root, 'DROP PROCEDURE commitcap_v01_count' );
$engine->install_infrastructure();
cc57_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test.commitcap_v01_count TO 'cc_writer'@'%'" );
cc57_check( Doctor::run( 'cc57_target', 5, $writer, $root ), 'objects', 'PASS' );

cc57_query( $writer, 'START TRANSACTION' );
cc57_check( Doctor::run( 'cc57_target', 5, $writer, $root ), 'transaction', 'FAIL' );
cc57_query( $writer, 'ROLLBACK' );
cc57_query( $writer, 'SET autocommit=0' );
cc57_check( Doctor::run( 'cc57_target', 5, $writer, $root ), 'transaction', 'FAIL' );
cc57_query( $writer, 'SET autocommit=1' );
cc57_assert( 'PASS' === Doctor::run( 'cc57_target', 5, $writer, $root )['overall'], 'doctor did not recover after transaction tests' );
$writer->query( 'SELECT * FROM cc57_missing' );
cc57_check( Doctor::run( 'cc57_target', 5, $writer, $root ), 'connection_state', 'FAIL' );
cc57_query( $writer, 'SELECT 1' );

// Artificial only for WordPress state and DB query failure; privilege/object
// assertions above exercise real server grants, metadata and procedures.
class CC57_Version_Failure_Wpdb extends wpdb {
	public function get_var( $query = null, $x = 0, $y = 0 ) {
		if ( 'SELECT VERSION()' === $query ) {
			return null;
		}
		return parent::get_var( $query, $x, $y );
	}
}
$broken = new CC57_Version_Failure_Wpdb( 'cc_writer', 'disposable_writer_password', 'wp_test', $host );
cc57_check( Doctor::run( 'cc57_target', 5, $broken, $root ), 'database', 'UNKNOWN' );
cc57_assert( 'PASS' !== Doctor::run( 'cc57_target', 5, $broken, $root )['overall'], 'UNKNOWN became PASS' );
class CC57_Grant_Failure_Wpdb extends wpdb {
	public function get_results( $query = null, $output = OBJECT ) {
		if ( 'SHOW GRANTS' === $query ) {
			return null;
		}
		return parent::get_results( $query, $output );
	}
}
$unreadable = new CC57_Grant_Failure_Wpdb( 'cc_writer', 'disposable_writer_password', 'wp_test', $host );
cc57_check( Doctor::run( 'cc57_target', 5, $unreadable, $root ), 'runtime_grants', 'UNKNOWN' );
cc57_assert( 'PASS' !== Doctor::run( 'cc57_target', 5, $unreadable, $root )['overall'], 'unreadable grants became PASS' );

cc57_query( $root, 'DROP TRIGGER cc57_other' );
cc57_query( $root, 'DROP TABLE cc57_conflict' );
cc57_query( $root, 'DROP TABLE cc57_myisam' );
$engine->remove_owned_policy( 'cc57_target', 5 );
cc57_query( $root, 'DROP TABLE cc57_target' );
foreach ( array( 'open', 'close', 'count', 'policy', 'attest' ) as $routine ) {
	cc57_query( $root, 'DROP PROCEDURE commitcap_v01_' . $routine );
}
cc57_query( $root, 'DROP TABLE commitcap_v01_state' );
cc57_check( Doctor::run( null, null, $writer, $root ), 'objects', 'FAIL' );
cc57_query( $root, 'CREATE TABLE commitcap_v01_state (id INT) ENGINE=InnoDB' );
cc57_check( Doctor::run( null, null, $writer, $root ), 'objects', 'FAIL' );
cc57_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commitcap_v01_state'" ), 'doctor changed unknown helper collision' );
cc57_query( $root, 'DROP TABLE commitcap_v01_state' );
$engine->install_infrastructure(); // Restore exact fixture-owned infrastructure.
cc57_check( Doctor::run( null, null, $writer, $root ), 'objects', 'PASS' );
echo "#57 $host: ALL EXPECTED DOCTOR ASSERTIONS PASS\n";

require_once __DIR__ . '/test-83-shared-runtime-doctor.php';
require_once __DIR__ . '/test-84-provisioning-plan.php';

