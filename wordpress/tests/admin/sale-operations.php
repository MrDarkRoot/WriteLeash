<?php
// #207 authenticated Admin boundary through the existing real DB/worker fixture.
use WriteLeash\Free_Admin as A207;
use WriteLeash\Price_Operation as O207;
use WriteLeash\Job_Repository as R207;
use WriteLeash\Price_Decimal as D207;
use WriteLeash\Product_Price_Snapshot as S207;
wp_set_current_user( 1 );
$home207 = render_view( '', '', 0 );
ok( str_contains( $home207, 'value="CLEAR_SALE"' ) && str_contains( $home207, 'value="SALE_DISCOUNT_PERCENT"' ), '#207 both Free selector options rendered' );
ok( str_contains( $home207, 'preserves sale schedules' ) && str_contains( $home207, 'own reviewed Regular Price' ), '#207 explanations describe exact semantics' );
foreach ( array( O207::CLEAR_SALE, O207::SALE_DISCOUNT_PERCENT ) as $type207 ) {
 $id207 = make_product( '100', 'publish', array( 'sale' => '90' ) );
 $post207 = preview_post( array( 'ids' => (string) $id207, 'price_field' => O207::FIELD_SALE, 'operation' => $type207, 'amount' => O207::CLEAR_SALE === $type207 ? '' : '20' ) );
 if ( O207::CLEAR_SALE === $type207 ) { unset( $post207['amount'] ); }
 $result207 = A207::process_preview( $post207, 'POST' );
 eq( $result207['status'], 'OK', '#207 Admin clear without amount / explicit discount' );
 $job207 = R207::read_by_public_id( $result207['public_id'] ); $plan207 = R207::hydrate_plan( $job207 );
 $target207 = O207::CLEAR_SALE === $type207 ? '' : '80.00';
 eq( $plan207->item( $id207 )->data()['planned_regular_price'], $target207, '#207 Admin Preview frozen final value' );
 $preview207 = render_view( 'preview', $job207['public_id'], 0 );
 ok( str_contains( $preview207, O207::CLEAR_SALE === $type207 ? 'Blank (no sale price)' : '$80.00 USD' ), '#207 Preview displays blank or final discount amount' );
 eq( A207::process_approve( approve_post( $job207 ), 'POST' )['status'], 'OK', '#207 explicit Admin approval' );
 run_job_terminal( (int) $job207['id'] );
 ok( D207::equal( S207::fresh_product( $id207 )->get_sale_price( 'edit' ), $target207 ), '#207 Admin Apply target equals Preview' );
 $html207 = render_view( 'job', $job207['public_id'], 0 );
 ok( str_contains( $html207, O207::CLEAR_SALE === $type207 ? 'Blank (no sale price)' : '$80.00 USD' ), '#207 History/result value exact' );
}
$id207 = make_product( '100' );
$post207 = preview_post( array( 'ids' => (string) $id207, 'price_field' => O207::FIELD_SALE, 'operation' => O207::SALE_DISCOUNT_PERCENT, 'amount' => '20' ) );
$result207 = A207::process_preview( $post207, 'POST' ); $job207 = R207::read_by_public_id( $result207['public_id'] );
eq( A207::process_approve( approve_post( $job207 ), 'POST' )['status'], 'OK', '#207 approve first sale reviewed basis' );
$p207 = S207::fresh_product( $id207 ); $p207->set_regular_price( '120' ); $p207->save();
run_job_terminal( (int) $job207['id'] );
eq( R207::items( (int) $job207['id'] )[0]['state'], 'CONFLICT', '#207 stale basis surfaced as Admin conflict' );
eq( S207::fresh_product( $id207 )->get_sale_price( 'edit' ), '', '#207 stale basis did not create sale' );
ok( str_contains( render_view( 'job', $job207['public_id'], 0 ), 'left the newer value unchanged' ), '#207 conflict merchant guidance visible' );
foreach ( array( '', '20%', '101', '1.0000001' ) as $invalid207 ) {
 $bad207 = A207::process_preview( array_merge( $post207, array( 'amount' => $invalid207 ) ), 'POST' );
 eq( $bad207['status'], 'INVALID', '#207 invalid discount refused by Admin' );
}
marker( '#207 Admin operations: selector, no-amount clear, percentage validation, frozen Preview/Apply, History and basis conflict' );
