<?php
// #87 acceptance suite: real pinned Redirection 5.5.2 Bulk Disable through the
// CommitCap shared restricted runtime on both pinned database engines.
require_once WP_PLUGIN_DIR . '/commitcap/commitcap.php';
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
/**
 * Narrow version-drift seam for the real REST route: production behavior is
 * fixed in Redirection_Bulk_Disable_Rest; only the detector is overridden.
 */
class CC87_Version_Drift_Rest extends \CommitCap\Redirection_Bulk_Disable_Rest {
	public static $version = '5.5.3';
	protected static function plugin_version(): ?string {
		return self::$version;
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
cc87_assert( false === $result['transaction_rollback_attempted'], 'committed run must not claim rollback evidence' );
cc87_assert( null === $result['guard_rollback_completed'], 'committed run must not claim rollback completion' );
cc87_assert( false === $result['durability_verified_by_fresh_observer'], 'committed run must not claim durable verification' );
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
cc87_assert( true === $result['transaction_rollback_attempted'], 'logical denial rollback attempt fact missing' );
cc87_assert( null === $result['guard_rollback_completed'], 'adapter must not claim rollback completion' );
cc87_assert( false === $result['durability_verified_by_fresh_observer'], 'adapter must not claim fresh-observer durability' );
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
cc87_assert( true === $result['transaction_rollback_attempted'] && null === $result['guard_rollback_completed'], 'physical denial rollback facts' );
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
cc87_assert( true === $result['transaction_rollback_attempted'] && null === $result['guard_rollback_completed'], 'L=0 rollback facts' );
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
// Redirection coexistence under an installed certified policy.
//
// Regression for the pre-scoping trigger: with the old body, every unguarded
// UPDATE to the protected table was denied. These cases must now pass because
// the canonical trigger enforces only the certified runtime identity.
// ---------------------------------------------------------------------------
cc87_seed( $root, 4 );
$ids = array_map( 'intval', $root->get_col( 'SELECT id FROM wp_redirection_items ORDER BY id LIMIT 4' ) );
cc87_assert( 4 === count( $ids ), 'coexistence ids' );
wp_set_current_user( 1 );

// Normal WordPress identity: a plain UPDATE succeeds under the installed policy.
$normal->query( 'SELECT 1' );
$normal_ok = $normal->query( 'UPDATE wp_redirection_items SET status = ' . "'disabled'" . ' WHERE id = ' . (int) $ids[0] );
cc87_assert( false !== $normal_ok, 'normal WordPress UPDATE was denied under the installed policy: ' . $normal->last_error );
cc87_assert( false === strpos( (string) $normal->last_error, 'CC54_DENIED' ), 'normal WordPress UPDATE hit the CommitCap trigger' );
cc87_assert( 1 === (int) $root->get_var( 'SELECT COUNT(*) FROM wp_redirection_items WHERE id = ' . (int) $ids[0] . " AND status = 'disabled'" ), 'normal WordPress UPDATE was not durable' );
echo "  normal WordPress identity update unaffected by installed policy: PASS\n";

// Item-scoped bulk path: stock Redirection behavior on the normal connection.
$normal->query( 'SELECT 1' );
list( $response, $statements ) = cc87_trace( $root, $normal_id, function () use ( $normal, $ids ) {
	$normal->query( "SELECT 'CC87_TRACE_START'" );
	$request = new WP_REST_Request( 'POST', '/redirection/v1/bulk/redirect/disable' );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$request->set_body_params( array( 'bulk' => 'disable', 'items' => array( (int) $ids[1], (int) $ids[2] ) ) );
	$value = rest_do_request( $request );
	$normal->query( "SELECT 'CC87_TRACE_END'" );
	return $value;
} );
cc87_assert( 200 === $response->get_status(), 'item-scoped REST status: ' . $response->get_status() );
$scoped_updates = 0;
foreach ( $statements as $sql ) {
	if ( preg_match( "/^UPDATE `wp_redirection_items` SET `status` = 'disabled' WHERE `id` = '\d+'$/i", $sql ) ) {
		++$scoped_updates;
	}
}
cc87_assert( 2 === $scoped_updates, 'item-scoped path did not issue two per-ID updates on the normal connection: ' . json_encode( $statements ) );
$runtime_rows = (int) $root->get_var( $root->prepare( 'SELECT COUNT(*) FROM mysql.general_log WHERE thread_id = %d', $runtime_id ) );
cc87_assert( 0 === $runtime_rows, 'runtime connection received SQL during the item-scoped request' );
cc87_assert( 2 === (int) $root->get_var( 'SELECT COUNT(*) FROM wp_redirection_items WHERE id IN (' . (int) $ids[1] . ',' . (int) $ids[2] . ") AND status = 'disabled'" ), 'item-scoped disable was not durable' );
cc87_assert_normal( $normal, $normal_id, '#87 item-scoped' );
echo "  item-scoped items=[...] path unchanged on the normal connection: PASS\n";

// Single-item edit path: Red_Item::disable()/enable() are ordinary $wpdb->update.
$item = Red_Item::get_by_id( (int) $ids[3] );
cc87_assert( $item instanceof Red_Item, 'single-item fixture missing' );
$item->disable();
cc87_assert( 1 === (int) $root->get_var( 'SELECT COUNT(*) FROM wp_redirection_items WHERE id = ' . (int) $ids[3] . " AND status = 'disabled'" ), 'single-item disable was denied' );
$item = Red_Item::get_by_id( (int) $ids[3] );
$item->enable();
cc87_assert( 1 === (int) $root->get_var( 'SELECT COUNT(*) FROM wp_redirection_items WHERE id = ' . (int) $ids[3] . " AND status = 'enabled'" ), 'single-item enable was denied' );
echo "  single-item Red_Item disable/enable path works under installed policy: PASS\n";

// Hit/stat writer: Red_Item::visit() updates last_count/last_access.
cc87_assert( ! empty( red_get_options()['track_hits'] ), 'hit-tracking option disabled in fixture' );
$item = Red_Item::get_by_id( (int) $ids[0] );
cc87_assert( $item instanceof Red_Item, 'hit-path fixture missing' );
$hits_before = (int) $item->get_hits();
list( $unused, $hit_statements ) = cc87_trace( $root, $normal_id, function () use ( $normal, $item ) {
	$normal->query( "SELECT 'CC87_TRACE_START'" );
	$item->visit( '/cc87-hit', false );
	$normal->query( "SELECT 'CC87_TRACE_END'" );
	return true;
} );
$hit_updates = 0;
foreach ( $hit_statements as $sql ) {
	if ( preg_match( '/^UPDATE `?wp_redirection_items`? SET last_count=last_count\+1, last_access=NOW\(\) WHERE id=\d+$/i', $sql ) ) {
		++$hit_updates;
	}
}
cc87_assert( 1 === $hit_updates, 'hit/stat writer did not update on the normal connection: ' . json_encode( $hit_statements ) );
cc87_assert( $hits_before + 1 === (int) $root->get_var( 'SELECT last_count FROM wp_redirection_items WHERE id = ' . (int) $ids[0] ), 'hit/stat writer was denied or not durable' );
$runtime_rows = (int) $root->get_var( $root->prepare( 'SELECT COUNT(*) FROM mysql.general_log WHERE thread_id = %d', $runtime_id ) );
cc87_assert( 0 === $runtime_rows, 'runtime connection received SQL during the hit/stat write' );
cc87_assert_normal( $normal, $normal_id, '#87 hit/stat writer' );
echo "  hit/stat writer (Red_Item::visit) works under installed policy: PASS\n";

// ---------------------------------------------------------------------------
// Restricted runtime outside Guard is still denied, even with forged session
// state. P is enforced for the certified identity only; other grantees are
// outside the cooperative contract and are classified explicitly.
// ---------------------------------------------------------------------------
cc87_seed( $root, 3 );
$unguarded_id = (int) $root->get_var( 'SELECT id FROM wp_redirection_items ORDER BY id LIMIT 1' );
$runtime->query( 'SET @commitcap_v01_denied = 0' );
$runtime->query( 'SET @cc87_forged = 1' );
$runtime->query( 'SELECT 1' );
$unguarded = $runtime->query( 'UPDATE wp_redirection_items SET status = ' . "'disabled'" . ' WHERE id = ' . $unguarded_id );
cc87_assert( false === $unguarded, 'unguarded restricted-runtime UPDATE was allowed' );
cc87_assert( false !== strpos( (string) $runtime->last_error, 'CC54_DENIED' ), 'unguarded UPDATE was not denied by the physical policy: ' . $runtime->last_error );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'unguarded denial left durable state' );
echo "  restricted runtime outside Guard denied (forged session state insufficient): PASS\n";

// Direct lifecycle calls remain outside the cooperative contract, but the
// physical ceiling P still bounds them: 5 events allowed, 6th denied. The
// 6th event reuses a row to prove repeated-row accounting is not a bypass.
cc87_set_ceiling( $root, 5 );
cc87_seed( $root, 5 );
$five_ids = array_map( 'intval', $root->get_col( 'SELECT id FROM wp_redirection_items ORDER BY id' ) );
cc87_assert( 5 === count( $five_ids ), 'direct lifecycle fixture' );
$runtime->query( 'SELECT 1' );
cc87_query( $runtime, 'START TRANSACTION' );
$runtime->query( 'CALL commitcap_v01_open(' . "'" . Engine::policy_id( CC87_TABLE ) . "'" . ')' );
$allowed = 0;
foreach ( $five_ids as $five_id ) {
	if ( false !== $runtime->query( 'UPDATE wp_redirection_items SET status = ' . "'disabled'" . ' WHERE id = ' . (int) $five_id ) ) {
		++$allowed;
	}
}
cc87_assert( 5 === $allowed, 'direct lifecycle path did not allow 5 events: ' . $allowed );
cc87_assert( false === $runtime->query( 'UPDATE wp_redirection_items SET status = ' . "'enabled'" . ' WHERE id = ' . (int) $five_ids[0] ), 'direct lifecycle path exceeded P' );
cc87_query( $runtime, 'ROLLBACK' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 5 === $total && 0 === $disabled, 'direct lifecycle path left durable state' );
cc87_set_ceiling( $root, CC87_CEILING );
echo "  direct lifecycle call outside Guard still bounded by physical P: PASS\n";

// Explicit classification: another explicitly granted DB principal is outside
// the certified runtime identity and is not intercepted by the scoped policy.
cc87_query( $root, "DROP USER IF EXISTS 'cc87_foreign'@'%'" );
cc87_query( $root, "CREATE USER 'cc87_foreign'@'%' IDENTIFIED BY 'cc87_foreign_secret'" );
cc87_query( $root, "GRANT SELECT, UPDATE ON wp_test.wp_redirection_items TO 'cc87_foreign'@'%'" );
$foreign = new wpdb( 'cc87_foreign', 'cc87_foreign_secret', 'wp_test', $host );
$foreign->suppress_errors( true );
$foreign_id = $five_ids[1];
$foreign_ok = $foreign->query( 'UPDATE wp_redirection_items SET status = ' . "'disabled'" . ' WHERE id = ' . (int) $foreign_id );
cc87_assert( false !== $foreign_ok, 'foreign granted principal was unexpectedly intercepted: ' . $foreign->last_error );
cc87_assert( 1 === (int) $root->get_var( 'SELECT COUNT(*) FROM wp_redirection_items WHERE id = ' . (int) $foreign_id . " AND status = 'disabled'" ), 'foreign principal update was not durable' );
echo "  explicitly granted foreign principal classified as outside cooperative enforcement: PASS\n";

// ---------------------------------------------------------------------------
// Real WordPress REST end-to-end certification of the actual Admin operation:
// POST /redirection/v1/bulk/redirect/disable with global=true. These cases go
// through WP_REST_Server (rest_do_request), never the adapter directly, and
// consume the #78 descriptor/config instead of any legacy budget option.
// ---------------------------------------------------------------------------
if ( ! defined( 'COMMITCAP_DB_USER' ) ) {
	define( 'COMMITCAP_DB_USER', CC87_RUNTIME_USER );
	define( 'COMMITCAP_DB_PASSWORD', CC87_RUNTIME_SECRET );
	define( 'COMMITCAP_DB_NAME', 'wp_test' );
}
$operation = \CommitCap\Certified_Operation::redirection_5_5_2_bulk_disable();
wp_set_current_user( 1 );

function cc87_configure( $operation, $enabled, $budget ) {
	\CommitCap\Operation_Config::reset( $operation );
	if ( null !== $budget ) {
		\CommitCap\Operation_Config::set_logical_budget( $operation, $budget );
	}
	\CommitCap\Operation_Config::set_enabled( $operation, $enabled );
}

// The product descriptor fixes the trusted physical ceiling at 2000.
cc87_assert( 2000 === $operation->physical_ceiling(), 'descriptor physical ceiling' );
cc87_set_ceiling( $root, $operation->physical_ceiling() );

// Safe: N = L = 5 under P = 2000.
cc87_configure( $operation, true, 5 );
cc87_seed( $root, 5 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 200 === $response->get_status(), 'REST safe status: ' . $response->get_status() . ' ' . wp_json_encode( $body ) );
cc87_assert( is_array( $body ) && isset( $body['items'], $body['total'], $body['commitcap'] ), 'REST safe stock list shape plus evidence: ' . wp_json_encode( $body ) );
cc87_assert( 'COMMITTED' === $body['commitcap']['outcome'], 'REST safe outcome: ' . wp_json_encode( $body['commitcap'] ) );
list( $disable_updates, $disable_threads ) = cc87_disable_updates( $threads );
cc87_assert( 1 === $disable_updates, 'REST safe expected exactly one plugin UPDATE, saw ' . $disable_updates . ': ' . json_encode( $threads ) );
cc87_assert( ! in_array( $normal_id, $disable_threads, true ), 'REST safe plugin UPDATE ran on the normal connection' );
cc87_assert( array() !== cc87_adapter_statements( $threads ), 'REST safe did not run the CommitCap adapter' );
cc87_assert_isolated( $threads[ $disable_threads[0] ], '#87 REST safe runtime', true );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 5 === $total && 5 === $disabled, 'REST safe fresh-observer durability' );
echo "  REST configured enabled N=L=5 -> COMMITTED, restricted connection only: PASS\n";

