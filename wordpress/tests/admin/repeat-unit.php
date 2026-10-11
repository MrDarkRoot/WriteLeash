<?php
// #232 Repeat-as-new-Preview prefill adapter: verified plan material maps to
// editable form defaults, and every unsupported shape refuses explicitly.
require __DIR__ . '/../free/unit.php';
require __DIR__ . '/../../writeleash/includes/free/class-free-admin.php';
use WriteLeash\Free_Admin as A;
use WriteLeash\Price_Operation as O;
use WriteLeash\Price_Range_Filter as R;
use WriteLeash\Price_Selection_Spec as S;
use WriteLeash\Safety_Policy as P;
use WriteLeash\Price_Store_Context as C;
use WriteLeash\Change_Plan as Plan;

$context = new C( 'USD', 2, '7.1.2', '11.1.2' );
$snapshot = static fn( $id ) => \WriteLeash\Product_Price_Snapshot::read( $id, new WC_Product_Simple( array( 'id' => $id, 'regular_price' => '100.00' ) ) );

// Exact-ID history prefills the reviewed IDs as editable advanced manual IDs.
$values = A::repeat_form_values( $thousand_plan->data() );
wl107_equal( $values['selector'], 'manual_ids', 'ids repeat as manual ids' );
wl107_equal( $values['ids'], implode( ',', range( 1, 1000 ) ), 'reviewed ids preserved in order' );
wl107_equal( $values['operation'], 'SET', 'operation suggestion' );
wl107_equal( $values['amount'], '80', 'amount suggestion' );
wl107_equal( $values['price_field'], 'regular_price', 'price field suggestion' );
wl107_equal( $values['ending'], 'default', 'absent ending defaults' );
wl107_equal( $values['max_products'], '1000', 'safety limit suggestion' );
wl107_equal( $values['max_increase'], '100', 'increase cap suggestion' );
wl107_equal( $values['block_zero'] ?? '', '', 'unchecked block-zero stays unchecked' );
wl107_equal( $values['range_enabled'] ?? '', '', 'disabled range stays disabled' );

// Category history restores scope, an enabled range, ending and safety flags.
$range = R::from_inputs( '1', '10', '50', 'sale_price', 'regular_price' );
$category_plan = Plan::create( 'wl232-category', gmdate( 'Y-m-d\TH:i:s\Z' ), 1, $context, S::category( 7, true, $range ), new O( O::DECREASE_FIXED, '5', O::FIELD_REGULAR, '99' ), new P( 10, '20', '30', true, '5' ), array( $snapshot( 11 ), $snapshot( 12 ) ) );
$values = A::repeat_form_values( $category_plan->data() );
wl107_equal( $values['selector'], 'category', 'category selector restored' );
wl107_equal( $values['category'], '7', 'category term restored' );
wl107_equal( $values['include_subcategories'], '1', 'nested scope restored' );
wl107_equal( $values['range_enabled'], '1', 'range enabled restored' );
wl107_equal( $values['range_min'], '10', 'range minimum restored' );
wl107_equal( $values['range_max'], '50', 'range maximum restored' );
wl107_equal( $values['range_basis'], 'sale_price', 'range basis independent of target field' );
wl107_equal( $values['ending'], '99', 'price ending restored' );
wl107_equal( $values['block_zero'], '1', 'block-zero restored' );

// SKU history restores the exact intended SKU for current-catalog resolution.
$sku_plan = Plan::create( 'wl232-sku', gmdate( 'Y-m-d\TH:i:s\Z' ), 1, $context, S::sku( 'REPEAT-1', R::disabled() ), new O( O::INCREASE_PERCENT, '5', O::FIELD_SALE ), new P( 1000, '50', '50', false, '20' ), array( $snapshot( 21 ) ) );
$values = A::repeat_form_values( $sku_plan->data() );
wl107_equal( $values['selector'], 'sku', 'sku selector restored' );
wl107_equal( $values['sku'], 'REPEAT-1', 'exact sku restored' );
wl107_equal( $values['price_field'], 'sale_price', 'sale field restored' );

// Clear Sale maps its blank input without inventing an amount.
$clear_plan = Plan::create( 'wl232-clear', gmdate( 'Y-m-d\TH:i:s\Z' ), 1, $context, S::ids( array( 31 ) ), new O( O::CLEAR_SALE, '', O::FIELD_SALE ), new P( 1000, '50', '50', false, '20' ), array( $snapshot( 31 ) ) );
$values = A::repeat_form_values( $clear_plan->data() );
wl107_equal( $values['operation'], 'CLEAR_SALE', 'clear operation restored' );
wl107_equal( $values['amount'], '', 'clear keeps a blank amount' );

// Legacy material without field/ending keys defaults exactly like hydration.
$legacy = $category_plan->data();
unset( $legacy['operation']['field'], $legacy['operation']['ending'] );
$values = A::repeat_form_values( $legacy );
wl107_equal( $values['price_field'], 'regular_price', 'legacy field defaults to regular' );
wl107_equal( $values['ending'], 'default', 'legacy ending defaults' );

// Stored plans survive a canonical-JSON round trip (key order normalized):
// the adapter must accept rehydrated material, not just live objects.
$rehydrated = \WriteLeash\Change_Plan::hydrate( json_decode( $category_plan->json(), true ) )->data();
wl107_equal( A::repeat_form_values( $rehydrated )['category'], '7', 'rehydrated category maps' );
$rehydrated_ids = \WriteLeash\Change_Plan::hydrate( json_decode( $thousand_plan->json(), true ) )->data();
wl107_equal( A::repeat_form_values( $rehydrated_ids )['selector'], 'manual_ids', 'rehydrated ids map' );
wl107_equal( A::repeat_form_values( $rehydrated_ids )['ids'], implode( ',', range( 1, 1000 ) ), 'rehydrated ids preserved' );

// Unsupported shapes refuse explicitly; never a silent mutating fallback.
$base = $category_plan->data();
wl107_error( static fn() => A::repeat_form_values( array() ), 'repeat_unsupported' );
$bad = $base; $bad['selection'] = array( 'type' => 'GROUP', 'ids' => array( 1 ) );
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; $bad['selection']['excluded_ids'] = array( 12 );
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; $bad['selection'] = array( 'type' => 'IDS', 'ids' => array(), 'warnings' => array() );
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; $bad['operation'] = array( 'type' => 'PERCENT', 'input' => '5' );
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; $bad['operation']['type'] = 'SET'; $bad['operation']['base'] = 'sale';
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; $bad['operation']['field'] = 'cost_price';
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; $bad['operation']['ending'] = '42';
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; $bad['operation'] = array( 'type' => 'CLEAR_SALE', 'input' => '5', 'field' => 'sale_price' );
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; $bad['operation'] = array( 'type' => 'SET', 'input' => 'invalid', 'field' => 'regular_price' );
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; $bad['selection']['price_range'] = array( 'enabled' => true, 'min' => '50', 'max' => '10', 'basis' => 'regular_price' );
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; $bad['selection']['price_range'] = array( 'enabled' => 'yes' );
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; unset( $bad['policy_snapshot']['block_zero'] );
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; $bad['policy_snapshot']['max_products_changed'] = '10';
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
$bad = $base; $bad['policy_snapshot']['extra_cap'] = '1';
wl107_error( static fn() => A::repeat_form_values( $bad ), 'repeat_unsupported' );
wl107_marker( '#232 repeat prefill maps selection/range/operation/safety and refuses unsupported shapes' );
