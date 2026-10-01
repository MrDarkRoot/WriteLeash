<?php
// #111 Admin entry audit: the Free Admin workflow plans through #107 and
// orchestrates #109/#110, but never recomputes prices, never writes price
// storage directly, and holds no superglobal variable literal (input enters
// through filter_input into explicit process_* arrays).
$root = $argv[1] ?? '';
if ( ! is_dir( $root . '/includes/free' ) || ! is_file( $root . '/writeleash.php' ) ) {
	throw new RuntimeException( 'Not the installed writeleash source root' );
}
$admin = $root . '/includes/free/class-free-admin.php';
if ( ! is_file( $admin ) ) {
	throw new RuntimeException( 'Free Admin implementation missing' );
}
$source = (string) file_get_contents( $admin );
$fail = static function ( string $message ): void {
	throw new RuntimeException( '#111 admin audit: ' . $message );
};
foreach ( array( '$_POST', '$_GET', '$_REQUEST', '$_COOKIE', '$_FILES' ) as $literal ) {
	if ( str_contains( $source, $literal ) ) {
		$fail( 'superglobal literal in Free Admin: ' . $literal );
	}
}
foreach ( array(
	'Woo_Price_Planner::preview',
	'Job_Repository::create_from_plan',
	'Job_Repository::approve',
	'Job_Worker::queue_job',
	'Job_Worker::run',
	'Job_Repository::observe',
	'Undo_Repository::initiate',
	'Undo_Repository::history_jobs',
	'Undo_Repository::history_job',
	'Undo_Repository::history_items',
	'undo_eligible',
	'Undo_Worker::run',
	'edit.php?post_type=product',
	'Undo restores eligible',
	'does not reverse',
) as $required ) {
	if ( ! str_contains( $source, $required ) ) {
		$fail( 'Free Admin missing required integration: ' . $required );
	}
}
foreach ( array(
	'Price_Calculator',
	'Price_Store_Context::current',
	'Product_Price_Selector',
	'WP_Query',
	'update_post_meta',
	'delete_post_meta',
	'add_post_meta',
	'set_regular_price',
	'wp_update_post',
	'wp_insert_post',
) as $forbidden ) {
	if ( str_contains( $source, $forbidden ) ) {
		$fail( 'Free Admin must not plan arithmetic, query the catalog or write price storage: ' . $forbidden );
	}
}
// The Admin layer reads the Woo availability facts directly; store currency
// and settings are validated by the frozen planner on every preview, and the
// Admin layer must not open a second context read.
$manifest = dirname( $root, 3 ) . '/release/writeleash-distribution-files.txt';
if ( ! is_file( $manifest ) ) {
	$manifest = '/opt/release/writeleash-distribution-files.txt';
}
if ( is_file( $manifest ) && ! str_contains( (string) file_get_contents( $manifest ), 'includes/free/class-free-admin.php' ) ) {
	$fail( 'Free Admin missing from the distribution allowlist' );
}
// Blocker 1: history reads never install schema. The History section of the
// Undo repository must contain no ensure_schema/install call; installation
// stays on explicit state-changing paths (initiate/import/approval).
$repository = (string) file_get_contents( $root . '/includes/free/class-undo-repository.php' );
$history_start = strpos( $repository, 'History: backend view models' );
$history_end = strpos( $repository, 'Retention: bounded, terminal-only' );
if ( false === $history_start || false === $history_end || $history_end <= $history_start ) {
	$fail( 'history section markers missing' );
}
$history_section = substr( $repository, $history_start, $history_end - $history_start );
foreach ( array( 'ensure_schema', 'Schema::install', 'dbDelta', 'update_option' ) as $token ) {
	if ( str_contains( $history_section, $token ) ) {
		$fail( 'history read path can mutate schema/options: ' . $token );
	}
}
// Blocker 2: the jobs history query is actor-scoped before pagination.
if ( ! str_contains( $repository, 'history_jobs( int $offset = 0, int $limit = 20, int $viewer_id' ) ) {
	$fail( 'history_jobs is not viewer-scoped' );
}
foreach ( array( 'creator_id=%d OR approver_id=%d', "'total' =>", "'next_offset' =>" ) as $token ) {
	if ( ! str_contains( $history_section, $token ) ) {
		$fail( 'scoped history pagination missing: ' . $token );
	}
}
$plugin_boot = (string) file_get_contents( $root . '/includes/class-plugin.php' );
if ( ! str_contains( $plugin_boot, 'Free_Admin::boot' ) ) {
	$fail( 'Free Admin not booted from the plugin' );
}
$main = (string) file_get_contents( $root . '/writeleash.php' );
if ( ! str_contains( $main, "includes/free/class-free-admin.php" ) ) {
	$fail( 'Free Admin not required from the main plugin file' );
}
if ( ! preg_match( '/^\s*\*\s*Requires Plugins:\s*woocommerce\s*$/m', $main ) ) {
	$fail( 'default Free product must depend on WooCommerce, not Redirection' );
}
echo "#111 admin entry audit: planner-owned planning, repository-owned truth, no superglobal, no price write, Woo-first entry PASS\n";