// Logical denial through the real REST route.
cc87_configure( $operation, true, 5 );
cc87_seed( $root, 6 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 409 === $response->get_status() && 'commitcap_budget_denied' === $body['code'], 'REST logical denial response: ' . json_encode( $body ) );
cc87_assert( 'logical' === $body['data']['denial_kind'], 'REST logical denial kind: ' . json_encode( $body['data'] ) );
list( $disable_updates, $disable_threads ) = cc87_disable_updates( $threads );
cc87_assert( 1 === $disable_updates && ! in_array( $normal_id, $disable_threads, true ), 'REST logical denial must not run the stock global UPDATE' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 6 === $total && 0 === $disabled, 'REST logical denial rollback durability' );
echo "  REST configured N=6 > L=5 -> typed 409 denial, full rollback, no stock fallback: PASS\n";

// Physical denial through the real REST route at the descriptor ceiling.
cc87_configure( $operation, true, 2000 );
cc87_seed_bulk( $root, 2001 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 409 === $response->get_status() && 'commitcap_budget_denied' === $body['code'], 'REST physical denial response: ' . json_encode( $body ) );
cc87_assert( 'physical' === $body['data']['denial_kind'], 'REST physical denial kind: ' . json_encode( $body['data'] ) );
list( $disable_updates, $disable_threads ) = cc87_disable_updates( $threads );
cc87_assert( 1 === $disable_updates && ! in_array( $normal_id, $disable_threads, true ), 'REST physical denial must not run the stock global UPDATE' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 2001 === $total && 0 === $disabled, 'REST physical denial rollback durability' );
echo "  REST attempted > P=2000 -> distinguishable physical 409 denial, full rollback: PASS\n";

