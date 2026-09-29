<?php
// Shared fixture preparation: Redirection 5.5.2 installs its database schema
// through its own setup/upgrade API, not on activation. The accepted adapter
// suite uses the same API; this is a test fixture step, not a product change.
require_once WP_PLUGIN_DIR . '/writeleash/writeleash.php';
if ( ! class_exists( 'Red_Database' ) ) {
	throw new RuntimeException( '#63 Redirection database API unavailable' );
}
$normal = $GLOBALS['wpdb'];
$tables = (int) $normal->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wp_redirection_%'" );
if ( $tables < 4 ) {
	Red_Database::get_latest_database()->install();
	Red_Flusher::clear();
	red_set_options();
	$tables = (int) $normal->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wp_redirection_%'" );
}
if ( $tables < 4 || '' !== (string) $normal->last_error ) {
	throw new RuntimeException( '#63 Redirection 5.5.2 schema install failed on current stable: ' . $normal->last_error );
}
echo "#63 current-core: Redirection 5.5.2 schema present ($tables tables) PASS\n";
