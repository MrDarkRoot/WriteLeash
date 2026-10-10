<?php
/**
 * #234 inclusive price-range selection filter contract harness.
 *
 * Query/Woo stubs are NOT Woo runtime evidence. Exercises range mathematics,
 * selection resolution, frozen-preview safety and Admin validation through
 * stubbed product/snapshot/selector/planner/Admin layers.
 */
define( 'ABSPATH', __DIR__ );
$wl234_source = getenv( 'WL234_SOURCE' ) ?: __DIR__ . '/../../writeleash/includes/free';
foreach ( array( 'price-decimal', 'price-operation', 'price-range', 'safety-policy', 'product-snapshot', 'product-selector', 'change-plan', 'free-support-contract', 'product-discovery', 'free-admin' ) as $wl234_file ) { require $wl234_source . '/class-' . $wl234_file . '.php'; }
use WriteLeash\Price_Range_Filter as Range234;
use WriteLeash\Price_Selection_Spec as Spec234;
use WriteLeash\Product_Price_Selector as Selector234;
use WriteLeash\Change_Plan as Plan234;
use WriteLeash\Free_Admin as Admin234;

$GLOBALS['wp_version'] = '7.1.2';
if ( ! defined( 'WC_VERSION' ) ) { define( 'WC_VERSION', '11.1.2' ); }
$GLOBALS['actor234'] = 1;
$GLOBALS['denied234'] = array();
$GLOBALS['products234'] = array();
$GLOBALS['terms234'] = array( 1 => 0, 2 => 1 );
$GLOBALS['throw234'] = array();
$GLOBALS['queries234'] = 0;

if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return $GLOBALS['actor234']; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap, $id = 0 ) { return $GLOBALS['actor234'] > 0 && ! in_array( (int) $id, $GLOBALS['denied234'], true ); } }
if ( ! function_exists( 'clean_post_cache' ) ) { function clean_post_cache( $id ) {} }
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( $key, $group = '' ) { return true; } }
if ( ! class_exists( 'WC_Cache_Helper' ) ) { class WC_Cache_Helper { public static function invalidate_cache_group( $group ): void {} } }
if ( ! function_exists( 'did_action' ) ) { function did_action( $hook ) { return 1; } }
if ( ! function_exists( 'get_woocommerce_currency' ) ) { function get_woocommerce_currency() { return 'USD'; } }
if ( ! function_exists( 'wc_get_price_decimals' ) ) { function wc_get_price_decimals() { return 2; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return 'USD'; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return false; } }
if ( ! function_exists( 'get_term' ) ) { function get_term( $id, $taxonomy ) { return array_key_exists( $id, $GLOBALS['terms234'] ) ? (object) array( 'term_id' => $id ) : false; } }
if ( ! function_exists( 'get_terms' ) ) { function get_terms( $args ) { return array(); } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return 'wl234-plan-id'; } }
if ( ! function_exists( '__' ) ) { function __( $text, $domain = null ) { return $text; } }
if ( ! function_exists( '_n' ) ) { function _n( $single, $plural, $number, $domain = null ) { return 1 === (int) $number ? $single : $plural; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text, $domain = null ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $url ) { return (string) $url; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b, $echo = true ) { $out = (string) $a === (string) $b ? " selected='selected'" : ''; if ( $echo ) { echo $out; } return $out; } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b, $echo = true ) { $out = (string) $a === (string) $b ? " checked='checked'" : ''; if ( $echo ) { echo $out; } return $out; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $path = '' ) { return 'http://example.test/wp-admin/' . $path; } }
if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg( $args, $url = '' ) { return $url . ( $args ? '?' . http_build_query( (array) $args ) : '' ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $action = '' ) { return 'nonce234'; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $action = '' ) { echo '<input type="hidden" name="_wpnonce" value="nonce234">'; } }
if ( ! function_exists( 'wc_get_price_thousand_separator' ) ) { function wc_get_price_thousand_separator() { return ','; } }
if ( ! function_exists( 'wc_get_price_decimal_separator' ) ) { function wc_get_price_decimal_separator() { return '.'; } }
if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) { function get_woocommerce_currency_symbol( $currency = '' ) { return '$'; } }
if ( ! function_exists( 'get_woocommerce_price_format' ) ) { function get_woocommerce_price_format() { return '%1$s%2$s'; } }

