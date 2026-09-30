<?php
// Test-only trusted serialization generated locally by the #107 factory.
require __DIR__ . '/hook-torture.php';
$fixture = json_decode( file_get_contents( $args[0] ), true );
$plan = unserialize( file_get_contents( $fixture['plan'] ) );
wp_set_current_user( $plan->data()['actor_id'] );
if ( ! empty( $fixture['wp_version_fault'] ) ) { $GLOBALS['wp_version'] = '0.0.0'; }
$GLOBALS['wl108_log'] = $fixture['log'];
$GLOBALS['wl108_active'] = true;
$GLOBALS['wl108_original_wpdb'] = $GLOBALS['wpdb'];
file_put_contents( $fixture['started'], (string) getmypid() );
add_action( 'writeleash_price_apply_checkpoint', static function ( $point, $id, $attempt ) use ( $fixture ) {
	if ( $point !== ( $fixture['point'] ?? '' ) ) { return; }
	file_put_contents( $fixture['barrier'], $point );
	switch ( $fixture['mode'] ?? '' ) {
		case 'throw': throw new RuntimeException( 'controlled-fault' );
		case 'ambiguous': throw new WriteLeash\Price_Apply_Error( 'AMBIGUOUS_COMMIT' );
		case 'lost-then-throw':
		case 'lost':
			global $wpdb;
			$db = WriteLeash\Price_Cache_Verifier::observer();
			$db->query( 'KILL CONNECTION ' . (int) $wpdb->dbh->thread_id );
			$db->close();
			if ( 'lost-then-throw' === $fixture['mode'] ) { throw new RuntimeException( 'fault plus unknown rollback' ); }
			return;
		case 'reconnected':
			$GLOBALS['wl108_original_wpdb']->db_connect( false );
			return;
		case 'kill-during-query':
			add_filter( 'query', static function ( $sql ) {
				static $done = false;
				if ( ! $done && preg_match( '/^UPDATE\s+.*posts/i', $sql ) ) {
					$done = true;
					$db = WriteLeash\Price_Cache_Verifier::observer();
					$db->query( 'KILL CONNECTION ' . (int) $GLOBALS['wpdb']->dbh->thread_id );
					$db->close();
				}
				return $sql;
			} );
			return;
		case 'cache-read-fault':
			add_action( 'woocommerce_product_read', static function ( $id, $p ) { $p->set_regular_price( '123' ); }, 10, 2 );
			return;
		case 'changed':
			global $wpdb;
			$GLOBALS['wl108_replacement'] = WriteLeash\Price_Cache_Verifier::observer();
			$wpdb->dbh = $GLOBALS['wl108_replacement']->dbh;
			return;
		case 'transaction-ended':
			$GLOBALS['wpdb']->dbh->query( 'ROLLBACK' );
			return;
		case 'wait':
			$deadline = microtime( true ) + 20;
			while ( ! is_file( $fixture['release'] ) ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'barrier-timeout' ); }
				usleep( 10000 );
			}
	}
}, 10, 3 );
if ( ! empty( $fixture['lookup_fault'] ) ) {
	add_action( 'woocommerce_after_product_object_save', static function ( $p ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->wc_product_meta_lookup} SET min_price=123 WHERE product_id=%d", $p->get_id() ) );
	} );
}
$result = WriteLeash\Woo_Price_Mutator::apply( $plan, $fixture['id'] );
// Test-only two-item process envelope, not a production job runner.
if ( isset( $fixture['next'] ) && in_array( $result['code'], array( 'APPLIED', 'ALREADY_APPLIED' ), true ) ) {
 $next = $fixture['next'];
 $next_plan = unserialize( file_get_contents( $next['plan'] ) );
 wp_set_current_user( $next_plan->data()['actor_id'] );
 $GLOBALS['wl108_log'] = $next['log'];
 $result = array( 'A' => $result, 'B' => WriteLeash\Woo_Price_Mutator::apply( $next_plan, $next['id'] ) );
}
file_put_contents( $fixture['result'], json_encode( $result ) );
echo json_encode( $result ) . "\n";
