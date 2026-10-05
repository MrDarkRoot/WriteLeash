<?php
/** Disposable real Woo fixture; saves here set up evidence, never execute a plan. */
require __DIR__ . '/assertions.php';
use WriteLeash\Price_Operation as O;
use WriteLeash\Safety_Policy as P;
use WriteLeash\Price_Selection_Spec as S;
use WriteLeash\Woo_Price_Planner as Planner;
use WriteLeash\Product_Price_Snapshot as Snapshot;
use WriteLeash\Price_Store_Context as Context;
use WriteLeash\Change_Plan as Plan;
use WriteLeash\Product_Price_Eligibility as Eligibility;
$wl107_assertions = 0;
// The fixture version must sit inside the supported range; the product never
// pins one exact release.
wl107_equal( \WriteLeash\Free_Support_Contract::woo_supported( WC_VERSION ), true, 'fixture Woo inside supported range' );
wl107_equal( \WriteLeash\Free_Support_Contract::wp_supported( get_bloginfo( 'version' ) ), true, 'fixture WP inside supported range' );
wl107_equal( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress fixture' );
wl107_equal( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, '8.2', 'pinned PHP minor version' );
wp_set_current_user( 1 );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
$category = wp_insert_term( 'WL107 category', 'product_cat' )['term_id'];
$other = wp_insert_term( 'WL107 other', 'product_cat' )['term_id'];
$child = wp_insert_term( 'WL107 child', 'product_cat', array( 'parent' => $category ) )['term_id'];
function wl107_product( string $name, string $price, string $status = 'publish', string $class = 'WC_Product_Simple' ): WC_Product {
	$p = new $class();
	$p->set_name( $name );
	$p->set_status( $status );
	$p->set_regular_price( $price );
	$p->set_sku( $name );
	$p->save();
	return $p;
}
$a = wl107_product( 'WL107-A', '100' );
$b = wl107_product( 'WL107-B', '50' );
$draft = wl107_product( 'WL107-draft', '100', 'draft' );
$private = wl107_product( 'WL107-private', '100', 'private' );
$trash = wl107_product( 'WL107-trash', '100', 'trash' );
$variable = wl107_product( 'WL107-variable', '', 'publish', 'WC_Product_Variable' );
// A real variable product declares its variation attributes on the parent;
// Woo only surfaces variation attribute values declared here.
$color_attr = new WC_Product_Attribute();
$color_attr->set_id( 0 );
$color_attr->set_name( 'Color' );
$color_attr->set_options( array( 'Blue', 'Red' ) );
$color_attr->set_position( 0 );
$color_attr->set_visible( true );
$color_attr->set_variation( true );
$size_attr = new WC_Product_Attribute();
$size_attr->set_id( 0 );
$size_attr->set_name( 'Size' );
$size_attr->set_options( array( 'M', 'L' ) );
$size_attr->set_position( 1 );
$size_attr->set_visible( true );
$size_attr->set_variation( true );
$variable->set_attributes( array( $color_attr, $size_attr ) );
$variable->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $variable->get_id() );
$variation->set_regular_price( '100' );
$variation->set_sku( 'WL107-variation' );
$variation->set_attributes( array( 'color' => 'Blue', 'size' => 'M' ) );
$variation->save();
$variation_two = new WC_Product_Variation();
$variation_two->set_parent_id( $variable->get_id() );
$variation_two->set_regular_price( '50' );
$variation_two->set_attributes( array( 'color' => 'Red', 'size' => 'L' ) );
$variation_two->save();
$grouped = wl107_product( 'WL107-grouped', '', 'publish', 'WC_Product_Grouped' );
$external = wl107_product( 'WL107-external', '100', 'publish', 'WC_Product_External' );
$empty = wl107_product( 'WL107-empty', '' );
$zero = wl107_product( 'WL107-zero', '0' );
$active = wl107_product( 'WL107-active', '100' );
$active->set_sale_price( '80' ); $active->save();
$future = wl107_product( 'WL107-future', '100' );
$future->set_sale_price( '80' ); $future->set_date_on_sale_from( time() + 86400 ); $future->set_date_on_sale_to( time() + 172800 ); $future->save();
$expired = wl107_product( 'WL107-expired', '100' );
$expired->set_sale_price( '80' ); $expired->set_date_on_sale_from( time() - 172800 ); $expired->set_date_on_sale_to( time() - 86400 ); $expired->save();
$date_only = wl107_product( 'WL107-date-only', '100' );
$date_only->set_date_on_sale_from( time() + 86400 ); $date_only->save();
$sale_zero = wl107_product( 'WL107-sale-zero', '100' );
$sale_zero->set_sale_price( '0' ); $sale_zero->save();
// #181 fixtures are created before the no-save planning guard below; the
// corruption itself stays in the dirty-catalog block.
$catalog_clean = wl107_product( 'WL107-catalog-clean', '100' );
$catalog_malformed = wl107_product( 'WL107-catalog-malformed', '100' );
$catalog_unreadable = wl107_product( 'WL107-catalog-unreadable', '100' );
$draft_parent = wl107_product( 'WL107-draft-parent', '', 'draft', 'WC_Product_Variable' );
$draft_variation = new WC_Product_Variation();
$draft_variation->set_parent_id( $draft_parent->get_id() );
$draft_variation->set_regular_price( '100' );
$draft_variation->save();
$empty_parent = wl107_product( 'WL107-empty-variable', '', 'publish', 'WC_Product_Variable' );
$a->set_category_ids( array( $category, $other ) ); $a->save();
$b->set_category_ids( array( $category ) ); $b->save();
$child_product = wl107_product( 'WL107-child', '100' );
$child_product->set_category_ids( array( $child ) ); $child_product->save();
$similar = wl107_product( 'prefix-WL107-A-suffix', '100' );

