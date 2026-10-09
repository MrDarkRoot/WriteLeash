<?php
// #207 real Woo CRUD / durable workers, run on every MySQL/MariaDB default/Redis profile.
use WriteLeash\Price_Operation as O207;
use WriteLeash\Job_Repository as R207;
use WriteLeash\Product_Price_Snapshot as S207;
use WriteLeash\Price_Cache_Verifier as V207;
use WriteLeash\Price_Decimal as D207;
use WriteLeash\Free_Admin as A207;
use WriteLeash\Undo_Item_State as U207;

function wl207_fixture( string $type, bool $variation = false, string $sale = '80', string $regular = '100' ): array {
 wp_set_current_user( 1 );
 $parent = null;
 if ( $variation ) {
  $parent = new WC_Product_Variable(); $parent->set_name( 'WL207-parent' ); $parent->set_status( 'publish' ); $parent->save();
  $product = new WC_Product_Variation(); $product->set_parent_id( $parent->get_id() );
 } else { $product = new WC_Product_Simple(); }
 $product->set_name( 'WL207-' . wp_generate_uuid4() ); $product->set_status( 'publish' );
 $product->set_regular_price( $regular ); $product->set_sale_price( $sale );
 $product->set_date_on_sale_from( time() + 86400 ); $product->set_date_on_sale_to( time() + 172800 );
 $product->update_meta_data( 'wl207_unrelated', 'preserve-me' ); $product->save();
 if ( $parent ) { WC_Product_Variable::sync( $parent ); }
 $id = $product->get_id(); $before = S207::read( $id, S207::fresh_product( $id ) )->data();
 $op = new O207( $type, O207::CLEAR_SALE === $type ? '' : '20', O207::FIELD_SALE );
 $plan = WriteLeash\Woo_Price_Planner::preview( WriteLeash\Price_Selection_Spec::ids( array( $id ) ), $op, new WriteLeash\Safety_Policy( 1000, '100', '100', true, '100' ) );
 $job = R207::create_from_plan( $plan, 1 ); $job = R207::approve( (int) $job['id'], 1 );
 return array( 'id' => $id, 'parent' => $parent, 'plan' => $plan, 'job' => $job, 'before' => $before );
}
function wl207_fresh( int $id ) { V207::invalidate( $id ); return S207::fresh_product( $id ); }

