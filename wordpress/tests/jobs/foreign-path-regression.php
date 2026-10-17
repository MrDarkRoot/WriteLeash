<?php
// #299 Gate D: checkout and WooCommerce product-admin edit still persist
// while WriteLeash is active. These paths are not intercepted. A pass is not
// a deny-before-persistence claim.
//
//   wp --path=$site eval-file wordpress/tests/jobs/foreign-path-regression.php
//
// Skipped by this file: Redis object cache, Action Scheduler, and the existing
// preview/approve/resume/undo suites.

function wl299_checkout_worker( string $spec_path ): void {
	$spec = json_decode( (string) file_get_contents( $spec_path ), true );
	$result = (string) ( $spec['result'] ?? '' );
	$id = 0;
	$reason = 'incomplete';
	register_shutdown_function( static function () use ( &$id, &$reason, $result ): void {
		if ( '' === $result || is_file( $result ) ) { return; }
		$fresh = $id > 0 ? wc_get_product( $id ) : null;
		$orders = function_exists( 'wc_get_orders' ) ? wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => array( 'processing', 'completed', 'on-hold' ) ) ) : array();
		$stock = $fresh ? (string) $fresh->get_stock_quantity() : '';
		file_put_contents( $result, wp_json_encode( array(
			'ok' => '4' === $stock && count( $orders ) >= 1,
			'product_id' => $id,
			'stock' => $stock,
			'orders' => count( $orders ),
			'reason' => $reason,
			'notices' => function_exists( 'wc_get_notices' ) ? wc_get_notices() : array(),
		) ) );
	} );
	try {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_allowed_countries', 'all' );
		update_option( 'woocommerce_ship_to_countries', 'disabled' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
		update_option( 'woocommerce_cod_settings', array(
			'enabled' => 'yes',
			'title' => 'Cash on delivery',
			'description' => '',
			'instructions' => '',
			'enable_for_methods' => array(),
			'enable_for_virtual' => 'yes',
		) );
		wp_set_current_user( 1 );
		$product = new WC_Product_Simple();
		$product->set_name( 'WL299-checkout-' . wp_generate_uuid4() );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10.00' );
		$product->set_virtual( true );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->save();
		$id = $product->get_id();
		wc_load_cart();
		WC()->cart->empty_cart();
		if ( ! WC()->cart->add_to_cart( $id, 1 ) ) {
			$reason = 'add_to_cart failed';
			return;
		}
		WC()->payment_gateways()->init();
		add_filter( 'wp_doing_ajax', '__return_true' );
		$nonce = wp_create_nonce( 'woocommerce-process_checkout' );
		$_POST = array(
			'woocommerce-process-checkout-nonce' => $nonce,
			'_wpnonce' => $nonce,
			'billing_first_name' => 'Ada',
			'billing_last_name' => 'Lovelace',
			'billing_country' => 'US',
			'billing_state' => 'CA',
			'billing_address_1' => '1 Market Street',
			'billing_city' => 'San Francisco',
			'billing_postcode' => '94105',
			'billing_email' => 'ada@example.test',
			'billing_phone' => '4155550100',
			'payment_method' => 'cod',
			'terms' => '1',
		);
		$_REQUEST = $_POST;
		WC()->checkout()->process_checkout();
		$reason = 'process_checkout returned: ' . wp_json_encode( wc_get_notices() );
	} catch ( Throwable $error ) {
		$reason = $error->getMessage();
	}
}

if ( isset( $args[0] ) && is_file( $args[0] ) ) {
	wl299_checkout_worker( $args[0] );
	return;
}

global $wpdb, $assertions;
$assertions = 0;

function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) {
		throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) );
	}
}
function ok( bool $condition, string $label ): void { eq( $condition, true, $label ); }
function meta_value( int $id, string $key ): string {
	global $wpdb;
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id DESC LIMIT 1", $id, $key ) );
	return (string) $value;
}
function journal_rows( int $id ): int {
	global $wpdb;
	$table = $wpdb->prefix . 'writeleash_price_items';
	$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
	if ( '1' !== (string) $exists ) { return 0; }
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id=%d", $id ) );
}

