<?php
// Loaded by WP-CLI in both foundation and real adapter installations.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$file = WP_PLUGIN_DIR . '/commitcap/commitcap.php';
if ( ! is_file( $file ) || is_file( WP_PLUGIN_DIR . '/commitcap/commitcap-for-wordpress.php' ) ) {
	throw new RuntimeException( '#62 installed plugin entrypoint mismatch' );
}
$data = get_plugin_data( $file, false, false );
$license_header = get_file_data(
	$file,
	array( 'License' => 'License', 'LicenseURI' => 'License URI' ),
	'plugin'
);
if ( 'CommitCap' !== $data['Name'] || 'commitcap' !== $data['TextDomain'] || '0.1.0' !== $data['Version'] ||
	'6.8' !== $data['RequiresWP'] || '7.4' !== $data['RequiresPHP'] || 'redirection' !== $data['RequiresPlugins'] ||
	'GPL v2 or later' !== $license_header['License'] || 'https://www.gnu.org/licenses/gpl-2.0.html' !== $license_header['LicenseURI'] ||
	'commitcap/commitcap.php' !== plugin_basename( $file ) ) {
	throw new RuntimeException( '#62/#63 public identity/header mismatch: ' . wp_json_encode( array_merge( $data, $license_header ) ) );
}
$headers = 0;
foreach ( glob( WP_PLUGIN_DIR . '/commitcap/*.php' ) as $entry ) {
	$headers += preg_match( '/^\s*\*\s*Plugin Name:/m', file_get_contents( $entry ) );
}
if ( 1 !== $headers ) {
	throw new RuntimeException( '#62 expected exactly one plugin header' );
}
$readme = WP_PLUGIN_DIR . '/commitcap/readme.txt';
$license = WP_PLUGIN_DIR . '/commitcap/LICENSE';
if ( ! is_file( $readme ) || ! is_file( $license ) || ! is_file( WP_PLUGIN_DIR . '/commitcap/operator-setup.txt' ) ) {
	throw new RuntimeException( '#63 release files missing from installed plugin' );
}
if ( ! preg_match( '/^Stable tag:\s*' . preg_quote( $data['Version'], '/' ) . '\s*$/mi', (string) file_get_contents( $readme ) ) ) {
	throw new RuntimeException( '#63 readme Stable tag does not match the installed plugin Version' );
}
if ( ! str_contains( (string) file_get_contents( $license ), 'GNU GENERAL PUBLIC LICENSE' ) ) {
	throw new RuntimeException( '#64 installed LICENSE is not the GNU GPL text' );
}
foreach ( array( 'README.md', 'LICENSE-AUDIT.md', 'RELEASE-MATRIX.md', 'THREAT-MODEL.md' ) as $excluded ) {
	if ( is_file( WP_PLUGIN_DIR . '/commitcap/' . $excluded ) ) {
		throw new RuntimeException( '#63 internal document staged into the distribution: ' . $excluded );
	}
}
echo '#62/#63 identity: CommitCap / commitcap / commitcap.php, headers=1, GPLv2+, readme stable 0.1.0, declared minimum PHP 7.4 PASS' . "\n";