foreach ( array( O207::CLEAR_SALE, O207::SALE_DISCOUNT_PERCENT ) as $type207 ) {
 foreach ( array( false, true ) as $variation207 ) {
  // Two regular bases, first-time sale, and a blank clear no-op.
  foreach ( array( array( '100', '90' ), array( '250', '210' ), array( '100', '' ) ) as $prices207 ) {
   $f207 = wl207_fixture( $type207, $variation207, $prices207[1], $prices207[0] );
   $id207 = $f207['id']; $job207 = (int) $f207['job']['id']; $plan207 = $f207['plan'];
   $target207 = O207::CLEAR_SALE === $type207 ? '' : ( '250' === $prices207[0] ? '200.00' : '80.00' );
   eq( $plan207->preview_page()['items'][0]['planned_regular_price'], $target207, '#207 preview final value' );
   eq( R207::hydrate_plan( R207::read( $job207 ) )->json(), $plan207->json(), '#207 durable plan preserves exact input/basis/target' );
   $saves207 = saves( $id207 );
   run_worker( array( 'job_id' => $job207, 'mode' => 'apply', 'limits' => limits( 5 ), 'manual' => true ) );
   $p207 = wl207_fresh( $id207 ); $s207 = S207::read( $id207, $p207 )->data();
   ok( D207::equal( $s207['sale_price'], $target207 ), '#207 Woo sale equals reviewed target (blank distinct)' );
   eq( $s207['regular_price'], $f207['before']['regular_price'], '#207 regular preserved' );
   eq( array( $s207['sale_from'], $s207['sale_to'] ), array( $f207['before']['sale_from'], $f207['before']['sale_to'] ), '#207 Apply schedule preserved' );
   eq( $p207->get_meta( 'wl207_unrelated' ), 'preserve-me', '#207 unrelated meta preserved' );
   $no_op207 = O207::CLEAR_SALE === $type207 && '' === $prices207[1];
   if ( $no_op207 ) { eq( saves( $id207 ), $saves207, '#207 blank clear no-op zero saves' ); continue; }
   $row207 = journal_row( $plan207->data()['plan_id'], $id207 );
   eq( $row207['state'], 'APPLIED', '#207 durable journal APPLIED' );
   eq( $row207['target_price'], $target207, '#207 journal blank versus numeric value' );
   $e207 = json_decode( $row207['evidence'], true );
   ok( D207::equal( $e207['field_value'], $target207 ), '#207 journal field evidence equals target' );
   V207::observe( $plan207, $id207 );
   eq( WriteLeash\Woo_Price_Mutator::apply( $plan207, $id207 )['code'], 'ALREADY_APPLIED', '#207 duplicate Apply idempotent' );
   $history207 = WriteLeash\Undo_Repository::history_items( $job207 );
   eq( $history207['items'][0]['planned_price'], $target207, '#207 History exact planned value' );
   $stream207 = fopen( 'php://temp', 'w+' ); A207::write_job_csv( $stream207, R207::read( $job207 ), $plan207 ); rewind( $stream207 );
   $header207 = fgetcsv( $stream207 ); $csv207 = array_combine( $header207, fgetcsv( $stream207 ) ); fclose( $stream207 );
   eq( $csv207['planned_price'], $target207, '#207 CSV blank remains empty, never zero' );
   eq( $csv207['regular_price_at_preview'], $f207['before']['regular_price'], '#207 CSV reviewed basis retained' );
   ok( str_contains( $csv207['task'], O207::CLEAR_SALE === $type207 ? 'blank' : '20%' ), '#207 CSV operation provenance unambiguous' );
   $op207 = start_undo( $job207 );
   $provenance207 = json_decode( undo_row( $job207, $id207 )['provenance'], true );
   ok( D207::equal( $provenance207['applied_price'], $target207 ), '#207 Undo applied blank/value evidence' );
   ok( D207::equal( $provenance207['blocking']['regular_context'], $f207['before']['regular_price'] ), '#207 Undo keeps reviewed regular context' );
   run_worker( array( 'undo_id' => (int) $op207['id'], 'mode' => 'undo', 'limits' => limits( 5 ), 'manual' => true ) );
   eq( undo_row( $job207, $id207 )['state'], U207::UNDONE, '#207 eligible Undo restores' );
   $s207 = S207::read( $id207, wl207_fresh( $id207 ) )->data();
   ok( D207::equal( $s207['sale_price'], $f207['before']['sale_price'] ), '#207 Undo original sale, including first-time blank' );
   eq( $s207['regular_price'], $f207['before']['regular_price'], '#207 Undo regular preserved' );
   eq( array( $s207['sale_from'], $s207['sale_to'] ), array( $f207['before']['sale_from'], $f207['before']['sale_to'] ), '#207 Undo schedules preserved' );
   eq( WriteLeash\Woo_Undo_Mutator::restore( $job207, (int) $op207['id'], $id207, wp_generate_uuid4() )['code'], 'ALREADY_UNDONE', '#207 duplicate Undo no replay' );
   if ( $f207['parent'] ) { V207::assert_parent_range( $wpdb, $f207['parent']->get_id(), array( $id207 ) ); }
  }
 }
 // Sale edit and basis edit are terminal conflicts; Resume preserves approval.
 foreach ( array( 'regular', 'sale', 'draft_parent', 'type_parent', 'reparent' ) as $drift207 ) {
  $variation207 = str_contains( $drift207, 'parent' );
  $f207 = wl207_fixture( $type207, $variation207, '90' ); $id207 = $f207['id']; $job207 = (int) $f207['job']['id'];
  if ( 'regular' === $drift207 || 'sale' === $drift207 ) {
   $p207 = wl207_fresh( $id207 );
   if ( 'regular' === $drift207 ) { $p207->set_regular_price( '120' ); } else { $p207->set_sale_price( '85' ); }
   $p207->save();
  } elseif ( 'draft_parent' === $drift207 ) { $f207['parent']->set_status( 'draft' ); $f207['parent']->save(); }
  elseif ( 'type_parent' === $drift207 ) { wp_set_object_terms( $f207['parent']->get_id(), 'simple', 'product_type' ); }
  else { $parent207 = new WC_Product_Variable(); $parent207->set_status( 'publish' ); $parent207->save(); $p207 = wl207_fresh( $id207 ); $p207->set_parent_id( $parent207->get_id() ); $p207->save(); }
  $before207 = saves( $id207 );
  run_worker( array( 'job_id' => $job207, 'mode' => 'apply', 'limits' => limits( 5 ), 'manual' => true ) );
  eq( journal_row( $f207['plan']->data()['plan_id'], $id207 )['state'], 'CONFLICT', '#207 drift Apply conflict: ' . $drift207 );
  run_worker( array( 'job_id' => $job207, 'mode' => 'apply', 'limits' => limits( 5 ), 'manual' => true ) );
  eq( saves( $id207 ), $before207, '#207 Resume never recomputes or overwrites drift' );
  eq( R207::hydrate_plan( R207::read( $job207 ) )->json(), $f207['plan']->json(), '#207 conflict retains old frozen authority' );
 }
 foreach ( array( 'sale', 'regular', 'draft_parent', 'type_parent', 'reparent' ) as $drift207 ) {
  $f207 = wl207_fixture( $type207, str_contains( $drift207, 'parent' ), '90' ); $id207 = $f207['id']; $job207 = (int) $f207['job']['id'];
  run_worker( array( 'job_id' => $job207, 'mode' => 'apply', 'limits' => limits( 5 ), 'manual' => true ) );
  eq( journal_row( $f207['plan']->data()['plan_id'], $id207 )['state'], 'APPLIED', '#207 conflict Undo fixture applied' );
  $p207 = wl207_fresh( $id207 );
  if ( 'sale' === $drift207 ) { $p207->set_sale_price( '75' ); $p207->save(); }
  elseif ( 'regular' === $drift207 ) { $p207->set_regular_price( '120' ); $p207->save(); }
  elseif ( 'draft_parent' === $drift207 ) { $f207['parent']->set_status( 'draft' ); $f207['parent']->save(); }
  elseif ( 'type_parent' === $drift207 ) { wp_set_object_terms( $f207['parent']->get_id(), 'simple', 'product_type' ); }
  else { $parent207 = new WC_Product_Variable(); $parent207->set_status( 'publish' ); $parent207->save(); $p207->set_parent_id( $parent207->get_id() ); $p207->save(); }
  $before207 = saves( $id207 ); $op207 = start_undo( $job207 );
  run_worker( array( 'undo_id' => (int) $op207['id'], 'mode' => 'undo', 'limits' => limits( 5 ), 'manual' => true ) );
  eq( undo_row( $job207, $id207 )['state'], U207::CONFLICT, '#207 newer edit Undo conflict: ' . $drift207 );
  eq( saves( $id207 ), $before207, '#207 conflicted Undo zero saves' );
 }
 // Apply crash/reconciliation uses the original approval, even if the basis
 // changes after rollback. A durable post-COMMIT apply is adopted, not saved twice.
 foreach ( array( 'retry', 'stale_basis', 'durable' ) as $crash207 ) {
  $f207 = wl207_fixture( $type207, false, '90' ); $id207 = $f207['id']; $job207 = (int) $f207['job']['id'];
  $point207 = 'durable' === $crash207 ? 'AFTER_COMMIT_BEFORE_RESPONSE' : 'AFTER_WOO_SAVE_BEFORE_JOURNAL';
  $before207 = saves( $id207 );
  $w207 = start_worker( array( 'job_id' => $job207, 'mode' => 'apply', 'limits' => limits( 5 ), 'manual' => true, 'apply_checkpoint' => $point207, 'fault' => 'wait' ) );
  await_file( $w207['spec']['barrier'] ); proc_terminate( $w207['proc'], 9 ); finish_worker( $w207, true );
  if ( 'durable' !== $crash207 ) { ok( D207::equal( wl207_fresh( $id207 )->get_sale_price( 'edit' ), '90' ), '#207 uncommitted Apply rolled back' ); }
  if ( 'stale_basis' === $crash207 ) { $p207 = wl207_fresh( $id207 ); $p207->set_regular_price( '120' ); $p207->save(); }
  $wpdb->query( $wpdb->prepare( 'UPDATE %i SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=%d', WriteLeash\Job_Schema::jobs_table( $wpdb ), $job207 ) );
  run_worker( array( 'job_id' => $job207, 'mode' => 'apply', 'limits' => limits( 5 ), 'manual' => true ) );
  eq( journal_row( $f207['plan']->data()['plan_id'], $id207 )['state'], 'stale_basis' === $crash207 ? 'CONFLICT' : 'APPLIED', '#207 Apply crash recovery: ' . $crash207 );
  eq( R207::hydrate_plan( R207::read( $job207 ) )->json(), $f207['plan']->json(), '#207 recovery frozen plan never recalculated' );
  if ( 'durable' === $crash207 ) { eq( saves( $id207 ) - $before207, 1, '#207 durable Apply adopted without second Woo save' ); }
 }
 // Real SIGKILL before and after Undo COMMIT proves recovery and no duplicate save.
 foreach ( array( 'UNDO_AFTER_WOO_SAVE_BEFORE_JOURNAL', 'UNDO_AFTER_COMMIT_BEFORE_RESPONSE' ) as $point207 ) {
  $f207 = wl207_fixture( $type207, false, '90' ); $id207 = $f207['id']; $job207 = (int) $f207['job']['id'];
  run_worker( array( 'job_id' => $job207, 'mode' => 'apply', 'limits' => limits( 5 ), 'manual' => true ) );
  $op207 = start_undo( $job207 ); $undo207 = (int) $op207['id']; $before207 = saves( $id207 );
  $w207 = start_worker( array( 'undo_id' => $undo207, 'mode' => 'undo', 'limits' => limits( 5 ), 'undo_checkpoint' => $point207, 'fault' => 'wait' ) );
  await_file( $w207['spec']['barrier'] ); proc_terminate( $w207['proc'], 9 ); finish_worker( $w207, true );
  expire_undo_lease( $undo207 );
  run_worker( array( 'undo_id' => $undo207, 'mode' => 'undo', 'limits' => limits( 5 ) ) );
  eq( undo_row( $job207, $id207 )['state'], U207::UNDONE, '#207 crash recovery UNDONE: ' . $point207 );
  ok( D207::equal( wl207_fresh( $id207 )->get_sale_price( 'edit' ), '90' ), '#207 crash recovery original sale restored' );
  if ( 'UNDO_AFTER_COMMIT_BEFORE_RESPONSE' === $point207 ) { eq( saves( $id207 ) - $before207, 1, '#207 durable Undo adopted with no second save' ); }
 }
}
// Unscheduled active sales: clear returns the shopper price to Regular Price,
// whereas a permitted 100% relative discount really writes numeric zero.
foreach ( array( O207::CLEAR_SALE, O207::SALE_DISCOUNT_PERCENT ) as $type207 ) {
 $f207 = wl207_fixture( $type207, false, '90' ); $id207 = $f207['id'];
 $p207 = wl207_fresh( $id207 ); $p207->set_date_on_sale_from( null ); $p207->set_date_on_sale_to( null ); $p207->save();
 $plan207 = WriteLeash\Woo_Price_Planner::preview( WriteLeash\Price_Selection_Spec::ids( array( $id207 ) ), new O207( $type207, O207::CLEAR_SALE === $type207 ? '' : '100', O207::FIELD_SALE ), new WriteLeash\Safety_Policy( 1000, '100', '100', false, '100' ) );
 $job207 = R207::create_from_plan( $plan207, 1 ); $job207 = R207::approve( (int) $job207['id'], 1 );
 run_worker( array( 'job_id' => (int) $job207['id'], 'mode' => 'apply', 'limits' => limits( 5 ), 'manual' => true ) );
 eq( journal_row( $plan207->data()['plan_id'], $id207 )['state'], 'APPLIED', '#207 active clear/100% discount applied' );
 $target207 = O207::CLEAR_SALE === $type207 ? '' : '0.00';
 eq( journal_row( $plan207->data()['plan_id'], $id207 )['target_price'], $target207, '#207 journal clear blank vs 100% numeric zero' );
 ok( D207::equal( wl207_fresh( $id207 )->get_price( 'edit' ), O207::CLEAR_SALE === $type207 ? '100' : '0' ), '#207 observable active shopper value correct' );
 V207::observe( $plan207, $id207 );
 $op207 = start_undo( (int) $job207['id'] );
 run_worker( array( 'undo_id' => (int) $op207['id'], 'mode' => 'undo', 'limits' => limits( 5 ), 'manual' => true ) );
 eq( undo_row( (int) $job207['id'], $id207 )['state'], U207::UNDONE, '#207 active clear/zero Undo eligible' );
 ok( D207::equal( wl207_fresh( $id207 )->get_price( 'edit' ), '90' ), '#207 Undo restores active sale' );
}
marker( '#207 sale operations: Preview/Plan/Apply/journal/History/CSV/Undo, frozen basis, parent conflicts and SIGKILL recovery' );