// Faithful setter/save/read evidence independent of planner; never apply a plan.
foreach ( array( 0 => '100', 1 => '100.0', 2 => '1.01', 3 => '0.001', 6 => '123456789012.123456' ) as $dp => $value ) {
	update_option( 'woocommerce_price_num_decimals', $dp );
	$probe = wl107_product( 'WL107-roundtrip-' . $dp, $value );
	wl107_equal( wc_get_product( $probe->get_id() )->get_regular_price( 'edit' ), $value, 'Woo canonical string roundtrip' );
	wl107_equal( wc_format_decimal( $value, false ), $value, 'Woo formatter false preserves string' );
}
update_option( 'woocommerce_price_num_decimals', 2 );
wl107_equal( wc_format_decimal( '1.005', false ), '1.005', 'false does NOT round to two decimals' );
wl107_marker( 'Woo public setter/save/read roundtrip 0/1/2/3/6; formatter false evidence' );

$ids = array_map( static fn( $p ) => $p->get_id(), array( $a, $b, $draft, $private, $trash, $variable, $variation, $grouped, $external, $empty, $zero, $active, $future, $expired, $date_only, $sale_zero ) );
$before = array();
foreach ( $ids as $id ) { $before[$id] = Snapshot::read( $id, wc_get_product( $id ) )->data(); }
$saves = 0;
$save_guard = static function () use ( &$saves ) { ++$saves; throw new RuntimeException( 'Planning attempted a Woo save' ); };
add_action( 'woocommerce_before_product_object_save', $save_guard );
$policy = new P( 100, '100', '100', true, '10' );
$op = new O( O::DECREASE_PERCENT, '20' );
$plan = Planner::preview( S::ids( array( $b->get_id(), $a->get_id(), $a->get_id() ) ), $op, $policy );
wl107_equal( $plan->item( $a->get_id() )->data()['planned_regular_price'], '80.00', 'A absolute target' );
wl107_equal( $plan->item( $b->get_id() )->data()['planned_regular_price'], '40.00', 'B absolute target' );
wl107_equal( $plan->data()['resolved_product_ids'], array( $a->get_id(), $b->get_id() ), 'unique sorted frozen IDs' );
wl107_equal( $plan->data()['selection']['warnings'], array( 'duplicate_selection' ), 'typed duplicate warning' );
$json = json_decode( $plan->json(), true );
wl107_equal( array_column( $json['items'], 'planned_regular_price' ), array( '80.00', '40.00' ), 'serialized absolute values' );
wl107_equal( $json['operation'], array( 'field' => O::FIELD_REGULAR, 'input' => '20', 'type' => O::DECREASE_PERCENT ), 'canonical operation provenance' );
wl107_equal( $plan->summary()['selected'], 2, 'selected count' );
wl107_equal( $plan->summary()['changing'], 2, 'changing count' );
wl107_equal( $plan->summary()['warning_count'], 2, 'per-item warnings' );
wl107_equal( $plan->summary()['warning_items'], 2, 'warning items' );
wl107_marker( 'normal A/B frozen plan; duplicate selection; absolute JSON targets 80.00/40.00' );
foreach ( array( O::SET => array( '80', '80.00' ), O::INCREASE_FIXED => array( '5', '105.00' ), O::DECREASE_FIXED => array( '5', '95.00' ), O::INCREASE_PERCENT => array( '10', '110.00' ), O::DECREASE_PERCENT => array( '20', '80.00' ) ) as $type => $case ) {
	wl107_equal( Planner::preview( S::ids( array( $a->get_id() ) ), new O( $type, $case[0] ), $policy )->item( $a->get_id() )->data()['planned_regular_price'], $case[1], 'Woo planner five operations ' . $type );
}
foreach ( array( 0 => '2', 1 => '1.5', 2 => '1.50', 3 => '1.500', 6 => '1.500000' ) as $dp => $target ) {
	update_option( 'woocommerce_price_num_decimals', $dp );
	wl107_equal( Planner::preview( S::ids( array( $a->get_id() ) ), new O( O::SET, '1.5' ), $policy )->item( $a->get_id() )->data()['planned_regular_price'], $target, 'Woo planner store precision' );
}
update_option( 'woocommerce_price_num_decimals', 2 );
$persisted = tempnam( sys_get_temp_dir(), 'wl107-plan-' );
try {
	file_put_contents( $persisted, $plan->json() );
	$reload = json_decode( file_get_contents( $persisted ), true );
	wl107_equal( array_column( $reload['items'], 'planned_regular_price' ), array( '80.00', '40.00' ), 'temporary persistence retains absolute strings' );
	wl107_equal( $reload['plan_hash'], $plan->hash(), 'temporary persistence retains fingerprint' );
} finally { unlink( $persisted ); }
wl107_marker( 'five Woo planning operations; store precision matrix; absolute JSON persistence roundtrip' );