if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {
		protected array $v;
		public function __construct( $data = array() ) {
			if ( is_int( $data ) ) { $data = isset( $GLOBALS['products234'][ $data ] ) ? $GLOBALS['products234'][ $data ]->v : array( 'id' => $data ); }
			$this->v = array_merge( array( 'id' => 0, 'sku' => '', 'type' => 'simple', 'status' => 'publish', 'regular_price' => '', 'sale_price' => '', 'categories' => array(), 'children' => array(), 'parent' => 0 ), $data );
		}
		public function get_id() { return (int) $this->v['id']; }
		public function get_name( $context = 'view' ) { return 'Product ' . $this->get_id(); }
		public function get_sku( $context = 'view' ) { return (string) $this->v['sku']; }
		public function get_type() { return (string) $this->v['type']; }
		public function get_status( $context = 'view' ) { return (string) $this->v['status']; }
		public function get_regular_price( $context = 'view' ) { return (string) $this->v['regular_price']; }
		public function get_sale_price( $context = 'view' ) { return (string) $this->v['sale_price']; }
		public function get_date_on_sale_from( $context = 'view' ) { return null; }
		public function get_date_on_sale_to( $context = 'view' ) { return null; }
		public function get_category_ids( $context = 'view' ) { return $this->v['categories']; }
		public function get_parent_id( $context = 'view' ) { return (int) $this->v['parent']; }
		public function get_children() { return $this->v['children']; }
		public function get_attributes() { return array(); }
	}
}
if ( ! class_exists( 'WC_Product_Simple' ) ) { class WC_Product_Simple extends WC_Product {} }
if ( ! class_exists( 'WC_Product_Variable' ) ) { class WC_Product_Variable extends WC_Product { public function get_type() { return 'variable'; } } }
if ( ! class_exists( 'WC_Product_Variation' ) ) { class WC_Product_Variation extends WC_Product { public function get_type() { return 'variation'; } } }
if ( ! class_exists( 'WC_Product_Grouped' ) ) { class WC_Product_Grouped extends WC_Product { public function get_type() { return 'grouped'; } } }
if ( ! class_exists( 'WC_Product_Factory' ) ) {
	class WC_Product_Factory {
		public static function get_product_type( $id ) {
			if ( in_array( (int) $id, $GLOBALS['throw234'], true ) ) { throw new RuntimeException( 'fixture unreadable' ); }
			return isset( $GLOBALS['products234'][ (int) $id ] ) ? $GLOBALS['products234'][ (int) $id ]->get_type() : false;
		}
		public static function get_product_classname( $id, $type, $post_type = '' ) { return get_class( $GLOBALS['products234'][ (int) $id ] ); }
	}
}
if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $id ) {
		if ( in_array( (int) $id, $GLOBALS['throw234'], true ) ) { throw new RuntimeException( 'fixture unreadable' ); }
		return $GLOBALS['products234'][ (int) $id ] ?? false;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['filters234'][ $tag ][ (int) $priority ][] = array( 'callback' => $callback, 'accepted_args' => (int) $accepted_args ); return true; }
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $tag, $callback, $priority = 10 ) {
		foreach ( (array) ( $GLOBALS['filters234'][ $tag ][ (int) $priority ] ?? array() ) as $key => $registered ) {
			if ( $registered['callback'] === $callback ) { unset( $GLOBALS['filters234'][ $tag ][ (int) $priority ][ $key ] ); }
		}
		return true;
	}
}
if ( ! function_exists( 'apply_filters234' ) ) {
	function apply_filters234( $tag, $value, ...$extra ) {
		foreach ( (array) ( $GLOBALS['filters234'][ $tag ] ?? array() ) as $callbacks ) {
			foreach ( $callbacks as $registered ) { $value = call_user_func_array( $registered['callback'], array_merge( array( $value ), array_slice( $extra, 0, max( 0, (int) $registered['accepted_args'] - 1 ) ) ) ); }
		}
		return $value;
	}
}
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $posts = array();
		public array $query_vars = array();
		public function get( $key ) { return $this->query_vars[ $key ] ?? ''; }
		public function __construct( array $args ) {
			$this->query_vars = $args;
			++$GLOBALS['queries234'];
			$ids = $args['post__in'] ?? array_keys( $GLOBALS['products234'] );
			$rows = array();
			foreach ( $ids as $id ) {
				$product = $GLOBALS['products234'][ (int) $id ] ?? null;
				if ( ! $product ) { continue; }
				$meta = $args['meta_query'][0] ?? null;
				if ( $meta && '_sku' === $meta['key'] && $product->get_sku() !== $meta['value'] ) { continue; }
				$matches = 1;
				$tax = $args['tax_query'][0] ?? null;
				if ( $tax ) {
					$matches = 0;
					foreach ( $product->get_category_ids() as $category ) {
						$category = (int) $category;
						do {
							if ( in_array( $category, $tax['terms'], true ) ) { ++$matches; break; }
							$category = ! empty( $tax['include_children'] ) ? ( $GLOBALS['terms234'][ $category ] ?? 0 ) : 0;
						} while ( $category );
					}
					if ( ! $matches ) { continue; }
				}
				for ( $row = 0; $row < $matches; ++$row ) { $rows[] = (object) array( 'ID' => (int) $id ); }
			}
			usort( $rows, static fn( $a, $b ) => $a->ID <=> $b->ID );
			$distinct = function_exists( 'apply_filters234' ) ? apply_filters234( 'posts_distinct', '', $this ) : '';
			if ( 'DISTINCT' === $distinct ) {
				$collapsed = array();
				foreach ( $rows as $row ) { $collapsed[ $row->ID ] = $row; }
				$rows = array_values( $collapsed );
			}
			$this->posts = array_slice( $rows, 0, (int) ( $this->query_vars['posts_per_page'] ?? 10 ) );
		}
	}
}

