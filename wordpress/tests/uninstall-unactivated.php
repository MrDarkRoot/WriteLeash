<?php
// Separate WordPress request before the plugin has ever been activated.
if ( false !== get_option( 'commitcap_version' ) || is_plugin_active( 'commitcap-for-wordpress/commitcap.php' ) ) {
	throw new RuntimeException( 'Expected inactive, uninstalled foundation' );
}
add_option( 'commitcap_unrelated', 'keep me' );
$wpdb = $GLOBALS['wpdb'];
$before = $wpdb->get_col( 'SHOW TABLES' );
uninstall_plugin( 'commitcap-for-wordpress/commitcap.php' );
if ( false !== get_option( 'commitcap_version' ) ||
	'keep me' !== get_option( 'commitcap_unrelated' ) ||
	$before !== $wpdb->get_col( 'SHOW TABLES' ) ) {
	throw new RuntimeException( 'Uninstall without activation changed site state' );
}
echo "Uninstall without activation: PASS\n";
