<?php
// #168 fixture setup/independent storage observer for the existing browser owner.
$mode = (string) getenv( 'WL168_MODE' );
$file = (string) getenv( 'WL168_FIXTURE' );
if ( ! $file || ! in_array( $mode, array( 'seed', 'observe', 'edit-undo' ), true ) ) { throw new RuntimeException( 'Explicit #168 disposable fixture required' ); }
wp_set_current_user( 1 );
global $wpdb;
if ( 'seed' === $mode ) {
	function product168( string $name, string $price = '18.00', string $sku = '' ): int {
		$p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_sku( $sku ); $p->set_status( 'publish' ); $p->set_regular_price( $price ); $p->save(); return $p->get_id();
	}
	function plan168( array $ids, string $operation = 'DECREASE_PERCENT', string $amount = '20', array $extra = array() ): array {
		$result = WriteLeash\Free_Admin::process_preview( array_merge( array( '_wpnonce' => wp_create_nonce( WriteLeash\Free_Admin::ACTION_PREVIEW ), 'selector' => 'ids', 'ids' => implode( ',', $ids ), 'operation' => $operation, 'amount' => $amount, 'max_products' => '100', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '100' ), $extra ), 'POST' );
		if ( 'OK' !== $result['status'] ) { throw new RuntimeException( wp_json_encode( $result ) ); }
		return WriteLeash\Job_Repository::read_by_public_id( $result['public_id'] );
	}
	function finish168( array $job ): void {
		WriteLeash\Job_Repository::approve( (int) $job['id'], get_current_user_id() );
		for ( $step = 0; $step < 15; ++$step ) {
			WriteLeash\Job_Worker::run( (int) $job['id'], array( 'max_items' => 10, 'budget_seconds' => 20 ), true );
			if ( WriteLeash\Job_State::is_terminal( WriteLeash\Job_Repository::read( (int) $job['id'] )['status'] ) ) { return; }
		}
		throw new RuntimeException( 'Fixture failed to finish Apply' );
	}
	$mixed = array( product168( 'Blue T-Shirt', '18.00', 'BLUE-' . wp_generate_uuid4() ) );
	for ( $i = 0; $i < 11; ++$i ) { $mixed[] = product168( 'Café 日本 ' . $i, '100.00' ); }
	$mixed_job = plan168( $mixed );
	$p = wc_get_product( $mixed[0] ); $p->set_name( 'Renamed externally' ); $p->set_regular_price( '21.00' ); $p->save();
	$sale = product168( 'Sale context changed' ); $sale_job = plan168( array( $sale ) );
	$p = wc_get_product( $sale ); $p->set_sale_price( '15.00' ); $p->save();
	$deleted = product168( 'Deleted but recognizable' ); $deleted_job = plan168( array( $deleted ) ); wp_delete_post( $deleted, true );
	$nochange = product168( 'Already at target', '14.40' );
	$excluded = product168( 'Has a sale', '18.00' ); $p = wc_get_product( $excluded ); $p->set_sale_price( '15.00' ); $p->save();
	$nochange_job = plan168( array( $nochange, $excluded ), 'SET', '14.40' );
	$blocked = plan168( array( product168( 'Blocked T-Shirt' ) ), 'DECREASE_PERCENT', '20', array( 'max_decrease' => '1' ) );
	$markup = product168( '<script>alert("168")</script> Café 日本' );
	$formula = product168( '=SUM(1,2) Café "quoted"', '18.00', '+SKU-' . wp_generate_uuid4() );
	$success = plan168( array( $markup, $formula ) );
	$expired_id = product168( 'Expired Undo' ); $expired = plan168( array( $expired_id ) ); finish168( $expired );
	$wpdb->query( $wpdb->prepare( "UPDATE %i SET completed_at='2000-01-01 00:00:00',updated_at='2000-01-01 00:00:00' WHERE id=%d", WriteLeash\Job_Schema::jobs_table( $wpdb ), (int) $expired['id'] ) );
	$actor = wp_insert_user( array( 'user_login' => 'wl168-deleted-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
	wp_set_current_user( $actor ); $operator_job = plan168( array( product168( 'Deleted operator job' ) ) );
	wp_set_current_user( 1 ); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $actor );
	$empty_password = wp_generate_password( 32, false );
	$empty_name = 'wl168-empty-' . wp_generate_uuid4();
	$empty = wp_insert_user( array( 'user_login' => $empty_name, 'user_pass' => $empty_password, 'role' => 'shop_manager' ) );
	// Guarantee multiple history pages with real frozen previews, no job naming system.
	for ( $i = 0; $i < 21; ++$i ) { plan168( array( $nochange ), 'SET', '14.40' ); }
	$fixture = array( 'username' => 'admin', 'password' => 'disposable_admin_password', 'empty_username' => $empty_name, 'empty_password' => $empty_password, 'deleted_actor' => $actor, 'mixed_ids' => $mixed, 'success_ids' => array( $markup, $formula ), 'sale_id' => $sale, 'nochange_ids' => array( $nochange, $excluded ), 'expired_id' => $expired_id );
	foreach ( array( 'mixed' => $mixed_job, 'sale' => $sale_job, 'deleted' => $deleted_job, 'nochange' => $nochange_job, 'blocked' => $blocked, 'success' => $success, 'expired' => $expired, 'operator' => $operator_job ) as $key => $job ) { $fixture['jobs'][ $key ] = $job['public_id']; }
	file_put_contents( $file, wp_json_encode( $fixture ) ); chmod( $file, 0600 );
	// Counters are fixture diagnostics only, never exported by the plugin.
	update_option( 'wl168_saves', 0, false ); update_option( 'wl168_ddl', 0, false );
	$mu = ABSPATH . 'wp-content/mu-plugins'; if ( ! is_dir( $mu ) ) { mkdir( $mu ); }
	file_put_contents( $mu . '/wl168-observer.php', '<?php add_filter("action_scheduler_allow_async_request_runner", "__return_false"); add_action("woocommerce_before_product_object_save", static function () { update_option("wl168_saves", (int) get_option("wl168_saves", 0) + 1, false); }); add_filter("query", static function ($q) { if (preg_match("/^\\s*(CREATE|ALTER|DROP|TRUNCATE)\\b/i",$q)) { update_option("wl168_ddl", (int) get_option("wl168_ddl", 0) + 1, false); } return $q; });' );
	echo '#168 fixtures seeded';
} else {
	$fixture = json_decode( file_get_contents( $file ), true );
	if ( 'edit-undo' === $mode ) { $p = wc_get_product( $fixture['mixed_ids'][1] ); $p->set_regular_price( '93.00' ); $p->save(); }
	$prices = array();
	foreach ( array_merge( $fixture['mixed_ids'], $fixture['success_ids'], array( $fixture['sale_id'], $fixture['expired_id'] ) ) as $id ) {
		$prices[$id] = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM %i WHERE post_id=%d AND meta_key='_regular_price'", $wpdb->postmeta, $id ) );
	}
	echo wp_json_encode( array( 'prices' => $prices, 'saves' => (int) get_option( 'wl168_saves' ), 'ddl' => (int) get_option( 'wl168_ddl' ) ) );
}
