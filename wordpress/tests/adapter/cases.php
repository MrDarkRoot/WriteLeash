<?php
// #87 acceptance suite: real pinned Redirection 5.5.2 Bulk Disable through the
// CommitCap shared restricted runtime on both pinned database engines.
require_once WP_PLUGIN_DIR . '/commitcap-for-wordpress/commitcap-for-wordpress.php';
require_once __DIR__ . '/helpers.php';

use CommitCap\Provisioning_Plan as Plan;
use CommitCap\Redirection_Bulk_Disable as Adapter;
use CommitCap\Update_Engine as Engine;

define( 'CC87_RUNTIME_USER', 'cc87_writer' );
define( 'CC87_RUNTIME_SECRET', 'cc87_secret' );
define( 'CC87_TABLE', 'wp_redirection_items' );
define( 'CC87_CEILING', 10 );

$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), 'unknown fixture host' );
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$normal    = $GLOBALS['wpdb'];
$normal_id = (int) $normal->get_var( 'SELECT CONNECTION_ID()' );
$normal->suppress_errors( true );
$version   = (string) $root->get_var( 'SELECT VERSION()' );
cc87_assert( 0 === strpos( $version, 'mysql' === $host ? '8.0.44' : '10.11.15' ), 'fixture version drift: ' . $version );
echo "--- Begin Redirection 5.5.2 Bulk Disable adapter tests ($host $version) ---\n";

// ---------------------------------------------------------------------------
// Exact Redirection 5.5.2 version pin and pure compatibility classification.
// ---------------------------------------------------------------------------
cc87_assert( '5.5.2' === Adapter::detected_version(), 'exact Redirection 5.5.2 not detected' );
cc87_assert( Adapter::plugin_class_available(), 'reviewed Red_Item::set_status_all() boundary missing' );
cc87_assert( array( 'SUPPORTED', 'ok' ) === Adapter::compatibility_status( '5.5.2', true ), 'supported version classification' );
cc87_assert( array( 'UNSUPPORTED', 'redirection_version_unsupported' ) === Adapter::compatibility_status( '5.4.0', true ), 'older version not refused' );
cc87_assert( array( 'UNSUPPORTED', 'redirection_version_unsupported' ) === Adapter::compatibility_status( '5.5.3', true ), 'newer version not refused' );
cc87_assert( array( 'UNKNOWN', 'redirection_missing' ) === Adapter::compatibility_status( null, false ), 'missing plugin not refused' );
cc87_assert( array( 'UNKNOWN', 'redirection_class_missing' ) === Adapter::compatibility_status( '5.5.2', false ), 'missing class not refused' );
echo "  Redirection 5.5.2 exact version and compatibility refusal: PASS\n";

// ---------------------------------------------------------------------------
// Redirection schema and reviewed CommitCap runtime provisioning.
// ---------------------------------------------------------------------------
$tables = (int) $normal->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wp_redirection_%'" );
if ( 0 === $tables ) {
	Red_Database::get_latest_database()->install();
	Red_Flusher::clear();
	red_set_options();
	$tables = (int) $normal->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wp_redirection_%'" );
}
cc87_assert( $tables >= 4, 'Redirection schema did not install' );

cc87_query( $root, "DROP USER IF EXISTS '" . CC87_RUNTIME_USER . "'@'%'" );
Plan::install( 'wp_test', CC87_RUNTIME_USER, '%', CC87_RUNTIME_SECRET )->apply( $root );
Plan::add_target( 'wp_test', CC87_RUNTIME_USER, '%', CC87_TABLE, CC87_CEILING )->apply( $root );

$runtime = new wpdb( CC87_RUNTIME_USER, CC87_RUNTIME_SECRET, 'wp_test', $host );
$runtime->suppress_errors( true );
// A secondary wpdb does not inherit the site table prefix; the shared runtime
// must be configured with it, exactly as the operator deploys it.
$runtime->set_prefix( $normal->prefix );
$runtime_id = (int) $runtime->get_var( 'SELECT CONNECTION_ID()' );
cc87_assert( $runtime_id > 0, 'runtime connection did not open' );
$provisioned = Plan::verify( array( CC87_TABLE => CC87_CEILING ), $runtime );
cc87_assert( 'PASS' === $provisioned['overall'], 'provisioned runtime not READY: ' . json_encode( $provisioned ) );
echo "  runtime provisioning and Doctor READY: PASS\n";

