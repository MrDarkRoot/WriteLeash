<?php
use WriteLeash\Price_Cache_Verifier as Cache209;
use WriteLeash\Price_Apply_Journal as Journal209;
use WriteLeash\Woo_Price_Mutator as Mutator209;
use WriteLeash\Woo_Price_Planner as Planner209;
use WriteLeash\Price_Selection_Spec as Selection209;
use WriteLeash\Price_Operation as Operation209;
use WriteLeash\Safety_Policy as Policy209;
global $wpdb;
wp_set_current_user( 1 );
Journal209::install();
$p = new WC_Product_Simple(); $p->set_name( 'WLREG-' . wp_generate_uuid4() ); $p->set_status( 'publish' ); $p->set_regular_price( '100.00' ); $p->save();
$id = $p->get_id();
$plan = Planner209::preview( Selection209::ids( array( $id ) ), new Operation209( Operation209::SET, '80.00' ), new Policy209( 2, '100', '100', true, '100' ) );
Journal209::seed( $plan );
$result = Mutator209::apply( $plan, $id );
echo 'apply=' . $result['code'] . ' price=' . wc_get_product( $id )->get_regular_price( 'edit' ) . "\n";
// Emulate the observed race: every meta read for this product serves the
// pre-write blob until the first public product read finishes, then normal.
$stale = true; $served = 0;
$filter = function ( $value, $object_id, $meta_key, $single ) use ( $id, &$stale, &$served ) {
	if ( ! $stale || (int) $object_id !== $id ) { return $value; }
	if ( '' === $meta_key && false === $single ) { ++$served; file_put_contents('/tmp/opencode/servedlog', 'SERVED trace=' . wp_debug_backtrace_summary() . "\n", FILE_APPEND ); return array( '_regular_price' => array( '100.00' ), '_price' => array( '100.00' ), '_sale_price' => array( '' ) ); }
	return $value;
};
$reads = 0;
$disarm = function ( $product_id, $product = null ) use ( $id, &$stale, &$reads ) { if ( (int) $product_id === $id ) { ++$reads; file_put_contents('/tmp/opencode/readlog', 'READ#' . $reads . ' price=' . ( $product ? (string) $product->get_regular_price( 'edit' ) : '?' ) . "\n", FILE_APPEND ); if ( $reads >= 2 ) { $stale = false; } } };
add_filter( 'get_post_metadata', $filter, 10, 4 );
add_action( 'woocommerce_product_read', $disarm, 10, 2 );
try {
	$observed = Cache209::observe( $plan, $id );
	echo "OBSERVE PASS journal=" . $observed['journal']['state'] . "\n";
} catch ( Throwable $e ) {
	echo 'OBSERVE FAIL ' . get_class( $e ) . ' ' . $e->getMessage() . "\n";
} finally {
	remove_filter( 'get_post_metadata', $filter, 10 );
	remove_action( 'woocommerce_product_read', $disarm, 10 );
}
echo 'price_after=' . wc_get_product( $id )->get_regular_price( 'edit' ) . ' stale_blobs_served=' . $served . "\n";
