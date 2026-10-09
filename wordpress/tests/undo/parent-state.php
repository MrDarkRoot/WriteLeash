<?php
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Price_Cache_Verifier as Verifier;
use WriteLeash\Price_Decimal as Decimal;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Safety_Policy as Policy;
use WriteLeash\Woo_Price_Planner as Planner;
use WriteLeash\Undo_Item_State as UItem;

// Included by integration.php on each MySQL/MariaDB and default/Redis profile.
// Both fields must preserve a newer parent identity or publication change.
foreach ( array( Operation::FIELD_REGULAR, Operation::FIELD_SALE ) as $field212 ) {
	foreach ( array( 'draft_before_apply', 'reparent_before_undo', 'unchanged_parent_undo' ) as $case212 ) {
		wp_set_current_user( 1 );
		$parent212 = new WC_Product_Variable();
		$parent212->set_name( 'WL212-parent-' . wp_generate_uuid4() );
		$parent212->set_status( 'publish' );
		$parent212->save();
		$child212 = new WC_Product_Variation();
		$child212->set_parent_id( $parent212->get_id() );
		$child212->set_status( 'publish' );
		$child212->set_regular_price( '100' );
		$child212->set_sale_price( '80' );
		$child212->save();
		WC_Product_Variable::sync( $parent212 );
		$id212 = $child212->get_id();
		$target212 = Operation::FIELD_REGULAR === $field212 ? '110' : '70';
		$plan212 = Planner::preview( Selection::ids( array( $id212 ) ), new Operation( Operation::SET, $target212, $field212 ), new Policy( 1000, '100', '100', true, '100' ) );
		$job212 = Repo::create_from_plan( $plan212, 1 );
		$job212 = Repo::approve( (int) $job212['id'], 1 );
		if ( 'draft_before_apply' === $case212 ) {
			$parent212->set_status( 'draft' );
			$parent212->save();
		}
		$before212 = saves( $id212 );
		run_worker( array( 'job_id' => (int) $job212['id'], 'mode' => 'apply', 'limits' => limits( 5 ), 'manual' => true ) );
		$row212 = journal_row( $plan212->data()['plan_id'], $id212 );
		if ( 'draft_before_apply' === $case212 ) {
			eq( $row212['state'], 'CONFLICT', '#212 draft parent: Apply conflicts ' . $field212 );
			eq( saves( $id212 ), $before212, '#212 draft parent: zero variation saves' );
			Verifier::invalidate( $id212 );
			$current212 = WriteLeash\Product_Price_Snapshot::fresh_product( $id212 );
			eq( Decimal::parse( $current212->get_regular_price( 'edit' ) ), '100', '#212 refused Apply regular unchanged' );
			eq( Decimal::parse( $current212->get_sale_price( 'edit' ) ), '80', '#212 refused Apply sale unchanged' );
			continue;
		}
		eq( $row212['state'], 'APPLIED', '#212 published-parent variation Apply control' );
		$other212 = null;
		if ( 'reparent_before_undo' === $case212 ) {
			$other212 = new WC_Product_Variable();
			$other212->set_name( 'WL212-new-parent-' . wp_generate_uuid4() );
			$other212->set_status( 'publish' );
			$other212->save();
			Verifier::invalidate( $id212 );
			$child212 = WriteLeash\Product_Price_Snapshot::fresh_product( $id212 );
			$child212->set_parent_id( $other212->get_id() );
			$child212->save();
			WC_Product_Variable::sync( $other212 );
			WC_Product_Variable::sync( $parent212 );
		}
		$op212 = start_undo( (int) $job212['id'] );
		$before212 = saves( $id212 );
		run_worker( array( 'undo_id' => (int) $op212['id'], 'mode' => 'undo', 'limits' => limits( 5 ), 'manual' => true ) );
		Verifier::invalidate( $id212 );
		$current212 = WriteLeash\Product_Price_Snapshot::fresh_product( $id212 );
		if ( $other212 ) {
			eq( undo_row( (int) $job212['id'], $id212 )['state'], UItem::CONFLICT, '#212 reparenting: Undo conflicts ' . $field212 );
			eq( saves( $id212 ), $before212, '#212 reparenting: zero restore saves' );
			eq( $current212->get_parent_id( 'edit' ), $other212->get_id(), '#212 reparenting: external parent preserved' );
			eq( Decimal::parse( Operation::FIELD_REGULAR === $field212 ? $current212->get_regular_price( 'edit' ) : $current212->get_sale_price( 'edit' ) ), $target212, '#212 reparenting: applied price preserved' );
		} else {
			eq( undo_row( (int) $job212['id'], $id212 )['state'], UItem::UNDONE, '#212 same parent: eligible variation Undo works ' . $field212 );
			eq( Decimal::parse( $current212->get_regular_price( 'edit' ) ), '100', '#212 same parent: regular restored' );
			eq( Decimal::parse( $current212->get_sale_price( 'edit' ) ), '80', '#212 same parent: sale restored/preserved' );
			global $wpdb;
			Verifier::assert_parent_range( $wpdb, $parent212->get_id(), array( $id212 ) );
		}
	}
}
marker( '#212 variation parent drift: both fields, draft Apply refusal, reparent Undo refusal, same-parent restore and lookup control' );