// Normal WordPress identity write probe, checked before and after the matrix.
cc87_query( $root, 'CREATE TABLE IF NOT EXISTS cc87_normal_probe (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB' );
cc87_query( $root, 'REPLACE INTO cc87_normal_probe (id, v) VALUES (1, 100)' );
cc87_query( $normal, 'UPDATE cc87_normal_probe SET v = v + 1 WHERE id = 1' );
cc87_assert( 101 === (int) $root->get_var( 'SELECT v FROM cc87_normal_probe WHERE id = 1' ), 'normal WordPress write before adapter matrix' );

function cc87_set_ceiling( $root, $ceiling ) {
	Plan::remove_target( 'wp_test', CC87_RUNTIME_USER, '%', CC87_TABLE, 0 )->apply( $root );
	Plan::add_target( 'wp_test', CC87_RUNTIME_USER, '%', CC87_TABLE, $ceiling )->apply( $root );
}

/**
 * Run one adapter against the restricted connection under the authoritative
 * general log, then assert isolation, restoration and clean accounting.
 */
function cc87_run_adapter( $root, $runtime, $runtime_id, Adapter $adapter, $expect_update, $assert_isolation, $label, $normal, $normal_id, $expect_clean_accounting = true ) {
	cc87_assert_normal( $normal, $normal_id, $label . ' pre' );
	list( $result, $statements ) = cc87_trace( $root, $runtime_id, function () use ( $runtime, $adapter ) {
		$runtime->query( "SELECT 'CC87_TRACE_START'" );
		$value = $adapter->run();
		$runtime->query( "SELECT 'CC87_TRACE_END'" );
		return $value;
	} );
	if ( $assert_isolation ) {
		cc87_assert_isolated( $statements, $label, $expect_update );
	}
	cc87_assert_normal( $normal, $normal_id, $label . ' post' );
	if ( $expect_clean_accounting ) {
		cc87_assert_no_accounting( $root, $runtime_id, $label );
	}
	return $result;
}

// Adversarial subclasses: simulate other plugin builds and hostile callback
// behavior without changing the production call path.
class CC87_Wrong_Version extends Adapter {
	protected function compatibility(): array {
		return array( 'UNSUPPORTED', 'redirection_version_unsupported' );
	}
}
class CC87_Missing_Class extends Adapter {
	protected function compatibility(): array {
		return array( 'UNKNOWN', 'redirection_class_missing' );
	}
}
class CC87_Plugin_Throws extends Adapter {
	protected function invoke_plugin() {
		throw new RuntimeException( 'cc87 injected plugin failure' );
	}
}
class CC87_Db_Error extends Adapter {
	protected function invoke_plugin() {
		return $GLOBALS['wpdb']->query( 'UPDATE wp_redirection_items SET cc87_missing_column = 1 WHERE id = 0' );
	}
}
class CC87_Swallowed_Error extends Adapter {
	protected function invoke_plugin() {
		$GLOBALS['wpdb']->query( 'UPDATE wp_redirection_items SET cc87_missing_column = 1 WHERE id = 0' );
		return parent::invoke_plugin();
	}
}
class CC87_Foreign_Write extends Adapter {
	protected function invoke_plugin() {
		return $GLOBALS['wpdb']->query( "UPDATE wp_options SET option_value = 'cc87' WHERE option_name = 'siteurl'" );
	}
}
class CC87_Ddl_Attempt extends Adapter {
	protected function invoke_plugin() {
		return $GLOBALS['wpdb']->query( 'CREATE TABLE cc87_ddl_probe (id INT)' );
	}
}
class CC87_Insert_Attempt extends Adapter {
	protected function invoke_plugin() {
		return $GLOBALS['wpdb']->query( "INSERT INTO wp_redirection_items (url, status) VALUES ('/cc87-insert', 'enabled')" );
	}
}
class CC87_Delete_Attempt extends Adapter {
	protected function invoke_plugin() {
		return $GLOBALS['wpdb']->query( 'DELETE FROM wp_redirection_items WHERE id = 0' );
	}
}
class CC87_Replace_Attempt extends Adapter {
	protected function invoke_plugin() {
		return $GLOBALS['wpdb']->query( "REPLACE INTO wp_redirection_items (id, url, status) VALUES (0, '/cc87-replace', 'enabled')" );
	}
}

