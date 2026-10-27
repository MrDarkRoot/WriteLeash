<?php
// #211 Woo Marketplace review-candidate coherence gate. Runs against an
// extracted candidate tree (invoked by build-woo-candidate.py with --exact)
// or the live plugin source root (invoked by wordpress/tests/release/run.sh
// without --exact, where repository research files legitimately surround the
// allowlisted closure). Creates no artifact. Usage:
//   woo-candidate.php <candidate-root> <distribution-manifest> <sha> <version> [--exact]
if ( 5 !== $argc && 6 !== $argc ) {
	throw new RuntimeException( 'Usage: woo-candidate.php <candidate-root> <distribution-manifest> <sha> <version> [--exact]' );
}
$root          = rtrim( $argv[1], '/' );
$manifest_path = $argv[2];
$sha           = $argv[3];
$version       = $argv[4];
$exact         = 6 === $argc && '--exact' === $argv[5];
if ( 6 === $argc && ! $exact ) {
	throw new RuntimeException( 'Usage: woo-candidate.php <candidate-root> <distribution-manifest> <sha> <version> [--exact]' );
}
$fail          = static function ( string $message ): void {
	throw new RuntimeException( '#211 woo candidate: ' . $message );
};

if ( ! preg_match( '/\A[0-9a-f]{40}\z/', $sha ) ) {
	$fail( 'candidate source SHA is not a 40-character commit id' );
}
if ( ! preg_match( '/\A\d+\.\d+\.\d+\z/', $version ) ) {
	$fail( 'candidate version is not numeric major.minor.patch' );
}
foreach ( array( $root . '/writeleash.php', $root . '/readme.txt', $root . '/changelog.txt', $manifest_path ) as $file ) {
	if ( ! is_file( $file ) ) {
		$fail( 'missing required file: ' . $file );
	}
}

// A. Header validation: every declared minimum/tested value must be exact.
$main = (string) file_get_contents( $root . '/writeleash.php' );
$expected_headers = array(
	'Plugin Name'         => 'WriteLeash',
	'Plugin URI'          => 'https://github.com/MrDarkRoot/WriteLeash',
	'Version'             => $version,
	'Author'              => 'Duy Tran',
	'Author URI'          => 'https://profiles.wordpress.org/duyytrann',
	'Developer'           => 'Duy Tran',
	'Developer URI'       => 'https://profiles.wordpress.org/duyytrann',
	'Requires at least'   => '6.7',
	'Tested up to'        => '7.1',
	'Requires PHP'        => '7.4',
	'Requires Plugins'    => 'woocommerce',
	'WC requires at least' => '9.0',
	'WC tested up to'     => '11.1',
	'Text Domain'         => 'writeleash',
	'Domain Path'         => '/languages',
	'License'             => 'GPL v2 or later',
	'License URI'         => 'https://www.gnu.org/licenses/gpl-2.0.html',
);
foreach ( $expected_headers as $key => $wanted ) {
	if ( ! preg_match( '/^\s*\*\s*' . preg_quote( $key, '/' ) . ':\s*(.+?)\s*$/m', $main, $match ) ) {
		$fail( 'missing plugin header: ' . $key );
	}
	if ( $match[1] !== $wanted ) {
		$fail( 'plugin header mismatch for ' . $key . ': ' . $match[1] );
	}
}
// The Woo deployment identifier is assigned automatically on submission; a
// pre-submission candidate must not guess or hand-supply it.
if ( preg_match( '/^\s*\*\s*Woo\s*:/m', $main ) ) {
	$fail( 'manually supplied Woo deployment identifier' );
}
// No invented Marketplace product URL: the only accepted URIs are the real
// project repository and the real author profile.
if ( preg_match( '/woocommerce\.com\/(products?|shop|extensions?)\//i', $main ) ) {
	$fail( 'invented WooCommerce Marketplace product URL in plugin header' );
}
foreach ( array( 'https://github.com/MrDarkRoot/WriteLeash', 'https://profiles.wordpress.org/duyytrann' ) as $uri ) {
	if ( false === strpos( $main, $uri ) ) {
		$fail( 'expected real project URI missing from plugin header: ' . $uri );
	}
}
// Preserved dependency and HPOS declarations.
if ( false === strpos( $main, "declare_compatibility( 'custom_order_tables'" ) ) {
	$fail( 'HPOS custom_order_tables declaration missing' );
}
if ( substr_count( $main, 'Plugin Name:' ) !== 1 ) {
	$fail( 'duplicate plugin header block' );
}

