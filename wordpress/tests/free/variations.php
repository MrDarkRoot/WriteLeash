<?php
/**
 * #179 pure-PHP variation domain harness: variable parents, core variations
 * and their Woo-documented price semantics without a database.
 *
 * Proves: variation snapshot/eligibility (including parent requirements and
 * extension refusals), preview-time parent expansion and the exact freeze,
 * regular/sale target computation on variations, field-scoped preconditions,
 * field-scoped Undo provenance and the parent-range computation Woo's own
 * sync/lookup performs.
 */
namespace {
	define( 'ABSPATH', __DIR__ );
	require __DIR__ . '/assertions.php';
	require __DIR__ . '/../../writeleash/includes/free/class-price-decimal.php';
	require __DIR__ . '/../../writeleash/includes/free/class-price-operation.php';
	require __DIR__ . '/../../writeleash/includes/free/class-safety-policy.php';
	require __DIR__ . '/../../writeleash/includes/free/class-free-support-contract.php';
	require __DIR__ . '/../../writeleash/includes/free/class-product-snapshot.php';
	require __DIR__ . '/../../writeleash/includes/free/class-product-selector.php';
	require __DIR__ . '/../../writeleash/includes/free/class-change-plan.php';
	require __DIR__ . '/../../writeleash/includes/free/class-undo-state.php';
	require __DIR__ . '/../../writeleash/includes/free/class-undo-fingerprint.php';
	class WC_DateTime {
		private int $timestamp;
		public function __construct( int $timestamp ) { $this->timestamp = $timestamp; }
		public function getTimestamp(): int { return $this->timestamp; }
	}
	class WC_Product {
		protected int $wl_id;
		protected array $wl_values;
		public function __construct( int $id = 0, array $values = array() ) { $this->wl_id = $id; $this->wl_values = $values; }
		public function get_id() { return $this->wl_id; }
		public function get_type() { return $this->wl_values['type'] ?? 'simple'; }
		public function get_status( $context = 'view' ) { return $this->wl_values['status'] ?? 'publish'; }
		public function get_name( $context = 'view' ) { return $this->wl_values['name'] ?? ( 'Product ' . $this->wl_id ); }
		public function get_sku( $context = 'view' ) { return $this->wl_values['sku'] ?? ''; }
		public function get_regular_price( $context = 'view' ) { return $this->wl_values['regular_price'] ?? ''; }
		public function get_sale_price( $context = 'view' ) { return $this->wl_values['sale_price'] ?? ''; }
		public function get_category_ids( $context = 'view' ) { return $this->wl_values['category_ids'] ?? array(); }
		public function get_date_on_sale_from( $context = 'view' ) { return $this->wl_values['sale_from'] ?? null; }
		public function get_date_on_sale_to( $context = 'view' ) { return $this->wl_values['sale_to'] ?? null; }
		public function get_parent_id( $context = 'view' ) { return (int) ( $this->wl_values['parent_id'] ?? 0 ); }
		public function get_attributes( $context = 'view' ) { return $this->wl_values['attributes'] ?? array(); }
		public function get_children() { return $this->wl_values['children'] ?? array(); }
		public function get_visible_children() { return $this->get_children(); }
		public function wl_set_value( string $key, $value ): void { $this->wl_values[ $key ] = $value; }
	}
	class WC_Product_Simple extends WC_Product {}
	class WC_Product_Variable extends WC_Product {
		public function get_type() { return 'variable'; }
	}
	class WC_Product_Variation extends WC_Product {
		public function get_type() { return 'variation'; }
	}
	class WP_Term {
		public $term_id;
		public $name;
		public function __construct( int $term_id, string $name ) { $this->term_id = $term_id; $this->name = $name; }
	}
	class WP_Query {
		public $posts = array();
		public function __construct( $args = array() ) {
			$registry = $GLOBALS['wl179_products'];
			if ( ! empty( $args['post__in'] ) ) {
				$candidates = array_map( 'intval', (array) $args['post__in'] );
			} else {
				$candidates = array_keys( $registry );
				if ( ! empty( $args['tax_query'][0]['terms'] ) ) {
					$term_id = (int) $args['tax_query'][0]['terms'][0];
					$candidates = array_filter( $candidates, static fn( $id ) => in_array( $term_id, (array) ( $GLOBALS['wl179_categories'][ (int) $id ] ?? array() ), true ) );
				}
			}
			foreach ( $candidates as $id ) {
				$id = (int) $id;
				if ( ! isset( $registry[ $id ] ) ) { continue; }
				$post = new \stdClass();
				$post->ID = $id;
				$this->posts[] = $post;
			}
		}
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		function wc_get_product( $id ) { return $GLOBALS['wl179_products'][ (int) $id ] ?? false; }
	}
	if ( ! function_exists( 'wc_get_formatted_variation' ) ) {
		function wc_get_formatted_variation( $variation, $flat = false, $include_names = true ) {
			$values = array_values( (array) $variation->get_attributes() );
			return implode( ', ', array_filter( $values, static fn( $value ) => is_string( $value ) && '' !== $value ) );
		}
	}
	if ( ! function_exists( 'did_action' ) ) { function did_action( $hook ) { return 'woocommerce_init' === $hook ? 1 : 0; } }
	if ( ! function_exists( 'get_woocommerce_currency' ) ) { function get_woocommerce_currency() { return 'USD'; } }
	if ( ! function_exists( 'wc_get_price_decimals' ) ) { function wc_get_price_decimals() { return 2; } }
	if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return 'USD'; } }
	if ( ! function_exists( 'get_term' ) ) { function get_term( $id, $taxonomy = '' ) { return $GLOBALS['wl179_terms'][ (int) $id ] ?? false; } }
	if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $thing ) { return false; } }

	$GLOBALS['wl179_products'] = array();
	$GLOBALS['wl179_categories'] = array();
	$GLOBALS['wl179_terms'] = array();
	$GLOBALS['wp_version'] = '7.1.2';
	if ( ! defined( 'WC_VERSION' ) ) { define( 'WC_VERSION', '11.1.2' ); }
}

