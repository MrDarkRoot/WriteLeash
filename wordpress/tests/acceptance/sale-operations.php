<?php
// Included after variations.php's real HTTP journey; reuse its authenticated
// Preview/Approve/Resume/Undo helpers and independent database assertions.
// Runtime evidence, not a syntax or simulated HTTP check.
wp_set_current_user( 1 );
$s207_simple = new WC_Product_Simple();
$s207_simple->set_name( 'WL207-HTTP-simple' ); $s207_simple->set_status( 'publish' );
$s207_simple->set_regular_price( '100' ); $s207_simple->set_sale_price( '90' );
$s207_simple->set_date_on_sale_from( time() + 86400 ); $s207_simple->set_date_on_sale_to( time() + 172800 ); $s207_simple->save();
$s207_parent = new WC_Product_Variable(); $s207_parent->set_name( 'WL207-HTTP-parent' ); $s207_parent->set_status( 'publish' ); $s207_parent->save();
$s207_child = new WC_Product_Variation(); $s207_child->set_parent_id( $s207_parent->get_id() ); $s207_child->set_status( 'publish' ); $s207_child->set_regular_price( '250' ); $s207_child->save();
WC_Product_Variable::sync( $s207_parent );
$s207_ids = array( $s207_simple->get_id(), $s207_child->get_id() );
$s207_report = array( 'git_sha' => getenv( 'WL112_SHA' ), 'wp' => get_bloginfo( 'version' ), 'woo' => WC_VERSION, 'php' => PHP_VERSION, 'db' => $wpdb->get_var( 'SELECT VERSION()' ), 'cache' => wp_using_ext_object_cache() ? 'persistent' : 'default' );
foreach ( array( 'SALE_DISCOUNT_PERCENT', 'CLEAR_SALE' ) as $s207_type ) {
 if ( 'CLEAR_SALE' === $s207_type ) {
  $s207_child = WriteLeash\Product_Price_Snapshot::fresh_product( $s207_child->get_id() ); $s207_child->set_sale_price( '200' ); $s207_child->save(); WC_Product_Variable::sync( $s207_parent );
 }
 $s207_home = wl112_get( $base );
 $s207_fields = vr_fields( $s207_home['body'], array( 'selector' => 'ids', 'picker_present' => '0', 'ids' => implode( ',', $s207_ids ), 'price_field' => 'sale_price', 'operation' => $s207_type, 'amount' => 'CLEAR_SALE' === $s207_type ? '' : '20', 'max_products' => '1000', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '100' ) );
 if ( 'CLEAR_SALE' === $s207_type ) { unset( $s207_fields['amount'] ); }
 $s207_run = vr_preview( $s207_fields ); $s207_plan = Repo::hydrate_plan( $s207_run['job'] );
 $s207_targets = array( 'CLEAR_SALE' === $s207_type ? '' : '80.00', 'CLEAR_SALE' === $s207_type ? '' : '200.00' );
 $s207_before = array();
 foreach ( $s207_ids as $s207_i => $s207_id ) {
  $s207_before[$s207_id] = WriteLeash\Product_Price_Snapshot::read( $s207_id, WriteLeash\Product_Price_Snapshot::fresh_product( $s207_id ) )->data();
  wl112_assert( $s207_plan->item( $s207_id )->data()['planned_regular_price'] === $s207_targets[$s207_i], '#207 HTTP reviewed final target' );
 }
 if ( 'CLEAR_SALE' === $s207_type ) { wl112_assert( str_contains( $s207_run['preview']['body'], 'Blank (no sale price)' ), '#207 HTTP blank preview unambiguous' ); }
 $s207_url = vr_run_to_terminal( (int) $s207_run['job']['id'], $s207_run['url'] );
 wl112_assert( 2 === Repo::counts( (int) $s207_run['job']['id'] )['applied'], '#207 HTTP both sale targets applied' );
 foreach ( $s207_ids as $s207_i => $s207_id ) {
  V::observe( $s207_plan, $s207_id );
  $s207_after = WriteLeash\Product_Price_Snapshot::read( $s207_id, WriteLeash\Product_Price_Snapshot::fresh_product( $s207_id ) )->data();
  wl112_assert( D::equal( $s207_after['sale_price'], $s207_targets[$s207_i] ), '#207 HTTP Apply equals Preview' );
  wl112_assert( $s207_after['regular_price'] === $s207_before[$s207_id]['regular_price'], '#207 HTTP regular preserved' );
  wl112_assert( array( $s207_after['sale_from'], $s207_after['sale_to'] ) === array( $s207_before[$s207_id]['sale_from'], $s207_before[$s207_id]['sale_to'] ), '#207 HTTP sale dates preserved' );
 }
 if ( 'CLEAR_SALE' === $s207_type ) { wl112_assert( str_contains( wl112_get( $s207_url )['body'], 'Blank (no sale price)' ), '#207 HTTP result/history blank unambiguous' ); }
 $s207_undo = vr_undo_to_terminal( (int) $s207_run['job']['id'], $s207_url );
 wl112_assert( 2 === (int) $s207_undo['undone'], '#207 HTTP eligible sale Undo' );
 foreach ( $s207_ids as $s207_id ) {
  $s207_restored = WriteLeash\Product_Price_Snapshot::read( $s207_id, WriteLeash\Product_Price_Snapshot::fresh_product( $s207_id ) )->data();
  wl112_assert( D::equal( $s207_restored['sale_price'], $s207_before[$s207_id]['sale_price'] ), '#207 HTTP original sale restored' );
 }
 $s207_report[$s207_type] = array( 'applied' => 2, 'undone' => 2, 'outcome' => 'PASS' );
}
file_put_contents( '/evidence/' . DB_HOST . '-sale-operations.json', json_encode( $s207_report, JSON_PRETTY_PRINT ) . "\n" );
echo '#207 real authenticated HTTP simple/variation sale Preview/Apply/Undo and blank History PASS' . "\n";
