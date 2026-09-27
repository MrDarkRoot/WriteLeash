<?php
// #57: real pinned engines and split grants. No mock database substitutes for
// object verification, effective privileges or Guard transaction behavior.
require_once WP_PLUGIN_DIR . '/commitcap-for-wordpress/commitcap.php';

use CommitCap\Compatibility_Doctor as Doctor;
use CommitCap\Compatibility_Grants as Grants;
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
$engine->install_infrastructure();
cc57_query( $root, 'CREATE TABLE cc57_target (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
cc57_query( $root, "GRANT SELECT, UPDATE ON wp_test.cc57_target TO 'cc_writer'@'%'" );
$engine->install_policy( 'cc57_target', 5 );
$version = $root->get_var( 'SELECT VERSION()' );
cc57_assert( 0 === strpos( $version, 'mysql' === $host ? '8.0.44' : '10.11.15' ), 'fixture version drift' );
$result = Doctor::run( 'cc57_target', 5, $writer, $root );
cc57_assert( 'PASS' === $result['overall'], 'pinned restricted environment should PASS: ' . json_encode( $result ) );
foreach ( array( 'wpdb', 'database', 'transaction', 'db_user', 'runtime_grants', 'installer_grants', 'objects', 'target_table', 'target_access' ) as $id ) {
	cc57_check( $result, $id, 'PASS' );
}
cc57_assert( false !== strpos( cc57_check( $result, 'database', 'PASS' )['detail'], 'TESTED exact fixture' ), 'pinned exact label' );
cc57_assert( false !== strpos( cc57_check( $result, 'db_user', 'PASS' )['detail'], 'cc_writer@%' ), 'effective matched DB user' );
echo "#57 $host $version: restricted fixture PASS\n";

foreach ( array( '5.7.42', '10.10.4-MariaDB', '8.0.44-TiDB', 'PostgreSQL 16', '' ) as $old ) {
	cc57_assert( 'FAIL' === Doctor::version_status( $old )[0], 'unsupported version accepted: ' . $old );
}
foreach ( array( '8.0.45' => 'MySQL', '10.11.16-MariaDB' => 'MariaDB', '10.11.15-MariaDB-other' => 'MariaDB' ) as $later => $family ) {
	$label = Doctor::version_status( $later );
	cc57_assert( 'PASS' === $label[0] && $family === $label[2] && false !== strpos( $label[1], 'UNTESTED' ), 'later version incorrectly labelled tested' );
}
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
foreach ( array( 'open', 'close', 'count', 'policy' ) as $routine ) {
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
