<?php
/**
 * Fictional catalog and the restricted shop-manager actor.
 * The generated user password is written only to WL327_SECRET_OUT.
 */
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "seed.php must run under wp eval-file\n" );
	exit( 1 );
}

$secret_out = getenv( 'WL327_SECRET_OUT' );
$public_out = getenv( 'WL327_PUBLIC_OUT' );
if ( ! is_string( $secret_out ) || '' === $secret_out || ! is_string( $public_out ) || '' === $public_out ) {
	fwrite( STDERR, "WL327_SECRET_OUT and WL327_PUBLIC_OUT are required\n" );
	exit( 1 );
}

$morgan = get_user_by( 'login', 'morgan' );
if ( ! $morgan ) {
	fwrite( STDERR, "morgan is missing\n" );
	exit( 1 );
}
wp_update_user(
	array(
		'ID'           => $morgan->ID,
		'display_name' => 'Morgan Merchant',
		'first_name'   => 'Morgan',
		'last_name'    => 'Merchant',
	)
);

$created_password = null;
$riley            = get_user_by( 'login', 'riley' );
if ( ! $riley ) {
	$created_password = wp_generate_password( 24, false );
	$user_id          = wp_insert_user(
		array(
			'user_login'   => 'riley',
			'user_pass'    => $created_password,
			'user_email'   => 'riley.agent@example.test',
			'role'         => 'shop_manager',
			'display_name' => 'Riley Agent',
			'first_name'   => 'Riley',
			'last_name'    => 'Agent',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		fwrite( STDERR, $user_id->get_error_message() . "\n" );
		exit( 1 );
	}
	$riley = get_user_by( 'id', $user_id );
}
if ( ! $riley || ! user_can( $riley, 'manage_woocommerce' ) || ! user_can( $riley, 'edit_products' ) ) {
	fwrite( STDERR, "riley cannot manage products\n" );
	exit( 1 );
}

delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_coming_soon_banner_dismissed', 'yes' );
update_option( 'woocommerce_onboarding_profile', array( 'completed' => true ), false );

/**
 * @param string $name
 * @param string $sku
 * @param string $price
 */
function wl327_product( $name, $sku, $price ) {
	$existing = wc_get_product_id_by_sku( $sku );
	if ( $existing ) {
		$product = wc_get_product( $existing );
	} else {
		$product = new WC_Product_Simple();
		$product->set_sku( $sku );
	}
	if ( ! $product instanceof WC_Product ) {
		fwrite( STDERR, "could not prepare {$sku}\n" );
		exit( 1 );
	}
	$product->set_name( $name );
	$product->set_regular_price( $price );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_description( 'Fictional demo product. Not a real catalog item.' );
	$product->set_short_description( 'Fictional product used only for this disposable demo.' );
	$id = $product->save();
	if ( ! $id ) {
		fwrite( STDERR, "could not save {$sku}\n" );
		exit( 1 );
	}
	$saved = wc_get_product( $id );
	return array(
		'id' => (int) $id,
		'sku' => $sku,
		'name' => $name,
		'regular_price' => $saved instanceof WC_Product ? (string) $saved->get_regular_price() : '',
	);
}

$permitted = wl327_product( 'Northwind Mug', 'WL327-MUG', '40.00' );
$denied    = wl327_product( 'Harborline Notebook', 'WL327-NOTE', '25.00' );
if ( '40.00' !== $permitted['regular_price'] || '25.00' !== $denied['regular_price'] ) {
	fwrite( STDERR, "seed prices were not stored\n" );
	exit( 1 );
}

$public = array(
	'issuer' => array(
		'id' => (int) $morgan->ID,
		'login' => 'morgan',
		'display_name' => 'Morgan Merchant',
	),
	'actor' => array(
		'id' => (int) $riley->ID,
		'login' => 'riley',
		'display_name' => 'Riley Agent',
	),
	'products' => array(
		'permitted' => $permitted,
		'denied' => $denied,
	),
);
$secret = array(
	'riley_password' => $created_password,
);
if ( false === file_put_contents( $public_out, wp_json_encode( $public ) ) ) {
	fwrite( STDERR, "could not write the public seed file\n" );
	exit( 1 );
}
if ( false === file_put_contents( $secret_out, wp_json_encode( $secret ) ) ) {
	fwrite( STDERR, "could not write the secret seed file\n" );
	exit( 1 );
}
fwrite( STDOUT, "seeded actor {$riley->ID} products {$permitted['id']} {$denied['id']}\n" );
