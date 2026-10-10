<?php
// #207 domain regressions; reuse the historical #178 fixture and its compatibility battery.
require __DIR__ . '/../admin/i18n-stubs.php';
require __DIR__ . '/sale-price.php';
require __DIR__ . '/../../writeleash/includes/free/class-free-admin.php';
use WriteLeash\Price_Operation as O;
use WriteLeash\Price_Calculator as C;
use WriteLeash\Price_Decimal as D;
use WriteLeash\Change_Plan as Plan;
use WriteLeash\Change_Plan_Item as Item;
use WriteLeash\Product_Price_Snapshot as Snapshot;
use WriteLeash\Safety_Policy as P;
use WriteLeash\Free_Admin as A;
use WriteLeash\Undo_Fingerprint as U;

function wc_get_price_thousand_separator() { return ','; }
function wc_get_price_decimal_separator() { return '.'; }
function get_woocommerce_currency_symbol( $currency ) { return '$'; }
function get_woocommerce_price_format() { return '%1$s%2$s'; }
class WC_Product_Variable extends WC_Product { public function get_type() { return 'variable'; } }
class WC_Product_Variation extends WC_Product {
 public function get_type() { return 'variation'; }
 public function get_parent_id( $context = 'view' ) { return $this->wl_values['parent_id'] ?? 900; }
 public function get_attributes() { return array( 'Blue' ); }
}
$clear = new O( O::CLEAR_SALE, '', O::FIELD_SALE );
$discount = new O( O::SALE_DISCOUNT_PERCENT, '20.000000', O::FIELD_SALE );
wl178_equal( $clear->data()['input'], '', 'clear has canonical nonnumeric input' );
wl178_equal( $discount->data()['input'], '20', 'exact discount normalized' );
foreach ( array( O::CLEAR_SALE, O::SALE_DISCOUNT_PERCENT ) as $type ) { wl178_error( static fn() => new O( $type, '', O::FIELD_REGULAR ), 'sale_operation_requires_sale_field' ); }
foreach ( array( '0', '20', null, array() ) as $input ) { wl178_error( static fn() => new O( O::CLEAR_SALE, $input, O::FIELD_SALE ), 'clear_sale_requires_empty_input' ); }
foreach ( array( '', '-1', '20%', ' 20', '1e1', 'NaN', 20, 20.0, null, array() ) as $input ) { wl178_error( static fn() => new O( O::SALE_DISCOUNT_PERCENT, $input, O::FIELD_SALE ), 'malformed_decimal' ); }
wl178_error( static fn() => new O( O::SALE_DISCOUNT_PERCENT, '100.000001', O::FIELD_SALE ), 'sale_discount_out_of_range' );
wl178_error( static fn() => new O( O::SALE_DISCOUNT_PERCENT, '1.0000001', O::FIELD_SALE ), 'input_precision_exceeded' );
foreach ( array( '80', '0', '' ) as $sale ) {
 $plan = wl178_plan( array( wl178_product( 601, '100', $sale, '2000000000', '2000100000' ) ), $clear );
 $item = $plan->item( 601 )->data();
 wl178_equal( $item['planned_regular_price'], '', 'clear target is blank, including numeric zero source' );
 wl178_equal( $item['result'], '' === $sale ? 'UNCHANGED' : 'CHANGING', 'blank is the only clear no-op' );
 wl178_equal( $item['snapshot']['sale_from'], '2000000000', 'schedule preserved in reviewed snapshot' );
 wl178_equal( Plan::hydrate( json_decode( $plan->json(), true ) )->json(), $plan->json(), 'blank target round trip exactly' );
 wl178_equal( $item['absolute_delta'], null, 'blank has no invented numeric delta' );
}
$plan = wl178_plan( array( wl178_product( 602, '100', '80' ) ), $clear, new P( 1000, '20', '100', true, '10' ) );
wl178_equal( $plan->item( 602 )->data()['blockers'], array( 'max_increase_exceeded' ), 'clear checks 25% return to regular, not a zero target' );
$plan = wl178_plan( array( wl178_product( 602, '100', '80' ) ), $clear, new P( 0, '100', '100', true, '100' ) );
wl178_equal( $plan->data()['status'], 'BLOCKED', 'clear respects changed-product limit' );
foreach ( array( '100' => '80.00', '250' => '200.00', '1.005' => '0.80' ) as $regular => $target ) {
 foreach ( array( '', '90' ) as $sale ) {
  $plan = wl178_plan( array( wl178_product( 603, (string) $regular, $sale ) ), $discount );
  wl178_equal( $plan->item( 603 )->data()['planned_regular_price'], $target, 'discount uses own regular price, not existing sale' );
  wl178_equal( Plan::hydrate( json_decode( $plan->json(), true ) )->json(), $plan->json(), 'discount approval round trip' );
 }
}
foreach ( array( 0 => '1', 1 => '0.8', 2 => '0.80', 3 => '0.804', 6 => '0.804000' ) as $dp => $target ) { wl178_equal( C::calculate( '', $discount, $dp, '1.005' ), $target, 'one exact final half-up rounding' ); }
wl178_equal( C::calculate( '', new O( O::SALE_DISCOUNT_PERCENT, '0', O::FIELD_SALE ), 2, '100' ), '100.00', '0 boundary arithmetic exact' );
wl178_equal( C::calculate( '', new O( O::SALE_DISCOUNT_PERCENT, '100', O::FIELD_SALE ), 2, '100' ), '0.00', '100 boundary is numeric zero, not clear' );
foreach ( array( '' => 'empty_regular_price', 'bad' => 'invalid_price' ) as $regular => $reason ) {
 wl178_equal( wl178_plan( array( wl178_product( 604, $regular ) ), $discount )->item( 604 )->data()['eligibility']['reason'], $reason, 'invalid/missing regular never becomes zero' );
}
$zero = wl178_plan( array( wl178_product( 604, '100' ) ), new O( O::SALE_DISCOUNT_PERCENT, '100', O::FIELD_SALE ), wl178_policy( true ) );
wl178_equal( $zero->item( 604 )->data()['blockers'], array( 'zero_target_blocked' ), 'actual rounded zero policy applies' );
$first_cap = wl178_plan( array( wl178_product( 604, '100' ) ), $discount, new P( 1000, '100', '10', false, '10' ) );
wl178_equal( $first_cap->item( 604 )->data()['blockers'], array( 'max_decrease_exceeded' ), 'first relative sale checks actual discount against reviewed regular basis' );
$old_first_set = wl178_plan( array( wl178_product( 604, '100' ) ), new O( O::SET, '80', O::FIELD_SALE ), new P( 1000, '0', '0', false, '0' ) );
wl178_equal( $old_first_set->data()['status'], 'PREVIEW', 'historical first-sale SET still has no ratio baseline' );
wl178_equal( wl178_plan( array( wl178_product( 604, '100' ) ), new O( O::SALE_DISCOUNT_PERCENT, '0', O::FIELD_SALE ) )->item( 604 )->data()['eligibility']['reason'], 'sale_price_not_below_regular', '0% cannot authorize an invalid sale' );
wl178_equal( wl178_plan( array( wl178_product( 604, '0.01' ) ), new O( O::SALE_DISCOUNT_PERCENT, '0.000001', O::FIELD_SALE ) )->item( 604 )->data()['eligibility']['reason'], 'sale_price_not_below_regular', 'rounded final target evaluated, not unrounded discount' );
foreach ( array( $clear, $discount ) as $operation ) {
 $plan = wl178_plan( array( wl178_product( 605, '100', '90' ) ), $operation );
 wl178_equal( $plan->precondition( 605, Snapshot::read( 605, wl178_product( 605, '120', '90' ) ), wl178_context() )['reasons'], array( 'regular_price_changed' ), 'even still-safe basis increase conflicts' );
 wl178_equal( $plan->precondition( 605, Snapshot::read( 605, wl178_product( 605, '100.000000', '90', '2000000000' ) ), wl178_context() )['state'], 'MATCH', 'canonical basis formatting and schedule do not invalidate approval' );
}
// Variation basis and frozen parent invariants, independently of sibling/parent prices.
$parent = new WC_Product_Variable( 900, array( 'regular_price' => '999' ) );
foreach ( array( $clear, $discount ) as $operation ) {
 $child = new WC_Product_Variation( 606, array( 'regular_price' => '250', 'sale_price' => '210', 'parent_id' => 900 ) );
 $snapshot = Snapshot::read( 606, $child, $parent );
 $item = Item::compute( $snapshot, wl178_context(), $operation, wl178_policy() );
 wl178_equal( $item->data()['planned_regular_price'], $operation === $clear ? '' : '200.00', 'variation uses its own regular price' );
 $plan = Plan::create( 'wl207-variation', '2026-10-09T00:00:00Z', 1, wl178_context(), WriteLeash\Price_Selection_Spec::ids( array( 606 ) ), $operation, wl178_policy(), array( $snapshot ) );
 foreach ( array( new WC_Product_Variable( 900, array( 'status' => 'draft' ) ), new WC_Product_Simple( 900 ), new WC_Product_Variable( 901 ) ) as $changed_parent ) {
  $changed = new WC_Product_Variation( 606, array( 'regular_price' => '250', 'sale_price' => '210', 'parent_id' => $changed_parent->get_id() ) );
  wl178_equal( $plan->precondition( 606, Snapshot::read( 606, $changed, $changed_parent ), wl178_context() )['state'], 'CONFLICT', 'parent status/type/reparenting conflict' );
 }
}
$facts = array_merge( $sale_facts, array( 'applied_price' => '', 'expected_price' => '80' ) );
$capture = U::capture( $facts ); $provenance = json_decode( $capture['provenance'], true );
$fresh = array_merge( $base, array( 'applied_price' => '', 'regular_context' => '100' ) );
wl178_equal( U::verify( $provenance, $fresh )['match'], true, 'blank applied sale is eligible Undo evidence' );
$fresh['applied_price'] = '0';
wl178_equal( U::verify( $provenance, $fresh )['reasons'], array( 'applied_price_changed' ), 'blank versus later zero is an Undo conflict' );
$store = array( 'currency' => 'USD', 'price_decimals' => 2 );
wl178_equal( A::money_display( '', $store, false, O::FIELD_SALE ), 'Blank (no sale price)', 'preview/history/polled blank explicit' );
wl178_equal( A::money_display( '', $store ), 'Unavailable', 'empty regular price stays unavailable' );
wl178_equal( A::money_display( '0', $store ), '$0.00 USD', 'zero remains money' );
wl178_equal( A::money_display( null, $store ), 'Unavailable', 'invalid/unavailable is not blank' );
wl178_equal( A::build_operation( array( 'operation' => O::CLEAR_SALE, 'price_field' => O::FIELD_SALE ) )->data(), $clear->data(), 'clear requires no amount, including no-JS form' );
wl178_error( static fn() => A::build_operation( array( 'operation' => O::SALE_DISCOUNT_PERCENT, 'price_field' => O::FIELD_SALE ) ), 'malformed_decimal' );
$GLOBALS['wl210_prefix'] = '[Ü] ';
wl178_equal( A::money_display( '', $store, false, O::FIELD_SALE ), '[Ü] Blank (no sale price)', 'blank translated' );
unset( $GLOBALS['wl210_prefix'] );
echo "#207 sale operations domain: PASS\n";

