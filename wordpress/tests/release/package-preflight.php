<?php
// #63 package-content preflight: the distribution allowlist must match the
// intended runtime exactly. This runs against the repository source tree and
// is the canonical list #77 will later consume; it creates no artifact.
if ( 3 !== $argc ) {
	throw new RuntimeException( 'Usage: package-preflight.php <plugin-source-root> <distribution-manifest>' );
}
$source        = rtrim( $argv[1], '/' );
$manifest_path = $argv[2];
$fail          = static function ( string $message ): void {
	throw new RuntimeException( '#63 package preflight: ' . $message );
};

if ( ! is_dir( $source ) || ! is_file( $manifest_path ) ) {
	$fail( 'missing plugin source root or distribution manifest' );
}
$raw = file( $manifest_path, FILE_IGNORE_NEW_LINES );
if ( ! is_array( $raw ) ) {
	$fail( 'manifest unreadable' );
}

$entries = array();
foreach ( $raw as $line ) {
	$line = trim( $line );
	if ( '' === $line || str_starts_with( $line, '#' ) ) {
		continue;
	}
	if ( preg_match( '/\s/', $line ) || str_contains( $line, '..' ) || str_contains( $line, '\\' ) || str_starts_with( $line, '/' ) ) {
		$fail( 'unsafe or non-canonical manifest entry: ' . $line );
	}
	$entries[] = $line;
}
if ( ! $entries ) {
	$fail( 'empty distribution manifest' );
}
$sorted = $entries;
sort( $sorted, SORT_STRING );
if ( $entries !== $sorted || count( $entries ) !== count( array_unique( $entries ) ) ) {
	$fail( 'manifest must be sorted and unique' );
}

// Every allowlisted file exists in source.
foreach ( $entries as $entry ) {
	if ( ! is_file( $source . '/' . $entry ) ) {
		$fail( 'allowlisted file missing from source: ' . $entry );
	}
}

// Required runtime and release files.
foreach ( array( 'commitcap.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'operator-setup.txt' ) as $required ) {
	if ( ! in_array( $required, $entries, true ) ) {
		$fail( 'required distribution file missing from allowlist: ' . $required );
	}
}

// No internal development/research document may be packaged.
$forbidden = array(
	'README.md', 'LICENSE-AUDIT.md', 'RELEASE-MATRIX.md', 'THREAT-MODEL.md', 'ADMIN-CLI.md',
	'CANDIDATES.md', 'DEMO.md', 'DOCTOR.md', 'ENGINE.md', 'GUARD.md', 'OPERATION.md',
	'PROVISIONING.md', 'REDIRECTION.md',
);
foreach ( $entries as $entry ) {
	if ( in_array( basename( $entry ), $forbidden, true ) ) {
		$fail( 'internal document must not be distributed: ' . $entry );
	}
	if ( 'md' === strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ) ) {
		$fail( 'markdown is not permitted in the distribution candidate: ' . $entry );
	}
}

// Every production PHP file must be accounted for: a new include cannot be
// silently omitted from the package.
$php_files = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$php_files[] = substr( $file->getPathname(), strlen( $source ) + 1 );
	}
}
sort( $php_files, SORT_STRING );
$allowlist_php = array_values( array_filter( $entries, static fn( $entry ) => 'php' === strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ) ) );
sort( $allowlist_php, SORT_STRING );
if ( $php_files !== $allowlist_php ) {
	$fail( 'allowlist does not match production PHP files: ' . json_encode( array_diff( $php_files, $allowlist_php ) ) );
}

// The packaged license must be the verbatim GNU GPLv2 text.
$license_hash = hash_file( 'sha256', $source . '/LICENSE' );
if ( 'edaef632cbb643e4e7a221717a6c441a4c1a7c918e6e4d56debc3d8739b233f6' !== $license_hash ) {
	$fail( 'LICENSE is not the reviewed verbatim GNU GPLv2 text' );
}
$license = (string) file_get_contents( $source . '/LICENSE' );
if ( ! str_contains( $license, 'GNU GENERAL PUBLIC LICENSE' ) || ! str_contains( $license, 'Version 2, June 1991' ) ) {
	$fail( 'LICENSE header text missing' );
}

// Main file must carry the founder-selected license and the release version.
$main = (string) file_get_contents( $source . '/commitcap.php' );
foreach ( array(
	'/^\s*\*\s*Version:\s*0\.1\.0\s*$/m',
	'/^\s*\*\s*Requires at least:\s*6\.8\s*$/m',
	'/^\s*\*\s*Requires PHP:\s*7\.4\s*$/m',
	'/^\s*\*\s*License:\s*GPL v2 or later\s*$/m',
	'/^\s*\*\s*License URI:\s*https:\/\/www\.gnu\.org\/licenses\/gpl-2\.0\.html\s*$/m',
	'/SPDX-License-Identifier:\s*GPL-2\.0-or-later/',
	'/either version 2 of the License, or \(at your option\) any later/',
) as $pattern ) {
	if ( ! preg_match( $pattern, $main ) ) {
		$fail( 'main plugin file is missing expected license/version header: ' . $pattern );
	}
}
if ( ! str_contains( $main, "define( 'COMMITCAP_VERSION', '0.1.0' )" ) ) {
	$fail( 'runtime version constant does not match the release version' );
}
if ( ! preg_match( '/^\s*\*\s*Requires Plugins:\s*redirection\s*$/m', $main ) ) {
	$fail( 'main plugin file must declare the Redirection dependency (Requires Plugins: redirection)' );
}

// The mistaken operation name must never reappear in distribution content.
foreach ( $entries as $entry ) {
	$content = (string) file_get_contents( $source . '/' . $entry );
	if ( false !== stripos( $content, 'Baseline Disable' ) ) {
		$fail( 'misleading operation wording "Baseline Disable" in ' . $entry );
	}
}

echo '#63 distribution allowlist: ' . count( $entries ) . " files, verbatim GPLv2, version 0.1.0, exact operation wording, no internal docs PASS\n";
