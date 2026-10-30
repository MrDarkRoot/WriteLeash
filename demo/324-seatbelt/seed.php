<?php
/**
 * Seed fictional catalog data for the disposable #324 demo.
 *
 * Writes one published simple product through WooCommerce CRUD so post meta
 * and the lookup row agree. Creates two shop managers. Prints nothing.
 * The caller passes WL324_SEED_OUT and reads that JSON file.
 */
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "seed.php must run under wp eval-file\n" );
	exit( 1 );
}

$out = getenv( 'WL324_SEED_OUT' );
if ( ! is_string( $out ) || '' === $out ) {
	fwrite( STDERR, "WL324_SEED_OUT is required\n" );
	exit( 1 );
}

if ( ! function_exists( 'wc_get_product' ) ) {
	fwrite( STDERR, "WooCommerce is not loaded\n" );
	exit( 1 );
}

/**
 * @param string $login
 * @param string $email
 * @param string $name
 * @param string $role
 */
function wl324_user( string $login, string $email, string $name, string $role ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing instanceof WP_User ) {
		return (int) $existing->ID;
	}
	$password = wp_generate_password( 24, false );
	$id       = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => $password,
			'user_email'   => $email,
			'display_name' => $name,
			'nickname'     => $name,
			'first_name'   => strtok( $name, ' ' ),
			'last_name'    => trim( (string) strstr( $name, ' ' ) ),
			'role'         => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, $id->get_error_message() . "\n" );
		exit( 1 );
	}
	$GLOBALS['wl324_created_passwords'][ $login ] = $password;
	return (int) $id;
}

$GLOBALS['wl324_created_passwords'] = array();

$morgan = get_user_by( 'login', 'morgan' );
if ( ! $morgan instanceof WP_User ) {
	fwrite( STDERR, "morgan must already exist from wp core install\n" );
	exit( 1 );
}
wp_update_user(
	array(
		'ID'           => $morgan->ID,
		'display_name' => 'Morgan Merchant',
		'nickname'     => 'Morgan Merchant',
		'first_name'   => 'Morgan',
		'last_name'    => 'Merchant',
	)
);

$casey = wl324_user( 'casey', 'casey.colleague@example.test', 'Casey Colleague', 'shop_manager' );
$riley = wl324_user( 'riley', 'riley.agent@example.test', 'Riley Agent', 'shop_manager' );

delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_coming_soon_banner_dismissed', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );

$product = new WC_Product_Simple();
$product->set_name( 'Northwind Canvas Tote' );
$product->set_status( 'publish' );
$product->set_catalog_visibility( 'visible' );
$product->set_sku( 'WL-DEMO-TOTE' );
$product->set_regular_price( '100.00' );
$product->set_description( 'Fictional demo product. Not a real catalog item.' );
$product->set_short_description( 'Fictional tote used only for this disposable demo.' );
$product_id = (int) $product->save();
if ( $product_id < 1 ) {
	fwrite( STDERR, "product save failed\n" );
	exit( 1 );
}

$fresh = wc_get_product( $product_id );
$price = $fresh instanceof WC_Product ? (string) $fresh->get_regular_price( 'edit' ) : '';

$payload = array(
	'product_id'    => $product_id,
	'sku'           => 'WL-DEMO-TOTE',
	'regular_price' => $price,
	'users'         => array(
		'morgan' => array(
			'id'    => (int) $morgan->ID,
			'login' => 'morgan',
			'name'  => 'Morgan Merchant',
		),
		'casey'  => array(
			'id'    => $casey,
			'login' => 'casey',
			'name'  => 'Casey Colleague',
		),
		'riley'  => array(
			'id'    => $riley,
			'login' => 'riley',
			'name'  => 'Riley Agent',
		),
	),
	'created_passwords' => $GLOBALS['wl324_created_passwords'],
);
file_put_contents( $out, wp_json_encode( $payload ) );
fwrite( STDOUT, "seeded product {$product_id} at {$price}\n" );
