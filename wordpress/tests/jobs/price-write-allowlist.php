<?php
// #295 price-write boundary allowlist: plugin PHP under wordpress/writeleash/
// may not gain another price writer without a review that updates this list
// and the contract in the same change.
//   php wordpress/tests/jobs/price-write-allowlist.php wordpress/writeleash
// Token-based scanning is a review tripwire, not a sandbox: it cannot detect
// every variable method, concatenated method name or raw SQL write. Pair it
// with the runtime mutation suites. Test fixtures under wordpress/tests/ are
// not production code and are never scanned: the scan root stays on the plugin.
$root = $argv[1] ?? __DIR__ . '/../../writeleash';

// File-and-symbol narrow allowlist. No whole-directory or broad-file exclusion.
$allowed = array(
	'includes/free/class-woo-price-mutator.php'    => array( 'set_regular_price', 'set_sale_price' ),
	'includes/free/class-woo-undo-mutator.php'     => array( 'set_regular_price', 'set_sale_price' ),
	'includes/free/class-price-cache-verifier.php' => array( 'WC_Product_Variable::sync' ),
	// #310 parent stock-status sync is the public Woo API, not a price write.
	// The token scanner matches the `sync` prefix of `sync_stock_status`.
	'includes/free/class-woo-stock-mutator.php'    => array( 'WC_Product_Variable::sync' ),
);
$watched = array( 'set_regular_price', 'set_sale_price', 'WC_Product_Variable::sync', 'update_post_meta(', 'update_metadata(' );

function wl295_audit_tree( string $root, array $allowed, array $watched ): array {
	$seen = array();
	$count = 0;
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( '.php' !== substr( $file->getPathname(), -4 ) ) { continue; }
		++$count;
		$relative = substr( $file->getPathname(), strlen( rtrim( $root, '/' ) ) + 1 );
		$source = file_get_contents( $file->getPathname() );
		if ( ! is_string( $source ) ) { throw new RuntimeException( 'price-write audit target unreadable: ' . $relative ); }
		if ( isset( $allowed[ $relative ] ) ) {
			$seen[] = $relative;
			foreach ( $watched as $token ) {
				if ( ! in_array( $token, $allowed[ $relative ], true ) && str_contains( $source, $token ) ) {
					throw new RuntimeException( 'price-write boundary: ' . $token . ' in ' . $relative );
				}
			}
			continue;
		}
		foreach ( $watched as $token ) {
			if ( str_contains( $source, $token ) ) {
				throw new RuntimeException( 'price-write boundary: ' . $token . ' in ' . $relative );
			}
		}
	}
	return array( 'files' => $count, 'seen' => $seen );
}

function wl295_remove_tree( string $dir ): void {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $file ) {
		if ( $file->isDir() ) { rmdir( $file->getPathname() ); }
		else { unlink( $file->getPathname() ); }
	}
	rmdir( $dir );
}

// Temporary copied tree for negative controls. Never committed under the
// plugin: temp dirs only, removed after each control.
function wl295_temp_tree( string $root, array $allowed, array $extra = array(), array $append = array() ): string {
	$dir = sys_get_temp_dir() . '/wl295-' . bin2hex( random_bytes( 6 ) );
	mkdir( $dir, 0777, true );
	foreach ( array_keys( $allowed ) as $relative ) {
		$source = file_get_contents( rtrim( $root, '/' ) . '/' . $relative );
		if ( ! is_string( $source ) ) { throw new RuntimeException( 'allowlist source missing: ' . $relative ); }
		if ( isset( $append[ $relative ] ) ) { $source .= $append[ $relative ]; }
		$target = $dir . '/' . $relative;
		if ( ! is_dir( dirname( $target ) ) ) { mkdir( dirname( $target ), 0777, true ); }
		file_put_contents( $target, $source );
	}
	foreach ( $extra as $relative => $content ) {
		$target = $dir . '/' . $relative;
		if ( ! is_dir( dirname( $target ) ) ) { mkdir( dirname( $target ), 0777, true ); }
		file_put_contents( $target, $content );
	}
	return $dir;
}