// #208 extends the same domain/legacy/variation fixture, without another calculator.
$start208 = $wl178_assertions;
foreach ( array( '99' => array( '10.20' => '9.99', '10.70' => '10.99', '10.49' => '10.99', '10.489999' => '9.99', '10.99' => '10.99', '0' => '0.99', '0.01' => '0.99' ), '95' => array( '10.20' => '9.95', '10.70' => '10.95', '10.45' => '10.95', '10.95' => '10.95', '0' => '0.95' ), '90' => array( '10.20' => '9.90', '10.70' => '10.90', '10.40' => '10.90', '10.90' => '10.90', '0' => '0.90' ), 'whole' => array( '10.49' => '10.00', '10.50' => '11.00', '10' => '10.00', '0.49' => '0.00', '0.50' => '1.00', '0' => '0.00' ) ) as $ending208 => $cases208 ) {
 foreach ( $cases208 as $input208 => $target208 ) {
  wl178_equal( C::calculate( '20', new O( O::SET, (string) $input208, O::FIELD_REGULAR, (string) $ending208 ), 2 ), $target208, '#208 nearest/tie/floor/already matching ' . $ending208 );
 }
}
foreach ( array( 0, 1, 2, 3, 6 ) as $dp208 ) {
 foreach ( array( O::SET, O::INCREASE_FIXED, O::DECREASE_FIXED, O::INCREASE_PERCENT, O::DECREASE_PERCENT, O::SALE_DISCOUNT_PERCENT ) as $type208 ) {
  $field208 = O::SALE_DISCOUNT_PERCENT === $type208 ? O::FIELD_SALE : O::FIELD_REGULAR;
  wl178_equal( C::calculate( '100', new O( $type208, '20', $field208, 'default' ), $dp208, '200' ), C::calculate( '100', new O( $type208, '20', $field208 ), $dp208, '200' ), '#208 default exact old operation parity' );
 }
 wl178_equal( C::calculate( '90', new O( O::CLEAR_SALE, '', O::FIELD_SALE, '99' ), $dp208 ), '', '#208 Clear ignores ending even at incompatible precision' );
 foreach ( array( '99', '95', '90' ) as $ending208 ) {
  if ( O::ending_supported( $ending208, $dp208 ) ) {
   wl178_equal( D::parse( C::calculate( '100', new O( O::SET, '10.7', O::FIELD_REGULAR, $ending208 ), $dp208 ) ), '10.' . rtrim( $ending208, '0' ), '#208 stable store formatting' );
  } else { wl178_error( static fn() => C::calculate( '100', new O( O::SET, '10.7', O::FIELD_REGULAR, $ending208 ), $dp208 ), 'price_ending_precision' ); }
 }
}
wl178_equal( C::calculate( '1', new O( O::DECREASE_PERCENT, '55.000001', O::FIELD_REGULAR, '90' ), 2 ), '0.90', '#208 exact intermediate below lowest nonnegative ending' );
wl178_equal( C::calculate( '10', new O( O::INCREASE_PERCENT, '4.899999', O::FIELD_REGULAR, '99' ), 2 ), '9.99', '#208 no double rounding of exact percentage intermediate at tie' );
foreach ( array( 'bad', '', 99, 0.99, array(), null ) as $invalid208 ) { wl178_error( static fn() => new O( O::SET, '10', O::FIELD_REGULAR, $invalid208 ), 'unsupported_price_ending' ); }
wl178_error( static fn() => C::calculate( '10', new O( O::SET, '999999999999.9', O::FIELD_REGULAR, 'whole' ), 2 ), 'price_overflow' );
$pol208 = new P( 1000, '0', '100', true, '100' );
$old208 = wl178_product( 708, '10.5' );
$default208 = wl178_plan( array( $old208 ), new O( O::DECREASE_FIXED, '0.01' ), $pol208 );
$rounded208 = wl178_plan( array( $old208 ), new O( O::DECREASE_FIXED, '0.01', O::FIELD_REGULAR, '99' ), $pol208 );
wl178_equal( $default208->data()['status'], 'PREVIEW', '#208 intermediate safe' );
wl178_equal( $rounded208->item( 708 )->data()['planned_regular_price'], '10.99', '#208 final is increase after decrease' );
wl178_equal( $rounded208->item( 708 )->data()['blockers'], array( 'max_increase_exceeded', 'price_ending_direction' ), '#208 caps and direction evaluate final' );
wl178_equal( $rounded208->data()['status'], 'BLOCKED', '#208 explained before approval' );
$zero208 = wl178_plan( array( wl178_product( 708, '1' ) ), new O( O::SET, '0.49', O::FIELD_REGULAR, 'whole' ), $pol208 );
wl178_equal( $zero208->item( 708 )->data()['blockers'], array( 'zero_target_blocked' ), '#208 safe positive intermediate becomes blocked zero' );
$up208 = wl178_plan( array( wl178_product( 708, '10.1' ) ), new O( O::INCREASE_FIXED, '0.01', O::FIELD_REGULAR, '99' ) );
wl178_equal( $up208->item( 708 )->data()['blockers'], array( 'price_ending_direction' ), '#208 unexpected downward increase blocked even under generous caps' );
$noop208 = wl178_plan( array( wl178_product( 708, '9.99' ) ), new O( O::SET, '10.2', O::FIELD_REGULAR, '99' ), new P( 0, '0', '0', true, '0' ) );
wl178_equal( $noop208->item( 708 )->data()['result'], 'UNCHANGED', '#208 final equals original is no-op; count/caps unchanged' );
foreach ( array( new O( O::SET, '10.5', O::FIELD_SALE, '99' ), new O( O::SALE_DISCOUNT_PERCENT, '1', O::FIELD_SALE, '99' ) ) as $op208 ) {
 wl178_equal( wl178_plan( array( wl178_product( 708, '10.8' ) ), $op208 )->item( 708 )->data()['eligibility']['reason'], 'sale_price_not_below_regular', '#208 safe sale intermediate becomes ineligible final' );
}
wl178_equal( wl178_plan( array( wl178_product( 708, '12', '10' ) ), new O( O::SET, '10.2', O::FIELD_REGULAR, '99' ) )->item( 708 )->data()['eligibility']['reason'], 'regular_price_not_above_sale', '#208 rounded regular crosses sale lower bound' );
$plan208 = wl178_plan( array( wl178_product( 708, '100', '90' ) ), new O( O::SALE_DISCOUNT_PERCENT, '20', O::FIELD_SALE, '99' ) );
wl178_equal( $plan208->item( 708 )->data()['planned_regular_price'], '79.99', '#208 relative frozen regular basis' );
wl178_equal( Plan::hydrate( json_decode( $plan208->json(), true ) )->json(), $plan208->json(), '#208 ending and final target hash-bound round trip' );
wl178_equal( $plan208->precondition( 708, Snapshot::read( 708, wl178_product( 708, '120', '90' ) ), wl178_context() )['reasons'], array( 'regular_price_changed' ), '#208 basis drift conflict, not recompute' );
wl178_equal( Plan::hydrate( $default208->data() )->json(), $default208->json(), '#208 missing ending old material never rewritten' );
wl178_equal( isset( $default208->data()['operation']['ending'] ), false, '#208 default does not alter operation hash material' );
wl178_equal( A::build_operation( array( 'operation' => O::CLEAR_SALE, 'price_field' => O::FIELD_SALE, 'ending' => '99' ) )->data(), $clear->data(), '#208 native Clear canonical ignored ending' );
wl178_equal( strpos( A::task_description( $plan208->data() ), 'nearest Price Ending .99 (ties upward)' ) !== false, true, '#208 History/CSV description retains ending provenance' );
echo '#208 Price Endings domain: PASS (' . ( $wl178_assertions - $start208 ) . " new assertions)\n";
