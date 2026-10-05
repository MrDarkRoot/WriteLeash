<?php
// #63/#121 deterministic WordPress.org readme preflight. Mirrors the current
// official readme structure rules and the metadata/version/license coherence
// that the release matrix and PR must not silently diverge from. The public
// listing is the WooCommerce Free product; historical Redirection setup copy
// must never return.
if ( $argc < 2 || $argc > 3 || ( 3 === $argc && '--frozen-artifact' !== $argv[2] ) ) {
	throw new RuntimeException( 'Usage: readme-validate.php <plugin-root> [--frozen-artifact]' );
}
$root   = rtrim( $argv[1], '/' );
// The pinned #123/#124 artifact reproduces a historical source whose listing
// states the exact then-supported WooCommerce release; the live tree must
// state the current supported range instead. Each mode requires its own exact
// wording, so neither the frozen artifact nor the live listing is loosened.
$frozen = 3 === $argc;
$readme = $root . '/readme.txt';
$main   = $root . '/writeleash.php';
$fail   = static function ( string $message ): void {
	throw new RuntimeException( '#63/#121 readme preflight: ' . $message );
};

foreach ( array( $readme, $main ) as $file ) {
	if ( ! is_file( $file ) ) {
		$fail( 'missing required file: ' . basename( $file ) );
	}
}
$text = (string) file_get_contents( $readme );
require_once __DIR__ . '/conflict-copy.php';
writeleash_conflict_copy_audit( $text );
if ( strlen( $text ) > 10240 ) {
	$fail( 'readme.txt is larger than the 10k directory guidance' );
}
if ( ! str_ends_with( $text, "\n" ) ) {
	$fail( 'readme.txt must end with a newline' );
}

$lines = preg_split( '/\r?\n/', $text );
if ( trim( $lines[0] ) !== '=== WriteLeash ===' ) {
	$fail( 'readme title must be exactly "=== WriteLeash ==="' );
}

// Parse the header block (up to the first blank line).
$headers      = array();
$short_lines  = array();
$in_headers   = true;
foreach ( array_slice( $lines, 1 ) as $line ) {
	if ( '' === trim( $line ) ) {
		$in_headers = false;
		continue;
	}
	if ( $in_headers && preg_match( '/\A([A-Za-z][A-Za-z ]+):\s*(.*)\z/', $line, $match ) ) {
		$headers[ strtolower( trim( $match[1] ) ) ] = trim( $match[2] );
		continue;
	}
	$in_headers = false;
	if ( '' !== trim( $line ) ) {
		$short_lines[] = trim( $line );
		break;
	}
}

foreach ( array( 'tags', 'requires at least', 'tested up to', 'stable tag', 'requires php', 'license', 'license uri' ) as $field ) {
	if ( ! isset( $headers[ $field ] ) || '' === $headers[ $field ] ) {
		$fail( 'missing readme header: ' . $field );
	}
}

$tags = array_filter( array_map( 'trim', explode( ',', $headers['tags'] ) ) );
if ( ! $tags || count( $tags ) > 5 || count( $tags ) !== count( array_unique( $tags ) ) ) {
	$fail( 'Tags must be 1-5 unique comma-separated terms' );
}
if ( ! preg_match( '/\A\d+\.\d\z/', $headers['requires at least'] ) || '7.0' !== $headers['requires at least'] ) {
	$fail( 'Requires at least must be the narrowest coherent value 7.0 (WooCommerce 11 package minimum)' );
}
if ( ! preg_match( '/\A\d+\.\d\z/', $headers['tested up to'] ) || '7.1' !== $headers['tested up to'] ) {
	$fail( 'Tested up to must be the tested current-stable major/minor 7.1' );
}
if ( ! preg_match( '/\A\d+\.\d+\.\d+\z/', $headers['stable tag'] ) || in_array( $headers['stable tag'], array( 'trunk', 'latest', 'dev', 'master', 'main' ), true ) ) {
	$fail( 'Stable tag must be a stable numeric version' );
}
if ( '7.4' !== $headers['requires php'] ) {
	$fail( 'Requires PHP must be the declared minimum 7.4' );
}
if ( 'GPLv2 or later' !== $headers['license'] ) {
	$fail( 'License must be "GPLv2 or later"' );
}
if ( 'https://www.gnu.org/licenses/gpl-2.0.html' !== $headers['license uri'] ) {
	$fail( 'License URI must point at the GNU GPLv2 license text' );
}

// Directory metadata and the main plugin header must not contradict each other.
$main_header = (string) file_get_contents( $main );
foreach ( array(
	'/^\s*\*\s*Requires at least:\s*7\.0\s*$/m',
	'/^\s*\*\s*Requires PHP:\s*7\.4\s*$/m',
) as $requirement ) {
	if ( ! preg_match( $requirement, $main_header ) ) {
		$fail( 'main plugin header contradicts the readme requirements: ' . $requirement );
	}
}

