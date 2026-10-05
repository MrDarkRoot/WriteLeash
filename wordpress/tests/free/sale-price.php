<?php
/**
 * #178 pure-PHP domain harness: regular/sale price-field planning without a
 * database. Exercises Product_Price_Snapshot::read, Product_Price_Eligibility,
 * Change_Plan_Item::compute, Change_Plan hydration/precondition and the
 * field-aware storage verifier against stub WC_Product objects and the
 * documented Woo 11.1.2 save/lookup semantics.
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
	}
	class WC_Product_Simple extends WC_Product {}
	class WC_Product_External extends WC_Product {}
}

namespace WriteLeash {
	/** Minimal typed error so Price_Cache_Verifier can load without wpdb. */
	final class Price_Apply_Error extends \RuntimeException {}
}

namespace {

	use WriteLeash\Change_Plan as Plan;
	use WriteLeash\Change_Plan_Item as Item;
	use WriteLeash\Plan_Hasher as Hasher;
	use WriteLeash\Price_Cache_Verifier as Verifier;
	use WriteLeash\Price_Operation as O;
	use WriteLeash\Price_Selection_Spec as S;
	use WriteLeash\Price_Store_Context as Context;
	use WriteLeash\Product_Price_Eligibility as Eligibility;
	use WriteLeash\Product_Price_Snapshot as Snapshot;
	use WriteLeash\Safety_Policy as P;

	require __DIR__ . '/../../writeleash/includes/free/class-price-cache-verifier.php';

	$wl178_assertions = 0;
	function wl178_equal( $actual, $expected, string $label ): void {
		global $wl178_assertions;
		++$wl178_assertions;
		if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
	}
	function wl178_error( callable $call, string $reason ): void {
		try { $call(); }
		catch ( \WriteLeash\Price_Validation_Error $error ) { wl178_equal( $error->reason(), $reason, 'typed error' ); return; }
		throw new RuntimeException( 'Missing typed error: ' . $reason );
	}
	function wl178_marker( string $label ): void {
		global $wl178_assertions;
		echo "#178 $label: PASS ($wl178_assertions cumulative assertions)\n";
	}
	function wl178_context(): Context { return new Context( 'USD', 2, '7.1.2', '11.1.2' ); }
	function wl178_policy( bool $block_zero = false ): P { return new P( 1000, '100', '100', $block_zero, '100' ); }
	function wl178_product( int $id, string $regular, string $sale = '', $from = null, $to = null, string $status = 'publish', string $class = 'WC_Product_Simple' ): WC_Product {
		$from = is_numeric( $from ) ? new WC_DateTime( (int) $from ) : $from;
		$to = is_numeric( $to ) ? new WC_DateTime( (int) $to ) : $to;
		return new $class( $id, array( 'regular_price' => $regular, 'sale_price' => $sale, 'sale_from' => $from, 'sale_to' => $to, 'status' => $status, 'name' => 'WL178-' . $id ) );
	}
	function wl178_plan( array $products, O $operation, ?P $policy = null ): Plan {
		$snapshots = array();
		foreach ( $products as $product ) { $snapshots[] = Snapshot::read( $product->get_id(), $product ); }
		return Plan::create( 'wl178-plan', '2026-10-05T00:00:00Z', 1, wl178_context(), S::ids( array_map( static fn( $p ) => $p->get_id(), $products ) ), $operation, $policy ?? wl178_policy(), $snapshots );
	}
	/** Faithful Woo storage shape for one simple product's current state. */
	function wl178_storage( string $regular, string $sale, $from = '', $to = '', ?string $price = null, ?string $onsale = null, $now = null ): array {
		$now = $now ?? time();
		$active = $regular;
		if ( '' !== $sale && \WriteLeash\Price_Decimal::compare( \WriteLeash\Price_Decimal::units( \WriteLeash\Price_Decimal::parse( $regular ) ), \WriteLeash\Price_Decimal::units( \WriteLeash\Price_Decimal::parse( $sale ) ) ) > 0
			&& ( '' === (string) $from || (int) $from <= $now ) && ( '' === (string) $to || (int) $to >= $now ) ) {
			$active = $sale;
		}
		$meta = array( '_regular_price' => array( $regular ), '_price' => array( $price ?? $active ) );
		if ( '' !== $sale ) { $meta['_sale_price'] = array( $sale ); }
		if ( '' !== (string) $from ) { $meta['_sale_price_dates_from'] = array( (string) $from ); }
		if ( '' !== (string) $to ) { $meta['_sale_price_dates_to'] = array( (string) $to ); }
		return array(
			'meta' => $meta,
			'lookup' => array( 'min_price' => $price ?? $active, 'max_price' => $price ?? $active, 'onsale' => $onsale ?? ( ( '' !== $sale && '0' !== $sale && $active === $sale ) ? '1' : '0' ) ),
		);
	}

