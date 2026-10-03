<?php
// Fixture-only satellite staging. Flagship continues through stage-plugin.sh.
if ( 3 !== $argc || ! in_array( $argv[2], array( 'price-history', 'price-campaigns' ), true ) ) {
    throw new RuntimeException( 'Expected site path and one explicit satellite target' );
}
$target = $argv[2];
$slug = 'writeleash-' . $target;
$source = '/opt/' . $slug;
$destination = rtrim( $argv[1], '/' ) . '/wp-content/plugins/' . $slug;
$lines = file( '/opt/release/' . $target . '/distribution-files.txt', FILE_IGNORE_NEW_LINES );
$paths = array_values( array_filter( $lines, static function ( $line ) { return '' !== $line && '#' !== $line[0]; } ) );
$sorted = $paths;
sort( $sorted, SORT_STRING );
if ( $sorted !== $paths || count( $paths ) !== count( array_unique( $paths ) ) || file_exists( $destination ) ) {
    throw new RuntimeException( 'Noncanonical manifest or existing destination' );
}
$actual = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
    if ( $file->isLink() || ! $file->isFile() ) { throw new RuntimeException( 'Linked/nonregular satellite input' ); }
    $actual[] = substr( $file->getPathname(), strlen( $source ) + 1 );
}
sort( $actual, SORT_STRING );
if ( $actual !== $paths ) { throw new RuntimeException( 'Unexpected satellite source file' ); }
foreach ( array( 'LICENSE', 'readme.txt', 'uninstall.php', $slug . '.php' ) as $required ) {
    if ( ! in_array( $required, $paths, true ) ) { throw new RuntimeException( 'Incomplete target manifest' ); }
}
foreach ( $paths as $path ) {
    if ( ! preg_match( '#\A[A-Za-z0-9_./-]+\z#D', $path ) || false !== strpos( $path, '..' ) || '/' === $path[0] ) {
        throw new RuntimeException( 'Unsafe satellite manifest path' );
    }
    $directory = dirname( $destination . '/' . $path );
    if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) ) { throw new RuntimeException( 'Cannot stage target' ); }
    if ( ! copy( $source . '/' . $path, $destination . '/' . $path ) ) { throw new RuntimeException( 'Cannot copy target file' ); }
}
echo '#144 finite fixture staging: ' . $target . " PASS\n";