$checks234 = 0;
function eq234( $actual, $expected, string $label ) { global $checks234; ++$checks234; if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( $actual ) . ' != ' . json_encode( $expected ) ); } }
function ok234( bool $condition, string $label ) { eq234( $condition, true, $label ); }
function error234( callable $call, string $reason ) { try { $call(); } catch ( WriteLeash\Price_Validation_Error $e ) { eq234( $e->reason(), $reason, 'refusal ' . $reason ); return; } throw new RuntimeException( 'Expected refusal: ' . $reason ); }
function ids234( $spec ) { return array_map( static fn( $snapshot ) => $snapshot->data()['product_id'], Selector234::resolve( $spec ) ); }
function outcome234( $spec ) { return Selector234::resolve_with_outcome( $spec ); }
function simple234( int $id, string $regular, array $extra = array() ) { return new WC_Product_Simple( array_merge( array( 'id' => $id, 'regular_price' => $regular ), $extra ) ); }

// ---- Range mathematics ----
$filter234 = Range234::from_inputs( '1', '20.00', '150', '', 'regular_price' );
eq234( $filter234->data(), array( 'enabled' => true, 'min' => '20', 'max' => '150', 'basis' => 'regular_price' ), 'canonical bounds, default basis follows target field' );
eq234( Range234::from_data( $filter234->data() )->data(), $filter234->data(), 'filter data round-trips exactly' );
ok234( $filter234->matches( array( 'regular_price' => '20', 'sale_price' => '' ) ), 'inclusive lower bound' );
ok234( $filter234->matches( array( 'regular_price' => '150', 'sale_price' => '' ) ), 'inclusive upper bound' );
ok234( ! $filter234->matches( array( 'regular_price' => '19.99', 'sale_price' => '' ) ), 'below lower bound' );
ok234( ! $filter234->matches( array( 'regular_price' => '150.01', 'sale_price' => '' ) ), 'above upper bound' );
$exact234 = Range234::from_inputs( '1', '20.005', '20.005', 'regular_price' );
ok234( $exact234->matches( array( 'regular_price' => '20.005', 'sale_price' => '' ) ), 'exact decimal boundary matches' );
ok234( ! $exact234->matches( array( 'regular_price' => '20.004999', 'sale_price' => '' ) ), 'exact decimal boundary excludes below' );
ok234( ! $exact234->matches( array( 'regular_price' => '20.005001', 'sale_price' => '' ) ), 'exact decimal boundary excludes above' );
$minonly234 = Range234::from_inputs( '1', '20', '', 'regular_price' );
ok234( $minonly234->matches( array( 'regular_price' => '999999999999', 'sale_price' => '' ) ), 'minimum-only has no ceiling' );
ok234( ! $minonly234->matches( array( 'regular_price' => '19.999999', 'sale_price' => '' ) ), 'minimum-only enforces floor' );
$maxonly234 = Range234::from_inputs( '1', '', '150', 'regular_price' );
ok234( $maxonly234->matches( array( 'regular_price' => '0', 'sale_price' => '' ) ), 'maximum-only has no floor' );
ok234( ! $maxonly234->matches( array( 'regular_price' => '150.000001', 'sale_price' => '' ) ), 'maximum-only enforces ceiling' );
$zero234 = Range234::from_inputs( '1', '0', '0', 'regular_price' );
ok234( $zero234->matches( array( 'regular_price' => '0.00', 'sale_price' => '' ) ), 'zero bound matches stored zero' );
ok234( ! $zero234->matches( array( 'regular_price' => '0.000001', 'sale_price' => '' ) ), 'zero bound excludes epsilon' );
error234( static fn() => Range234::from_inputs( '1', '150', '20', 'regular_price' ), 'invalid_price_range' );
error234( static fn() => Range234::from_inputs( '1', '', '', 'regular_price' ), 'invalid_price_range' );
foreach ( array( 'abc', '1,000', ' 20', '20 ', '20.0000001', '1e2', '-5', '.5', '01', '$20', '20%' ) as $bad234 ) { error234( static fn() => Range234::from_inputs( '1', $bad234, '', 'regular_price' ), 'invalid_price_range' ); }
foreach ( array( array( '20' ), 20, 20.5, true ) as $bad234 ) { error234( static fn() => Range234::from_inputs( '1', $bad234, '', 'regular_price' ), 'invalid_price_range' ); }
foreach ( array( 'yes', '0', 1, array( '1' ) ) as $bad234 ) { error234( static fn() => Range234::from_inputs( $bad234, '1', '', 'regular_price' ), 'invalid_price_range' ); }
error234( static fn() => Range234::from_inputs( '1', '1', '', 'cost_price' ), 'invalid_price_range' );
error234( static fn() => Range234::from_inputs( '1', '1', '', array( 'regular_price' ) ), 'invalid_price_range' );
error234( static fn() => Range234::from_data( array( 'enabled' => true, 'min' => '30', 'max' => '20', 'basis' => 'regular_price' ) ), 'invalid_price_range' );
error234( static fn() => Range234::from_data( array( 'enabled' => 'yes', 'min' => '20', 'max' => null, 'basis' => 'regular_price' ) ), 'invalid_price_range' );
eq234( Range234::from_inputs( null, 'garbage', 'garbage', 'cost_price' )->data()['enabled'], false, 'absent control disables and ignores garbage' );
eq234( Range234::from_inputs( '', 'garbage', 'garbage', 'cost_price' )->data()['enabled'], false, 'empty control disables and ignores garbage' );
eq234( Range234::disabled()->matches( array( 'regular_price' => '', 'sale_price' => '' ) ), true, 'disabled filter matches everything' );
eq234( Range234::from_inputs( '1', '1', '', '' )->data()['basis'], 'regular_price', 'empty basis defaults to regular target field' );
eq234( Range234::from_inputs( '1', '1', '', '', 'sale_price' )->data()['basis'], 'sale_price', 'empty basis defaults to sale target field' );
$salefilter234 = Range234::from_inputs( '1', '', '50', 'sale_price' );
ok234( ! $salefilter234->matches( array( 'regular_price' => '10', 'sale_price' => '' ) ), 'blank sale never matches a numeric sale range' );
ok234( $salefilter234->matches( array( 'regular_price' => '10', 'sale_price' => '50' ) ), 'sale basis upper bound inclusive' );
ok234( ! $salefilter234->matches( array( 'regular_price' => '10', 'sale_price' => '50.000001' ) ), 'sale basis excludes above' );
ok234( ! $filter234->matches( array( 'regular_price' => 'not-a-price', 'sale_price' => '' ) ), 'malformed stored price never matches' );
ok234( ! $filter234->matches( array( 'regular_price' => '', 'sale_price' => '' ) ), 'empty regular never matches an enabled range' );
// Six fractional digits are the configured precision ceiling; the seventh is refused.
ok234( Range234::from_inputs( '1', '0.000001', '999999999999.999999', 'regular_price' )->matches( array( 'regular_price' => '0.000001', 'sale_price' => '' ) ), 'base precision bounds accepted' );
error234( static fn() => Range234::from_inputs( '1', '0.0000001', '', 'regular_price' ), 'invalid_price_range' );

