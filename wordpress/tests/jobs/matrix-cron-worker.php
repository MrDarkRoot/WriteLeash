<?php
// #311 user-0 background writer for the matrix non-interference cell.
// Spawned by matrix-integration.php as:
//   wp --path=<abspath> eval-file .../matrix-cron-worker.php <spec.json>
// Runs with no current user (user_id 0) exactly like Action Scheduler / WP-Cron,
// performs a plain wc_update_product_stock() write, and records the outcome so
// the parent process can assert the background path was never intercepted.
require __DIR__ . '/../durable/hook-torture.php';
$spec = json_decode( (string) file_get_contents( (string) ( $args[0] ?? '' ) ), true );
if ( ! is_array( $spec ) || ! isset( $spec['product_id'], $spec['qty'], $spec['result'] ) ) {
	throw new RuntimeException( 'cron worker spec invalid' );
}
$GLOBALS['wl108_active'] = ! empty( $spec['hook_log'] );
$GLOBALS['wl108_log'] = (string) ( $spec['hook_log'] ?? '' );
$id = (int) $spec['product_id'];
$qty = (int) $spec['qty'];
$product = wc_get_product( $id );
$before = $product ? $product->get_stock_quantity( 'edit' ) : null;
$result = array(
	'user_id' => (int) get_current_user_id(),
	'before' => null === $before ? '' : (string) $before,
	'after' => '',
	'applied' => false,
	'error' => '',
);
try {
	$after = wc_update_product_stock( $id, $qty, 'decrease' );
	if ( is_wp_error( $after ) ) {
		$result['error'] = (string) $after->get_error_code();
	} else {
		$result['after'] = null === $after ? '' : (string) $after;
		$result['applied'] = true;
	}
} catch ( \Throwable $e ) {
	$result['error'] = get_class( $e ) . ': ' . $e->getMessage();
}
file_put_contents( (string) $spec['result'], (string) wp_json_encode( $result ) );
exit( 0 );
