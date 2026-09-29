<?php
// #63 deterministic WordPress.org readme preflight. Mirrors the official
// readme structure rules and the stable-tag/version/license consistency that
// the release matrix and PR must not silently diverge from.
if ( 2 !== $argc ) {
	throw new RuntimeException( 'Usage: readme-validate.php <plugin-root>' );
}
$root   = rtrim( $argv[1], '/' );
$readme = $root . '/readme.txt';
$main   = $root . '/commitcap.php';
$fail   = static function ( string $message ): void {
	throw new RuntimeException( '#63 readme preflight: ' . $message );
};

foreach ( array( $readme, $main ) as $file ) {
	if ( ! is_file( $file ) ) {
		$fail( 'missing required file: ' . basename( $file ) );
	}
}
$text = (string) file_get_contents( $readme );
if ( strlen( $text ) > 10240 ) {
	$fail( 'readme.txt is larger than the 10k directory guidance' );
}
if ( ! str_ends_with( $text, "\n" ) ) {
	$fail( 'readme.txt must end with a newline' );
}

$lines = preg_split( '/\r?\n/', $text );
if ( trim( $lines[0] ) !== '=== CommitCap ===' ) {
	$fail( 'readme title must be exactly "=== CommitCap ==="' );
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
if ( ! preg_match( '/\A\d+\.\d\z/', $headers['requires at least'] ) || '6.8' !== $headers['requires at least'] ) {
	$fail( 'Requires at least must be the reviewed major/minor value 6.8' );
}
if ( ! preg_match( '/\A\d+\.\d\z/', $headers['tested up to'] ) || '6.8' !== $headers['tested up to'] ) {
	$fail( 'Tested up to must be the tested major/minor fixture 6.8' );
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

// Required recognizable sections.
foreach ( array( '== Description ==', '== How it works ==', '== Installation ==', '== Supported configuration ==', '== Frequently Asked Questions ==', '== Changelog ==' ) as $section ) {
	if ( ! str_contains( $text, $section ) ) {
		$fail( 'missing readme section: ' . $section );
	}
}

// No placeholders or unfinished text may ship.
foreach ( array( 'TBD', 'TODO', 'FIXME', 'XXX', 'lorem ipsum', '<STRONG_GENERATED_PASSWORD_HERE>', 'Stable tag: trunk' ) as $placeholder ) {
	if ( false !== stripos( $text, $placeholder ) ) {
		$fail( 'placeholder or forbidden text in readme: ' . $placeholder );
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
if ( false === strpos( (string) file_get_contents( $main ), "define( 'COMMITCAP_VERSION', '" . $version . "' )" ) ) {
	$fail( 'main plugin runtime constant does not match the Version header' );
}

// Public identity and license must agree with the plugin header.
$main_source = (string) file_get_contents( $main );
if ( ! preg_match( '/^\s*\*\s*Plugin Name:\s*CommitCap\s*$/m', $main_source ) ||
	! preg_match( '/^\s*\*\s*License:\s*GPL v2 or later\s*$/m', $main_source ) ) {
	$fail( 'main plugin header does not match the readme identity/license' );
}

echo '#63 readme preflight: title, ' . strlen( $short ) . "-char description, " . count( $tags ) . " tags, Stable tag $version, GPLv2-or-later PASS\n";