	// ------------------------------------------------------------------
	// Snapshot + eligibility field awareness.
	// ------------------------------------------------------------------
	$snapshot = Snapshot::read( 7, wl178_product( 7, '100.00', '80.00' ) )->data();
	wl178_equal( $snapshot['regular_price'], '100.00', 'snapshot keeps the stored regular price' );
	wl178_equal( $snapshot['sale_price'], '80.00', 'snapshot keeps the stored sale price' );
	wl178_equal( Eligibility::evaluate( Snapshot::read( 7, wl178_product( 7, '100.00', '80.00' ) ), wl178_context() )->data()['state'], 'ELIGIBLE', 'sale-configured simple product is eligible for regular-price edits' );
	wl178_equal( Eligibility::evaluate( Snapshot::read( 7, wl178_product( 7, '100.00', '80.00' ) ), wl178_context(), O::FIELD_SALE )->data()['state'], 'ELIGIBLE', 'sale field eligibility reads the same snapshot' );
	wl178_error( static fn() => Eligibility::evaluate( Snapshot::read( 7, wl178_product( 7, '100.00' ) ), wl178_context(), 'cost_price' ), 'unsupported_price_field' );
	wl178_equal( Eligibility::evaluate( Snapshot::read( 7, wl178_product( 7, '', '80.00' ) ), wl178_context(), O::FIELD_SALE )->data()['reason'], 'empty_regular_price', 'sale field still needs a regular baseline' );
	wl178_marker( 'snapshot and eligibility keep sale configuration' );

	// ------------------------------------------------------------------
	// Regular field on a sale-configured product.
	// ------------------------------------------------------------------
	$sale_product = wl178_product( 11, '100.00', '80.00' );
	$item = Item::compute( Snapshot::read( 11, $sale_product ), wl178_context(), new O( O::DECREASE_PERCENT, '20' ), wl178_policy() )->data();
	wl178_equal( $item['result'], 'UNSUPPORTED', 'regular target at the sale price is refused' );
	wl178_equal( $item['eligibility']['reason'], 'regular_price_not_above_sale', 'typed refusal reason' );
	wl178_equal( $item['planned_regular_price'], null, 'refused item never carries a target' );
	$item = Item::compute( Snapshot::read( 11, $sale_product ), wl178_context(), new O( O::DECREASE_PERCENT, '10' ), wl178_policy() )->data();
	wl178_equal( $item['result'], 'CHANGING', 'regular target above the sale changes' );
	wl178_equal( $item['planned_regular_price'], '90.00', 'regular target absolute value' );
	wl178_equal( $item['snapshot']['sale_price'], '80.00', 'sale configuration stays in the frozen snapshot' );
	$item = Item::compute( Snapshot::read( 11, $sale_product ), wl178_context(), new O( O::SET, '80.00' ), wl178_policy() )->data();
	wl178_equal( $item['eligibility']['reason'], 'regular_price_not_above_sale', 'SET exactly at the sale price is refused' );
	$item = Item::compute( Snapshot::read( 11, wl178_product( 11, '100.00', '80.00', (string) ( time() + 86400 ), (string) ( time() + 172800 ) ) ), wl178_context(), new O( O::DECREASE_PERCENT, '20' ), wl178_policy() )->data();
	wl178_equal( $item['eligibility']['reason'], 'regular_price_not_above_sale', 'future sale still protects its configured price' );
	wl178_marker( 'regular edits on sale-configured products refuse only sale-clearing targets' );

