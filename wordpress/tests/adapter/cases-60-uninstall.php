<?php
// #60 repair: the actual WordPress uninstall.php removes only local
// WriteLeash-owned options; trusted DB infrastructure is operator lifecycle.
require_once WP_PLUGIN_DIR . '/writeleash/writeleash.php';
require_once __DIR__ . '/helpers.php';
if ( ! function_exists( 'uninstall_plugin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

use WriteLeash\Certified_Operation as Operation;
use WriteLeash\Disposable_Demo as Demo;
use WriteLeash\Disposable_Demo_Setup as Setup;
use WriteLeash\Last_Outcome;
use WriteLeash\Operation_Config as Config;
use WriteLeash\Update_Engine as Engine;

$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), '#60 uninstall pinned host' );
$plugin = 'writeleash/writeleash.php';
$normal = $GLOBALS['wpdb'];
$normal->suppress_errors( true );
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$operation = Operation::redirection_5_5_2_bulk_disable();
$demo_table = Demo::table( (string) $normal->prefix );
$table = 'wp_redirection_items';
$options_table = (string) $normal->options;
$owned_options = array(
	'writeleash_version',
	Config::STATE_OPTION,
	Last_Outcome::OPTION,
	'writeleash_operation_budget_redirection_5_5_2_bulk_disable',
);
// Finite pre-release development cleanup: exact old CommitCap option names only.
$legacy_cleanup = array(
	'commitcap_version',
	'commitcap_certified_operation_state',
	'commitcap_last_certified_outcome',
	'commitcap_operation_budget_redirection_5_5_2_bulk_disable',
);

// Drift regression: exact literals in the standalone uninstall file must match
// the production class constants (uninstall.php never bootstraps the plugin).
$uninstall_source = file_get_contents( WP_PLUGIN_DIR . '/writeleash/uninstall.php' );
cc87_assert( is_string( $uninstall_source ) && '' !== $uninstall_source, 'uninstall.php readable' );
foreach ( array_merge( $owned_options, $legacy_cleanup ) as $owned_option ) {
	cc87_assert( false !== strpos( $uninstall_source, "'" . $owned_option . "'" ), 'uninstall.php drift, missing literal: ' . $owned_option );
}

// Trusted fixture provisions the demo so its DB objects can be proven preserved.
$setup = new Setup( $root, 'cc87_writer', '%' );
$setup->setup();
$setup->reset();

// Real local state through production services, exactly as the product writes it.
Config::reset( $operation );
Config::set_logical_budget( $operation, 10 );
Config::set_enabled( $operation, true );
Last_Outcome::record( array(
	'operation_id'                     => Operation::REDIRECTION_BULK_DISABLE_ID,
	'outcome'                          => 'DENIED',
	'reason'                           => 'logical_budget_exceeded',
	'logical_budget'                   => 10,
	'physical_ceiling'                 => 2000,
	'attempted'                        => 11,
	'consumed'                         => 10,
	'affected_rows'                    => null,
	'denial_kind'                      => 'logical',
	'transaction_rollback_attempted'   => true,
	'guard_rollback_completed'         => null,
	'durability_verified_by_fresh_observer' => false,
) );
update_option( 'writeleash_operation_budget_redirection_5_5_2_bulk_disable', 1 );
update_option( 'writeleash_unrelated', 'keep me' );
update_option( 'commitcap_unrelated', 'keep me too' );
// Adversarial old-brand state: deleted by exact name, never read as authority.
update_option( 'commitcap_version', '0.1.0', false );
update_option( 'commitcap_certified_operation_state', array( 'operation_id' => Operation::REDIRECTION_BULK_DISABLE_ID, 'enabled' => true, 'logical_budget' => 2000 ), false );
update_option( 'commitcap_last_certified_outcome', array( 'outcome' => 'COMMITTED' ), false );
update_option( 'commitcap_operation_budget_redirection_5_5_2_bulk_disable', 1, false );
$state = Config::read( $operation );
cc87_assert( true === $state['enabled'] && 10 === $state['logical_budget'], 'seeded product config shape' );
cc87_assert( null !== Last_Outcome::read(), 'seeded last outcome invalid' );
echo "#60 $host: product local state seeded (enabled L=10, valid DENIED outcome, legacy option)\n";