// WC compatibility must stay inside the shipped support contract.
$contract_file = $root . '/includes/free/class-free-support-contract.php';
if ( ! is_file( $contract_file ) ) {
	$fail( 'missing Free support contract' );
}
$contract = (string) file_get_contents( $contract_file );
if ( ! preg_match( "/WOOCOMMERCE_MIN = '([^']+)'/", $contract, $min_match ) ||
	! preg_match( "/WOOCOMMERCE_MAX_EXCLUSIVE = '([^']+)'/", $contract, $max_match ) ) {
	$fail( 'unreadable WooCommerce support-contract range' );
}
if ( '10.0.0' !== $min_match[1] || '12.0.0' !== $max_match[1] ) {
	$fail( 'modern WooCommerce band must stay 10.0.0 inclusive to 12.0.0 exclusive, saw ' . $min_match[1] . ' to ' . $max_match[1] );
}
if ( ! preg_match( "/WORDPRESS_MIN = '7\\.0'/", $contract ) || ! preg_match( "/WORDPRESS_MAX_EXCLUSIVE = '8\\.0'/", $contract ) ) {
	$fail( 'modern WordPress band must stay 7.0 inclusive to 8.0 exclusive' );
}
if ( ! preg_match( "/LEGACY_WORDPRESS_MIN = '6\\.7'/", $contract ) || ! preg_match( "/LEGACY_WORDPRESS_MAX_EXCLUSIVE = '7\\.0'/", $contract ) || ! preg_match( "/LEGACY_WOOCOMMERCE_MIN = '9\\.0\\.0'/", $contract ) || ! preg_match( "/LEGACY_WOOCOMMERCE_MAX_EXCLUSIVE = '10\\.0\\.0'/", $contract ) ) {
	$fail( 'legacy pair band must stay WordPress 6.7 up to 7.0 and WooCommerce 9.0.0 up to 10.0.0' );
}
if ( ! ( version_compare( '11.1', $min_match[1], '>=' ) && version_compare( '11.1', $max_match[1], '<' ) ) ) {
	$fail( 'WC tested up to 11.1 is outside the modern support contract ' . $min_match[1] . ' to ' . $max_match[1] );
}

// B. Version coherence across every candidate identity surface.
if ( false === strpos( $main, "define( 'WRITELEASH_VERSION', '" . $version . "' )" ) ) {
	$fail( 'runtime WRITELEASH_VERSION does not match the candidate version' );
}
$readme = (string) file_get_contents( $root . '/readme.txt' );
if ( ! preg_match( '/^Stable tag:\s*(\S+)\s*$/m', $readme, $stable_match ) || $stable_match[1] !== $version ) {
	$fail( 'readme Stable tag does not match the candidate version' );
}
$changelog = (string) file_get_contents( $root . '/changelog.txt' );
if ( false === strpos( $changelog, '- version ' . $version ) ) {
	$fail( 'changelog.txt has no entry for the candidate version' );
}

// C. Changelog validation per Woo changelog.txt conventions.
$changelog_lines = explode( "\n", $changelog );
if ( trim( $changelog_lines[0] ) !== '*** WriteLeash Changelog ***' ) {
	$fail( 'changelog title must be exactly "*** WriteLeash Changelog ***"' );
}
if ( ! str_ends_with( $changelog, "\n" ) ) {
	$fail( 'changelog.txt must end with a newline' );
}
$entry_count = 0;
$typed_bullets = 0;
$allowed_types = array( 'add', 'added', 'feature', 'new', 'developer', 'dev', 'tweak', 'changed', 'update', 'delete', 'remove', 'fixed', 'fix' );
foreach ( $changelog_lines as $line ) {
	if ( trim( $line ) === '*** WriteLeash Changelog ***' ) {
		continue;
	}
	if ( preg_match( '/\A(\d{4}-\d{2}-\d{2}) - version (\d+\.\d+\.\d+)\s*\z/', $line, $entry ) ) {
		++$entry_count;
		if ( ! preg_match( '/\A\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])\z/', $entry[1] ) ) {
			$fail( 'changelog entry has an invalid date: ' . $line );
		}
		continue;
	}
	if ( preg_match( '/\A\* ([A-Za-z]+) - .+\z/', $line, $bullet ) ) {
		if ( ! in_array( strtolower( $bullet[1] ), $allowed_types, true ) ) {
			$fail( 'changelog bullet has an unrecognized change type: ' . $line );
		}
		++$typed_bullets;
		continue;
	}
	if ( '' === trim( $line ) ) {
		continue;
	}
	$fail( 'changelog.txt has a line outside the Woo format: ' . $line );
}
if ( $entry_count < 1 || $typed_bullets < 1 ) {
	$fail( 'changelog.txt must carry at least one version entry with typed changes' );
}
// Feature wording follows the reviewed candidate source, not the original
// #211 candidate's historical deferred-feature list (#207/#208 are merged).

