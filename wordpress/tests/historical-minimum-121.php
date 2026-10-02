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
$readme = $destination . '/readme.txt';
$public_readme = $source . '/readme.txt';
foreach ( array( array( $main, $public ), array( $readme, $public_readme ) ) as $pair ) {
	if ( is_link( $pair[0] ) || ! is_file( $pair[0] ) || ! is_file( $pair[1] ) ||
		realpath( $pair[0] ) === realpath( $pair[1] ) || fileinode( $pair[0] ) === fileinode( $pair[1] ) ||
		file_get_contents( $pair[0] ) !== file_get_contents( $pair[1] ) ) {
		throw new RuntimeException( 'HISTORICAL TEST ONLY: requires unmodified, independently copied public entrypoint/readme' );
	}
}
$before = hash_file( 'sha256', $public );
$readme_before = hash_file( 'sha256', $public_readme );
$text = file_get_contents( $main );
$text = preg_replace( '/^( \* Requires at least:) 7\.0$/m', '$1 6.8.3' . "\n * HISTORICAL TEST ONLY: disposable WP 6.8.3 metadata shim; not public support.", $text, -1, $count );
$readme_text = preg_replace( '/^Requires at least: 7\.0$/m', 'Requires at least: 6.8.3', file_get_contents( $readme ), -1, $readme_count );
// Official Plugin Check audits this historical staged tree too. Keep its
// metadata coherent without changing any production file or public assertion.
if ( 1 !== $count || 1 !== $readme_count || false === file_put_contents( $main, $text ) ||
	false === file_put_contents( $readme, $readme_text ) ||
	hash_file( 'sha256', $public ) !== $before || hash_file( 'sha256', $public_readme ) !== $readme_before ) {
	throw new RuntimeException( 'HISTORICAL TEST ONLY: exact metadata shim failed or public source changed' );
}
echo "#121 HISTORICAL TEST ONLY: staged header/readme WP minimum 6.8.3; production remains 7.0; no runtime authority added: PASS\n";
