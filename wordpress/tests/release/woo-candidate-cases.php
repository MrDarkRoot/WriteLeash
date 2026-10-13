<?php
// Disposable coherence-gate controls; no plugin execution or release artifact.
$repo = dirname( __DIR__, 3 );
$fixture = sys_get_temp_dir() . '/wl211-coherence-' . bin2hex( random_bytes( 6 ) );
$root = $fixture . '/candidate';
$manifest = $fixture . '/manifest.txt';
$sha = str_repeat( 'a', 40 ); // Syntactic identity fixture, not an artifact receipt.
$remove = static function ( string $dir ) use ( &$remove ): void {
 if ( ! is_dir( $dir ) ) { return; }
 foreach ( new FilesystemIterator( $dir ) as $file ) {
  if ( $file->isDir() && ! $file->isLink() ) { $remove( $file->getPathname() ); }
  else { unlink( $file->getPathname() ); }
 }
 rmdir( $dir );
};
$prepare = static function () use ( $repo, $fixture, $root, $manifest, $remove ): void {
 $remove( $fixture ); mkdir( $root, 0700, true );
 $source_manifest = $repo . '/wordpress/release/writeleash-woo-distribution-files.txt';
 copy( $source_manifest, $manifest );
 foreach ( file( $source_manifest, FILE_IGNORE_NEW_LINES ) as $entry ) {
  if ( '' === $entry || '#' === $entry[0] ) { continue; }
  $destination = $root . '/' . $entry;
  if ( ! is_dir( dirname( $destination ) ) ) { mkdir( dirname( $destination ), 0700, true ); }
  copy( $repo . '/wordpress/writeleash/' . $entry, $destination );
 }
 file_put_contents( $root . '/changelog.txt', "*** WriteLeash Changelog ***\n\n2026-10-09 - version 0.2.1\n* Added - Clear Sale Price and sale discounts from reviewed Regular Price.\n* Added - optional Price Endings, subject to precision, eligibility and safety limits.\n" );
};
$replace = static function ( string $path, string $from, string $to ): void {
 $bytes = file_get_contents( $path );
 if ( false === strpos( $bytes, $from ) ) { throw new RuntimeException( 'Missing negative-control input: ' . $from ); }
 file_put_contents( $path, str_replace( $from, $to, $bytes ) );
};
$run = static function ( string $reason = '', bool $exact = true, ?string $identity = null, string $version = '0.2.1' ) use ( $repo, $root, $manifest, $sha ): void {
 $command = array( PHP_BINARY, __DIR__ . '/woo-candidate.php', $root, $manifest, $identity ?? $sha, $version );
 if ( $exact ) { $command[] = '--exact'; }
 $pipes = array();
 $process = proc_open( $command, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
 if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Unable to launch coherence gate' ); }
 fclose( $pipes[0] );
 $output = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
 $output .= stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
 $status = proc_close( $process );
 if ( '' === $reason ? 0 !== $status || false === strpos( $output, 'product URL PASS' ) : 0 === $status || false === strpos( $output, $reason ) ) {
  throw new RuntimeException( 'Unexpected coherence result (' . $status . ', expected ' . ( $reason ?: 'PASS' ) . '): ' . $output );
 }
};
$cases = array(
 'malformed bullet' => array( 'outside the Woo format', static function () use ( $root, $replace ) { $replace( $root . '/changelog.txt', '* Added -', '- Added -' ); } ),
 'unknown change type' => array( 'unrecognized change type', static function () use ( $root, $replace ) { $replace( $root . '/changelog.txt', '* Added -', '* Imagined -' ); } ),
 'invalid date' => array( 'invalid date', static function () use ( $root, $replace ) { $replace( $root . '/changelog.txt', '2026-10-09', '2026-13-09' ); } ),
 'wrong title' => array( 'changelog title must be exactly', static function () use ( $root, $replace ) { $replace( $root . '/changelog.txt', 'WriteLeash Changelog', 'Different Changelog' ); } ),
 'missing newline' => array( 'must end with a newline', static function () use ( $root ) { $path = $root . '/changelog.txt'; file_put_contents( $path, rtrim( file_get_contents( $path ), "\n" ) ); } ),
 'missing typed changes' => array( 'at least one version entry with typed changes', static function () use ( $root ) { file_put_contents( $root . '/changelog.txt', "*** WriteLeash Changelog ***\n2026-10-09 - version 0.2.1\n" ); } ),
 'changelog version mismatch' => array( 'no entry for the candidate version', static function () use ( $root, $replace ) { $replace( $root . '/changelog.txt', '0.2.1', '9.9.9' ); } ),
 'header version mismatch' => array( 'plugin header mismatch for Version', static function () use ( $root, $replace ) { $replace( $root . '/writeleash.php', 'Version: 0.2.1', 'Version: 9.9.9' ); } ),
 'runtime version mismatch' => array( 'runtime WRITELEASH_VERSION does not match', static function () use ( $root, $replace ) { $replace( $root . '/writeleash.php', "define( 'WRITELEASH_VERSION', '0.2.1' )", "define( 'WRITELEASH_VERSION', '9.9.9' )" ); } ),
 'readme version mismatch' => array( 'Stable tag does not match', static function () use ( $root, $replace ) { $replace( $root . '/readme.txt', 'Stable tag: 0.2.1', 'Stable tag: 9.9.9' ); } ),
 'compatibility header mismatch' => array( 'plugin header mismatch for WC tested up to', static function () use ( $root, $replace ) { $replace( $root . '/writeleash.php', 'WC tested up to: 11.1', 'WC tested up to: 99.0' ); } ),
 'invented identifier' => array( 'manually supplied Woo deployment identifier', static function () use ( $root, $replace ) { $replace( $root . '/writeleash.php', 'Plugin Name: WriteLeash', "Plugin Name: WriteLeash\n * Woo: 123:invented" ); } ),
 'invented URL' => array( 'invented WooCommerce Marketplace product URL', static function () use ( $root, $replace ) { $replace( $root . '/writeleash.php', 'Plugin Name: WriteLeash', "Plugin Name: WriteLeash\n * Marketplace: https://woocommerce.com/products/invented/" ); } ),
 'missing manifest member' => array( 'allowlisted file missing', static function () use ( $root ) { unlink( $root . '/LICENSE' ); } ),
 'extra exact-tree file' => array( 'not exactly the allowlisted closure', static function () use ( $root ) { file_put_contents( $root . '/unexpected.txt', 'not public' ); } ),
 'unsorted manifest' => array( 'manifest must be sorted and unique', static function () use ( $manifest ) { $entries = file( $manifest, FILE_IGNORE_NEW_LINES ); file_put_contents( $manifest, implode( "\n", array_reverse( $entries ) ) . "\n" ); } ),
 'duplicate manifest member' => array( 'manifest must be sorted and unique', static function () use ( $manifest ) { file_put_contents( $manifest, "changelog.txt\n", FILE_APPEND ); } ),
 'changelog omitted from manifest' => array( 'manifest must include the Woo changelog', static function () use ( $manifest, $replace ) { $replace( $manifest, "changelog.txt\n", '' ); } ),
);
try {
 $prepare(); $run(); // Supported-feature wording must pass exact candidate mode.
 $prepare(); file_put_contents( $root . '/RESEARCH.md', 'repository-only source context' ); $run( '', false );
 foreach ( $cases as $case ) { $prepare(); $case[1](); $run( $case[0] ); }
 $prepare(); $run( 'not a 40-character commit id', true, 'not-a-sha' );
 $prepare(); $run( 'candidate version is not numeric', true, null, 'latest' );
 echo '#211 Woo coherence: supported Clear Sale/Price Endings accepted in exact/subset modes; 20 malformed/identity/manifest negatives rejected PASS' . "\n";
} finally { $remove( $fixture ); }