// ---------------------------------------------------------------------------
// Safe logical run: N = L = 5 under P = 10 commits exactly 5 row events.
// ---------------------------------------------------------------------------
cc87_seed( $root, 5 );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new Adapter( $runtime, 5 ), true, true, '#87 safe run', $normal, $normal_id );
cc87_expect( $result, 'COMMITTED', 'ok', null, '#87 safe run' );
cc87_assert( 5 === $result['consumed'] && 5 === $result['logical_budget'], 'safe consumed/budget' );
cc87_assert( 10 === $result['physical_ceiling'], 'safe physical ceiling' );
cc87_assert( 5 === $result['affected_rows'], 'safe affected rows' );
cc87_assert( false === $result['rollback_verified'], 'committed run must not claim rollback evidence' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 5 === $total && 5 === $disabled, 'safe run fresh-observer durability' );
echo "  safe run N=5 L=5 P=10 -> COMMITTED with fresh observer: PASS\n";

// ---------------------------------------------------------------------------
// Logical denial: N = 6 > L = 5 with P = 10. The real plugin UPDATE runs and
// the full statement set is rolled back before COMMIT.
// ---------------------------------------------------------------------------
cc87_seed( $root, 6 );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new Adapter( $runtime, 5 ), true, true, '#87 logical denial', $normal, $normal_id );
cc87_expect( $result, 'DENIED', 'logical_budget_exceeded', 'logical', '#87 logical denial' );
cc87_assert( $result['consumed'] > $result['logical_budget'], 'logical denial must observe consumed > L' );
cc87_assert( true === $result['rollback_verified'], 'logical denial rollback evidence missing' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 6 === $total && 0 === $disabled, 'logical denial left durable mutations' );
echo "  logical denial N=6 > L=5 -> typed Budget_Denied + full rollback: PASS\n";

// ---------------------------------------------------------------------------
// Physical denial: P = 5, attempted 8 row events. Distinguishable from logical.
// ---------------------------------------------------------------------------
cc87_set_ceiling( $root, 5 );
cc87_seed( $root, 8 );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new Adapter( $runtime, 5 ), true, true, '#87 physical denial', $normal, $normal_id );
cc87_assert( 'DENIED' === $result['outcome'], 'physical denial outcome: ' . json_encode( $result ) );
cc87_assert( in_array( $result['reason'], array( 'budget_exceeded', 'denial_signal' ), true ), 'physical denial reason: ' . $result['reason'] );
cc87_assert( 'physical' === $result['denial_kind'], 'physical denial kind: ' . json_encode( $result['denial_kind'] ) );
cc87_assert( 5 === $result['physical_ceiling'], 'physical denial ceiling' );
cc87_assert( true === $result['rollback_verified'], 'physical denial rollback evidence missing' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 8 === $total && 0 === $disabled, 'physical denial left durable mutations' );
cc87_set_ceiling( $root, CC87_CEILING );
echo "  physical denial P=5 -> distinguishable denial + full rollback: PASS\n";

// ---------------------------------------------------------------------------
// L = 0: a non-empty Disable is denied and rolled back.
// ---------------------------------------------------------------------------
cc87_seed( $root, 3 );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new Adapter( $runtime, 0 ), true, true, '#87 L=0', $normal, $normal_id );
cc87_expect( $result, 'DENIED', 'logical_budget_exceeded', 'logical', '#87 L=0' );
cc87_assert( $result['consumed'] > 0, 'L=0 denial consumed' );
cc87_assert( true === $result['rollback_verified'], 'L=0 rollback evidence missing' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'L=0 left durable mutations' );
echo "  L=0 non-empty Disable -> denied and rolled back: PASS\n";

