<?php
// #58 synthetic proof, run after #78 on the same shared runtime/Redirection site.
require_once WP_PLUGIN_DIR . '/commitcap-for-wordpress/commitcap-for-wordpress.php';
require_once __DIR__ . '/helpers.php';

use CommitCap\Compatibility_Grants;
use CommitCap\Disposable_Demo as Demo;
use CommitCap\Disposable_Demo_Setup as Setup;
use CommitCap\Guard;
use CommitCap\Guard_Error;
use CommitCap\Provisioning_Plan as Plan;
use CommitCap\Update_Engine as Engine;

$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), 'demo pinned host' );
$normal = $GLOBALS['wpdb'];
$normal->suppress_errors( true );
$normal_id = (int) $normal->get_var( 'SELECT CONNECTION_ID()' );
$installer = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$installer->suppress_errors( true );
$runtime = new wpdb( 'cc87_writer', 'cc87_secret', 'wp_test', $host );
$runtime->suppress_errors( true );
$runtime->set_prefix( (string) $normal->prefix );
$runtime_id = (int) $runtime->get_var( 'SELECT CONNECTION_ID()' );
$table = Demo::table( (string) $normal->prefix );
$trigger = Engine::trigger_name( $table );
$setup = new Setup( $installer, 'cc87_writer', '%' );
$demo = new Demo( $runtime );
cc87_assert( 6 === Demo::PHYSICAL_CEILING && 5 === Demo::LOGICAL_BUDGET, 'reviewed P/L' );
cc87_assert( $table === 'wp_commitcap_demo_rows', 'code-known owned target' );
cc87_assert( $normal_id !== $runtime_id, 'explicit secondary wpdb' );
cc87_assert( 'NOT_READY' === $demo->status()['status'], 'before setup fail closed' );

// A named but foreign table is never adopted/dropped.
cc87_query( $installer, "CREATE TABLE `$table` (id INT PRIMARY KEY) ENGINE=InnoDB" );
try {
	$setup->setup();
	throw new RuntimeException( 'foreign demo table was adopted' );
} catch ( RuntimeException $error ) {
	cc87_assert( false !== strpos( $error->getMessage(), 'foreign shape' ), 'foreign table collision refused' );
}
cc87_assert( 'UNKNOWN' === $setup->cleanup_status()['status'], 'foreign shape cleanup refused' );
cc87_query( $installer, "DROP TABLE `$table`" ); // External fixture removes its own foreign collision.

