<?php
require __DIR__ . '/../free/unit.php';
require __DIR__ . '/../../writeleash/includes/free/class-free-admin.php';
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value ) { return json_encode( $value ); } }
use WriteLeash\Price_Preset_Configuration as Config230;
use WriteLeash\Free_Admin as Admin230;
use WriteLeash\Price_Operation as Op230;
$base230 = array( 'selector' => 'manual_ids', 'ids' => '12,34', 'operation' => Op230::SET, 'amount' => '80', 'max_products' => '1000', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '20' );
foreach ( array(
 array( 'selector' => 'ids', 'picker_present' => '1', 'product_ids' => array( '34', '12', '34' ) ),
 array( 'selector' => 'category', 'category' => '123' ),
 array( 'selector' => 'category', 'category' => '123', 'include_subcategories' => '1' ),
 array( 'selector' => 'sku', 'sku' => 'Exact-SKU-230' ), array(),
) as $selection230 ) {
 foreach ( array( Op230::SET, Op230::INCREASE_FIXED, Op230::DECREASE_FIXED, Op230::INCREASE_PERCENT, Op230::DECREASE_PERCENT, Op230::CLEAR_SALE, Op230::SALE_DISCOUNT_PERCENT ) as $op230 ) {
  foreach ( array( 'regular_price', 'sale_price' ) as $field230 ) {
   if ( in_array( $op230, array( Op230::CLEAR_SALE, Op230::SALE_DISCOUNT_PERCENT ), true ) && 'regular_price' === $field230 ) { continue; }
   foreach ( array( 'default', '99', '95', '90', 'whole' ) as $ending230 ) {
    foreach ( array( null, '1' ) as $zero230 ) {
     $form230 = array_merge( $base230, $selection230, array( 'operation' => $op230, 'price_field' => $field230, 'amount' => Op230::CLEAR_SALE === $op230 ? '' : '20.000000', 'ending' => $ending230, 'block_zero' => $zero230 ) );
     $config230 = Config230::capture( $form230 ); $loaded230 = Config230::form( $config230 );
     wl107_equal( Config230::capture( $loaded230 ), $config230, 'all selections/operations/fields/endings/safety survive editable mapping' );
     $loaded230['amount'] = Op230::CLEAR_SALE === $op230 ? '' : '10';
     Admin230::build_operation( $loaded230 );
    }
   }
  }
 }
}
foreach ( array(
 array(),
 array( 'range_enabled' => '1', 'range_min' => '0' ),
 array( 'range_enabled' => '1', 'range_max' => '150.000001' ),
 array( 'range_enabled' => '1', 'range_min' => '20.000001', 'range_max' => '150.000001' ),
 array( 'range_enabled' => '1', 'range_min' => '0', 'range_max' => '0', 'range_basis' => 'sale_price' ),
) as $range230 ) {
 $input230 = array_merge( $base230, $range230 );
 $range_config230 = Config230::capture( $input230 );
 $range_form230 = Config230::form( $range_config230 );
 wl107_equal( Admin230::build_selection( $range_form230 )->data(), Admin230::build_selection( $input230 )->data(), 'range membership predicate survives persistence exactly' );
 $range_form230['price_field'] = 'sale_price';
 wl107_equal( Admin230::build_price_range( $range_form230 )->data(), Admin230::build_price_range( $input230 )->data(), 'explicit range basis independent of edited operation field' );
}
foreach ( array( array( 'range_min' => '1,00' ), array( 'range_min' => '1e2' ), array( 'range_min' => '2', 'range_max' => '1' ) ) as $range230 ) {
 wl107_error( static fn() => Config230::capture( array_merge( $base230, array( 'range_enabled' => '1' ), $range230 ) ), 'invalid_price_range' );
}
$config230 = Config230::capture( array_merge( $base230, array( 'nonce' => 'secret', 'plan_hash' => 'hash', 'job' => 'job', 'source_job' => 'job', 'permission_grant' => true ) ) );
wl107_equal( array_keys( $config230 ), array( 'schema', 'selection', 'operation', 'safety' ), 'allowlist drops all execution authority' );
$legacy230 = $config230; $legacy230['schema'] = 1; unset( $legacy230['selection']['price_range'] );
$legacy_form230 = Config230::form( $legacy230 );
wl107_equal( Admin230::build_price_range( $legacy_form230 )->data()['enabled'], false, 'schema 1 remains unfiltered' );
$legacy_round230 = Config230::capture( $legacy_form230 ); $legacy_round230['schema'] = 1; unset( $legacy_round230['selection']['price_range'] );
wl107_equal( $legacy_round230, $legacy230, 'schema 1 original meaning unchanged' );
foreach ( array(
 array( 'enabled' => '1', 'min' => '0', 'max' => null, 'basis' => 'regular_price' ),
 array( 'enabled' => true, 'min' => '1e2', 'max' => null, 'basis' => 'regular_price' ),
 array( 'enabled' => true, 'min' => '2', 'max' => '1', 'basis' => 'regular_price' ),
 array( 'enabled' => true, 'min' => 0, 'max' => null, 'basis' => 'regular_price' ),
 array( 'enabled' => false, 'min' => '0', 'max' => null, 'basis' => 'sale_price' ),
 array( 'enabled' => true, 'min' => '0', 'max' => null, 'basis' => 'unknown' ),
 array( 'enabled' => true, 'min' => '00.00', 'max' => null, 'basis' => 'regular_price' ),
) as $range230 ) {
 $bad230 = $config230; $bad230['selection']['price_range'] = $range230;
 wl107_error( static fn() => Config230::form( $bad230 ), 'invalid_preset' );
}
$bad230 = $config230; unset( $bad230['selection']['price_range'] );
wl107_error( static fn() => Config230::form( $bad230 ), 'invalid_preset' );
foreach ( array( 'selection_refinement', 'excluded_ids', 'candidate_ids', 'refused_ids', 'source_selection' ) as $key230 ) {
 wl107_error( static fn() => Config230::capture( array_merge( $base230, array( $key230 => array() ) ) ), 'invalid_preset' );
}
foreach ( array( 'plan_hash' => 'x', 'nonce' => 'x', 'job' => 'x', 'targets' => array(), 'schema' => 3 ) as $key230 => $value230 ) {
 $bad230 = $config230; $bad230[$key230] = $value230;
 wl107_error( static fn() => Config230::form( $bad230 ), 'invalid_preset' );
}
$bad230 = $config230; $bad230['selection']['exclusions'] = array( 12 );
wl107_error( static fn() => Config230::form( $bad230 ), 'invalid_preset' );
$bad230 = $config230; $bad230['selection']['ids'][0] = '12';
wl107_error( static fn() => Config230::form( $bad230 ), 'invalid_preset' );
foreach ( array( '', '<script>', "bad\nname", str_repeat( 'a', 101 ), array(), null ) as $bad230 ) {
 wl107_error( static fn() => WriteLeash\Price_Preset_Repository::name( $bad230 ), 'invalid_preset_name' );
}
$form230 = array_merge( $base230, array( 'ids' => implode( ',', range( 1, 1000 ) ) ) );
wl107_equal( count( Config230::capture( $form230 )['selection']['ids'] ), 1000, '1000 configuration boundary' );
$form230['ids'] .= ',1001';
try { Config230::capture( $form230 ); throw new RuntimeException( '1001 accepted' ); }
catch ( WriteLeash\Free_Job_Limit_Error $error ) { wl107_equal( $error->selected(), 1001, '1001 rejected before persistence' ); }
wl107_marker( '#230 versioned form mapping, authority exclusion, tamper/size/type rejection and boundaries' );