// Disabled operation: explicit fail closed, never stock fallback.
cc87_configure( $operation, false, 5 );
cc87_seed( $root, 3 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 503 === $response->get_status() && 'commitcap_operation_disabled' === $body['code'], 'REST disabled response: ' . json_encode( $body ) );
list( $disable_updates, ) = cc87_disable_updates( $threads );
cc87_assert( 0 === $disable_updates && array() === cc87_adapter_statements( $threads ), 'disabled operation ran a mutation or the adapter' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'disabled operation changed durable state' );
echo "  REST disabled -> 503 fail closed, zero stock mutation: PASS\n";

// Missing config: absent state is disabled and fails closed.
\CommitCap\Operation_Config::reset( $operation );
cc87_seed( $root, 3 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 503 === $response->get_status() && 'commitcap_operation_disabled' === $body['code'], 'REST missing config response: ' . json_encode( $body ) );
list( $disable_updates, ) = cc87_disable_updates( $threads );
cc87_assert( 0 === $disable_updates, 'missing config ran a mutation' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'missing config changed durable state' );
echo "  REST missing config -> 503 fail closed, zero stock mutation: PASS\n";

// Malformed stored config: fail closed, never silently repaired.
update_option(
	\CommitCap\Operation_Config::STATE_OPTION,
	array( 'operation_id' => $operation->id(), 'enabled' => true, 'logical_budget' => 5, 'table_name' => 'wp_redirection_items' )
);
cc87_seed( $root, 3 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 503 === $response->get_status() && 'commitcap_operation_misconfigured' === $body['code'], 'REST malformed config response: ' . json_encode( $body ) );
list( $disable_updates, ) = cc87_disable_updates( $threads );
cc87_assert( 0 === $disable_updates && array() === cc87_adapter_statements( $threads ), 'malformed config ran a mutation or the adapter' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'malformed config changed durable state' );
\CommitCap\Operation_Config::reset( $operation );
echo "  REST malformed config -> 503 fail closed, no stock fallback: PASS\n";