// Short description: one plain-text line, 150 characters or fewer.
if ( ! $short_lines ) {
	$fail( 'short description is missing' );
}
$short = $short_lines[0];
if ( str_contains( $short, '<' ) || str_contains( $short, '[' ) || preg_match( '/\]\(/', $short ) ) {
	$fail( 'short description must not contain markup' );
}
if ( strlen( $short ) > 150 ) {
	$fail( 'short description is longer than 150 characters: ' . strlen( $short ) );
}

// Required recognizable sections for the WooCommerce Free listing.
foreach ( array( '== Description ==', '== Supported scope ==', '== Installation ==', '== Screenshots ==', '== Frequently Asked Questions ==', '== Changelog ==' ) as $section ) {
	if ( ! str_contains( $text, $section ) ) {
		$fail( 'missing readme section: ' . $section );
	}
}

// No placeholders or forbidden text may ship.
foreach ( array( 'TBD', 'TODO', 'FIXME', 'XXX', 'lorem ipsum', 'Stable tag: trunk' ) as $placeholder ) {
	if ( false !== stripos( $text, $placeholder ) ) {
		$fail( 'placeholder or forbidden text in readme: ' . $placeholder );
	}
}
// Exact operation wording: the mistaken name must never reappear.
if ( false !== stripos( $text, 'Baseline Disable' ) ) {
	$fail( 'misleading operation wording "Baseline Disable" in readme' );
}
// The exact tested WordPress core fixtures must remain explicit so that a
// major/minor "Tested up to" value is never read as blanket coverage.
foreach ( array( '7.0.1', '7.1.2' ) as $tested_core ) {
	if ( ! str_contains( $text, $tested_core ) ) {
		$fail( 'readme must name the exact tested WordPress core fixture: ' . $tested_core );
	}
}

// #121 release-claim guard: the public listing must not widen the accepted
// #111/#112/#177 evidence. The current supported new-job ceiling is 1,000
// (#177); the pinned historical release artifact predates #177 and keeps its
// reviewed 100 ceiling. Universal rollback and all-host/all-version promises
// are forbidden.
$unsupported = array( '10,000', '10000', 'universal rollback', 'all bad edits', 'every shared host', 'all WooCommerce versions', 'any WooCommerce version' );
if ( $frozen ) { $unsupported[] = '1,000'; $unsupported[] = '1000'; }
foreach ( $unsupported as $claim ) {
	if ( false !== stripos( $text, $claim ) ) {
		$fail( 'unsupported public claim in readme: ' . $claim );
	}
}
$woo_claim = $frozen ? 'WooCommerce 11.1.2 exactly' : 'WooCommerce 10.0 through 11.x';
$ceiling = $frozen ? 'Up to 100' : 'Up to 1,000';
foreach ( array( $ceiling, $woo_claim, 'Multisite is unsupported', 'has not been tested' ) as $required_scope ) {
	if ( false === stripos( $text, $required_scope ) ) {
		$fail( 'required scope limitation missing from readme: ' . $required_scope );
	}
}

// Stable tag, header version and runtime version must agree exactly.
$version = null;
if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', (string) file_get_contents( $main ), $match ) ) {
	$version = $match[1];
}
if ( null === $version || $headers['stable tag'] !== $version ) {
	$fail( 'Stable tag does not match the main plugin Version header' );
}
if ( false === strpos( (string) file_get_contents( $main ), "define( 'WRITELEASH_VERSION', '" . $version . "' )" ) ) {
	$fail( 'main plugin runtime constant does not match the Version header' );
}

// Public identity, dependency and license must agree with the plugin header.
$main_source = $main_header;
if ( ! preg_match( '/^\s*\*\s*Plugin Name:\s*WriteLeash\s*$/m', $main_source ) ||
	! preg_match( '/^\s*\*\s*Requires Plugins:\s*woocommerce\s*$/m', $main_source ) ||
	! preg_match( '/^\s*\*\s*License:\s*GPL v2 or later\s*$/m', $main_source ) ) {
	$fail( 'main plugin header does not match the readme identity/dependency/license' );
}
// The readme display name must match the plugin header exactly and must never
// include the WordPress.org-restricted term "WordPress" (mismatched_plugin_name
// / trademarked_term Plugin Check findings).
$readme_title = trim( $lines[0], "= \t" );
if ( '' === $readme_title || preg_match( '/\bwordpress\b/i', $readme_title ) ||
	! preg_match( '/^\s*\*\s*Plugin Name:\s*(.+?)\s*$/m', $main_source, $plugin_name_match ) ||
	$readme_title !== trim( $plugin_name_match[1] ) ) {
	$fail( 'readme title must equal the plugin header name and must not contain the restricted term "WordPress"' );
}

echo '#63/#121 readme preflight: title, ' . strlen( $short ) . "-char description, " . count( $tags ) . " tags, Stable tag $version, directory/header requirements coherent, GPLv2-or-later PASS\n";