// ---- Selection fixtures ----
// term 2 nests under term 1. Parent 200 (regular 999: must be ignored) expands
// to variations judged by their own prices. 109 is unreadable, 110 spans both
// categories, 111 is an unsupported grouped type, 112 has a malformed price.
$GLOBALS['products234'] = array(
	101 => simple234( 101, '19.99', array( 'categories' => array( 1 ) ) ),
	102 => simple234( 102, '20.00', array( 'categories' => array( 1 ) ) ),
	103 => simple234( 103, '150.00', array( 'categories' => array( 1 ) ) ),
	104 => simple234( 104, '150.01', array( 'categories' => array( 1 ) ) ),
	105 => simple234( 105, '50', array( 'sale_price' => '45', 'categories' => array( 1 ) ) ),
	106 => simple234( 106, '50', array( 'categories' => array( 1 ) ) ),
	107 => simple234( 107, '50', array( 'sale_price' => '60', 'categories' => array( 1 ) ) ),
	108 => simple234( 108, '60', array( 'categories' => array( 2 ) ) ),
	110 => simple234( 110, '70', array( 'categories' => array( 1, 2 ) ) ),
	111 => new WC_Product_Grouped( array( 'id' => 111, 'categories' => array( 1 ) ) ),
	112 => simple234( 112, 'not-a-price', array( 'categories' => array( 1 ) ) ),
	200 => new WC_Product_Variable( array( 'id' => 200, 'regular_price' => '999', 'categories' => array( 1 ), 'children' => array( 201, 202 ) ) ),
	201 => new WC_Product_Variation( array( 'id' => 201, 'parent' => 200, 'regular_price' => '25', 'categories' => array( 1 ) ) ),
	202 => new WC_Product_Variation( array( 'id' => 202, 'parent' => 200, 'regular_price' => '500' ) ),
	109 => simple234( 109, '60', array( 'categories' => array( 1 ) ) ),
);
$GLOBALS['throw234'] = array( 109 );
$ids_spec234 = Spec234::ids( array( 101, 102, 103, 104, 105, 106, 107, 112 ), Range234::from_inputs( '1', '20', '150', 'regular_price' ) );
eq234( ids234( $ids_spec234 ), array( 102, 103, 105, 106, 107 ), 'explicit IDs filtered by regular range; malformed/outside excluded' );
$outcome_ids234 = outcome234( $ids_spec234 );
eq234( $outcome_ids234['outcome'], array( 'matched' => 5, 'excluded_by_range' => 3, 'unsupported' => 0 ), 'explicit ID outcome counts' );
// Sale basis: 105 matches on its sale price, 106 (blank sale) and 107 (sale 60) do not.
$sale_spec234 = Spec234::ids( array( 105, 106, 107 ), Range234::from_inputs( '1', '40', '50', 'sale_price' ) );
eq234( ids234( $sale_spec234 ), array( 105 ), 'sale basis uses the variation/product sale price, blank never matches' );
eq234( outcome234( $sale_spec234 )['outcome'], array( 'matched' => 1, 'excluded_by_range' => 2, 'unsupported' => 0 ), 'sale basis outcome counts' );
// Target field stays independent from the range basis: editing the sale field
// while filtering on the regular price still matches by the regular price.
$mixed_spec234 = Spec234::ids( array( 105, 106 ), Range234::from_inputs( '1', '20', '150', 'regular_price' ) );
eq234( ids234( $mixed_spec234 ), array( 105, 106 ), 'range basis independent of the operation target field' );
// Category scope: direct members exclude the nested category; opt-in includes it once.
$direct234 = Spec234::category( 1, false, Range234::from_inputs( '1', '20', '150', 'regular_price' ) );
eq234( ids234( $direct234 ), array( 102, 103, 105, 106, 107, 110, 201 ), 'direct category filtered; nested members excluded' );
$nested234 = Spec234::category( 1, true, Range234::from_inputs( '1', '20', '150', 'regular_price' ) );
eq234( ids234( $nested234 ), array( 102, 103, 105, 106, 107, 108, 110, 201 ), 'nested category filtered with overlap deduplicated once' );
eq234( outcome234( $nested234 )['outcome'], array( 'matched' => 8, 'excluded_by_range' => 5, 'unsupported' => 1 ), 'nested outcome: outside/malformed/grouped excluded, unreadable unsupported' );
// Variable parents expand to variations judged by their own price: the parent
// regular of 999 never admits child 202 (500), and child 201 (25) matches.
$parent_spec234 = Spec234::ids( array( 200 ), Range234::from_inputs( '1', '20', '150', 'regular_price' ) );
eq234( ids234( $parent_spec234 ), array( 201 ), 'variation final target judged by its own price, not the parent price' );
// An enabled range may exclude every requested ID; the empty population
// still freezes so Preview explains the match instead of refusing it.
$empty_spec234 = Spec234::ids( array( 101, 104 ), Range234::from_inputs( '1', '20', '150', 'regular_price' ) );
eq234( ids234( $empty_spec234 ), array(), 'all IDs excluded freezes empty, not refused' );
$empty_plan234 = plan234( $empty_spec234 );
eq234( $empty_plan234->data()['resolved_product_ids'], array(), 'empty frozen population' );
eq234( $empty_plan234->summary()['selected'], 0, 'empty frozen summary' );
eq234( $empty_plan234->data()['price_range']['excluded_by_range'], 2, 'empty-plan exclusions still counted' );
// Exact SKU honours the same predicate.
$GLOBALS['products234'][120] = simple234( 120, '30', array( 'sku' => 'WL234-SKU' ) );
eq234( ids234( Spec234::sku( 'WL234-SKU', Range234::from_inputs( '1', '20', '150', 'regular_price' ) ) ), array( 120 ), 'exact SKU inside the range' );
eq234( ids234( Spec234::sku( 'WL234-SKU', Range234::from_inputs( '1', '31', '150', 'regular_price' ) ) ), array(), 'exact SKU outside the range freezes empty' );
// Duplicate SKU fixtures stay ambiguous even with a range.
$GLOBALS['products234'][121] = simple234( 121, '30', array( 'sku' => 'WL234-SKU' ) );
error234( static fn() => Selector234::resolve( Spec234::sku( 'WL234-SKU', Range234::from_inputs( '1', '20', '150', 'regular_price' ) ) ), 'ambiguous_sku' );
unset( $GLOBALS['products234'][121] );
// Unsupported products keep their typed exclusion when unfiltered.
$plain_grouped234 = Selector234::resolve( Spec234::ids( array( 111 ) ) );
eq234( $plain_grouped234[0]->data()['exists'], true, 'grouped product exists' );
// Over-limit source populations refuse even when the range would narrow them:
// completeness cannot be proved inside the bounded window.
$GLOBALS['products234'] = array();
for ( $bulk234 = 1; $bulk234 <= 1001; ++$bulk234 ) { $GLOBALS['products234'][ $bulk234 ] = simple234( $bulk234, '25', array( 'categories' => array( 1 ) ) ); }
error234( static fn() => Selector234::resolve( Spec234::category( 1, false, Range234::from_inputs( '1', '20', '150', 'regular_price' ) ) ), 'selection_limit_exceeded' );
error234( static fn() => Selector234::resolve( Spec234::category( 1 ) ), 'selection_limit_exceeded' );
// Restore the focused fixtures for the remaining legs.
$GLOBALS['products234'] = array(
	101 => simple234( 101, '19.99', array( 'categories' => array( 1 ) ) ),
	102 => simple234( 102, '20.00', array( 'categories' => array( 1 ) ) ),
	103 => simple234( 103, '150.00', array( 'categories' => array( 1 ) ) ),
	104 => simple234( 104, '150.01', array( 'categories' => array( 1 ) ) ),
	105 => simple234( 105, '50', array( 'sale_price' => '45', 'categories' => array( 1 ) ) ),
	106 => simple234( 106, '50', array( 'categories' => array( 1 ) ) ),
	107 => simple234( 107, '50', array( 'sale_price' => '60', 'categories' => array( 1 ) ) ),
	108 => simple234( 108, '60', array( 'categories' => array( 2 ) ) ),
	110 => simple234( 110, '70', array( 'categories' => array( 1, 2 ) ) ),
	111 => new WC_Product_Grouped( array( 'id' => 111, 'categories' => array( 1 ) ) ),
	112 => simple234( 112, 'not-a-price', array( 'categories' => array( 1 ) ) ),
	200 => new WC_Product_Variable( array( 'id' => 200, 'regular_price' => '999', 'categories' => array( 1 ), 'children' => array( 201, 202 ) ) ),
	201 => new WC_Product_Variation( array( 'id' => 201, 'parent' => 200, 'regular_price' => '25', 'categories' => array( 1 ) ) ),
	202 => new WC_Product_Variation( array( 'id' => 202, 'parent' => 200, 'regular_price' => '500' ) ),
	109 => simple234( 109, '60', array( 'categories' => array( 1 ) ) ),
);

