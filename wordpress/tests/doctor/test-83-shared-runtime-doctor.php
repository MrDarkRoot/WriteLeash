<?php
// Gate #83: verify shared-runtime Doctor without retained installer credentials.
// Proves narrow trusted-evidence model for one shared restricted runtime and
// multiple code-known/versioned integration policies on real MySQL and MariaDB engines.

use WriteLeash\Certified_Operation;
use WriteLeash\Compatibility_Doctor as Doctor;
use WriteLeash\Compatibility_Grants;
use WriteLeash\Guard;
use WriteLeash\Update_Engine as Engine;

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
function cc83_reset_grants( $root, $installer ) {
	cc83_query( $root, "REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'cc_writer'@'%'" );
	$installer->install_infrastructure();
	foreach ( array( 'open', 'close', 'count', 'policy', 'attest' ) as $r ) {
		cc83_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test.writeleash_v01_$r TO 'cc_writer'@'%'" );
	}
	cc83_query( $root, "GRANT SELECT ON wp_test.writeleash_v01_state TO 'cc_writer'@'%'" );
	foreach ( array( 'cc83_a', 'cc83_b' ) as $target ) {
		$exists = (int) $root->get_var( $root->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='wp_test' AND TABLE_NAME=%s", $target ) );
		if ( $exists > 0 ) {
			cc83_query( $root, "GRANT SELECT, UPDATE ON wp_test.`$target` TO 'cc_writer'@'%'" );
		}
	}
}
cc83_reset_grants( $root, $installer );

/**
 * Adversarial body-only tamper: replace a reviewed routine with a malicious
 * body that keeps the exact signature, SQL SECURITY DEFINER and name, and
 * re-grants EXECUTE so the tamper simulates an installer-capable adversary.
 */
function cc83_tamper( $root, $name, $params, $body ) {
	cc83_query( $root, "DROP PROCEDURE `$name`" );
	cc83_query( $root, "CREATE PROCEDURE `$name` $params SQL SECURITY DEFINER $body" );
	cc83_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test.`$name` TO 'cc_writer'@'%'" );
}

function cc83_restore_routines( $root, $installer ) {
	foreach ( array( 'open', 'close', 'count', 'policy', 'attest' ) as $r ) {
		$root->query( "DROP PROCEDURE IF EXISTS writeleash_v01_$r" );
	}
	$installer->install_infrastructure();
	cc83_reset_grants( $root, $installer );
}

function cc83_tamper_ok( $root, $installer, $writer, $name, $params, $body, $policy_table ) {
	cc83_tamper( $root, $name, $params, $body );
	$result = Doctor::runtime( array( $policy_table => 15 ), $writer );
	cc83_assert( 'PASS' !== $result['overall'], "$name tamper must not PASS: " . json_encode( $result ) );
	cc83_check( $result, 'evidence_channel', 'FAIL' );
	cc83_assert( 'UNKNOWN' === $result['integrations'][ $policy_table ]['status'], "$name tamper must force integration UNKNOWN: " . json_encode( $result['integrations'] ) );
	cc83_restore_routines( $root, $installer );
	$restored = Doctor::runtime( array( $policy_table => 15 ), $writer );
	cc83_assert( 'PASS' === $restored['overall'], "$name restore must return to PASS: " . json_encode( $restored ) );
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
$installer->install_policy( 'cc83_a', 10, 'cc_writer' ); // physical ceiling P = 10

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
$installer->install_policy( 'cc83_b', 20, 'cc_writer' ); // physical ceiling P = 20

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
$b_trigger = 'writeleash_v01_' . substr( hash( 'sha256', 'cc83_b' ), 0, 16 );
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
$installer->install_policy( 'cc83_b', 20, 'cc_writer' );
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
$a_trigger = 'writeleash_v01_' . substr( hash( 'sha256', 'cc83_a' ), 0, 16 );
cc83_query( $root, "DROP TRIGGER `$a_trigger`" );
cc83_query( $root, "CREATE TRIGGER `$a_trigger` BEFORE UPDATE ON cc83_a FOR EACH ROW SET @cc83_tamper = 1" );
$res_tampered_trig = Doctor::runtime( array( 'cc83_a' => 5, 'cc83_b' => 15 ), $writer );
cc83_assert( 'FAIL' === $res_tampered_trig['integrations']['cc83_a']['status'], '#83.6b tampered trigger must FAIL integration' );
cc83_assert( 'UNKNOWN' === $res_tampered_trig['integrations']['cc83_b']['status'], '#83.6b tampered trigger forces B to UNKNOWN' );

// Restore canonical policy
cc83_query( $root, "DROP TRIGGER `$a_trigger`" );
$installer->install_policy( 'cc83_a', 10, 'cc_writer' );
echo "  #83.6 policy ceiling and template mismatch: PASS\n";

// ---------------------------------------------------------------------------
// #83.7: Credential rotation and removed A while B remains
// ---------------------------------------------------------------------------
// 7a: Removed A while B remains
$installer->remove_owned_policy( 'cc83_a', 10, 'cc_writer' );
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
// #83.8: Signature-level infrastructure tampering detection
// ---------------------------------------------------------------------------
cc83_query( $root, 'DROP PROCEDURE writeleash_v01_count' );
cc83_query( $root, 'CREATE PROCEDURE writeleash_v01_count() SELECT 1' );
$res_infra_tamper = Doctor::runtime( array( 'cc83_b' => 15 ), $writer );
cc83_check( $res_infra_tamper, 'evidence_channel', 'FAIL' );
cc83_assert( 'PASS' !== $res_infra_tamper['overall'], '#83.8 infrastructure tampering must FAIL' );
cc83_restore_routines( $root, $installer );
$res_infra_clean = Doctor::runtime( array( 'cc83_b' => 15 ), $writer );
cc83_check( $res_infra_clean, 'objects', 'PASS' );
echo "  #83.8 signature-level infrastructure tampering detection: PASS\n";

// ---------------------------------------------------------------------------
// #83.9: Body-only tamper with identical name/parameters/DEFINER/apparent
// counts. The body keeps the canonical statement shape and only changes one
// behavioral token, so signature counting and successful CALLs cannot detect it.
// Cross-attested live definitions and direct information_schema metadata must.
// ---------------------------------------------------------------------------
$canonical = Engine::routines();

$open_tamper = str_replace( 'p_policy, 0)', 'p_policy, 7)', $canonical['writeleash_v01_open'] );
cc83_assert( $open_tamper !== $canonical['writeleash_v01_open'], 'open tamper fixture changed nothing' );
cc83_tamper_ok( $root, $installer, $writer, 'writeleash_v01_open',
	'(IN p_policy CHAR(64) CHARACTER SET ascii COLLATE ascii_bin)', $open_tamper, 'cc83_b' );
echo "  #83.9a body-only tamper of open detected: PASS\n";

$close_tamper = str_replace( 'COALESCE(@writeleash_v01_denied, 1) != 0', 'COALESCE(@writeleash_v01_denied, 0) != 0', $canonical['writeleash_v01_close'] );
cc83_assert( $close_tamper !== $canonical['writeleash_v01_close'], 'close tamper fixture changed nothing' );
cc83_tamper_ok( $root, $installer, $writer, 'writeleash_v01_close',
	'(IN p_policy CHAR(64) CHARACTER SET ascii COLLATE ascii_bin)', $close_tamper, 'cc83_b' );
echo "  #83.9b body-only tamper of close detected: PASS\n";

$count_tamper = str_replace( 'SELECT consumed INTO p_count', 'SELECT 0*consumed INTO p_count', $canonical['writeleash_v01_count'] );
cc83_assert( $count_tamper !== $canonical['writeleash_v01_count'], 'count tamper fixture changed nothing' );
cc83_tamper_ok( $root, $installer, $writer, 'writeleash_v01_count',
	'(IN p_policy CHAR(64) CHARACTER SET ascii COLLATE ascii_bin, OUT p_count BIGINT UNSIGNED)', $count_tamper, 'cc83_b' );
echo "  #83.9c body-only tamper of count detected: PASS\n";

// Evidence root #1: writeleash_v01_policy. The tamper appends a statement to the
// canonical body, preserving signature, DEFINER, output shape and apparent
// counts. Only writeleash_v01_attest and direct metadata can expose it.
$policy_tamper = str_replace( 'BEGIN ', 'BEGIN SET @cc83_policy_tamper = 1; ', $canonical['writeleash_v01_policy'] );
cc83_assert( $policy_tamper !== $canonical['writeleash_v01_policy'], 'policy tamper fixture changed nothing' );
cc83_tamper_ok( $root, $installer, $writer, 'writeleash_v01_policy',
	'(IN p_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin, IN p_trigger VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin)', $policy_tamper, 'cc83_b' );
echo "  #83.9d body-only tamper of the policy evidence root detected: PASS\n";

// Evidence root #2: writeleash_v01_attest. Symmetric cross-report from policy.
$attest_tamper = str_replace( 'ORDER BY r.ROUTINE_NAME', 'ORDER BY r.ROUTINE_NAME, 1', $canonical['writeleash_v01_attest'] );
cc83_assert( $attest_tamper !== $canonical['writeleash_v01_attest'], 'attest tamper fixture changed nothing' );
cc83_tamper_ok( $root, $installer, $writer, 'writeleash_v01_attest', '()', $attest_tamper, 'cc83_b' );
echo "  #83.9e body-only tamper of the attest evidence root detected: PASS\n";

// ---------------------------------------------------------------------------
// #83.10: A tampered count routine cannot produce consumed > L with a Guard
// COMMIT. Guard reads the helper state without any routine mediation, so the
// logical pre-commit denial still fires and rolls the whole transaction back
// even while the count routine under-reports.
// ---------------------------------------------------------------------------
// Re-seed cc83_b to a known all-zero state through root.
cc83_query( $root, 'DELETE FROM cc83_b' );
for ( $i = 1; $i <= 20; ++$i ) {
	cc83_query( $root, "INSERT INTO cc83_b (id, touched) VALUES ($i, 0)" );
}
cc83_tamper( $root, 'writeleash_v01_count',
	'(IN p_policy CHAR(64) CHARACTER SET ascii COLLATE ascii_bin, OUT p_count BIGINT UNSIGNED)',
	'BEGIN SET p_count = 0; END' );
$res_lied = Doctor::runtime( array( 'cc83_b' => 15 ), $writer );
cc83_assert( 'PASS' !== $res_lied['overall'], '#83.10 tampered count must not PASS Doctor' );
$denied_by_state = null;
try {
	Guard::update(
		'cc83_b',
		15,
		function () use ( $writer ) {
			for ( $i = 1; $i <= 16; ++$i ) {
				cc83_query( $writer, "UPDATE cc83_b SET touched = 1 WHERE id = $i" );
			}
		},
		$writer
	);
} catch ( \WriteLeash\Budget_Denied $error ) {
	$denied_by_state = $error->details();
}
cc83_assert( null !== $denied_by_state, '#83.10 tampered count caused an over-budget COMMIT' );
cc83_assert( 'logical_budget_exceeded' === $denied_by_state['reason'], '#83.10 denial must come from direct state accounting: ' . $denied_by_state['reason'] );
cc83_assert( 16 === $denied_by_state['consumed'], '#83.10 direct state must report the true 16 events' );
$observer = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$observer->suppress_errors( true );
cc83_assert( 0 === (int) $observer->get_var( 'SELECT COUNT(*) FROM cc83_b WHERE touched = 1' ), '#83.10 over-budget events became durable' );
cc83_restore_routines( $root, $installer );
echo "  #83.10 tampered count cannot force an over-budget Guard COMMIT (direct-state denial + full rollback): PASS\n";

// ---------------------------------------------------------------------------
// #83.11: Behavioral trigger probe is independent of the evidence routine.
// With the policy trigger dropped, the probe must refuse even though no
// definition channel reported anything.
// ---------------------------------------------------------------------------
$b_trigger2 = Engine::trigger_name( 'cc83_b' );
cc83_query( $root, "DROP TRIGGER `$b_trigger2`" );
$probe_engine = new Engine( $writer );
$probe_refused = false;
try {
	$probe_engine->runtime_trigger_probe( 'cc83_b' );
} catch ( \Throwable $error ) {
	$probe_refused = true;
}
cc83_assert( $probe_refused, '#83.11 behavioral trigger probe accepted a dropped trigger' );
$installer->install_policy( 'cc83_b', 20, 'cc_writer' );
$probe_result = $probe_engine->runtime_trigger_probe( 'cc83_b' );
cc83_assert( 'probed' === $probe_result, '#83.11 behavioral trigger probe did not pass on the canonical trigger: ' . $probe_result );
echo "  #83.11 behavioral trigger accounting probe is independent of definition evidence: PASS\n";

// ---------------------------------------------------------------------------
// #83.12: the runtime-identity condition is part of the trusted enforcement
// body. A canonical-looking trigger with the wrong identity, the condition
// removed or broadened, or an extra accepted principal must not certify.
// ---------------------------------------------------------------------------
function cc83_identity_tamper( $root, $installer, $writer, $table, $ceiling, $body, $label ) {
	$trigger = Engine::trigger_name( $table );
	cc83_query( $root, "DROP TRIGGER `$trigger`" );
	cc83_query( $root, "CREATE TRIGGER `$trigger` BEFORE UPDATE ON `$table` FOR EACH ROW $body" );
	$result = Doctor::runtime( array( $table => $ceiling ), $writer );
	cc83_assert( 'PASS' !== $result['overall'], "$label must not PASS: " . json_encode( $result ) );
	cc83_assert( 'FAIL' === $result['integrations'][ $table ]['status'], "$label integration must FAIL: " . json_encode( $result['integrations'] ) );
	cc83_query( $root, "DROP TRIGGER `$trigger`" );
	$installer->install_policy( $table, $ceiling, 'cc_writer' );
}
$canonical_b = Engine::trigger_body( 'cc83_b', 20, 'cc_writer' );
$identity_condition = "LOWER(SUBSTRING_INDEX(USER(), '@', 1)) = LOWER('cc_writer')";
cc83_assert( false !== strpos( $canonical_b, $identity_condition ), '#83.12 canonical identity condition missing' );
$identity_variants = array(
	'wrong runtime identity' => str_replace( $identity_condition, "LOWER(SUBSTRING_INDEX(USER(), '@', 1)) = LOWER('cc83_other')", $canonical_b ),
	'identity check removed' => str_replace( $identity_condition, '1 = 1', $canonical_b ),
	'broadened bypass' => str_replace( $identity_condition, "LOWER(SUBSTRING_INDEX(USER(), '@', 1)) = LOWER('cc_writer') OR 1 = 1", $canonical_b ),
	'extra principal accepted' => str_replace( $identity_condition, "LOWER(SUBSTRING_INDEX(USER(), '@', 1)) IN (LOWER('cc_writer'), LOWER('cc83_foreign'))", $canonical_b ),
);
foreach ( $identity_variants as $label => $variant ) {
	cc83_assert( $canonical_b !== $variant, '#83.12 fixture mutation did not occur: ' . $label );
	cc83_identity_tamper( $root, $installer, $writer, 'cc83_b', 20, $variant, $label );
}
$restored_identity = Doctor::runtime( array( 'cc83_b' => 15 ), $writer );
cc83_assert( 'PASS' === $restored_identity['overall'], '#83.12 canonical restoration must PASS: ' . json_encode( $restored_identity ) );
echo "  #83.12 runtime-identity trigger variants rejected; canonical restore PASS: PASS\n";

// ---------------------------------------------------------------------------
// #83.13: layering. The generic shared-runtime evidence proves target
// privilege PRESENCE (SELECT+UPDATE); the exact descriptor-reviewed boundary
// is owned by the #78 verifier. Extra target mutation authority must be
// rejected by the exact verifier, never silently certified.
// ---------------------------------------------------------------------------
$descriptor_privileges = Certified_Operation::redirection_5_5_2_bulk_disable()->target_privileges();
foreach ( array( 'INSERT', 'DELETE' ) as $extra ) {
	cc83_query( $root, "GRANT $extra ON wp_test.cc83_b TO 'cc_writer'@'%'" );
	$grants = Compatibility_Grants::read( $writer, 'wp_test' );
	cc83_assert( null !== $grants, '#83.13 grants read failed' );
	$generic = $grants->target_access( 'cc83_b' );
	cc83_assert( 'PASS' === $generic[0], '#83.13 generic presence check is scoped to presence: ' . $generic[1] );
	$exact = $grants->target_access_exact( 'cc83_b', $descriptor_privileges );
	cc83_assert( 'FAIL' === $exact[0], '#83.13 extra ' . $extra . ' must fail the exact target boundary: ' . $exact[1] );
	cc83_assert( false !== stripos( $exact[1], 'unreviewed' ), '#83.13 exact failure must name the unreviewed privilege: ' . $exact[1] );
	cc83_query( $root, "REVOKE $extra ON wp_test.cc83_b FROM 'cc_writer'@'%'" );
	$restored = Compatibility_Grants::read( $writer, 'wp_test' )->target_access_exact( 'cc83_b', $descriptor_privileges );
	cc83_assert( 'PASS' === $restored[0], '#83.13 canonical restore must PASS: ' . $restored[1] );
}
// Unknown/pattern grant evidence is fail-closed, never PASS.
$incomplete = Compatibility_Grants::from_statements(
	array(
		"GRANT SELECT, UPDATE ON wp_test.cc83_b TO 'cc_writer'@'%'",
		"GRANT `dynamic-role` TO 'cc_writer'@'%'",
	),
	'wp_test'
);
cc83_assert( null === $incomplete, '#83.13 unexpanded role grant evidence must be null/UNKNOWN' );
echo "  #83.13 generic presence vs exact descriptor boundary; extra target INSERT/DELETE rejected: PASS\n";

// Clean up Gate #83 test objects
$installer->remove_owned_policy( 'cc83_b', 20, 'cc_writer' );
cc83_query( $root, "REVOKE ALL PRIVILEGES ON wp_test.cc83_b FROM 'cc_writer'@'%'" );
cc83_query( $root, 'DROP TABLE cc83_a' );
cc83_query( $root, 'DROP TABLE cc83_b' );

echo "--- End Gate #83 Shared-Runtime Doctor Tests ($host): ALL PASS ---\n";
