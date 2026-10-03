<?php
// Repository-only observer, invoked by WP-CLI eval-file on a ZIP-installed site.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$fail = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$plugins = get_plugins();
$basename = 'writeleash/writeleash.php';
$fail( isset( $plugins[ $basename ] ), 'ZIP basename missing' );
$headers = array_filter( $plugins, static fn( $p ) => 'WriteLeash' === $p['Name'] );
$fail( 1 === count( $headers ), 'duplicate WriteLeash header' );
$fail( '0.1.0' === $plugins[ $basename ]['Version'], 'wrong installed version' );
$fail( 'woocommerce' === $plugins[ $basename ]['RequiresPlugins'], 'wrong dependency metadata' );
WP_Plugin_Dependencies::initialize();
$fail( array( 'woocommerce' ) === WP_Plugin_Dependencies::get_dependencies( $basename ), 'Core dependency not recognized' );
global $wpdb;
if ( '6.8.3' === get_bloginfo( 'version' ) ) {
    $fail( ! is_plugin_active( $basename ), 'unsupported plugin active' );
    $error = validate_plugin_requirements( $basename );
    $fail( is_wp_error( $error ) && 'plugin_wp_incompatible' === $error->get_error_code(), 'Core minimum WP refusal missing' );
    $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'writeleash_' ) . '%' ) );
    $fail( ! $tables, 'unexpected durable WriteLeash tables' );
    $fail( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" ), 'unexpected product mutation' );
    echo "WP 6.8.3: Core plugin_wp_incompatible; inactive; no WriteLeash tables/products PASS\n";
} else {
    $fail( '7.1.2' === get_bloginfo( 'version' ), 'wrong supported core' );
    $fail( defined( 'WC_VERSION' ) && '11.1.2' === WC_VERSION, 'wrong Woo version' );
    $fail( is_plugin_active( $basename ), 'activation missing' );
    $fail( ! is_plugin_active( 'redirection/redirection.php' ), 'unexpected Redirection' );
    wp_set_current_user( get_user_by( 'login', 'package_admin' )->ID );
    global $submenu;
    $submenu = array();
    WriteLeash\Free_Admin::menu();
    $entries = $submenu['edit.php?post_type=product'] ?? array();
    $fail( (bool) array_filter( $entries, static fn( $entry ) => 'writeleash-bulk-prices' === $entry[2] ), 'Products menu missing' );
    ob_start();
    WriteLeash\Free_Admin::render();
    $page = ob_get_clean();
    $fail( false !== strpos( $page, 'WriteLeash Bulk Prices' ), 'page did not render' );
    echo "WP 7.1.2 + Woo 11.1.2: basename/header/dependency/activation/Products Bulk Prices PASS\n";
}
