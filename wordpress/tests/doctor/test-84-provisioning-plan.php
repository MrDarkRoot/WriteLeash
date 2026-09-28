<?php
// Gate #84: Deterministic shared-runtime provisioning, rotation and cleanup plans.
// Proves version-pinned inspectable plans, apply/verify flow, credential rotation,
// scoped tamper probes, and safe application-table preservation across MySQL and MariaDB.

use CommitCap\Compatibility_Doctor as Doctor;
use CommitCap\Guard;
use CommitCap\Provisioning_Plan as Plan;
use CommitCap\Update_Engine as Engine;

echo "--- Begin Gate #84 Provisioning Plan Tests ($host) ---\n";

function cc84_assert( $value, $label ) {
	if ( ! $value ) {
		throw new RuntimeException( 'ASSERTION FAILED: ' . $label );
	}
}

function cc84_query( $db, $sql ) {
	$res = $db->query( $sql );
	cc84_assert( false !== $res, 'SQL failed: ' . $sql . ': ' . $db->last_error );
	return $res;
}

$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );

// ---------------------------------------------------------------------------
// #84.1: Offline Plan Renderer Inspection & Secret Redaction
// ---------------------------------------------------------------------------
// 1a: Install plan
$install_plan = Plan::install( 'wp_test', 'cc84_writer', '%', 'secret_pass_123', array( 'target_init' => 10 ) );
cc84_assert( Plan::ACTION_INSTALL === $install_plan->get_action(), '#84.1a install action' );
$install_steps = $install_plan->get_steps( true );
cc84_assert( count( $install_steps ) >= 13, '#84.1a install step count' );

// Redacted SQL output
$redacted_sql = $install_plan->render_sql( true );
cc84_assert( false === strpos( $redacted_sql, 'secret_pass_123' ), '#84.1a secret must be redacted in default render_sql' );
cc84_assert( false !== strpos( $redacted_sql, Plan::REDACTED_SECRET ), '#84.1a REDACTED_SECRET placeholder present' );

// Raw SQL output when explicitly requested
$raw_sql = $install_plan->render_sql( false );
cc84_assert( false !== strpos( $raw_sql, 'secret_pass_123' ), '#84.1a raw secret present in explicit raw render' );

// Config snippet rendering
$config_snippet = Plan::render_wp_config_snippet( 'cc84_writer', 'localhost', 'wp_test', 'secret_pass_123' );
cc84_assert( false !== strpos( $config_snippet, "define( 'COMMITCAP_DB_USER', 'cc84_writer' );" ), '#84.1a config user' );
cc84_assert( false !== strpos( $config_snippet, "define( 'COMMITCAP_DB_PASSWORD', 'secret_pass_123' );" ), '#84.1a config pass' );

// 1b: Identifier validation & error handling
try {
	Plan::install( 'wp-bad-schema!', 'cc84_writer' );
	cc84_assert( false, '#84.1b invalid schema should throw' );
} catch ( InvalidArgumentException $e ) {
	cc84_assert( true, '#84.1b invalid schema caught' );
}

try {
	Plan::add_target( 'wp_test', 'cc84_writer', '%', 'cc84_invalid;drop', 10 );
	cc84_assert( false, '#84.1b invalid table should throw' );
} catch ( InvalidArgumentException $e ) {
	cc84_assert( true, '#84.1b invalid table caught' );
}

try {
	Plan::add_target( 'wp_test', 'cc84_writer', '%', 'cc84_ok', 0 );
	cc84_assert( false, '#84.1b zero ceiling should throw' );
} catch ( InvalidArgumentException $e ) {
	cc84_assert( true, '#84.1b zero ceiling caught' );
}

echo "  #84.1 offline plan renderer inspection and redaction: PASS\n";

// ---------------------------------------------------------------------------
// #84.2: Install Plan Apply & Initial Runtime Verification
// ---------------------------------------------------------------------------
// Clean any prior fixture artifacts
cc84_query( $root, "DROP USER IF EXISTS 'cc84_writer'@'%'" );
cc84_query( $root, 'DROP TABLE IF EXISTS cc84_a' );
cc84_query( $root, 'DROP TABLE IF EXISTS cc84_b' );

$plan_install = Plan::install( 'wp_test', 'cc84_writer', '%', 'cc84_initial_secret' );
$apply_res = $plan_install->apply( $root, 'cc84_initial_secret' );
cc84_assert( $apply_res['success'], '#84.2 apply install success' );
cc84_assert( $apply_res['executed_steps'] >= 11, '#84.2 executed steps count' );

// Connect via restricted runtime account
$writer = new wpdb( 'cc84_writer', 'cc84_initial_secret', 'wp_test', $host );
$writer->suppress_errors( true );