namespace WriteLeash {
	/** Minimal typed error so Price_Cache_Verifier can load without wpdb. */
	final class Price_Apply_Error extends \RuntimeException {}
}

namespace {

	use WriteLeash\Change_Plan as Plan;
	use WriteLeash\Change_Plan_Item as Item;
	use WriteLeash\Price_Operation as O;
	use WriteLeash\Price_Selection_Spec as S;
	use WriteLeash\Price_Store_Context as Context;
	use WriteLeash\Product_Price_Eligibility as Eligibility;
	use WriteLeash\Product_Price_Selector as Selector;
	use WriteLeash\Product_Price_Snapshot as Snapshot;
	use WriteLeash\Safety_Policy as P;
	use WriteLeash\Undo_Fingerprint as UFingerprint;
	use WriteLeash\Price_Cache_Verifier as Verifier;

	require __DIR__ . '/../../writeleash/includes/free/class-price-cache-verifier.php';

	$wl179_assertions = 0;
	function wl179_equal( $actual, $expected, string $label ): void {
		global $wl179_assertions;
		++$wl179_assertions;
		if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
	}
	function wl179_error( callable $call, string $reason ): void {
		try { $call(); }
		catch ( \WriteLeash\Price_Validation_Error $error ) { wl179_equal( $error->reason(), $reason, 'typed error' ); return; }
		throw new RuntimeException( 'Missing typed error: ' . $reason );
	}
	function wl179_marker( string $label ): void {
		global $wl179_assertions;
		echo "#179 $label: PASS ($wl179_assertions cumulative assertions)\n";
	}
	function wl179_context(): Context { return new Context( 'USD', 2, '7.1.2', '11.1.2' ); }
	function wl179_policy(): P { return new P( 1000, '100', '100', false, '100' ); }
	function wl179_register( WC_Product $product ): WC_Product {
		$GLOBALS['wl179_products'][ $product->get_id() ] = $product;
		return $product;
	}
	function wl179_variable( int $id, array $children, string $name = 'Classic Hoodie', string $status = 'publish' ): WC_Product {
		return wl179_register( new WC_Product_Variable( $id, array( 'type' => 'variable', 'name' => $name, 'status' => $status, 'children' => $children ) ) );
	}
	function wl179_variation( int $id, int $parent_id, string $regular, string $sale = '', array $attributes = array(), string $status = 'publish', string $sku = '' ): WC_Product {
		return wl179_register( new WC_Product_Variation( $id, array(
			'type' => 'variation', 'name' => 'Classic Hoodie', 'status' => $status, 'parent_id' => $parent_id,
			'regular_price' => $regular, 'sale_price' => $sale, 'attributes' => $attributes, 'sku' => $sku,
		) ) );
	}
	function wl179_simple( int $id, string $regular, string $sale = '' ): WC_Product {
		$GLOBALS['wl179_categories'][ $id ] = $GLOBALS['wl179_categories'][ $id ] ?? array();
		return wl179_register( new WC_Product_Simple( $id, array( 'type' => 'simple', 'name' => 'Simple ' . $id, 'regular_price' => $regular, 'sale_price' => $sale ) ) );
	}
	function wl179_plan( array $products, O $operation, array $selection_ids = array() ): Plan {
		$ids = $selection_ids ?: array_map( static fn( $p ) => $p->get_id(), $products );
		$snapshots = array();
		foreach ( $products as $product ) { $snapshots[] = Snapshot::read( $product->get_id(), $product ); }
		return Plan::create( 'wl179-plan', '2026-10-05T00:00:00Z', 1, wl179_context(), S::ids( $ids ), $operation, wl179_policy(), $snapshots );
	}

