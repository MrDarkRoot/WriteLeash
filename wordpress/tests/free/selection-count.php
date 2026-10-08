<?php
/** #206 contract harness. Query/Woo stubs are NOT Woo runtime evidence. */
define( 'ABSPATH', __DIR__ );
$source = getenv( 'WL206_SOURCE' ) ?: __DIR__ . '/../../writeleash/includes/free';
foreach ( array( 'price-decimal', 'price-operation', 'safety-policy', 'product-snapshot', 'product-selector', 'change-plan', 'free-support-contract', 'free-admin' ) as $file ) { require $source . '/class-' . $file . '.php'; }
use WriteLeash\Price_Selection_Spec as S;
use WriteLeash\Product_Price_Selector as Selector;
use WriteLeash\Change_Plan as Plan;
use WriteLeash\Free_Admin as Admin;
$GLOBALS['wp_version'] = '7.1.2';
define( 'WC_VERSION', '11.1.2' );
$GLOBALS['actor206'] = 1;
$GLOBALS['denied206'] = array();
$GLOBALS['products206'] = array();
$GLOBALS['terms206'] = array( 1 => 0, 2 => 1, 3 => 2, 4 => 0 );
$GLOBALS['throw206'] = array();
$GLOBALS['reads206'] = 0;
function get_current_user_id() { return $GLOBALS['actor206']; }
function current_user_can( $cap, $id = 0 ) { return $GLOBALS['actor206'] > 0 && ! in_array( $id, $GLOBALS['denied206'], true ); }
function clean_post_cache( $id ) {}
function wp_cache_delete( $key, $group = '' ) { return true; }
function did_action( $hook ) { return 1; }
function get_woocommerce_currency() { return 'USD'; }
function wc_get_price_decimals() { return 2; }
function get_option( $key ) { return 'USD'; }
function is_wp_error( $v ) { return false; }
function get_term( $id, $taxonomy ) { return array_key_exists( $id, $GLOBALS['terms206'] ) ? (object) array( 'term_id' => $id ) : false; }
function wc_get_product( $id ) {
 ++$GLOBALS['reads206'];
 if ( in_array( $id, $GLOBALS['throw206'], true ) ) { throw new RuntimeException( 'fixture unreadable' ); }
 return $GLOBALS['products206'][$id] ?? false;
}
class WC_Product {
 protected array $v;
 public function __construct( int $id, array $v = array() ) { $this->v = ! $v && isset( $GLOBALS['products206'][$id] ) ? $GLOBALS['products206'][$id]->v : array_merge( array( 'id' => $id, 'categories' => array(), 'children' => array(), 'parent' => 0 ), $v ); }
 public function get_id() { return $this->v['id']; }
 public function get_name( $context = 'view' ) { return 'Product ' . $this->get_id(); }
 public function get_sku( $context = 'view' ) { return ''; }
 public function get_type() { return 'simple'; }
 public function get_status( $context = 'view' ) { return 'publish'; }
 public function get_regular_price( $context = 'view' ) { return '100.00'; }
 public function get_sale_price( $context = 'view' ) { return ''; }
 public function get_date_on_sale_from( $context = 'view' ) { return null; }
 public function get_date_on_sale_to( $context = 'view' ) { return null; }
 public function get_category_ids( $context = 'view' ) { return $this->v['categories']; }
 public function get_parent_id( $context = 'view' ) { return $this->v['parent']; }
 public function get_children() { return $this->v['children']; }
 public function get_attributes() { return array(); }
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variable extends WC_Product { public function get_type() { return 'variable'; } }
class WC_Product_Variation extends WC_Product { public function get_type() { return 'variation'; } }
class WC_Product_Factory {
 public static function get_product_type( $id ) { ++$GLOBALS['reads206']; if ( in_array( $id, $GLOBALS['throw206'], true ) ) { throw new RuntimeException( 'fixture unreadable' ); } return isset( $GLOBALS['products206'][$id] ) ? $GLOBALS['products206'][$id]->get_type() : false; }
 public static function get_product_classname( $id, $type, $post_type = '' ) { return get_class( $GLOBALS['products206'][$id] ); }
}
class WP_Query {
 public array $posts = array();
 public function __construct( array $args ) {
  $GLOBALS['query206'] = $args;
  $ids = $args['post__in'] ?? array_keys( $GLOBALS['products206'] );
  foreach ( $ids as $id ) {
   $product = $GLOBALS['products206'][$id] ?? null;
   if ( ! $product ) { continue; }
   $tax = $args['tax_query'][0] ?? null;
   if ( $tax ) {
    $match = false;
    foreach ( $product->get_category_ids() as $category ) {
     do {
      if ( in_array( $category, $tax['terms'], true ) ) { $match = true; break; }
      $category = $tax['include_children'] ? ( $GLOBALS['terms206'][$category] ?? 0 ) : 0;
     } while ( $category );
    }
    if ( ! $match ) { continue; }
   }
   $this->posts[] = (object) array( 'ID' => $id );
  }
  usort( $this->posts, static fn( $a, $b ) => $a->ID <=> $b->ID );
  $this->posts = array_slice( $this->posts, 0, $args['posts_per_page'] );
 }
}
$checks206 = 0;
function eq206( $actual, $expected, string $label ) { global $checks206; ++$checks206; if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( $actual ) . ' != ' . json_encode( $expected ) ); } }
function error206( callable $call, string $reason ) { try { $call(); } catch ( WriteLeash\Price_Validation_Error $e ) { eq206( $e->reason(), $reason, 'refusal ' . $reason ); return; } throw new RuntimeException( 'Expected refusal: ' . $reason ); }
function ids206( S $selection ) { return array_map( static fn( $snapshot ) => $snapshot->data()['product_id'], Selector::resolve( $selection ) ); }
function plan206( S $selection ) { $snapshots = Selector::resolve( $selection ); return Plan::create( 'wl206', '2026-10-08T00:00:00Z', 1, WriteLeash\Price_Store_Context::current(), Selector::resolved_selection( $selection, $snapshots ), new WriteLeash\Price_Operation( 'SET', '80' ), new WriteLeash\Safety_Policy( 1000, '100', '100', false, '100' ), $snapshots ); }
$GLOBALS['products206'] = array(
 10 => new WC_Product_Simple( 10, array( 'categories' => array( 1 ) ) ),
 11 => new WC_Product_Simple( 11, array( 'categories' => array( 2 ) ) ),
 12 => new WC_Product_Simple( 12, array( 'categories' => array( 3 ) ) ),
 13 => new WC_Product_Simple( 13, array( 'categories' => array( 1, 2, 3 ) ) ),
 20 => new WC_Product_Variable( 20, array( 'categories' => array( 2, 3 ), 'children' => array( 21, 22, 21 ) ) ),
 21 => new WC_Product_Variation( 21, array( 'parent' => 20, 'categories' => array( 1, 2 ) ) ),
 22 => new WC_Product_Variation( 22, array( 'parent' => 20 ) ),
 30 => new WC_Product_Variable( 30, array( 'categories' => array( 3 ) ) ),
);
eq206( S::category( 1 )->data()['include_children'], false, 'default direct scope' );
eq206( S::category( 1, true )->data()['include_children'], true, 'explicit opt-in' );
eq206( ids206( S::category( 1 ) ), array( 10, 13, 21 ), 'direct members remain default' );
eq206( ids206( S::category( 1, true ) ), array( 10, 11, 12, 13, 21, 22, 30 ), 'nested membership, parent expansion, overlap dedup, no-child parent' );
eq206( Selector::discover_count( S::category( 1, true ) ), array( 'selected' => 7, 'unreadable' => 0, 'missing' => 0 ), 'count shares actual targets incl unsupported parent' );
eq206( Selector::discover_count( S::category( 4 ) )['selected'], 0, 'empty category' );
error206( static fn() => Selector::discover_count( S::category( 999 ) ), 'invalid_category' );
$GLOBALS['throw206'] = array( 12 );
eq206( Selector::discover_count( S::category( 1, true ) ), array( 'selected' => 7, 'unreadable' => 1, 'missing' => 0 ), 'unreadable target remains counted and explained' );
$GLOBALS['throw206'] = array();
$GLOBALS['denied206'] = array( 22 );
error206( static fn() => Selector::discover_count( S::category( 1, true ) ), 'permission_denied' );
$GLOBALS['denied206'] = array();
$GLOBALS['actor206'] = 0;
error206( static fn() => Selector::discover_count( S::category( 1 ) ), 'permission_denied' );
$GLOBALS['actor206'] = 1;
eq206( Selector::discover_count( S::ids( array( 10, 999 ) ) ), array( 'selected' => 2, 'unreadable' => 0, 'missing' => 1 ), 'missing explicit target remains counted and explained' );
$old206 = plan206( S::category( 1 ) );
$old_bytes206 = $old206->json();
eq206( Plan::hydrate( json_decode( $old_bytes206, true ) )->json(), $old_bytes206, 'historical direct plan hydrates byte-for-byte' );
$count206 = Selector::discover_count( S::category( 1, true ) )['selected'];
$GLOBALS['products206'][14] = new WC_Product_Simple( 14, array( 'categories' => array( 2 ) ) );
$preview206 = plan206( S::category( 1, true ) );
eq206( $preview206->summary()['selected'], $count206 + 1, 'Preview independently resolves after count' );
eq206( $preview206->data()['selection']['include_children'], true, 'scope retained in hashed plan context' );
eq206( $preview206->hash() !== $old206->hash(), true, 'scope/population bound to hash' );
$GLOBALS['products206'][15] = new WC_Product_Simple( 15, array( 'categories' => array( 3 ) ) );
eq206( Plan::hydrate( json_decode( $preview206->json(), true ) )->data()['resolved_product_ids'], $preview206->data()['resolved_product_ids'], 'category changes after Preview never change frozen population' );
eq206( in_array( 15, $preview206->data()['resolved_product_ids'], true ), false, 'unseen later target absent' );
eq206( Admin::build_selection( array( 'selector' => 'category', 'category' => '1' ) )->data()['include_children'], false, 'Admin unchecked default' );
eq206( Admin::build_selection( array( 'selector' => 'category', 'category' => '1', 'include_subcategories' => '1' ) )->data()['include_children'], true, 'Admin strict opt-in' );
foreach ( array( '0', 'true', array( '1' ), 1 ) as $bad206 ) { error206( static fn() => Admin::build_selection( array( 'selector' => 'category', 'category' => '1', 'include_subcategories' => $bad206 ) ), 'invalid_category' ); }
$GLOBALS['products206'] = array();
for ( $id206 = 1; $id206 <= 1001; ++$id206 ) { $GLOBALS['products206'][$id206] = new WC_Product_Simple( $id206, array( 'categories' => array( $id206 <= 1000 ? 1 : 2 ) ) ); }
eq206( Selector::discover_count( S::category( 1 ) )['selected'], 1000, '1000 category targets accepted' );
error206( static fn() => Selector::discover_count( S::category( 1, true ) ), 'selection_limit_exceeded' );
$GLOBALS['products206'] = array( 2000 => new WC_Product_Variable( 2000, array( 'categories' => array( 1 ), 'children' => range( 3000, 4999 ) ) ) );
for ( $id206 = 3000; $id206 < 5000; ++$id206 ) { $GLOBALS['products206'][$id206] = new WC_Product_Variation( $id206, array( 'parent' => 2000 ) ); }
$GLOBALS['reads206'] = 0;
error206( static fn() => Selector::discover_count( S::category( 1 ) ), 'selection_limit_exceeded' );
eq206( $GLOBALS['reads206'], 1002, 'oversized variable stops after parent and 1001 child reads' );
$GLOBALS['products206'][2000] = new WC_Product_Variable( 2000, array( 'categories' => array( 1 ), 'children' => range( 3000, 3999 ) ) );
eq206( Selector::discover_count( S::category( 1 ) )['selected'], 1000, '1000 expanded variations accepted' );
for ( $id206 = 3000; $id206 < 4000; ++$id206 ) { $GLOBALS['products206'][$id206] = new WC_Product_Variation( $id206, array( 'parent' => 2000, 'categories' => array( 1 ) ) ); }
eq206( Selector::discover_count( S::category( 1 ) )['selected'], 1000, '1001 raw parent and directly categorized children deduplicate to 1000 targets' );
eq206( $GLOBALS['query206']['posts_per_page'], 2001, 'category raw discovery has fixed sentinel bound' );
$GLOBALS['products206'][6000] = new WC_Product_Simple( 6000, array( 'categories' => array( 1 ) ) );
error206( static fn() => Selector::discover_count( S::category( 1 ) ), 'selection_limit_exceeded' );
$GLOBALS['products206'] = array();
for ( $id206 = 1; $id206 <= 1001; ++$id206 ) { $GLOBALS['products206'][$id206] = new WC_Product_Variable( $id206, array( 'categories' => array( 1 ) ) ); }
error206( static fn() => Selector::discover_count( S::category( 1 ) ), 'selection_limit_exceeded' );
echo '#206 stub contract PASS (' . $checks206 . " assertions); actual Woo runtime NOT_TESTED\n";