	// ------------------------------------------------------------------
	// Sale field: first sale, fixed/percent baseline, regular guard.
	// ------------------------------------------------------------------
	$plain = wl178_product( 21, '100.00' );
	$item = Item::compute( Snapshot::read( 21, $plain ), wl178_context(), new O( O::SET, '80', O::FIELD_SALE ), wl178_policy() )->data();
	wl178_equal( $item['result'], 'CHANGING', 'SET adds a first sale price' );
	wl178_equal( $item['expected_regular_price'], '', 'empty sale is represented as the empty string' );
	wl178_equal( $item['planned_regular_price'], '80.00', 'sale target is absolute' );
	wl178_equal( $item['absolute_delta'], null, 'no ratio baseline for a first sale' );
	wl178_equal( $item['percentage_delta'], null, 'no percentage baseline for a first sale' );
	wl178_equal( Item::compute( Snapshot::read( 21, $plain ), wl178_context(), new O( O::INCREASE_FIXED, '5', O::FIELD_SALE ), wl178_policy() )->data()['eligibility']['reason'], 'empty_sale_price', 'fixed from empty sale is refused' );
	wl178_equal( Item::compute( Snapshot::read( 21, $plain ), wl178_context(), new O( O::DECREASE_PERCENT, '10', O::FIELD_SALE ), wl178_policy() )->data()['eligibility']['reason'], 'empty_sale_price', 'percent from empty sale is refused' );
	$item = Item::compute( Snapshot::read( 21, $plain ), wl178_context(), new O( O::SET, '0', O::FIELD_SALE ), wl178_policy( true ) )->data();
	wl178_equal( $item['blockers'], array( 'zero_target_blocked' ), 'zero-target blocker still applies to a first sale' );
	$sale_90 = wl178_product( 22, '100.00', '90' );
	$item = Item::compute( Snapshot::read( 22, $sale_90 ), wl178_context(), new O( O::DECREASE_PERCENT, '10', O::FIELD_SALE ), wl178_policy() )->data();
	wl178_equal( $item['planned_regular_price'], '81.00', 'sale percent uses the sale baseline' );
	wl178_equal( $item['expected_regular_price'], '90', 'expected value is the sale field value' );
	wl178_equal( Item::compute( Snapshot::read( 22, $sale_90 ), wl178_context(), new O( O::SET, '100', O::FIELD_SALE ), wl178_policy() )->data()['eligibility']['reason'], 'sale_price_not_below_regular', 'sale at the regular price is refused' );
	wl178_equal( Item::compute( Snapshot::read( 22, $sale_90 ), wl178_context(), new O( O::SET, '100.00', O::FIELD_SALE ), wl178_policy() )->data()['eligibility']['reason'], 'sale_price_not_below_regular', 'numeric equality is enough to refuse' );
	wl178_equal( Item::compute( Snapshot::read( 22, $sale_90 ), wl178_context(), new O( O::SET, '99.99', O::FIELD_SALE ), wl178_policy() )->data()['result'], 'CHANGING', 'sale below the regular price changes' );
	$item = Item::compute( Snapshot::read( 23, wl178_product( 23, '100', '0' ) ), wl178_context(), new O( O::INCREASE_FIXED, '5', O::FIELD_SALE ), wl178_policy() )->data();
	wl178_equal( $item['blockers'], array( 'percentage_limit_from_zero_undefined' ), 'fixed increase from a zero sale still runs the ratio guard' );
	wl178_marker( 'sale target operations, first-sale SET and Woo-clearing refusals' );

	// ------------------------------------------------------------------
	// Plan creation, hash coverage, hydration round trips.
	// ------------------------------------------------------------------
	$sale_plan = wl178_plan( array( wl178_product( 31, '100.00', '80.00' ) ), new O( O::SET, '70', O::FIELD_SALE ) );
	wl178_equal( $sale_plan->price_field(), O::FIELD_SALE, 'plan exposes the sale field' );
	wl178_equal( $sale_plan->data()['operation']['field'], O::FIELD_SALE, 'operation material carries the field' );
	$round = Plan::hydrate( json_decode( $sale_plan->json(), true ) );
	wl178_equal( $round->hash(), $sale_plan->hash(), 'new plan hash survives hydration' );
	wl178_equal( $round->price_field(), O::FIELD_SALE, 'hydrated plan stays on the sale field' );
	wl178_equal( $round->item( 31 )->data()['expected_regular_price'], '80', 'hydrated sale expected value' );
	$first_sale_plan = wl178_plan( array( wl178_product( 32, '100.00' ) ), new O( O::SET, '70', O::FIELD_SALE ) );
	$round = Plan::hydrate( json_decode( $first_sale_plan->json(), true ) );
	wl178_equal( $round->item( 32 )->data()['expected_regular_price'], '', 'hydrate accepts an empty expected sale' );
	wl178_equal( $round->item( 32 )->data()['result'], 'CHANGING', 'first sale stays changing after hydration' );

	$regular_plan = wl178_plan( array( wl178_product( 33, '100.00' ) ), new O( O::SET, '90' ) );
	$sale_field_plan = wl178_plan( array( wl178_product( 33, '100.00' ) ), new O( O::SET, '90', O::FIELD_SALE ) );
	wl178_equal( $regular_plan->hash() !== $sale_field_plan->hash(), true, 'the field is covered by the plan hash' );

