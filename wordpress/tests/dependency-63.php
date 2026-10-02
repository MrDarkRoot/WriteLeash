<?php
// #63/#111 dependency semantics on the pinned WordPress fixture. Phases are
// driven by CC63_PHASE from in-container.sh; this uses normal Core behavior
// and never reimplements dependency management.
//
// The default Free product depends on WooCommerce, not Redirection: fresh
// WP + Woo + WriteLeash (Redirection absent) must activate and reach the
// Free workflow. Redirection regression coverage lives in the adapter suite.
if ( ! function_exists( 'activate_plugin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$phase = getenv( 'CC63_PHASE' );
// Core parses Requires Plugins during initialize(); in WP-CLI that is not run
// automatically before our assertions.
WP_Plugin_Dependencies::initialize();
$plugin = 'writeleash/writeleash.php';
$dependency = 'woocommerce/woocommerce.php';
// Expected Woo build for this leg: 9.9.7 on the WordPress 6.8.3 Guard
// baseline (activation shim, not a Free product pin). Free E2E legs pin
// WooCommerce 11.1.2 on WordPress 7.1.2 and assert it themselves.
$woo_version = getenv( 'CC63_WOO_VERSION' );
if ( ! is_string( $woo_version ) || '' === $woo_version ) {
	$woo_version = '9.9.7';
}
$assert = static function ( $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( '#63 dependency: ' . $message );
	}
};

switch ( $phase ) {
	case 'block':
		// WooCommerce is not installed yet: normal Core activation must refuse.
		$assert( ! is_plugin_active( $plugin ), 'plugin unexpectedly active without dependency' );
		$assert( true === WP_Plugin_Dependencies::has_unmet_dependencies( $plugin ), 'Core did not report unmet dependency' );
		$result = activate_plugin( $plugin );
		$assert( $result instanceof WP_Error, 'Core activation was not blocked: ' . wp_json_encode( $result ) );
		$assert( false !== stripos( $result->get_error_message(), 'woocommerce' ), 'Core refusal does not name WooCommerce: ' . $result->get_error_message() );
		$assert( ! is_plugin_active( $plugin ), 'plugin became active despite unmet dependency' );
		echo "#63 dependency block: Core refuses WriteLeash activation while WooCommerce is missing: PASS\n";
		break;

	case 'installed':
		// The leg's WooCommerce build installed and active: the Core slug
		// dependency is satisfied. Version mechanics are slug-based; the
		// Free product pin is asserted by the 7.1.2 E2E legs, not here.
		$assert( defined( 'WC_VERSION' ) && $woo_version === WC_VERSION, 'WooCommerce ' . $woo_version . ' not active' );
		$assert( false === WP_Plugin_Dependencies::has_unmet_dependencies( $plugin ), 'Core still reports unmet dependency' );
		$names = WP_Plugin_Dependencies::get_dependency_names( $plugin );
		$assert( isset( $names['woocommerce'] ), 'Core did not parse the WooCommerce dependency: ' . wp_json_encode( $names ) );
		$assert( false !== WP_Plugin_Dependencies::get_dependency_filepath( 'woocommerce' ), 'Core did not resolve the WooCommerce slug' );
		echo "#63 dependency installed: Core resolves Requires Plugins: woocommerce: PASS\n";
		break;

	case 'dependents':
		// WriteLeash active: this is the predicate the plugin list UI uses to
		// withhold normal deactivation/deletion of its active dependency.
		$assert( is_plugin_active( $plugin ), 'WriteLeash not active' );
		$assert( true === WP_Plugin_Dependencies::has_active_dependents( $dependency ), 'Core did not report WriteLeash as an active dependent' );
		$assert( in_array( $plugin, WP_Plugin_Dependencies::get_dependents( 'woocommerce' ), true ), 'WriteLeash missing from dependency graph' );
		echo "#63 dependency active: Core withholds normal dependency deactivation while WriteLeash is active: PASS\n";
		break;

	case 'lost':
		// Lower-level/WP-CLI deactivation succeeded: the Free runtime must
		// still fail closed with zero product mutation. This mirrors real
		// deployment loss (filesystem, automation) rather than the UI path.
		$assert( is_plugin_active( $plugin ), 'WriteLeash was deactivated along with the dependency' );
		$assert( ! is_plugin_active( $dependency ), 'WooCommerce still active in lost phase' );
		$assert( ! defined( 'WC_VERSION' ) && ! function_exists( 'wc_get_product' ), 'WooCommerce API still available after loss' );
		try {
			\WriteLeash\Price_Store_Context::current();
			$assert( false, 'store context succeeded without WooCommerce' );
		} catch ( \WriteLeash\Price_Validation_Error $error ) {
			$assert( 'woocommerce_unavailable' === $error->reason(), 'dependency loss was not classified unavailable: ' . $error->reason() );
		}
		echo "#63 dependency lost: Free planning fails closed and zero product mutation: PASS\n";
		break;

	case 'released':
		// WriteLeash inactive: the dependency no longer has active dependents.
		$assert( ! is_plugin_active( $plugin ), 'WriteLeash still active' );
		$assert( is_plugin_active( $dependency ), 'WooCommerce not active' );
		$assert( false === WP_Plugin_Dependencies::has_active_dependents( $dependency ), 'Core still reports an active dependent' );
		echo "#63 dependency released: no active dependents once WriteLeash is inactive: PASS\n";
		break;

	default:
		throw new RuntimeException( '#63 dependency: unknown phase: ' . (string) $phase );
}
