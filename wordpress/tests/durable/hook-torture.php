<?php
/** Synthetic third-party plugin: no actual mail/HTTP/remote work. Not packaged. */
foreach ( array( 'woocommerce_before_product_object_save', 'woocommerce_product_object_updated_props', 'woocommerce_update_product', 'woocommerce_after_product_object_save' ) as $hook ) {
	add_action( $hook, static function ( $subject ) use ( $hook ) {
		if ( empty( $GLOBALS['wl108_active'] ) ) { return; }
		global $wpdb;
		$id = $subject instanceof WC_Product ? $subject->get_id() : (int) $subject;
		// Filesystem append is deliberately outside database rollback.
		file_put_contents( $GLOBALS['wl108_log'], json_encode( array( 'hook' => $hook, 'id' => $id, 'connection' => (int) $wpdb->dbh->thread_id ) ) . "\n", FILE_APPEND | LOCK_EX );
		$table = $wpdb->prefix . 'wl108_hook_events';
		$wpdb->insert( $table, array( 'product_id' => $id, 'hook' => $hook ) );
		update_option( 'wl108_hook_option_' . $id, $hook, false );
	}, 10, 1 );
}
