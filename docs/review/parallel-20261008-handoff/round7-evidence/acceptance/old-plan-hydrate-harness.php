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
class WC_Cache_Helper { public static function invalidate_cache_group( $group ): void {} }
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
$GLOBALS['filters206'] = array();
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['filters206'][ $tag ][ (int) $priority ][] = array( 'callback' => $callback, 'accepted_args' => (int) $accepted_args ); return true; }
function remove_filter( $tag, $callback, $priority = 10 ) {
 if ( empty( $GLOBALS['filters206'][ $tag ][ (int) $priority ] ) ) { return false; }
 foreach ( $GLOBALS['filters206'][ $tag ][ (int) $priority ] as $key => $registered ) {
  if ( $registered['callback'] === $callback ) { unset( $GLOBALS['filters206'][ $tag ][ (int) $priority ][ $key ] ); }
 }
 if ( ! $GLOBALS['filters206'][ $tag ][ (int) $priority ] ) { unset( $GLOBALS['filters206'][ $tag ][ (int) $priority ] ); }
 if ( ! $GLOBALS['filters206'][ $tag ] ) { unset( $GLOBALS['filters206'][ $tag ] ); }
 return true;
}
/** WP apply_filters semantics: value first, then accepted extra args. */
function apply_filters206( $tag, $value, ...$extra ) {
 if ( empty( $GLOBALS['filters206'][ $tag ] ) ) { return $value; }
 $priorities = $GLOBALS['filters206'][ $tag ];
 ksort( $priorities );
 foreach ( $priorities as $callbacks ) {
  foreach ( $callbacks as $registered ) {
   $args = array_merge( array( $value ), array_slice( $extra, 0, max( 0, (int) $registered['accepted_args'] - 1 ) ) );
   $value = call_user_func_array( $registered['callback'], $args );
  }
 }
 return $value;
}
class WP_Query {
 public array $posts = array();
 public array $query_vars = array();
 public function get( $key ) { return $this->query_vars[ $key ] ?? ''; }
 public function __construct( array $args ) {
  $this->query_vars = $args;
  $GLOBALS['query206'] = $args;
  if ( ! empty( $GLOBALS['throw_query206'] ) ) { throw new RuntimeException( 'fixture query construction failure' ); }
  if ( ! empty( $GLOBALS['nested_probe206'] ) ) {
   // An unrelated query built while the discovery guard is registered (the
   // pre_get_posts shape). It must not inherit the discovery DISTINCT.
   $GLOBALS['nested_probe206'] = false;
   new self( array( 'post__in' => array(), 'posts_per_page' => 10, 'writeleash_probe' => true ) );
   $GLOBALS['query206'] = $args;
  }
  $ids = $args['post__in'] ?? array_keys( $GLOBALS['products206'] );
  $rows = array();
  foreach ( $ids as $id ) {
   $product = $GLOBALS['products206'][$id] ?? null;
   if ( ! $product ) { continue; }
   $matches = 1;
   $tax = $args['tax_query'][0] ?? null;
   if ( $tax ) {
    $matches = 0;
    foreach ( $product->get_category_ids() as $category ) {
     $category = (int) $category;
     do {
      if ( in_array( $category, $tax['terms'], true ) ) { ++$matches; break; }
      $category = $tax['include_children'] ? ( $GLOBALS['terms206'][$category] ?? 0 ) : 0;
     } while ( $category );
    }
    if ( ! $matches ) { continue; }
   }
   // A real tax_query join is one row per matching membership; SQL keeps the
   // duplicate rows unless DISTINCT applies. force_raw_rows206 models an
   // environment that ignores the requested DISTINCT.
   for ( $row = 0; $row < $matches; ++$row ) { $rows[] = (object) array( 'ID' => (int) $id ); }
  }
  usort( $rows, static fn( $a, $b ) => $a->ID <=> $b->ID );
  if ( ! empty( $GLOBALS['window_override206'] ) ) { $this->query_vars['posts_per_page'] = (int) $GLOBALS['window_override206']; }
  $distinct = apply_filters206( 'posts_distinct', '', $this );
  $GLOBALS['distinct206'][] = array( 'token' => $this->query_vars['writeleash_discovery_token'] ?? '', 'distinct' => $distinct );
  if ( empty( $GLOBALS['force_raw_rows206'] ) && 'DISTINCT' === $distinct ) {
   $collapsed = array();
   foreach ( $rows as $row ) { $collapsed[ $row->ID ] = $row; }
   $rows = array_values( $collapsed );
  }
  $this->posts = array_slice( $rows, 0, (int) $this->query_vars['posts_per_page'] );
  if ( ! empty( $GLOBALS['inject_results206'] ) ) { $this->posts = call_user_func( $GLOBALS['inject_results206'], $this->posts ); }
 }
}
$checks206 = 0;
function eq206( $actual, $expected, string $label ) { global $checks206; ++$checks206; if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( $actual ) . ' != ' . json_encode( $expected ) ); } }
function error206( callable $call, string $reason ) { try { $call(); } catch ( WriteLeash\Price_Validation_Error $e ) { eq206( $e->reason(), $reason, 'refusal ' . $reason ); return; } throw new RuntimeException( 'Expected refusal: ' . $reason ); }
function ids206( S $selection ) { return array_map( static fn( $snapshot ) => $snapshot->data()['product_id'], Selector::resolve( $selection ) ); }
function plan206( S $selection ) { $snapshots = Selector::resolve( $selection ); return Plan::create( 'wl206', '2026-10-08T00:00:00Z', 1, WriteLeash\Price_Store_Context::current(), Selector::resolved_selection( $selection, $snapshots ), new WriteLeash\Price_Operation( 'SET', '80' ), new WriteLeash\Safety_Policy( 1000, '100', '100', false, '100' ), $snapshots ); }

echo Plan::hydrate(json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR))->json();