// Physical ceiling mismatch: NOT READY even though Doctor itself would pass.
cc87_configure( $operation, true, 5 );
cc87_set_ceiling( $root, 1999 );
cc87_seed( $root, 3 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 503 === $response->get_status() && 'commitcap_physical_ceiling_mismatch' === $body['code'], 'REST P mismatch response: ' . json_encode( $body ) );
cc87_assert( 2000 === $body['data']['expected_physical_ceiling'] && 1999 === $body['data']['actual_physical_ceiling'], 'REST P mismatch evidence' );
list( $disable_updates, ) = cc87_disable_updates( $threads );
cc87_assert( 0 === $disable_updates, 'P mismatch ran a mutation' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'P mismatch changed durable state' );
cc87_set_ceiling( $root, 2000 );
echo "  REST P mismatch -> 503 NOT READY with explicit evidence, zero mutation: PASS\n";

// Doctor not ready: fail closed, no stock unguarded Disable.
cc87_configure( $operation, true, 5 );
cc87_seed( $root, 4 );
$trigger = Engine::trigger_name( CC87_TABLE );
cc87_query( $root, "DROP TRIGGER `$trigger`" );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 503 === $response->get_status() && 'commitcap_operation_unavailable' === $body['code'], 'REST not-ready response: ' . json_encode( $body ) );
list( $disable_updates, ) = cc87_disable_updates( $threads );
cc87_assert( 0 === $disable_updates, 'not-ready route ran a mutation' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 4 === $total && 0 === $disabled, 'not-ready route changed durable state' );
Plan::add_target( 'wp_test', CC87_RUNTIME_USER, '%', CC87_TABLE, 2000 )->apply( $root );
echo "  REST Doctor NOT READY -> 503 fail closed, zero stock mutation: PASS\n";

