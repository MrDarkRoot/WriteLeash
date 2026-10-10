<?php
// Loaded by the existing real Woo MySQL/MariaDB x default/Redis Admin matrix.
use WriteLeash\Free_Admin as A231;
use WriteLeash\Job_Repository as R231;
use WriteLeash\Price_Operation as O231;
use WriteLeash\Price_Apply_Journal as J231;
function refine231( array $job, array $ids = array(), string $action = 'exclude' ): array {
	return array( 'job' => $job['public_id'], '_wpnonce' => wp_create_nonce( A231::ACTION_REFINE . '_' . $job['public_id'] ), 'rows' => array_map( 'strval', $ids ), 'refinement_action' => $action );
}
function finish231( int $id, bool $undo = false ): void {
	for ( $n = 0; $n < 110; ++$n ) {
		if ( $undo ) {
			if ( WriteLeash\Undo_State::is_terminal( WriteLeash\Undo_Repository::read_operation( $id )['status'] ) ) { return; }
			WriteLeash\Undo_Worker::run( $id, array( 'max_items' => 10, 'budget_seconds' => 20 ), true );
		} else {
			if ( WriteLeash\Job_State::is_terminal( R231::read( $id )['status'] ) ) { return; }
			WriteLeash\Job_Worker::run( $id, array( 'max_items' => 10, 'budget_seconds' => 20 ), true );
		}
	}
	throw new RuntimeException( '#231 job did not finish' );
}
function journal231( string $plan_id, int $id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE plan_id=%s AND product_id=%d', J231::table( $wpdb ), $plan_id, $id ) );
}
wp_set_current_user( 1 );
$parent231 = wp_insert_term( 'Exclusions parent ' . wp_generate_uuid4(), 'product_cat' );
$child231 = wp_insert_term( 'Exclusions child ' . wp_generate_uuid4(), 'product_cat', array( 'parent' => $parent231['term_id'] ) );
$ids231 = array();
for ( $n231 = 0; $n231 < 350; ++$n231 ) { $ids231[] = make_product( '100.00', 'publish', array( 'category' => $child231['term_id'] ) ); }
$resolved231 = A231::process_preview( preview_post( array( 'selector' => 'category', 'category' => (string) $parent231['term_id'], 'include_subcategories' => '1', 'max_products' => '1000' ) ), 'POST' );
eq( $resolved231['status'], 'OK', '#231 descendants resolve 350 candidates' );
$root231 = R231::read_by_public_id( $resolved231['public_id'] ); $root_bytes231 = R231::hydrate_plan( $root231 )->json();
$saves231 = array();
$watch231 = static function ( $p ) use ( &$saves231, $ids231 ) { if ( in_array( $p->get_id(), $ids231, true ) ) { $saves231[] = $p->get_id(); } };
add_action( 'woocommerce_before_product_object_save', $watch231 );
$excluded231 = array_slice( $ids231, 0, 7 );
$first231 = A231::process_refine( refine231( $root231, array_slice( $excluded231, 0, 3 ) ), 'POST' ); eq( $first231['status'], 'OK', '#231 first-page exclusions' );
$page231 = R231::read_by_public_id( $first231['public_id'] );
$second231 = A231::process_refine( refine231( $page231, array_slice( $excluded231, 3 ) ), 'POST' ); eq( $second231['status'], 'OK', '#231 subsequent submission retains prior exclusions' );
$job231 = R231::read_by_public_id( $second231['public_id'] ); $plan231 = R231::hydrate_plan( $job231 );
eq( $plan231->summary()['selected'], 343, '#231 350 -> 343 real Woo frozen population' );
eq( $plan231->data()['selection_refinement']['excluded_ids'], $excluded231, '#231 exact explicit exclusions across submissions' );
eq( $plan231->data()['selection_refinement']['source_selection']['include_children'], true, '#231 source category scope retained' );
eq( R231::hydrate_plan( R231::read( (int) $root231['id'] ) )->json(), $root_bytes231, '#231 old immutable Preview unchanged' );
eq( (int) $job231['approver_id'], 0, '#231 no hidden automatic approval' );
$restore231 = A231::process_refine( refine231( $job231, array( $excluded231[0] ), 'restore' ), 'POST' ); eq( $restore231['status'], 'OK', '#231 explicit Restore creates a fresh review' );
$restored231 = R231::read_by_public_id( $restore231['public_id'] ); eq( R231::hydrate_plan( $restored231 )->summary()['selected'], 344, '#231 restores only the chosen row' );
eq( R231::hydrate_plan( R231::read( (int) $job231['id'] ) )->summary()['selected'], 343, '#231 Restore leaves previous reviewed Plan unchanged' );
$confirm231 = A231::process_refine( refine231( $job231, $excluded231, 'confirm' ), 'POST' ); eq( $confirm231['status'], 'OK', '#231 confirmed checked exclusions do not reset membership' );
eq( R231::hydrate_plan( R231::read_by_public_id( $confirm231['public_id'] ) )->data()['selection_refinement']['excluded_ids'], $excluded231, '#231 confirm preserves explicit server state' );
$html231 = render_view( 'preview', $job231['public_id'], 20 );
foreach ( array( 'Resolved candidates 350', 'Included targets 343', 'Excluded targets 7', 'Changing included targets 343', 'Unchanged included targets 0' ) as $copy231 ) { ok( str_contains( $html231, $copy231 ), '#231 server count ' . $copy231 ); }
render_view( 'refine', $job231['public_id'], 0 ); render_view( 'refine', $job231['public_id'], 20 );
eq( $saves231, array(), '#231 exclude, reload, pagination and cancel links perform zero Woo saves' );
eq( A231::process_refine( refine231( $job231, $ids231 ), 'POST' )['reason'], 'no_included_targets', '#231 all excluded explicitly refused' );
eq( A231::process_refine( refine231( $job231, array( $ids231[0], $ids231[0] ) ), 'POST' )['status'], 'OK', '#231 duplicate submitted IDs harmless' );
$forged231 = make_product( '100' );
eq( A231::process_refine( refine231( $job231, array( $forged231 ) ), 'POST' )['reason'], 'invalid_selection_refinement', '#231 unrelated forged row refused' );
eq( A231::process_refine( array_merge( refine231( $job231 ), array( '_wpnonce' => 'bad' ) ), 'POST' )['reason'], 'invalid_nonce', '#231 nonce' );
eq( A231::process_refine( refine231( $job231 ), 'GET' )['reason'], 'post_required', '#231 POST only' );
$other231 = wp_insert_user( array( 'user_login' => 'exclude-other-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $other231 ); eq( A231::process_refine( refine231( $job231 ), 'POST' )['status'], 'INVALID', '#231 unrelated actor cannot refine another Preview' );
wp_set_current_user( 0 ); eq( A231::process_refine( refine231( $job231 ), 'POST' )['status'], 'FORBIDDEN', '#231 anonymous capability refusal' ); wp_set_current_user( 1 );
$deny231 = static function ( $caps, $cap, $uid, $args ) use ( $ids231 ) { return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $ids231[7] ? array( 'do_not_allow' ) : $caps; };
add_filter( 'map_meta_cap', $deny231, 10, 4 );
eq( A231::process_refine( refine231( $job231 ), 'POST' )['reason'], 'permission_denied', '#231 revoked included-product permission refused' );
remove_filter( 'map_meta_cap', $deny231, 10 );
eq( A231::process_approve( array_merge( approve_post( $root231 ), array( 'job' => $job231['public_id'] ) ), 'POST' )['reason'], 'invalid_nonce', '#231 previous Plan approval nonce cannot approve new Plan' );
eq( A231::process_approve( approve_post( $job231 ), 'POST' )['status'], 'OK', '#231 explicit final approval' ); finish231( (int) $job231['id'] );
eq( R231::counts( (int) $job231['id'] )['applied'], 343, '#231 exactly 343 actual Apply successes' );
foreach ( $excluded231 as $id231 ) { price_eq( fresh_price( $id231 ), '100', '#231 excluded price unchanged' ); eq( journal231( $job231['plan_id'], $id231 ), 0, '#231 excluded has zero Apply/Undo journal entries' ); ok( ! in_array( $id231, $saves231, true ), '#231 excluded has zero Woo saves' ); }
$undo231 = WriteLeash\Undo_Repository::initiate( (int) $job231['id'], 1 ); finish231( (int) $undo231['id'], true );
eq( WriteLeash\Undo_Repository::history_job( (int) $job231['id'] )['undo']['undone'], 343, '#231 included targets Undo normally' );
foreach ( $ids231 as $id231 ) { price_eq( fresh_price( $id231 ), '100', '#231 all originals restored or untouched' ); }
foreach ( $excluded231 as $id231 ) { eq( journal231( $job231['plan_id'], $id231 ), 0, '#231 excluded remains absent after Undo' ); ok( ! in_array( $id231, $saves231, true ), '#231 excluded remains zero-save after Undo' ); }
remove_action( 'woocommerce_before_product_object_save', $watch231 );
eq( A231::process_refine( refine231( R231::read( (int) $job231['id'] ) ), 'POST' )['status'], 'INVALID', '#231 approved Plan cannot be edited' );
// Real Woo parent expansion, direct variation, reparenting, publication and removal.
$vp231 = new WC_Product_Variable(); $vp231->set_name( 'Exclusions variable' ); $vp231->set_status( 'publish' ); $vp231->save();
$children231 = array();
for ( $n231 = 0; $n231 < 2; ++$n231 ) { $v231 = new WC_Product_Variation(); $v231->set_parent_id( $vp231->get_id() ); $v231->set_status( 'publish' ); $v231->set_regular_price( '100' ); $v231->save(); $children231[] = $v231->get_id(); }
WC_Product_Variable::sync( $vp231->get_id() );
$vr231 = A231::process_preview( preview_post( array( 'ids' => $vp231->get_id() . ',' . implode( ',', $children231 ) ) ), 'POST' ); eq( $vr231['status'], 'OK', '#231 actual variable parent expansion' );
$vroot231 = R231::read_by_public_id( $vr231['public_id'] );
$vr231 = A231::process_refine( refine231( $vroot231, array( $children231[0] ) ), 'POST' ); eq( $vr231['status'], 'OK', '#231 exclude one expanded variation' );
$vjob231 = R231::read_by_public_id( $vr231['public_id'] );
eq( R231::hydrate_plan( $vjob231 )->data()['resolved_product_ids'], array( $children231[1] ), '#231 actual sibling retained exactly once' );
$vp231 = WriteLeash\Product_Price_Snapshot::fresh_product( $vp231->get_id() ); $vp231->set_status( 'draft' ); $vp231->save();
eq( A231::process_refine( refine231( $vroot231, array( $children231[0] ) ), 'POST' )['reason'], 'selection_changed', '#231 invalidated parent refused at confirmation' );
$vp231->set_status( 'publish' ); $vp231->save();
$direct231 = A231::process_preview( preview_post( array( 'ids' => implode( ',', $children231 ) ) ), 'POST' ); $directroot231 = R231::read_by_public_id( $direct231['public_id'] );
$otherparent231 = new WC_Product_Variable(); $otherparent231->set_name( 'Other variable' ); $otherparent231->set_status( 'publish' ); $otherparent231->save();
$v231 = WriteLeash\Product_Price_Snapshot::fresh_product( $children231[1] ); $v231->set_parent_id( $otherparent231->get_id() ); $v231->save();
eq( A231::process_refine( refine231( $directroot231, array( $children231[0] ) ), 'POST' )['reason'], 'selection_changed', '#231 frozen parent identity revalidated' );
$v231->set_parent_id( $vp231->get_id() ); $v231->save();
$vr231 = A231::process_refine( refine231( $directroot231, array( $children231[0] ) ), 'POST' ); eq( $vr231['status'], 'OK', '#231 direct variation refined' );
$vjob231 = R231::read_by_public_id( $vr231['public_id'] ); A231::process_approve( approve_post( $vjob231 ), 'POST' ); finish231( (int) $vjob231['id'] );
price_eq( fresh_price( $children231[0] ), '100', '#231 excluded variation untouched' ); price_eq( fresh_price( $children231[1] ), '80', '#231 included sibling applied' ); eq( journal231( $vjob231['plan_id'], $children231[0] ), 0, '#231 excluded variation no journal' );
$vu231 = WriteLeash\Undo_Repository::initiate( (int) $vjob231['id'], 1 ); finish231( (int) $vu231['id'], true ); price_eq( fresh_price( $children231[1] ), '100', '#231 included variation guarded Undo' );
$removed231 = make_product( '100' ); $rr231 = A231::process_preview( preview_post( array( 'ids' => $removed231 . ',' . $forged231 ) ), 'POST' ); $rroot231 = R231::read_by_public_id( $rr231['public_id'] ); wp_delete_post( $removed231, true );
eq( A231::process_refine( refine231( $rroot231, array() ), 'POST' )['reason'], 'selection_changed', '#231 removed identity refused before final Preview' );
// Oversized source resolution is refused before exclusions can discount it.
for ( $n231 = 350; $n231 < 1000; ++$n231 ) { make_product( '100', 'publish', array( 'category' => $child231['term_id'] ) ); }
$boundary231 = A231::process_preview( preview_post( array( 'selector' => 'category', 'category' => (string) $parent231['term_id'], 'include_subcategories' => '1', 'max_products' => '1000' ) ), 'POST' ); eq( $boundary231['status'], 'OK', '#231 actual 1000 boundary Preview' );
$broot231 = R231::read_by_public_id( $boundary231['public_id'] ); eq( A231::process_refine( refine231( $broot231, array(), 'confirm' ), 'POST' )['status'], 'OK', '#231 zero exclusions at 1000 valid' );
make_product( '100', 'publish', array( 'category' => $child231['term_id'] ) );
eq( A231::process_refine( refine231( $broot231, $excluded231 ), 'POST' )['reason'], 'selection_limit_exceeded', '#231 1001 cannot be discounted by seven exclusions' );
// Exercise the same target-membership boundary for every accepted operation.
foreach ( array( array( O231::SET, '80', 'regular_price', 'default' ), array( O231::INCREASE_FIXED, '5', 'regular_price', 'default' ), array( O231::DECREASE_FIXED, '5', 'regular_price', 'default' ), array( O231::INCREASE_PERCENT, '10', 'regular_price', 'default' ), array( O231::DECREASE_PERCENT, '10', 'regular_price', '99' ), array( O231::CLEAR_SALE, '', 'sale_price', 'default' ), array( O231::SALE_DISCOUNT_PERCENT, '20', 'sale_price', '95' ) ) as list( $op231, $amount231, $field231, $ending231 ) ) {
	$skip231 = make_product( '100', 'publish', array( 'sale' => '0' ) ); $keep231 = make_product( '100', 'publish', array( 'sale' => '90' ) );
	$r231 = A231::process_preview( preview_post( array( 'ids' => $skip231 . ',' . $keep231, 'operation' => $op231, 'amount' => $amount231, 'price_field' => $field231, 'ending' => $ending231 ) ), 'POST' );
	$old231 = R231::read_by_public_id( $r231['public_id'] ); $r231 = A231::process_refine( refine231( $old231, array( $skip231 ) ), 'POST' ); eq( $r231['status'], 'OK', '#231 refine ' . $op231 );
	$j231 = R231::read_by_public_id( $r231['public_id'] ); $p231 = R231::hydrate_plan( $j231 ); eq( $p231->data()['resolved_product_ids'], array( $keep231 ), '#231 only exact included target ' . $op231 );
	A231::process_approve( approve_post( $j231 ), 'POST' ); finish231( (int) $j231['id'] );
	$target231 = $p231->item( $keep231 )->data()['planned_regular_price'];
	ok( WriteLeash\Price_Decimal::equal( WriteLeash\Product_Price_Snapshot::fresh_product( $keep231 )->{'get_' . $field231}( 'edit' ), $target231 ), '#231 frozen target applied ' . $op231 );
	price_eq( fresh_price( $skip231 ), '100', '#231 excluded regular untouched ' . $op231 ); eq( WriteLeash\Product_Price_Snapshot::fresh_product( $skip231 )->get_sale_price( 'edit' ), '0', '#231 excluded numeric-zero sale untouched ' . $op231 ); eq( journal231( $j231['plan_id'], $skip231 ), 0, '#231 no excluded journal ' . $op231 );
	$u231 = WriteLeash\Undo_Repository::initiate( (int) $j231['id'], 1 ); finish231( (int) $u231['id'], true );
	price_eq( fresh_price( $keep231 ), '100', '#231 included regular restored ' . $op231 ); price_eq( WriteLeash\Product_Price_Snapshot::fresh_product( $keep231 )->get_sale_price( 'edit' ), '90', '#231 included sale restored/preserved ' . $op231 );
}
marker( '#231 real exclusions: 350 -> 343, explicit approval, zero excluded writes/journal, pagination, security, operations and Undo' );
