<?php
// Loaded by WP-CLI in both foundation and real adapter installations.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$file = WP_PLUGIN_DIR . '/writeleash/writeleash.php';
if ( ! is_file( $file ) ) {
	throw new RuntimeException( '#62 installed plugin entrypoint mismatch' );
}
// The old CommitCap package identity must no longer be the installed target.
if ( is_file( WP_PLUGIN_DIR . '/commitcap/commitcap.php' ) ||
	is_file( WP_PLUGIN_DIR . '/writeleash/writeleash-for-wordpress.php' ) ) {
	throw new RuntimeException( '#62 stale CommitCap package basename present in the plugin directory' );
}
$data = get_plugin_data( $file, false, false );
$main = file_get_contents( $file );
$historical = false !== strpos( $main, 'HISTORICAL TEST ONLY: disposable WP 6.8.3 metadata shim; not public support.' );
$minimum = $historical ? '6.8.3' : '7.0';
if ( $historical && ( '6.8.3' !== $GLOBALS['wp_version'] ||
	0 !== strpos( realpath( ABSPATH ), '/tmp/' ) ||
	! preg_match( '/^ \* Requires at least: 7\.0$/m', file_get_contents( '/opt/writeleash/writeleash.php' ) ) ) ) {
	throw new RuntimeException( '#121 historical identity requires disposable exact WP 6.8.3 and untouched production minimum 7.0' );
}
$license_header = get_file_data(
	$file,
	array( 'License' => 'License', 'LicenseURI' => 'License URI' ),
	'plugin'
);
if ( 'WriteLeash' !== $data['Name'] || 'writeleash' !== $data['TextDomain'] || '0.2.0' !== $data['Version'] ||
	$minimum !== $data['RequiresWP'] || '7.4' !== $data['RequiresPHP'] || 'woocommerce' !== $data['RequiresPlugins'] ||
	'GPL v2 or later' !== $license_header['License'] || 'https://www.gnu.org/licenses/gpl-2.0.html' !== $license_header['LicenseURI'] ||
	'writeleash/writeleash.php' !== plugin_basename( $file ) ||
	'commitcap/commitcap.php' === plugin_basename( $file ) ) {
	throw new RuntimeException( '#62/#63 public identity/header mismatch: ' . wp_json_encode( array_merge( $data, $license_header ) ) );
}
$headers = 0;
foreach ( glob( WP_PLUGIN_DIR . '/writeleash/*.php' ) as $entry ) {
	$headers += preg_match( '/^\s*\*\s*Plugin Name:/m', file_get_contents( $entry ) );
}
if ( 1 !== $headers ) {
	throw new RuntimeException( '#62 expected exactly one plugin header' );
}
$readme = WP_PLUGIN_DIR . '/writeleash/readme.txt';
$license = WP_PLUGIN_DIR . '/writeleash/LICENSE';
if ( ! is_file( $readme ) || ! is_file( $license ) ) {
	throw new RuntimeException( '#63 release files missing from installed plugin' );
}
if ( ! preg_match( '/^Stable tag:\s*' . preg_quote( $data['Version'], '/' ) . '\s*$/mi', (string) file_get_contents( $readme ) ) ) {
	throw new RuntimeException( '#63 readme Stable tag does not match the installed plugin Version' );
}
if ( ! str_contains( (string) file_get_contents( $license ), 'GNU GENERAL PUBLIC LICENSE' ) ) {
	throw new RuntimeException( '#64 installed LICENSE is not the GNU GPL text' );
}
foreach ( array( 'README.md', 'LICENSE-AUDIT.md', 'RELEASE-MATRIX.md', 'THREAT-MODEL.md' ) as $excluded ) {
	if ( is_file( WP_PLUGIN_DIR . '/writeleash/' . $excluded ) ) {
		throw new RuntimeException( '#63 internal document staged into the distribution: ' . $excluded );
	}
}
echo '#62/#63 identity: WriteLeash / writeleash / writeleash.php, old commitcap/commitcap.php absent, headers=1, GPLv2+, readme stable 0.2.0, declared minimum PHP 7.4 PASS' . "\n";
