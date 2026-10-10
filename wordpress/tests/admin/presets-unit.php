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
$config230 = Config230::capture( array_merge( $base230, array( 'nonce' => 'secret', 'plan_hash' => 'hash', 'job' => 'job', 'source_job' => 'job', 'permission_grant' => true ) ) );
wl107_equal( array_keys( $config230 ), array( 'schema', 'selection', 'operation', 'safety' ), 'allowlist drops all execution authority' );
foreach ( array( 'plan_hash' => 'x', 'nonce' => 'x', 'job' => 'x', 'targets' => array(), 'schema' => 2 ) as $key230 => $value230 ) {
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
