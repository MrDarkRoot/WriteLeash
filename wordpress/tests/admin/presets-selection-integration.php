<?php
// Genuine options/products/Plans. Included by the existing four-profile Admin matrix.
use WriteLeash\Free_Admin as PA230;
use WriteLeash\Price_Preset_Configuration as PC230;
use WriteLeash\Price_Preset_Repository as PR230;
use WriteLeash\Job_Repository as PJ230;
wp_set_current_user( 1 );
$range_ids230 = array();
foreach ( array( '19.99', '20', '50', '150', '150.01', '0' ) as $n230 => $price230 ) {
 $range_ids230[] = make_product( $price230, 'publish', 2 === $n230 ? array( 'sale' => '45' ) : array() );
}
$range_base230 = preview_post( array( 'selector' => 'manual_ids', 'ids' => implode( ',', $range_ids230 ), 'max_products' => '1000', 'operation' => 'DECREASE_PERCENT', 'amount' => '5' ) );
$range_cases230 = array(
 array( array(), $range_ids230 ),
 array( array( 'range_enabled' => '1', 'range_min' => '20' ), array_slice( $range_ids230, 1, 4 ) ),
 array( array( 'range_enabled' => '1', 'range_max' => '20' ), array( $range_ids230[0], $range_ids230[1], $range_ids230[5] ) ),
 array( array( 'range_enabled' => '1', 'range_min' => '20', 'range_max' => '150' ), array_slice( $range_ids230, 1, 3 ) ),
 array( array( 'range_enabled' => '1', 'range_min' => '0', 'range_max' => '0' ), array( $range_ids230[5] ) ),
 array( array( 'range_enabled' => '1', 'range_min' => '45', 'range_max' => '45', 'range_basis' => 'sale_price' ), array( $range_ids230[2] ) ),
 array( array( 'range_enabled' => '1', 'range_min' => '20.000001', 'range_max' => '150' ), array( $range_ids230[2], $range_ids230[3] ) ),
);
$range_saves230 = array();
$range_watch230 = static function ( $p ) use ( &$range_saves230, $range_ids230 ) { if ( in_array( $p->get_id(), $range_ids230, true ) ) { $range_saves230[] = $p->get_id(); } };
add_action( 'woocommerce_before_product_object_save', $range_watch230 );
try {
 foreach ( $range_cases230 as list( $filter230, $expected230 ) ) {
  $input230 = array_merge( $range_base230, $filter230 );
  $before230 = PA230::process_preview( $input230, 'POST' ); eq( $before230['status'], 'OK', 'range before persistence' );
  eq( PJ230::hydrate_plan( PJ230::read_by_public_id( $before230['public_id'] ) )->data()['resolved_product_ids'], $expected230, 'exact range membership before persistence' );
  $saved230 = PR230::save( 'Range round trip', PC230::capture( $input230 ) );
  $loaded230 = preset_call230( 'load', $saved230['id'] ); eq( $loaded230['status'], 'OK', 'range load' );
  $form230 = $loaded230['form']; $form230['amount'] = '4'; $form230['_wpnonce'] = wp_create_nonce( PA230::ACTION_PREVIEW );
  $after230 = PA230::process_preview( $form230, 'POST' ); eq( $after230['status'], 'OK', 'edit loaded amount and create fresh range Preview' );
  $after_job230 = PJ230::read_by_public_id( $after230['public_id'] );
  eq( PJ230::hydrate_plan( $after_job230 )->data()['resolved_product_ids'], $expected230, 'membership unchanged after persistence and edit' );
  eq( (int) $after_job230['approver_id'], 0, 'fresh range Preview unapproved' );
  ok( $before230['plan_id'] !== $after230['plan_id'], 'new range plan identity' );
  PR230::delete( $saved230['id'] );
 }
 eq( $range_saves230, array(), 'range preset CRUD and Preview perform zero product saves' );
 // A saved filter freezes no membership: catalog price drift is resolved again.
 $fresh_input230 = array_merge( $range_base230, array( 'range_enabled' => '1', 'range_min' => '20', 'range_max' => '150' ) );
 $fresh_record230 = PR230::save( 'Current range', PC230::capture( $fresh_input230 ) );
 $fresh_before230 = PA230::process_preview( $fresh_input230, 'POST' );
 $fresh_old230 = PJ230::read_by_public_id( $fresh_before230['public_id'] );
 remove_action( 'woocommerce_before_product_object_save', $range_watch230 );
 $changed230 = WriteLeash\Product_Price_Snapshot::fresh_product( $range_ids230[4] ); $changed230->set_regular_price( '149' ); $changed230->save();
 add_action( 'woocommerce_before_product_object_save', $range_watch230 );
 $fresh_form230 = preset_call230( 'load', $fresh_record230['id'] )['form']; $fresh_form230['_wpnonce'] = wp_create_nonce( PA230::ACTION_PREVIEW );
 $fresh_after230 = PA230::process_preview( $fresh_form230, 'POST' ); eq( $fresh_after230['status'], 'OK', 'range rechecks prices changed after preset save' );
 eq( PJ230::hydrate_plan( PJ230::read_by_public_id( $fresh_after230['public_id'] ) )->data()['resolved_product_ids'], array_slice( $range_ids230, 1, 4 ), 'newly in-range product joins only new independently reviewed Preview' );
 eq( PJ230::hydrate_plan( PJ230::read( (int) $fresh_old230['id'] ) )->data()['resolved_product_ids'], array_slice( $range_ids230, 1, 3 ), 'older ranged Preview membership stays frozen' );
 eq( PA230::process_refine( refine231( $fresh_old230 ), 'POST' )['reason'], 'selection_changed', 'old candidate cannot silently broaden through refinement' );
 PR230::delete( $fresh_record230['id'] );
 remove_action( 'woocommerce_before_product_object_save', $range_watch230 );
 $changed230->set_regular_price( '150.01' ); $changed230->save();
 add_action( 'woocommerce_before_product_object_save', $range_watch230 );
 // Real signed schema-1 record remains readable without modifying its bytes/signature.
 $legacy230 = PC230::capture( $range_base230 ); $legacy230['schema'] = 1; unset( $legacy230['selection']['price_range'] );
 $legacy_record230 = PR230::save( 'Legacy', $legacy230 );
 eq( PR230::load( $legacy_record230['id'] ), $legacy_record230, 'legacy HMAC/record unchanged' );
 eq( PC230::form( $legacy230 )['range_enabled'], '', 'legacy never becomes filtered' ); PR230::delete( $legacy_record230['id'] );
 foreach ( array( array( 'range_min' => '1,00' ), array( 'range_min' => '2', 'range_max' => '1' ) ) as $bad230 ) {
  eq( preset_call230( 'save', '', array_merge( $range_base230, array( 'preset_name' => 'Invalid', 'range_enabled' => '1' ), $bad230 ) )['reason'], 'invalid_price_range', 'invalid range save refused' );
 }
 // Current authorization covers the whole source, even a product outside the range.
 $deny_range230 = static function ( $caps, $cap, $actor, $args ) use ( $range_ids230 ) { return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $range_ids230[4] ? array( 'do_not_allow' ) : $caps; };
 add_filter( 'map_meta_cap', $deny_range230, 10, 4 );
 try { eq( PA230::process_preview( array_merge( $range_base230, array( 'range_enabled' => '1', 'range_min' => '20', 'range_max' => '150' ) ), 'POST' )['status'], 'FORBIDDEN', 'out-of-range source authorization is mandatory' ); }
 finally { remove_filter( 'map_meta_cap', $deny_range230, 10 ); }
 // Ordinary preset -> ranged candidates -> individually refined immutable Plan.
 $input230 = array_merge( $range_base230, array( 'range_enabled' => '1', 'range_min' => '20', 'range_max' => '150' ) );
 $saved230 = PR230::save( 'Range and later exclusions', PC230::capture( $input230 ) );
 $form230 = preset_call230( 'load', $saved230['id'] )['form']; $form230['_wpnonce'] = wp_create_nonce( PA230::ACTION_PREVIEW );
 $root_result230 = PA230::process_preview( $form230, 'POST' ); $root230 = PJ230::read_by_public_id( $root_result230['public_id'] );
 $refined230 = PA230::process_refine( refine231( $root230, array( $range_ids230[2] ) ), 'POST' ); eq( $refined230['status'], 'OK', 'loaded ranged candidate can use existing refinement' );
 $refined_job230 = PJ230::read_by_public_id( $refined230['public_id'] ); $refined_plan230 = PJ230::hydrate_plan( $refined_job230 );
 $frozen230 = $refined_plan230->json();
 eq( $refined_plan230->data()['resolved_product_ids'], array( $range_ids230[1], $range_ids230[3] ), 'range plus individual exclusion freezes only included IDs' );
 eq( $refined_plan230->data()['selection_refinement']['excluded_ids'], array( $range_ids230[2] ), 'exclusion remains frozen provenance' );
 foreach ( array( 'preview', 'refine' ) as $view230 ) {
  $html230 = render_view( $view230, $refined230['public_id'], 0 );
  ok( ! str_contains( $html230, 'Save as preset' ), 'no misleading refined-plan preset Save' );
  ok( str_contains( $html230, 'This refined Preview cannot be saved as a preset.' ), 'refinement limitation visible' );
 }
 try { PC230::capture( $refined_plan230->data() ); throw new RuntimeException( 'refined plan accepted as editable configuration' ); }
 catch ( WriteLeash\Price_Validation_Error $error230 ) { eq( $error230->reason(), 'invalid_preset', 'refined material rejected explicitly' ); }
 preset_call230( 'load', $saved230['id'] ); PR230::rename( $saved230['id'], 'Ordinary configuration' ); PR230::delete( $saved230['id'] );
 eq( PJ230::hydrate_plan( PJ230::read( (int) $refined_job230['id'] ) )->json(), $frozen230, 'ordinary preset CRUD never changes refined plan' );
 eq( PA230::process_approve( array_merge( approve_post( $root230 ), array( 'job' => $refined230['public_id'] ) ), 'POST' )['reason'], 'invalid_nonce', 'candidate approval cannot approve refined Preview' );
 eq( PA230::process_approve( approve_post( $refined_job230 ), 'POST' )['status'], 'OK', 'independent refined approval' );
 run_job_terminal( (int) $refined_job230['id'] );
 foreach ( array( $range_ids230[0], $range_ids230[2], $range_ids230[4], $range_ids230[5] ) as $excluded230 ) {
  ok( ! in_array( $excluded230, $range_saves230, true ), 'range/individual excluded products never written' );
  eq( journal231( $refined_job230['plan_id'], $excluded230 ), 0, 'excluded products have no Journal entry' );
 }
 eq( PJ230::counts( (int) $refined_job230['id'] )['applied'], 2, 'only two included products applied' );
 $undo230 = WriteLeash\Undo_Repository::initiate( (int) $refined_job230['id'], 1 ); run_undo_terminal( (int) $undo230['id'] );
 eq( WriteLeash\Undo_Repository::history_job( (int) $refined_job230['id'] )['undo']['undone'], 2, 'included range targets Undo normally' );
 // Loaded ranged Preview still conflicts on a later external write.
 $conflict_input230 = array_merge( $range_base230, array( 'ids' => (string) $range_ids230[1], 'range_enabled' => '1', 'range_min' => '20', 'range_max' => '20' ) );
 $conflict_record230 = PR230::save( 'Conflict range', PC230::capture( $conflict_input230 ) );
 $conflict_form230 = preset_call230( 'load', $conflict_record230['id'] )['form']; $conflict_form230['_wpnonce'] = wp_create_nonce( PA230::ACTION_PREVIEW );
 $conflict_result230 = PA230::process_preview( $conflict_form230, 'POST' ); $conflict_job230 = PJ230::read_by_public_id( $conflict_result230['public_id'] );
 remove_action( 'woocommerce_before_product_object_save', $range_watch230 );
 $changed230 = WriteLeash\Product_Price_Snapshot::fresh_product( $range_ids230[1] ); $changed230->set_regular_price( '21' ); $changed230->save();
 add_action( 'woocommerce_before_product_object_save', $range_watch230 );
 eq( PA230::process_approve( approve_post( $conflict_job230 ), 'POST' )['status'], 'OK', 'explicit conflict-preview approval' ); run_job_terminal( (int) $conflict_job230['id'] );
 eq( PJ230::counts( (int) $conflict_job230['id'] )['conflict'], 1, 'post-Preview range drift is CONFLICT, never reselected' );
 price_eq( fresh_price( $range_ids230[1] ), '21', 'later external price preserved' ); PR230::delete( $conflict_record230['id'] );
} finally { remove_action( 'woocommerce_before_product_object_save', $range_watch230 ); }
marker( '#230/#234 range membership round trips, legacy integrity, #231 frozen refinement/no-excluded-write/approval/Undo' );
