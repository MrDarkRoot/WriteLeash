<?php
/**
 * Fictional catalog and the restricted shop-manager actor for the
 * third-party agent lab. Passwords are written only to WL324_SEED_OUT.
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

$created = array();
$riley   = get_user_by( 'login', 'riley' );
if ( ! $riley ) {
	$password = wp_generate_password( 24, false );
	$user_id  = wp_insert_user(
		array(
			'user_login'   => 'riley',
			'user_pass'    => $password,
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
	$created['riley'] = $password;
	$riley            = get_user_by( 'id', $user_id );
}

delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_coming_soon_banner_dismissed', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
// Same option AddSynapse writes when an administrator clicks "No thanks".
// The banner is not part of the product-write path. Declining it sends nothing.
update_option( 'addsynapse_assistant_usage', array( 'choice' => 'declined' ), false );

/**
 * @param string $name
 * @param string $sku
 * @param string $price
 */
function wl324_product( $name, $sku, $price ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_sku( $sku );
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
	return array(
		'id'            => (int) $id,
		'sku'           => $sku,
		'name'          => $name,
		'regular_price' => $price,
	);
}

$products = array(
	'tote'    => wl324_product( 'Northwind Canvas Tote', 'WL-AI-TOTE', '100.00' ),
	'cap'     => wl324_product( 'Harborline Wool Cap', 'WL-AI-CAP', '40.00' ),
	'lantern' => wl324_product( 'Pilot Lantern', 'WL-AI-LANTERN', '30.00' ),
);

$payload = array(
	'users'             => array(
		'morgan' => array(
			'id'    => (int) $morgan->ID,
			'login' => 'morgan',
		),
		'riley'  => array(
			'id'    => (int) $riley->ID,
			'login' => 'riley',
		),
	),
	'products'          => $products,
	'created_passwords' => $created,
);
file_put_contents( $out, wp_json_encode( $payload ) );
echo 'seeded tote ' . $products['tote']['id'] . ' cap ' . $products['cap']['id'] . ' lantern ' . $products['lantern']['id'] . "\n";
