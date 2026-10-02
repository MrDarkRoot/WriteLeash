<?php
// #120: enforce complete, single-valued PHP inventory and historical overlay.
$root = $argv[1];
$inventory = file_get_contents( $argv[2] );
$manifest = file( $argv[3], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
$legacy = file( dirname( __DIR__ ) . '/legacy/files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
$classified = array();
foreach ( explode( "\n", $inventory ) as $line ) {
	if ( ! preg_match( '/^\| ([^|]+) \| [^|]+ \| (PUBLIC_FREE_REQUIRED|REPOSITORY_ONLY_HISTORICAL|OPTIONAL_ADVANCED_FUTURE|REMOVE_FROM_PUBLIC_PACKAGE) \|$/', $line, $match ) ) { continue; }
	$path = $match[1];
	if ( preg_match( '/\A[a-z-]+\z/', $path ) ) { $path = 'includes/free/class-' . $path . '.php'; }
	if ( 'php' !== pathinfo( $path, PATHINFO_EXTENSION ) ) { continue; }
	if ( isset( $classified[ $path ] ) ) { throw new RuntimeException( 'Duplicate PHP inventory classification: ' . $path ); }
	$classified[ $path ] = $match[2];
}
$files = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) { continue; }
	$path = substr( $file->getPathname(), strlen( $root ) + 1 );
	$files[] = $path;
	if ( ! isset( $classified[ $path ] ) ) { throw new RuntimeException( 'Uninventoried source PHP: ' . $path ); }
	$public = 'PUBLIC_FREE_REQUIRED' === $classified[ $path ];
	if ( $public !== in_array( $path, $manifest, true ) || $public === in_array( $path, $legacy, true ) ) {
		throw new RuntimeException( 'Inventory/public/legacy staging disagreement: ' . $path );
	}
}
if ( array_diff( array_keys( $classified ), $files ) ) { throw new RuntimeException( 'Inventory names a missing PHP file' ); }
echo '#120 checked inventory: ' . count( $files ) . ' PHP files, exactly one classification each; public=' . count( array_filter( $classified, static fn( $value ) => 'PUBLIC_FREE_REQUIRED' === $value ) ) . "; explicit legacy overlay agrees PASS\n";