// ---------------------------------------------------------------------------
// L > P fails before the plugin callback executes.
// ---------------------------------------------------------------------------
cc87_seed( $root, 3 );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new Adapter( $runtime, 11 ), false, true, '#87 L>P', $normal, $normal_id );
cc87_expect( $result, 'UNKNOWN', 'doctor_not_ready', null, '#87 L>P' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'L>P changed durable state' );
echo "  L=11 > P=10 -> refused before plugin callback: PASS\n";

// ---------------------------------------------------------------------------
// Doctor FAIL: target policy trigger absent.
// ---------------------------------------------------------------------------
cc87_seed( $root, 4 );
$trigger = Engine::trigger_name( CC87_TABLE );
cc87_query( $root, "DROP TRIGGER `$trigger`" );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new Adapter( $runtime, 4 ), false, true, '#87 doctor fail', $normal, $normal_id );
cc87_expect( $result, 'UNKNOWN', 'doctor_not_ready', null, '#87 doctor fail' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 4 === $total && 0 === $disabled, 'doctor FAIL changed durable state' );
Plan::add_target( 'wp_test', CC87_RUNTIME_USER, '%', CC87_TABLE, CC87_CEILING )->apply( $root );
echo "  Doctor FAIL (missing policy trigger) -> refused before callback: PASS\n";

// ---------------------------------------------------------------------------
// Doctor FAIL: target policy trigger replaced with a foreign body / ceiling.
// ---------------------------------------------------------------------------
cc87_seed( $root, 4 );
$trigger = Engine::trigger_name( CC87_TABLE );
cc87_query( $root, "DROP TRIGGER `$trigger`" );
cc87_query( $root, "CREATE TRIGGER `$trigger` BEFORE UPDATE ON wp_redirection_items FOR EACH ROW SET @cc87_alien = 1" );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new Adapter( $runtime, 4 ), false, true, '#87 tampered trigger', $normal, $normal_id );
cc87_expect( $result, 'UNKNOWN', 'doctor_not_ready', null, '#87 tampered trigger' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 4 === $total && 0 === $disabled, 'tampered trigger changed durable state' );
cc87_query( $root, "DROP TRIGGER `$trigger`" );
Plan::add_target( 'wp_test', CC87_RUNTIME_USER, '%', CC87_TABLE, CC87_CEILING )->apply( $root );
echo "  Doctor FAIL (foreign trigger body / ceiling mismatch) -> refused before callback: PASS\n";

// ---------------------------------------------------------------------------
// Doctor FAIL: trusted ROTATED_UNSAFE marker.
// ---------------------------------------------------------------------------
cc87_seed( $root, 3 );
cc87_query( $root, "INSERT INTO commitcap_v01_state (connection_id, policy_id, consumed) VALUES (0, 'cc87_marker', 1)" );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new Adapter( $runtime, 3 ), false, true, '#87 rotated unsafe', $normal, $normal_id );
cc87_expect( $result, 'UNKNOWN', 'doctor_not_ready', null, '#87 rotated unsafe' );
cc87_query( $root, "DELETE FROM commitcap_v01_state WHERE connection_id = 0 AND policy_id = 'cc87_marker'" );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'ROTATED_UNSAFE changed durable state' );
echo "  ROTATED_UNSAFE -> refused before callback: PASS\n";

// ---------------------------------------------------------------------------
// Stale committed accounting for this connection/policy is refused before the
// plugin callback. Doctor's behavioral trigger probe opens accounting for the
// same connection/policy and therefore observes the stale row first; either
// layer refusing is a valid fail-closed outcome and nothing is mutated.
// ---------------------------------------------------------------------------
cc87_seed( $root, 3 );
$policy_id = Engine::policy_id( CC87_TABLE );
cc87_query( $root, $root->prepare( 'INSERT INTO commitcap_v01_state (connection_id, policy_id, consumed) VALUES (%d, %s, 1)', $runtime_id, $policy_id ) );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new Adapter( $runtime, 3 ), false, true, '#87 stale accounting', $normal, $normal_id, false );
cc87_expect( $result, 'UNKNOWN', 'doctor_not_ready', null, '#87 stale accounting' );
cc87_assert( 1 === (int) $root->get_var( $root->prepare( 'SELECT COUNT(*) FROM commitcap_v01_state WHERE connection_id = %d AND policy_id = %s', $runtime_id, $policy_id ) ), 'stale accounting row was not preserved on refusal' );
cc87_query( $root, $root->prepare( 'DELETE FROM commitcap_v01_state WHERE connection_id = %d AND policy_id = %s', $runtime_id, $policy_id ) );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'stale accounting changed durable state' );
echo "  stale committed accounting -> refused before callback: PASS\n";