echo '#299 foreign-path env: WP ' . get_bloginfo( 'version' ) . ' Woo ' . WC_VERSION . ' PHP ' . PHP_VERSION . ' DB ' . $wpdb->get_var( 'SELECT VERSION()' ) . ' cache ' . ( getenv( 'WL109_CACHE' ) ?: 'unspecified' ) . "\n";
echo "#299 foreign-path skipped: Redis-only behavior is not this file; cron/Action Scheduler and preview/approve/resume/undo stay on the jobs/admin/undo suites\n";
ok( is_plugin_active( 'writeleash/writeleash.php' ), 'WriteLeash is active during the foreign-path regression' );
eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
eq( WC_VERSION, '11.1.2', 'pinned WooCommerce' );

require_once WC_ABSPATH . 'includes/admin/class-wc-admin-meta-boxes.php';
require_once WC_ABSPATH . 'includes/admin/meta-boxes/class-wc-meta-box-product-data.php';
wp_set_current_user( 1 );
$editor = new WC_Product_Simple();
$editor->set_name( 'WL299-admin-' . wp_generate_uuid4() );
$editor->set_status( 'publish' );
$editor->set_regular_price( '10.00' );
$editor->set_manage_stock( true );
$editor->set_stock_quantity( 8 );
$editor->save();
$edit_id = $editor->get_id();
$_POST = array(
	'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ),
	'post_ID' => (string) $edit_id,
	'product-type' => 'simple',
	'_regular_price' => '25.50',
	'_sale_price' => '',
	'_visibility' => 'visible',
	'_tax_status' => 'taxable',
	'_tax_class' => '',
	'_stock_status' => 'instock',
	'_manage_stock' => 'yes',
	'_stock' => '8',
	'_original_stock' => '8',
	'_backorders' => 'no',
	'comment_status' => 'open',
);
$_REQUEST = $_POST;
add_action( 'woocommerce_process_product_meta', array( 'WC_Meta_Box_Product_Data', 'save' ), 10, 2 );
$boxes = ( new ReflectionClass( 'WC_Admin_Meta_Boxes' ) )->newInstanceWithoutConstructor();
$boxes->save_meta_boxes( $edit_id, get_post( $edit_id ) );
if ( WC_Admin_Meta_Boxes::$meta_box_errors ) {
	throw new RuntimeException( 'product edit form errors: ' . wp_json_encode( WC_Admin_Meta_Boxes::$meta_box_errors ) );
}
eq( meta_value( $edit_id, '_regular_price' ), '25.50', 'product edit form persisted the new regular price' );
eq( journal_rows( $edit_id ), 0, 'product edit form created no WriteLeash journal row' );

$work = sys_get_temp_dir() . '/wl299-foreign-' . bin2hex( random_bytes( 3 ) );
mkdir( $work );
$spec = $work . '/checkout.json';
$result = $work . '/checkout.result';
file_put_contents( $spec, wp_json_encode( array( 'result' => $result ) ) );
$proc = proc_open(
	array( 'wp', '--path=' . ABSPATH, 'eval-file', __FILE__, $spec ),
	array( 1 => array( 'file', $work . '/stdout', 'w' ), 2 => array( 'file', $work . '/stderr', 'w' ) ),
	$pipes
);
if ( ! is_resource( $proc ) ) { throw new RuntimeException( 'checkout worker did not start' ); }
$exit = proc_close( $proc );
$stdout = is_file( $work . '/stdout' ) ? (string) file_get_contents( $work . '/stdout' ) : '';
$stderr = is_file( $work . '/stderr' ) ? (string) file_get_contents( $work . '/stderr' ) : '';
if ( ! is_file( $result ) ) {
	throw new RuntimeException( 'checkout worker wrote no result; exit=' . $exit . ' stdout=' . $stdout . ' stderr=' . $stderr );
}
$checkout = json_decode( (string) file_get_contents( $result ), true );
if ( ! is_array( $checkout ) || empty( $checkout['ok'] ) ) {
	throw new RuntimeException( 'checkout failed: ' . file_get_contents( $result ) . ' stdout=' . $stdout . ' stderr=' . $stderr );
}
eq( (string) $checkout['stock'], '4', 'checkout reduced stock by one' );
eq( (int) $checkout['orders'], 1, 'checkout created one order' );
eq( journal_rows( (int) $checkout['product_id'] ), 0, 'checkout created no WriteLeash journal row' );
eq( meta_value( (int) $checkout['product_id'], '_stock' ), '4', 'checkout stock is durable in postmeta' );
echo '#299 foreign-path regression: PASS (' . $assertions . " assertions); checkout and product-admin edit still persist; not enforced\n";