	// ------------------------------------------------------------------
	// Variation snapshot identity: parent facts, attributes and label.
	// ------------------------------------------------------------------
	$parent = wl179_variable( 10, array( 11, 12 ) );
	$blue_m = wl179_variation( 11, 10, '100.00', '', array( 'pa_color' => 'Blue', 'pa_size' => 'M' ), 'publish', 'HOOD-BLUE-M' );
	$red_l = wl179_variation( 12, 10, '100.00', '80.00', array( 'pa_color' => 'Red', 'pa_size' => 'L' ) );
	$snapshot = Snapshot::read( 11, $blue_m, $parent )->data();
	wl179_equal( $snapshot['type'], 'variation', 'variation type stays Woo own type' );
	wl179_equal( $snapshot['core_variation'], true, 'exact core variation is frozen as such' );
	wl179_equal( $snapshot['core_simple'], false, 'a variation is never core simple' );
	wl179_equal( $snapshot['parent_id'], 10, 'parent ID is frozen' );
	wl179_equal( $snapshot['parent_type'], 'variable', 'parent type frozen' );
	wl179_equal( $snapshot['parent_status'], 'publish', 'parent publication frozen' );
	wl179_equal( $snapshot['parent_core_variable'], true, 'exact core variable parent proven' );
	wl179_equal( $snapshot['variation_label'], 'Classic Hoodie — Blue / M', 'human-readable attribute identity' );
	wl179_equal( $snapshot['name'], 'Classic Hoodie', 'existing name key keeps its Woo meaning' );
	wl179_equal( $snapshot['sku'], 'HOOD-BLUE-M', 'variation SKU is secondary identity' );
	$simple222 = new WC_Product_Simple( 222, array( 'type' => 'simple', 'name' => 'Simple', 'regular_price' => '10' ) );
	$simple_entry = Snapshot::read( 222, $simple222 )->data();
	wl179_equal( $simple_entry['core_variation'], false, 'simple snapshots carry the same keys with false' );
	wl179_equal( $simple_entry['parent_id'], 0, 'simple snapshots have no parent' );
	wl179_equal( $simple_entry['variation_label'], '', 'simple snapshots have no variation label' );
	wl179_marker( 'variation snapshot identity, parent facts and attribute label' );