// ---------------------------------------------------------------------------
// Wrong/absent plugin builds are refused before any callback.
// ---------------------------------------------------------------------------
cc87_seed( $root, 3 );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new CC87_Wrong_Version( $runtime, 3 ), false, true, '#87 wrong version', $normal, $normal_id );
cc87_expect( $result, 'UNKNOWN', 'redirection_version_unsupported', null, '#87 wrong version' );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new CC87_Missing_Class( $runtime, 3 ), false, true, '#87 missing class', $normal, $normal_id );
cc87_expect( $result, 'UNKNOWN', 'redirection_class_missing', null, '#87 missing class' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'incompatible builds changed durable state' );
echo "  wrong/missing Redirection build -> refused before callback: PASS\n";

// ---------------------------------------------------------------------------
// Failure atomicity: plugin exception, DB error, swallowed error, foreign
// write, DDL and INSERT attempts roll back and always restore global $wpdb.
// ---------------------------------------------------------------------------
cc87_seed( $root, 3 );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new CC87_Plugin_Throws( $runtime, 3 ), false, true, '#87 plugin exception', $normal, $normal_id );
cc87_expect( $result, 'ERROR', 'callback_failed', null, '#87 plugin exception' );

$result = cc87_run_adapter( $root, $runtime, $runtime_id, new CC87_Db_Error( $runtime, 3 ), false, false, '#87 db error', $normal, $normal_id );
cc87_expect( $result, 'ERROR', 'database_error', null, '#87 db error' );

$result = cc87_run_adapter( $root, $runtime, $runtime_id, new CC87_Swallowed_Error( $runtime, 3 ), false, false, '#87 swallowed error', $normal, $normal_id );
cc87_expect( $result, 'ERROR', 'database_error', null, '#87 swallowed error' );

$siteurl_before = $normal->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new CC87_Foreign_Write( $runtime, 3 ), false, false, '#87 foreign write', $normal, $normal_id );
cc87_expect( $result, 'ERROR', 'database_error', null, '#87 foreign write' );
cc87_assert( $siteurl_before === $normal->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" ), 'foreign-table write changed wp_options' );

$result = cc87_run_adapter( $root, $runtime, $runtime_id, new CC87_Ddl_Attempt( $runtime, 3 ), false, false, '#87 ddl attempt', $normal, $normal_id, false );
cc87_assert( 'ERROR' === $result['outcome'], 'DDL attempt did not fail closed: ' . json_encode( $result ) );
cc87_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cc87_ddl_probe'" ), 'DDL succeeded on restricted identity' );
// DDL triggers an implicit commit before the denied statement, so the open
// accounting row is already durable; the guard fails closed and the residue
// blocks the next run until trusted cleanup (documented in GUARD.md).
$ddl_residue = (int) $root->get_var( $root->prepare( 'SELECT COUNT(*) FROM commitcap_v01_state WHERE connection_id = %d', $runtime_id ) );
cc87_assert( $ddl_residue > 0, 'DDL implicit commit did not leave the expected accounting residue' );
$blocked = cc87_run_adapter( $root, $runtime, $runtime_id, new Adapter( $runtime, 3 ), false, false, '#87 ddl residue', $normal, $normal_id, false );
cc87_assert( 'COMMITTED' !== $blocked['outcome'], 'accounting residue did not fail closed' );
cc87_query( $root, $root->prepare( 'DELETE FROM commitcap_v01_state WHERE connection_id = %d', $runtime_id ) );

$result = cc87_run_adapter( $root, $runtime, $runtime_id, new CC87_Insert_Attempt( $runtime, 3 ), false, false, '#87 insert attempt', $normal, $normal_id );
cc87_assert( 'ERROR' === $result['outcome'], 'INSERT attempt did not fail closed: ' . json_encode( $result ) );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new CC87_Delete_Attempt( $runtime, 3 ), false, false, '#87 delete attempt', $normal, $normal_id );
cc87_assert( 'ERROR' === $result['outcome'], 'DELETE attempt did not fail closed: ' . json_encode( $result ) );
$result = cc87_run_adapter( $root, $runtime, $runtime_id, new CC87_Replace_Attempt( $runtime, 3 ), false, false, '#87 replace attempt', $normal, $normal_id );
cc87_assert( 'ERROR' === $result['outcome'], 'REPLACE attempt did not fail closed: ' . json_encode( $result ) );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'INSERT/DELETE/REPLACE attempt changed durable rows' );
echo "  plugin exception / DB error / swallowed error / foreign write / DDL / INSERT / DELETE / REPLACE fail closed and restore wpdb: PASS\n";

