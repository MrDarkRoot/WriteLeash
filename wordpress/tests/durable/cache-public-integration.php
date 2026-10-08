<?php
/** #209 focused real Woo probe; also runnable on an existing disposable 9.9.7 site. */
use WriteLeash\Price_Cache_Verifier as Cache209;
use WriteLeash\Price_Apply_Journal as Journal209;
use WriteLeash\Woo_Price_Mutator as Mutator209;
use WriteLeash\Woo_Price_Planner as Planner209;
use WriteLeash\Price_Selection_Spec as Selection209;
use WriteLeash\Price_Operation as Operation209;
use WriteLeash\Safety_Policy as Policy209;
use WriteLeash\Price_Decimal as Decimal209;

wp_set_current_user( 1 );
$checks209 = 0;
$assert209 = static function ( $actual, $expected, string $label ) use ( &$checks209 ): void {
	++$checks209;
	if ( $actual !== $expected ) { throw new RuntimeException( '#209 ' . $label . ': ' . wp_json_encode( array( $actual, $expected ) ) ); }
};
$assert209( WriteLeash\Free_Support_Contract::woo_supported( WC_VERSION ), true, 'supported Woo fixture' );
$assert209( wp_using_ext_object_cache(), 'persistent' === getenv( 'WL209_CACHE' ), 'actual cache profile' );
$instance209 = version_compare( WC_VERSION, '10.5', '>=' );
if ( $instance209 ) {
	$assert209( Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'product_instance_caching' ), true, 'instance feature enabled before request init' );
}
Journal209::install();
$ids209 = array();
$make209 = static function ( string $class, string $price, int $parent = 0 ) use ( &$ids209 ): WC_Product {
	$p = new $class();
	$p->set_name( 'WL209-' . wp_generate_uuid4() );
	$p->set_status( 'publish' );
	if ( $parent ) { $p->set_parent_id( $parent ); }
	if ( '' !== $price ) { $p->set_regular_price( $price ); }
	$p->save(); $ids209[] = $p->get_id(); return $p;
};
$plan209 = static function ( int $id, string $target ) {
	return Planner209::preview( Selection209::ids( array( $id ) ), new Operation209( Operation209::SET, $target ), new Policy209( 2, '100', '100', true, '100' ) );
};
// A genuine second PHP request changes prices using supported public CRUD.
$edit209 = static function ( int $id, string $price ): void {
	$code = '$p=wc_get_product(' . $id . ');$p->set_regular_price(' . var_export( $price, true ) . ');$p->save();if($p instanceof WC_Product_Variation){WC_Product_Variable::sync($p->get_parent_id());}';
	$proc = proc_open( array( 'wp', '--path=' . ABSPATH, 'eval', $code ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) { throw new RuntimeException( '#209 edit process unavailable' ); }
	fclose( $pipes[0] ); $output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] );
	if ( 0 !== proc_close( $proc ) ) { throw new RuntimeException( '#209 edit process failed: ' . $output ); }
};
$observe209 = new ReflectionMethod( WriteLeash\Free_Admin::class, 'product_observation' );
$read209 = static function ( int $id ) use ( $observe209 ): array { return $observe209->invoke( null, $id, Operation209::FIELD_REGULAR ); };
$s209 = $make209( WC_Product_Simple::class, '100' );
$p209 = $make209( WC_Product_Variable::class, '' );
$v209 = $make209( WC_Product_Variation::class, '100', $p209->get_id() );
$other209 = $make209( WC_Product_Variation::class, '150', $p209->get_id() );
WC_Product_Variable::sync( $p209->get_id() );
try {
	foreach ( array( $s209->get_id(), $v209->get_id() ) as $id209 ) {
		$old_plan209 = $plan209( $id209, '80' ); Journal209::seed( $old_plan209 );
		Cache209::invalidate( $id209 );
		$cached209 = wc_get_product( $id209 );
		$reads209 = 0;
		$listener209 = static function ( $id ) use ( $id209, &$reads209 ) { if ( $id === $id209 ) { ++$reads209; } };
		add_action( 'woocommerce_product_read', $listener209 );
		try {
			wc_get_product( $id209 );
			if ( $instance209 ) { $assert209( $reads209, 0, 'negative control: public factory reused primed instance' ); }
			$edit209( $id209, '120' );
			if ( $instance209 ) { $assert209( Decimal209::parse( wc_get_product( $id209 )->get_regular_price( 'edit' ) ), '100', 'negative control: cross-request edit leaves local primed instance stale' ); }
			// Preview must obtain fresh values itself, before any Admin/cache read.
			$preview209 = $plan209( $id209, '80' );
			$assert209( Decimal209::parse( $preview209->item( $id209 )->data()['snapshot']['regular_price'] ), '120', 'standalone Preview refreshes its own primed stale instance' );
			$assert209( Decimal209::parse( $read209( $id209 )['price'] ), '120', 'Admin public cleanup reads concurrent price' );
			// Woo's variation store has no product_read hook; prices above certify
			// that path, while the simple store also exposes this read diagnostic.
			if ( 'WC_Product_Simple' === get_class( $cached209 ) ) { $assert209( $reads209 > 0, true, 'public cleanup invokes real data store read' ); }
			$assert209( Mutator209::apply( $old_plan209, $id209 )['code'], 'CONFLICT', 'Apply preserves newer supported edit' );
			$assert209( Decimal209::parse( $read209( $id209 )['price'] ), '120', 'conflict never overwrites newer price' );
			$new_plan209 = $plan209( $id209, '80' );
			$assert209( Decimal209::parse( $new_plan209->item( $id209 )->data()['snapshot']['regular_price'] ), '120', 'new Preview freezes freshly observed value' );
			Journal209::seed( $new_plan209 );
			$assert209( Mutator209::apply( $new_plan209, $id209 )['code'], 'APPLIED', 'fresh supported Apply succeeds' );
			Cache209::observe( $new_plan209, $id209 );
			$assert209( Mutator209::undo_precondition( $new_plan209, $id209 ), 'UNDO_ELIGIBLE', 'fresh independent Undo precondition' );
			$edit209( $id209, '90' );
			$assert209( Mutator209::undo_precondition( $new_plan209, $id209 ), 'UNDO_CONFLICT', 'Undo refuses newer edit' );
			$assert209( Decimal209::parse( $read209( $id209 )['price'] ), '90', 'current display preserves newer edit' );
		} finally { remove_action( 'woocommerce_product_read', $listener209 ); }
	}
	$db209 = Cache209::observer();
	try { Cache209::observe_variable_parent( $db209, $p209->get_id() ); } finally { $db209->close(); }
	// Rollback leaves shared metadata/lookup cache residue: repair through the
	// same public invalidation boundary, then certify DB and fresh Woo reads.
	$id209 = $s209->get_id(); Cache209::invalidate( $id209 );
	$GLOBALS['wpdb']->query( 'START TRANSACTION' );
	$rollback209 = wc_get_product( $id209 ); $rollback209->set_regular_price( '70' ); $rollback209->save();
	get_post_meta( $id209, '_regular_price', true );
	$GLOBALS['wpdb']->query( 'ROLLBACK' );
	$assert209( Decimal209::parse( get_post_meta( $id209, '_regular_price', true ) ), '70', 'negative control: rollback left cached metadata' );
	Cache209::invalidate( $id209 );
	$assert209( Decimal209::parse( wc_get_product( $id209 )->get_regular_price( 'edit' ) ), '90', 'public recovery reads durable pre-rollback price' );
	$db209 = Cache209::observer();
	try { Cache209::matches( Cache209::storage( $db209, $id209 ), '90' ); } finally { $db209->close(); }
	// Instance freshness must not depend on optional Woo cache-clean handlers.
	Cache209::invalidate( $id209 ); wc_get_product( $id209 );
	$clean_hook209 = isset( $GLOBALS['wp_filter']['clean_post_cache'] ) ? clone $GLOBALS['wp_filter']['clean_post_cache'] : null;
	remove_all_actions( 'clean_post_cache' );
	try {
		$edit209( $id209, '95' );
		if ( $instance209 ) { $assert209( Decimal209::parse( wc_get_product( $id209 )->get_regular_price( 'edit' ) ), '90', 'negative control: absent eviction callback leaves factory stale' ); }
		$assert209( Decimal209::parse( $read209( $id209 )['price'] ), '95', 'direct public constructor remains fresh without eviction callback' );
		$missing_hook_plan209 = $plan209( $id209, '80' );
		$assert209( Decimal209::parse( $missing_hook_plan209->item( $id209 )->data()['snapshot']['regular_price'] ), '95', 'Preview remains fresh without instance eviction callback' );
	} finally {
		if ( null === $clean_hook209 ) { unset( $GLOBALS['wp_filter']['clean_post_cache'] ); }
		else { $GLOBALS['wp_filter']['clean_post_cache'] = $clean_hook209; }
	}
	$unreadable209 = static function ( $class, $type, $context, $id ) use ( $id209 ) { if ( $id === $id209 ) { throw new RuntimeException( 'injected public factory read failure' ); } return $class; };
	add_filter( 'woocommerce_product_class', $unreadable209, 10, 4 );
	try {
		$assert209( $read209( $id209 )['price'], 'Unavailable', 'failed public read never shows saved-plan price' );
		$unreadable_plan209 = $plan209( $id209, '80' );
		$assert209( $unreadable_plan209->item( $id209 )->data()['snapshot']['unreadable'], true, 'Preview retains explicitly unreadable product' );
	} finally { remove_filter( 'woocommerce_product_class', $unreadable209, 10 ); }
	$assert209( Decimal209::parse( $read209( $id209 )['price'] ), '95', 'failed freshness observations never change product price' );
	// A live product whose public class mapping changes to a stable extension
	// product class keeps the established terminal refusal with zero write; a
	// resolved non-product classname stays a retryable read failure. Both are
	// staged only for the Apply read, after a genuinely fresh Preview/seed.
	$assert209( class_exists( 'WC_Product_External' ), true, 'extension fixture class available' );
	$terminal_plan209 = $plan209( $id209, '80' );
	Journal209::seed( $terminal_plan209 );
	$extension_class209 = static function ( $class, $type, $context, $id ) use ( $id209 ) { return $id === $id209 ? 'WC_Product_External' : $class; };
	add_filter( 'woocommerce_product_class', $extension_class209, 10, 4 );
	try { $terminal209 = Mutator209::apply( $terminal_plan209, $id209 ); }
	finally { remove_filter( 'woocommerce_product_class', $extension_class209, 10 ); }
	$assert209( $terminal209['code'], 'UNSUPPORTED_PRODUCT_STATE', 'extension class mapping is a terminal unsupported refusal' );
	$assert209( $terminal209['reason'], 'UNSUPPORTED_PRODUCT_STATE', 'terminal unsupported refusal keeps its stable reason' );
	$assert209( Decimal209::parse( $read209( $id209 )['price'] ), '95', 'terminal unsupported refusal never writes' );
	$terminal_row209 = Journal209::read( $GLOBALS['wpdb'], $terminal_plan209->data()['plan_id'], $id209 );
	$assert209( $terminal_row209['state'], 'FAILED', 'terminal unsupported refusal is durable FAILED, never PENDING' );
	$retry_plan209 = $plan209( $id209, '80' );
	Journal209::seed( $retry_plan209 );
	$broken_class209 = static function ( $class, $type, $context, $id ) use ( $id209 ) { return $id === $id209 ? 'stdClass' : $class; };
	add_filter( 'woocommerce_product_class', $broken_class209, 10, 4 );
	try { $retry209 = Mutator209::apply( $retry_plan209, $id209 ); }
	finally { remove_filter( 'woocommerce_product_class', $broken_class209, 10 ); }
	$assert209( $retry209['code'], 'FAILED', 'broken classname stays a retryable read failure' );
	$assert209( $retry209['reason'], 'FAILED', 'retryable read failure keeps its generic reason' );
	$assert209( Decimal209::parse( $read209( $id209 )['price'] ), '95', 'retryable read failure never writes' );
	$retry_row209 = Journal209::read( $GLOBALS['wpdb'], $retry_plan209->data()['plan_id'], $id209 );
	$assert209( $retry_row209['state'], 'PENDING', 'retryable read failure returns to durable PENDING' );
	// R3: a transient public read refusal is never a verified safe-to-retry write.
	// It leaves durable PENDING with zero Woo save; the retry re-runs the frozen
	// precondition before any write, so a later edit between attempts wins as
	// CONFLICT with zero overwrite.
	$saves209 = array();
	$count_save209 = static function ( $subject ) use ( &$saves209 ): void {
		$save_id209 = $subject instanceof WC_Product ? $subject->get_id() : (int) $subject;
		$saves209[ $save_id209 ] = ( $saves209[ $save_id209 ] ?? 0 ) + 1;
	};
	add_action( 'woocommerce_before_product_object_save', $count_save209, 10, 1 );
	$broken_once209 = static function ( $class, $type, $context, $id ) use ( $id209 ) { return $id === $id209 ? 'stdClass' : $class; };
	$retry_again_plan209 = $plan209( $id209, '80' );
	Journal209::seed( $retry_again_plan209 );
	add_filter( 'woocommerce_product_class', $broken_once209, 10, 4 );
	try { $retry_failed209 = Mutator209::apply( $retry_again_plan209, $id209 ); }
	finally { remove_filter( 'woocommerce_product_class', $broken_once209, 10 ); }
	$assert209( $retry_failed209['code'], 'FAILED', 'R3 transient refusal is retryable FAILED' );
	$assert209( $saves209[ $id209 ] ?? 0, 0, 'R3 transient refusal performs zero Woo saves' );
	$assert209( Journal209::read( $GLOBALS['wpdb'], $retry_again_plan209->data()['plan_id'], $id209 )['state'], 'PENDING', 'R3 transient refusal leaves durable PENDING' );
	$assert209( Decimal209::parse( $read209( $id209 )['price'] ), '95', 'R3 transient refusal never writes' );
	$retry_applied209 = Mutator209::apply( $retry_again_plan209, $id209 );
	$assert209( $retry_applied209['code'], 'APPLIED', 'R3 retry after transient refusal applies the frozen plan' );
	$assert209( $saves209[ $id209 ] ?? 0, 1, 'R3 retry performs exactly one Woo save' );
	$assert209( Decimal209::parse( $read209( $id209 )['price'] ), '80', 'R3 retry writes the planned target' );
	$assert209( Journal209::read( $GLOBALS['wpdb'], $retry_again_plan209->data()['plan_id'], $id209 )['state'], 'APPLIED', 'R3 retry reaches durable APPLIED' );
	$conflict_plan209 = $plan209( $id209, '70' );
	Journal209::seed( $conflict_plan209 );
	add_filter( 'woocommerce_product_class', $broken_once209, 10, 4 );
	try { $conflict_failed209 = Mutator209::apply( $conflict_plan209, $id209 ); }
	finally { remove_filter( 'woocommerce_product_class', $broken_once209, 10 ); }
	$assert209( $conflict_failed209['code'], 'FAILED', 'R3 precondition fixture starts from a transient refusal' );
	$assert209( Journal209::read( $GLOBALS['wpdb'], $conflict_plan209->data()['plan_id'], $id209 )['state'], 'PENDING', 'R3 precondition fixture is durable PENDING' );
	$edit209( $id209, '85' );
	$saves_before_retry209 = $saves209[ $id209 ] ?? 0;
	$conflict_retry209 = Mutator209::apply( $conflict_plan209, $id209 );
	$assert209( $conflict_retry209['code'], 'CONFLICT', 'R3 retry re-runs the precondition and refuses the newer edit' );
	$assert209( ( $saves209[ $id209 ] ?? 0 ) - $saves_before_retry209, 0, 'R3 conflicting retry performs zero Woo saves' );
	$assert209( Decimal209::parse( $read209( $id209 )['price'] ), '85', 'R3 conflicting retry preserves the newer edit' );
	$assert209( Journal209::read( $GLOBALS['wpdb'], $conflict_plan209->data()['plan_id'], $id209 )['state'], 'CONFLICT', 'R3 conflicting retry is durable CONFLICT' );
	// R4: a committed APPLIED journal with matching independent storage must not
	// be reported as uncertain because a per-request meta cache still serves the
	// pre-write value. Emulate the observed race deterministically: every blob
	// read for the product serves the pre-write array until the second public
	// product read completes, then behaves normally. The observation may evict
	// and reconstruct exactly once; a persistent mismatch must still fail.
	$stale209 = $make209( WC_Product_Simple::class, '100' );
	$stale_id209 = $stale209->get_id();
	$stale_plan209 = $plan209( $stale_id209, '80' );
	Journal209::seed( $stale_plan209 );
	$assert209( Mutator209::apply( $stale_plan209, $stale_id209 )['code'], 'APPLIED', 'R4 fixture applies before the stale-cache observation' );
	$saves_before_stale209 = $saves209[ $stale_id209 ] ?? 0;
	$stale_served209 = 0;
	$stale_reads209 = 0;
	$stale_filter209 = static function ( $value, $object_id, $meta_key, $single ) use ( $stale_id209, &$stale_served209 ) {
		if ( (int) $object_id !== $stale_id209 ) { return $value; }
		if ( '' === $meta_key && false === $single ) { ++$stale_served209; return array( '_regular_price' => array( '100.00' ), '_price' => array( '100.00' ), '_sale_price' => array( '' ) ); }
		return $value;
	};
	$stale_disarm209 = static function ( $product_id ) use ( $stale_id209, &$stale_reads209 ) {
		if ( (int) $product_id === $stale_id209 && ++$stale_reads209 >= 2 ) { remove_filter( 'get_post_metadata', $GLOBALS['wl209_stale_filter'] ?? null, 10 ); }
	};
	$GLOBALS['wl209_stale_filter'] = $stale_filter209;
	add_filter( 'get_post_metadata', $stale_filter209, 10, 4 );
	add_action( 'woocommerce_product_read', $stale_disarm209, 10, 1 );
	try {
		$stale_observed209 = Cache209::observe( $stale_plan209, $stale_id209 );
		$assert209( $stale_observed209['journal']['state'], 'APPLIED', 'R4 stale per-request cache does not degrade a committed observation' );
	} finally {
		remove_filter( 'get_post_metadata', $stale_filter209, 10 );
		remove_action( 'woocommerce_product_read', $stale_disarm209, 10 );
		unset( $GLOBALS['wl209_stale_filter'] );
	}
	$assert209( $stale_served209 >= 1, true, 'R4 stale blob emulation was served' );
	$assert209( ( $saves209[ $stale_id209 ] ?? 0 ) - $saves_before_stale209, 0, 'R4 stale observation performs zero Woo saves' );
	$assert209( Decimal209::parse( $read209( $stale_id209 )['price'] ), '80', 'R4 stale observation never changes the committed price' );
	$suspended209 = $GLOBALS['_wp_suspend_cache_invalidation'] ?? false;
	$GLOBALS['_wp_suspend_cache_invalidation'] = true;
	try {
		$assert209( $read209( $id209 )['price'], 'Unavailable', 'Admin unavailable when freshness disabled' );
		try { Cache209::invalidate( $id209 ); throw new RuntimeException( 'expected cache refusal' ); }
		catch ( WriteLeash\Price_Apply_Error $error209 ) { $assert209( $error209->getMessage(), 'CACHE_VERIFICATION_FAILED', 'execution fails visibly on suspended freshness' ); }
	} finally { $GLOBALS['_wp_suspend_cache_invalidation'] = $suspended209; }
	echo '#209 public cache real Woo/default-or-Redis/simple/variation/concurrent/rollback/Undo-precondition: PASS (' . $checks209 . ' assertions; Woo ' . WC_VERSION . ")\n";
} finally { foreach ( array_reverse( $ids209 ) as $id209 ) { wp_delete_post( $id209, true ); } }
