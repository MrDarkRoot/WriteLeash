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

ok( str_contains( $home207, 'id="writeleash-free-ending"' ) && str_contains( $home207, 'exact ties go upward' ), '#208 compact labelled control and actual rule' );
foreach ( array( '99' => '79.99', '95' => '79.95', '90' => '79.90', 'whole' => '80.00' ) as $ending208 => $target208 ) {
 $id208 = make_product( '100', 'publish', array( 'sale' => '90' ) );
 $post208 = preview_post( array( 'ids' => (string) $id208, 'price_field' => O207::FIELD_SALE, 'operation' => O207::SALE_DISCOUNT_PERCENT, 'amount' => '20', 'ending' => (string) $ending208 ) );
 $r208 = A207::process_preview( $post208, 'POST' ); eq( $r208['status'], 'OK', '#208 Admin ending accepted' );
 $j208 = R207::read_by_public_id( $r208['public_id'] ); $p208 = R207::hydrate_plan( $j208 );
 eq( $p208->item( $id208 )->data()['planned_regular_price'], $target208, '#208 Admin final frozen target' );
 ok( str_contains( render_view( 'preview', $j208['public_id'], 0 ), '$' . $target208 . ' USD' ), '#208 Preview displays final rounded price' );
 eq( A207::process_approve( approve_post( $j208 ), 'POST' )['status'], 'OK', '#208 approved existing path' ); run_job_terminal( (int) $j208['id'] );
 ok( D207::equal( S207::fresh_product( $id208 )->get_sale_price( 'edit' ), $target208 ), '#208 Apply same final target' );
}
$id208 = make_product( '10.5' );
$r208 = A207::process_preview( preview_post( array( 'ids' => (string) $id208, 'operation' => O207::DECREASE_FIXED, 'amount' => '0.01', 'ending' => '99', 'max_increase' => '0' ) ), 'POST' );
$j208 = R207::read_by_public_id( $r208['public_id'] );
eq( R207::hydrate_plan( $j208 )->data()['status'], 'BLOCKED', '#208 final increase blocks Preview' );
$html208 = render_view( 'preview', $j208['public_id'], 0 );
ok( str_contains( $html208, 'reverses the selected increase or decrease' ) && ! str_contains( $html208, '>Approve and apply<' ), '#208 final-target refusal explained before approval' );
update_option( 'woocommerce_price_num_decimals', 0 );
$html208 = render_view( '', '', 0 );
ok( preg_match( '/<option value="99"[^>]*disabled/', $html208 ) === 1 && str_contains( $html208, '.99/.95 need two decimal places' ), '#208 precision incompatibility visibly disabled and explained' );
update_option( 'woocommerce_price_num_decimals', 2 );
marker( '#208 Admin final targets, blocked-direction explanation and precision controls' );
