<?php
// #63 current-core lifecycle sanity after the deactivation boundary: WordPress
// uninstall removes only local plugin state, trusted database infrastructure
// and Redirection data survive, and a reinstall starts clean.
require_once WP_PLUGIN_DIR . '/writeleash/writeleash.php';
require_once __DIR__ . '/../adapter/helpers.php';
if ( ! function_exists( 'uninstall_plugin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

use WriteLeash\Certified_Operation as Operation;
use WriteLeash\Last_Outcome;
use WriteLeash\Operation_Config as Config;
use WriteLeash\Update_Engine as Engine;

$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), '#63 pinned host' );
$plugin = 'writeleash/writeleash.php';
$table  = 'wp_redirection_items';
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$normal = $GLOBALS['wpdb'];
$normal->suppress_errors( true );
$options_table = (string) $normal->options;
$operation = Operation::redirection_5_5_2_bulk_disable();

// Seed local state through production services, plus durable Redirection rows.
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
// Adversarial pre-release CommitCap state: must be deleted by the finite
// exact-name development cleanup and can never be recovered by reinstall.
update_option( 'commitcap_version', '0.1.0', false );
update_option(
	'commitcap_certified_operation_state',
	array( 'operation_id' => Operation::REDIRECTION_BULK_DISABLE_ID, 'enabled' => true, 'logical_budget' => 2000 ),
	false
);
update_option(
	'commitcap_last_certified_outcome',
	array(
		'schema_version'                        => 1,
		'timestamp'                             => '2026-01-01T00:00:00Z',
		'operation_id'                          => Operation::REDIRECTION_BULK_DISABLE_ID,
		'outcome'                               => 'COMMITTED',
		'reason'                                => 'ok',
		'logical_budget'                        => 2000,
		'physical_ceiling'                      => 2000,
		'attempted'                             => null,
		'consumed'                              => 6,
		'affected_rows'                         => 6,
		'denial_kind'                           => null,
		'transaction_rollback_attempted'        => false,
		'guard_rollback_completed'              => null,
		'durability_verified_by_fresh_observer' => false,
	),
	false
);
update_option( 'commitcap_operation_budget_redirection_5_5_2_bulk_disable', 1, false );
cc87_seed_bulk( $root, 3 );
$owned = array(
	'writeleash_version', Config::STATE_OPTION, Last_Outcome::OPTION, 'writeleash_operation_budget_redirection_5_5_2_bulk_disable',
	// Finite pre-release development cleanup list, deleted by exact name only.
	'commitcap_version', 'commitcap_certified_operation_state', 'commitcap_last_certified_outcome',
	'commitcap_operation_budget_redirection_5_5_2_bulk_disable',
);
foreach ( $owned as $option ) {
	$count = (int) $root->get_var( $root->prepare( "SELECT COUNT(*) FROM `$options_table` WHERE option_name = %s", $option ) );
	cc87_assert( $count > 0, '#63 local state not seeded: ' . $option );
}

$trigger = Engine::trigger_name( $table );
$trigger_before = $root->get_row( $root->prepare( 'SELECT ACTION_STATEMENT, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $trigger ), ARRAY_A );
$grants_before = $root->get_results( "SHOW GRANTS FOR 'cc63_writer'@'%'", ARRAY_N );
$routines_before = $root->get_results( "SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE 'writeleash_v01_%' ORDER BY ROUTINE_NAME", ARRAY_N );
$rows_before = $root->get_results( "SELECT id, url, status FROM `$table` ORDER BY id", ARRAY_A );
cc87_assert( is_array( $trigger_before ) && 5 === count( $routines_before ), '#63 trusted infrastructure missing before uninstall' );

uninstall_plugin( $plugin );
foreach ( $owned as $option ) {
	$count = (int) $root->get_var( $root->prepare( "SELECT COUNT(*) FROM `$options_table` WHERE option_name = %s", $option ) );
	cc87_assert( 0 === $count, '#63 uninstall left local state: ' . $option );
}
cc87_assert( $trigger_before === $root->get_row( $root->prepare( 'SELECT ACTION_STATEMENT, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $trigger ), ARRAY_A ), '#63 uninstall changed the policy trigger' );
cc87_assert( $grants_before === $root->get_results( "SHOW GRANTS FOR 'cc63_writer'@'%'", ARRAY_N ), '#63 uninstall changed runtime grants' );
cc87_assert( $routines_before === $root->get_results( "SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE 'writeleash_v01_%' ORDER BY ROUTINE_NAME", ARRAY_N ), '#63 uninstall changed the reviewed routines' );
cc87_assert( $rows_before === $root->get_results( "SELECT id, url, status FROM `$table` ORDER BY id", ARRAY_A ), '#63 uninstall changed Redirection rows' );
$after_uninstall_runtime = new wpdb( 'cc63_writer', 'cc63_runtime_secret', 'wp_test', $host );
$after_uninstall_runtime->suppress_errors( true );
cc87_assert( $after_uninstall_runtime->ready && 2000 === ( new Engine( $after_uninstall_runtime ) )->runtime_ceiling( $table ), '#63 runtime unusable after plugin uninstall' );
echo "#63 current-core $host: uninstall removed only local state; trusted infrastructure/grants/routines and Redirection rows survived: PASS\n";

// Reinstall semantics: activation recreates metadata only, never old state.
deactivate_plugins( $plugin );
cc87_assert( null === activate_plugin( $plugin ) && is_plugin_active( $plugin ), '#63 reinstall activation failed' );
cc87_assert( '0.2.0' === get_option( 'writeleash_version' ), '#63 reinstall did not recreate metadata' );
$state = Config::read( $operation );
cc87_assert( 'absent' === $state['state'] && false === $state['enabled'] && null === $state['logical_budget'], '#63 reinstall recovered old config' );
cc87_assert( null === Last_Outcome::read(), '#63 reinstall recovered old evidence' );
echo "#63 current-core $host: reinstall starts disabled with no budget and no stale evidence: PASS\n";
