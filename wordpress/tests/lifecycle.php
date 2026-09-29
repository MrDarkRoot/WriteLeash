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

$plugin = 'commitcap/commitcap.php';
cc_assert( ! is_plugin_active( $plugin ), 'Plugin active before first activation' );
cc_assert( false === get_option( 'commitcap_version' ), 'Metadata before first activation' );
add_option( 'commitcap_unrelated', 'keep me' );
$before = cc_snapshot();
require_once WP_PLUGIN_DIR . '/' . $plugin;
cc_assert( $plugin === plugin_basename( COMMITCAP_PLUGIN_FILE ), 'Wrong WordPress plugin basename' );
cc_assert( false === get_option( 'commitcap_version' ), 'Bootstrap wrote metadata' );
cc_assert( $before === cc_snapshot(), 'Bootstrap changed DB objects or user tables' );

$result = activate_plugin( $plugin );
cc_assert( null === $result, 'WordPress activation returned error' );
cc_assert( is_plugin_active( $plugin ), 'Plugin did not activate' );
cc_assert( class_exists( 'CommitCap\\Plugin' ), 'Plugin bootstrap not loaded' );
cc_assert( '0.1.0' === \CommitCap\Plugin::version(), 'Wrong plugin version' );
cc_assert( false === \CommitCap\Plugin::can_manage(), 'Non-admin may manage plugin' );
wp_set_current_user( 1 );
cc_assert( \CommitCap\Plugin::can_manage(), 'Admin capability check failed' );
cc_assert( '' !== \CommitCap\Environment::wordpress_version(), 'No WordPress version' );
cc_assert( \CommitCap\Environment::has_wpdb(), 'No wpdb' );
cc_assert( '' !== \CommitCap\Environment::database_version(), 'No database identity' );
cc_assert( '0.1.0' === get_option( 'commitcap_version' ), 'Missing owned metadata' );
cc_assert( $before === cc_snapshot(), 'Activation changed user tables or database objects' );
echo "Bootstrap and activation: PASS\n";

// WordPress skips a second activate_plugin() for an active plugin; exercise the
// hook too, to prove its one-option metadata path remains idempotent.
cc_assert( null === activate_plugin( $plugin ), 'Double activation failed' );
\CommitCap\Lifecycle::activate();
cc_assert( '0.1.0' === get_option( 'commitcap_version' ), 'Double activation changed metadata' );
cc_assert( $before === cc_snapshot(), 'Double activation changed DB objects' );
echo "Double activation: PASS\n";

deactivate_plugins( $plugin );
cc_assert( ! is_plugin_active( $plugin ), 'Plugin still active after deactivation' );
cc_assert( '0.1.0' === get_option( 'commitcap_version' ), 'Deactivation deleted persistent metadata' );
cc_assert( $before === cc_snapshot(), 'Deactivation changed DB objects' );
echo "Deactivation: PASS\n";

// The merged product owns three local options plus one stale #87 compatibility
// option. Seed them through the production services, then prove the real
// uninstall.php removes exactly those and nothing else.
$owned_operation = \CommitCap\Certified_Operation::redirection_5_5_2_bulk_disable();
\CommitCap\Operation_Config::set_logical_budget( $owned_operation, 10 );
\CommitCap\Operation_Config::set_enabled( $owned_operation, true );
\CommitCap\Last_Outcome::record( array(
	'operation_id'                     => \CommitCap\Certified_Operation::REDIRECTION_BULK_DISABLE_ID,
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
update_option( 'commitcap_operation_budget_redirection_5_5_2_bulk_disable', 1 );
cc_assert( 'ok' === \CommitCap\Operation_Config::read( $owned_operation )['state'], 'Product config not seeded' );
cc_assert( null !== \CommitCap\Last_Outcome::read(), 'Product outcome not seeded' );

uninstall_plugin( $plugin );
$options_db = $GLOBALS['wpdb'];
foreach ( array(
	'commitcap_version',
	\CommitCap\Operation_Config::STATE_OPTION,
	\CommitCap\Last_Outcome::OPTION,
	'commitcap_operation_budget_redirection_5_5_2_bulk_disable',
) as $owned_option ) {
	$remaining = (string) $options_db->get_var( $options_db->prepare( 'SELECT COUNT(*) FROM ' . $options_db->options . ' WHERE option_name = %s', $owned_option ) );
	cc_assert( '0' === $remaining, 'Uninstall left owned state: ' . $owned_option );
}
cc_assert( 'keep me' === get_option( 'commitcap_unrelated' ), 'Uninstall removed unrelated metadata' );
cc_assert( $before === cc_snapshot(), 'Uninstall changed user tables or DB objects' );
echo "Uninstall: PASS\n";
