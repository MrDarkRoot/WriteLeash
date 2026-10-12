<?php
// Repository-only exact-ZIP historical upgrade fixture; execute via WP-CLI.
// Install the recorded #194 ZIP before seed, and the v0.2 ZIP before verify.
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Price_Cache_Verifier as Verifier;
use WriteLeash\Price_Decimal as Decimal;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Safety_Policy as Policy;
use WriteLeash\Woo_Price_Planner as Planner;
use WriteLeash\Undo_Repository as Undo;
use WriteLeash\Undo_Worker as UndoWorker;

$mode240 = getenv( 'WL240_UPGRADE_MODE' );
$file240 = getenv( 'WL240_UPGRADE_RECEIPT' );
if ( ! $file240 ) { throw new RuntimeException( 'Explicit private fixture receipt required' ); }
wp_set_current_user( 1 );
$assert240 = static function ( bool $ok, string $label ): void {
	if ( ! $ok ) { throw new RuntimeException( '#240 upgrade: ' . $label ); }
};
$price240 = static function ( int $id ): string {
	Verifier::invalidate( $id );
	return Decimal::parse( wc_get_product( $id )->get_regular_price( 'edit' ) );
};
if ( 'seed' === $mode240 ) {
	$assert240( '0.1.0' === WRITELEASH_VERSION, 'historical version installed' );
	$old_zip240 = getenv( 'WL240_HISTORICAL_ZIP' );
	$assert240( $old_zip240 && is_file( $old_zip240 ) && 'b8bd5f406667f6f686348ea67453ec25b90e09607cd9a13b542e6054c9f8a3e8' === hash_file( 'sha256', $old_zip240 ), 'recorded historical ZIP identity' );
	$receipt240 = array( 'historical_version' => WRITELEASH_VERSION, 'historical_zip_sha256' => hash_file( 'sha256', $old_zip240 ), 'cases' => array() );
	foreach ( array( 'completed' => 1, 'undo_eligible' => 1, 'resumable' => 3, 'resume_conflict' => 3 ) as $case240 => $count240 ) {
		$ids240 = array();
		for ( $i240 = 0; $i240 < $count240; ++$i240 ) {
			$p240 = new WC_Product_Simple(); $p240->set_name( 'Upgrade240-' . wp_generate_uuid4() );
			$p240->set_status( 'publish' ); $p240->set_regular_price( '100' ); $p240->save(); $ids240[] = $p240->get_id();
		}
		$plan240 = Planner::preview( Selection::ids( $ids240 ), new Operation( Operation::INCREASE_PERCENT, '10' ), new Policy( 100, '100', '100', true, '100' ) );
		$job240 = Repo::create_from_plan( $plan240, 1 ); $job240 = Repo::approve( (int) $job240['id'], 1 );
		Worker::run( (int) $job240['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true );
		$job240 = Repo::read( (int) $job240['id'] );
		$journals240 = array(); global $wpdb;
		foreach ( $ids240 as $id240 ) { $journals240[$id240] = WriteLeash\Price_Apply_Journal::read( $wpdb, $job240['plan_id'], $id240 ); }
		if ( 'resume_conflict' === $case240 ) {
			$assert240( 1 === Repo::counts( (int) $job240['id'] )['applied'] && 'PENDING' === $journals240[$ids240[1]]['state'], 'pending drift seeded after one applied item' );
			$p240 = wc_get_product( $ids240[1] ); $p240->set_regular_price( '120' ); $p240->save();
		}
		$receipt240['cases'][$case240] = array( 'ids' => $ids240, 'job' => $job240,
			'items' => Repo::items( (int) $job240['id'] ), 'apply_journal' => $journals240, 'plan_hash' => $plan240->hash(), 'plan_json' => $plan240->json() );
	}
	file_put_contents( $file240, wp_json_encode( $receipt240, JSON_PRETTY_PRINT ) );
	chmod( $file240, 0600 );
echo "#240 historical 0.1 exact-ZIP seed: completed, Undo-eligible, partial percent and pending-drift jobs; direct Apply-journal snapshots PASS\n";
	return;
}
$assert240( 'verify' === $mode240 && '0.2.0' === WRITELEASH_VERSION, 'candidate installed for verification' );
$receipt240 = json_decode( file_get_contents( $file240 ), true, 512, JSON_THROW_ON_ERROR );
foreach ( $receipt240['cases'] as $case240 => $saved240 ) {
	$job240 = Repo::read( (int) $saved240['job']['id'] ); $plan240 = Repo::hydrate_plan( $job240 );
	$assert240( $plan240->hash() === $saved240['plan_hash'] && $plan240->json() === $saved240['plan_json'], 'old plan/hash unchanged ' . $case240 );
	foreach ( array( 'public_id', 'creator_id', 'approver_id', 'approved_at', 'plan_json', 'plan_hash' ) as $key240 ) {
		$assert240( $job240[$key240] === $saved240['job'][$key240], 'old approval unchanged ' . $key240 );
	}
	$assert240( Repo::items( (int) $job240['id'] ) === $saved240['items'], 'old item journal before Resume' );
	global $wpdb;
	foreach ( $saved240['apply_journal'] as $id240 => $old240 ) {
		$current240 = WriteLeash\Price_Apply_Journal::read( $wpdb, $job240['plan_id'], (int) $id240 );
		foreach ( array( 'id', 'plan_id', 'plan_hash', 'product_id', 'expected_price', 'target_price', 'state', 'attempt_id', 'reason', 'evidence', 'created_at', 'applied_at' ) as $key240 ) {
			$assert240( $current240[$key240] === $old240[$key240], 'direct Apply journal preserved ' . $case240 . '/' . $key240 );
		}
	}
	if ( in_array( $case240, array( 'resumable', 'resume_conflict' ), true ) ) {
		$assert240( Repo::counts( (int) $job240['id'] )['applied'] === 1, 'one old applied item' );
		for ( $i240 = 0; $i240 < 10 && ! WriteLeash\Job_State::is_terminal( Repo::read( (int) $job240['id'] )['status'] ); ++$i240 ) {
			Worker::run( (int) $job240['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true );
		}
	}
	foreach ( $saved240['ids'] as $i240 => $id240 ) { $assert240( ( 'resume_conflict' === $case240 && 1 === $i240 ? '120' : '110' ) === $price240( $id240 ), 'no double percentage or pending drift overwrite after upgrade' ); }
	$assert240( Repo::counts( (int) $job240['id'] )['applied'] === count( $saved240['ids'] ) - ( 'resume_conflict' === $case240 ? 1 : 0 ), 'durable applied counts' );
	if ( 'resume_conflict' === $case240 ) { $assert240( 1 === Repo::counts( (int) $job240['id'] )['conflict'], 'pending Resume drift is conflict' ); }
	if ( 'completed' === $case240 ) {
		$p240 = wc_get_product( $saved240['ids'][0] ); $p240->set_regular_price( '120' ); $p240->save();
	}
	$op240 = Undo::initiate( (int) $job240['id'], 1 );
	for ( $i240 = 0; $i240 < 10 && ! WriteLeash\Undo_State::is_terminal( Undo::read_operation( (int) $op240['id'] )['status'] ); ++$i240 ) {
		UndoWorker::run( (int) $op240['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true );
	}
	foreach ( $saved240['ids'] as $i240 => $id240 ) { $assert240( ( 'completed' === $case240 || ( 'resume_conflict' === $case240 && 1 === $i240 ) ? '120' : '100' ) === $price240( $id240 ), 'eligible Undo or preserved newer edit' ); }
	$counts240 = Undo::counts( (int) $op240['id'] );
	$assert240( ( 'completed' === $case240 ? 1 : 0 ) === $counts240['conflict'], 'Undo conflict count' );
	$assert240( ( 'completed' === $case240 ? 0 : count( $saved240['ids'] ) - ( 'resume_conflict' === $case240 ? 1 : 0 ) ) === $counts240['undone'], 'durable eligible UNDONE count' );
	if ( 'resume_conflict' === $case240 ) { $assert240( null === Undo::read_item( $wpdb, (int) $job240['id'], $saved240['ids'][1] ), 'Apply-conflicted pending item never Undo eligible' ); }
	echo '#240 upgrade ' . $case240 . ": old plan/approval retained, percentage not replayed, Undo/conflict PASS\n";
}