function wl295_expect_failure( string $root, array $allowed, array $watched, string $file, string $operation, string $label ): void {
	try { wl295_audit_tree( $root, $allowed, $watched ); }
	catch ( RuntimeException $error ) {
		if ( ! str_contains( $error->getMessage(), $file ) || ! str_contains( $error->getMessage(), $operation ) ) {
			throw new RuntimeException( $label . ': diagnostics must name file and operation, got: ' . $error->getMessage() );
		}
		echo '#295 negative ' . $label . ': ' . $error->getMessage() . " PASS\n";
		return;
	}
	throw new RuntimeException( $label . ': check passed a forbidden writer' );
}

// Positive: the real plugin tree passes, and every allowlisted file was visited.
$result = wl295_audit_tree( $root, $allowed, $watched );
foreach ( array_keys( $allowed ) as $relative ) {
	if ( ! in_array( $relative, $result['seen'], true ) ) { throw new RuntimeException( 'price-write audit did not visit allowlisted file: ' . $relative ); }
}
echo '#295 positive: ' . $result['files'] . ' plugin PHP files scanned; writers only in the ' . count( $allowed ) . " allowlisted files/operations PASS\n";

// Negative: a setter in a non-allowlisted plugin file fails.
$dir = wl295_temp_tree( $root, $allowed, array( 'includes/free/class-evil-widget.php' => "<?php\n\$product->set_regular_price( '1.00' );\n" ) );
try { wl295_expect_failure( $dir, $allowed, $watched, 'class-evil-widget.php', 'set_regular_price', 'setter outside allowlist' ); }
finally { wl295_remove_tree( $dir ); }

// Negative: WC_Product_Variable::sync outside the cache verifier fails.
$dir = wl295_temp_tree( $root, $allowed, array( 'includes/free/class-sync-elsewhere.php' => "<?php\n\\WC_Product_Variable::sync( \$parent );\n" ) );
try { wl295_expect_failure( $dir, $allowed, $watched, 'class-sync-elsewhere.php', 'WC_Product_Variable::sync', 'sync outside verifier' ); }
finally { wl295_remove_tree( $dir ); }

// Negative: set_regular_price inside the cache verifier fails.
$dir = wl295_temp_tree( $root, $allowed, array(), array( 'includes/free/class-price-cache-verifier.php' => "\n\$product->set_regular_price( '1.00' );\n" ) );
try { wl295_expect_failure( $dir, $allowed, $watched, 'class-price-cache-verifier.php', 'set_regular_price', 'setter inside verifier' ); }
finally { wl295_remove_tree( $dir ); }

// Negative: direct metadata writes are detected.
$dir = wl295_temp_tree( $root, $allowed, array( 'includes/free/class-meta-writer.php' => "<?php\nupdate_post_meta( \$id, '_regular_price', '1.00' );\n" ) );
try { wl295_expect_failure( $dir, $allowed, $watched, 'class-meta-writer.php', 'update_post_meta(', 'direct meta write' ); }
finally { wl295_remove_tree( $dir ); }
$dir = wl295_temp_tree( $root, $allowed, array( 'includes/free/class-meta-writer.php' => "<?php\nupdate_metadata( 'post', \$id, '_regular_price', '1.00' );\n" ) );
try { wl295_expect_failure( $dir, $allowed, $watched, 'class-meta-writer.php', 'update_metadata(', 'direct metadata write' ); }
finally { wl295_remove_tree( $dir ); }

// #295 PERMISSION_REVOKED copy: names the plan actor (preview creator), never
// the approver. Authorization behavior is unchanged; the string matches the
// worker, which re-checks plan.actor_id.
$state = file_get_contents( rtrim( $root, '/' ) . '/includes/free/class-job-state.php' );
if ( ! is_string( $state ) ) { throw new RuntimeException( 'PERMISSION_REVOKED source missing' ); }
if ( ! str_contains( $state, 'The person who created this preview can no longer edit one or more products; remaining products are paused.' ) ) {
	throw new RuntimeException( 'PERMISSION_REVOKED does not name the plan actor' );
}
if ( str_contains( $state, 'person who approved' ) ) { throw new RuntimeException( 'PERMISSION_REVOKED still names the approver' ); }
echo "#295 PERMISSION_REVOKED names the preview creator (plan actor), not the approver PASS\n";
echo "#295 price-write boundary allowlist PASS (token tripwire only: variable methods, concatenated names and raw SQL need runtime review)\n";
