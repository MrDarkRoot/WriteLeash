<?php
// Repository-only bounded lifecycle fixture: create actual retained apply/Undo evidence.
$check = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
wp_set_current_user( get_user_by( 'login', 'artifact_actor' )->ID );
$product = new WC_Product_Simple();
$product->set_name( 'Exact artifact lifecycle retention control' );
$product->set_status( 'publish' );
$product->set_regular_price( '31.00' );
$id = $product->save();
$post = array( '_wpnonce' => wp_create_nonce( WriteLeash\Free_Admin::ACTION_PREVIEW ), 'selector' => 'ids', 'ids' => (string) $id, 'operation' => 'SET', 'amount' => '32.00', 'max_products' => '100', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '50', 'block_zero' => '1' );
$preview = WriteLeash\Free_Admin::process_preview( $post, 'POST' );
$check( 'OK' === $preview['status'], 'durable retention preview' );
$approved = WriteLeash\Free_Admin::process_approve( array( 'job' => $preview['public_id'], '_wpnonce' => wp_create_nonce( WriteLeash\Free_Admin::ACTION_APPROVE . '_' . $preview['plan_id'] ) ), 'POST' );
$check( 'OK' === $approved['status'], 'durable retention approval' );
WriteLeash\Free_Admin::process_resume( array( 'job' => $preview['public_id'], '_wpnonce' => wp_create_nonce( WriteLeash\Free_Admin::ACTION_RESUME . '_' . $preview['public_id'] ) ), 'POST' );
WriteLeash\Price_Cache_Verifier::invalidate( $id );
$check( '32.00' === wc_get_product( $id )->get_regular_price( 'edit' ), 'apply fixture did not persist' );
$undo = WriteLeash\Free_Admin::process_undo( array( 'job' => $preview['public_id'], '_wpnonce' => wp_create_nonce( WriteLeash\Free_Admin::ACTION_UNDO . '_' . $preview['public_id'] ) ), 'POST' );
$check( 'OK' === $undo['status'], 'eligible Free Undo fixture failed' );
WriteLeash\Price_Cache_Verifier::invalidate( $id );
$check( '31' === WriteLeash\Price_Decimal::parse( wc_get_product( $id )->get_regular_price( 'edit' ) ), 'Undo fixture did not persist' );
global $wpdb;
$journal = WriteLeash\Price_Apply_Journal::read( $wpdb, $preview['plan_id'], $id );
$check( $journal && 'APPLIED' === $journal['state'], 'apply evidence missing after Undo' );
$operation = WriteLeash\Undo_Repository::read_operation_by_job( $preview['job_id'] );
$check( $operation && in_array( $operation['status'], array( 'UNDO_COMPLETED', 'UNDO_COMPLETED_WITH_ISSUES' ), true ), 'Undo evidence missing' );
echo "Normal public Admin one-product apply/eligible Undo fixture; nonempty journal/job/Undo evidence ready for lifecycle preservation PASS\n";
