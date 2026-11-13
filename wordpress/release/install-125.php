<?php
// Normal Plugins-screen activation, using the installed ZIP and actual HTTP session.
require __DIR__ . '/http-125.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$wl112_cookies = array();
wl112_login();
$page = wl112_get( getenv( 'WL125_URL' ) . '/wp-admin/plugins.php' );
$dom = new DOMDocument(); libxml_use_internal_errors( true ); $dom->loadHTML( $page['body'] ); libxml_clear_errors();
$target = null;
foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
    $url = $a->getAttribute( 'href' ); $query = array(); parse_str( parse_url( $url, PHP_URL_QUERY ) ?: '', $query );
    if ( ( $query['action'] ?? '' ) === 'activate' && ( $query['plugin'] ?? '' ) === 'writeleash/writeleash.php' ) { $target = $url; break; }
}
wl112_assert( is_string( $target ), 'normal Core activation link missing' );
if ( 0 === strpos( $target, '/' ) ) { $target = getenv( 'WL125_URL' ) . $target; } elseif ( ! preg_match( '/\Ahttps?:\/\//', $target ) ) { $target = admin_url( $target ); }
$response = wl112_http( 'GET', $target );
wl112_assert( in_array( $response['code'], array( 302, 303 ), true ), 'Core activation failed' );
$page = wl112_get( getenv( 'WL125_URL' ) . '/wp-admin/edit.php?post_type=product&page=writeleash-bulk-prices' );
wl112_assert( false !== strpos( $page['body'], 'WriteLeash Bulk Prices' ), 'Products entry missing' );
$plugins = get_plugins();
wl112_assert( isset( $plugins['writeleash/writeleash.php'] ), 'basename drift' );
wl112_assert( 'woocommerce' === $plugins['writeleash/writeleash.php']['RequiresPlugins'], 'Woo dependency drift' );
WP_Plugin_Dependencies::initialize();
wl112_assert( array( 'woocommerce' ) === WP_Plugin_Dependencies::get_dependencies( 'writeleash/writeleash.php' ), 'Core dependency recognition' );
echo "Normal ZIP install/Core Plugins activation/Products entry/basename/Woo dependency PASS\n";