$matrix = array(
	$draft->get_id() => 'unsupported_status', $private->get_id() => 'unsupported_status', $trash->get_id() => 'unsupported_status',
	$grouped->get_id() => 'unsupported_product_type', $external->get_id() => 'unsupported_product_type',
	$active->get_id() => 'regular_price_not_above_sale', $future->get_id() => 'regular_price_not_above_sale', $expired->get_id() => 'regular_price_not_above_sale',
	$empty->get_id() => 'empty_regular_price', $zero->get_id() => 'percent_from_zero_undefined', 2147483647 => 'missing_product',
);
$eligibility_text = array( $date_only->get_id() => 'date-only', $sale_zero->get_id() => 'zero sale', $variation->get_id() => 'variation one', $variation_two->get_id() => 'variation two' );
$mixed = Planner::preview( S::ids( array_merge( $ids, array( 2147483647 ) ) ), $op, $policy );
foreach ( $matrix as $id => $reason ) { wl107_equal( $mixed->item( $id )->data()['eligibility']['reason'], $reason, 'eligibility ' . $id ); }
foreach ( $eligibility_text as $id => $label ) {
	wl107_equal( $mixed->item( $id )->data()['result'], 'CHANGING', 'sale configuration no longer excludes ' . $label . ' from eligible price edits' );
}
wl107_equal( $mixed->summary()['eligible'], 6, 'mixed eligible count' );
wl107_equal( $mixed->summary()['unsupported'], 11, 'mixed unsupported count' );
wl107_equal( in_array( $variable->get_id(), $mixed->data()['resolved_product_ids'], true ), false, 'the requested variable parent is replaced by children, never executed itself' );
wl107_equal( in_array( $variation_two->get_id(), $mixed->data()['resolved_product_ids'], true ), true, 'expansion adds unselected sibling variations at preview' );
wl107_equal( $mixed->item( $variation_two->get_id() )->data()['planned_regular_price'], '40.00', 'expanded sibling variation computes its own target' );
wl107_error( static fn() => $mixed->item( $variable->get_id() ), 'unpreviewed_product' );
wl107_equal( wc_get_product( $future->get_id() )->is_on_sale(), false, 'scheduled sale not active yet' );
wl107_equal( wc_get_product( $expired->get_id() )->is_on_sale(), false, 'expired sale not active' );
wl107_equal( $before[$expired->get_id()]['sale_price'], '80', 'expired sale metadata remains configured' );
class WL107_Complex extends WC_Product_Simple {}
$complex = new WL107_Complex( $a->get_id() );
wl107_equal( Eligibility::evaluate( Snapshot::read( $a->get_id(), $complex ), Context::current() )->data()['reason'], 'unsupported_product_type', 'extension subclass excluded' );
wl107_equal( Eligibility::evaluate( Snapshot::read( $a->get_id(), $a ), new Context( 'USD', 2, get_bloginfo( 'version' ), WC_VERSION, false ) )->data()['reason'], 'unsupported_currency_context', 'non-base context' );
wl107_marker( 'published/core simple and variation rules; 11 typed unsupported cases; date-only/zero-sale/variation edits stay eligible; sale-clearing targets refused' );