// ---- Count and authorization ----
$count234 = Selector234::discover_count( Spec234::category( 1, true, Range234::from_inputs( '1', '20', '150', 'regular_price' ) ) );
eq234( $count234['selected'], 8, 'count reports matched targets only' );
eq234( $count234['excluded_by_range'], 5, 'count reports range exclusions' );
eq234( $count234['unreadable'], 1, 'count keeps unreadable over the whole population' );
eq234( $count234['missing'], 0, 'count keeps missing over the whole population' );
eq234( $count234['range'], array( 'enabled' => true, 'min' => '20', 'max' => '150', 'basis' => 'regular_price' ), 'count echoes the frozen filter' );
$plain_count234 = Selector234::discover_count( Spec234::category( 1, true ) );
eq234( $plain_count234, array( 'selected' => 14, 'unreadable' => 1, 'missing' => 0 ), 'filter-free count shape unchanged' );
$GLOBALS['denied234'] = array( 102 );
error234( static fn() => Selector234::discover_count( Spec234::category( 1, true, Range234::from_inputs( '1', '20', '150', 'regular_price' ) ) ), 'permission_denied' );
$GLOBALS['denied234'] = array( 104 );
error234( static fn() => Selector234::discover_count( Spec234::category( 1, true, Range234::from_inputs( '1', '20', '150', 'regular_price' ) ) ), 'permission_denied' );
$GLOBALS['denied234'] = array();
$GLOBALS['actor234'] = 0;
error234( static fn() => Selector234::discover_count( Spec234::category( 1 ) ), 'permission_denied' );
$GLOBALS['actor234'] = 1;