// Non-certified routes stay stock Redirection on the normal connection.
cc87_configure( $operation, true, 5 );
cc87_seed( $root, 4 );
$scoped_ids = array_map( 'intval', $root->get_col( 'SELECT id FROM wp_redirection_items ORDER BY id LIMIT 2' ) );
list( $response, $threads ) = cc87_trace_all( $root, function () use ( $scoped_ids ) {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'items' => $scoped_ids ) ) );
} );
$body = $response->get_data();
cc87_assert( 200 === $response->get_status() && ! isset( $body['commitcap'] ), 'item-scoped REST must stay stock: ' . json_encode( $body ) );
list( $disable_updates, ) = cc87_disable_updates( $threads );
cc87_assert( 0 === $disable_updates && array() === cc87_adapter_statements( $threads ), 'item-scoped REST used CommitCap' );
cc87_assert( 2 === (int) $root->get_var( 'SELECT COUNT(*) FROM wp_redirection_items WHERE id IN (' . (int) $scoped_ids[0] . ',' . (int) $scoped_ids[1] . ") AND status = 'disabled'" ), 'item-scoped REST not durable on the normal connection' );
echo "  non-certified REST item-scoped items=[...] stays stock: PASS\n";

// Enable stays stock Redirection.
cc87_seed( $root, 3 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'enable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 200 === $response->get_status() && ! isset( $body['commitcap'] ), 'enable REST must stay stock: ' . json_encode( $body ) );
cc87_assert( array() === cc87_adapter_statements( $threads ), 'enable REST used CommitCap' );
$enable_updates = 0;
foreach ( (array) $threads as $statements ) {
	foreach ( $statements as $sql ) {
		if ( preg_match( "/^UPDATE wp_redirection_items SET status='enabled'\$/i", $sql ) ) {
			++$enable_updates;
		}
	}
}
cc87_assert( $enable_updates >= 1, 'enable REST did not run on the normal connection' );
echo "  non-certified REST Enable global=true stays stock: PASS\n";

// Reset stays stock Redirection.
cc87_seed( $root, 2 );
cc87_query( $root, 'UPDATE wp_redirection_items SET last_count = 5' );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'reset', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 200 === $response->get_status() && ! isset( $body['commitcap'] ), 'reset REST must stay stock: ' . json_encode( $body ) );
cc87_assert( array() === cc87_adapter_statements( $threads ), 'reset REST used CommitCap' );
cc87_assert( 0 === (int) $root->get_var( 'SELECT SUM(last_count) FROM wp_redirection_items' ), 'reset REST was not durable' );
echo "  non-certified REST Reset global=true stays stock: PASS\n";