// ---------------------------------------------------------------------------
// Existing transaction on the runtime connection is refused.
// ---------------------------------------------------------------------------
cc87_seed( $root, 3 );
$tx_runtime = new wpdb( CC87_RUNTIME_USER, CC87_RUNTIME_SECRET, 'wp_test', $host );
$tx_runtime->suppress_errors( true );
$tx_runtime->set_prefix( $normal->prefix );
$tx_id = (int) $tx_runtime->get_var( 'SELECT CONNECTION_ID()' );
cc87_query( $tx_runtime, 'START TRANSACTION' );
list( $result, $statements ) = cc87_trace( $root, $tx_id, function () use ( $tx_runtime ) {
	$tx_runtime->query( "SELECT 'CC87_TRACE_START'" );
	$value = ( new Adapter( $tx_runtime, 3 ) )->run();
	$tx_runtime->query( "SELECT 'CC87_TRACE_END'" );
	return $value;
} );
cc87_expect( $result, 'UNKNOWN', 'doctor_not_ready', null, '#87 existing transaction' );
cc87_assert_isolated( $statements, '#87 existing transaction', false );
cc87_query( $tx_runtime, 'ROLLBACK' );
cc87_assert_no_accounting( $root, $tx_id, '#87 existing transaction' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'existing transaction changed durable state' );
echo "  existing runtime transaction -> refused before callback: PASS\n";

// ---------------------------------------------------------------------------
// Malformed logical budgets are rejected before any database work.
// ---------------------------------------------------------------------------
foreach ( array( -1, 'abc', 1.5, '01', '2147483648' ) as $bad ) {
	try {
		new Adapter( $runtime, $bad );
		cc87_assert( false, 'malformed budget accepted: ' . var_export( $bad, true ) );
	} catch ( InvalidArgumentException $error ) {
		// Expected.
	}
}
echo "  malformed logical budgets rejected before callback: PASS\n";

// ---------------------------------------------------------------------------
// Prefix mismatch between the normal WordPress connection and the restricted
// connection would guard a different table; fail closed.
// ---------------------------------------------------------------------------
cc87_seed( $root, 3 );
$odd = new wpdb( CC87_RUNTIME_USER, CC87_RUNTIME_SECRET, 'wp_test', $host );
$odd->suppress_errors( true );
$odd->set_prefix( 'zz_' );
$odd_id = (int) $odd->get_var( 'SELECT CONNECTION_ID()' );
$result = cc87_run_adapter( $root, $odd, $odd_id, new Adapter( $odd, 3 ), false, true, '#87 prefix mismatch', $normal, $normal_id );
cc87_expect( $result, 'UNKNOWN', 'runtime_prefix_mismatch', null, '#87 prefix mismatch' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'prefix mismatch changed durable state' );
echo "  runtime prefix mismatch -> refused before callback: PASS\n";

