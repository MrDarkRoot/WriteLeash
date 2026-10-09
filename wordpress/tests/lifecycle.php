<?php
// Executed by WP-CLI after WordPress boots, not by PHP alone.
function cc_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function cc_snapshot() {
	global $wpdb;
	return array(
		'tables'   => $wpdb->get_col( 'SHOW TABLES' ),
		'triggers' => $wpdb->get_col( 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()' ),
		'routines' => $wpdb->get_col( 'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()' ),
		'posts'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
		'users'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
	);
}

$plugin = 'writeleash/writeleash.php';
cc_assert( ! is_plugin_active( $plugin ), 'Plugin active before first activation' );
cc_assert( false === get_option( 'writeleash_version' ), 'Metadata before first activation' );
add_option( 'writeleash_unrelated', 'keep me' );
add_option( 'commitcap_unrelated', 'keep me too' );
add_option( 'writeleash_other', 'not owned' );
add_option( 'commitcap_other', 'not owned either' );

// #96 adversarial pre-release state: a fully forged old CommitCap world must
// never become WriteLeash authority, evidence or readiness input.
update_option( 'commitcap_version', '0.1.0', false );
// Literal operation id: classes are not loaded yet in this pre-activation phase.
update_option(
	'commitcap_certified_operation_state',
	array(
		'operation_id'   => 'redirection-5.5.2-bulk-disable-global',
		'enabled'        => true,
		'logical_budget' => 2000,
	),
	false
);
update_option(
	'commitcap_last_certified_outcome',
	array(
		'schema_version'                         => 1,
		'timestamp'                              => '2026-01-01T00:00:00Z',
		'operation_id'                           => 'redirection-5.5.2-bulk-disable-global',
		'outcome'                                => 'COMMITTED',
		'reason'                                 => 'ok',
		'logical_budget'                         => 2000,
		'physical_ceiling'                       => 2000,
		'attempted'                              => null,
		'consumed'                               => 6,
		'affected_rows'                          => 6,
		'denial_kind'                            => null,
		'transaction_rollback_attempted'         => false,
		'guard_rollback_completed'               => null,
		'durability_verified_by_fresh_observer'  => false,
	),
	false
);
update_option( 'commitcap_operation_budget_redirection_5_5_2_bulk_disable', 1, false );
set_transient( 'commitcap_notice_1', array( 'status' => 'COMPLETE', 'reason' => 'guard_paths_exercised' ), 120 );

$before = cc_snapshot();
require_once WP_PLUGIN_DIR . '/' . $plugin;
cc_assert( $plugin === plugin_basename( WRITELEASH_PLUGIN_FILE ), 'Wrong WordPress plugin basename' );
cc_assert( false === get_option( 'writeleash_version' ), 'Bootstrap wrote metadata' );
cc_assert( $before === cc_snapshot(), 'Bootstrap changed DB objects or user tables' );

$result = activate_plugin( $plugin );
cc_assert( null === $result, 'WordPress activation returned error' );
cc_assert( is_plugin_active( $plugin ), 'Plugin did not activate' );
cc_assert( class_exists( 'WriteLeash\\Plugin' ), 'Plugin bootstrap not loaded' );
cc_assert( '0.2.0' === \WriteLeash\Plugin::version(), 'Wrong plugin version' );
cc_assert( false === \WriteLeash\Plugin::can_manage(), 'Non-admin may manage plugin' );
wp_set_current_user( 1 );
cc_assert( \WriteLeash\Plugin::can_manage(), 'Admin capability check failed' );
cc_assert( '' !== \WriteLeash\Environment::wordpress_version(), 'No WordPress version' );
cc_assert( \WriteLeash\Environment::has_wpdb(), 'No wpdb' );
cc_assert( '' !== \WriteLeash\Environment::database_version(), 'No database identity' );
cc_assert( '0.2.0' === get_option( 'writeleash_version' ), 'Missing owned metadata' );
cc_assert( $before === cc_snapshot(), 'Activation changed user tables or database objects' );
echo "Bootstrap and activation: PASS\n";

// Old CommitCap local state must stay inert: it is neither migrated nor read,
// so it cannot enable the operation, supply a budget, populate Last_Outcome or
// affect readiness. The old keys remain physically untouched by activation.
$old_operation = \WriteLeash\Certified_Operation::redirection_5_5_2_bulk_disable();
$config = \WriteLeash\Operation_Config::read( $old_operation );
cc_assert( 'absent' === $config['state'], 'Old CommitCap state became WriteLeash config authority' );
cc_assert( false === $config['enabled'], 'Old CommitCap state enabled WriteLeash' );
cc_assert( null === $config['logical_budget'], 'Old CommitCap state supplied a WriteLeash budget' );
cc_assert( null === \WriteLeash\Last_Outcome::read(), 'Old CommitCap state populated WriteLeash evidence' );
cc_assert( false === get_transient( 'writeleash_notice_1' ), 'Old CommitCap transient populated a WriteLeash notice' );
$readiness = \WriteLeash\Certified_Operation_Status::check( $old_operation, '5.5.2' );
cc_assert( \WriteLeash\Certified_Operation_Status::DISABLED === $readiness['status'], 'Old CommitCap state affected readiness' );
cc_assert( 'operation_disabled' === $readiness['reason'], 'Old CommitCap state changed the readiness reason' );
cc_assert( is_array( get_option( 'commitcap_certified_operation_state' ) ), 'Activation migrated or deleted old CommitCap state' );
cc_assert( is_array( get_option( 'commitcap_last_certified_outcome' ) ), 'Activation migrated or deleted old CommitCap outcome' );
echo "Old CommitCap state ignored: PASS\n";

// WordPress skips a second activate_plugin() for an active plugin; exercise the
// hook too, to prove its one-option metadata path remains idempotent.
cc_assert( null === activate_plugin( $plugin ), 'Double activation failed' );
\WriteLeash\Lifecycle::activate();
cc_assert( '0.2.0' === get_option( 'writeleash_version' ), 'Double activation changed metadata' );
cc_assert( $before === cc_snapshot(), 'Double activation changed DB objects' );
echo "Double activation: PASS\n";

deactivate_plugins( $plugin );
cc_assert( ! is_plugin_active( $plugin ), 'Plugin still active after deactivation' );
cc_assert( '0.2.0' === get_option( 'writeleash_version' ), 'Deactivation deleted persistent metadata' );
cc_assert( $before === cc_snapshot(), 'Deactivation changed DB objects' );
echo "Deactivation: PASS\n";

// The merged product owns three local options plus one stale #87 compatibility
// option. Seed them through the production services, then prove the real
// uninstall.php removes exactly those (plus the finite pre-release development
// cleanup list) and nothing else: no wildcard deletion, no migration.
$owned_operation = \WriteLeash\Certified_Operation::redirection_5_5_2_bulk_disable();
\WriteLeash\Operation_Config::set_logical_budget( $owned_operation, 10 );
\WriteLeash\Operation_Config::set_enabled( $owned_operation, true );
\WriteLeash\Last_Outcome::record( array(
	'operation_id'                     => \WriteLeash\Certified_Operation::REDIRECTION_BULK_DISABLE_ID,
	'outcome'                          => 'COMMITTED',
	'reason'                           => 'ok',
	'logical_budget'                   => 10,
	'physical_ceiling'                 => 2000,
	'attempted'                        => null,
	'consumed'                         => 6,
	'affected_rows'                    => 6,
	'denial_kind'                      => null,
	'transaction_rollback_attempted'   => false,
	'guard_rollback_completed'         => null,
	'durability_verified_by_fresh_observer' => false,
) );
update_option( 'writeleash_operation_budget_redirection_5_5_2_bulk_disable', 1, false );
cc_assert( 'ok' === \WriteLeash\Operation_Config::read( $owned_operation )['state'], 'Product config not seeded' );
cc_assert( null !== \WriteLeash\Last_Outcome::read(), 'Product outcome not seeded' );

uninstall_plugin( $plugin );
$options_db = $GLOBALS['wpdb'];
$deleted = array(
	'writeleash_version',
	\WriteLeash\Operation_Config::STATE_OPTION,
	\WriteLeash\Last_Outcome::OPTION,
	'writeleash_operation_budget_redirection_5_5_2_bulk_disable',
	// #109 schema/setup/lifecycle metadata.
	'writeleash_job_schema',
	'writeleash_job_setup',
	'writeleash_runner_state',
	// #110 Undo/history/retention metadata, deleted by exact name only.
	'writeleash_undo_schema',
	'writeleash_undo_setup',
	// Finite pre-release development cleanup list, deleted by exact name only.
	'commitcap_version',
	'commitcap_certified_operation_state',
	'commitcap_last_certified_outcome',
	'commitcap_operation_budget_redirection_5_5_2_bulk_disable',
);
foreach ( $deleted as $owned_option ) {
	$remaining = (string) $options_db->get_var( $options_db->prepare( 'SELECT COUNT(*) FROM ' . $options_db->options . ' WHERE option_name = %s', $owned_option ) );
	cc_assert( '0' === $remaining, 'Uninstall left owned state: ' . $owned_option );
}
// Sentinels prove exact-name deletion: unrelated and prefix-similar keys stay.
foreach ( array( 'writeleash_unrelated', 'commitcap_unrelated', 'writeleash_other', 'commitcap_other' ) as $sentinel ) {
	cc_assert( false !== get_option( $sentinel ), 'Uninstall wildcard-deleted unrelated metadata: ' . $sentinel );
}
cc_assert( $before === cc_snapshot(), 'Uninstall changed user tables or DB objects' );
echo "Uninstall: PASS\n";