	// ------------------------------------------------------------------
	// Variation eligibility: exact core variation, published core variable
	// parent, the same field-aware regular/sale rules as simple products.
	// ------------------------------------------------------------------
	wl179_equal( Eligibility::evaluate( Snapshot::read( 11, $blue_m, $parent ), wl179_context() )->data()['state'], 'ELIGIBLE', 'published variation of a published core variable parent is eligible' );
	wl179_equal( Eligibility::evaluate( Snapshot::read( 12, $red_l, $parent ), wl179_context(), O::FIELD_SALE )->data()['state'], 'ELIGIBLE', 'sale field eligibility reads the same snapshot' );
	$draft_parent = wl179_variable( 20, array( 21 ), 'Draft parent', 'draft' );
	$draft_child = wl179_variation( 21, 20, '100' );
	wl179_equal( Eligibility::evaluate( Snapshot::read( 21, $draft_child, $draft_parent ), wl179_context() )->data()['reason'], 'unsupported_parent_product', 'draft variable parent refuses its variation' );
	$orphan = wl179_variation( 31, 999, '100' );
	wl179_equal( Eligibility::evaluate( Snapshot::read( 31, $orphan ), wl179_context() )->data()['reason'], 'unsupported_parent_product', 'missing parent refuses its variation instead of guessing' );
	$draft_child_own = wl179_variation( 13, 10, '100', '', array(), 'draft' );
	wl179_equal( Eligibility::evaluate( Snapshot::read( 13, $draft_child_own, $parent ), wl179_context() )->data()['reason'], 'unsupported_status', 'unpublished variation itself is refused' );
	$grouped_parent = wl179_register( new WC_Product( 40, array( 'type' => 'grouped', 'name' => 'Grouped', 'status' => 'publish' ) ) );
	wl179_equal( Eligibility::evaluate( Snapshot::read( 14, wl179_variation( 14, 40, '100' ), $grouped_parent ), wl179_context() )->data()['reason'], 'unsupported_parent_product', 'non-variable parent refused' );
	class WL179_Ext_Variable extends WC_Product_Variable {}
	class WL179_Ext_Variation extends WC_Product_Variation {}
	$ext_parent = wl179_register( new WL179_Ext_Variable( 50, array( 'type' => 'variable', 'name' => 'Ext', 'status' => 'publish', 'children' => array( 51 ) ) ) );
	$ext_child = wl179_register( new WL179_Ext_Variation( 51, array( 'type' => 'variation', 'name' => 'Ext', 'status' => 'publish', 'parent_id' => 50, 'regular_price' => '100', 'attributes' => array( 'pa_color' => 'Blue' ) ) ) );
	wl179_equal( Snapshot::read( 51, $ext_child, $ext_parent )->data()['core_variation'], false, 'extension variation subclass is not core' );
	wl179_equal( Eligibility::evaluate( Snapshot::read( 51, $ext_child, $ext_parent ), wl179_context() )->data()['reason'], 'unsupported_product_type', 'extension variation subclass refused exactly like extension simple subclasses' );
	$ext_simple_parent = wl179_register( new WC_Product( 52, array( 'type' => 'variable', 'name' => 'Fake', 'status' => 'publish' ) ) );
	wl179_equal( Eligibility::evaluate( Snapshot::read( 15, wl179_variation( 15, 52, '100' ), $ext_simple_parent ), wl179_context() )->data()['reason'], 'unsupported_parent_product', 'a fake variable parent is refused' );
	wl179_equal( Eligibility::evaluate( Snapshot::read( 21, $draft_child, $draft_parent ), wl179_context(), O::FIELD_SALE )->data()['state'], 'UNSUPPORTED', 'sale field keeps the parent requirement' );
	wl179_marker( 'variation eligibility and parent requirements' );

