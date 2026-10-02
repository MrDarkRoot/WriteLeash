<?php
// #63/#121 PUBLIC dependency semantics on supported WP, plus unsupported-Core
// refusal before any repository-only historical metadata/legacy overlay. Phases are
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
$woo_version = '11.1.2';
$assert = static function ( $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( '#63 dependency: ' . $message );
	}
};
$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
$assert( '7.0' === $data['RequiresWP'], 'public WP minimum was shimmed or reverted' );
$assert( false === strpos( file_get_contents( WP_PLUGIN_DIR . '/' . $plugin ), 'HISTORICAL TEST ONLY' ), 'historical shim in public fixture' );
global $wp_version, $wpdb;
if ( 'minimum' !== $phase ) {
	$assert( in_array( $wp_version, array( '7.0.1', '7.1.2' ), true ), 'public dependency proof must run on an exact supported WP point' );
}
$snapshot = static function () use ( $wpdb ): string {
	$tables = $wpdb->get_col( 'SHOW TABLES' );
	sort( $tables );
	$owned = array();
	foreach ( $tables as $table ) {
		if ( false !== strpos( $table, 'writeleash' ) ) {
			$owned[ $table ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
		}
	}
	return hash( 'sha256', serialize( array(
		$owned,
		$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY ID', $wpdb->posts ), ARRAY_A ),
		$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY meta_id', $wpdb->postmeta ), ARRAY_A ),
		$wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, '%writeleash%' ), ARRAY_A ),
	) ) );
};

switch ( $phase ) {
	case 'minimum':
		$assert( '6.8.3' === $wp_version, 'unsupported fixture must stay exact WP 6.8.3' );
		$assert( ! is_plugin_active( $plugin ), 'public plugin already active on unsupported WP' );
		// Woo is absent: seed stored product facts through Core for a non-vacuous
		// zero-product-mutation assertion, without loading the public plugin.
		register_post_type( 'product', array( 'public' => true ) );
		$product_id = wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => '#121 refusal sentinel' ), true );
		$assert( ! is_wp_error( $product_id ) && $product_id > 0, 'could not seed refusal product sentinel' );
		update_post_meta( $product_id, '_regular_price', '123.45' );
		update_post_meta( $product_id, '_price', '123.45' );
		$before = $snapshot();
		$result = activate_plugin( $plugin );
		$assert( $result instanceof WP_Error && 'plugin_wp_incompatible' === $result->get_error_code(), 'WP minimum did not block activation first: ' . wp_json_encode( $result ) );
		$assert( false !== strpos( $result->get_error_message(), '7.0' ), 'minimum refusal does not name WP 7.0' );
		$assert( ! is_plugin_active( $plugin ), 'public plugin activated normally on WP 6.8.3' );
		$assert( $before === $snapshot(), 'Core refusal caused durable/product mutation' );
		$assert( ! defined( 'WRITELEASH_VERSION' ), 'public plugin loaded despite minimum refusal' );
		wp_delete_post( $product_id, true );
		echo "#121 WP 6.8.3 PUBLIC ACTIVATION: EXPECTED CORE REFUSAL (minimum 7.0 first), inactive, zero WriteLeash durable/product mutation: PASS\n";
		break;

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
		// Exact public Woo build active: Core's slug dependency is satisfied.
		$assert( defined( 'WC_VERSION' ) && $woo_version === WC_VERSION, 'WooCommerce ' . $woo_version . ' not active' );
		$assert( false === WP_Plugin_Dependencies::has_unmet_dependencies( $plugin ), 'Core still reports unmet dependency' );
		$names = WP_Plugin_Dependencies::get_dependency_names( $plugin );
		$assert( isset( $names['woocommerce'] ), 'Core did not parse the WooCommerce dependency: ' . wp_json_encode( $names ) );
		$assert( false !== WP_Plugin_Dependencies::get_dependency_filepath( 'woocommerce' ), 'Core did not resolve the WooCommerce slug' );
		$result = activate_plugin( $plugin );
		$assert( ! is_wp_error( $result ) && is_plugin_active( $plugin ), 'public activation failed with supported WP/Woo: ' . wp_json_encode( $result ) );
		echo "#63 public dependency installed: WooCommerce 11.1.2 active, Core dependency satisfied and public WriteLeash activation succeeds: PASS\n";
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