$redirection_before = $installer->get_row( 'SELECT COUNT(*) AS total, COALESCE(SUM(status = "disabled"),0) AS disabled FROM wp_redirection_items', ARRAY_A );
$redirection_trigger = Engine::trigger_name( 'wp_redirection_items' );
$redirection_body = $installer->get_var( $installer->prepare( 'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $redirection_trigger ) );
$setup->setup();
$setup->setup(); // No duplicate trigger or privilege accumulation.
$setup->reset();
$grants_before = $installer->get_results( "SHOW GRANTS FOR 'cc87_writer'@'%'", ARRAY_N );
$body_before = $installer->get_var( $installer->prepare( 'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $trigger ) );
cc87_assert( 'OWNED' === $setup->cleanup_status()['status'], 'owned cleanup inventory' );
cc87_assert( 'READY' === $demo->status()['status'] && 'PASS' === $demo->status()['exact_grants'], 'Doctor READY with exact grants and shared sibling' );
echo "#58 $host: owned target=$table L=5 P=6 Doctor READY exact grants SELECT,UPDATE\n";

function cc58_observe( $host, $table ) {
	// New connection after Guard returns. Never use the restricted transaction connection.
	$observer = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
	$observer->suppress_errors( true );
	$rows = $observer->get_results( "SELECT id, value FROM `$table` ORDER BY id", ARRAY_A );
	cc87_assert( is_array( $rows ) && 6 === count( $rows ), 'fresh observer must see six rows' );
	return array_map( static function ( $row ) { return (int) $row['value']; }, $rows );
}

function cc58_trace( $root, $normal_id, $runtime_id, $table, $invoke, $label ) {
	list( $result, $threads ) = cc87_trace_all( $root, $invoke );
	$updates = 0;
	$counter_events = 0;
	$normal_updates = 0;
	$redirection_updates = 0;
	$starts = 0;
	$commits = 0;
	$rollbacks = 0;
	foreach ( $threads as $id => $queries ) {
		foreach ( $queries as $sql ) {
			if ( preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`? SET value = [12] WHERE id BETWEEN 1 AND [56]/i', $sql ) ) {
				++$updates;
				cc87_assert( $id === $runtime_id, "$label demo UPDATE must use restricted connection" );
			}
			if ( $id === $normal_id && preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`?/i', $sql ) ) {
				++$normal_updates;
			}
			if ( preg_match( '/^UPDATE `?wp_redirection_items`?/i', $sql ) ) {
				++$redirection_updates;
			}
			if ( $id === $runtime_id ) {
				$counter_events += (int) (bool) preg_match( '/^UPDATE commitcap_v01_state SET consumed = consumed \+ 1 /i', $sql );
				$starts += (int) ( 'START TRANSACTION' === strtoupper( $sql ) );
				$commits += (int) ( 'COMMIT' === strtoupper( $sql ) );
				$rollbacks += (int) ( 'ROLLBACK' === strtoupper( $sql ) );
			}
		}
	}
	cc87_assert( 2 === $updates && 0 === $normal_updates, "$label exactly two restricted demo UPDATEs, zero normal UPDATEs: " . json_encode( $threads ) );
	cc87_assert( 0 === $redirection_updates, "$label must never UPDATE Redirection (including no-op Doctor probes)" );
	// Doctor probes only the demo target, once before each callback.
	cc87_assert( 13 === $counter_events, "$label expected 11 demo + 2 Doctor probe events: $counter_events" );
	cc87_assert( 4 === $starts && 1 === $commits && $rollbacks >= 3, "$label Guard and two demo Doctor probes own START/COMMIT/ROLLBACK: $starts/$commits/$rollbacks" );
	return $result;
}

for ( $cycle = 1; $cycle <= 2; ++$cycle ) {
	if ( 2 === $cycle ) {
		$setup->reset();
	}
	$result = cc58_trace( $installer, $normal_id, $runtime_id, $table, static function () use ( $demo ) {
		return $demo->run();
	}, "cycle $cycle" );
	$safe = $result['safe'];
	$denied = $result['denied'];
	cc87_assert( 'COMPLETE' === $result['status'], 'both demo paths ran: ' . json_encode( $result ) );
	cc87_assert( Demo::LABEL === $safe['label'] && 'COMMITTED' === $safe['outcome'] && 5 === $safe['consumed'] && 5 === $safe['affected_rows'], 'five events committed' );
	cc87_assert( 'DENIED' === $denied['outcome'] && 'logical' === $denied['denial_kind'] && 'logical_budget_exceeded' === $denied['reason'] && 6 === $denied['consumed'] && 6 === $denied['attempted'] && $denied['transaction_rollback_attempted'], 'six events logical denial after physical trigger accepted six: ' . json_encode( $denied ) );
	cc87_assert( false === $safe['durability_verified_by_fresh_observer'] && false === $denied['durability_verified_by_fresh_observer'] && null === $denied['guard_rollback_completed'], 'service does not manufacture fresh observer claims' );
	cc87_assert( array( 1, 1, 1, 1, 1, 0 ) === cc58_observe( $host, $table ), 'fresh observer proves safe durability and zero denied value=2' );
	cc87_assert( 0 === (int) $installer->get_var( $installer->prepare( 'SELECT COUNT(*) FROM commitcap_v01_state WHERE connection_id = %d', $runtime_id ) ), 'no stale accounting after safe/denied' );
	cc87_assert( $grants_before === $installer->get_results( "SHOW GRANTS FOR 'cc87_writer'@'%'", ARRAY_N ), 'runtime grants did not accumulate' );
	cc87_assert( $body_before === $installer->get_var( $installer->prepare( 'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $trigger ) ), 'no trigger duplication/replacement' );
	echo "#58 $host cycle $cycle: 5 COMMITTED (fresh observer 5), 6 logical DENIED (fresh observer zero denied), Guard rollback, normal wpdb UPDATE=0: PASS\n";
}

// Independent denied-only pass after a trusted reset: all SIX baseline rows
// start unchanged (zero), and a fresh observer sees the same six zeros.
$setup->reset();
$denied_only = $demo->run_denied();
cc87_assert( 'COMPLETE' === $denied_only['status'] && 'logical' === $denied_only['denied']['denial_kind'] &&
	6 === $denied_only['denied']['consumed'] && $denied_only['denied']['transaction_rollback_attempted'], 'standalone denied-only logical proof' );
cc87_assert( array( 0, 0, 0, 0, 0, 0 ) === cc58_observe( $host, $table ), 'fresh observer sees all six original rows after denied-only run' );
echo "#58 $host: trusted reset -> 6 logical DENIED -> fresh observer all six unchanged: PASS\n";

// No caller-supplied callback is accepted by the demo. Probe Guard's failure
// behavior directly on the same owned table and shared runtime.
$setup->reset();
foreach ( array( 'exception', 'db_error' ) as $failure ) {
	try {
		Guard::update( $table, 5, static function () use ( $runtime, $table, $failure ) {
			cc87_assert( false !== $runtime->query( "UPDATE `$table` SET value = 1 WHERE id BETWEEN 1 AND 5" ), 'fixture update' );
			if ( 'exception' === $failure ) {
				throw new RuntimeException( 'synthetic callback failure' );
			}
			$runtime->query( "UPDATE `$table` SET missing_column = 1 WHERE id = 1" );
		}, $runtime );
		throw new RuntimeException( 'Guard committed a failed callback' );
	} catch ( Guard_Error $error ) {
		cc87_assert( in_array( $error->reason(), array( 'callback_failed', 'database_error' ), true ), 'typed Guard failure: ' . $error->reason() );
	}
	cc87_assert( array( 0, 0, 0, 0, 0, 0 ) === cc58_observe( $host, $table ), 'fresh observer after ' . $failure . ' sees no mutation' );
	$runtime->last_error = '';
}

$absent = new wpdb( 'cc58_absent', 'absent', 'wp_test', $host );
$absent->suppress_errors( true );
cc87_assert( 'NOT_READY' === ( new Demo( $absent ) )->run()['status'], 'runtime unavailable no callback' );
cc87_query( $installer, "GRANT INSERT ON wp_test.`$table` TO 'cc87_writer'@'%'" );
cc87_assert( 'target_privileges_mismatch' === $demo->run()['reason'], 'extra INSERT fails closed' );
cc87_assert( 'UNKNOWN' === $setup->cleanup_status()['status'], 'extra grant blocks trusted cleanup' );
cc87_query( $installer, "REVOKE INSERT ON wp_test.`$table` FROM 'cc87_writer'@'%'" );
cc87_query( $installer, "DROP TRIGGER `$trigger`" );
cc87_assert( 'NOT_READY' === $demo->run()['status'], 'missing policy fails closed' );
$setup->setup();
cc87_assert( 'READY' === $demo->status()['status'], 'retry after policy repair deterministic' );
$engine = new Engine( $installer );
$engine->remove_owned_policy( $table, 6, 'cc87_writer' );
$engine->install_policy( $table, 7, 'cc87_writer' );
cc87_assert( 'physical_ceiling_mismatch' === $demo->run()['reason'], 'P=7 is not the reviewed demo P=6' );
$engine->remove_owned_policy( $table, 7, 'cc87_writer' );
$setup->setup();

// Foreign-bodied trigger at the expected name blocks BOTH setup and cleanup.
cc87_query( $installer, "DROP TRIGGER `$trigger`" );
cc87_query( $installer, "CREATE TRIGGER `$trigger` BEFORE UPDATE ON `$table` FOR EACH ROW SET @cc58_foreign = 1" );
cc87_assert( 'NOT_READY' === $demo->run()['status'], 'tampered policy fails closed' );
cc87_assert( 'UNKNOWN' === $setup->cleanup_status()['status'], 'tampered trigger blocks cleanup' );
try {
	$setup->cleanup();
	throw new RuntimeException( 'tampered trigger was removed' );
} catch ( RuntimeException $error ) {
	cc87_assert( false !== strpos( $error->getMessage(), 'foreign body' ), 'foreign trigger retained for operator' );
}
cc87_query( $installer, "DROP TRIGGER `$trigger`" ); // Fixture repairs its own tamper.
$setup->setup();
$setup->reset();
cc87_assert( 'COMPLETE' === $demo->run()['status'], 'retry works after trusted repair' );
cc87_assert( array( 1, 1, 1, 1, 1, 0 ) === cc58_observe( $host, $table ), 'retry fresh observer' );

// Interrupted cleanup: #84 removes the policy/grants first; retry the demo
// cleanup on the still-owned table without touching the shared runtime.
Plan::remove_target( 'wp_test', 'cc87_writer', '%', $table, 6 )->apply( $installer );
cc87_assert( 'OWNED' === $setup->cleanup_status()['status'], 'partially cleaned owned table is actionable' );
$removed = $setup->cleanup();
cc87_assert( 'REMOVED' === $removed['status'] && 'ABSENT' === $setup->cleanup_status()['status'], 'exact cleanup removes table and trigger' );
cc87_assert( 'ABSENT' === $setup->cleanup()['status'], 'cleanup replay is idempotent' );
$remaining = array_column( $installer->get_results( "SHOW GRANTS FOR 'cc87_writer'@'%'", ARRAY_N ), 0 );
cc87_assert( 'PASS' === Compatibility_Grants::from_statements( $remaining, 'wp_test' )->target_access_exact( $table, array(), false )[0], 'demo target grants removed exactly' );
cc87_assert( 0 === (int) $installer->get_var( $installer->prepare( 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $trigger ) ), 'only demo policy trigger removed' );
cc87_assert( 0 === (int) $installer->get_var( $installer->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ), 'only demo table removed' );
cc87_assert( 'NOT_READY' === $demo->status()['status'], 'after cleanup not READY' );
cc87_assert( $redirection_before === $installer->get_row( 'SELECT COUNT(*) AS total, COALESCE(SUM(status = "disabled"),0) AS disabled FROM wp_redirection_items', ARRAY_A ), 'Redirection data unchanged' );
cc87_assert( $redirection_body === $installer->get_var( $installer->prepare( 'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $redirection_trigger ) ), 'Redirection policy unchanged' );
cc87_assert( 'cc87_writer@%' === $runtime->get_var( 'SELECT CURRENT_USER()' ), 'shared runtime account preserved' );
cc87_assert( 2000 === ( new Engine( $runtime ) )->runtime_ceiling( 'wp_redirection_items' ), 'shared Redirection policy preserved' );
cc87_assert_normal( $normal, $normal_id, 'after #58' );
echo "#58 $host: failures fail closed, repeat/reset, exact cleanup, Redirection and shared runtime preserved: PASS\n";