	$legacy = json_decode( $regular_plan->json(), true );
	unset( $legacy['operation']['field'] );
	unset( $legacy['summary'] );
	$material = $legacy;
	unset( $material['plan_id'], $material['created_at'], $material['plan_hash'] );
	$legacy['plan_hash'] = Hasher::hash( $material );
	$legacy = Plan::hydrate( $legacy );
	wl178_equal( $legacy->price_field(), O::FIELD_REGULAR, 'legacy plan defaults to the regular price' );
	wl178_equal( array_key_exists( 'field', $legacy->data()['operation'] ), false, 'hydration never injects the field into hashed material' );
	wl178_equal( $legacy->hash(), $legacy->data()['plan_hash'], 'legacy hash still verifies byte-for-byte' );

	$tampered = json_decode( $regular_plan->json(), true );
	$tampered['operation']['field'] = 'cost_price';
	$material = $tampered;
	unset( $material['plan_id'], $material['created_at'], $material['plan_hash'], $material['summary'] );
	$tampered['plan_hash'] = Hasher::hash( $material );
	wl178_error( static fn() => Plan::hydrate( $tampered ), 'invalid_plan_material' );

	$item = $sale_plan->item( 31 )->data();
	$bad = $item;
	$bad['expected_regular_price'] = '';
	wl178_error( static fn() => Item::hydrate( $bad, O::FIELD_REGULAR ), 'invalid_plan_item' );
	wl178_equal( Item::hydrate( $bad, O::FIELD_SALE )->data()['expected_regular_price'], '', 'empty expected is valid for the sale field' );
	wl178_marker( 'plan hash coverage, legacy compatibility and field-scoped hydration' );

	// ------------------------------------------------------------------
	// Field-scoped preconditions.
	// ------------------------------------------------------------------
	$plan = wl178_plan( array( wl178_product( 41, '100.00', '80.00' ) ), new O( O::SET, '90' ) );
	$context = wl178_context();
	wl178_equal( $plan->precondition( 41, Snapshot::read( 41, wl178_product( 41, '100.00', '80.00' ) ), $context )['state'], 'MATCH', 'regular field matches its frozen price' );
	wl178_equal( $plan->precondition( 41, Snapshot::read( 41, wl178_product( 41, '100.00', '70.00' ) ), $context )['state'], 'MATCH', 'external sale price changes are not a regular-field conflict' );
	wl178_equal( $plan->precondition( 41, Snapshot::read( 41, wl178_product( 41, '100.00', '', (string) ( time() + 3600 ) ) ), $context )['state'], 'MATCH', 'external sale schedule changes are not a regular-field conflict' );
	wl178_equal( $plan->precondition( 41, Snapshot::read( 41, wl178_product( 41, '110.00', '80.00' ) ), $context )['reasons'], array( 'regular_price_changed' ), 'regular price drift still conflicts' );
	wl178_equal( $plan->precondition( 41, Snapshot::read( 41, wl178_product( 41, '100.00', '80.00', null, null, 'draft' ) ), $context )['reasons'], array( 'product_status_changed' ), 'status drift still conflicts' );

	$sale_plan = wl178_plan( array( wl178_product( 42, '100.00', '80.00' ) ), new O( O::SET, '70', O::FIELD_SALE ) );
	wl178_equal( $sale_plan->precondition( 42, Snapshot::read( 42, wl178_product( 42, '100.00', '80.00' ) ), $context )['state'], 'MATCH', 'sale field matches its frozen price' );
	wl178_equal( $sale_plan->precondition( 42, Snapshot::read( 42, wl178_product( 42, '100.00', '75.00' ) ), $context )['reasons'], array( 'sale_price_changed' ), 'sale price drift conflicts on the sale field' );
	wl178_equal( $sale_plan->precondition( 42, Snapshot::read( 42, wl178_product( 42, '65.00', '80.00' ) ), $context )['reasons'], array( 'sale_price_not_below_regular' ), 'regular drop below the planned sale conflicts' );
	wl178_equal( $sale_plan->precondition( 42, Snapshot::read( 42, wl178_product( 42, '120.00', '80.00' ) ), $context )['state'], 'MATCH', 'regular increase keeps the planned sale valid' );
	wl178_equal( $sale_plan->precondition( 42, Snapshot::read( 42, wl178_product( 42, '100.00', '80.00', (string) ( time() + 3600 ) ) ), $context )['state'], 'MATCH', 'sale schedule edits do not block the sale field' );

