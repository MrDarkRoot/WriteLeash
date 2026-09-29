<?php
// Loaded by WP-CLI in both foundation and real adapter installations.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$file = WP_PLUGIN_DIR . '/commitcap/commitcap.php';
if ( ! is_file( $file ) || is_file( WP_PLUGIN_DIR . '/commitcap/commitcap-for-wordpress.php' ) ) {
	throw new RuntimeException( '#62 installed plugin entrypoint mismatch' );
}
$data = get_plugin_data( $file, false, false );
if ( 'CommitCap' !== $data['Name'] || 'commitcap' !== $data['TextDomain'] || '0.1.0-dev' !== $data['Version'] ||
	'7.4' !== $data['RequiresPHP'] || 'commitcap/commitcap.php' !== plugin_basename( $file ) ) {
	throw new RuntimeException( '#62 public identity/header mismatch: ' . wp_json_encode( $data ) );
}
$headers = 0;
foreach ( glob( WP_PLUGIN_DIR . '/commitcap/*.php' ) as $entry ) {
	$headers += preg_match( '/^\s*\*\s*Plugin Name:/m', file_get_contents( $entry ) );
}
if ( 1 !== $headers ) {
	throw new RuntimeException( '#62 expected exactly one plugin header' );
}
echo '#62 identity: CommitCap / commitcap / commitcap/commitcap.php, headers=1, declared minimum PHP 7.4 PASS' . "\n";
