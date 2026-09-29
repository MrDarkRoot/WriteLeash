<?php
// Gate #84: Deterministic shared-runtime provisioning, rotation and cleanup plans.
// Proves version-pinned inspectable plans, apply/verify flow, credential rotation,
// scoped tamper probes, and safe application-table preservation across MySQL and MariaDB.

use WriteLeash\Compatibility_Doctor as Doctor;
use WriteLeash\Guard;
use WriteLeash\Provisioning_Plan as Plan;
use WriteLeash\Update_Engine as Engine;

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
$installer = new Engine( $root );
$is_mysql = 'mysql' === $host;

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
cc84_assert( false !== strpos( $config_snippet, "define( 'WRITELEASH_DB_USER', 'cc84_writer' );" ), '#84.1a config user' );
cc84_assert( false !== strpos( $config_snippet, "define( 'WRITELEASH_DB_PASSWORD', 'secret_pass_123' );" ), '#84.1a config pass' );

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
} catch ( \WriteLeash\Budget_Denied $e ) {
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
} catch ( \WriteLeash\Budget_Denied $e ) {
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
// #84.6b: Collision refusal for all destructive/replacement behavior. Foreign
// objects that merely share a WriteLeash name must never be overwritten.
// The suite restores the exact reviewed fixture before returning.
// ---------------------------------------------------------------------------
function cc84_reset_grants( $root ) {
	foreach ( array( 'open', 'close', 'count', 'policy', 'attest' ) as $r ) {
		cc84_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test.commitcap_v01_$r TO 'cc84_writer'@'%'" );
	}
	cc84_query( $root, "GRANT SELECT ON wp_test.commitcap_v01_state TO 'cc84_writer'@'%'" );
}

// (a) Foreign helper table shape refuses the install plan before any statement.
cc84_query( $root, 'DROP TABLE commitcap_v01_state' );
cc84_query( $root, 'CREATE TABLE commitcap_v01_state (id INT) ENGINE=InnoDB' );
$helper_refused = false;
try {
	Plan::install( 'wp_test', 'cc84_probe_user', '%', 'cc84_probe_secret' )->apply( $root );
} catch ( RuntimeException $e ) {
	$helper_refused = true;
	cc84_assert( false !== stripos( $e->getMessage(), 'helper' ), '#84.6b helper refusal message' );
}
cc84_assert( $helper_refused, '#84.6b foreign helper shape must refuse install' );
cc84_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='wp_test' AND TABLE_NAME='commitcap_v01_state'" ), '#84.6b foreign helper overwritten' );
cc84_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM mysql.user WHERE user='cc84_probe_user'" ), '#84.6b install created user after refusal' );
cc84_query( $root, 'DROP TABLE commitcap_v01_state' );

// (b) Foreign-bodied WriteLeash-named routine refuses install/replacement.
cc84_query( $root, 'DROP PROCEDURE commitcap_v01_count' );
cc84_query( $root, 'CREATE PROCEDURE commitcap_v01_count(IN p_policy CHAR(64) CHARACTER SET ascii COLLATE ascii_bin, OUT p_count BIGINT UNSIGNED) SQL SECURITY DEFINER BEGIN SET p_count = 999; END' );
$routine_refused = false;
try {
	Plan::install( 'wp_test', 'cc84_probe_user', '%', 'cc84_probe_secret' )->apply( $root );
} catch ( RuntimeException $e ) {
	$routine_refused = true;
	cc84_assert( false !== stripos( $e->getMessage(), 'foreign body' ), '#84.6b routine refusal message: ' . $e->getMessage() );
}
cc84_assert( $routine_refused, '#84.6b foreign-bodied routine must refuse install' );
cc84_assert( false !== strpos( (string) $root->get_var( "SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='wp_test' AND ROUTINE_NAME='commitcap_v01_count'" ), '999' ), '#84.6b foreign routine overwritten' );
cc84_query( $root, 'DROP PROCEDURE commitcap_v01_count' );

// (c) Pre-existing runtime account with unknown grants refuses install.
cc84_query( $root, "CREATE USER 'cc84_foreign_user'@'%' IDENTIFIED BY 'foreign_secret'" );
cc84_query( $root, "GRANT SELECT ON wp_test.cc84_a TO 'cc84_foreign_user'@'%'" );
$user_refused = false;
try {
	Plan::install( 'wp_test', 'cc84_foreign_user', '%', 'cc84_probe_secret' )->apply( $root );
} catch ( RuntimeException $e ) {
	$user_refused = true;
	cc84_assert( false !== stripos( $e->getMessage(), 'reviewed surface' ), '#84.6b user refusal message: ' . $e->getMessage() );
}
cc84_assert( $user_refused, '#84.6b unknown-grant account must refuse install' );
cc84_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = \"'cc84_foreign_user'@'%'\" AND TABLE_NAME='cc84_a' AND PRIVILEGE_TYPE='SELECT'" ), '#84.6b foreign user grants altered' );
cc84_query( $root, "DROP USER 'cc84_foreign_user'@'%'" );