// Delete stays stock Redirection.
cc87_seed( $root, 2 );
$delete_id = (int) $root->get_var( 'SELECT id FROM wp_redirection_items ORDER BY id LIMIT 1' );
list( $response, $threads ) = cc87_trace_all( $root, function () use ( $delete_id ) {
	return rest_do_request( cc87_rest_bulk_request( 'delete', array( 'items' => array( $delete_id ) ) ) );
} );
$body = $response->get_data();
cc87_assert( 200 === $response->get_status() && ! isset( $body['commitcap'] ), 'delete REST must stay stock: ' . json_encode( $body ) );
cc87_assert( array() === cc87_adapter_statements( $threads ), 'delete REST used CommitCap' );
cc87_assert( 1 === (int) $root->get_var( 'SELECT COUNT(*) FROM wp_redirection_items' ), 'delete REST was not durable' );
echo "  non-certified REST Delete stays stock: PASS\n";

// Permission model is Redirection's own; unauthorized callers never reach
// CommitCap and never mutate.
cc87_seed( $root, 3 );
wp_set_current_user( 0 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
wp_set_current_user( 1 );
$body = $response->get_data();
cc87_assert( 401 === $response->get_status() || 403 === $response->get_status(), 'unauthorized REST status: ' . $response->get_status() . ' ' . json_encode( $body ) );
list( $disable_updates, ) = cc87_disable_updates( $threads );
cc87_assert( 0 === $disable_updates && array() === cc87_adapter_statements( $threads ), 'unauthorized request reached CommitCap or a mutation' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 3 === $total && 0 === $disabled, 'unauthorized request changed durable state' );
echo "  unauthorized user -> Redirection permission denial, no adapter, no mutation: PASS\n";

// Version drift on the real REST route: the global=true Disable candidate is
// still owned by CommitCap, but an uncertified or unavailable Redirection build
// must fail closed instead of falling through to the stock unbounded UPDATE.
// The drift filter runs before the production filter (priority 5) and the
// production filter passes an earlier non-null result through unchanged.
cc87_configure( $operation, true, 5 );
add_filter( 'rest_dispatch_request', array( 'CC87_Version_Drift_Rest', 'dispatch' ), 5, 4 );
foreach ( array( '5.5.3', '5.4.0', null ) as $drift ) {
	CC87_Version_Drift_Rest::$version = $drift;
	cc87_seed( $root, 3 );
	list( $response, $threads ) = cc87_trace_all( $root, function () {
		return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
	} );
	$body = $response->get_data();
	cc87_assert(
		503 === $response->get_status() && 'commitcap_redirection_version_unsupported' === $body['code'],
		'REST version drift (' . var_export( $drift, true ) . ') response: ' . json_encode( $body )
	);
	list( $disable_updates, ) = cc87_disable_updates( $threads );
	cc87_assert( 0 === $disable_updates && array() === cc87_adapter_statements( $threads ), 'REST version drift (' . var_export( $drift, true ) . ') ran a mutation or the adapter' );
	list( $total, $disabled ) = cc87_counts( $root );
	cc87_assert( 3 === $total && 0 === $disabled, 'REST version drift (' . var_export( $drift, true ) . ') changed durable state' );
}
remove_filter( 'rest_dispatch_request', array( 'CC87_Version_Drift_Rest', 'dispatch' ), 5 );

// The production filter still certifies 5.5.2 after the drift simulation.
cc87_configure( $operation, true, 5 );
cc87_seed( $root, 3 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 200 === $response->get_status() && isset( $body['commitcap'] ) && 'COMMITTED' === $body['commitcap']['outcome'], 'production filter did not resume after drift tests: ' . json_encode( $body ) );
cc87_assert( 1 === cc87_disable_updates( $threads )[0], 'production filter did not run exactly one restricted UPDATE after drift tests' );
echo "  REST unsupported/unknown Redirection version -> 503 fail closed, zero normal and restricted UPDATE: PASS\n";

// ---------------------------------------------------------------------------
// Normal WordPress identity is still original after the whole matrix.
// ---------------------------------------------------------------------------
cc87_query( $normal, 'UPDATE cc87_normal_probe SET v = v + 1 WHERE id = 1' );
cc87_assert( 102 === (int) $root->get_var( 'SELECT v FROM cc87_normal_probe WHERE id = 1' ), 'normal WordPress write after adapter matrix' );
cc87_assert_normal( $normal, $normal_id, '#87 final' );

echo "--- End Redirection 5.5.2 Bulk Disable adapter tests ($host): ALL PASS ---\n";