	$first = wl178_plan( array( wl178_product( 43, '100.00' ) ), new O( O::SET, '70', O::FIELD_SALE ) );
	wl178_equal( $first->precondition( 43, Snapshot::read( 43, wl178_product( 43, '100.00' ) ), $context )['state'], 'MATCH', 'first sale still matches when the field stays empty' );
	wl178_equal( $first->precondition( 43, Snapshot::read( 43, wl178_product( 43, '100.00', '60.00' ) ), $context )['reasons'], array( 'sale_price_changed' ), 'an externally added sale conflicts with a first-sale plan' );
	wl178_marker( 'field-scoped preconditions preserve the untouched field' );

	// ------------------------------------------------------------------
	// Field-aware storage verification (Woo save/lookup semantics).
	// ------------------------------------------------------------------
	wl178_equal( Verifier::active_price( '100.00', '80.00', '', '', time() ), '80', 'active price is the sale while it is valid' );
	wl178_equal( Verifier::active_price( '100.00', '80.00', (string) ( time() + 3600 ), '', time() ), '100', 'future sale is not active yet' );
	wl178_equal( Verifier::active_price( '100.00', '80.00', '', (string) ( time() - 3600 ), time() ), '100', 'past sale is not active' );
	wl178_equal( Verifier::active_price( '100.00', '120.00', '', '', time() ), '100', 'sale at or above regular never activates' );

	$storage = wl178_storage( '90.00', '80.00' );
	Verifier::matches_field( $storage, O::FIELD_REGULAR, '90.00', array( 'sale_price' => '80.00' ) );
	wl178_equal( Verifier::coherence( $storage ), '80', 'coherent sale state reports the sale as active' );
	$broken = wl178_storage( '80.00', '80.00' );
	try { Verifier::matches_field( $broken, O::FIELD_REGULAR, '90.00', array( 'sale_price' => '80.00' ) ); throw new RuntimeException( 'sale-clearing write accepted' ); }
	catch ( \WriteLeash\Price_Apply_Error $error ) { wl178_equal( $error->getMessage(), 'JOURNAL_MISMATCH', 'a cleared sale fails field verification' ); }
	$broken = wl178_storage( '90.00', '', '', '', '80.00', '1' );
	try { Verifier::matches_field( $broken, O::FIELD_REGULAR, '90.00', array( 'sale_price' => '80.00' ) ); throw new RuntimeException( 'lost sale accepted' ); }
	catch ( \WriteLeash\Price_Apply_Error $error ) { wl178_equal( $error->getMessage(), 'JOURNAL_MISMATCH', 'a lost sale fails field verification' ); }
	$broken = wl178_storage( '90.00', '80.00' );
	$broken['lookup']['min_price'] = '90.00';
	$broken['lookup']['max_price'] = '90.00';
	try { Verifier::matches_field( $broken, O::FIELD_REGULAR, '90.00', array( 'sale_price' => '80.00' ) ); throw new RuntimeException( 'lookup divergence accepted' ); }
	catch ( \WriteLeash\Price_Apply_Error $error ) { wl178_equal( $error->getMessage(), 'LOOKUP_MISMATCH', 'lookup disagreement fails verification' ); }

	$storage = wl178_storage( '100.00', '80.00' );
	Verifier::matches_field( $storage, O::FIELD_SALE, '80.00', array( 'regular_price' => '100.00' ) );
	$storage = wl178_storage( '100.00', '' );
	Verifier::matches_field( $storage, O::FIELD_SALE, '', array( 'regular_price' => '100.00' ) );
	wl178_equal( Verifier::coherence( $storage ), '100', 'removing the sale restores the regular active price' );
	$future = wl178_storage( '100.00', '80.00', (string) ( time() + 3600 ) );
	$future['meta']['_price'] = array( '100.00' );
	$future['lookup']['min_price'] = '100.00';
	$future['lookup']['max_price'] = '100.00';
	$future['lookup']['onsale'] = '0';
	Verifier::matches_field( $future, O::FIELD_SALE, '80.00', array( 'regular_price' => '100.00' ) );
	$zero_sale = wl178_storage( '90.00', '0' );
	Verifier::matches_field( $zero_sale, O::FIELD_REGULAR, '90.00', array( 'sale_price' => '0' ) );
	$zero_sale = wl178_storage( '100.00', '0.00' );
	$zero_sale['lookup']['onsale'] = '1';
	Verifier::matches_field( $zero_sale, O::FIELD_SALE, '0.00', array( 'regular_price' => '100.00' ) );
	wl178_marker( 'field-aware verification against Woo save/lookup semantics' );

	echo "#178 sale-price domain harness: PASS ($wl178_assertions assertions)\n";
}
