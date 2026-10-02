<?php
// Adversarial packaging gate tests; no WordPress, legacy loader or release ZIP.
require_once __DIR__ . '/public-runtime-audit.php';
$source = realpath( $argv[1] );
$manifest = $argv[2];
$entries = array_values( array_filter( file( $manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ), static fn( $line ) => '#' !== $line[0] ) );
$root = '/tmp/opencode/wl120-audit-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0700 );
$write = static function ( string $entry, string $content ) use ( $root ): void {
	$directory = dirname( $root . '/' . $entry );
	if ( ! is_dir( $directory ) ) { mkdir( $directory, 0700, true ); }
	file_put_contents( $root . '/' . $entry, $content );
};
foreach ( $entries as $entry ) { $write( $entry, file_get_contents( $source . '/' . $entry ) ); }
$expect = static function ( string $label, array $candidate, bool $pass ) use ( $root ): void {
	ob_start();
	try {
		writeleash_public_runtime_audit( $root, $candidate );
		$passed = true;
	} catch ( RuntimeException $error ) { $passed = false; }
	finally { ob_end_clean(); }
	if ( $pass !== $passed ) { throw new RuntimeException( 'Public audit adversarial case failed: ' . $label . ( isset( $error ) ? ': ' . $error->getMessage() : '' ) ); }
	echo '#120 audit negative/positive case: ' . $label . " PASS\n";
};
try {
	$expect( 'exact public closure accepted', $entries, true );
	$expect( 'required include omitted fails', array_values( array_diff( $entries, array( 'includes/free/class-job-worker.php' ) ) ), false );
	$write( 'includes/free/class-research.php', "<?php namespace WriteLeash; class Research_Only {}\n" );
	$expect( 'new unused repository PHP does not become public', $entries, true );
	$expect( 'unused PHP added to manifest fails closure', array_merge( $entries, array( 'includes/free/class-research.php' ) ), false );
	$write( 'operator-setup.txt', 'historical instructions' );
	$expect( 'operator setup rejected', array_merge( $entries, array( 'operator-setup.txt' ) ), false );
	$write( 'includes/class-guard.php', '<?php class Guard {}' );
	$expect( 'historical class path rejected', array_merge( $entries, array( 'includes/class-guard.php' ) ), false );
	$expect( 'test loader path rejected', array_merge( $entries, array( 'tests/legacy/bootstrap.php' ) ), false );
	$original = file_get_contents( $source . '/includes/class-plugin.php' );
	$write( 'includes/class-plugin.php', $original . "\n// an ordinary guard comment is harmless\n" );
	$expect( 'English guard comment allowed', $entries, true );
	foreach ( array( 'Guard::update();', 'guard::update();', "call_user_func('WriteLeash\\\\Guard::update');", 'Compatibility_Doctor::runtime();', 'Admin_Page::boot();', 'Product_CLI::class;', 'new Missing_Public_Class();', '\\WriteLeash\\Missing_Public_Class::run();', "class_exists('WriteLeash\\\\Missing_Public_Class');", "require_once __DIR__ . '/includes/free/class-missing.php';", 'require_once $unreviewed;' ) as $code ) {
		$write( 'includes/class-plugin.php', $original . "\n" . $code );
		$expect( 'forbidden/unresolved/dynamic dependency: ' . $code, $entries, false );
	}
	$write( 'includes/class-plugin.php', $original );
	unlink( $root . '/includes/class-plugin.php' );
	symlink( $source . '/includes/class-plugin.php', $root . '/includes/class-plugin.php' );
	$expect( 'symlinked payload rejected', $entries, false );
} finally {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $files as $file ) { $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); }
	rmdir( $root );
}
