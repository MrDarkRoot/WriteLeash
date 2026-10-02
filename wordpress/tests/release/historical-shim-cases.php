<?php
// Metadata-only boundary tests, not a substitute for Core/integration evidence.
$source = realpath( $argv[1] );
$public = $source . '/writeleash.php';
$original = file_get_contents( $public );
$public_readme = $source . '/readme.txt';
$original_readme = file_get_contents( $public_readme );
$root = '/tmp/opencode/wl121-shim-' . bin2hex( random_bytes( 6 ) );
$plugin = $root . '/wp-content/plugins/writeleash';
mkdir( $plugin, 0700, true );
mkdir( $root . '/wp-includes', 0700 );
$main = $plugin . '/writeleash.php';
$readme = $plugin . '/readme.txt';
$core = $root . '/wp-includes/version.php';
$run = static function ( string $label, bool $pass ) use ( $root, $source, $public, $original, $public_readme, $original_readme ): void {
	$process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/historical-minimum-121.php', $root, $source ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] );
	$exit = proc_close( $process );
	if ( $pass !== ( 0 === $exit ) || file_get_contents( $public ) !== $original || file_get_contents( $public_readme ) !== $original_readme ) {
		throw new RuntimeException( '#121 historical shim case failed: ' . $label . ': ' . $output );
	}
	echo '#121 HISTORICAL TEST ONLY shim boundary: ' . $label . " PASS\n";
};
try {
	file_put_contents( $core, "<?php \$wp_version = '6.8.3';\n" );
	copy( $public, $main );
	copy( $public_readme, $readme );
	$run( 'independent exact historical copy accepted; production untouched', true );
	$staged = file_get_contents( $main );
	$expected = str_replace( ' * Requires at least: 7.0', " * Requires at least: 6.8.3\n * HISTORICAL TEST ONLY: disposable WP 6.8.3 metadata shim; not public support.", $original );
	if ( $expected !== $staged ) { throw new RuntimeException( 'Historical shim changed executable body' ); }
	if ( str_replace( 'Requires at least: 7.0', 'Requires at least: 6.8.3', $original_readme ) !== file_get_contents( $readme ) ) {
		throw new RuntimeException( 'Historical shim changed readme beyond metadata compatibility' );
	}
	$run( 'second shim/previously modified copy rejected', false );
	copy( $public, $main );
	copy( $public_readme, $readme );
	file_put_contents( $core, "<?php \$wp_version = '7.1.2';\n" );
	$run( 'supported Core cannot be shimmed', false );
	file_put_contents( $core, "<?php \$wp_version = '6.8.3';\n" );
	unlink( $main ); symlink( $public, $main );
	$run( 'symlink to production rejected', false );
	unlink( $main ); link( $public, $main );
	$run( 'hardlink to production rejected', false );
	unlink( $main ); copy( $public, $main );
	unlink( $readme ); symlink( $public_readme, $readme );
	$run( 'symlink to production readme rejected', false );
	unlink( $readme ); link( $public_readme, $readme );
	$run( 'hardlink to production readme rejected', false );
} finally {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $files as $file ) { $file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); }
	rmdir( $root );
}
