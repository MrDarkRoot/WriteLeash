<?php
// Disposable exact-ZIP fault probes; parent harness sends real SIGKILL or kills
// only this worker's connection on a task-owned DB instance. Never production.
use WriteLeash\Job_Repository as R;
use WriteLeash\Job_Worker as W;
use WriteLeash\Price_Apply_Journal as J;
use WriteLeash\Price_Cache_Verifier as V;
use WriteLeash\Price_Decimal as D;
use WriteLeash\Undo_Repository as U;
global $wpdb;
wp_set_current_user( 1 );
$mode240 = getenv( 'WL240_RECOVERY_MODE' ); $kind240 = getenv( 'WL240_RECOVERY_KIND' ); $file240 = getenv( 'WL240_RECOVERY_RECEIPT' );
if ( ! $file240 || ! in_array( $kind240, array( 'before_commit', 'after_commit', 'connection_loss' ), true ) ) { throw new RuntimeException( 'Explicit disposable recovery inputs required' ); }
$check240 = static function ( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( '#240 recovery: ' . $label ); } };
$price240 = static function ( int $id ): string { V::invalidate( $id ); return D::parse( wc_get_product( $id )->get_regular_price( 'edit' ) ); };
$plan240 = static function ( array $ids ): array {
	$plan = WriteLeash\Woo_Price_Planner::preview( WriteLeash\Price_Selection_Spec::ids( $ids ), new WriteLeash\Price_Operation( WriteLeash\Price_Operation::INCREASE_PERCENT, '10' ), new WriteLeash\Safety_Policy( 1000, '100', '100', true, '100' ) );
	$job = R::create_from_plan( $plan, 1 ); return array( $plan, R::approve( (int) $job['id'], 1 ) );
};
if ( 'seed' === $mode240 ) {
	$ids240 = array();
	for ( $i240 = 0; $i240 < 3; ++$i240 ) { $p = new WC_Product_Simple(); $p->set_name( 'Recovery240-' . wp_generate_uuid4() ); $p->set_status( 'publish' ); $p->set_regular_price( '100' ); $p->save(); $ids240[] = $p->get_id(); }
	list( $plan, $job ) = $plan240( $ids240 );
	file_put_contents( $file240, wp_json_encode( array( 'ids' => $ids240, 'job' => $job, 'plan_json' => $plan->json(), 'plan_hash' => $plan->hash() ) ) ); chmod( $file240, 0600 );
	echo "#240 recovery approved three-target percent job seeded PASS\n"; return;
}
$receipt240 = json_decode( file_get_contents( $file240 ), true, 512, JSON_THROW_ON_ERROR );
$ids240 = $receipt240['ids']; $job240 = R::read( (int) $receipt240['job']['id'] );
if ( 'worker' === $mode240 ) {
	$hook240 = static function ( string $point, int $id, string $attempt ) use ( $kind240, $ids240 ): void {
		$target = 'after_commit' === $kind240 ? 'AFTER_COMMIT_BEFORE_RESPONSE' : 'AFTER_WOO_SAVE_BEFORE_JOURNAL';
		if ( $point !== $target || $id !== $ids240[0] ) { return; }
		if ( 'connection_loss' === $kind240 ) {
			global $wpdb;
			$connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
			$db = V::observer(); try { $db->query( 'KILL ' . $connection ); } finally { $db->close(); }
			return;
		}
		file_put_contents( getenv( 'WL240_RECOVERY_MARKER' ), $point );
		sleep( 300 ); // The parent must observe this checkpoint and send real SIGKILL.
	};
	add_action( 'writeleash_price_apply_checkpoint', $hook240, 10, 3 );
	try { echo wp_json_encode( W::run( (int) $job240['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true ) ); }
	finally { remove_action( 'writeleash_price_apply_checkpoint', $hook240, 10 ); }
	return;
}
$plan = R::hydrate_plan( $job240 );
$check240( $plan->hash() === $receipt240['plan_hash'] && $plan->json() === $receipt240['plan_json'], 'approved plan preserved after failure' );
$row240 = J::read( $wpdb, $job240['plan_id'], $ids240[0] );
if ( 'observe' === $mode240 ) {
	$check240( ( 'after_commit' === $kind240 ? '110' : '100' ) === $price240( $ids240[0] ), 'authoritative price after interrupted boundary' );
	$check240( ( 'after_commit' === $kind240 ? 'APPLIED' : ( 'connection_loss' === $kind240 ? 'NEEDS_REVIEW' : 'PENDING' ) ) === $row240['state'], 'authoritative journal state after boundary' );
	foreach ( array_slice( $ids240, 1 ) as $id ) { $check240( '100' === $price240( $id ), 'unvisited price unchanged' ); }
	echo '#240 ' . $kind240 . ': interrupted price/journal authoritative, unvisited targets unchanged PASS' . "\n"; return;
}
$check240( 'recover' === $mode240, 'known mode' );
if ( 'connection_loss' === $kind240 ) {
	$check240( 'NEEDS_REVIEW' === $job240['status'], 'connection loss requires review, not silent retry' );
	$refused240 = false;
	try { U::initiate( (int) $job240['id'], 1 ); } catch ( WriteLeash\Undo_Error $error ) { $refused240 = 'UNDO_JOB_RUNNING' === $error->reason(); }
	$check240( $refused240, 'uncertain original job cannot claim eligible Undo' );
	// Deliberately fresh current-price Preview and independent approval; old
	// ambiguous evidence is not repaired, overwritten or converted to APPLIED.
	list( $plan, $job240 ) = $plan240( $ids240 );
}
for ( $i240 = 0; $i240 < 12 && ! WriteLeash\Job_State::is_terminal( R::read( (int) $job240['id'] )['status'] ); ++$i240 ) {
	W::run( (int) $job240['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true );
}
$check240( 3 === R::counts( (int) $job240['id'] )['applied'], 'truthful applied population' );
foreach ( $ids240 as $id ) { $check240( '110' === $price240( $id ), 'percentage applied once, never 121' ); }
if ( 'connection_loss' === $kind240 ) {
	$check240( 'NEEDS_REVIEW' === J::read( $wpdb, $receipt240['job']['plan_id'], $ids240[0] )['state'], 'old uncertain journal retained' );
}
$op = U::initiate( (int) $job240['id'], 1 );
for ( $i240 = 0; $i240 < 12 && ! WriteLeash\Undo_State::is_terminal( U::read_operation( (int) $op['id'] )['status'] ); ++$i240 ) { WriteLeash\Undo_Worker::run( (int) $op['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true ); }
$check240( 3 === U::counts( (int) $op['id'] )['undone'], 'eligible durable Undo population' );
foreach ( $ids240 as $id ) { $check240( '100' === $price240( $id ), 'Undo actual restoration' ); }
echo '#240 ' . $kind240 . ': explicit recovery/Resume, no percent replay, truthful history and eligible Undo PASS' . "\n";
