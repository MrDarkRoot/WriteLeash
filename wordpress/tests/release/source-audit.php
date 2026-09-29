<?php
// Supplement, not replacement, for actual Admin/REST/Guard integration tests.
$root = $argv[1] ?? '';
if ( ! is_dir( $root . '/includes' ) || ! is_file( $root . '/writeleash.php' ) ||
	is_file( $root . '/writeleash-for-wordpress.php' ) || is_file( $root . '/commitcap.php' ) ) {
	throw new RuntimeException( 'Not the installed writeleash source root' );
}
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$php_count = 0;
$headers = 0;
foreach ( $files as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$path = $file->getPathname();
	$relative = substr( $path, strlen( $root ) );
	if ( preg_match( '/\.(?:min\.(?:js|css)|phar|zip)$/i', $relative ) ||
		preg_match( '#/(?:vendor|node_modules|tests)/#', $relative ) ) {
		throw new RuntimeException( 'Unexpected compiled/dependency/test file in staged root: ' . $path );
	}
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	++$php_count;
	$source = file_get_contents( $path );
	$headers += preg_match_all( '/^\s*\*\s*Plugin Name:/m', $source );
	if ( basename( $path ) === 'uninstall.php' ) {
		if ( false === strpos( $source, "defined( 'WP_UNINSTALL_PLUGIN' )" ) ) {
			throw new RuntimeException( 'Uninstall guard missing' );
		}
	} elseif ( false === strpos( $source, "defined( 'ABSPATH' )" ) ) {
		throw new RuntimeException( 'ABSPATH direct access guard missing: ' . $path );
	}
	if ( str_contains( $path, '/includes/' ) && false === strpos( $source, 'namespace WriteLeash;' ) ) {
		throw new RuntimeException( 'Unprefixed production include: ' . $path );
	}
	$code = php_strip_whitespace( $path ); // Exclude comments, not PHP string literals.
	if ( preg_match( '/cc87_secret|cc87_initial_secret|disposable_root_password|disposable_wp_password|secret_pass_123/i', $code ) ) {
		throw new RuntimeException( 'Test-only credential literal entered runtime source: ' . $path );
	}
	$forbidden = '/\b(?:eval|create_function|base64_decode|shell_exec|exec|system|passthru|proc_open|popen|curl_exec|curl_init|wp_(?:safe_)?remote_[a-z_]+|file_get_contents|error_reporting|ini_set|header|wp_redirect|activate_plugin|deactivate_plugins|wp_update_plugins)\s*\(|\$_(?:REQUEST|GET|COOKIE|FILES)\b|\b(?:wp_redirection_items|wp_writeleash_demo_rows)\b/i';
	if ( preg_match( $forbidden, $code, $match ) ) {
		throw new RuntimeException( 'Unreviewed runtime source token ' . $match[0] . ' in ' . $path );
	}
	if ( preg_match( '/\b(?:include|require|include_once|require_once)\s*\(\s*\$/i', $code ) ) {
		throw new RuntimeException( 'Dynamic include in ' . $path );
	}
	if ( preg_match( '/\b(?:class|interface|trait)\s+(?:wp_|__|_)[a-z_]/i', $code ) ||
		( ! str_contains( $path, '/includes/' ) && preg_match( '/\bfunction\s+(?:wp_|__|_)[a-z_]/i', $code ) ) ) {
		throw new RuntimeException( 'Reserved production identifier in ' . $path );
	}
	if ( false !== strpos( $code, '$_POST' ) && basename( $path ) !== 'class-admin-page.php' ) {
		throw new RuntimeException( 'Unreviewed POST handler in ' . $path );
	}
}
if ( 1 !== $headers || $php_count < 20 ||
	! preg_match( '/\* Plugin Name: WriteLeash\s*$/m', file_get_contents( $root . '/writeleash.php' ) ) ||
	! preg_match( '/\* Text Domain: writeleash\s*$/m', file_get_contents( $root . '/writeleash.php' ) ) ) {
	throw new RuntimeException( 'Plugin identity, source count or duplicate headers' );
}

// #63/#64 staged release files: license, readme and the public operator guide
// must be present; internal development documents must not be staged.
foreach ( array( 'readme.txt', 'LICENSE', 'operator-setup.txt' ) as $required ) {
	if ( ! is_file( $root . '/' . $required ) ) {
		throw new RuntimeException( 'Required staged release file missing: ' . $required );
	}
}
// The monorepo source root still carries its internal documents; a staged
// distribution root does not. Reject internal documents only in the
// distribution-shaped root so this audit can run against both.
if ( ! is_file( $root . '/README.md' ) ) {
	foreach ( array( 'LICENSE-AUDIT.md', 'RELEASE-MATRIX.md', 'THREAT-MODEL.md', 'PROVISIONING.md' ) as $excluded ) {
		if ( is_file( $root . '/' . $excluded ) ) {
			throw new RuntimeException( 'Internal document staged into the distribution: ' . $excluded );
		}
	}
}
if ( 'edaef632cbb643e4e7a221717a6c441a4c1a7c918e6e4d56debc3d8739b233f6' !== hash_file( 'sha256', $root . '/LICENSE' ) ) {
	throw new RuntimeException( 'Staged LICENSE is not the reviewed verbatim GNU GPLv2 text' );
}

// #96 active-brand audit: inside the WordPress product tree every remaining
// `commitcap` occurrence must belong to the #97-deferred low-level database
// object family (`commitcap_v01_*`). The only other allowed occurrences are the
// finite, exact-name pre-release development cleanup literals in uninstall.php.
// Internal markdown transition notes are audited by the later global pass.
$legacy_uninstall_literals = array(
	'commitcap_version',
	'commitcap_certified_operation_state',
	'commitcap_last_certified_outcome',
	'commitcap_operation_budget_redirection_5_5_2_bulk_disable',
);
$brand_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $brand_files as $file ) {
	if ( ! $file->isFile() || ! preg_match( '/\.(?:php|txt)$/i', $file->getFilename() ) ) {
		continue;
	}
	$content = (string) file_get_contents( $file->getPathname() );
	$stripped = preg_replace( '/commitcap_v01_[a-z0-9_]*/i', '', $content );
	if ( null === $stripped ) {
		throw new RuntimeException( 'Brand audit failed to scan: ' . $file->getPathname() );
	}
	if ( 'uninstall.php' === $file->getFilename() ) {
		$stripped = str_replace( $legacy_uninstall_literals, '', $stripped );
	}
	if ( preg_match( '/commitcap/i', $stripped ) ) {
		throw new RuntimeException( '#96 stale CommitCap brand outside the #97-deferred commitcap_v01_* family: ' . $file->getPathname() );
	}
}
echo "#96 active-brand audit: only #97-deferred commitcap_v01_* names and the finite uninstall cleanup literals remain; no stale runtime/package brand PASS\n";
echo "#62/#63 source audit: $php_count guarded PHP files; one public header; GPLv2 LICENSE; readme/operator guide staged; no internal docs; no forbidden network/updater/dynamic execution/secret fixture/table literals/compiled assets PASS\n";