// Exact DB infrastructure evidence before uninstall.
$redirection_rows_before = $root->get_results( "SELECT id, url, status FROM `$table` ORDER BY id", ARRAY_A );
$demo_rows_before = $root->get_results( "SELECT id, value FROM `$demo_table` ORDER BY id", ARRAY_A );
$grants_before = $root->get_results( "SHOW GRANTS FOR 'cc87_writer'@'%'", ARRAY_N );
$trigger_columns = 'TRIGGER_NAME, ACTION_STATEMENT, ACTION_TIMING, EVENT_MANIPULATION, DEFINER, SQL_MODE, CHARACTER_SET_CLIENT, COLLATION_CONNECTION';
$redirection_trigger_before = $root->get_row( $root->prepare( "SELECT $trigger_columns FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s", Engine::trigger_name( $table ) ), ARRAY_A );
$demo_trigger_before = $root->get_row( $root->prepare( "SELECT $trigger_columns FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s", Engine::trigger_name( $demo_table ) ), ARRAY_A );
$routine_columns = 'ROUTINE_NAME, ROUTINE_DEFINITION, SECURITY_TYPE, DEFINER, CREATED, LAST_ALTERED';
$routines_before = $root->get_results( "SELECT $routine_columns FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('writeleash_v01_open','writeleash_v01_close','writeleash_v01_count','writeleash_v01_policy','writeleash_v01_attest') ORDER BY ROUTINE_NAME", ARRAY_A );
$helper_before = $root->get_row( "SELECT TABLE_TYPE, ENGINE, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeleash_v01_state'", ARRAY_A );
$redirection_options_before = $root->get_results( "SELECT option_name, option_value FROM `$options_table` WHERE option_name LIKE 'redirection%' ORDER BY option_name", ARRAY_A );
cc87_assert( is_array( $redirection_trigger_before ) && is_array( $demo_trigger_before ), 'canonical triggers present before uninstall' );
cc87_assert( is_array( $helper_before ) && 5 === count( $routines_before ), 'helper and five routines present before uninstall' );

// Execute the real uninstall.php through WordPress's own entry point.
list( $returned, $threads ) = cc87_trace_all( $root, static function () use ( $plugin ) {
	return uninstall_plugin( $plugin );
} );
cc87_assert( true === $returned, 'uninstall_plugin() did not execute uninstall.php' );
cc87_assert( defined( 'WP_UNINSTALL_PLUGIN' ) && $plugin === WP_UNINSTALL_PLUGIN, 'uninstall ran under the WordPress uninstall guard' );

// Strict SQL classification: only bootstrap SELECTs, session SETs and the four
// exact wp_options deletions are allowed in the uninstall window.
$expected_deletes = array_fill_keys( array_merge( $owned_options, $legacy_cleanup ), 0 );
$unexpected = array();
foreach ( $threads as $statements ) {
	foreach ( $statements as $sql ) {
		if ( 0 === strpos( $sql, 'SELECT ' ) || 0 === strpos( $sql, 'SET ' ) ) {
			continue;
		}
		if ( preg_match( '/^DELETE FROM `?' . preg_quote( $options_table, '/' ) . '`? WHERE `?option_name`? = \'([^\']+)\'$/i', $sql, $match ) && isset( $expected_deletes[ $match[1] ] ) ) {
			++$expected_deletes[ $match[1] ];
			continue;
		}
		$unexpected[] = $sql;
	}
}
cc87_assert( array() === $unexpected, 'uninstall issued unexpected SQL (DDL/DCL/application/runtime or wildcard): ' . json_encode( $unexpected ) );
foreach ( $expected_deletes as $option_name => $count ) {
	cc87_assert( 1 === $count, 'expected exactly one exact option deletion for ' . $option_name . ', saw ' . $count );
}
foreach ( $threads as $statements ) {
	foreach ( $statements as $sql ) {
		cc87_assert( ! preg_match( '/\b(CREATE|ALTER|DROP|GRANT|REVOKE|TRUNCATE)\b/i', $sql ), 'uninstall DDL/DCL: ' . $sql );
		cc87_assert( false === stripos( $sql, 'writeleash_v01_' ), 'uninstall runtime object statement: ' . $sql );
		cc87_assert( false === stripos( $sql, $table ) && false === stripos( $sql, $demo_table ), 'uninstall application/demo statement: ' . $sql );
	}
}