	// ------------------------------------------------------------------
	// Regular and sale field semantics on variations.
	// ------------------------------------------------------------------
	$item = Item::compute( Snapshot::read( 11, $blue_m, $parent ), wl179_context(), new O( O::SET, '80' ), wl179_policy() )->data();
	wl179_equal( $item['result'], 'CHANGING', 'regular SET changes a variation' );
	wl179_equal( $item['planned_regular_price'], '80.00', 'variation regular target' );
	$item = Item::compute( Snapshot::read( 11, $blue_m, $parent ), wl179_context(), new O( O::INCREASE_PERCENT, '10' ), wl179_policy() )->data();
	wl179_equal( $item['planned_regular_price'], '110.00', 'variation percent increase uses the regular baseline' );
	$sale_item = Item::compute( Snapshot::read( 12, $red_l, $parent ), wl179_context(), new O( O::DECREASE_PERCENT, '10', O::FIELD_SALE ), wl179_policy() )->data();
	wl179_equal( $sale_item['expected_regular_price'], '80', 'expected value is the sale field value' );
	wl179_equal( $sale_item['planned_regular_price'], '72.00', 'variation sale percent target' );
	$sale_item = Item::compute( Snapshot::read( 11, $blue_m, $parent ), wl179_context(), new O( O::SET, '70', O::FIELD_SALE ), wl179_policy() )->data();
	wl179_equal( $sale_item['expected_regular_price'], '', 'first variation sale starts from empty' );
	wl179_equal( $sale_item['planned_regular_price'], '70.00', 'first variation sale SET' );
	wl179_equal( Item::compute( Snapshot::read( 11, $blue_m, $parent ), wl179_context(), new O( O::INCREASE_FIXED, '5', O::FIELD_SALE ), wl179_policy() )->data()['eligibility']['reason'], 'empty_sale_price', 'fixed from an empty variation sale refused' );
	wl179_equal( Item::compute( Snapshot::read( 12, $red_l, $parent ), wl179_context(), new O( O::SET, '100', O::FIELD_SALE ), wl179_policy() )->data()['eligibility']['reason'], 'sale_price_not_below_regular', 'sale at the variation regular price refused' );
	wl179_equal( Item::compute( Snapshot::read( 12, $red_l, $parent ), wl179_context(), new O( O::SET, '110', O::FIELD_SALE ), wl179_policy() )->data()['eligibility']['reason'], 'sale_price_not_below_regular', 'sale above the variation regular price refused' );
	$variation_sale = wl179_variation( 16, 10, '100.00', '80.00' );
	wl179_equal( Item::compute( Snapshot::read( 16, $variation_sale, $parent ), wl179_context(), new O( O::SET, '80', O::FIELD_REGULAR ), wl179_policy() )->data()['eligibility']['reason'], 'regular_price_not_above_sale', 'variation regular target that would clear its sale refused' );
	wl179_marker( 'regular and sale target operations on variations' );

	// ------------------------------------------------------------------
	// Preview-time parent expansion, individual selection and exact freeze.
	// ------------------------------------------------------------------
	$spec = S::ids( array( 10 ) );
	$snapshots = Selector::resolve( $spec );
	wl179_equal( array_map( static fn( $s ) => $s->data()['product_id'], $snapshots ), array( 11, 12 ), 'selected variable parent expands to its variations only' );
	$resolved = Selector::resolved_selection( $spec, $snapshots );
	wl179_equal( $resolved->data()['type'], 'IDS', 'expanded selection stays an explicit IDS selection' );
	wl179_equal( $resolved->data()['ids'], array( 11, 12 ), 'IDS selection freezes the exact variation IDs' );