// ---------------------------------------------------------------------------
// Item-scoped Redirection path regression.
//
// #87 adds no REST hook and never intercepts this path. The control run proves
// the unchanged plugin behavior with no CommitCap policy installed. The
// protected run proves the same code path and connection routing with the
// certified policy installed, and records the pre-existing #54 consequence:
// unguarded UPDATEs to a protected table are denied by the physical trigger
// (GUARD.md: "the #54 trigger still denies unguarded writes").
// ---------------------------------------------------------------------------
cc87_seed( $root, 4 );
$ids = array_map( 'intval', $root->get_col( 'SELECT id FROM wp_redirection_items ORDER BY id LIMIT 2' ) );
cc87_assert( 2 === count( $ids ), 'item-scoped ids' );
wp_set_current_user( 1 );

function cc87_item_scoped_enable( $root, $normal, $normal_id, $ids, $runtime_id, $label ) {
	list( $response, $statements ) = cc87_trace( $root, $normal_id, function () use ( $normal, $ids ) {
		$normal->query( "SELECT 'CC87_TRACE_START'" );
		$request = new WP_REST_Request( 'POST', '/redirection/v1/bulk/redirect/enable' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params( array( 'bulk' => 'enable', 'items' => $ids ) );
		$value = rest_do_request( $request );
		$normal->query( "SELECT 'CC87_TRACE_END'" );
		return $value;
	} );
	cc87_assert( 200 === $response->get_status(), $label . ' REST status: ' . $response->get_status() );
	$updates = 0;
	foreach ( $statements as $sql ) {
		if ( preg_match( "/^UPDATE `wp_redirection_items` SET `status` = 'enabled' WHERE `id` = '\d+'$/i", $sql ) ) {
			++$updates;
		}
	}
	cc87_assert( 2 === $updates, $label . ' did not issue two per-ID updates on the normal connection: ' . json_encode( $statements ) );
	$runtime_rows = (int) $root->get_var( $root->prepare( 'SELECT COUNT(*) FROM mysql.general_log WHERE thread_id = %d', $runtime_id ) );
	cc87_assert( 0 === $runtime_rows, $label . ' runtime connection received SQL' );
	cc87_assert_normal( $normal, $normal_id, $label );
}

// Control regime: no certified policy installed.
Plan::remove_target( 'wp_test', CC87_RUNTIME_USER, '%', CC87_TABLE, 0 )->apply( $root );
cc87_item_scoped_enable( $root, $normal, $normal_id, $ids, $runtime_id, '#87 item-scoped control' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 4 === $total && 0 === $disabled, 'item-scoped control changed durable state unexpectedly' );
Plan::add_target( 'wp_test', CC87_RUNTIME_USER, '%', CC87_TABLE, CC87_CEILING )->apply( $root );

// Certified policy installed: same code path and normal connection, no adapter
// interception; the physical trigger denies the unguarded UPDATE attempts.
cc87_item_scoped_enable( $root, $normal, $normal_id, $ids, $runtime_id, '#87 item-scoped protected' );
$probe = $normal->query( 'UPDATE wp_redirection_items SET status = ' . "'enabled'" . ' WHERE id = ' . (int) $ids[0] );
cc87_assert( false === $probe, 'unguarded normal-connection update unexpectedly succeeded under the installed policy' );
cc87_assert( false !== strpos( (string) $normal->last_error, 'CC54_DENIED' ), 'unguarded update was not denied by the physical policy: ' . $normal->last_error );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 4 === $total && 0 === $disabled, 'item-scoped path changed durable state unexpectedly' );
echo "  item-scoped path: normal connection, no interception; control works, installed policy denies unguarded UPDATE: PASS\n";

// ---------------------------------------------------------------------------
// Normal WordPress identity is still original after the whole matrix.
// ---------------------------------------------------------------------------
cc87_query( $normal, 'UPDATE cc87_normal_probe SET v = v + 1 WHERE id = 1' );
cc87_assert( 102 === (int) $root->get_var( 'SELECT v FROM cc87_normal_probe WHERE id = 1' ), 'normal WordPress write after adapter matrix' );
cc87_assert_normal( $normal, $normal_id, '#87 final' );

echo "--- End Redirection 5.5.2 Bulk Disable adapter tests ($host): ALL PASS ---\n";
