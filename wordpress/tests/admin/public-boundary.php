<?php
// #120: inspect the actual manifest-only installed plugin, never the legacy lab.
function wl120_assert( bool $condition, string $label ): void {
	if ( ! $condition ) { throw new RuntimeException( '#120 public boundary: ' . $label ); }
}
wl120_assert( is_plugin_active( 'writeleash/writeleash.php' ), 'public plugin active' );
wl120_assert( WriteLeash\Free_Support_Contract::woo_supported( WC_VERSION ), 'supported-range Woo active' );
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
	'WriteLeash\\Job_Resume_Rest', 'WriteLeash\\Agent_Jobs_Rest', 'WriteLeash\\Undo_Rest', 'WriteLeash\\Job_Worker',
	'WriteLeash\\Undo_Worker', 'WriteLeash\\Undo_Scheduler', 'WriteLeash\\Perimeter_Guard',
	'WriteLeash\\Perimeter_Audit_Log', 'WriteLeash\\Grant_Notice', 'WriteLeash\\Grant_Admin' );
$observed = array();
$guard_hooks = array();
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
				if ( 'WriteLeash\\Perimeter_Guard' === $class ) { $guard_hooks[ $hook ] = true; }
			}
		}
	}
}
$guard_hooks = array_keys( $guard_hooks );
sort( $guard_hooks );
$reviewed_guard_hooks = array( 'add_post_metadata', 'delete_post_metadata', 'pre_delete_post', 'pre_trash_post',
	'rest_pre_dispatch', 'update_post_metadata', 'woocommerce_before_product_object_save',
	'woocommerce_before_product_variation_object_save', 'woocommerce_product_before_set_stock',
	'woocommerce_update_product_stock_query', 'woocommerce_variation_before_set_stock' );
sort( $reviewed_guard_hooks );
wl120_assert( $reviewed_guard_hooks === $guard_hooks, 'Perimeter Guard intercepts only the reviewed hooks: ' . implode( ', ', $guard_hooks ) );
// #307 follow-up: the audit logger listens only to the denial event; the
// kill-switch notice only to the standard admin_notices hook. Exact
// callbacks, so an unrelated listener or a class-only boot cannot pass.
wl120_assert( false !== has_action( 'writeleash_perimeter_denied', array( 'WriteLeash\\Perimeter_Audit_Log', 'record' ) ), 'audit logger registered its record callback' );
wl120_assert( false !== has_action( 'admin_notices', array( 'WriteLeash\\Grant_Notice', 'render' ) ), 'kill-switch notice registered its render callback' );
wl120_assert( false === has_action( 'admin_post_writeleash_action' ), 'legacy provisioning/Admin action absent' );
foreach ( array( 'preview', 'approve', 'resume', 'undo' ) as $action ) {
	wl120_assert( false !== has_action( 'admin_post_writeleash_free_' . $action ), 'Free Admin ' . $action . ' booted' );
}
// #309/#308: the grant surface registers exactly the six mutation actions;
// listing is a page render, never an admin_post action.
foreach ( array( 'create', 'revoke', 'remove', 'disable', 'enable', 'revoke_all' ) as $action ) {
	wl120_assert( false !== has_action( 'admin_post_writeleash_grant_' . $action ), 'Grant Admin ' . $action . ' booted' );
}
wl120_assert( false === has_action( 'admin_post_writeleash_grant_list' ), 'grant listing is a page render, not an admin_post action' );
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
$grant_admin_page = false;
foreach ( (array) $submenu as $parent => $pages ) {
	foreach ( $pages as $page ) {
		wl120_assert( 'writeleash' !== $page[2] && false === strpos( (string) $page[0], 'WriteLeash Advanced' ), 'Tools Advanced absent' );
		if ( 'writeleash-bulk-prices' === $page[2] ) {
			wl120_assert( 'edit.php?post_type=product' === $parent && 'Bulk Prices' === $page[0], 'Woo Products placement' );
			$bulk_prices = true;
		}
		if ( 'writeleash-grants' === $page[2] ) {
			wl120_assert( 'edit.php?post_type=product' === $parent && 'Grants' === $page[0], 'Products → Grants placement' );
			$grant_admin_page = true;
		}
	}
}
wl120_assert( $bulk_prices, 'Products → Bulk Prices exists' );
wl120_assert( $grant_admin_page, 'Products → Grants exists' );
$routes = rest_get_server()->get_routes();
$public_routes = array();
foreach ( $routes as $route => $handlers ) {
	if ( 0 !== strpos( $route, '/writeleash/v1/' ) ) { continue; }
	wl120_assert( 1 === preg_match( '#^/writeleash/v1/(jobs/.+/resume|undo/.+/start|grants/.+/jobs)$#', $route ), 'only Free REST endpoints: ' . $route );
	$public_routes[] = $route;
}
wl120_assert( 3 === count( $public_routes ), 'resume, Undo, and grant submission routes registered' );
// #309: the grant CLI namespace is registered in the runtime boot; the
// legacy Product_CLI subcommands stay absent from it.
$writeleash_cli = WP_CLI::get_root_command()->get_subcommands()['writeleash'] ?? null;
wl120_assert( $writeleash_cli instanceof \WP_CLI\Dispatcher\CompositeCommand, 'grant CLI namespace registered' );
$writeleash_cli_subcommands = $writeleash_cli->get_subcommands();
wl120_assert( isset( $writeleash_cli_subcommands['grant'] ), 'grant subcommand group registered' );
$grant_cli = $writeleash_cli_subcommands['grant'];
wl120_assert( $grant_cli instanceof \WP_CLI\Dispatcher\CompositeCommand, 'grant leaf is a composite command' );
$grant_cli_subcommands = $grant_cli->get_subcommands();
foreach ( array( 'list', 'create', 'revoke', 'disable', 'enable' ) as $grant_cli_command ) {
	wl120_assert( isset( $grant_cli_subcommands[ $grant_cli_command ] ), 'grant CLI subcommand registered: ' . $grant_cli_command );
}
wl120_assert( ! isset( $grant_cli_subcommands['remove'] ), 'remove restriction is not a CLI subcommand' );
foreach ( array( 'status', 'doctor', 'demo' ) as $legacy_cli ) {
	wl120_assert( ! isset( $writeleash_cli_subcommands[ $legacy_cli ] ), 'legacy CLI subcommand absent: ' . $legacy_cli );
}
wl120_assert( ! is_file( WP_PLUGIN_DIR . '/writeleash/operator-setup.txt' ), 'operator guide absent' );
wl120_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()' ), 'no trigger setup' );
wl120_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()' ), 'no routine setup' );
if ( ! defined( 'REDIRECTION_VERSION' ) ) {
	wl120_assert( ! class_exists( 'Red_Item', false ), 'Redirection absent from clean fixture' );
}
echo '#120 manifest-only activation/menus/hooks/routes/CLI boundary: PASS; Woo=' . WC_VERSION . '; Redirection=' . ( defined( 'REDIRECTION_VERSION' ) ? REDIRECTION_VERSION : 'ABSENT' ) . "\n";
echo '#120 registered public callbacks: ' . wp_json_encode( $observed ) . "\n";
echo '#120 registered public routes: ' . wp_json_encode( $public_routes ) . "\n";
