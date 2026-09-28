<?php
// Gate #83: verify shared-runtime Doctor without retained installer credentials.
// Proves narrow trusted-evidence model for one shared restricted runtime and
// multiple code-known/versioned integration policies on real MySQL and MariaDB engines.

use CommitCap\Compatibility_Doctor as Doctor;
use CommitCap\Guard;
use CommitCap\Update_Engine as Engine;

echo "--- Begin Gate #83 Shared-Runtime Doctor Tests ($host) ---\n";

function cc83_assert( $value, $label ) {
	if ( ! $value ) {
		throw new RuntimeException( 'ASSERTION FAILED: ' . $label );
	}
}

function cc83_check( $result, $id, $expected_status ) {
	foreach ( $result['checks'] as $check ) {
		if ( $id === $check['id'] ) {
			cc83_assert( $expected_status === $check['status'], "$id: expected $expected_status, got {$check['status']}: {$check['detail']}" );
			return $check;
		}
	}
	throw new RuntimeException( 'Missing check: ' . $id );
}

function cc83_query( $db, $sql ) {
	$res = $db->query( $sql );
	cc83_assert( false !== $res, 'SQL failed: ' . $sql . ': ' . $db->last_error );
	return $res;
}

$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$writer = new wpdb( 'cc_writer', 'disposable_writer_password', 'wp_test', $host );
$root->suppress_errors( true );
$writer->suppress_errors( true );
$installer = new Engine( $root );

// The #57 suite runs first and leaves grants behind on cc57_target.
// Reset the writer to the exact surface this suite declares and proves.
cc83_query( $root, "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'cc_writer'@'%'" );
$installer->install_infrastructure();
foreach ( array( 'open', 'close', 'count', 'policy' ) as $r ) {
	cc83_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test.commitcap_v01_$r TO 'cc_writer'@'%'" );
}