// D. Allowlist validation: exact public-file closure for the candidate.
$raw = file( $manifest_path, FILE_IGNORE_NEW_LINES );
if ( ! is_array( $raw ) ) {
	$fail( 'candidate manifest unreadable' );
}
$entries = array();
foreach ( $raw as $line ) {
	if ( $line !== trim( $line ) ) {
		$fail( 'candidate manifest whitespace is not canonical' );
	}
	if ( '' === $line || 0 === strpos( $line, '#' ) ) {
		continue;
	}
	$entries[] = $line;
}
$sorted = $entries;
sort( $sorted, SORT_STRING );
if ( $entries !== $sorted || count( $entries ) !== count( array_unique( $entries ) ) ) {
	$fail( 'candidate manifest must be sorted and unique' );
}
if ( ! in_array( 'changelog.txt', $entries, true ) ) {
	$fail( 'candidate manifest must include the Woo changelog.txt' );
}
foreach ( $entries as $entry ) {
	if ( ! is_file( $root . '/' . $entry ) ) {
		$fail( 'allowlisted file missing from candidate: ' . $entry );
	}
	if ( 'md' === strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ) ) {
		$fail( 'markdown is not permitted in the distribution candidate: ' . $entry );
	}
}
$on_disk = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( $file->isFile() ) {
		$on_disk[] = substr( $file->getPathname(), strlen( $root ) + 1 );
	}
}
sort( $on_disk, SORT_STRING );
if ( $exact && $on_disk !== $entries ) {
	$fail( 'candidate tree is not exactly the allowlisted closure' );
}
if ( ! $exact ) {
	foreach ( $entries as $entry ) {
		if ( ! in_array( $entry, $on_disk, true ) ) {
			$fail( 'allowlisted file missing from source tree: ' . $entry );
		}
	}
}

// E. Runtime require closure: every file the entry loader pulls in with
// `require_once __DIR__ . '/…'` must be published by BOTH distribution
// manifests. A require omitted from the Woo candidate manifest would fatal
// on activation while the WordPress.org artifact and the production
// inventory gate stay green; pin the reverse direction here.
$required_paths = array();
if ( preg_match_all( "/require_once\s+__DIR__\s*\.\s*'([^']+)'/", $main, $require_matches ) ) {
	foreach ( $require_matches[1] as $require_path ) {
		$required_paths[] = ltrim( $require_path, '/' );
	}
}
if ( array() === $required_paths ) {
	$fail( 'no require_once __DIR__ includes parsed from the entry file' );
}
$org_manifest_path = dirname( __DIR__, 2 ) . '/release/writeleash-distribution-files.txt';
if ( ! is_file( $org_manifest_path ) ) {
	$fail( 'missing WordPress.org distribution manifest: ' . $org_manifest_path );
}
$org_entries = array();
foreach ( file( $org_manifest_path, FILE_IGNORE_NEW_LINES ) as $org_line ) {
	if ( '' === $org_line || 0 === strpos( $org_line, '#' ) ) {
		continue;
	}
	$org_entries[] = $org_line;
}
if ( array() === $org_entries ) {
	$fail( 'WordPress.org distribution manifest unreadable: ' . $org_manifest_path );
}
foreach ( $required_paths as $required_path ) {
	if ( ! in_array( $required_path, $entries, true ) ) {
		$fail( 'runtime require omitted from the Woo candidate manifest: ' . $required_path );
	}
	if ( ! in_array( $required_path, $org_entries, true ) ) {
		$fail( 'runtime require omitted from the WordPress.org manifest: ' . $required_path );
	}
}

echo '#211 woo candidate ' . $version . ' @' . substr( $sha, 0, 12 ) . ': ' . count( $expected_headers ) . " headers, version coherence, $entry_count changelog entries with $typed_bullets typed changes, " . count( $entries ) . ' allowlisted files, ' . count( $required_paths ) . " entry requires in both manifests, no Woo identifier, no product URL PASS\n";