// Exact local state removal, with existence checks against wp_options rows.
foreach ( array_merge( $owned_options, $legacy_cleanup ) as $owned_option ) {
	$remaining = (string) $root->get_var( $root->prepare( "SELECT COUNT(*) FROM `$options_table` WHERE option_name = %s", $owned_option ) );
	cc87_assert( '0' === $remaining, 'uninstall left owned local state: ' . $owned_option );
}
cc87_assert( 'keep me' === get_option( 'writeleash_unrelated' ), 'uninstall removed an unrelated writeleash_-prefixed option' );
cc87_assert( 'keep me too' === get_option( 'commitcap_unrelated' ), 'uninstall wildcard-deleted an unrelated commitcap_-prefixed option' );

// Trusted DB infrastructure must be byte-identical and still canonical.
$redirection_trigger_after = $root->get_row( $root->prepare( "SELECT $trigger_columns FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s", Engine::trigger_name( $table ) ), ARRAY_A );
$demo_trigger_after = $root->get_row( $root->prepare( "SELECT $trigger_columns FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s", Engine::trigger_name( $demo_table ) ), ARRAY_A );
$routines_after = $root->get_results( "SELECT $routine_columns FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME IN ('writeleash_v01_open','writeleash_v01_close','writeleash_v01_count','writeleash_v01_policy','writeleash_v01_attest') ORDER BY ROUTINE_NAME", ARRAY_A );
$helper_after = $root->get_row( "SELECT TABLE_TYPE, ENGINE, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeleash_v01_state'", ARRAY_A );
cc87_assert( $grants_before === $root->get_results( "SHOW GRANTS FOR 'cc87_writer'@'%'", ARRAY_N ), 'uninstall changed runtime grants' );
cc87_assert( $redirection_trigger_before === $redirection_trigger_after, 'uninstall changed the Redirection policy trigger' );
cc87_assert( $demo_trigger_before === $demo_trigger_after, 'uninstall changed the demo policy trigger' );
cc87_assert( $routines_before === $routines_after, 'uninstall changed the five reviewed routines' );
cc87_assert( $helper_before === $helper_after, 'uninstall changed the helper table metadata' );
cc87_assert( $redirection_rows_before === $root->get_results( "SELECT id, url, status FROM `$table` ORDER BY id", ARRAY_A ), 'uninstall changed Redirection rows' );
cc87_assert( $demo_rows_before === $root->get_results( "SELECT id, value FROM `$demo_table` ORDER BY id", ARRAY_A ), 'uninstall changed demo rows' );
cc87_assert( $redirection_options_before === $root->get_results( "SELECT option_name, option_value FROM `$options_table` WHERE option_name LIKE 'redirection%' ORDER BY option_name", ARRAY_A ), 'uninstall changed Redirection plugin options' );
$engine = new Engine( $root );
$engine->verify_infrastructure_objects();
$engine->verify_policy( $table, 2000, 'cc87_writer' );
$engine->verify_policy( $demo_table, Demo::PHYSICAL_CEILING, 'cc87_writer' );
$runtime = new wpdb( 'cc87_writer', 'cc87_secret', 'wp_test', $host );
$runtime->suppress_errors( true );
$runtime->set_prefix( (string) $normal->prefix );
cc87_assert( $runtime->ready && 2000 === ( new Engine( $runtime ) )->runtime_ceiling( $table ), 'shared runtime account unusable after uninstall' );
cc87_assert( Demo::PHYSICAL_CEILING === ( new Engine( $runtime ) )->runtime_ceiling( $demo_table ), 'demo policy P=6 unusable after uninstall' );
echo "#60 $host: actual uninstall removed only 8 exact options (4 canonical + 4 pre-release development cleanup); runtime/grants/routines/helper/Redirection+demo policy and data unchanged; zero privileged SQL: PASS\n";