// ---- Planner, frozen preview and safety ----
function plan234( $spec, $field = 'regular_price' ) {
	$snapshots = Selector234::resolve( $spec );
	return Plan234::create( 'wl234', '2026-10-08T00:00:00Z', 1, new WriteLeash\Price_Store_Context( 'USD', 2, '7.1.2', '11.1.2' ), Selector234::resolved_selection( $spec, $snapshots ), new WriteLeash\Price_Operation( 'INCREASE_PERCENT', '8', $field ), new WriteLeash\Safety_Policy( 1000, '100', '100', false, '100' ), $snapshots, outcome234( $spec )['outcome'] );
}
$plan_ids234 = plan234( $ids_spec234 );
eq234( $plan_ids234->data()['resolved_product_ids'], array( 102, 103, 105, 106, 107 ), 'frozen preview holds exactly the matched IDs' );
eq234( $plan_ids234->data()['selection']['price_range'], array( 'enabled' => true, 'min' => '20', 'max' => '150', 'basis' => 'regular_price' ), 'filter retained in hashed selection' );
eq234( $plan_ids234->data()['price_range'], array( 'filter' => array( 'enabled' => true, 'min' => '20', 'max' => '150', 'basis' => 'regular_price' ), 'matched' => 5, 'excluded_by_range' => 3, 'unsupported' => 0 ), 'range provenance frozen in plan material' );
eq234( $plan_ids234->price_range_context(), $plan_ids234->data()['price_range'], 'provenance accessor matches material' );
$rehydrated234 = Plan234::hydrate( json_decode( $plan_ids234->json(), true ) );
eq234( $rehydrated234->json(), $plan_ids234->json(), 'ranged plan hydrates byte-for-byte' );
eq234( $rehydrated234->data()['resolved_product_ids'], array( 102, 103, 105, 106, 107 ), 'resume keeps the approved frozen targets' );
// A changed filter is a different plan: approval binding invalidates.
$shifted234 = plan234( Spec234::ids( array( 101, 102, 103, 104, 105, 106, 107, 112 ), Range234::from_inputs( '1', '21', '150', 'regular_price' ) ) );
ok234( $shifted234->hash() !== $plan_ids234->hash(), 'filter change invalidates the approval hash' );
ok234( $shifted234->data()['resolved_product_ids'] !== $plan_ids234->data()['resolved_product_ids'], 'filter change freezes a different population' );
// A price move after Preview conflicts through the existing precondition;
// Apply never reselects, so the frozen population is unchanged.
$pre_match234 = $plan_ids234->precondition( 102, WriteLeash\Product_Price_Snapshot::read( 102, $GLOBALS['products234'][102] ), new WriteLeash\Price_Store_Context( 'USD', 2, '7.1.2', '11.1.2' ) );
eq234( $pre_match234['state'], 'MATCH', 'unchanged price still matches' );
$GLOBALS['products234'][102] = simple234( 102, '200.00', array( 'categories' => array( 1 ) ) );
$pre_conflict234 = $plan_ids234->precondition( 102, WriteLeash\Product_Price_Snapshot::read( 102, $GLOBALS['products234'][102] ), new WriteLeash\Price_Store_Context( 'USD', 2, '7.1.2', '11.1.2' ) );
eq234( $pre_conflict234, array( 'state' => 'CONFLICT', 'reasons' => array( 'regular_price_changed' ) ), 'post-preview price move conflicts instead of reselecting' );
$GLOBALS['products234'][102] = simple234( 102, '20.00', array( 'categories' => array( 1 ) ) );
$queries_before234 = $GLOBALS['queries234'];
Plan234::hydrate( json_decode( $plan_ids234->json(), true ) );
$plan_ids234->precondition( 103, WriteLeash\Product_Price_Snapshot::read( 103, $GLOBALS['products234'][103] ), new WriteLeash\Price_Store_Context( 'USD', 2, '7.1.2', '11.1.2' ) );
eq234( $GLOBALS['queries234'], $queries_before234, 'hydrate and precondition run zero discovery queries: no apply-time reselection' );
// Full planner path authorizes the whole source population: denying an
// out-of-range product still refuses instead of leaking the filtered count.
$planner_spec234 = Spec234::category( 1, true, Range234::from_inputs( '1', '20', '150', 'regular_price' ) );
$planned234 = WriteLeash\Woo_Price_Planner::preview( $planner_spec234, new WriteLeash\Price_Operation( 'SET', '80' ), new WriteLeash\Safety_Policy( 1000, '100', '100', false, '100' ) );
eq234( $planned234->data()['resolved_product_ids'], ids234( $planner_spec234 ), 'planner freezes the matched population' );
$GLOBALS['denied234'] = array( 104 );
error234( static fn() => WriteLeash\Woo_Price_Planner::preview( $planner_spec234, new WriteLeash\Price_Operation( 'SET', '80' ), new WriteLeash\Safety_Policy( 1000, '100', '100', false, '100' ) ), 'permission_denied' );
$GLOBALS['denied234'] = array();
// Protocol check: a mismatched outcome refuses loudly instead of persisting inventive counts.
error234( static fn() => Plan234::create( 'wl234-bad', '2026-10-08T00:00:00Z', 1, new WriteLeash\Price_Store_Context( 'USD', 2, '7.1.2', '11.1.2' ), Spec234::ids( array( 105 ) ), new WriteLeash\Price_Operation( 'SET', '80' ), new WriteLeash\Safety_Policy( 1000, '100', '100', false, '100' ), Selector234::resolve( Spec234::ids( array( 105 ) ) ), array( 'matched' => 99, 'excluded_by_range' => 0, 'unsupported' => 0 ) ), 'invalid_snapshot' );

