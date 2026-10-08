<?php
/**
 * #181 pure-PHP dirty-catalog leg.
 *
 * Minimal Woo/WordPress stubs exercise selector resolution, eligibility and
 * plan-item computation without a database: one throwing product must stay in
 * the frozen population as an explicitly unreadable item, and malformed or
 * absent products must keep their own stable reasons.
 */
$GLOBALS['wp_version'] = '7.1.2';
if ( ! defined( 'WC_VERSION' ) ) { define( 'WC_VERSION', '11.1.2' ); }

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		private $data;
		public function __construct( $data = array() ) {
			if ( is_int( $data ) ) {
				if ( isset( $GLOBALS['wl181_throwing'][ $data ] ) ) { throw new \RuntimeException( 'unreadable product fixture' ); }
				$data = $GLOBALS['wl181_products'][ $data ]->data ?? array();
			}
			$this->data = array_merge( array( 'id' => 0, 'name' => '', 'sku' => '', 'type' => 'simple', 'status' => 'publish', 'regular_price' => '', 'sale_price' => '', 'sale_from' => null, 'sale_to' => null, 'category_ids' => array() ), $data );
		}
		public function get_id() { return (int) $this->data['id']; }
		public function get_name( $context = 'view' ) { return (string) $this->data['name']; }
		public function get_sku( $context = 'view' ) { return (string) $this->data['sku']; }
		public function get_type() { return (string) $this->data['type']; }
		public function get_status( $context = 'view' ) { return (string) $this->data['status']; }
		public function get_regular_price( $context = 'view' ) { return (string) $this->data['regular_price']; }
		public function get_sale_price( $context = 'view' ) { return (string) $this->data['sale_price']; }
		public function get_date_on_sale_from( $context = 'view' ) { return $this->data['sale_from']; }
		public function get_date_on_sale_to( $context = 'view' ) { return $this->data['sale_to']; }
		public function get_category_ids( $context = 'view' ) { return (array) $this->data['category_ids']; }
	}
}
if ( ! class_exists( 'WC_Product_Simple' ) ) {
	class WC_Product_Simple extends WC_Product {}
}
if ( ! class_exists( 'WC_Product_Factory' ) ) {
	class WC_Product_Factory {
		public static function get_product_type( $id ) { return isset( $GLOBALS['wl181_throwing'][ $id ] ) ? 'simple' : ( isset( $GLOBALS['wl181_products'][ $id ] ) ? $GLOBALS['wl181_products'][ $id ]->get_type() : false ); }
		public static function get_product_classname( $id, $type ) { return isset( $GLOBALS['wl181_products'][ $id ] ) ? get_class( $GLOBALS['wl181_products'][ $id ] ) : 'WC_Product_Simple'; }
	}
}
if ( ! class_exists( 'WC_Cache_Helper' ) ) { class WC_Cache_Helper { public static function invalidate_cache_group( $group ): void {} } }
if ( ! function_exists( 'clean_post_cache' ) ) { function clean_post_cache( $id ): void {} }
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( $id, $group ): bool { return true; } }
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $posts = array();
		public function __construct( $args = array() ) {
			$ids = ! empty( $args['post__in'] ) ? (array) $args['post__in'] : array_keys( $GLOBALS['wl181_posts'] );
			foreach ( $ids as $id ) {
				if ( empty( $GLOBALS['wl181_posts'][ (int) $id ] ) ) { continue; }
				$post = new \stdClass();
				$post->ID = (int) $id;
				$this->posts[] = $post;
			}
		}
	}
}
if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $id ) {
		if ( isset( $GLOBALS['wl181_throwing'][ (int) $id ] ) ) { throw new \RuntimeException( 'unreadable product fixture' ); }
		return $GLOBALS['wl181_products'][ (int) $id ] ?? false;
	}
}
if ( ! function_exists( 'did_action' ) ) { function did_action( $hook ) { return 'woocommerce_init' === $hook ? 1 : 0; } }
if ( ! function_exists( 'get_woocommerce_currency' ) ) { function get_woocommerce_currency() { return 'USD'; } }
if ( ! function_exists( 'wc_get_price_decimals' ) ) { function wc_get_price_decimals() { return 2; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return 'USD'; } }

$GLOBALS['wl181_posts'] = array();
$GLOBALS['wl181_products'] = array();
$GLOBALS['wl181_throwing'] = array();

// Snapshot contract: a true miss and an unreadable read stay distinguishable
// while keeping the serialized exists => false shape.
$miss = \WriteLeash\Product_Price_Snapshot::read( 601, false )->data();
wl107_equal( $miss['exists'], false, 'true miss exists false' );
wl107_equal( $miss['unreadable'], false, 'true miss is not unreadable' );
$missing = \WriteLeash\Product_Price_Snapshot::unreadable( 601 )->data();
wl107_equal( $missing['exists'], false, 'unreadable keeps exists false' );
wl107_equal( $missing['unreadable'], true, 'unreadable flag set' );
wl107_equal( json_decode( json_encode( $missing ), true ), $missing, 'unreadable snapshot is JSON compatible' );
wl107_error( static fn() => \WriteLeash\Product_Price_Snapshot::unreadable( 0 ), 'invalid_product_id' );
wl107_marker( 'unreadable snapshot factory and serialization shape' );