// #181 dirty catalog: a malformed stored price and a throwing Woo read are
// classified per product while the clean sibling still plans. No repair path
// is exercised and no malformed value is guessed. Fixtures were created above,
// before the no-save planning guard.
update_post_meta( $catalog_malformed->get_id(), '_regular_price', 'not-a-price' ); // Fixture corruption only.
\WriteLeash\Price_Cache_Verifier::invalidate( $catalog_malformed->get_id() );
$catalog_filter = static function ( $class, $type, $post_type, $id ) use ( $catalog_unreadable ) {
	if ( (int) $id === $catalog_unreadable->get_id() ) { throw new RuntimeException( 'test-only unreadable product' ); }
	return $class;
};
add_filter( 'woocommerce_product_class', $catalog_filter, 10, 4 );
try { $catalog_plan = Planner::preview( S::ids( array( $catalog_unreadable->get_id(), $catalog_clean->get_id(), $catalog_malformed->get_id() ) ), $op, $policy ); }
finally { remove_filter( 'woocommerce_product_class', $catalog_filter, 10 ); }
wl107_equal( $catalog_plan->summary()['selected'], 3, 'dirty catalog selection stays complete' );
wl107_equal( $catalog_plan->summary()['changing'], 1, 'one clean product still changes' );
wl107_equal( $catalog_plan->summary()['unsupported'], 2, 'two dirty products skipped' );
wl107_equal( $catalog_plan->item( $catalog_clean->get_id() )->data()['result'], 'CHANGING', 'clean sibling unaffected' );
wl107_equal( $catalog_plan->item( $catalog_malformed->get_id() )->data()['eligibility']['reason'], 'invalid_price', 'malformed stored price typed' );
wl107_equal( $catalog_plan->item( $catalog_malformed->get_id() )->data()['planned_regular_price'], null, 'malformed price never guessed' );
wl107_equal( $catalog_plan->item( $catalog_unreadable->get_id() )->data()['eligibility']['reason'], 'unreadable_product_data', 'throwing read typed unreadable' );
wl107_equal( $catalog_plan->item( $catalog_unreadable->get_id() )->data()['snapshot']['exists'], false, 'unreadable keeps exists false' );
wl107_equal( $catalog_plan->item( $catalog_unreadable->get_id() )->data()['snapshot']['unreadable'], true, 'unreadable flag frozen' );
wl107_equal( $catalog_plan->item( $catalog_unreadable->get_id() )->data()['planned_regular_price'], null, 'unreadable never guessed' );
wl107_marker( 'dirty catalog: clean sibling plans, malformed and unreadable stay skipped' );

$category_plan = Planner::preview( S::category( $category ), $op, $policy );
wl107_equal( $category_plan->data()['resolved_product_ids'], array( $a->get_id(), $b->get_id() ), 'direct category excludes child; multi membership unique' );
$sku_plan = Planner::preview( S::sku( 'WL107-A' ), $op, $policy );
wl107_equal( $sku_plan->data()['resolved_product_ids'], array( $a->get_id() ), 'exact SKU not contains' );
wl107_equal( Planner::preview( S::sku( 'wl107-a' ), $op, $policy )->summary()['selected'], 0, 'case-sensitive exact SKU' );
wl107_equal( Planner::preview( S::sku( 'not-found' ), $op, $policy )->summary()['selected'], 0, 'missing SKU empty selection' );
wl107_equal( Planner::preview( S::sku( 'WL107-variation' ), $op, $policy )->item( $variation->get_id() )->data()['result'], 'CHANGING', 'advanced exact SKU selects that variation as one exact product' );