// ---- Admin validation ----
$base_post234 = array( 'selector' => 'ids', 'ids' => '105,106', 'price_field' => 'regular_price' );
$admin_spec234 = Admin234::build_selection( array_merge( $base_post234, array( 'range_enabled' => '1', 'range_min' => '20.00', 'range_max' => '150', 'range_basis' => 'regular_price' ) ) );
eq234( $admin_spec234->price_range(), array( 'enabled' => true, 'min' => '20', 'max' => '150', 'basis' => 'regular_price' ), 'admin builds the merchant range into the spec' );
eq234( Admin234::build_selection( $base_post234 )->price_range()['enabled'], false, 'absent control leaves the spec unfiltered' );
eq234( Admin234::build_selection( array_merge( $base_post234, array( 'range_min' => 'garbage', 'range_basis' => 'cost_price' ) ) )->price_range()['enabled'], false, 'disabled filter ignores garbage bounds and basis' );
eq234( Admin234::build_selection( array_merge( $base_post234, array( 'range_enabled' => '1', 'range_min' => '0', 'range_max' => '0' ) ) )->price_range(), array( 'enabled' => true, 'min' => '0', 'max' => '0', 'basis' => 'regular_price' ), 'admin accepts a zero bound as a number, not empty input' );
eq234( Admin234::build_selection( array_merge( $base_post234, array( 'price_field' => 'sale_price', 'range_enabled' => '1', 'range_min' => '10' ) ) )->price_range()['basis'], 'sale_price', 'empty basis defaults to the target field' );
$cat_admin234 = Admin234::build_selection( array( 'selector' => 'category', 'category' => '7', 'price_field' => 'regular_price', 'range_enabled' => '1', 'range_min' => '20', 'range_max' => '150', 'range_basis' => 'sale_price' ) );
eq234( $cat_admin234->data()['term_id'], 7, 'category selection keeps its term' );
eq234( $cat_admin234->price_range()['basis'], 'sale_price', 'category selection carries an explicit sale basis' );
error234( static fn() => Admin234::build_selection( array_merge( $base_post234, array( 'range_enabled' => '1', 'range_min' => '150', 'range_max' => '20' ) ) ), 'invalid_price_range' );
error234( static fn() => Admin234::build_selection( array_merge( $base_post234, array( 'range_enabled' => '1', 'range_min' => '', 'range_max' => '' ) ) ), 'invalid_price_range' );
error234( static fn() => Admin234::build_selection( array_merge( $base_post234, array( 'range_enabled' => '1', 'range_min' => 'ten' ) ) ), 'invalid_price_range' );
error234( static fn() => Admin234::build_selection( array_merge( $base_post234, array( 'range_enabled' => '1', 'range_basis' => 'cost_price' ) ) ), 'invalid_price_range' );
error234( static fn() => Admin234::build_selection( array_merge( $base_post234, array( 'range_enabled' => '1', 'range_min' => array( '20' ) ) ) ), 'invalid_price_range' );
error234( static fn() => Admin234::build_selection( array_merge( $base_post234, array( 'range_enabled' => 'sometimes' ) ) ), 'invalid_price_range' );
// Admin copy: range-aware counts, invalid-range guidance and frozen summary.
$ranged_message234 = Admin234::selection_count_message( array( 'selected' => 5, 'unreadable' => 1, 'missing' => 0, 'excluded_by_range' => 3, 'range' => array( 'enabled' => true, 'min' => '20', 'max' => '150', 'basis' => 'regular_price' ) ) );
ok234( str_contains( $ranged_message234, '5' ) && str_contains( $ranged_message234, '3' ) && str_contains( $ranged_message234, 'regular prices' ) && ! str_contains( $ranged_message234, '%' ), 'range count states matched, excluded and basis with placeholders substituted' );
$empty_range_message234 = Admin234::selection_count_message( array( 'selected' => 0, 'unreadable' => 0, 'missing' => 0, 'excluded_by_range' => 4, 'range' => array( 'enabled' => true, 'min' => null, 'max' => '150', 'basis' => 'sale_price' ) ) );
ok234( str_contains( $empty_range_message234, 'No products match this price range' ) && str_contains( $empty_range_message234, 'sale prices' ), 'empty range count names the basis' );
$legacy_message234 = Admin234::selection_count_message( array( 'selected' => 2, 'unreadable' => 0, 'missing' => 0 ) );
ok234( str_contains( $legacy_message234, '2' ) && ! str_contains( $legacy_message234, 'range' ), 'filter-free count copy unchanged' );
ok234( str_contains( Admin234::reason_message( 'invalid_price_range' ), 'price range' ), 'invalid range guidance names the control' );
$summary234 = Admin234::price_range_summary( array( 'filter' => array( 'enabled' => true, 'min' => '20', 'max' => '150', 'basis' => 'regular_price' ), 'matched' => 5, 'excluded_by_range' => 3, 'unsupported' => 1 ), array( 'currency' => 'USD', 'price_decimals' => 2 ) );
ok234( str_contains( $summary234, '$20.00 USD' ) && str_contains( $summary234, '$150.00 USD' ) && str_contains( $summary234, 'never adds more' ), 'frozen summary shows formatted bounds, counts and the no-adds promise' );
$open_summary234 = Admin234::price_range_summary( array( 'filter' => array( 'enabled' => true, 'min' => null, 'max' => null, 'basis' => 'sale_price' ), 'matched' => 1, 'excluded_by_range' => 0, 'unsupported' => 0 ), array( 'currency' => 'USD', 'price_decimals' => 2 ) );
ok234( str_contains( $open_summary234, '1 matching product frozen' ), 'singular matched copy' );
// Rendered form: accessible labels, retained values and output escaping (no-JS identical fields).
$form234 = array( 'selector' => 'ids', 'ids' => '', 'sku' => '', 'category' => '', 'include_subcategories' => '', 'range_enabled' => '1', 'range_min' => '20.00', 'range_max' => '150', 'range_basis' => 'sale_price', 'operation' => 'SET', 'price_field' => 'sale_price', 'amount' => '', 'max_products' => '1000', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '20', 'product_search' => '', 'category_search' => '', 'product_page' => '1', 'category_page' => '1' );
$html234 = ( function ( $values ) { ob_start(); ( new ReflectionMethod( Admin234::class, 'render_selector_form' ) )->invoke( null, $values ); return (string) ob_get_clean(); } )( $form234 );
ok234( str_contains( $html234, 'id="writeleash-free-range-enabled"' ) && str_contains( $html234, 'for="writeleash-free-range-enabled"' ), 'range control has an accessible label' );
ok234( str_contains( $html234, 'id="writeleash-free-range_min"' ) && str_contains( $html234, 'for="writeleash-free-range_min"' ), 'minimum input has an accessible label' );
ok234( str_contains( $html234, 'id="writeleash-free-range_max"' ) && str_contains( $html234, 'for="writeleash-free-range_max"' ), 'maximum input has an accessible label' );
ok234( str_contains( $html234, 'id="writeleash-free-range-basis"' ) && str_contains( $html234, 'for="writeleash-free-range-basis"' ), 'basis control has an accessible label' );
ok234( str_contains( $html234, 'value="20.00"' ) && str_contains( $html234, 'value="150"' ), 'entered bounds retained on redisplay' );
ok234( str_contains( $html234, 'Minimum price (USD)' ) && str_contains( $html234, 'Maximum price (USD)' ), 'currency semantics visible on the inputs' );
$evil234 = $form234;
$evil234['range_min'] = '"><script>alert(1)</script>';
$evil_html234 = ( function ( $values ) { ob_start(); ( new ReflectionMethod( Admin234::class, 'render_selector_form' ) )->invoke( null, $values ); return (string) ob_get_clean(); } )( $evil234 );
ok234( str_contains( $evil_html234, '&lt;script&gt;' ) && ! str_contains( $evil_html234, '<script>alert' ), 'retained bound escaped at the HTML boundary' );

echo '#234 stub contract PASS (' . $checks234 . " assertions); actual Woo runtime NOT_TESTED\n";
