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

$plugin = 'commitcap-for-wordpress/commitcap-for-wordpress.php';
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
cc_assert( '0.1.0-dev' === \CommitCap\Plugin::version(), 'Wrong plugin version' );
cc_assert( false === \CommitCap\Plugin::can_manage(), 'Non-admin may manage plugin' );
wp_set_current_user( 1 );
cc_assert( \CommitCap\Plugin::can_manage(), 'Admin capability check failed' );
cc_assert( '' !== \CommitCap\Environment::wordpress_version(), 'No WordPress version' );
cc_assert( \CommitCap\Environment::has_wpdb(), 'No wpdb' );
cc_assert( '' !== \CommitCap\Environment::database_version(), 'No database identity' );
cc_assert( '0.1.0-dev' === get_option( 'commitcap_version' ), 'Missing owned metadata' );
cc_assert( $before === cc_snapshot(), 'Activation changed user tables or database objects' );
echo "Bootstrap and activation: PASS\n";

// WordPress skips a second activate_plugin() for an active plugin; exercise the
// hook too, to prove its one-option metadata path remains idempotent.
cc_assert( null === activate_plugin( $plugin ), 'Double activation failed' );
\CommitCap\Lifecycle::activate();
cc_assert( '0.1.0-dev' === get_option( 'commitcap_version' ), 'Double activation changed metadata' );
cc_assert( $before === cc_snapshot(), 'Double activation changed DB objects' );
echo "Double activation: PASS\n";

deactivate_plugins( $plugin );
cc_assert( ! is_plugin_active( $plugin ), 'Plugin still active after deactivation' );
cc_assert( '0.1.0-dev' === get_option( 'commitcap_version' ), 'Deactivation deleted persistent metadata' );
cc_assert( $before === cc_snapshot(), 'Deactivation changed DB objects' );
echo "Deactivation: PASS\n";

uninstall_plugin( $plugin );
cc_assert( false === get_option( 'commitcap_version' ), 'Uninstall left owned metadata' );
cc_assert( 'keep me' === get_option( 'commitcap_unrelated' ), 'Uninstall removed unrelated metadata' );
cc_assert( $before === cc_snapshot(), 'Uninstall changed user tables or DB objects' );
echo "Uninstall: PASS\n";
