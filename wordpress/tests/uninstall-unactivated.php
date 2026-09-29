<?php
// Separate WordPress request before the plugin has ever been activated.
if ( false !== get_option( 'writeleash_version' ) || is_plugin_active( 'writeleash/writeleash.php' ) ) {
	throw new RuntimeException( 'Expected inactive, uninstalled foundation' );
}
add_option( 'writeleash_unrelated', 'keep me' );
add_option( 'commitcap_unrelated', 'keep me too' );
// Pre-release development cleanup keys must be removed by exact name even when
// the plugin was never activated, without any wildcard option deletion.
update_option( 'commitcap_version', '0.1.0', false );
update_option( 'commitcap_certified_operation_state', array( 'enabled' => true ), false );
$wpdb = $GLOBALS['wpdb'];
$before = $wpdb->get_col( 'SHOW TABLES' );
uninstall_plugin( 'writeleash/writeleash.php' );
if ( false !== get_option( 'writeleash_version' ) ||
	false !== get_option( 'commitcap_version' ) ||
	false !== get_option( 'commitcap_certified_operation_state' ) ||
	'keep me' !== get_option( 'writeleash_unrelated' ) ||
	'keep me too' !== get_option( 'commitcap_unrelated' ) ||
	$before !== $wpdb->get_col( 'SHOW TABLES' ) ) {
	throw new RuntimeException( 'Uninstall without activation changed site state' );
}
echo "Uninstall without activation: PASS\n";