// (d) Expected-named trigger with a foreign body refuses add_target/remove_target.
cc84_query( $root, 'DROP TABLE IF EXISTS cc84_conflict2' );
cc84_query( $root, 'CREATE TABLE cc84_conflict2 (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
$conflict2_trigger = Engine::trigger_name( 'cc84_conflict2' );
cc84_query( $root, "CREATE TRIGGER `$conflict2_trigger` BEFORE UPDATE ON cc84_conflict2 FOR EACH ROW SET @cc84_alien = 1" );
$expected_trigger_refused = false;
try {
	Plan::add_target( 'wp_test', 'cc84_writer', '%', 'cc84_conflict2', 10 )->apply( $root );
} catch ( RuntimeException $e ) {
	$expected_trigger_refused = true;
	cc84_assert( false !== stripos( $e->getMessage(), 'foreign body' ), '#84.6b expected-trigger refusal message: ' . $e->getMessage() );
}
cc84_assert( $expected_trigger_refused, '#84.6b foreign-bodied expected trigger must refuse add_target' );
$remove_refused = false;
try {
	Plan::remove_target( 'wp_test', 'cc84_writer', '%', 'cc84_conflict2', 10 )->apply( $root );
} catch ( RuntimeException $e ) {
	$remove_refused = true;
}
cc84_assert( $remove_refused, '#84.6b remove_target must refuse foreign-bodied expected trigger' );
cc84_assert( 1 === (int) $root->get_var( $root->prepare( "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='wp_test' AND TRIGGER_NAME=%s", $conflict2_trigger ) ), '#84.6b foreign trigger destroyed' );
cc84_assert( false !== strpos( (string) $root->get_var( $root->prepare( "SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='wp_test' AND TRIGGER_NAME=%s", $conflict2_trigger ) ), 'cc84_alien' ), '#84.6b foreign trigger body altered' );
cc84_query( $root, "DROP TRIGGER `$conflict2_trigger`" );
cc84_query( $root, 'DROP TABLE cc84_conflict2' );

// Restore all routines in one installation batch after destructive tamper
// fixtures; restoring only the missing member violates Doctor's 10s batch.
foreach ( Engine::routine_names() as $routine ) {
	cc84_query( $root, "DROP PROCEDURE IF EXISTS `$routine`" );
}
$installer->install_infrastructure();
cc84_reset_grants( $root );

// (e) Uninstall refuses when a WriteLeash-named routine has a foreign body.
cc84_query( $root, 'DROP PROCEDURE commitcap_v01_attest' );
cc84_query( $root, 'CREATE PROCEDURE commitcap_v01_attest() SQL SECURITY DEFINER BEGIN SELECT 1; END' );
$uninstall_refused = false;
try {
	Plan::uninstall( 'wp_test', 'cc84_writer', '%', array( 'cc84_b' ) )->apply( $root );
} catch ( RuntimeException $e ) {
	$uninstall_refused = true;
	cc84_assert( false !== stripos( $e->getMessage(), 'foreign body' ), '#84.6b uninstall refusal message: ' . $e->getMessage() );
}
cc84_assert( $uninstall_refused, '#84.6b foreign-bodied attest must refuse uninstall' );
foreach ( array( 'open', 'close', 'count', 'policy' ) as $routine ) {
	cc84_assert( 1 === (int) $root->get_var( $root->prepare( "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='wp_test' AND ROUTINE_NAME=%s", 'commitcap_v01_' . $routine ) ), '#84.6b uninstall dropped objects despite refusal' );
}
cc84_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='wp_test' AND TABLE_NAME='commitcap_v01_state'" ), '#84.6b uninstall dropped helper despite refusal' );
cc84_query( $root, 'DROP PROCEDURE commitcap_v01_attest' );
foreach ( Engine::routine_names() as $routine ) {
	cc84_query( $root, "DROP PROCEDURE IF EXISTS `$routine`" );
}
$installer->install_infrastructure();
cc84_reset_grants( $root );
echo "  #84.6b collision refusal (helper, routine, account, trigger, uninstall): PASS\n";

// ---------------------------------------------------------------------------
// #84.6c: the certified runtime username must map to exactly one mysql.user
// account row. The physical trigger is username-scoped, so a second Host row
// would make runtime identity ambiguous. Refuse before any policy trigger or
// target grant mutation.
// ---------------------------------------------------------------------------
cc84_query( $root, "DROP USER IF EXISTS 'cc84_ambig'@'%', 'cc84_ambig'@'localhost'" );
cc84_query( $root, 'DROP TABLE IF EXISTS cc84_ambig_t1, cc84_ambig_t2' );
cc84_query( $root, 'CREATE TABLE cc84_ambig_t1 (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
cc84_query( $root, 'CREATE TABLE cc84_ambig_t2 (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
cc84_query( $root, "CREATE USER 'cc84_ambig'@'%' IDENTIFIED BY 'cc84_ambig_secret'" );
foreach ( array( 'open', 'close', 'count', 'policy', 'attest' ) as $routine ) {
	cc84_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test.commitcap_v01_$routine TO 'cc84_ambig'@'%'" );
}
cc84_query( $root, "GRANT SELECT ON wp_test.commitcap_v01_state TO 'cc84_ambig'@'%'" );
Plan::add_target( 'wp_test', 'cc84_ambig', '%', 'cc84_ambig_t1', 5 )->apply( $root );

$ambig_writer = new wpdb( 'cc84_ambig', 'cc84_ambig_secret', 'wp_test', $host );
$ambig_writer->suppress_errors( true );
cc84_assert( 'PASS' === Doctor::run( 'cc84_ambig_t1', 5, $ambig_writer, $root )['overall'], '#84.6c unique account must certify before collision' );

// Collision: a second account row for the same certified username.
cc84_query( $root, "CREATE USER 'cc84_ambig'@'localhost' IDENTIFIED BY 'cc84_ambig_secret'" );
$ambig_add_refused = false;
try {
	Plan::add_target( 'wp_test', 'cc84_ambig', '%', 'cc84_ambig_t2', 5 )->apply( $root );
} catch ( RuntimeException $e ) {
	$ambig_add_refused = true;
	cc84_assert( false !== stripos( $e->getMessage(), 'ambiguous' ), '#84.6c add_target refusal message: ' . $e->getMessage() );
}
cc84_assert( $ambig_add_refused, '#84.6c add_target must refuse a same-username collision' );
$t2_trigger = Engine::trigger_name( 'cc84_ambig_t2' );
cc84_assert( 0 === (int) $root->get_var( $root->prepare( "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='wp_test' AND TRIGGER_NAME=%s", $t2_trigger ) ), '#84.6c collision installed a trigger' );
cc84_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = \"'cc84_ambig'@'%'\" AND TABLE_NAME='cc84_ambig_t2' AND PRIVILEGE_TYPE='SELECT'" ), '#84.6c collision mutated target grants' );

$ambig_remove_refused = false;
try {
	Plan::remove_target( 'wp_test', 'cc84_ambig', '%', 'cc84_ambig_t1', 5 )->apply( $root );
} catch ( RuntimeException $e ) {
	$ambig_remove_refused = true;
}
cc84_assert( $ambig_remove_refused, '#84.6c remove_target must refuse a same-username collision' );
$t1_trigger = Engine::trigger_name( 'cc84_ambig_t1' );
cc84_assert( 1 === (int) $root->get_var( $root->prepare( "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='wp_test' AND TRIGGER_NAME=%s", $t1_trigger ) ), '#84.6c removal dropped the trigger despite ambiguity' );

// Trusted verification reports the ambiguity explicitly.
$ambig_verify = Doctor::run( 'cc84_ambig_t1', 5, $ambig_writer, $root );
cc84_assert( 'PASS' !== $ambig_verify['overall'], '#84.6c ambiguous identity must not PASS' );
cc84_assert( 'FAIL' === $ambig_verify['integrations']['cc84_ambig_t1']['status'], '#84.6c ambiguous identity integration status: ' . json_encode( $ambig_verify['integrations'] ) );

// Removing the collision restores deterministic retry.
cc84_query( $root, "DROP USER 'cc84_ambig'@'localhost'" );
Plan::add_target( 'wp_test', 'cc84_ambig', '%', 'cc84_ambig_t2', 5 )->apply( $root );
$ambig_retry = Doctor::run( 'cc84_ambig_t1', 5, $ambig_writer, $root, array( 'cc84_ambig_t2' => 5 ) );
cc84_assert( 'PASS' === $ambig_retry['overall'], '#84.6c retry after collision removal must PASS: ' . json_encode( $ambig_retry ) );

// Cleanup this fixture only; shared infrastructure stays installed.
Plan::remove_target( 'wp_test', 'cc84_ambig', '%', 'cc84_ambig_t1', 5 )->apply( $root );
Plan::remove_target( 'wp_test', 'cc84_ambig', '%', 'cc84_ambig_t2', 5 )->apply( $root );
cc84_query( $root, "DROP USER 'cc84_ambig'@'%'" );
cc84_query( $root, 'DROP TABLE cc84_ambig_t1' );
cc84_query( $root, 'DROP TABLE cc84_ambig_t2' );
echo "  #84.6c same-username account collision refused before policy trigger or grant mutation: PASS\n";

// ---------------------------------------------------------------------------
// #84.6d: rotate_credential( ..., false ) must also refuse before ALTER USER
// when the certified username is ambiguous. Credential, session, trigger and
// helper state must all survive unchanged, and the retry after removing the
// collision must install the new secret without any trigger DDL.
// ---------------------------------------------------------------------------
cc84_query( $root, "DROP USER IF EXISTS 'cc84_rot_ambig'@'%', 'cc84_rot_ambig'@'localhost'" );
cc84_query( $root, 'DROP TABLE IF EXISTS cc84_rot_tbl' );
cc84_query( $root, 'CREATE TABLE cc84_rot_tbl (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
cc84_query( $root, "CREATE USER 'cc84_rot_ambig'@'%' IDENTIFIED BY 'rot_v1_secret'" );
foreach ( array( 'open', 'close', 'count', 'policy', 'attest' ) as $routine ) {
	cc84_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test.commitcap_v01_$routine TO 'cc84_rot_ambig'@'%'" );
}
cc84_query( $root, "GRANT SELECT ON wp_test.commitcap_v01_state TO 'cc84_rot_ambig'@'%'" );
Plan::add_target( 'wp_test', 'cc84_rot_ambig', '%', 'cc84_rot_tbl', 5 )->apply( $root );

$rot_v1 = new wpdb( 'cc84_rot_ambig', 'rot_v1_secret', 'wp_test', $host );
$rot_v1->suppress_errors( true );
$rot_v1_id = (int) $rot_v1->get_var( 'SELECT CONNECTION_ID()' );
$rot_trig = Engine::trigger_name( 'cc84_rot_tbl' );
$rot_trig_before = (string) $root->get_var( $root->prepare(
	'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s',
	$rot_trig
) );
$rot_helper_before = (int) $root->get_var( 'SELECT COUNT(*) FROM commitcap_v01_state' );
$rot_hash_before = (string) $root->get_var( "SELECT authentication_string FROM mysql.user WHERE User = 'cc84_rot_ambig' AND Host = '%'" );

cc84_query( $root, "CREATE USER 'cc84_rot_ambig'@'localhost' IDENTIFIED BY 'rot_collision_secret'" );
$rot_collision_hash = (string) $root->get_var( "SELECT authentication_string FROM mysql.user WHERE User = 'cc84_rot_ambig' AND Host = 'localhost'" );
$rot_refused = false;
try {
	Plan::rotate_credential( 'cc84_rot_ambig', '%', 'rot_v2_secret', false )->apply( $root );
} catch ( RuntimeException $e ) {
	$rot_refused = true;
	cc84_assert( false !== stripos( $e->getMessage(), 'ambiguous' ), '#84.6d no-drain rotation refusal message: ' . $e->getMessage() );
}
cc84_assert( $rot_refused, '#84.6d rotate_credential(..., false) must refuse a same-username collision' );

// Old credential still authenticates to the '%' account.
$rot_v1_again = new wpdb( 'cc84_rot_ambig', 'rot_v1_secret', 'wp_test', $host );
$rot_v1_again->suppress_errors( true );
cc84_assert( 1 === (int) $rot_v1_again->get_var( 'SELECT 1' ), '#84.6d failed rotation changed the certified account secret' );
cc84_assert( $rot_hash_before === (string) $root->get_var( "SELECT authentication_string FROM mysql.user WHERE User = 'cc84_rot_ambig' AND Host = '%'" ), '#84.6d credential hash changed' );
// New secret does not authenticate.
$rot_v2_try = new wpdb( 'cc84_rot_ambig', 'rot_v2_secret', 'wp_test', $host );
$rot_v2_try->suppress_errors( true );
cc84_assert( ! $rot_v2_try->ready, '#84.6d new secret became usable despite refusal' );
// Other Host account credential unchanged (an account row for the alias host
// would only match a localhost/socket connection, not this TCP fixture).
cc84_assert( $rot_collision_hash === (string) $root->get_var( "SELECT authentication_string FROM mysql.user WHERE User = 'cc84_rot_ambig' AND Host = 'localhost'" ), '#84.6d collision account changed' );
// No session drain: the surviving V1 session is untouched.
cc84_assert( 1 === (int) $rot_v1->get_var( 'SELECT 1' ), '#84.6d refusal terminated the existing session' );
cc84_assert( 1 === (int) $root->get_var( $root->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = %d', $rot_v1_id ) ), '#84.6d refusal killed a session' );
// Trigger body, helper state and rotation marker unchanged.
$rot_trig_after = (string) $root->get_var( $root->prepare(
	'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s',
	$rot_trig
) );
cc84_assert( $rot_trig_before === $rot_trig_after, '#84.6d refusal changed the policy trigger' );
cc84_assert( $rot_helper_before === (int) $root->get_var( 'SELECT COUNT(*) FROM commitcap_v01_state' ), '#84.6d refusal changed helper state' );
cc84_assert( 0 === (int) $root->get_var( 'SELECT COUNT(*) FROM commitcap_v01_state WHERE connection_id = 0' ), '#84.6d refusal created a rotation marker' );

// Remove the collision; the same plan shape must now succeed without DDL.
cc84_query( $root, "DROP USER 'cc84_rot_ambig'@'localhost'" );
Plan::rotate_credential( 'cc84_rot_ambig', '%', 'rot_v2_secret', false )->apply( $root );
$rot_v1_new = new wpdb( 'cc84_rot_ambig', 'rot_v1_secret', 'wp_test', $host );
$rot_v1_new->suppress_errors( true );
cc84_assert( ! $rot_v1_new->ready, '#84.6d old secret still authenticates after retry' );
$rot_v2 = new wpdb( 'cc84_rot_ambig', 'rot_v2_secret', 'wp_test', $host );
$rot_v2->suppress_errors( true );
cc84_assert( 1 === (int) $rot_v2->get_var( 'SELECT 1' ), '#84.6d retry did not install V2' );
$rot_trig_retry = (string) $root->get_var( $root->prepare(
	'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s',
	$rot_trig
) );
cc84_assert( $rot_trig_before === $rot_trig_retry, '#84.6d rotation required trigger DDL' );
// The no-drain retry is diagnostic-only and correctly leaves ROTATED_UNSAFE;
// completing the supported lifecycle needs the standalone drain. The drain
// terminates the V2 session too, so a fresh connection follows it.
$rot_unsafe = Doctor::run( 'cc84_rot_tbl', 5, $rot_v2, $root );
cc84_assert( 'PASS' !== $rot_unsafe['overall'], '#84.6d no-drain retry must not report READY' );
Plan::drain( 'cc84_rot_ambig', '%' )->apply( $root );
$rot_v2 = new wpdb( 'cc84_rot_ambig', 'rot_v2_secret', 'wp_test', $host );
$rot_v2->suppress_errors( true );
$rot_verify = Doctor::run( 'cc84_rot_tbl', 5, $rot_v2, $root );
cc84_assert( 'PASS' === $rot_verify['overall'], '#84.6d rotated runtime not READY after drain: ' . json_encode( $rot_verify ) );
cc84_query( $root, 'INSERT INTO cc84_rot_tbl (id, touched) VALUES (1, 0), (2, 0)' );
$rot_guarded = Guard::update( 'cc84_rot_tbl', 2, function () use ( $rot_v2 ) {
	cc84_query( $rot_v2, 'UPDATE cc84_rot_tbl SET touched = touched + 1 WHERE id <= 2' );
	return 'rot-guarded';
}, $rot_v2 );
cc84_assert( 'rot-guarded' === $rot_guarded, '#84.6d rotated runtime did not enforce' );
$rot_denied = false;
try {
	Guard::update( 'cc84_rot_tbl', 0, function () use ( $rot_v2 ) {
		$rot_v2->query( 'UPDATE cc84_rot_tbl SET touched = touched + 1 WHERE id = 1' );
	}, $rot_v2 );
} catch ( \WriteLeash\Budget_Denied $e ) {
	$rot_denied = true;
}
cc84_assert( $rot_denied, '#84.6d rotated runtime lost budget enforcement' );

// Cleanup this fixture only.
Plan::remove_target( 'wp_test', 'cc84_rot_ambig', '%', 'cc84_rot_tbl', 5 )->apply( $root );
cc84_query( $root, "DROP USER 'cc84_rot_ambig'@'%'" );
cc84_query( $root, 'DROP TABLE cc84_rot_tbl' );
echo "  #84.6d no-drain rotation refuses same-username collision before ALTER USER and retries without trigger DDL: PASS\n";

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
// #84.8: Credential Rotation & Deterministic Session Draining
// ---------------------------------------------------------------------------
// A connection that authenticated BEFORE ALTER USER survives the rotation on
// both pinned engines. Rotation alone is NOT draining; this test demonstrates
// the surviving authority and the supported drain action that removes it.
$survivor = new wpdb( 'cc84_writer', 'cc84_initial_secret', 'wp_test', $host );
$survivor->suppress_errors( true );
$survivor_id = (int) $survivor->get_var( 'SELECT CONNECTION_ID()' );
cc84_assert( $survivor_id > 0, '#84.8 survivor session did not connect' );

// Password rotation must not invalidate the identity-scoped policy trigger.
$b_trigger = Engine::trigger_name( 'cc84_b' );
$trigger_before_rotation = $root->get_var( $root->prepare(
	'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s',
	$b_trigger
) );
cc84_assert( is_string( $trigger_before_rotation ), '#84.8 policy trigger missing before rotation' );

$plan_rotate = Plan::rotate_credential( 'cc84_writer', '%', 'cc84_rotated_v2_secret', false );
cc84_assert( 1 === count( $plan_rotate->get_steps( true ) ), '#84.8 no-drain rotate must have exactly one step' );
$plan_rotate->apply( $root );

// New connection attempt with old secret FAILS
$old_writer = new wpdb( 'cc84_writer', 'cc84_initial_secret', 'wp_test', $host );
$old_writer->suppress_errors( true );
$res_old_auth = Plan::verify( array( 'cc84_b' => 15 ), $old_writer );
cc84_assert( 'FAIL' === $res_old_auth['overall'], '#84.8 old credentials must fail for new connections' );

// The already-authenticated V1 session still holds authority after ALTER USER.
$survived = $survivor->get_var( 'SELECT 1' );
cc84_assert( 1 === (int) $survived, '#84.8 pre-existing session lost authority without a drain (unexpected on this engine)' );

// Supported drain: terminate every surviving session and verify none remain.
$drain_plan = Plan::drain( 'cc84_writer', '%' );
cc84_assert( Plan::ACTION_DRAIN === $drain_plan->get_action(), '#84.8 drain action' );
$drain_result = $drain_plan->apply( $root );
cc84_assert( $drain_result['success'], '#84.8 drain apply success' );

// The surviving V1 connection is now gone: its raw server session is dead.
// A direct mysqli probe avoids wpdb's auto-reconnect (which would only hide
// the drained authority behind a new rejected connection attempt).
$survivor_dead = false;
if ( $survivor->dbh instanceof mysqli ) {
	$survivor_dead = false === @mysqli_query( $survivor->dbh, 'SELECT 1' );
}
cc84_assert( $survivor_dead, '#84.8 drained session still executes SQL' );
$process_gone = (int) $root->get_var( $root->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = %d', $survivor_id ) );
cc84_assert( 0 === $process_gone, '#84.8 drained session still present in PROCESSLIST' );

// Rotated V2 connection passes Doctor and Guard still enforces on it.
$writer_v2 = new wpdb( 'cc84_writer', 'cc84_rotated_v2_secret', 'wp_test', $host );
$writer_v2->suppress_errors( true );
$res_v2_auth = Plan::verify( array( 'cc84_b' => 15 ), $writer_v2 );
cc84_assert( 'PASS' === $res_v2_auth['overall'], '#84.8 rotated credentials verify PASS: ' . json_encode( $res_v2_auth ) );
cc84_assert( 'PASS' === $res_v2_auth['integrations']['cc84_b']['status'], '#84.8 B verified under rotated creds' );
$v2_committed = Guard::update(
	'cc84_b',
	15,
	function () use ( $writer_v2 ) {
		for ( $i = 1; $i <= 5; ++$i ) {
			cc84_query( $writer_v2, "UPDATE cc84_b SET touched = touched + 1 WHERE id = $i" );
		}
		return 'v2-committed';
	},
	$writer_v2
);
cc84_assert( 'v2-committed' === $v2_committed, '#84.8 Guard enforcement under rotated credentials' );

// The identity-scoped trigger body is untouched by a password-only rotation,
// and the same runtime username is still physically enforced after rotation.
$trigger_after_rotation = $root->get_var( $root->prepare(
	'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s',
	$b_trigger
) );
cc84_assert( $trigger_before_rotation === $trigger_after_rotation, '#84.8 password rotation rewrote the policy trigger' );
$rotated_unguarded = $writer_v2->query( 'UPDATE cc84_b SET touched = 999 WHERE id = 1' );
cc84_assert( false === $rotated_unguarded && false !== strpos( (string) $writer_v2->last_error, 'CC54_DENIED' ), '#84.8 rotated runtime identity is no longer physically enforced' );
$writer_v2->query( 'SELECT 1' );
$writer = $writer_v2;

// Verify secrets do not leak in Doctor reports or plan JSON.
$report_json = json_encode( $res_v2_auth );
cc84_assert( false === strpos( $report_json, 'cc84_rotated_v2_secret' ), '#84.8 secret not in report' );
cc84_assert( false === strpos( json_encode( $plan_rotate->get_steps( true ) ), 'cc84_rotated_v2_secret' ), '#84.8 secret not in safe steps' );
cc84_assert( false === strpos( $plan_rotate->render_sql( true ), 'cc84_rotated_v2_secret' ), '#84.8 secret not in redacted render' );
echo "  #84.8 credential rotation, surviving-session proof and deterministic drain: PASS\n";

// ---------------------------------------------------------------------------
// #84.8b: Secret leakage in failure paths
// ---------------------------------------------------------------------------
$sentinel = 'CC84_SENTINEL_SECRET_9f3a';
$log_file = sys_get_temp_dir() . '/cc84-error-log-' . getmypid() . '.txt';
@unlink( $log_file );
$previous_log = ini_get( 'error_log' );
ini_set( 'error_log', $log_file );

$leaked = false;
$failure_message = '';
$fail_plan = Plan::rotate_credential( 'cc84_absent_user', '%', $sentinel, false );
try {
	$fail_plan->apply( $root );
} catch ( RuntimeException $e ) {
	$failure_message = $e->getMessage();
}
cc84_assert( '' !== $failure_message, '#84.8b failing credential DDL must throw' );
$leaked = $leaked || false !== strpos( $failure_message, $sentinel );
$leaked = $leaked || false !== strpos( (string) $fail_plan->render_sql( true ), $sentinel );
$leaked = $leaked || false !== strpos( json_encode( $fail_plan->get_steps( true ) ), $sentinel );
$leaked = $leaked || false !== strpos( json_encode( $fail_plan->get_params() ), $sentinel );
if ( file_exists( $log_file ) ) {
	$leaked = $leaked || false !== strpos( (string) file_get_contents( $log_file ), $sentinel );
}
ini_set( 'error_log', (string) $previous_log );
@unlink( $log_file );
cc84_assert( ! $leaked, '#84.8b raw secret leaked in failure path' );
// An absent account is now refused by the unconditional identity preflight
// before any credential DDL. The withheld-credential-SQL failure path itself
// stays exercised by #84.10c (identity preflight passes, ALTER is denied).
cc84_assert( false !== stripos( $failure_message, 'Runtime identity is ambiguous' ), '#84.8b absent account must be refused before credential DDL: ' . $failure_message );

// Successful apply results and default rendered plans carry no raw secret.
$ok_rotate = Plan::rotate_credential( 'cc84_writer', '%', 'cc84_ok_secret_2', false );
$ok_result = $ok_rotate->apply( $root );
cc84_assert( false === strpos( json_encode( $ok_result ), 'cc84_ok_secret_2' ), '#84.8b secret leaked in apply result' );
cc84_assert( false === strpos( $ok_rotate->render_sql( true ), 'cc84_ok_secret_2' ), '#84.8b secret leaked in default plan render' );
cc84_assert( false !== strpos( $ok_rotate->render_sql( false ), 'cc84_ok_secret_2' ), '#84.8b explicit raw render must be the only raw output' );
$rotate_back = Plan::rotate_credential( 'cc84_writer', '%', 'cc84_rotated_v2_secret', false );
$rotate_back->apply( $root );
$writer = new wpdb( 'cc84_writer', 'cc84_rotated_v2_secret', 'wp_test', $host );
$writer->suppress_errors( true );
echo "  #84.8b credential failure paths and default plans never expose the raw secret: PASS\n";

// Supported single-plan rotate+drain on the real certified B runtime (A-F).
$live_v1 = new wpdb( 'cc84_writer', 'cc84_rotated_v2_secret', 'wp_test', $host );
$live_v1->suppress_errors( true );
$live_v1_id = (int) $live_v1->get_var( 'SELECT CONNECTION_ID()' );
$supported = Plan::rotate_credential( 'cc84_writer', '%', 'cc84_supported_v3', true )->apply( $root );
cc84_assert( $supported['success'] && 'DRAINED' === $supported['state'], '#84.8 supported rotation not drained' );
cc84_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = $live_v1_id" ), '#84.8 V1 still alive' );
cc84_assert( false === @mysqli_query( $live_v1->dbh, 'SELECT 1' ), '#84.8 V1 can still query after supported drain' );
$v3_writer = new wpdb( 'cc84_writer', 'cc84_supported_v3', 'wp_test', $host );
$v3_writer->suppress_errors( true );
cc84_assert( 'PASS' === Plan::verify( array( 'cc84_b' => 15 ), $v3_writer )['overall'], '#84.8 V2 Doctor not PASS after supported drain' );
$v3_denied = false;
try {
	Guard::update( 'cc84_b', 0, function () use ( $v3_writer ) {
		$v3_writer->query( 'UPDATE cc84_b SET touched = touched + 1 WHERE id = 1' );
	}, $v3_writer );
} catch ( \WriteLeash\Budget_Denied $error ) {
	$v3_denied = true;
}
cc84_assert( $v3_denied, '#84.8 V2 Guard did not enforce after supported drain' );
Plan::rotate_credential( 'cc84_writer', '%', 'cc84_rotated_v2_secret', true )->apply( $root );
$writer = new wpdb( 'cc84_writer', 'cc84_rotated_v2_secret', 'wp_test', $host );
$writer->suppress_errors( true );
echo "  #84.8 supported rotate+drain with V1 invalidation, V2 Doctor and Guard: PASS\n";

class CC84_Kill_Fault_DB extends wpdb {
	public $fail_kill = true;
	public function query( $query ) {
		if ( $this->fail_kill && preg_match( '/\AKILL [0-9]+\z/', $query ) ) {
			return false;
		}
		return parent::query( $query );
	}
}
$faulty = new CC84_Kill_Fault_DB( 'root', 'disposable_root_password', 'wp_test', $host );
$faulty->suppress_errors( true );
$unsafe_v1_id = (int) $writer->get_var( 'SELECT CONNECTION_ID()' );
$unsafe_error = '';
try {
	Plan::rotate_credential( 'cc84_writer', '%', 'cc84_unsafe_v4', true )->apply( $faulty );
} catch ( RuntimeException $error ) {
	$unsafe_error = $error->getMessage();
}
cc84_assert( false !== strpos( $unsafe_error, 'ROTATED_UNSAFE' ), '#84.8 post-ALTER KILL failure must be explicit' );
cc84_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = $unsafe_v1_id" ), '#84.8 V1 no longer surviving KILL failure' );
$unsafe_v2 = new wpdb( 'cc84_writer', 'cc84_unsafe_v4', 'wp_test', $host );
$unsafe_v2->suppress_errors( true );
$unsafe_report = Plan::verify( array( 'cc84_b' => 15 ), $unsafe_v2 );
cc84_assert( 'FAIL' === $unsafe_report['overall'] && 'UNKNOWN' === $unsafe_report['integrations']['cc84_b']['status'], '#84.8 unsafe rotation Doctor claimed READY' );
$ran_unsafe = false;
try {
	Guard::update( 'cc84_b', 5, function () use ( &$ran_unsafe ) { $ran_unsafe = true; }, $unsafe_v2 );
} catch ( \WriteLeash\Guard_Error $error ) {
	cc84_assert( 'policy_unverified' === $error->reason(), '#84.8 unsafe Guard refusal reason' );
}
cc84_assert( ! $ran_unsafe, '#84.8 Guard ran callback despite unsafe rotation' );
Plan::drain( 'cc84_writer', '%' )->apply( $root );
$recovered = new wpdb( 'cc84_writer', 'cc84_unsafe_v4', 'wp_test', $host );
$recovered->suppress_errors( true );
cc84_assert( 'PASS' === Plan::verify( array( 'cc84_b' => 15 ), $recovered )['overall'], '#84.8 trusted recovery drain did not restore Doctor' );
Plan::rotate_credential( 'cc84_writer', '%', 'cc84_rotated_v2_secret', true )->apply( $root );
$writer = new wpdb( 'cc84_writer', 'cc84_rotated_v2_secret', 'wp_test', $host );
$writer->suppress_errors( true );
echo "  #84.8 post-ALTER KILL fault blocks V2 Doctor/Guard until standalone drain: PASS\n";

// ---------------------------------------------------------------------------
// #84.8c: adversarial drain matrix. PROCESS is visibility, not KILL authority;
// PROCESSLIST.HOST is the client address, not the matched mysql.user.Host.
// All preflight refusals must leave V1 credentials AND sessions unchanged.
// ---------------------------------------------------------------------------
cc84_query( $root, "DROP USER IF EXISTS 'cc84_drain'@'%', 'cc84_drain'@'localhost', 'cc84_drain_x'@'%'" );
cc84_query( $root, "CREATE USER 'cc84_drain'@'%' IDENTIFIED BY 'drain_v1'" );
cc84_query( $root, "CREATE USER 'cc84_drain_x'@'%' IDENTIFIED BY 'unrelated_v1'" );
cc84_query( $root, "GRANT SELECT ON wp_test.cc84_b TO 'cc84_drain'@'%', 'cc84_drain_x'@'%'" );
$victim = new wpdb( 'cc84_drain', 'drain_v1', 'wp_test', $host );
$unrelated = new wpdb( 'cc84_drain_x', 'unrelated_v1', 'wp_test', $host );
$victim->suppress_errors( true );
$unrelated->suppress_errors( true );
$victim_id = (int) $victim->get_var( 'SELECT CONNECTION_ID()' );
$unrelated_id = (int) $unrelated->get_var( 'SELECT CONNECTION_ID()' );
cc84_assert( $victim_id > 0 && $unrelated_id > 0, '#84.8c session setup' );
$initial_hash = (string) $root->get_var( "SELECT authentication_string FROM mysql.user WHERE User='cc84_drain' AND Host='%'" );

function cc84_preflight_unchanged( $root, $host, $operator, $label, $victim_id, $unrelated_id, $initial_hash ) {
	$failure = '';
	try {
		Plan::rotate_credential( 'cc84_drain', '%', 'should_not_install', true )->apply( $operator );
	} catch ( RuntimeException $error ) {
		$failure = $error->getMessage();
	}
	cc84_assert( '' !== $failure && false === strpos( $failure, 'ROTATED_UNSAFE' ), "$label must refuse during PRECHECK" );
	$old = new wpdb( 'cc84_drain', 'drain_v1', 'wp_test', $host );
	$old->suppress_errors( true );
	cc84_assert( 1 === (int) $old->get_var( 'SELECT 1' ), "$label changed V1 password" );
	$new = new wpdb( 'cc84_drain', 'should_not_install', 'wp_test', $host );
	$new->suppress_errors( true );
	cc84_assert( ! $new->ready, "$label installed V2 despite preflight refusal" );
	cc84_assert( $initial_hash === (string) $root->get_var( "SELECT authentication_string FROM mysql.user WHERE User='cc84_drain' AND Host='%'" ), "$label mutated credential hash" );
	cc84_assert( 0 === (int) $root->get_var( 'SELECT COUNT(*) FROM commitcap_v01_state WHERE connection_id = 0' ), "$label left partial rotation marker state" );
	cc84_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = $victim_id" ), "$label killed existing V1 session" );
	cc84_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = $unrelated_id" ), "$label killed unrelated session" );
}

foreach ( array( 'none', 'process', 'super', 'connection' ) as $mode ) {
	if ( 'connection' === $mode && ! $is_mysql ) {
		// MariaDB CONNECTION ADMIN is exercised separately below as an alternative to SUPER.
	}
	$user = 'cc84_op_' . $mode;
	cc84_query( $root, "DROP USER IF EXISTS '$user'@'%'" );
	cc84_query( $root, "CREATE USER '$user'@'%' IDENTIFIED BY 'op_secret'" );
	// The operator has real ALTER USER capability, so only the drain precheck
	// can prevent a credential change. SELECT mysql.user enables identity check.
	cc84_query( $root, "GRANT CREATE USER ON *.* TO '$user'@'%'" );
	cc84_query( $root, "GRANT SELECT ON mysql.user TO '$user'@'%'" );
	cc84_query( $root, "GRANT SELECT ON wp_test.cc84_b TO '$user'@'%'" );
	if ( 'process' === $mode ) {
		cc84_query( $root, "GRANT PROCESS ON *.* TO '$user'@'%'" );
	} elseif ( 'super' === $mode ) {
		cc84_query( $root, "GRANT SUPER ON *.* TO '$user'@'%'" );
	} elseif ( 'connection' === $mode ) {
		cc84_query( $root, "GRANT " . ( $is_mysql ? 'CONNECTION_ADMIN' : 'CONNECTION ADMIN' ) . " ON *.* TO '$user'@'%'" );
	}
	$op = new wpdb( $user, 'op_secret', 'wp_test', $host );
	$op->suppress_errors( true );
	cc84_assert( $op->ready, "$mode operator login" );
	// Prove raw cross-user KILL behavior on a separate sacrificial connection,
	// without ever touching the real V1 session in the negative matrix.
	$sacrifice = new wpdb( 'cc84_drain_x', 'unrelated_v1', 'wp_test', $host );
	$sacrifice->suppress_errors( true );
	$sacrifice_id = (int) $sacrifice->get_var( 'SELECT CONNECTION_ID()' );
	$visible = $op->get_col( $op->prepare( 'SELECT ID FROM information_schema.PROCESSLIST WHERE ID = %d', $sacrifice_id ) );
	$kill_result = $op->query( 'KILL ' . $sacrifice_id );
	cc84_assert( ( 'process' === $mode || 'none' === $mode ) === ( false === $kill_result ), "$mode raw cross-user KILL outcome" );
	if ( false !== $kill_result ) {
		cc84_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = $sacrifice_id" ), "$mode raw KILL did not terminate" );
	}
	if ( 'process' === $mode || 'none' === $mode ) {
		cc84_assert( ( 'process' === $mode ) === in_array( (string) $sacrifice_id, array_map( 'strval', (array) $visible ), true ), "$mode processlist visibility" );
	}
	cc84_preflight_unchanged( $root, $host, $op, $mode, $victim_id, $unrelated_id, $initial_hash );
	echo "    $mode only: raw cross-user KILL " . ( false === $kill_result ? 'DENIED' : 'SUCCEEDED' ) . ', victim visible=' . ( in_array( (string) $sacrifice_id, array_map( 'strval', (array) $visible ), true ) ? 'yes' : 'no' ) . "; pre-ALTER rotation refusal PASS\n";
	cc84_query( $root, "DROP USER '$user'@'%'" );
}

// Account collision: even root must refuse before ALTER and without killing
// any session. Client HOST cannot identify which of the two account rows won.
cc84_query( $root, "CREATE USER 'cc84_drain'@'localhost' IDENTIFIED BY 'collision_secret'" );
$collision_hash = (string) $root->get_var( "SELECT authentication_string FROM mysql.user WHERE User='cc84_drain' AND Host='localhost'" );
cc84_preflight_unchanged( $root, $host, $root, 'same-username host collision', $victim_id, $unrelated_id, $initial_hash );
cc84_assert( $collision_hash === (string) $root->get_var( "SELECT authentication_string FROM mysql.user WHERE User='cc84_drain' AND Host='localhost'" ), '#84.8c collision account credential changed' );
cc84_query( $root, "DROP USER 'cc84_drain'@'localhost'" );

// Combined privileges, on BOTH engines, actually rotate + drain; a similar
// username is untouched. The pinned-engine matrix proves preflight and KILL.
cc84_query( $root, "CREATE USER 'cc84_op_combined'@'%' IDENTIFIED BY 'op_secret'" );
cc84_query( $root, "GRANT CREATE USER, PROCESS ON *.* TO 'cc84_op_combined'@'%'" );
cc84_query( $root, "GRANT SELECT ON mysql.user TO 'cc84_op_combined'@'%'" );
cc84_query( $root, "GRANT SELECT ON wp_test.cc84_b TO 'cc84_op_combined'@'%'" );
cc84_query( $root, "GRANT SELECT, INSERT, UPDATE, DELETE ON wp_test.commitcap_v01_state TO 'cc84_op_combined'@'%'" );
cc84_query( $root, "GRANT " . ( $is_mysql ? 'CONNECTION_ADMIN' : 'CONNECTION ADMIN' ) . " ON *.* TO 'cc84_op_combined'@'%'" );
$combined = new wpdb( 'cc84_op_combined', 'op_secret', 'wp_test', $host );
$combined->suppress_errors( true );
$combined_result = Plan::rotate_credential( 'cc84_drain', '%', 'drain_v2', true )->apply( $combined );
cc84_assert( $combined_result['success'] && 'DRAINED' === $combined_result['state'], '#84.8c combined operator did not drain' );
cc84_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = $victim_id" ), '#84.8c V1 survived combined drain' );
cc84_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = $unrelated_id" ), '#84.8c similar username was killed' );
$v2 = new wpdb( 'cc84_drain', 'drain_v2', 'wp_test', $host );
$v2->suppress_errors( true );
cc84_assert( 1 === (int) $v2->get_var( 'SELECT 1' ), '#84.8c V2 auth failed' );
$v1 = new wpdb( 'cc84_drain', 'drain_v1', 'wp_test', $host );
$v1->suppress_errors( true );
cc84_assert( ! $v1->ready, '#84.8c V1 new auth still works' );

// Fault-inject an actual KILL failure AFTER real ALTER USER. This is an unsafe
// partial state even if V2 could authenticate; rerun drain rather than rotate.
$faulty = new CC84_Kill_Fault_DB( 'root', 'disposable_root_password', 'wp_test', $host );
$faulty->suppress_errors( true );
$v2_id = (int) $v2->get_var( 'SELECT CONNECTION_ID()' );
$post_failure = '';
try {
	Plan::rotate_credential( 'cc84_drain', '%', 'drain_v3', true )->apply( $faulty );
} catch ( RuntimeException $error ) {
	$post_failure = $error->getMessage();
}
cc84_assert( false !== strpos( $post_failure, 'ROTATED_UNSAFE' ) && false !== strpos( $post_failure, 'old authenticated sessions may still exist' ), '#84.8c post-ALTER failure state/recovery absent' );
cc84_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = $v2_id" ), '#84.8c fault injection killed V2 survivor' );
$v3 = new wpdb( 'cc84_drain', 'drain_v3', 'wp_test', $host );
$v3->suppress_errors( true );
cc84_assert( 1 === (int) $v3->get_var( 'SELECT 1' ), '#84.8c ALTER did not execute before fault' );
cc84_assert( 'DRAINED' === Plan::drain( 'cc84_drain', '%' )->apply( $root )['state'], '#84.8c standalone recovery drain failed' );
cc84_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = $v2_id" ), '#84.8c recovery left stale V2 session' );
cc84_query( $root, "DROP USER 'cc84_op_combined'@'%', 'cc84_drain'@'%', 'cc84_drain_x'@'%'" );
echo "  #84.8c drain privilege matrix, account collision, combined operator and post-ALTER recovery: PASS\n";

// ---------------------------------------------------------------------------
// #84.9: Remove B and Full Uninstall
// ---------------------------------------------------------------------------
$plan_uninstall = Plan::uninstall( 'wp_test', 'cc84_writer', '%', array( 'cc84_b' ) );
$plan_uninstall->apply( $root );

// Both application tables cc84_a and cc84_b and their data SURVIVE intact
cc84_assert( 10 === (int) $root->get_var( 'SELECT COUNT(*) FROM cc84_a' ), '#84.9 cc84_a data survives uninstall' );
cc84_assert( 20 === (int) $root->get_var( 'SELECT COUNT(*) FROM cc84_b' ), '#84.9 cc84_b data survives uninstall' );

// WriteLeash infrastructure objects are gone
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

// ---------------------------------------------------------------------------
// #84.10: Partial-failure recovery. DDL/DCL is auto-commit: a plan can stop
// halfway. Every partial state must be clearly detected (no PASS), and the
// version-pinned plan must replay deterministically to completion.
// ---------------------------------------------------------------------------
$is_mysql = 'mysql' === $host;

// --- A: install fails after creating the account and helper table ----------
cc84_query( $root, "DROP USER IF EXISTS 'cc84_op_install'@'%'" );
cc84_query( $root, "CREATE USER 'cc84_op_install'@'%' IDENTIFIED BY 'cc84_op_secret'" );
cc84_query( $root, "GRANT CREATE, SELECT ON wp_test.* TO 'cc84_op_install'@'%'" );
cc84_query( $root, "GRANT CREATE USER ON *.* TO 'cc84_op_install'@'%'" );
cc84_query( $root, "GRANT SELECT ON mysql.* TO 'cc84_op_install'@'%'" );
$op_install = new wpdb( 'cc84_op_install', 'cc84_op_secret', 'wp_test', $host );
$op_install->suppress_errors( true );

$install_failure = '';
try {
	Plan::install( 'wp_test', 'cc84_partial_user', '%', 'cc84_partial_secret' )->apply( $op_install );
} catch ( RuntimeException $e ) {
	$install_failure = $e->getMessage();
}
cc84_assert( '' !== $install_failure, '#84.10a install with missing CREATE ROUTINE must fail' );
cc84_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM mysql.user WHERE user='cc84_partial_user'" ), '#84.10a account not created before failure' );
cc84_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='wp_test' AND TABLE_NAME='commitcap_v01_state'" ), '#84.10a helper not created before failure' );
$partial_writer = new wpdb( 'cc84_partial_user', 'cc84_partial_secret', 'wp_test', $host );
$partial_writer->suppress_errors( true );
$partial_verify = Plan::verify( array(), $partial_writer );
cc84_assert( 'PASS' !== $partial_verify['overall'], '#84.10a partial install incorrectly PASSed' );
$partial_ev = null;
foreach ( $partial_verify['checks'] as $check ) {
	if ( 'evidence_channel' === $check['id'] ) {
		$partial_ev = $check;
	}
}
cc84_assert( null !== $partial_ev && 'PASS' !== $partial_ev['status'], '#84.10a partial install must not certify the evidence channel' );

// Deterministic replay: the same plan completes on the partial state.
$replay = Plan::install( 'wp_test', 'cc84_partial_user', '%', 'cc84_partial_secret' );
$replay_result = $replay->apply( $root );
cc84_assert( $replay_result['success'], '#84.10a install replay must succeed' );
$replay_writer = new wpdb( 'cc84_partial_user', 'cc84_partial_secret', 'wp_test', $host );
$replay_writer->suppress_errors( true );
$replay_verify = Plan::verify( array(), $replay_writer );
cc84_assert( 'PASS' === $replay_verify['overall'], '#84.10a replay did not reach READY: ' . json_encode( $replay_verify ) );
Plan::uninstall( 'wp_test', 'cc84_partial_user', '%' )->apply( $root );
cc84_query( $root, "DROP USER 'cc84_op_install'@'%'" );
echo "  #84.10a install partial failure detected and deterministically replayed: PASS\n";

// --- B: add_target fails after the grant, before the trigger ---------------
$install_part = Plan::install( 'wp_test', 'cc84_part_user', '%', 'cc84_part_v1_secret' );
$install_part->apply( $root );
cc84_query( $root, 'DROP TABLE IF EXISTS cc84_part' );
cc84_query( $root, 'CREATE TABLE cc84_part (id INT PRIMARY KEY, touched INT NOT NULL DEFAULT 0) ENGINE=InnoDB' );
for ( $i = 1; $i <= 5; ++$i ) {
	cc84_query( $root, "INSERT INTO cc84_part (id, touched) VALUES ($i, 0)" );
}

cc84_query( $root, "DROP USER IF EXISTS 'cc84_op_target'@'%'" );
cc84_query( $root, "CREATE USER 'cc84_op_target'@'%' IDENTIFIED BY 'cc84_op_secret'" );
cc84_query( $root, 'GRANT SELECT, UPDATE ON wp_test.cc84_part TO \'cc84_op_target\'@\'%\' WITH GRANT OPTION' );
cc84_query( $root, "GRANT SELECT ON mysql.* TO 'cc84_op_target'@'%'" );
$op_target = new wpdb( 'cc84_op_target', 'cc84_op_secret', 'wp_test', $host );
$op_target->suppress_errors( true );
$target_failure = '';
try {
	Plan::add_target( 'wp_test', 'cc84_part_user', '%', 'cc84_part', 10 )->apply( $op_target );
} catch ( RuntimeException $e ) {
	$target_failure = $e->getMessage();
}
cc84_assert( '' !== $target_failure, '#84.10b add_target without TRIGGER must fail' );
$part_writer = new wpdb( 'cc84_part_user', 'cc84_part_v1_secret', 'wp_test', $host );
$part_writer->suppress_errors( true );
$partial_target = Plan::verify( array( 'cc84_part' => 2 ), $part_writer );
cc84_assert( 'PASS' !== $partial_target['overall'], '#84.10b partial add_target incorrectly PASSed' );
cc84_assert( 'FAIL' === $partial_target['integrations']['cc84_part']['status'], '#84.10b partial target must be FAIL' );
cc84_assert( 5 === (int) $root->get_var( 'SELECT COUNT(*) FROM cc84_part' ), '#84.10b application rows lost during failure' );

Plan::add_target( 'wp_test', 'cc84_part_user', '%', 'cc84_part', 10 )->apply( $root );
$repaired_target = Plan::verify( array( 'cc84_part' => 2 ), $part_writer );
cc84_assert( 'PASS' === $repaired_target['overall'], '#84.10b add_target replay did not reach READY: ' . json_encode( $repaired_target ) );
cc84_query( $root, "DROP USER 'cc84_op_target'@'%'" );
echo "  #84.10b add_target partial failure detected and deterministically replayed: PASS\n";

// --- C: rotation fails without altering the account ------------------------
cc84_query( $root, "DROP USER IF EXISTS 'cc84_op_rotate'@'%'" );
cc84_query( $root, "CREATE USER 'cc84_op_rotate'@'%' IDENTIFIED BY 'cc84_op_secret'" );
cc84_query( $root, "GRANT SELECT ON wp_test.* TO 'cc84_op_rotate'@'%'" ); // connection must be ready
cc84_query( $root, "GRANT SELECT ON mysql.* TO 'cc84_op_rotate'@'%'" ); // identity preflight must pass so ALTER is the failing step
cc84_query( $root, "GRANT SELECT, INSERT, UPDATE, DELETE ON wp_test.commitcap_v01_state TO 'cc84_op_rotate'@'%'" ); // rotation marker write/clear, no ALTER USER
$op_rotate = new wpdb( 'cc84_op_rotate', 'cc84_op_secret', 'wp_test', $host );
$op_rotate->suppress_errors( true );
$rotate_failure = '';
try {
	Plan::rotate_credential( 'cc84_part_user', '%', 'cc84_part_v2_secret', false )->apply( $op_rotate );
} catch ( RuntimeException $e ) {
	$rotate_failure = $e->getMessage();
}
cc84_assert( '' !== $rotate_failure, '#84.10c rotation without ALTER USER must fail' );
cc84_assert( false !== strpos( $rotate_failure, 'withheld' ), '#84.10c failed credential DDL must withhold SQL: ' . $rotate_failure );
cc84_assert( 0 === (int) $root->get_var( 'SELECT COUNT(*) FROM commitcap_v01_state WHERE connection_id = 0' ), '#84.10c failed rotation left an unsafe marker' );
$unchanged = new wpdb( 'cc84_part_user', 'cc84_part_v1_secret', 'wp_test', $host );
$unchanged->suppress_errors( true );
cc84_assert( 1 === (int) $unchanged->get_var( 'SELECT 1' ), '#84.10c failed rotation changed the account secret' );
Plan::rotate_credential( 'cc84_part_user', '%', 'cc84_part_v2_secret', false )->apply( $root );
$rotated = new wpdb( 'cc84_part_user', 'cc84_part_v2_secret', 'wp_test', $host );
$rotated->suppress_errors( true );
cc84_assert( 1 === (int) $rotated->get_var( 'SELECT 1' ), '#84.10c rotation replay did not install V2' );
cc84_query( $root, "DROP USER 'cc84_op_rotate'@'%'" );
echo "  #84.10c rotation partial failure (DCL withheld) detected and deterministically replayed: PASS\n";

// --- D: uninstall fails after dropping triggers/routines, before the helper
cc84_query( $root, "DROP USER IF EXISTS 'cc84_op_uninstall'@'%'" );
cc84_query( $root, "CREATE USER 'cc84_op_uninstall'@'%' IDENTIFIED BY 'cc84_op_secret'" );
cc84_query( $root, "GRANT ALTER ROUTINE, TRIGGER, SELECT ON wp_test.* TO 'cc84_op_uninstall'@'%' WITH GRANT OPTION" );
cc84_query( $root, "GRANT SELECT ON mysql.* TO 'cc84_op_uninstall'@'%'" );
if ( $is_mysql ) {
	cc84_query( $root, "GRANT SHOW_ROUTINE ON *.* TO 'cc84_op_uninstall'@'%'" );
	// MySQL 8.0 protects root-defined routines behind SYSTEM_USER; MariaDB has
	// no such privilege and uses its own definer rules.
	cc84_query( $root, "GRANT SYSTEM_USER ON *.* TO 'cc84_op_uninstall'@'%'" );
}
$op_uninstall = new wpdb( 'cc84_op_uninstall', 'cc84_op_secret', 'wp_test', $host );
$op_uninstall->suppress_errors( true );
$uninstall_failure = '';
try {
	Plan::uninstall( 'wp_test', 'cc84_part_user', '%', array( 'cc84_part' ) )->apply( $op_uninstall );
} catch ( RuntimeException $e ) {
	$uninstall_failure = $e->getMessage();
}
cc84_assert( '' !== $uninstall_failure, '#84.10d uninstall without DROP authority must fail' );
cc84_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM mysql.user WHERE user='cc84_part_user'" ), '#84.10d runtime account vanished before failing step' );
cc84_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='wp_test' AND ROUTINE_NAME LIKE 'commitcap_v01_%'" ), '#84.10d routines not dropped before failing step: ' . $uninstall_failure );
cc84_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='wp_test' AND TABLE_NAME='commitcap_v01_state'" ), '#84.10d helper unexpectedly dropped before failing step' );
$partial_uninstall_writer = new wpdb( 'cc84_part_user', 'cc84_part_v2_secret', 'wp_test', $host );
$partial_uninstall_writer->suppress_errors( true );
$partial_uninstall = Plan::verify( array(), $partial_uninstall_writer );
cc84_assert( 'PASS' !== $partial_uninstall['overall'], '#84.10d partial uninstall incorrectly PASSed' );
Plan::uninstall( 'wp_test', 'cc84_part_user', '%', array( 'cc84_part' ) )->apply( $root );
$gone = new wpdb( 'cc84_part_user', 'cc84_part_v2_secret', 'wp_test', $host );
$gone->suppress_errors( true );
cc84_assert( ! $gone->ready, '#84.10d uninstall replay did not drop the account' );
cc84_assert( 5 === (int) $root->get_var( 'SELECT COUNT(*) FROM cc84_part' ), '#84.10d application rows lost during uninstall replay' );
cc84_query( $root, "DROP USER 'cc84_op_uninstall'@'%'" );
cc84_query( $root, 'DROP TABLE cc84_part' );
echo "  #84.10d uninstall partial failure detected and deterministically replayed: PASS\n";

echo "--- End Gate #84 Provisioning Plan Tests ($host): ALL PASS ---\n";
