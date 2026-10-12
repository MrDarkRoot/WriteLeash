<?php
// Installed-ZIP parent guards. Prove observer liveness on the SAME variation
// through successful Apply AND Undo before measuring either refusal.
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Price_Cache_Verifier as Verifier;
use WriteLeash\Price_Decimal as Decimal;
use WriteLeash\Price_Apply_Journal as Journal;
use WriteLeash\Undo_Repository as Undo;
global $wpdb, $saves240;
wp_set_current_user( 1 );
$saves240 = array();
$watch240 = static function ( $p ) use ( &$saves240 ): void {
	$id = $p->get_id(); $saves240[$id] = ( $saves240[$id] ?? 0 ) + 1;
};
add_action( 'woocommerce_before_product_object_save', $watch240 );
$check240 = static function ( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( '#240 parent: ' . $label ); } };
$count240 = static function ( int $id ) use ( &$saves240 ): int { return $saves240[$id] ?? 0; };
$plan240 = static function ( int $id, string $target, string $field ): array {
	$plan = WriteLeash\Woo_Price_Planner::preview( WriteLeash\Price_Selection_Spec::ids( array( $id ) ), new Operation( Operation::SET, $target, $field ), new WriteLeash\Safety_Policy( 1000, '100', '100', true, '100' ) );
	$job = Repo::create_from_plan( $plan, 1 );
	return array( $plan, Repo::approve( (int) $job['id'], 1 ) );
};
$apply240 = static function ( array $job ): void { WriteLeash\Job_Worker::run( (int) $job['id'], array( 'max_items' => 5, 'budget_seconds' => 20 ), true ); };
$undo240 = static function ( array $op ): void { WriteLeash\Undo_Worker::run( (int) $op['id'], array( 'max_items' => 5, 'budget_seconds' => 20 ), true ); };
try {
	foreach ( array( Operation::FIELD_REGULAR, Operation::FIELD_SALE ) as $field240 ) {
		$parent240 = new WC_Product_Variable(); $parent240->set_name( 'Parent240-' . wp_generate_uuid4() ); $parent240->set_status( 'publish' ); $parent240->save();
		$child240 = new WC_Product_Variation(); $child240->set_parent_id( $parent240->get_id() ); $child240->set_status( 'publish' );
		$child240->set_regular_price( '100' ); $child240->set_sale_price( '80' ); $child240->save(); WC_Product_Variable::sync( $parent240 );
		$id240 = $child240->get_id(); $target240 = Operation::FIELD_REGULAR === $field240 ? '110' : '70';
		list( $plan, $job ) = $plan240( $id240, $target240, $field240 );
		$before240 = $count240( $id240 ); $apply240( $job );
		$check240( $count240( $id240 ) > $before240, 'variation Apply save observer ' . $field240 );
		$check240( 'APPLIED' === Journal::read( $wpdb, $plan->data()['plan_id'], $id240 )['state'], 'published parent Apply' );
		$op = Undo::initiate( (int) $job['id'], 1 ); $before240 = $count240( $id240 ); $undo240( $op );
		$check240( $count240( $id240 ) > $before240, 'variation Undo save observer ' . $field240 );
		$check240( 'UNDONE' === Undo::read_item( $wpdb, (int) $job['id'], $id240 )['state'], 'same-parent eligible Undo' );
		Verifier::invalidate( $id240 ); $child240 = WriteLeash\Product_Price_Snapshot::fresh_product( $id240 );
		$check240( '100' === Decimal::parse( $child240->get_regular_price( 'edit' ) ) && '80' === Decimal::parse( $child240->get_sale_price( 'edit' ) ), 'both originals restored' );
		Verifier::assert_parent_range( $wpdb, $parent240->get_id(), array( $id240 ) );

		// Same variation, proven live above. Parent-only saves cannot supply liveness.
		list( $plan, $job ) = $plan240( $id240, $target240, $field240 );
		$parent240->set_status( 'draft' ); $parent240->save();
		$before240 = $count240( $id240 ); $apply240( $job );
		$check240( 'CONFLICT' === Journal::read( $wpdb, $plan->data()['plan_id'], $id240 )['state'], 'draft-parent Apply refusal' );
		$check240( $count240( $id240 ) === $before240, 'refused Apply zero variation saves' );
		Verifier::invalidate( $id240 ); $child240 = WriteLeash\Product_Price_Snapshot::fresh_product( $id240 );
		$check240( '100' === Decimal::parse( $child240->get_regular_price( 'edit' ) ) && '80' === Decimal::parse( $child240->get_sale_price( 'edit' ) ), 'refused Apply preserves both prices' );

		$parent240->set_status( 'publish' ); $parent240->save();
		list( $plan, $job ) = $plan240( $id240, $target240, $field240 );
		$before240 = $count240( $id240 ); $apply240( $job );
		$check240( $count240( $id240 ) > $before240, 'variation Apply save observer before reparent ' . $field240 );
		$other240 = new WC_Product_Variable(); $other240->set_name( 'Other240-' . wp_generate_uuid4() ); $other240->set_status( 'publish' ); $other240->save();
		Verifier::invalidate( $id240 ); $child240 = WriteLeash\Product_Price_Snapshot::fresh_product( $id240 );
		$child240->set_parent_id( $other240->get_id() ); $child240->save(); WC_Product_Variable::sync( $other240 ); WC_Product_Variable::sync( $parent240 );
		$op = Undo::initiate( (int) $job['id'], 1 ); $before240 = $count240( $id240 ); $undo240( $op );
		$check240( 'CONFLICT' === Undo::read_item( $wpdb, (int) $job['id'], $id240 )['state'], 'reparented Undo refusal' );
		$check240( $count240( $id240 ) === $before240, 'refused Undo zero variation saves' );
		Verifier::invalidate( $id240 ); $child240 = WriteLeash\Product_Price_Snapshot::fresh_product( $id240 );
		$check240( $child240->get_parent_id( 'edit' ) === $other240->get_id(), 'external parent preserved' );
		$check240( ( Operation::FIELD_REGULAR === $field240 ? $target240 : '100' ) === Decimal::parse( $child240->get_regular_price( 'edit' ) ) &&
			( Operation::FIELD_SALE === $field240 ? $target240 : '80' ) === Decimal::parse( $child240->get_sale_price( 'edit' ) ), 'refused Undo preserves both prices' );
		echo '#240 parent ' . $field240 . ": same-variation Apply/Undo observer active before zero-save refusals; values/parent preserved PASS\n";
	}
} finally { remove_action( 'woocommerce_before_product_object_save', $watch240 ); }