	$parent_plan = Plan::create( 'wl179-expanded', '2026-10-05T00:00:00Z', 1, wl179_context(), $resolved, new O( O::SET, '90' ), wl179_policy(), $snapshots );
	wl179_equal( $parent_plan->data()['resolved_product_ids'], array( 11, 12 ), 'plan population is the expanded variation set' );
	wl179_equal( $parent_plan->summary()['changing'], 2, 'both variations change' );

	$individual = Selector::resolve( S::ids( array( 11 ) ) );
	wl179_equal( array_map( static fn( $s ) => $s->data()['product_id'], $individual ), array( 11 ), 'an individual variation selection stays exactly one variation' );
	$both = Selector::resolve( S::ids( array( 10, 11, 10 ) ) );
	wl179_equal( array_map( static fn( $s ) => $s->data()['product_id'], $both ), array( 11, 12 ), 'parent plus child selection deduplicates to the exact variation set' );
	$warned = Selector::resolved_selection( S::ids( array( 10, 10 ) ), Selector::resolve( S::ids( array( 10, 10 ) ) ) );
	wl179_equal( $warned->data()['warnings'], array( 'duplicate_selection' ), 'requested duplicate warning is preserved through expansion' );

	// A variation created after approval can never enter the frozen plan.
	$late = wl179_variation( 13, 10, '100.00', '', array( 'pa_color' => 'Green', 'pa_size' => 'S' ) );
	$GLOBALS['wl179_products'][10]->wl_set_value( 'children', array( 11, 12, 13 ) );
	$late_snapshots = Selector::resolve( S::ids( array( 10 ) ) );
	wl179_equal( array_map( static fn( $s ) => $s->data()['product_id'], $late_snapshots ), array( 11, 12, 13 ), 'a new preview would include the new variation' );
	wl179_equal( $parent_plan->data()['resolved_product_ids'], array( 11, 12 ), 'the frozen plan population never silently includes the later variation' );
	wl179_equal( $parent_plan->data()['selection']['ids'], array( 11, 12 ), 'the frozen IDS selection holds only the inspected variations' );
	wl179_error( static fn() => $parent_plan->item( 13 ), 'unpreviewed_product' );
	wl179_error( static function () use ( $late_snapshots ) { Plan::create( 'wl179-late', '2026-10-05T00:00:00Z', 1, wl179_context(), S::ids( array( 11, 12 ) ), new O( O::SET, '80' ), wl179_policy(), $late_snapshots ); }, 'selection_snapshot_mismatch' );

	// Category selection expands variable parents and keeps simple products.
	$GLOBALS['wl179_terms'][5] = new WP_Term( 5, 'Hoodies' );
	$cat_simple = wl179_simple( 60, '50' );
	$GLOBALS['wl179_categories'][60] = array( 5 );
	$GLOBALS['wl179_categories'][10] = array( 5 );
	$cat_spec = S::category( 5 );
	$cat_snapshots = Selector::resolve( $cat_spec );
	wl179_equal( array_map( static fn( $s ) => $s->data()['product_id'], $cat_snapshots ), array( 11, 12, 13, 60 ), 'category expands variable parents to variations and keeps simple products' );
	$cat_plan = Plan::create( 'wl179-category', '2026-10-05T00:00:00Z', 1, wl179_context(), $cat_spec, new O( O::SET, '40' ), wl179_policy(), $cat_snapshots );
	wl179_equal( $cat_plan->data()['selection']['type'], 'CATEGORY', 'category selection keeps its category provenance' );
	wl179_equal( $cat_plan->data()['resolved_product_ids'], array( 11, 12, 13, 60 ), 'category population is frozen by resolved IDs' );
	wl179_equal( Selector::resolved_selection( $cat_spec, $cat_snapshots )->data(), $cat_spec->data(), 'non-IDS selections are not rewritten' );
	$skus = Selector::resolve( S::sku( 'HOOD-BLUE-M' ) );
	wl179_equal( array_map( static fn( $s ) => $s->data()['product_id'], $skus ), array( 11 ), 'an exact variation SKU resolves that variation (advanced SKU stays one exact product)' );
	$ext_expansion = Selector::resolve( S::ids( array( 50 ) ) );
	wl179_equal( array_map( static fn( $s ) => $s->data()['product_id'], $ext_expansion ), array( 50 ), 'an extension variable parent is never expanded' );
	wl179_equal( Eligibility::evaluate( $ext_expansion[0], wl179_context() )->data()['reason'], 'unsupported_product_type', 'an extension variable parent stays one explained refusal' );
	wl179_marker( 'parent expansion, dedupe, category expansion and the exact preview freeze' );

