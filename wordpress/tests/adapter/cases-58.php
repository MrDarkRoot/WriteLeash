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
cc87_assert( 'trusted_reset_required' === $demo->status()['reason'], 'unseeded table is not runnable' );
$setup->reset();
$grants_before = $installer->get_results( "SHOW GRANTS FOR 'cc87_writer'@'%'", ARRAY_N );
$body_before = $installer->get_var( $installer->prepare( 'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $trigger ) );
cc87_assert( 'OWNED' === $setup->cleanup_status()['status'], 'owned cleanup inventory' );
$initial = $demo->status();
cc87_assert( 'READY' === $initial['status'] && 'A' === $initial['canonical_state'] && 'PASS' === $initial['exact_grants'], 'Doctor READY in canonical A with exact grants and shared sibling' );
echo "#58 $host: owned target=$table L=5 P=6 Doctor READY state=A exact grants SELECT,UPDATE (one trusted seed/reset)\n";

function cc58_observe( $host, $table ) {
	// New connection after Guard returns. Never use the restricted transaction connection.
	$observer = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
	$observer->suppress_errors( true );
	$rows = $observer->get_results( "SELECT id, value FROM `$table` ORDER BY id", ARRAY_A );
	cc87_assert( is_array( $rows ) && 6 === count( $rows ), 'fresh observer must see six rows' );
	cc87_assert( 0 === (int) $observer->get_var( "SELECT COUNT(*) FROM `$table` WHERE value = 2" ), 'denied value 2 must not be durable' );
	return array_map( static function ( $row ) { return (int) $row['value']; }, $rows );
}

/** Cumulative exact SQL counts on the restricted thread while log stays on. */
function cc58_update_counts( $root, $runtime_id, $table ) {
	$queries = $root->get_col( $root->prepare(
		"SELECT argument FROM mysql.general_log WHERE thread_id = %d AND command_type = 'Query'", $runtime_id
	) );
	cc87_assert( is_array( $queries ) && '' === (string) $root->last_error, 'per-run general log readable' );
	$counts = array( 0 => 0, 1 => 0, 2 => 0 );
	foreach ( $queries as $query ) {
		$sql = preg_replace( '/\s+/', ' ', trim( (string) $query ) );
		if ( "UPDATE `$table` SET value = 0 WHERE id BETWEEN 1 AND 5 AND value = 1" === $sql ) {
			++$counts[0];
		} elseif ( "UPDATE `$table` SET value = 1 WHERE id BETWEEN 1 AND 5 AND value = 0" === $sql ) {
			++$counts[1];
		} elseif ( "UPDATE `$table` SET value = 2 WHERE id BETWEEN 1 AND 6" === $sql ) {
			++$counts[2];
		}
	}
	return $counts;
}

/** One uninterrupted general-log window covers all THREE ordinary runs. */
function cc58_assert_repeat_trace( $threads, $normal_id, $runtime_id, $table ) {
	$safe_updates = array( 0 => 0, 1 => 0 );
	$denied_updates = 0;
	$doctor_probes = 0;
	$counter_events = 0;
	$normal_updates = 0;
	$redirection_updates = 0;
	$other_demo_writes = 0;
	$privileged_sql = 0;
	$starts = 0;
	$commits = 0;
	$rollbacks = 0;
	foreach ( $threads as $id => $queries ) {
		foreach ( $queries as $sql ) {
			if ( preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`? SET value = ([01]) WHERE id BETWEEN 1 AND 5 AND value = ([01])$/i', $sql, $match ) &&
				(int) $match[1] !== (int) $match[2] ) {
				++$safe_updates[ (int) $match[1] ];
				cc87_assert( $id === $runtime_id, 'safe UPDATE must use restricted connection' );
			} elseif ( preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`? SET value = 2 WHERE id BETWEEN 1 AND 6$/i', $sql ) ) {
				++$denied_updates;
				cc87_assert( $id === $runtime_id, 'denied UPDATE must use restricted connection' );
			} elseif ( preg_match( '/^UPDATE `' . preg_quote( $table, '/' ) . '` SET `id` = `id` LIMIT 1$/i', $sql ) ) {
				++$doctor_probes;
				cc87_assert( $id === $runtime_id, 'Doctor no-op probe must use restricted connection' );
			} elseif ( preg_match( '/^(UPDATE|INSERT|DELETE|REPLACE|TRUNCATE) (?:INTO |FROM |TABLE )?`?' . preg_quote( $table, '/' ) . '`?(?=\s|$|\()/i', $sql ) ) {
				++$other_demo_writes;
			}
			if ( $id === $normal_id && preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`?/i', $sql ) ) {
				++$normal_updates;
			}
			if ( preg_match( '/^UPDATE `?wp_redirection_items`?/i', $sql ) ) {
				++$redirection_updates;
			}
			if ( preg_match( '/^(CREATE|DROP|ALTER|GRANT|REVOKE|TRUNCATE)\b/i', $sql ) ) {
				++$privileged_sql;
			}
			if ( $id === $runtime_id ) {
				$counter_events += (int) (bool) preg_match( '/^UPDATE commitcap_v01_state SET consumed = consumed \+ 1 /i', $sql );
				$starts += (int) ( 'START TRANSACTION' === strtoupper( $sql ) );
				$commits += (int) ( 'COMMIT' === strtoupper( $sql ) );
				$rollbacks += (int) ( 'ROLLBACK' === strtoupper( $sql ) );
			}
		}
	}
	cc87_assert( array( 0 => 1, 1 => 2 ) === $safe_updates && 3 === $denied_updates && 9 === $doctor_probes,
		'exactly one restricted safe and denied UPDATE per run, toggling A/B/A (safe=' . json_encode( $safe_updates ) . " denied=$denied_updates probes=$doctor_probes)" );
	cc87_assert( 0 === $normal_updates && 0 === $other_demo_writes && 0 === $privileged_sql,
		"zero normal wpdb UPDATE, demo INSERT/DELETE/reset or DDL/DCL across all three runs (normal=$normal_updates other=$other_demo_writes privileged=$privileged_sql)" );
	cc87_assert( 0 === $redirection_updates, 'demo must never UPDATE Redirection (including Doctor no-ops)' );
	// Three runs x (5 safe + 6 denied + 3 demo Doctor no-op probes,
	// including the post-run READY check inside this uninterrupted log window).
	cc87_assert( 42 === $counter_events, "three runs expected 33 demo + 9 Doctor physical events: $counter_events" );
	cc87_assert( 15 === $starts && 3 === $commits && $rollbacks >= 12,
		"Guard and demo Doctor probes own START/COMMIT/ROLLBACK: $starts/$commits/$rollbacks" );
}

// A denied-only call from A uses restricted UPDATE and leaves all six rows A.
$denied_a = $demo->run_denied();
cc87_assert( 'COMPLETE' === $denied_a['status'] && 'A' === $denied_a['input_state'] &&
	'logical' === $denied_a['denied']['denial_kind'] && 6 === $denied_a['denied']['consumed'] &&
	6 === $denied_a['denied']['attempted'] && $denied_a['denied']['transaction_rollback_attempted'], 'A denied-only logical proof' );
cc87_assert( array( 0, 0, 0, 0, 0, 0 ) === cc58_observe( $host, $table ), 'denied-only fresh observer A preserved' );
cc87_assert( 'A' === $demo->status()['canonical_state'], 'A remains READY after denied-only' );

// Logging stays enabled across all three calls, intervening status checks and
// independently connected observers. No hidden trusted reset can escape it.
list( $runs, $threads ) = cc87_trace_all( $installer, static function () use ( $demo, $host, $table, $runtime_id, $installer ) {
	$runs = array();
	$previous_counts = array( 0 => 0, 1 => 0, 2 => 0 );
	foreach ( array( 1 => 'B', 2 => 'A', 3 => 'B' ) as $number => $next ) {
		$result = $demo->run(); // Deliberately NO setup/reset between runs.
		$safe = isset( $result['safe'] ) ? $result['safe'] : array();
		$denied = isset( $result['denied'] ) ? $result['denied'] : array();
		cc87_assert( 'COMPLETE' === $result['status'], "run #$number must complete: " . json_encode( $result ) );
		cc87_assert( $next === $result['safe_state'] && ( 'B' === $next ? 'A' : 'B' ) === $result['input_state'], "run #$number toggled the canonical state" );
		cc87_assert( Demo::LABEL === $safe['label'] && 'COMMITTED' === $safe['outcome'] && 5 === $safe['consumed'] && 5 === $safe['affected_rows'], "run #$number safe five events" );
		cc87_assert( 'DENIED' === $denied['outcome'] && 'logical' === $denied['denial_kind'] && 'logical_budget_exceeded' === $denied['reason'] &&
			6 === $denied['consumed'] && 6 === $denied['attempted'] && $denied['transaction_rollback_attempted'], "run #$number six events logically denied" );
		cc87_assert( false === $safe['durability_verified_by_fresh_observer'] && false === $denied['durability_verified_by_fresh_observer'] && null === $denied['guard_rollback_completed'], 'service must not claim independent rollback proof' );
		$counts = cc58_update_counts( $installer, $runtime_id, $table );
		$delta = array( $counts[0] - $previous_counts[0], $counts[1] - $previous_counts[1], $counts[2] - $previous_counts[2] );
		$expected_sql = 'B' === $next ? array( 0, 1, 1 ) : array( 1, 0, 1 );
		cc87_assert( $expected_sql === $delta, "run #$number has exactly one restricted safe and one restricted denied UPDATE: " . json_encode( $delta ) );
		$previous_counts = $counts;
		$observed = cc58_observe( $host, $table );
		$expected = 'B' === $next ? array( 1, 1, 1, 1, 1, 0 ) : array( 0, 0, 0, 0, 0, 0 );
		cc87_assert( $expected === $observed, "run #$number fresh observer state $next; no denied 2" );
		$status = $demo->status();
		cc87_assert( 'READY' === $status['status'] && $next === $status['canonical_state'], "run #$number must leave READY runnable state $next" );
		cc87_assert( 0 === (int) $installer->get_var( $installer->prepare( 'SELECT COUNT(*) FROM commitcap_v01_state WHERE connection_id = %d', $runtime_id ) ), 'no stale accounting' );
		$runs[] = $result;
		echo "#58 $host normal run #$number: 5 COMMITTED, 6 logical DENIED, fresh observer STATE_$next, status READY, no trusted reset: PASS\n";
	}
	return $runs;
} );
cc87_assert( 3 === count( $runs ), 'three normal runs without installer mutation' );
cc58_assert_repeat_trace( $threads, $normal_id, $runtime_id, $table );
cc87_assert( $grants_before === $installer->get_results( "SHOW GRANTS FOR 'cc87_writer'@'%'", ARRAY_N ), 'no privilege accumulation across three normal runs' );
cc87_assert( $body_before === $installer->get_var( $installer->prepare( 'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $trigger ) ), 'no trigger duplication across three normal runs' );
echo "#58 $host: one trusted reset -> runs #1/#2/#3 (B/A/B) with ZERO demo INSERT/DELETE, DDL/DCL or trusted reset SQL: PASS\n";

// B denied-only requires no reset after run #3 and preserves B durably.
$denied_b = $demo->run_denied();
cc87_assert( 'COMPLETE' === $denied_b['status'] && 'B' === $denied_b['input_state'] &&
	'logical' === $denied_b['denied']['denial_kind'] && 6 === $denied_b['denied']['consumed'] &&
	6 === $denied_b['denied']['attempted'] && $denied_b['denied']['transaction_rollback_attempted'], 'B denied-only logical proof' );
cc87_assert( array( 1, 1, 1, 1, 1, 0 ) === cc58_observe( $host, $table ), 'denied-only fresh observer B preserved' );
cc87_assert( 'READY' === $demo->status()['status'] && 'B' === $demo->status()['canonical_state'], 'B remains runnable after denied-only' );
echo "#58 $host: denied-only from A and B: logical DENIED, both canonical states preserved by fresh observers: PASS\n";

// Recovery is the ONLY reason for another trusted reset: corrupt a demo-owned
// row deliberately and verify Doctor never reports runnable until repaired.
cc87_query( $installer, "UPDATE `$table` SET value = 0 WHERE id = 3" );
$noncanonical = $demo->status();
cc87_assert( 'NOT_READY' === $noncanonical['status'] && 'trusted_reset_required' === $noncanonical['reason'], 'partial/corrupt state is not READY' );
list( $refused, $refusal_sql ) = cc87_trace_all( $installer, static function () use ( $demo ) { return $demo->run(); } );
cc87_assert( 'NOT_READY' === $refused['status'] && 'trusted_reset_required' === $refused['reason'], 'noncanonical run refuses callback' );
foreach ( $refusal_sql as $queries ) {
	foreach ( $queries as $sql ) {
		cc87_assert( ! preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`? SET value = /i', $sql ) ||
			(bool) preg_match( '/ LIMIT 1$/i', $sql ), 'noncanonical callback UPDATE must not execute' );
	}
}
cc87_assert( array( 1, 1, 0, 1, 1, 0 ) === cc58_observe( $host, $table ), 'refused run leaves noncanonical data unchanged' );
cc87_query( $installer, "UPDATE `$table` SET value = 1 WHERE id = 3" );
cc87_query( $installer, "UPDATE `$table` SET id = 7 WHERE id = 6" );
cc87_assert( 'trusted_reset_required' === $demo->status()['reason'], 'wrong id with six rows not runnable' );
cc87_query( $installer, "DELETE FROM `$table` WHERE id = 7" );
cc87_assert( 'trusted_reset_required' === $demo->status()['reason'], 'wrong row count not runnable' );
$setup->reset(); // Trusted recovery, never part of the normal three-run sequence.
cc87_assert( 'READY' === $demo->status()['status'] && 'A' === $demo->status()['canonical_state'], 'trusted recovery restores READY A' );
cc87_assert( 'COMPLETE' === $demo->run()['status'] && array( 1, 1, 1, 1, 1, 0 ) === cc58_observe( $host, $table ), 'normal demo works after trusted recovery' );
echo "#58 $host: noncanonical NOT_READY/no callback -> trusted recovery reset -> normal COMPLETE: PASS\n";

// Test-only interruption simulation using existing Guard (no new production
// partial-run API): stop after the safe COMMIT; next ordinary run uses B.
cc87_assert( 'COMPLETE' === $demo->run()['status'] && 'A' === $demo->status()['canonical_state'], 'prepare A with only restricted normal run' );
$interrupted_safe = Guard::update( $table, 5, static function () use ( $runtime, $table ) {
	$affected = $runtime->query( "UPDATE `$table` SET value = 1 WHERE id BETWEEN 1 AND 5 AND value = 0" );
	return array( 'affected' => $affected, 'consumed' => ( new Engine( $runtime ) )->state_consumed( $table ) );
}, $runtime );
cc87_assert( 5 === $interrupted_safe['consumed'] && 5 === $interrupted_safe['affected'], 'safe-only Guard COMMIT counted five' );
cc87_assert( array( 1, 1, 1, 1, 1, 0 ) === cc58_observe( $host, $table ) && 'B' === $demo->status()['canonical_state'], 'safe-only COMMIT leaves runnable B' );
$resumed = $demo->run();
cc87_assert( 'COMPLETE' === $resumed['status'] && 'B' === $resumed['input_state'] && 'A' === $resumed['safe_state'] &&
	array( 0, 0, 0, 0, 0, 0 ) === cc58_observe( $host, $table ), 'fresh normal run after interruption B -> A without reset' );
echo "#58 $host: test-only stop after safe COMMIT B -> next normal run succeeds B/A without reset: PASS\n";

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
echo "#58 $host: failures fail closed, trusted recovery, exact cleanup, Redirection and shared runtime preserved: PASS\n";
