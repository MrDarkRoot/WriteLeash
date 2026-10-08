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

// #206 P1 defensive regression: a tax_query join returns one row per matching
// descendant term. PHP keying by ID is only exact when the raw page provably
// holds every matching row, so duplicates on a short page are accepted while
// a full duplicate page fails closed (negative discriminator: the old
// row-count path accepted this page; SQL may have truncated distinct posts
// before dedupe could see them). force_raw_rows206 makes the stub behave like
// a backend that did not collapse the requested DISTINCT join.
$GLOBALS['terms206'] = array( 1 => 0 );
for ( $t206 = 2; $t206 <= 2501; ++$t206 ) { $GLOBALS['terms206'][$t206] = 1; }
$GLOBALS['products206'] = array( 9000 => new WC_Product_Simple( 9000, array( 'categories' => range( 1, 2501 ) ) ) );
$GLOBALS['force_raw_rows206'] = true;
$GLOBALS['reads206'] = 0;
error206( static fn() => Selector::discover_count( S::category( 1, true ) ), 'selection_limit_exceeded' );
eq206( $GLOBALS['reads206'], 0, '2,501 duplicate raw rows fill the 2,001-row window without DISTINCT: fail closed before any Woo read' );
eq206( $GLOBALS['filters206'], array(), 'posts_distinct guard removed after the refused query' );
$GLOBALS['force_raw_rows206'] = false;
eq206( Selector::discover_count( S::category( 1, true ) )['selected'], 1, 'DISTINCT query accepts the same duplicate-member population' );
eq206( $GLOBALS['query206']['posts_per_page'], 2001, 'category sentinel remains 2,001' );

// DISTINCT collapses joins before LIMIT, so an otherwise-valid population is
// never truncated by its own overlapping memberships. 1,003 rows for one
// product plus 999 simple targets is a valid 1,000-target selection; without
// collapsing, the 2,001-row page is full and duplicate-crowded, so the old
// row-count path resolved a truncated 999-product page. The fail-closed check
// refuses that page instead.
$GLOBALS['terms206'] = array( 1 => 0 );
for ( $t206 = 2; $t206 <= 1003; ++$t206 ) { $GLOBALS['terms206'][$t206] = 1; }
$GLOBALS['products206'] = array( 8000 => new WC_Product_Simple( 8000, array( 'categories' => range( 1, 1003 ) ) ) );
for ( $id206 = 8001; $id206 <= 8999; ++$id206 ) { $GLOBALS['products206'][$id206] = new WC_Product_Simple( $id206, array( 'categories' => array( 1 ) ) ); }
eq206( Selector::discover_count( S::category( 1, true ) )['selected'], 1000, 'DISTINCT exposes a crowded valid population completely' );
$GLOBALS['force_raw_rows206'] = true;
$GLOBALS['reads206'] = 0;
error206( static fn() => Selector::discover_count( S::category( 1, true ) ), 'selection_limit_exceeded' );
eq206( $GLOBALS['reads206'], 0, 'stripped DISTINCT full duplicate window refuses instead of resolving the old truncated 999-product page' );
$GLOBALS['force_raw_rows206'] = false;

// Round-3 completeness matrix: a short duplicate page stays exact (every
// matching row was returned), while a rewritten window or a full/injected
// duplicate page refuses before any Woo read.
$GLOBALS['terms206'] = array( 1 => 0, 2 => 1 );
$GLOBALS['products206'] = array(
 9100 => new WC_Product_Simple( 9100, array( 'categories' => array( 1, 2 ) ) ),
 9101 => new WC_Product_Simple( 9101, array( 'categories' => array( 1 ) ) ),
);
$GLOBALS['force_raw_rows206'] = true;
$GLOBALS['reads206'] = 0;
eq206( Selector::discover_count( S::category( 1, true ) ), array( 'selected' => 2, 'unreadable' => 0, 'missing' => 0 ), 'short duplicate raw page is complete: dedupe exact' );
eq206( $GLOBALS['reads206'], 2, 'short duplicate raw page reads each distinct product once' );
eq206( $GLOBALS['filters206'], array(), 'guard removed after the short duplicate page' );
$GLOBALS['force_raw_rows206'] = false;