	// ------------------------------------------------------------------
	// Field-scoped preconditions on variations.
	// ------------------------------------------------------------------
	$blue_plan = Plan::create( 'wl179-blue', '2026-10-05T00:00:00Z', 1, wl179_context(), S::ids( array( 11, 12 ) ), new O( O::SET, '90' ), wl179_policy(), Selector::resolve( S::ids( array( 11, 12 ) ) ) );
	wl179_equal( $blue_plan->precondition( 12, Snapshot::read( 12, $red_l, $parent ), wl179_context() )['state'], 'MATCH', 'variation regular field matches its frozen price' );
	wl179_equal( $blue_plan->precondition( 12, Snapshot::read( 12, wl179_variation( 12, 10, '100.00', '70.00', array( 'pa_color' => 'Red', 'pa_size' => 'L' ) ), $parent ), wl179_context() )['state'], 'MATCH', 'external sale change is not a regular-field conflict on a variation' );
	wl179_equal( $blue_plan->precondition( 12, Snapshot::read( 12, wl179_variation( 12, 10, '110.00', '80.00', array( 'pa_color' => 'Red', 'pa_size' => 'L' ) ), $parent ), wl179_context() )['reasons'], array( 'regular_price_changed' ), 'variation regular drift conflicts' );
	$sale_plan = Plan::create( 'wl179-sale', '2026-10-05T00:00:00Z', 1, wl179_context(), S::ids( array( 12 ) ), new O( O::SET, '70', O::FIELD_SALE ), wl179_policy(), Selector::resolve( S::ids( array( 12 ) ) ) );
	wl179_equal( $sale_plan->price_field(), O::FIELD_SALE, 'variation sale plan exposes the field' );
	wl179_equal( $sale_plan->precondition( 12, Snapshot::read( 12, wl179_variation( 12, 10, '100.00', '75.00', array( 'pa_color' => 'Red', 'pa_size' => 'L' ) ), $parent ), wl179_context() )['reasons'], array( 'sale_price_changed' ), 'variation sale drift conflicts on the sale field' );
	wl179_equal( $sale_plan->precondition( 12, Snapshot::read( 12, wl179_variation( 12, 10, '65.00', '80.00', array( 'pa_color' => 'Red', 'pa_size' => 'L' ) ), $parent ), wl179_context() )['reasons'], array( 'sale_price_not_below_regular' ), 'regular drop below the planned variation sale conflicts' );
	$moved = wl179_variation( 12, 10, '100.00', '80.00', array( 'pa_color' => 'Red', 'pa_size' => 'L' ) );
	$GLOBALS['wl179_products'][12] = new WC_Product_Variation( 12, array( 'type' => 'variation', 'name' => 'Classic Hoodie', 'status' => 'publish', 'parent_id' => 20, 'regular_price' => '100.00' ) );
	wl179_equal( $blue_plan->precondition( 12, Snapshot::read( 12, $GLOBALS['wl179_products'][12] ), wl179_context() )['reasons'], array( 'product_type_changed' ), 'reparenting a variation is a type conflict' );
	wl179_marker( 'field-scoped variation preconditions and reparenting conflict' );