// #179 variable products: a selected parent freezes its exact variations at
// preview with attribute identity, and individual variations work like simple
// products for both price fields.
$parent_plan = Planner::preview( S::ids( array( $variable->get_id() ) ), $op, $policy );
wl107_equal( $parent_plan->data()['resolved_product_ids'], array( $variation->get_id(), $variation_two->get_id() ), 'requested variable parent resolves to exact variation IDs' );
wl107_equal( $parent_plan->data()['selection']['ids'], array( $variation->get_id(), $variation_two->get_id() ), 'frozen IDS selection holds only the resolved variations' );
wl107_equal( $parent_plan->item( $variation->get_id() )->data()['planned_regular_price'], '80.00', 'variation one regular target' );
wl107_equal( $parent_plan->item( $variation_two->get_id() )->data()['planned_regular_price'], '40.00', 'variation two regular target' );
$variation_snapshot = $parent_plan->item( $variation->get_id() )->data()['snapshot'];
wl107_equal( $variation_snapshot['core_variation'], true, 'variation frozen as a core variation' );
wl107_equal( $variation_snapshot['core_simple'], false, 'variation never claims to be a simple product' );
wl107_equal( $variation_snapshot['parent_id'], $variable->get_id(), 'variation freezes its parent ID' );
wl107_equal( $variation_snapshot['parent_type'], 'variable', 'variation freezes the parent type' );
wl107_equal( $variation_snapshot['variation_label'], 'WL107-variable — Blue / M', 'variation identity carries human-readable attributes' );
$individual_plan = Planner::preview( S::ids( array( $variation_two->get_id() ) ), $op, $policy );
wl107_equal( $individual_plan->data()['resolved_product_ids'], array( $variation_two->get_id() ), 'an individual variation selection stays exactly one variation' );
$variation_sale_plan = Planner::preview( S::ids( array( $variation->get_id() ) ), new O( O::SET, '70', O::FIELD_SALE ), $policy );
wl107_equal( $variation_sale_plan->price_field(), O::FIELD_SALE, 'sale plan on a variation exposes the field' );
wl107_equal( $variation_sale_plan->item( $variation->get_id() )->data()['expected_regular_price'], '', 'first variation sale starts from empty' );
wl107_equal( $variation_sale_plan->item( $variation->get_id() )->data()['planned_regular_price'], '70.00', 'variation sale target is absolute' );
$variation_sale_snapshot = $variation_sale_plan->item( $variation->get_id() )->data()['snapshot'];
wl107_equal( $variation_sale_snapshot['sale_price'], '', 'variation sale snapshot reads the stored sale field' );
$draft_child_plan = Planner::preview( S::ids( array( $draft_variation->get_id() ) ), $op, $policy );
wl107_equal( $draft_child_plan->item( $draft_variation->get_id() )->data()['eligibility']['reason'], 'unsupported_parent_product', 'a variation of an unpublished parent is refused with its own reason' );
$empty_parent_plan = Planner::preview( S::ids( array( $empty_parent->get_id() ) ), $op, $policy );
wl107_equal( $empty_parent_plan->summary()['selected'], 1, 'a variable parent with no children stays one explained item' );
wl107_equal( $empty_parent_plan->item( $empty_parent->get_id() )->data()['eligibility']['reason'], 'unsupported_product_type', 'a variable parent is never eligible itself' );
wl107_marker( 'variable parent expansion, individual variations and field-aware variation plans' );
$woo_include = wc_get_products( array( 'include' => array( $a->get_id(), $b->get_id() ), 'return' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
wl107_equal( $woo_include, array( $a->get_id(), $b->get_id() ), 'Woo include public API evidence' );
wl107_marker( 'explicit IDs/category/exact SKU; Woo include API; missing/case/contains SKU semantics' );

$blocked = Planner::preview( S::ids( array( $a->get_id(), $b->get_id() ) ), $op, new P( 1, '100', '100', true, '10' ) );
wl107_equal( $blocked->data()['policy_result']['state'], 'BLOCKED', 'entire plan max products' );
wl107_equal( $blocked->summary()['blocked'], 2, 'all changing items denied' );
wl107_equal( $blocked->precondition( $a->get_id(), Snapshot::read( $a->get_id(), $a ), Context::current() )['state'], 'BLOCKED', 'clean A also blocked' );
$unchanged = Planner::preview( S::ids( array( $a->get_id() ) ), new O( O::SET, '100' ), new P( 0, '0', '0', true, '0' ) );
wl107_equal( $unchanged->item( $a->get_id() )->data()['result'], 'UNCHANGED', 'unchanged target' );
wl107_equal( $unchanged->summary()['changing'], 0, 'unchanged not counted against cap' );
wl107_equal( $unchanged->data()['policy_result']['state'], 'ALLOW', 'max changing zero allows unchanged' );
$zero_plan = Planner::preview( S::ids( array( $a->get_id(), $b->get_id() ) ), new O( O::SET, '0' ), $policy );
wl107_equal( $zero_plan->data()['policy_result']['state'], 'BLOCKED', 'zero target entire plan block' );
wl107_equal( $zero_plan->item( $a->get_id() )->data()['blockers'], array( 'zero_target_blocked' ), 'zero cap exactly 100 allowed but zero blocked' );
$single_violation = Planner::preview( S::ids( array( $a->get_id(), $b->get_id() ) ), new O( O::DECREASE_FIXED, '50' ), $policy );
wl107_equal( $single_violation->item( $a->get_id() )->data()['blockers'], array(), 'A has no individual blocker' );
wl107_equal( $single_violation->item( $b->get_id() )->data()['blockers'], array( 'zero_target_blocked' ), 'B is the single zero violation' );
wl107_equal( $single_violation->summary()['blocked'], 2, 'one violation blocks both changing items' );
wl107_equal( $single_violation->precondition( $a->get_id(), Snapshot::read( $a->get_id(), $a ), Context::current() )['state'], 'BLOCKED', 'clean A not authorized by B violation' );
$mixed_unchanged = Planner::preview( S::ids( array( $a->get_id(), $b->get_id() ) ), new O( O::SET, '100' ), new P( 1, '100', '100', true, '100' ) );
wl107_equal( $mixed_unchanged->summary()['unchanged'], 1, 'selected two one unchanged' );
wl107_equal( $mixed_unchanged->data()['policy_result']['state'], 'ALLOW', 'two eligible but only one changing within max one' );
$negative = Planner::preview( S::ids( array( $b->get_id() ) ), new O( O::DECREASE_FIXED, '51' ), $policy );
wl107_equal( $negative->item( $b->get_id() )->data()['eligibility']['reason'], 'negative_target', 'negative typed unsupported no clamp' );
wl107_equal( Planner::preview( S::ids( array( $zero->get_id() ) ), new O( O::SET, '0' ), $policy )->item( $zero->get_id() )->data()['result'], 'UNCHANGED', 'numeric zero eligible for SET' );
wl107_marker( 'whole-plan BLOCKED before save; max uses changing only; unchanged, negative and zero rules' );

$snapshots = \WriteLeash\Product_Price_Selector::resolve( S::ids( array( $a->get_id(), $b->get_id() ) ) );
$make = static fn( $selection, $operation, $p, $context, $ss, $actor = 1 ) => Plan::create( 'test-plan', '2026-09-30T00:00:00Z', $actor, $context, $selection, $operation, $p, $ss );
$sel = S::ids( array( $a->get_id(), $b->get_id() ) );
$one = $make( $sel, $op, $policy, Context::current(), $snapshots );
$two = Plan::create( 'other-identity', '2026-09-30T01:00:00Z', 1, Context::current(), S::ids( array( $b->get_id(), $a->get_id() ) ), new O( O::DECREASE_PERCENT, '20.000000' ), $policy, array_reverse( $snapshots ) );
wl107_equal( $one->hash(), $two->hash(), 'same canonical material independent identity/time/order' );
foreach ( array(
	$make( $sel, new O( O::DECREASE_PERCENT, '19' ), $policy, Context::current(), $snapshots ),
	$make( $sel, new O( O::SET, '80' ), $policy, Context::current(), $snapshots ),
	$make( $sel, $op, new P( 99, '100', '100', true, '10' ), Context::current(), $snapshots ),
	$make( $sel, $op, $policy, new Context( 'EUR', 2, get_bloginfo( 'version' ), WC_VERSION ), $snapshots ),
	$make( $sel, $op, $policy, new Context( 'USD', 3, get_bloginfo( 'version' ), WC_VERSION ), $snapshots ),
	$make( $sel, $op, $policy, Context::current(), $snapshots, 2 ),
	$make( S::category( $category ), $op, $policy, Context::current(), $snapshots ),
	$make( S::ids( array( $a->get_id() ) ), $op, $policy, Context::current(), array( $snapshots[0] ) ),
) as $different ) { wl107_equal( $one->hash() !== $different->hash(), true, 'material change new hash' ); }
$copy = $one->data(); $copy['items'][0]['planned_regular_price'] = '1';
wl107_equal( $one->item( $a->get_id() )->data()['planned_regular_price'], '80.00', 'DTO output copy cannot mutate plan' );
wl107_error( static function () use ( $one ) { $one->operation = new O( O::SET, '1' ); }, 'immutable_plan' );
wl107_error( static function () use ( $one ) { $one->policy = new P( 0, '0', '0', true, '0' ); }, 'immutable_plan' );
wl107_error( static function () use ( $one ) { $one->selection = S::ids( array( 1 ) ); }, 'immutable_plan' );
wl107_equal( count( $one->preview_page( 0, 1 )['items'] ), 1, 'bounded preview' );
wl107_equal( $one->preview_page( 0, 1 )['next_offset'], 1, 'preview next offset' );
wl107_equal( $one->preview_page( 1, 1 )['next_offset'], null, 'preview last page' );
wl107_equal( $one->preview_page( 500, 1 )['items'], array(), 'empty out-of-range page' );
wl107_error( static fn() => $one->preview_page( 0, 101 ), 'invalid_preview_page' );
wl107_error( static fn() => $one->item( 99999 ), 'unpreviewed_product' );
wl107_marker( 'immutable DTO/JSON; deterministic hash identity exclusion + eight material changes; pagination' );

$current = Snapshot::read( $a->get_id(), $a );
wl107_equal( $one->precondition( $a->get_id(), $current, Context::current() )['state'], 'MATCH', 'matching precondition' );
// Routine supported patch drift is not a product-state change: bump the
// trailing component of each frozen version and require MATCH.
$bump = static function ( string $version ): string {
	$parts = explode( '.', $version );
	$parts[ count( $parts ) - 1 ] = (string) ( (int) end( $parts ) + 1 );
	return implode( '.', $parts );
};
wl107_equal( $one->precondition( $a->get_id(), $current, new Context( 'USD', 2, $bump( get_bloginfo( 'version' ) ), WC_VERSION ) )['state'], 'MATCH', 'routine WordPress patch drift' );
wl107_equal( $one->precondition( $a->get_id(), $current, new Context( 'USD', 2, get_bloginfo( 'version' ), $bump( WC_VERSION ) ) )['state'], 'MATCH', 'routine WooCommerce patch drift' );
foreach ( array(
	array( 'currency_context_changed', new Context( 'EUR', 2, get_bloginfo( 'version' ), WC_VERSION ) ),
	array( 'price_decimals_changed', new Context( 'USD', 3, get_bloginfo( 'version' ), WC_VERSION ) ),
	array( 'software_version_changed', new Context( 'USD', 2, 'other-version', WC_VERSION ) ),
	// Below the supported WooCommerce floor: not routine drift.
	array( 'software_version_changed', new Context( 'USD', 2, get_bloginfo( 'version' ), '9.9.9' ) ),
) as $case ) {
	wl107_equal( $one->precondition( $a->get_id(), $current, $case[1] ), array( 'state' => 'CONFLICT', 'reasons' => array( $case[0] ) ), 'store drift' );
}
$a->set_regular_price( '110' );
wl107_equal( $one->precondition( $a->get_id(), Snapshot::read( $a->get_id(), $a ), Context::current() )['reasons'], array( 'regular_price_changed' ), 'price drift no recompute' );
wl107_equal( $one->item( $a->get_id() )->data()['planned_regular_price'], '80.00', 'drift preserves absolute target' );
$a->set_regular_price( '100.00' );
wl107_equal( $one->precondition( $a->get_id(), Snapshot::read( $a->get_id(), $a ), Context::current() )['state'], 'MATCH', 'numeric equivalent stored format' );
$a->set_status( 'draft' );
wl107_equal( $one->precondition( $a->get_id(), Snapshot::read( $a->get_id(), $a ), Context::current() )['reasons'], array( 'product_status_changed' ), 'status drift' );
$a->set_status( 'publish' ); $a->set_sale_price( '90' );
wl107_equal( $one->precondition( $a->get_id(), Snapshot::read( $a->get_id(), $a ), Context::current() )['state'], 'MATCH', 'sale price drift is preserved by a regular-price plan' );
$a->set_sale_price( '' ); $a->set_date_on_sale_to( time() + 86400 );
wl107_equal( $one->precondition( $a->get_id(), Snapshot::read( $a->get_id(), $a ), Context::current() )['state'], 'MATCH', 'sale schedule drift is preserved by a regular-price plan' );
$a->set_date_on_sale_to( null );
$changed_type = new WC_Product_External( $a->get_id() );
wl107_equal( $one->precondition( $a->get_id(), Snapshot::read( $a->get_id(), $changed_type ), Context::current() )['reasons'], array( 'product_type_changed' ), 'type drift' );
$a->set_category_ids( array( $other ) );
wl107_equal( $category_plan->precondition( $a->get_id(), Snapshot::read( $a->get_id(), $a ), Context::current() )['state'], 'MATCH', 'category drift provenance only' );
wl107_equal( $category_plan->data()['resolved_product_ids'], array( $a->get_id(), $b->get_id() ), 'category remains frozen' );
wl107_marker( 'CONFLICT on price/type/status/currency/decimals/out-of-range versions; sale drift preserved; in-range WP/Woo patch drift MATCH; category provenance; no target recomputation' );

wl107_equal( $saves, 0, 'zero Woo saves during ALL planning calls' );
foreach ( $ids as $id ) { wl107_equal( Snapshot::read( $id, wc_get_product( $id ) )->data(), $before[$id], 'persisted product untouched ' . $id ); }
remove_action( 'woocommerce_before_product_object_save', $save_guard );
wl107_marker( 'planning Woo saves=0; all 16 persisted product snapshots unchanged' );

// Real persisted drift between preview and later precondition, outside planning.
$a = wc_get_product( $a->get_id() ); $a->set_regular_price( '110' ); $a->save();
wl107_equal( $one->precondition( $a->get_id(), Snapshot::read( $a->get_id(), wc_get_product( $a->get_id() ) ), Context::current() )['state'], 'CONFLICT', 'persisted external edit' );
wl107_equal( $make( $sel, $op, $policy, Context::current(), \WriteLeash\Product_Price_Selector::resolve( $sel ) )->hash() !== $one->hash(), true, 'expected price change hash' );
$b->set_category_ids( array( $other ) ); $b->save();
wl107_equal( $category_plan->data()['resolved_product_ids'], array( $a->get_id(), $b->get_id() ), 'persisted category membership cannot expand/reselect frozen plan' );

// Woo normally rejects duplicate SKU via its public setter. Simulate an unexpected
// legacy duplicate through the public metadata API (fixture only, never price SQL).
update_post_meta( $similar->get_id(), '_sku', 'WL107-A' );
wl107_error( static fn() => Planner::preview( S::sku( 'WL107-A' ), $op, $policy ), 'ambiguous_sku' );
wl107_error( static fn() => Planner::preview( S::category( 2147483647 ), $op, $policy ), 'invalid_category' );
$overflow_query = static fn( $posts ) => array_fill( 0, 1001, $posts[0] );
add_filter( 'posts_results', $overflow_query );
try { wl107_error( static fn() => Planner::preview( $sel, $op, $policy ), 'selection_limit_exceeded' ); }
finally { remove_filter( 'posts_results', $overflow_query ); }
foreach ( array( array( '1' ), array( 0 ), array( -1 ), array() ) as $bad ) { wl107_error( static fn() => S::ids( $bad ), ! $bad ? 'invalid_selection_size' : 'invalid_product_id' ); }
wp_set_current_user( 0 );
wl107_error( static fn() => Planner::preview( $sel, $op, $policy ), 'permission_denied' );
wl107_marker( 'persisted drift; unexpected duplicate SKU fails closed; validation and capability rejection' );
echo '#107 fixture: WordPress ' . get_bloginfo( 'version' ) . '; WooCommerce ' . WC_VERSION . '; PHP ' . PHP_VERSION . "; planning complete, no executor\n";
