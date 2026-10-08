<?php
/** #209 public-read contract model. This does not certify actual Woo/cache runtime. */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../../writeleash/includes/free/class-price-decimal.php';
require __DIR__ . '/../../writeleash/includes/free/class-product-snapshot.php';
use WriteLeash\Product_Price_Snapshot as Snapshot209;

$catalog209 = array( 1 => array( 'type' => 'simple', 'regular_price' => '100' ) );
$metadata209 = array(); $types209 = array(); $factory_instances209 = array(); $calls209 = array();
class WC_Product {
	protected int $id;
	protected array $data;
	public function __construct( int $id ) {
		$this->id = $id;
		$this->data = $GLOBALS['metadata209'][ $id ] ?? $GLOBALS['catalog209'][ $id ];
		$GLOBALS['metadata209'][ $id ] = $this->data;
	}
	public function get_id() { return isset( $GLOBALS['wrong_id209'] ) ? (int) $GLOBALS['wrong_id209'] : $this->id; }
	public function get_regular_price( $context = 'view' ) { return $this->data['regular_price']; }
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variation extends WC_Product {}
class WC_Product_Variable extends WC_Product {}
class WC_Product_External209 extends WC_Product_Simple {
	public function __construct( int $id ) { $GLOBALS['external209_constructed'] = true; parent::__construct( $id ); }
}
// A resolved classname that is not a WC_Product at all is a broken mapping,
// never a product class, and is refused without ever being constructed.
class WC_Product_WrongId209 { public function get_id() { return 2; } }
class WC_Product_Factory {
	public static function get_product_type( $id ) { return $GLOBALS['types209'][ $id ] ?? ( $GLOBALS['catalog209'][ $id ]['type'] ?? false ); }
	public static function get_product_classname( $id, $type ) {
		if ( array_key_exists( 'override209', $GLOBALS ) && null !== $GLOBALS['override209'] ) { return $GLOBALS['override209']; }
		if ( 'variation' === $type ) { return 'WC_Product_Variation'; }
		if ( 'variable' === $type ) { return 'WC_Product_Variable'; }
		return 'WC_Product_Simple';
	}
}
class WC_Cache_Helper {
	public static function invalidate_cache_group( $group ): void {
		$GLOBALS['calls209'][] = array( 'group', $group );
		unset( $GLOBALS['types209'][ (int) substr( $group, 8 ) ] );
	}
}
function clean_post_cache( $id ): void { $GLOBALS['calls209'][] = array( 'post', $id ); }
function wp_cache_delete( $id, $group ): bool {
	$GLOBALS['calls209'][] = array( $group, $id );
	if ( 'post_meta' === $group ) { unset( $GLOBALS['metadata209'][ $id ] ); }
	return true;
}
function wc_get_product( $id ) {
	return $GLOBALS['factory_instances209'][ $id ] ?? ( $GLOBALS['factory_instances209'][ $id ] = new WC_Product_Simple( $id ) );
}
$checks209 = 0;
$assert209 = static function ( $actual, $expected, string $label ) use ( &$checks209 ): void {
	++$checks209;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( $actual, $expected ) ) ); }
};
$refuse209 = static function ( callable $callback, string $reason ) use ( $assert209 ): void {
	try { $callback(); throw new RuntimeException( 'expected refusal' ); }
	catch ( WriteLeash\Price_Validation_Error $error ) { $assert209( $error->getMessage(), $reason, 'typed freshness refusal' ); }
};
$old209 = wc_get_product( 1 );
// Model the other request's committed price/type while retaining this request's
// factory object and cached metadata/type. No eviction hook is supplied here.
$catalog209[1]['regular_price'] = '120';
$assert209( wc_get_product( 1 )->get_regular_price( 'edit' ), '100', 'negative control: primed factory instance stale' );
$fresh209 = Snapshot209::fresh_product( 1 );
$assert209( $fresh209->get_regular_price( 'edit' ), '120', 'constructor reads fresh metadata without instance callback' );
$assert209( $fresh209 === $old209, false, 'fresh read never returns the retained factory instance' );
$assert209( array_slice( $calls209, 0, 3 ), array( array( 'post', 1 ), array( 'post_meta', 1 ), array( 'group', 'product_1' ) ), 'targeted cleanup precedes public type lookup' );
$types209[1] = 'simple'; $catalog209[1]['type'] = 'variation';
$assert209( get_class( Snapshot209::fresh_product( 1 ) ), 'WC_Product_Variation', 'stale cached type is not reused' );
$catalog209[1]['type'] = 'variable';
$assert209( get_class( Snapshot209::fresh_product( 1 ) ), 'WC_Product_Variable', 'public variable class constructed literally' );
// An extension classname resolved by the public factory is a stable
// unsupported product class: terminal refusal, never instantiated (the
// runtime has no dynamic class dependency) and never substituted with a core
// class that could become eligible for a write.
$GLOBALS['external209_constructed'] = false;
$override209 = 'WC_Product_External209';
$refuse209( static fn() => Snapshot209::fresh_product( 1 ), 'unsupported_product_type' );
$assert209( $GLOBALS['external209_constructed'], false, 'extension class is never constructed' );
$override209 = 'stdClass'; $refuse209( static fn() => Snapshot209::fresh_product( 1 ), 'unreadable_product_data' );
$override209 = 'WC_Product_WrongId209'; $refuse209( static fn() => Snapshot209::fresh_product( 1 ), 'unreadable_product_data' );
$override209 = false; $refuse209( static fn() => Snapshot209::fresh_product( 1 ), 'unreadable_product_data' );
unset( $override209 );
// A core classname whose constructed object reports a different ID is a
// constructor-result mismatch: typed unreadable, never silently accepted.
$GLOBALS['wrong_id209'] = 2;
$refuse209( static fn() => Snapshot209::fresh_product( 1 ), 'unreadable_product_data' );
unset( $GLOBALS['wrong_id209'] );
$assert209( Snapshot209::fresh_product( 999 ), false, 'absent public product type remains absent' );
$refuse209( static fn() => Snapshot209::fresh_product( 0 ), 'invalid_product_id' );
$GLOBALS['_wp_suspend_cache_invalidation'] = true;
$before209 = $calls209;
$refuse209( static fn() => Snapshot209::fresh_product( 1 ), 'unreadable_product_data' );
$assert209( $calls209, $before209, 'suspended invalidation performs no partial cleanup' );
unset( $GLOBALS['_wp_suspend_cache_invalidation'] );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( __DIR__ . '/../../writeleash/includes', FilesystemIterator::SKIP_DOTS ) ) as $file209 ) {
	if ( 'php' !== $file209->getExtension() ) { continue; }
	$text209 = str_replace( '\\\\', '\\', file_get_contents( $file209->getPathname() ) );
	$assert209( str_contains( $text209, 'Automattic\\WooCommerce\\Internal\\' ), false, 'runtime dependency inventory including escaped namespace strings' );
}
echo '#209 public freshness contract model and runtime namespace inventory: PASS (' . $checks209 . " assertions; real Woo runtime NOT_TESTED)\n";
