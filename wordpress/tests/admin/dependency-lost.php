<?php
// Fresh-process #111 dependency-loss probe: runs in its own `wp eval-file`
// invocation after the parent suite deactivated WooCommerce, so Woo code is
// genuinely unloaded (in-process deactivation cannot unload functions).
use WriteLeash\Free_Admin as Admin;

if ( function_exists( 'wc_get_product' ) || defined( 'WC_VERSION' ) ) {
	throw new RuntimeException( '#111 Woo API still loaded after dependency loss' );
}
if ( Admin::dependency_ok() ) {
	throw new RuntimeException( '#111 dependency gate open without WooCommerce' );
}
wp_set_current_user( 1 );
$post = array(
	'_wpnonce' => wp_create_nonce( Admin::ACTION_PREVIEW ),
	'selector' => 'ids',
	'ids' => '1',
	'sku' => null,
	'category' => null,
	'operation' => 'SET',
	'amount' => '80.00',
	'max_products' => '1000',
	'max_increase' => '100',
	'max_decrease' => '100',
	'warning_threshold' => '100',
	'block_zero' => null,
	'job' => null,
);
$result = Admin::process_preview( $post, 'POST' );
if ( 'INVALID' !== ( $result['status'] ?? '' ) || 'woocommerce_unavailable' !== ( $result['reason'] ?? '' ) ) {
	throw new RuntimeException( '#111 preview not refused without Woo: ' . json_encode( $result ) );
}
echo "#111 dependency loss fails closed in a fresh process: PASS\n";