$GLOBALS['nested_probe206'] = true;
$GLOBALS['distinct206'] = array();
eq206( Selector::discover_count( S::category( 1, true ) )['selected'], 2, 'token-scoped guard still collapses the discovery query itself' );
eq206( $GLOBALS['distinct206'][0], array( 'token' => '', 'distinct' => '' ), 'nested/unrelated query while the guard is registered keeps its own distinct clause' );
eq206( $GLOBALS['distinct206'][1]['distinct'], 'DISTINCT', 'discovery query receives DISTINCT through its private token' );
eq206( $GLOBALS['filters206'], array(), 'guard removed after the token-scoping probe' );

$GLOBALS['throw_query206'] = true;
$thrown206 = null;
try { Selector::discover_count( S::category( 1, true ) ); }
catch ( RuntimeException $error206 ) { $thrown206 = $error206; }
eq206( $thrown206 instanceof RuntimeException, true, 'query construction failure propagates' );
eq206( $GLOBALS['filters206'], array(), 'guard removed after an exceptional query construction' );
$GLOBALS['throw_query206'] = false;

$GLOBALS['window_override206'] = 500;
$GLOBALS['reads206'] = 0;
error206( static fn() => Selector::discover_count( S::category( 1, true ) ), 'selection_limit_exceeded' );
eq206( $GLOBALS['reads206'], 0, 'rewritten bounded window refuses before any Woo read' );
eq206( $GLOBALS['query206']['posts_per_page'], 2001, 'selector still requests the fixed 2,001-row window' );
$GLOBALS['window_override206'] = null;

$GLOBALS['inject_results206'] = static fn( $posts ) => array_merge( $posts, array_fill( 0, 2001, $posts[0] ) );
$GLOBALS['reads206'] = 0;
error206( static fn() => Selector::discover_count( S::category( 1, true ) ), 'selection_limit_exceeded' );
eq206( $GLOBALS['reads206'], 0, 'injected full-window duplicate rows refuse before any Woo read' );
$GLOBALS['inject_results206'] = static fn( $posts ) => array_merge( $posts, array_fill( 0, 3, $posts[0] ) );
eq206( Selector::discover_count( S::category( 1, true ) )['selected'], 2, 'injected duplicate rows on a short page are deduplicated exactly' );
eq206( $GLOBALS['reads206'], 2, 'injected short-page duplicates read each distinct product once' );
$GLOBALS['inject_results206'] = null;

// A distinct raw population above the bound is still refused before any read:
// 2,001 distinct rows imply more than 1,000 final targets.
$GLOBALS['terms206'] = array( 1 => 0 );
$GLOBALS['products206'] = array();
for ( $id206 = 1; $id206 <= 2001; ++$id206 ) { $GLOBALS['products206'][$id206] = new WC_Product_Simple( $id206, array( 'categories' => array( 1 ) ) ); }
$GLOBALS['reads206'] = 0;
error206( static fn() => Selector::discover_count( S::category( 1 ) ), 'selection_limit_exceeded' );
eq206( $GLOBALS['reads206'], 0, '2,001 distinct raw rows refuse before any Woo read' );

// Exact raw boundary: 2,000 distinct rows (1,000 core parents plus their
// directly categorized 1,000 children) deduplicate to 1,000 accepted targets;
// one further distinct row is refused.
$GLOBALS['terms206'] = array( 1 => 0 );
$GLOBALS['products206'] = array();
for ( $i206 = 0; $i206 < 1000; ++$i206 ) {
 $parent206 = 5000 + $i206;
 $child206 = 7000 + $i206;
 $GLOBALS['products206'][$parent206] = new WC_Product_Variable( $parent206, array( 'categories' => array( 1 ), 'children' => array( $child206 ) ) );
 $GLOBALS['products206'][$child206] = new WC_Product_Variation( $child206, array( 'parent' => $parent206, 'categories' => array( 1 ) ) );
}
eq206( Selector::discover_count( S::category( 1 ) )['selected'], 1000, '2,000 distinct raw rows deduplicate to 1,000 accepted targets' );
$GLOBALS['products206'][9000] = new WC_Product_Simple( 9000, array( 'categories' => array( 1 ) ) );
$GLOBALS['reads206'] = 0;
error206( static fn() => Selector::discover_count( S::category( 1 ) ), 'selection_limit_exceeded' );
eq206( $GLOBALS['reads206'], 0, '2,001 distinct raw rows refuse before expansion reads' );

eq206( Selector::MAX_SELECTED, 1000, 'MAX_SELECTED untouched' );
eq206( WriteLeash\Free_Support_Contract::MAX_JOB_PRODUCTS, 1000, 'MAX_JOB_PRODUCTS untouched' );
echo '#206 stub contract PASS (' . $checks206 . " assertions); actual Woo runtime NOT_TESTED\n";