	$homogeneous = Selector::resolve( S::ids( array( 11, 12 ) ) );
	$variation_plan = Plan::create( 'wl179-homogeneous', '2026-10-05T00:00:00Z', 1, wl179_context(), S::ids( array( 11, 12 ) ), new O( O::SET, '90' ), wl179_policy(), $homogeneous );
	$round = Plan::hydrate( json_decode( $variation_plan->json(), true ) );
	wl179_equal( $round->hash(), $variation_plan->hash(), 'variation plan hash survives hydration' );
	wl179_equal( $round->item( 11 )->data()['snapshot']['core_variation'], true, 'variation identity survives hydration' );
	wl179_equal( $round->item( 11 )->data()['snapshot']['variation_label'], 'Classic Hoodie — Blue / M', 'variation attribute label survives hydration' );

	// ------------------------------------------------------------------
	// Parent range computation mirrors WC_Product_Variable::sync().
	// ------------------------------------------------------------------
	wl179_equal( Verifier::parent_range( array() ), array( 'min' => null, 'max' => null ), 'no visible children have no range' );
	wl179_equal( Verifier::parent_range( array( '', null, '80.00', '100', '80.00' ) ), array( 'min' => '80.00', 'max' => '100' ), 'distinct child prices sort numerically; min first, max last' );
	wl179_equal( Verifier::parent_range( array( '100', '80', '90.5' ) ), array( 'min' => '80', 'max' => '100' ), 'unsorted children produce the Woo min/max pair' );
	wl179_equal( Verifier::parent_range( array( '0', '0.00' ) ), array( 'min' => '0', 'max' => '0.00' ), 'numeric duplicates keep their distinct stored strings exactly like SELECT DISTINCT' );

	// ------------------------------------------------------------------
	// Field-scoped Undo provenance for variations.
	// ------------------------------------------------------------------
	$base = array( 'product_id' => 12, 'applied_price' => '72', 'product_type' => 'variation', 'core_simple' => false, 'status' => 'publish', 'currency' => 'USD', 'price_decimals' => 2, 'wordpress_version' => '7.1.2', 'woocommerce_version' => '11.1.2', 'regular_context' => '100' );
	$facts = array_merge( $base, array( 'price_field' => O::FIELD_SALE, 'expected_price' => '80', 'regular_context' => '100', 'apply_attempt_id' => 'attempt', 'applied_at' => '2026-01-01 00:00:00', 'actor_id' => 1, 'initiator_id' => 1, 'job_id' => 1, 'plan_id' => 'variation-plan' ) );
	$captured = UFingerprint::capture( $facts );
	$provenance = json_decode( $captured['provenance'], true );
	wl179_equal( $provenance['blocking']['core_simple'], false, 'variation Undo provenance records the core simple fact as false' );
	wl179_equal( $provenance['blocking']['product_type'], 'variation', 'variation Undo provenance records the type' );
	wl179_equal( UFingerprint::verify( $provenance, $base )['match'], true, 'variation sale Undo matches its sale and regular context' );
	$drifted = $base;
	$drifted['applied_price'] = '70';
	wl179_equal( UFingerprint::verify( $provenance, $drifted )['reasons'], array( 'applied_price_changed' ), 'variation sale Undo blocks applied-price drift' );
	$regular_facts = $facts;
	$regular_facts['price_field'] = O::FIELD_REGULAR;
	$regular_facts['applied_price'] = '90';
	unset( $regular_facts['regular_context'] );
	$regular_provenance = json_decode( UFingerprint::capture( $regular_facts )['provenance'], true );
	$regular_fresh = array_merge( $base, array( 'applied_price' => '90', 'sale_price' => '70', 'active_price' => '70', 'lookup_min' => '70', 'lookup_max' => '70', 'lookup_onsale' => '1' ) );
	wl179_equal( UFingerprint::verify( $regular_provenance, $regular_fresh )['match'], true, 'variation regular Undo ignores sibling sale and parent lookup drift' );
	wl179_marker( 'parent range computation and field-scoped variation Undo provenance' );

	echo "#179 variation domain harness: PASS ($wl179_assertions assertions)\n";
}