// Doctor verify initial state: PASS (ready for targets, 0 integrations)
$res_init = Plan::verify( array(), $writer );
cc84_assert( 'PASS' === $res_init['overall'], '#84.2 initial runtime verify overall PASS' );
echo "  #84.2 install plan apply and initial verify: PASS\n";

// ---------------------------------------------------------------------------
// #84.3: Add Target A -> Doctor Verify -> Guard Enforce
// ---------------------------------------------------------------------------
// Create application table cc84_a with 10 rows
cc84_query( $root, 'CREATE TABLE cc84_a (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
for ( $i = 1; $i <= 10; ++$i ) {
	cc84_query( $root, "INSERT INTO cc84_a (id, touched) VALUES ($i, 0)" );
}

$plan_a = Plan::add_target( 'wp_test', 'cc84_writer', '%', 'cc84_a', 10 );
$plan_a->apply( $root );

// Doctor runtime verify: cc84_a PASS (ceiling 10, logical budget 5)
$res_a = Plan::verify( array( 'cc84_a' => 5 ), $writer );
cc84_assert( 'PASS' === $res_a['overall'], '#84.3 runtime verify cc84_a' );
cc84_assert( 'PASS' === $res_a['integrations']['cc84_a']['status'], '#84.3 cc84_a status PASS' );
cc84_assert( 10 === $res_a['integrations']['cc84_a']['physical_ceiling'], '#84.3 cc84_a ceiling 10' );

// Guard enforcement test: 5 updates commit
$ran_5 = Guard::update( 'cc84_a', 5, function() use ( $writer ) {
	for ( $i = 1; $i <= 5; ++$i ) {
		$writer->query( "UPDATE cc84_a SET touched = 1 WHERE id = $i" );
	}
	return 'COMMITTED_5';
}, $writer );
cc84_assert( 'COMMITTED_5' === $ran_5, '#84.3 Guard 5 rows commit' );
cc84_assert( 5 === (int) $root->get_var( 'SELECT COUNT(*) FROM cc84_a WHERE touched = 1' ), '#84.3 durable commit 5' );

// Guard enforcement test: 6 updates on budget 5 -> typed Budget_Denied, full rollback
$denied_seen = false;
try {
	Guard::update( 'cc84_a', 5, function() use ( $writer ) {
		for ( $i = 1; $i <= 6; ++$i ) {
			$writer->query( "UPDATE cc84_a SET touched = 2 WHERE id = $i" );
		}
	}, $writer );
} catch ( \CommitCap\Budget_Denied $e ) {
	$denied_seen = true;
	$det = $e->details();
	cc84_assert( 'cc84_a' === $det['table'], '#84.3 Budget_Denied table' );
	cc84_assert( 5 === $det['budget'], '#84.3 Budget_Denied budget' );
}
cc84_assert( $denied_seen, '#84.3 6th update must trigger Budget_Denied' );
// Fresh observer confirms rollback: touched = 2 count must be 0
cc84_assert( 0 === (int) $root->get_var( 'SELECT COUNT(*) FROM cc84_a WHERE touched = 2' ), '#84.3 full rollback verified by observer' );
echo "  #84.3 add target A, doctor verify, guard enforce and rollback: PASS\n";

// ---------------------------------------------------------------------------
// #84.4: Add Target B -> Shared Runtime Verification & Enforcement
// ---------------------------------------------------------------------------
cc84_query( $root, 'CREATE TABLE cc84_b (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
for ( $i = 1; $i <= 20; ++$i ) {
	cc84_query( $root, "INSERT INTO cc84_b (id, touched) VALUES ($i, 0)" );
}

$plan_b = Plan::add_target( 'wp_test', 'cc84_writer', '%', 'cc84_b', 20 );
$plan_b->apply( $root );

$res_ab = Plan::verify( array( 'cc84_a' => 5, 'cc84_b' => 15 ), $writer );
cc84_assert( 'PASS' === $res_ab['overall'], '#84.4 runtime verify A and B' );
cc84_assert( 'PASS' === $res_ab['integrations']['cc84_a']['status'], '#84.4 A status PASS' );
cc84_assert( 'PASS' === $res_ab['integrations']['cc84_b']['status'], '#84.4 B status PASS' );

// Guard enforcement test on B: 15 updates commit, 16th updates rollback
$ran_15 = Guard::update( 'cc84_b', 15, function() use ( $writer ) {
	for ( $i = 1; $i <= 15; ++$i ) {
		$writer->query( "UPDATE cc84_b SET touched = 1 WHERE id = $i" );
	}
	return 'COMMITTED_15';
}, $writer );
cc84_assert( 'COMMITTED_15' === $ran_15, '#84.4 Guard 15 rows commit on B' );
cc84_assert( 15 === (int) $root->get_var( 'SELECT COUNT(*) FROM cc84_b WHERE touched = 1' ), '#84.4 durable commit 15 on B' );

$denied_b = false;
try {
	Guard::update( 'cc84_b', 15, function() use ( $writer ) {
		for ( $i = 1; $i <= 16; ++$i ) {
			$writer->query( "UPDATE cc84_b SET touched = 2 WHERE id = $i" );
		}
	}, $writer );
} catch ( \CommitCap\Budget_Denied $e ) {
	$denied_b = true;
}
cc84_assert( $denied_b, '#84.4 16th update on B triggers Budget_Denied' );
cc84_assert( 0 === (int) $root->get_var( 'SELECT COUNT(*) FROM cc84_b WHERE touched = 2' ), '#84.4 full rollback on B' );
echo "  #84.4 add target B, shared runtime verify and enforcement: PASS\n";

// ---------------------------------------------------------------------------
// #84.5: Scoped Tamper Probes on Restricted Writer Account
// ---------------------------------------------------------------------------
$writer_tamper = new wpdb( 'cc84_writer', 'cc84_initial_secret', 'wp_test', $host );
$writer_tamper->suppress_errors( true );

// Probe 1: Helper DML attempted directly by writer -> fails
$probe_helper = $writer_tamper->query( "INSERT INTO commitcap_v01_state (connection_id, policy_id, consumed) VALUES (99999, 'bad', 0)" );
cc84_assert( false === $probe_helper, '#84.5 probe 1: helper DML must fail' );

// Probe 2: Routine DDL attempted directly by writer -> fails
$probe_ddl = $writer_tamper->query( 'DROP PROCEDURE commitcap_v01_open' );
cc84_assert( false === $probe_ddl, '#84.5 probe 2: routine DDL must fail' );

// Probe 3: Target trigger DROP/CREATE attempted by writer -> fails
$trig_a = Engine::trigger_name( 'cc84_a' );
$probe_trig = $writer_tamper->query( "DROP TRIGGER $trig_a" );
cc84_assert( false === $probe_trig, '#84.5 probe 3: trigger DDL must fail' );

// Probe 4: Self-GRANT attempted by writer -> fails
$probe_grant = $writer_tamper->query( "GRANT ALL PRIVILEGES ON wp_test.* TO 'cc84_writer'@'%'" );
cc84_assert( false === $probe_grant, '#84.5 probe 4: self-grant must fail' );

// Probe 5: CREATE ROUTINE attempted by writer -> fails
$probe_croutine = $writer_tamper->query( 'CREATE PROCEDURE cc84_malicious() SELECT 1' );
cc84_assert( false === $probe_croutine, '#84.5 probe 5: create routine must fail' );
echo "  #84.5 scoped tamper probes (helper DML, routine DDL, trigger DDL, grant, create routine): PASS\n";

// ---------------------------------------------------------------------------
// #84.6: Conflict Refusal
// ---------------------------------------------------------------------------
// Existing unreviewed trigger on target table causes apply() to refuse
cc84_query( $root, 'DROP TABLE IF EXISTS cc84_conflict' );
cc84_query( $root, 'CREATE TABLE cc84_conflict (id INT PRIMARY KEY, touched INT) ENGINE=InnoDB' );
cc84_query( $root, 'CREATE TRIGGER cc84_alien_trig BEFORE UPDATE ON cc84_conflict FOR EACH ROW SET @alien = 1' );

$plan_conflict = Plan::add_target( 'wp_test', 'cc84_writer', '%', 'cc84_conflict', 10 );
$conflict_caught = false;
try {
	$plan_conflict->apply( $root );
} catch ( RuntimeException $e ) {
	$conflict_caught = true;
	cc84_assert( false !== strpos( $e->getMessage(), 'existing unreviewed trigger' ), '#84.6 conflict message' );
}
cc84_assert( $conflict_caught, '#84.6 conflict refusal on unreviewed trigger' );
cc84_query( $root, 'DROP TRIGGER cc84_alien_trig' );
cc84_query( $root, 'DROP TABLE cc84_conflict' );
echo "  #84.6 conflict refusal on unreviewed target trigger: PASS\n";

// ---------------------------------------------------------------------------
// #84.7: Remove Target A while B Remains Active
// ---------------------------------------------------------------------------
$plan_remove_a = Plan::remove_target( 'wp_test', 'cc84_writer', '%', 'cc84_a', 10 );
$plan_remove_a->apply( $root );

// Table cc84_a and all its rows SURVIVE intact
cc84_assert( 10 === (int) $root->get_var( 'SELECT COUNT(*) FROM cc84_a' ), '#84.7 table A and rows survive removal' );

// Fresh web request connection after grant change
$writer = new wpdb( 'cc84_writer', 'cc84_initial_secret', 'wp_test', $host );
$writer->suppress_errors( true );

// B alone passes Doctor::runtime
$res_b_alone = Plan::verify( array( 'cc84_b' => 15 ), $writer );
cc84_assert( 'PASS' === $res_b_alone['overall'], '#84.7 B alone overall PASS: ' . json_encode( $res_b_alone ) );
cc84_assert( 'PASS' === $res_b_alone['integrations']['cc84_b']['status'], '#84.7 B alone status PASS' );

// Target A is no longer writable by restricted writer
$probe_write_a = $writer->query( 'UPDATE cc84_a SET touched = 99 WHERE id = 1' );
cc84_assert( false === $probe_write_a, '#84.7 removed target A write access revoked' );
echo "  #84.7 remove target A with B remaining and application data surviving: PASS\n";

// ---------------------------------------------------------------------------
// #84.8: Credential Rotation & Session Draining
// ---------------------------------------------------------------------------
$plan_rotate = Plan::rotate_credential( 'cc84_writer', '%', 'cc84_rotated_v2_secret' );
$plan_rotate->apply( $root );

// New connection attempt with old secret FAILS
$old_writer = new wpdb( 'cc84_writer', 'cc84_initial_secret', 'wp_test', $host );
$old_writer->suppress_errors( true );
$res_old_auth = Plan::verify( array( 'cc84_b' => 15 ), $old_writer );
cc84_assert( 'FAIL' === $res_old_auth['overall'], '#84.8 old credentials must fail' );

// Drain surviving sessions and connect with rotated secret
$writer_v2 = new wpdb( 'cc84_writer', 'cc84_rotated_v2_secret', 'wp_test', $host );
$writer_v2->suppress_errors( true );
$res_v2_auth = Plan::verify( array( 'cc84_b' => 15 ), $writer_v2 );
cc84_assert( 'PASS' === $res_v2_auth['overall'], '#84.8 rotated credentials verify PASS' );
cc84_assert( 'PASS' === $res_v2_auth['integrations']['cc84_b']['status'], '#84.8 B verified under rotated creds' );

// Verify secrets do not leak in Doctor reports or plan JSON
$report_json = json_encode( $res_v2_auth );
cc84_assert( false === strpos( $report_json, 'cc84_rotated_v2_secret' ), '#84.8 secret not in report' );
$plan_json = json_encode( $plan_rotate->get_steps( true ) );
cc84_assert( false === strpos( $plan_json, 'cc84_rotated_v2_secret' ), '#84.8 secret not in safe steps' );
echo "  #84.8 credential rotation, session draining and secret non-leakage: PASS\n";

// ---------------------------------------------------------------------------
// #84.9: Remove B and Full Uninstall
// ---------------------------------------------------------------------------
$plan_uninstall = Plan::uninstall( 'wp_test', 'cc84_writer', '%', array( 'cc84_b' ) );
$plan_uninstall->apply( $root );

// Both application tables cc84_a and cc84_b and their data SURVIVE intact
cc84_assert( 10 === (int) $root->get_var( 'SELECT COUNT(*) FROM cc84_a' ), '#84.9 cc84_a data survives uninstall' );
cc84_assert( 20 === (int) $root->get_var( 'SELECT COUNT(*) FROM cc84_b' ), '#84.9 cc84_b data survives uninstall' );

// CommitCap infrastructure objects are gone
$routines_left = (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'wp_test' AND ROUTINE_NAME LIKE 'commitcap_v01_%'" );
cc84_assert( 0 === $routines_left, '#84.9 all routines dropped' );

$state_tbl_left = (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'wp_test' AND TABLE_NAME = 'commitcap_v01_state'" );
cc84_assert( 0 === $state_tbl_left, '#84.9 helper table dropped' );

// User cc84_writer is dropped
$user_writer_test = new wpdb( 'cc84_writer', 'cc84_rotated_v2_secret', 'wp_test', $host );
$user_writer_test->suppress_errors( true );
cc84_assert( ! $user_writer_test->ready, '#84.9 runtime user dropped' );

// Clean fixture tables
cc84_query( $root, 'DROP TABLE cc84_a' );
cc84_query( $root, 'DROP TABLE cc84_b' );

echo "  #84.9 remove B, uninstall, and data preservation: PASS\n";
echo "--- End Gate #84 Provisioning Plan Tests ($host): ALL PASS ---\n";
