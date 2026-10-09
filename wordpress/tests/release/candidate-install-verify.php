<?php
// #211 candidate install verification: exercises WordPress's normal Upload
// Plugin semantics against an exact candidate ZIP without needing a database.
// WordPress's Plugin_Upgrader installs a plugin ZIP by unzipping the package
// into wp-content/plugins/ preserving archive paths; this script replicates
// exactly that step into a fresh directory, then proves the installed tree
// matches the ZIP byte-for-byte and the entry point parses under WordPress
// core's own get_file_data() header algorithm. Creates no artifact outside
// the given install root. Usage:
//   candidate-install-verify.php <candidate-zip> <evidence.json> <install-root>
if ( 4 !== $argc ) {
	throw new RuntimeException( 'Usage: candidate-install-verify.php <candidate-zip> <evidence.json> <install-root>' );
}
$zip_path     = $argv[1];
$evidence_path = $argv[2];
$install_root = rtrim( $argv[3], '/' );
$fail         = static function ( string $message ): void {
	throw new RuntimeException( '#211 install verify: ' . $message );
};

if ( ! is_file( $zip_path ) || ! is_file( $evidence_path ) ) {
	$fail( 'missing candidate ZIP or evidence.json' );
}
$evidence = json_decode( (string) file_get_contents( $evidence_path ), true );
if ( ! is_array( $evidence ) || ! isset( $evidence['ZIP_SHA256'], $evidence['RUNTIME_FILES'], $evidence['VERSION'] ) ) {
	$fail( 'evidence.json is not a candidate evidence record' );
}
if ( hash_file( 'sha256', $zip_path ) !== $evidence['ZIP_SHA256'] ) {
	$fail( 'ZIP digest does not match the recorded candidate evidence' );
}
if ( is_dir( $install_root ) ) {
	$fail( 'install root already exists; use a fresh directory' );
}

// WordPress upload semantics: unzip the package into wp-content/plugins/.
// Plugin_Upgrader::install() -> unzip_file($package, $working_dir) keeps
// archive-internal paths verbatim, so extraction must not rename or skip.
$plugins_dir = $install_root . '/wp-content/plugins';
if ( ! mkdir( $plugins_dir, 0777, true ) ) {
	$fail( 'cannot create install root' );
}
$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path ) ) {
	$fail( 'cannot open candidate ZIP' );
}
if ( ! $zip->extractTo( $plugins_dir ) ) {
	$fail( 'ZIP extraction failed' );
}
$zip->close();
$installed = $plugins_dir . '/writeleash';
if ( ! is_dir( $installed ) ) {
	$fail( 'installed plugin directory writeleash/ missing after upload' );
}

// Installed tree must equal the ZIP's expected contents and hashes.
$expected = array();
foreach ( $evidence['RUNTIME_FILES'] as $row ) {
	$expected[ $row['path'] ] = $row;
}
$on_disk = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $installed, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$relative = substr( $file->getPathname(), strlen( $installed ) + 1 );
	$on_disk[] = $relative;
	if ( ! isset( $expected[ $relative ] ) ) {
		$fail( 'installed file has no ZIP provenance: ' . $relative );
	}
	$actual_size = filesize( $file->getPathname() );
	$actual_sha  = hash_file( 'sha256', $file->getPathname() );
	if ( $actual_size !== $expected[ $relative ]['size'] || $actual_sha !== $expected[ $relative ]['sha256'] ) {
		$fail( 'installed file differs from ZIP bytes: ' . $relative );
	}
}
sort( $on_disk, SORT_STRING );
$manifest_paths = array_keys( $expected );
sort( $manifest_paths, SORT_STRING );
if ( $on_disk !== $manifest_paths ) {
	$fail( 'installed tree omits ZIP files' );
}

// WordPress core's get_file_data() header algorithm, verbatim semantics:
// each requested field matches ^[ \t\/*#@]*FieldName:(.*)$ in the file's
// first 8kiB docblock region. Replicated here so header recognition does not
// depend on a live database.
$entry = (string) file_get_contents( $installed . '/writeleash.php' );
$read_header = static function ( string $source, string $field ): string {
	if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $source, $match ) ) {
		return trim( $match[1] );
	}
	return '';
};
$parsed = array(
	'Name'           => $read_header( $entry, 'Plugin Name' ),
	'PluginURI'      => $read_header( $entry, 'Plugin URI' ),
	'Version'        => $read_header( $entry, 'Version' ),
	'RequiresWP'     => $read_header( $entry, 'Requires at least' ),
	'RequiresPHP'    => $read_header( $entry, 'Requires PHP' ),
	'RequiresPlugins' => $read_header( $entry, 'Requires Plugins' ),
	'WCRequires'     => $read_header( $entry, 'WC requires at least' ),
	'WCTested'       => $read_header( $entry, 'WC tested up to' ),
	'TextDomain'     => $read_header( $entry, 'Text Domain' ),
);
foreach ( array(
	'Name' => 'WriteLeash',
	'Version' => $evidence['VERSION'],
	'RequiresWP' => '7.0',
	'RequiresPHP' => '7.4',
	'RequiresPlugins' => 'woocommerce',
	'WCRequires' => '10.0',
	'WCTested' => '11.1',
	'TextDomain' => 'writeleash',
) as $field => $wanted ) {
	if ( $parsed[ $field ] !== $wanted ) {
		$fail( 'installed header field ' . $field . ' is ' . var_export( $parsed[ $field ], true ) );
	}
}
// Dependency behavior surface: with WooCommerce absent, WordPress core shows
// a Requires-Plugins notice and refuses activation; the plugin must never
// fatal during that discovery. The entry point aborts before any class load.
if ( 0 !== strpos( trim( $entry ), '<?php' ) ) {
	$fail( 'installed entry point does not open with <?php' );
}
if ( false === strpos( $entry, "if ( ! defined( 'ABSPATH' ) )" ) ) {
	$fail( 'installed entry point lost its ABSPATH guard' );
}
if ( false === strpos( $entry, "declare_compatibility( 'custom_order_tables'" ) ) {
	$fail( 'installed entry point lost its HPOS declaration' );
}
if ( false === strpos( (string) file_get_contents( $installed . '/uninstall.php' ), "defined( 'WP_UNINSTALL_PLUGIN' )" ) ) {
	$fail( 'installed uninstall guard missing' );
}
foreach ( $on_disk as $relative ) {
	if ( 'php' !== pathinfo( $relative, PATHINFO_EXTENSION ) ) {
		continue;
	}
	$output = array();
	$code = 0;
	exec( 'php -l ' . escapeshellarg( $installed . '/' . $relative ) . ' 2>&1', $output, $code );
	if ( 0 !== $code ) {
		$fail( 'installed PHP does not lint: ' . $relative );
	}
}

echo '#211 install verify: WordPress upload semantics into ' . $install_root . ', ' . count( $on_disk ) . ' installed files byte-equal to ZIP ' . substr( $evidence['ZIP_SHA256'], 0, 12 ) . ', core header parse v' . $evidence['VERSION'] . ', lint clean, HPOS/uninstall guards intact PASS' . "\n";
