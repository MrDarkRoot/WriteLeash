<?php
// #63 dependency semantics on the pinned WordPress fixture. Phases are driven
// by CC63_PHASE from in-container.sh; this uses normal Core behavior and never
// reimplements dependency management.
if ( ! function_exists( 'activate_plugin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$phase = getenv( 'CC63_PHASE' );
// Core parses Requires Plugins during initialize(); in WP-CLI that is not run
// automatically before our assertions.
WP_Plugin_Dependencies::initialize();
$plugin = 'commitcap/commitcap.php';
$dependency = 'redirection/redirection.php';
$assert = static function ( $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( '#63 dependency: ' . $message );
	}
};

switch ( $phase ) {
	case 'block':
		// Redirection is not installed yet: normal Core activation must refuse.
		$assert( ! is_plugin_active( $plugin ), 'plugin unexpectedly active without dependency' );
		$assert( true === WP_Plugin_Dependencies::has_unmet_dependencies( $plugin ), 'Core did not report unmet dependency' );
		$result = activate_plugin( $plugin );
		$assert( $result instanceof WP_Error, 'Core activation was not blocked: ' . wp_json_encode( $result ) );
		$assert( false !== stripos( $result->get_error_message(), 'redirection' ), 'Core refusal does not name Redirection: ' . $result->get_error_message() );
		$assert( ! is_plugin_active( $plugin ), 'plugin became active despite unmet dependency' );
		echo "#63 dependency block: Core refuses CommitCap activation while Redirection is missing: PASS\n";
		break;

	case 'installed':
		// Redirection 5.5.2 installed and active: the dependency is satisfied.
		$assert( defined( 'REDIRECTION_VERSION' ) && '5.5.2' === REDIRECTION_VERSION, 'Redirection 5.5.2 not active' );
		$assert( false === WP_Plugin_Dependencies::has_unmet_dependencies( $plugin ), 'Core still reports unmet dependency' );
		$names = WP_Plugin_Dependencies::get_dependency_names( $plugin );
		$assert( isset( $names['redirection'] ), 'Core did not parse the Redirection dependency: ' . wp_json_encode( $names ) );
		$assert( false !== WP_Plugin_Dependencies::get_dependency_filepath( 'redirection' ), 'Core did not resolve the Redirection slug' );
		echo "#63 dependency installed: Core resolves Requires Plugins: redirection: PASS\n";
		break;

	case 'dependents':
		// CommitCap active: this is the predicate the plugin list UI uses to
		// withhold normal deactivation/deletion of its active dependency.
		$assert( is_plugin_active( $plugin ), 'CommitCap not active' );
		$assert( true === WP_Plugin_Dependencies::has_active_dependents( $dependency ), 'Core did not report CommitCap as an active dependent' );
		$assert( in_array( $plugin, WP_Plugin_Dependencies::get_dependents( 'redirection' ), true ), 'CommitCap missing from dependency graph' );
		echo "#63 dependency active: Core withholds normal dependency deactivation while CommitCap is active: PASS\n";
		break;

	case 'lost':
		// Lower-level/WP-CLI deactivation succeeded: the runtime must still
		// fail closed with zero target mutation. This mirrors real deployment
		// loss (filesystem, automation) rather than the UI path.
		require_once WP_PLUGIN_DIR . '/commitcap/commitcap.php';
		require_once __DIR__ . '/adapter/helpers.php';
		$assert( is_plugin_active( $plugin ), 'CommitCap was deactivated along with the dependency' );
		$assert( ! is_plugin_active( $dependency ), 'Redirection still active in lost phase' );
		$assert( ! defined( 'REDIRECTION_VERSION' ), 'Redirection constant still defined after loss' );
		$assert( null === \CommitCap\Redirection_Bulk_Disable::detected_version(), 'lost dependency reported a version' );
		$assert(
			array( 'UNKNOWN', 'redirection_missing' ) === \CommitCap\Redirection_Bulk_Disable::compatibility_status( null, false ),
			'lost dependency was not classified missing'
		);
		$host = getenv( 'CC_DB_FAMILY' );
		$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
		$root->suppress_errors( true );
		cc87_seed_bulk( $root, 2 );
		$response = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
		$route_missing = ( is_wp_error( $response ) && 'rest_no_route' === $response->get_error_code() )
			|| ( $response instanceof WP_REST_Response && 404 === $response->get_status() && 'rest_no_route' === ( $response->get_data()['code'] ?? null ) );
		$assert( $route_missing, 'owned route still dispatchable after dependency loss: ' . wp_json_encode( $response ) );
		$assert( array( 2, 0 ) === cc87_counts( $root ), 'dependency loss changed durable rows' );
		echo "#63 dependency lost: certified request cannot run and zero target mutation: PASS\n";
		break;

	case 'released':
		// CommitCap inactive: the dependency no longer has active dependents.
		$assert( ! is_plugin_active( $plugin ), 'CommitCap still active' );
		$assert( is_plugin_active( $dependency ), 'Redirection not active' );
		$assert( false === WP_Plugin_Dependencies::has_active_dependents( $dependency ), 'Core still reports an active dependent' );
		echo "#63 dependency released: no active dependents once CommitCap is inactive: PASS\n";
		break;

	default:
		throw new RuntimeException( '#63 dependency: unknown phase: ' . (string) $phase );
}