// ---------------------------------------------------------------------------
// #83.1: Table A alone READY without retained installer credentials
// ---------------------------------------------------------------------------
cc83_query( $root, 'DROP TABLE IF EXISTS cc83_a' );
cc83_query( $root, 'CREATE TABLE cc83_a (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
for ( $i = 1; $i <= 10; ++$i ) {
	cc83_query( $root, "INSERT INTO cc83_a (id, touched) VALUES ($i, 0)" );
}
cc83_query( $root, "GRANT SELECT, UPDATE ON wp_test.cc83_a TO 'cc_writer'@'%'" );
$installer->install_policy( 'cc83_a', 10 ); // physical ceiling P = 10

// Normal web request: ONLY restricted $writer connection, NO installer connection
$res_a = Doctor::runtime( array( 'cc83_a' => 5 ), $writer );
cc83_assert( 'PASS' === $res_a['overall'], '#83.1 overall should be PASS: ' . json_encode( $res_a ) );
cc83_assert( isset( $res_a['integrations']['cc83_a'] ), '#83.1 cc83_a integration missing' );
cc83_assert( 'PASS' === $res_a['integrations']['cc83_a']['status'], '#83.1 cc83_a status must be PASS' );
cc83_assert( 10 === $res_a['integrations']['cc83_a']['physical_ceiling'], '#83.1 physical ceiling must be 10' );
cc83_assert( 5 === $res_a['integrations']['cc83_a']['logical_budget'], '#83.1 logical budget must be 5' );
cc83_check( $res_a, 'evidence_channel', 'PASS' );
cc83_check( $res_a, 'objects', 'PASS' );
cc83_check( $res_a, 'runtime_grants', 'PASS' );
cc83_check( $res_a, 'runtime_trigger_surface', 'PASS' );

// Guard-owned execution under logical budget 5 commits successfully
$committed = Guard::update(
	'cc83_a',
	5,
	function () use ( $writer ) {
		for ( $i = 1; $i <= 5; ++$i ) {
			cc83_query( $writer, "UPDATE cc83_a SET touched = touched + 1 WHERE id = $i" );
		}
		return 'committed';
	},
	$writer
);
cc83_assert( 'committed' === $committed, '#83.1 guarded execution under budget 5' );
echo "  #83.1 table A alone READY without installer credentials: PASS\n";

// ---------------------------------------------------------------------------
// #83.2: Exact A+B READY (sibling policy recognized, not foreign trigger)
// ---------------------------------------------------------------------------
cc83_query( $root, 'DROP TABLE IF EXISTS cc83_b' );
cc83_query( $root, 'CREATE TABLE cc83_b (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
for ( $i = 1; $i <= 10; ++$i ) {
	cc83_query( $root, "INSERT INTO cc83_b (id, touched) VALUES ($i, 0)" );
}
cc83_query( $root, "GRANT SELECT, UPDATE ON wp_test.cc83_b TO 'cc_writer'@'%'" );
$installer->install_policy( 'cc83_b', 20 ); // physical ceiling P = 20

// Both A and B are known code integration policies
$res_ab = Doctor::runtime( array( 'cc83_a' => 5, 'cc83_b' => 15 ), $writer );
cc83_assert( 'PASS' === $res_ab['overall'], '#83.2 A+B overall should be PASS: ' . json_encode( $res_ab ) );
cc83_assert( 'PASS' === $res_ab['integrations']['cc83_a']['status'], '#83.2 cc83_a status must be PASS' );
cc83_assert( 'PASS' === $res_ab['integrations']['cc83_b']['status'], '#83.2 cc83_b status must be PASS' );
cc83_assert( 10 === $res_ab['integrations']['cc83_a']['physical_ceiling'], '#83.2 A ceiling 10' );
cc83_assert( 20 === $res_ab['integrations']['cc83_b']['physical_ceiling'], '#83.2 B ceiling 20' );
cc83_check( $res_ab, 'runtime_trigger_surface', 'PASS' );

// Both can run guarded transactions independently
$b_committed = Guard::update(
	'cc83_b',
	15,
	function () use ( $writer ) {
		for ( $i = 1; $i <= 5; ++$i ) {
			cc83_query( $writer, "UPDATE cc83_b SET touched = touched + 1 WHERE id = $i" );
		}
		return 'b_ok';
	},
	$writer
);
cc83_assert( 'b_ok' === $b_committed, '#83.2 guarded execution on table B' );
echo "  #83.2 exact A+B READY with recognized sibling policy: PASS\n";

// ---------------------------------------------------------------------------
// #83.3: B corrupted or missing while A exists -> A MUST become UNKNOWN
// ---------------------------------------------------------------------------
// Shared Reachability Invariant: A and B share the restricted connection.
// If B is corrupted, writing to B during A's transaction could bypass accounting
// or execute unconstrained writes. A cannot be certified PASS.
$b_trigger = 'commitcap_v01_' . substr( hash( 'sha256', 'cc83_b' ), 0, 16 );
cc83_query( $root, "DROP TRIGGER `$b_trigger`" );

$res_corrupt_b = Doctor::runtime( array( 'cc83_a' => 5, 'cc83_b' => 15 ), $writer );
cc83_assert( 'PASS' !== $res_corrupt_b['overall'], '#83.3 corrupted B must NOT be overall PASS' );
cc83_assert( 'DEGRADED' === $res_corrupt_b['overall'] || 'FAIL' === $res_corrupt_b['overall'], '#83.3 overall must be DEGRADED/FAIL' );
cc83_assert( 'FAIL' === $res_corrupt_b['integrations']['cc83_b']['status'], '#83.3 B status must be FAIL' );
// Crucial: A MUST BECOME UNKNOWN due to shared reachability to corrupt B!
cc83_assert( 'UNKNOWN' === $res_corrupt_b['integrations']['cc83_a']['status'], '#83.3 A must be UNKNOWN due to corrupt sibling reachability: ' . json_encode( $res_corrupt_b ) );
cc83_assert( false !== strpos( $res_corrupt_b['integrations']['cc83_a']['detail'], 'compromised by corrupted sibling' ), '#83.3 detail must state shared reachability rationale' );

// Test 3b: Table B dropped entirely while still declared in known policies
cc83_query( $root, 'DROP TABLE cc83_b' );
$res_missing_b = Doctor::runtime( array( 'cc83_a' => 5, 'cc83_b' => 15 ), $writer );
cc83_assert( 'PASS' !== $res_missing_b['overall'], '#83.3 missing B must not PASS' );
cc83_assert( 'FAIL' === $res_missing_b['integrations']['cc83_b']['status'], '#83.3 missing B must be FAIL' );
cc83_assert( 'UNKNOWN' === $res_missing_b['integrations']['cc83_a']['status'], '#83.3 A must be UNKNOWN when B missing' );

// Restore table B and policy
cc83_query( $root, 'CREATE TABLE cc83_b (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
for ( $i = 1; $i <= 10; ++$i ) {
	cc83_query( $root, "INSERT INTO cc83_b (id, touched) VALUES ($i, 0)" );
}
cc83_query( $root, "GRANT SELECT, UPDATE ON wp_test.cc83_b TO 'cc_writer'@'%'" );
$installer->install_policy( 'cc83_b', 20 );
$res_restored = Doctor::runtime( array( 'cc83_a' => 5, 'cc83_b' => 15 ), $writer );
cc83_assert( 'PASS' === $res_restored['overall'], '#83.3 restoration should restore PASS' );
echo "  #83.3 corrupt/missing B forces A to UNKNOWN and aggregate to DEGRADED: PASS\n";

// ---------------------------------------------------------------------------
// #83.4: Foreign writable triggered table fails closed
// ---------------------------------------------------------------------------
cc83_query( $root, 'DROP TABLE IF EXISTS cc83_foreign' );
cc83_query( $root, 'CREATE TABLE cc83_foreign (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
cc83_query( $root, "GRANT UPDATE ON wp_test.cc83_foreign TO 'cc_writer'@'%'" );
cc83_query( $root, 'CREATE TRIGGER cc83_foreign_trig BEFORE UPDATE ON cc83_foreign FOR EACH ROW SET @cc83_probe = 1' );

$res_foreign = Doctor::runtime( array( 'cc83_a' => 5, 'cc83_b' => 15 ), $writer );
cc83_assert( 'PASS' !== $res_foreign['overall'], '#83.4 foreign trigger must NOT PASS' );
cc83_check( $res_foreign, 'runtime_trigger_surface', 'UNKNOWN' );
cc83_assert( 'UNKNOWN' === $res_foreign['integrations']['cc83_a']['status'], '#83.4 A must be UNKNOWN with foreign trigger' );
cc83_assert( 'UNKNOWN' === $res_foreign['integrations']['cc83_b']['status'], '#83.4 B must be UNKNOWN with foreign trigger' );

// Clean table without triggers does NOT block PASS
cc83_query( $root, 'DROP TRIGGER cc83_foreign_trig' );
$res_clean_foreign = Doctor::runtime( array( 'cc83_a' => 5, 'cc83_b' => 15 ), $writer );
cc83_check( $res_clean_foreign, 'runtime_trigger_surface', 'PASS' );
cc83_assert( 'PASS' === $res_clean_foreign['overall'], '#83.4 foreign table without trigger restores PASS' );

// Clean up foreign fixture
cc83_query( $root, "REVOKE ALL PRIVILEGES ON wp_test.cc83_foreign FROM 'cc_writer'@'%'" );
cc83_query( $root, 'DROP TABLE cc83_foreign' );
echo "  #83.4 foreign writable triggered object fails closed: PASS\n";

// ---------------------------------------------------------------------------
// #83.5: Altered grant fail closed
// ---------------------------------------------------------------------------
// 5a: Writer granted dangerous TRIGGER privilege
cc83_query( $root, "GRANT TRIGGER ON wp_test.* TO 'cc_writer'@'%'" );
$res_trigger_grant = Doctor::runtime( array( 'cc83_a' => 5, 'cc83_b' => 15 ), $writer );
cc83_check( $res_trigger_grant, 'runtime_grants', 'FAIL' );
cc83_assert( 'FAIL' === $res_trigger_grant['overall'], '#83.5a TRIGGER grant must FAIL' );
cc83_query( $root, "REVOKE TRIGGER ON wp_test.* FROM 'cc_writer'@'%'" );

// 5b: Writer granted extra EXECUTE on unreviewed stored function
cc83_query( $root, 'DROP FUNCTION IF EXISTS cc83_unrev' );
cc83_query( $root, 'CREATE FUNCTION cc83_unrev() RETURNS INT DETERMINISTIC RETURN 42' );
cc83_query( $root, "GRANT EXECUTE ON FUNCTION wp_test.cc83_unrev TO 'cc_writer'@'%'" );
$res_extra_exec = Doctor::runtime( array( 'cc83_a' => 5, 'cc83_b' => 15 ), $writer );
cc83_check( $res_extra_exec, 'runtime_grants', 'FAIL' );
cc83_assert( 'FAIL' === $res_extra_exec['overall'], '#83.5b extra EXECUTE must FAIL' );
cc83_query( $root, "REVOKE EXECUTE ON FUNCTION wp_test.cc83_unrev FROM 'cc_writer'@'%'" );
cc83_query( $root, 'DROP FUNCTION cc83_unrev' );

// 5c: Target write access revoked
cc83_query( $root, "REVOKE UPDATE ON wp_test.cc83_a FROM 'cc_writer'@'%'" );
$res_no_update = Doctor::runtime( array( 'cc83_a' => 5, 'cc83_b' => 15 ), $writer );
cc83_assert( 'FAIL' === $res_no_update['integrations']['cc83_a']['status'], '#83.5c revoked UPDATE must FAIL integration' );
cc83_assert( 'UNKNOWN' === $res_no_update['integrations']['cc83_b']['status'], '#83.5c failing sibling forces B to UNKNOWN' );
cc83_assert( 'PASS' !== $res_no_update['overall'], '#83.5c revoked UPDATE must not PASS' );
cc83_query( $root, "GRANT UPDATE ON wp_test.cc83_a TO 'cc_writer'@'%'" );
echo "  #83.5 altered grant detection: PASS\n";

// ---------------------------------------------------------------------------
// #83.6: Policy version and ceiling mismatch
// ---------------------------------------------------------------------------
// 6a: Logical budget L > physical ceiling P (L = 15, P = 10)
$res_over_ceiling = Doctor::runtime( array( 'cc83_a' => 15, 'cc83_b' => 15 ), $writer );
cc83_assert( 'FAIL' === $res_over_ceiling['integrations']['cc83_a']['status'], '#83.6a L > P must FAIL integration' );
cc83_assert( 'UNKNOWN' === $res_over_ceiling['integrations']['cc83_b']['status'], '#83.6a failing sibling forces B to UNKNOWN' );
cc83_assert( false !== strpos( $res_over_ceiling['integrations']['cc83_a']['detail'], 'exceeds installed physical ceiling' ), '#83.6a detail message' );

// 6b: Trigger statement template altered
$a_trigger = 'commitcap_v01_' . substr( hash( 'sha256', 'cc83_a' ), 0, 16 );
cc83_query( $root, "DROP TRIGGER `$a_trigger`" );
cc83_query( $root, "CREATE TRIGGER `$a_trigger` BEFORE UPDATE ON cc83_a FOR EACH ROW SET @cc83_tamper = 1" );
$res_tampered_trig = Doctor::runtime( array( 'cc83_a' => 5, 'cc83_b' => 15 ), $writer );
cc83_assert( 'FAIL' === $res_tampered_trig['integrations']['cc83_a']['status'], '#83.6b tampered trigger must FAIL integration' );
cc83_assert( 'UNKNOWN' === $res_tampered_trig['integrations']['cc83_b']['status'], '#83.6b tampered trigger forces B to UNKNOWN' );

// Restore canonical policy
cc83_query( $root, "DROP TRIGGER `$a_trigger`" );
$installer->install_policy( 'cc83_a', 10 );
echo "  #83.6 policy ceiling and template mismatch: PASS\n";

// ---------------------------------------------------------------------------
// #83.7: Credential rotation and removed A while B remains
// ---------------------------------------------------------------------------
// 7a: Removed A while B remains
$installer->remove_owned_policy( 'cc83_a', 10 );
cc83_query( $root, "REVOKE ALL PRIVILEGES ON wp_test.cc83_a FROM 'cc_writer'@'%'" );
// Table cc83_a and its rows survive
cc83_assert( 10 === (int) $root->get_var( 'SELECT COUNT(*) FROM cc83_a' ), '#83.7a table rows survive policy removal' );

// Runtime verification for remaining B only
$res_b_only = Doctor::runtime( array( 'cc83_b' => 15 ), $writer );
cc83_assert( 'PASS' === $res_b_only['overall'], '#83.7a B alone must be PASS: ' . json_encode( $res_b_only ) );
cc83_assert( 'PASS' === $res_b_only['integrations']['cc83_b']['status'], '#83.7a B status PASS' );

// 7b: Credential rotation
cc83_query( $root, "ALTER USER 'cc_writer'@'%' IDENTIFIED BY 'cc83_rotated_secret'" );

// Attempting runtime verification with old credentials FAILS
$old_writer = new wpdb( 'cc_writer', 'disposable_writer_password', 'wp_test', $host );
$old_writer->suppress_errors( true );
$res_old = Doctor::runtime( array( 'cc83_b' => 15 ), $old_writer );
cc83_assert( 'FAIL' === $res_old['overall'], '#83.7b old credentials must FAIL' );
cc83_check( $res_old, 'wpdb', 'FAIL' );

// Connecting with new rotated credentials SUCCEEDS
$new_writer = new wpdb( 'cc_writer', 'cc83_rotated_secret', 'wp_test', $host );
$new_writer->suppress_errors( true );
$res_new = Doctor::runtime( array( 'cc83_b' => 15 ), $new_writer );
cc83_assert( 'PASS' === $res_new['overall'], '#83.7b rotated credentials must PASS' );
cc83_assert( 'PASS' === $res_new['integrations']['cc83_b']['status'], '#83.7b B status PASS under new creds' );

// Verify no secrets leaked in report output
$json_report = json_encode( $res_new );
cc83_assert( false === strpos( $json_report, 'cc83_rotated_secret' ), '#83.7b secret must not leak in report' );

// Restore original password for fixture cleanliness
cc83_query( $root, "ALTER USER 'cc_writer'@'%' IDENTIFIED BY 'disposable_writer_password'" );
echo "  #83.7 credential rotation and removed A while B remains: PASS\n";

// ---------------------------------------------------------------------------
// #83.8: Infrastructure tampering detection via DEFINER routine
// ---------------------------------------------------------------------------
cc83_query( $root, 'DROP PROCEDURE commitcap_v01_count' );
cc83_query( $root, 'CREATE PROCEDURE commitcap_v01_count() SELECT 1' );
$res_infra_tamper = Doctor::runtime( array( 'cc83_b' => 15 ), $writer );
cc83_check( $res_infra_tamper, 'objects', 'FAIL' );
cc83_assert( 'PASS' !== $res_infra_tamper['overall'], '#83.8 infrastructure tampering must FAIL' );

// Restore canonical infrastructure
cc83_query( $root, 'DROP PROCEDURE commitcap_v01_count' );
$installer->install_infrastructure();
cc83_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test.commitcap_v01_count TO 'cc_writer'@'%'" );
$res_infra_clean = Doctor::runtime( array( 'cc83_b' => 15 ), $writer );
cc83_check( $res_infra_clean, 'objects', 'PASS' );
echo "  #83.8 infrastructure tampering detection: PASS\n";

// Clean up Gate #83 test objects
$installer->remove_owned_policy( 'cc83_b', 20 );
cc83_query( $root, "REVOKE ALL PRIVILEGES ON wp_test.cc83_b FROM 'cc_writer'@'%'" );
cc83_query( $root, 'DROP TABLE cc83_a' );
cc83_query( $root, 'DROP TABLE cc83_b' );

echo "--- End Gate #83 Shared-Runtime Doctor Tests ($host): ALL PASS ---\n";
