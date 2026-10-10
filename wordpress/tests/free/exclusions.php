<?php
// Reuse the existing core simple/variation fixtures and real Plan/calculator.
// These are domain tests, not evidence of Woo database writes.
require __DIR__ . '/variations.php';
require __DIR__ . '/../../writeleash/includes/free/class-job-repository.php';
use WriteLeash\Selection_Refinement as R;
use WriteLeash\Woo_Price_Planner as Planner;
use WriteLeash\Price_Selection_Spec as S;
use WriteLeash\Price_Operation as O;
use WriteLeash\Change_Plan as Plan;
function get_current_user_id() { return $GLOBALS['actor231'] ?? 1; }
function current_user_can( $cap, $id = 0 ) { return get_current_user_id() > 0 && ! in_array( $id, $GLOBALS['denied231'] ?? array(), true ); }
function wp_generate_uuid4() { static $n = 0; return sprintf( '00000000-0000-4000-8000-%012d', ++$n ); }
const ROOT231 = '00000000-0000-4000-8000-000000000231';
function plan231( S $selection, ?O $op = null ): Plan { return Planner::preview( $selection, $op ?? new O( O::SET, '80' ), wl179_policy() ); }
$GLOBALS['wl179_products'] = array();
for ( $id = 1; $id <= 350; ++$id ) { wl179_simple( $id, '100.00' ); }
$candidate = plan231( S::ids( range( 1, 350 ) ) ); $before = $candidate->json();
$plain231 = WriteLeash\Change_Plan::create( 'plain231', '2026-10-10T00:00:00Z', 1, wl179_context(), S::ids( range( 1, 350 ) ), new O( O::SET, '80' ), wl179_policy(), WriteLeash\Product_Price_Selector::resolve( S::ids( range( 1, 350 ) ) ) );
wl179_equal( $candidate->hash(), $plain231->hash(), 'ordinary Preview retains established material fingerprint' );
$final = R::preview( $candidate, range( 1, 7 ), ROOT231 );
wl179_equal( $final->summary()['selected'], 343, '350 minus 7 = exactly 343 frozen targets' );
wl179_equal( $final->data()['resolved_product_ids'], range( 8, 350 ), 'exact included membership' );
wl179_equal( $candidate->json(), $before, 'previous reviewed Plan bytes unchanged' );
wl179_equal( Plan::hydrate( json_decode( $final->json(), true ) )->json(), $final->json(), 'provenance and hash round trip' );
$tampered231 = $final->data(); $tampered231['selection_refinement']['excluded_ids'][] = 9999;
wl179_error( fn() => Plan::hydrate( $tampered231 ), 'plan_hash_mismatch' );
wl179_error( fn() => $final->item( 1 ), 'unpreviewed_product' );
wl179_equal( R::preview( $candidate, array( 1, 1, 2 ), ROOT231 )->summary()['selected'], 348, 'duplicates do not inflate exclusion counts' );
wl179_equal( R::preview( $candidate, array(), ROOT231 )->summary()['selected'], 350, 'zero excluded' );
wl179_error( fn() => R::preview( $candidate, range( 1, 350 ), ROOT231 ), 'no_included_targets' );
wl179_error( fn() => R::preview( $candidate, array( 9999 ), ROOT231 ), 'invalid_selection_refinement' );
$changed = R::preview( $candidate, array( 1 ), ROOT231 );
wl179_equal( $changed->data()['plan_id'] !== $final->data()['plan_id'], true, 'new reviewed identity for changed exclusions' );
$GLOBALS['denied231'] = array( 8 ); wl179_error( fn() => R::preview( $candidate, range( 1, 7 ), ROOT231 ), 'permission_denied' ); $GLOBALS['denied231'] = array();
unset( $GLOBALS['wl179_products'][8] ); wl179_error( fn() => R::preview( $candidate, range( 1, 7 ), ROOT231 ), 'selection_changed' ); wl179_simple( 8, '100.00' );
$GLOBALS['wl179_products'][8]->wl_set_value( 'regular_price', '120.00' );
$fresh = R::preview( $candidate, range( 1, 7 ), ROOT231 ); wl179_equal( $fresh->item( 8 )->data()['expected_regular_price'], '120', 'fresh current prices, never old candidate prices' );
$GLOBALS['wl179_products'][8]->wl_set_value( 'regular_price', '125.00' );
wl179_equal( $fresh->precondition( 8, WriteLeash\Product_Price_Snapshot::read( 8, $GLOBALS['wl179_products'][8] ), wl179_context() )['state'], 'CONFLICT', 'ordinary post-Preview stale-price conflict' );
for ( $id = 351; $id <= 1000; ++$id ) { wl179_simple( $id, '100' ); }
$boundary = plan231( S::ids( range( 1, 1000 ) ) ); wl179_equal( R::preview( $boundary, array(), ROOT231 )->summary()['selected'], 1000, '1000 valid' );
$GLOBALS['wl179_terms'][1] = new WP_Term( 1, 'Full category' );
foreach ( range( 1, 1000 ) as $id ) { $GLOBALS['wl179_categories'][$id] = array( 1 ); }
$category = plan231( S::category( 1 ) ); wl179_simple( 1001, '100' ); $GLOBALS['wl179_categories'][1001] = array( 1 );
wl179_error( fn() => R::preview( $category, range( 1, 7 ), ROOT231 ), 'selection_limit_exceeded' );
wl179_error( fn() => plan231( S::category( 1 ) ), 'selection_limit_exceeded' );
// Variation identity and one-row exclusion preserve the sibling and exact parent guard.
$GLOBALS['wl179_products'] = array(); $parent = wl179_variable( 10, array( 11, 12, 11 ) ); $a = wl179_variation( 11, 10, '100', '0' ); $b = wl179_variation( 12, 10, '200', '' );
$v = plan231( S::ids( array( 10, 11, 11 ) ) );
wl179_equal( $v->data()['requested_selection']['ids'], array( 10, 11 ), 'only expansion adds the necessary original parent context' );
wl179_equal( R::preview( $v, array( 11 ), ROOT231 )->data()['resolved_product_ids'], array( 12 ), 'one parent-expanded variation excluded, sibling included once' );
wl179_equal( R::preview( plan231( S::ids( array( 11, 12 ) ) ), array( 11 ), ROOT231 )->data()['resolved_product_ids'], array( 12 ), 'direct variation selection' );
$parent->wl_set_value( 'status', 'draft' ); wl179_error( fn() => R::preview( $v, array( 11 ), ROOT231 ), 'selection_changed' ); $parent->wl_set_value( 'status', 'publish' );
wl179_variable( 20, array() ); $b->wl_set_value( 'parent_id', 20 ); wl179_error( fn() => R::preview( $v, array( 11 ), ROOT231 ), 'selection_changed' ); $b->wl_set_value( 'parent_id', 10 );
foreach ( array( new O( O::SET, '80' ), new O( O::INCREASE_FIXED, '5' ), new O( O::DECREASE_FIXED, '5' ), new O( O::INCREASE_PERCENT, '10' ), new O( O::DECREASE_PERCENT, '10' ), new O( O::CLEAR_SALE, '', O::FIELD_SALE ), new O( O::SALE_DISCOUNT_PERCENT, '20', O::FIELD_SALE ), new O( O::DECREASE_PERCENT, '10', O::FIELD_REGULAR, '99' ), new O( O::SALE_DISCOUNT_PERCENT, '20', O::FIELD_SALE, '95' ) ) as $operation ) {
	$opplan = plan231( S::ids( array( 11, 12 ) ), $operation ); $refined = R::preview( $opplan, array( 11 ), ROOT231 );
	wl179_equal( $refined->data()['resolved_product_ids'], array( 12 ), 'operation-independent membership' );
	wl179_equal( $refined->item( 12 )->data(), $opplan->item( 12 )->data(), 'same calculation, expected basis and final policy target' );
}
$clear = R::preview( plan231( S::ids( array( 11, 12 ) ), new O( O::CLEAR_SALE, '', O::FIELD_SALE ) ), array(), ROOT231 );
wl179_equal( array_column( $clear->data()['items'], 'expected_regular_price' ), array( '0', '' ), 'blank versus numeric zero preserved' );
wl179_equal( $clear->summary()['changing'], 1, 'clear zero changes, clear blank unchanged' );
$a->wl_set_value( 'status', 'draft' ); $mixed = R::preview( plan231( S::ids( array( 11, 12 ) ) ), array(), ROOT231 );
wl179_equal( $mixed->data()['resolved_product_ids'], array( 12 ), 'only supported candidates become included targets' );
wl179_equal( $mixed->data()['selection_refinement']['refused_ids'], array( 11 ), 'refusal provenance outside executable items' );
echo "#231 exclusions domain: PASS; 350 -> 343, bounds, forgery, identity, variations, operations, immutable Plans\n";