// A dirty catalog: clean, malformed, unreadable and absent rows.
$clean = new WC_Product_Simple( array( 'id' => 701, 'name' => 'Clean', 'sku' => 'WL181-CLEAN', 'regular_price' => '100' ) );
$malformed = new WC_Product_Simple( array( 'id' => 702, 'name' => 'Malformed', 'sku' => 'WL181-MALFORMED', 'regular_price' => 'not-a-price' ) );
$GLOBALS['wl181_products'] = array( 701 => $clean, 702 => $malformed );
$GLOBALS['wl181_posts'] = array( 701 => true, 702 => true, 703 => true );
$GLOBALS['wl181_throwing'] = array( 703 => true );

$spec = \WriteLeash\Price_Selection_Spec::ids( array( 703, 701, 702, 704 ) );
$snapshots = \WriteLeash\Product_Price_Selector::resolve( $spec );
$ids = array_map( static fn( $snapshot ) => $snapshot->data()['product_id'], $snapshots );
wl107_equal( $ids, array( 701, 702, 703, 704 ), 'explicit selection stays complete and sorted' );
$read = array_column( array_map( static fn( $snapshot ) => $snapshot->data(), $snapshots ), null, 'product_id' );
wl107_equal( $read[701]['exists'] . '/' . $read[701]['unreadable'], '1/', 'clean product read' );
wl107_equal( $read[702]['regular_price'], 'not-a-price', 'malformed stored price preserved raw' );
wl107_equal( $read[703]['exists'] . '/' . $read[703]['unreadable'], '/1', 'throwing read becomes unreadable item' );
wl107_equal( $read[704]['exists'] . '/' . $read[704]['unreadable'], '/', 'absent product stays missing' );
wl107_marker( 'throwing product isolated; selection population complete' );

$context = new \WriteLeash\Price_Store_Context( 'USD', 2, '7.1.2', '11.1.2' );
$reasons = array();
foreach ( $snapshots as $snapshot ) {
	$data = $snapshot->data();
	$reasons[ $data['product_id'] ] = \WriteLeash\Product_Price_Eligibility::evaluate( $snapshot, $context )->data()['reason'];
}
wl107_equal( $reasons, array( 701 => null, 702 => 'invalid_price', 703 => 'unreadable_product_data', 704 => 'missing_product' ), 'per-product stable eligibility reasons' );

$operation = new \WriteLeash\Price_Operation( \WriteLeash\Price_Operation::SET, '80' );
$policy = new \WriteLeash\Safety_Policy( 100, '100', '100', false, '100' );
$plan = \WriteLeash\Change_Plan::create( 'dirty-catalog', '2026-10-05T00:00:00Z', 1, $context, $spec, $operation, $policy, $snapshots );
wl107_equal( $plan->data()['resolved_product_ids'], array( 701, 702, 703, 704 ), 'unreadable item frozen in plan population' );
wl107_equal( $plan->summary(), array( 'selected' => 4, 'eligible' => 1, 'changing' => 1, 'unchanged' => 0, 'unsupported' => 3, 'blocked' => 0, 'conflicted' => 0, 'warning_items' => 0, 'warning_count' => 0, 'selection_warning_count' => 0 ), 'summary separates changed from skipped' );
wl107_equal( $plan->item( 701 )->data()['result'], 'CHANGING', 'clean product still changes' );
wl107_equal( $plan->item( 701 )->data()['planned_regular_price'], '80.00', 'clean absolute target frozen' );
foreach ( array( 702, 703, 704 ) as $dirty_id ) {
	wl107_equal( $plan->item( $dirty_id )->data()['result'], 'UNSUPPORTED', 'dirty product skipped ' . $dirty_id );
	wl107_equal( $plan->item( $dirty_id )->data()['planned_regular_price'], null, 'no guessed target ' . $dirty_id );
}
wl107_equal( \WriteLeash\Change_Plan_Item::hydrate( $plan->item( 703 )->data() )->data(), $plan->item( 703 )->data(), 'unreadable item rehydrates unchanged' );
wl107_marker( 'plan keeps one changing item and skips three dirty items without guessing' );

// SKU resolution keeps an unreadable candidate instead of dropping it or
// aborting, and a single byte-exact match is still not ambiguous.
$sku_clean = new WC_Product_Simple( array( 'id' => 711, 'name' => 'SKU clean', 'sku' => 'WL181-SKU', 'regular_price' => '100' ) );
$GLOBALS['wl181_products'] = array( 711 => $sku_clean );
$GLOBALS['wl181_posts'] = array( 711 => true, 712 => true );
$GLOBALS['wl181_throwing'] = array( 712 => true );
$sku_snapshots = \WriteLeash\Product_Price_Selector::resolve( \WriteLeash\Price_Selection_Spec::sku( 'WL181-SKU' ) );
$sku_read = array_column( array_map( static fn( $snapshot ) => $snapshot->data(), $sku_snapshots ), null, 'product_id' );
wl107_equal( array_keys( $sku_read ), array( 711, 712 ), 'SKU selection keeps unreadable candidate' );
wl107_equal( $sku_read[711]['exists'], true, 'byte-exact SKU match kept' );
wl107_equal( $sku_read[712]['unreadable'], true, 'unreadable SKU candidate flagged' );
$GLOBALS['wl181_products'] = array();
$GLOBALS['wl181_posts'] = array();
$GLOBALS['wl181_throwing'] = array();
wl107_marker( 'SKU selection survives one unreadable candidate' );
