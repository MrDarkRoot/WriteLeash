<?php
// HISTORICAL TEST ONLY. Metadata compatibility for the disposable WP 6.8.3
// Guard/Doctor/Redirection lab. No runtime authority or executable code added.
if ( PHP_SAPI !== 'cli' || $argc < 2 || $argc > 3 ) {
	throw new RuntimeException( 'HISTORICAL TEST ONLY: usage historical-minimum-121.php <disposable-site> [public-source]' );
}
$site = realpath( $argv[1] );
$source = realpath( $argv[2] ?? '/opt/writeleash' );
$destination = $site . '/wp-content/plugins/writeleash';
if ( false === $site || false === $source || 0 !== strpos( $site, '/tmp/' ) ||
	realpath( $destination ) !== $destination || $destination === $source ||
	! is_file( $site . '/wp-includes/version.php' ) ||
	! preg_match( '/\$wp_version\s*=\s*[\'"]6\.8\.3[\'"]\s*;/', file_get_contents( $site . '/wp-includes/version.php' ) ) ) {
	throw new RuntimeException( 'HISTORICAL TEST ONLY: requires a separate disposable exact WP 6.8.3 site' );
}
$main = $destination . '/writeleash.php';
$public = $source . '/writeleash.php';
if ( is_link( $main ) || ! is_file( $main ) || ! is_file( $public ) ||
	realpath( $main ) === realpath( $public ) || fileinode( $main ) === fileinode( $public ) ||
	file_get_contents( $main ) !== file_get_contents( $public ) ) {
	throw new RuntimeException( 'HISTORICAL TEST ONLY: requires an unmodified, independently copied public entrypoint' );
}
$before = hash_file( 'sha256', $public );
$text = file_get_contents( $main );
$text = preg_replace( '/^( \* Requires at least:) 7\.0$/m', '$1 6.8.3' . "\n * HISTORICAL TEST ONLY: disposable WP 6.8.3 metadata shim; not public support.", $text, -1, $count );
if ( 1 !== $count || false === file_put_contents( $main, $text ) || hash_file( 'sha256', $public ) !== $before ) {
	throw new RuntimeException( 'HISTORICAL TEST ONLY: exact metadata shim failed or public source changed' );
}
echo "#121 HISTORICAL TEST ONLY: staged WP minimum 6.8.3; production remains 7.0; no runtime authority added: PASS\n";
