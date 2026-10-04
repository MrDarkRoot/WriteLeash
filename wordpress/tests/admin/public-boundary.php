<?php
// #120: inspect the actual manifest-only installed plugin, never the legacy lab.
function wl120_assert( bool $condition, string $label ): void {
	if ( ! $condition ) { throw new RuntimeException( '#120 public boundary: ' . $label ); }
}
wl120_assert( is_plugin_active( 'writeleash/writeleash.php' ), 'public plugin active' );
wl120_assert( '11.1.2' === WC_VERSION, 'exact supported Woo active' );
foreach ( array( 'USER', 'PASSWORD', 'NAME', 'HOST' ) as $suffix ) {
	wl120_assert( ! defined( 'WRITELEASH_DB_' . $suffix ), 'no restricted DB config: ' . $suffix );
}
$legacy = array( 'Update_Engine', 'Guard_Error', 'Unsupported_Transaction_State', 'Budget_Denied',
	'Guard_Transaction', 'Guard_Sql', 'Guard_Monitor', 'Guard', 'Compatibility_Grants',
	'Compatibility_Doctor', 'Provisioning_Plan', 'Redirection_Bulk_Disable',
	'Redirection_Bulk_Disable_Rest', 'Certified_Operation', 'Operation_Config',
	'Certified_Operation_Status', 'Last_Outcome', 'Disposable_Demo', 'Disposable_Demo_Setup',
	'Product_Status', 'Admin_Page', 'Product_CLI' );
foreach ( $legacy as $class ) {
	wl120_assert( ! class_exists( 'WriteLeash\\' . $class, false ), 'historical class not loaded: ' . $class );
}
global $wp_filter, $menu, $submenu, $wpdb;
$allowed_callbacks = array( 'WriteLeash\\Lifecycle', 'WriteLeash\\Free_Admin', 'WriteLeash\\Product_Discovery',
	'WriteLeash\\Job_Resume_Rest', 'WriteLeash\\Undo_Rest', 'WriteLeash\\Job_Worker',
	'WriteLeash\\Undo_Worker', 'WriteLeash\\Undo_Scheduler' );
$observed = array();
foreach ( $wp_filter as $hook => $registered ) {
	foreach ( $registered->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'];
			$class = is_array( $function ) ? ( is_object( $function[0] ) ? get_class( $function[0] ) : $function[0] ) : ( is_string( $function ) ? $function : '' );
			if ( 0 === strpos( ltrim( $class, '\\' ), 'WriteLeash\\' ) ) {
				$class = ltrim( $class, '\\' );
				wl120_assert( in_array( $class, $allowed_callbacks, true ), 'unexpected WriteLeash callback: ' . $hook . ':' . $class );
				wl120_assert( ! in_array( $hook, array( 'query', 'rest_dispatch_request', 'admin_post_writeleash_action' ), true ), 'legacy interception absent' );
				wl120_assert( 'WriteLeash\\Product_Discovery' !== $class || ( 'wp_ajax_writeleash_free_discovery' === $hook && 'ajax' === $function[1] ), 'discovery registers only the reviewed authenticated read callback' );
				$observed[] = $hook . ':' . $class;
			}
		}
	}
}
wl120_assert( false === has_action( 'admin_post_writeleash_action' ), 'legacy provisioning/Admin action absent' );
foreach ( array( 'preview', 'approve', 'resume', 'undo' ) as $action ) {
	wl120_assert( false !== has_action( 'admin_post_writeleash_free_' . $action ), 'Free Admin ' . $action . ' booted' );
}
foreach ( array( 'writeleash_process_job', 'writeleash_process_undo', 'writeleash_retention_purge' ) as $hook ) {
	wl120_assert( false !== has_action( $hook ), 'owned scheduler callback ' . $hook );
}
wl120_assert( false === has_action( 'wp_ajax_nopriv_writeleash_free_discovery' ), 'no unauthenticated discovery callback' );
wp_set_current_user( 1 );
// WP-CLI has no wp-admin menu bootstrap; initialize the normal Admin globals
// before invoking the actual registered admin_menu callbacks.
$menu = is_array( $menu ) ? $menu : array();
$submenu = is_array( $submenu ) ? $submenu : array();
do_action( 'admin_menu' );
$bulk_prices = false;
foreach ( (array) $submenu as $parent => $pages ) {
	foreach ( $pages as $page ) {
		wl120_assert( 'writeleash' !== $page[2] && false === strpos( (string) $page[0], 'WriteLeash Advanced' ), 'Tools Advanced absent' );
		if ( 'writeleash-bulk-prices' === $page[2] ) {
			wl120_assert( 'edit.php?post_type=product' === $parent && 'Bulk Prices' === $page[0], 'Woo Products placement' );
			$bulk_prices = true;
		}
	}
}
wl120_assert( $bulk_prices, 'Products → Bulk Prices exists' );
$routes = rest_get_server()->get_routes();
$public_routes = array();
foreach ( $routes as $route => $handlers ) {
	if ( 0 !== strpos( $route, '/writeleash/v1/' ) ) { continue; }
	wl120_assert( 1 === preg_match( '#^/writeleash/v1/(jobs/.+/resume|undo/.+/start)$#', $route ), 'only Free REST endpoints: ' . $route );
	$public_routes[] = $route;
}
wl120_assert( 2 === count( $public_routes ), 'both Free resume and Undo routes registered' );
wl120_assert( ! isset( WP_CLI::get_root_command()->get_subcommands()['writeleash'] ), 'legacy CLI namespace absent' );
wl120_assert( ! is_file( WP_PLUGIN_DIR . '/writeleash/operator-setup.txt' ), 'operator guide absent' );
wl120_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()' ), 'no trigger setup' );
wl120_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()' ), 'no routine setup' );
if ( ! defined( 'REDIRECTION_VERSION' ) ) {
	wl120_assert( ! class_exists( 'Red_Item', false ), 'Redirection absent from clean fixture' );
}
echo '#120 manifest-only activation/menus/hooks/routes/CLI boundary: PASS; Woo=' . WC_VERSION . '; Redirection=' . ( defined( 'REDIRECTION_VERSION' ) ? REDIRECTION_VERSION : 'ABSENT' ) . "\n";
echo '#120 registered public callbacks: ' . wp_json_encode( $observed ) . "\n";
echo '#120 registered public routes: ' . wp_json_encode( $public_routes ) . "\n";
