<?php
// Explicit test overlay on an already validated public stage, not packaging.
$destination = $argv[1];
foreach ( file( '/opt/tests/legacy/files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $entry ) {
	if ( '#' === $entry[0] ) { continue; }
	if ( ! preg_match( '#\A(?:includes/class-[a-z-]+\.php|operator-setup\.txt)\z#', $entry ) ||
		! is_file( '/opt/writeleash/' . $entry ) || is_file( $destination . '/' . $entry ) ) {
		throw new RuntimeException( 'Invalid or overlapping historical regression entry: ' . $entry );
	}
	if ( ! copy( '/opt/writeleash/' . $entry, $destination . '/' . $entry ) ) {
		throw new RuntimeException( 'Historical staging copy failed: ' . $entry );
	}
}
$main = $destination . '/writeleash.php';
$source = file_get_contents( $main );
$needle = '\\WriteLeash\\Plugin::boot();';
if ( 1 !== substr_count( $source, $needle ) ) {
	throw new RuntimeException( 'Historical lab requires exactly one public boot anchor.' );
}
// Not in plugin source or public manifest. Only runs when the staged plugin
// itself loads, preserving the historical #61 deactivation boundary.
file_put_contents( $main, str_replace( $needle, "require_once '/opt/tests/legacy/bootstrap.php';\n" . $needle, $source ) );
echo "Repository-only historical regression overlay: explicit files + external CLI loader\n";
