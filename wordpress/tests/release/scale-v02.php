<?php
// Exact installed-artifact 1,000 selected targets, two changing / 998 unchanged.
use WriteLeash\Free_Admin as Admin;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Price_Cache_Verifier as V;
wp_set_current_user( 1 );
$check240 = static function ( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( '#240 scale: ' . $label ); } };
$ids240 = array();
for ( $i240 = 0; $i240 < 1001; ++$i240 ) {
	$p240 = new WC_Product_Simple(); $p240->set_name( 'Scale240-' . wp_generate_uuid4() );
	$p240->set_status( 'publish' ); $p240->set_regular_price( $i240 < 2 ? '110' : '100' ); $p240->save(); $ids240[] = $p240->get_id();
}
$post240 = array( '_wpnonce' => wp_create_nonce( Admin::ACTION_PREVIEW ), 'selector' => 'manual_ids',
	'ids' => implode( ',', array_slice( $ids240, 0, 1000 ) ), 'operation' => 'SET', 'price_field' => 'regular_price', 'amount' => '100',
	'max_products' => '1000', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '100', 'block_zero' => '1' );
$result240 = Admin::process_preview( $post240, 'POST' ); $check240( 'OK' === $result240['status'], '1000 Preview' );
$job240 = Repo::read( $result240['job_id'] ); $plan240 = Repo::hydrate_plan( $job240 );
$check240( 1000 === $plan240->summary()['selected'], 'actual 1000 frozen targets' );
$result240 = Admin::process_approve( array( 'job' => $job240['public_id'], '_wpnonce' => wp_create_nonce( Admin::ACTION_APPROVE . '_' . $job240['plan_id'] ) ), 'POST' );
$check240( 'OK' === $result240['status'], 'separate approval' );
for ( $i240 = 0; $i240 < 110 && ! WriteLeash\Job_State::is_terminal( Repo::read( (int) $job240['id'] )['status'] ); ++$i240 ) {
	Worker::run( (int) $job240['id'], array( 'max_items' => 50, 'budget_seconds' => 20 ), true );
}
$counts240 = Repo::counts( (int) $job240['id'] );
$check240( 2 === $counts240['applied'] && 998 === $counts240['unchanged'], 'truthful completed population' );
foreach ( $ids240 as $id240 ) { V::invalidate( $id240 ); $check240( '100' === WriteLeash\Price_Decimal::parse( wc_get_product( $id240 )->get_regular_price( 'edit' ) ), 'expected current price' ); }
$op240 = WriteLeash\Undo_Repository::initiate( (int) $job240['id'], 1 );
for ( $i240 = 0; $i240 < 10 && ! WriteLeash\Undo_State::is_terminal( WriteLeash\Undo_Repository::read_operation( (int) $op240['id'] )['status'] ); ++$i240 ) {
	WriteLeash\Undo_Worker::run( (int) $op240['id'], array( 'max_items' => 10, 'budget_seconds' => 20 ), true );
}
$check240( 2 === WriteLeash\Undo_Repository::counts( (int) $op240['id'] )['undone'], 'eligible Undo count' );
$post240['ids'] = implode( ',', $ids240 );
$result240 = Admin::process_preview( $post240, 'POST' );
$check240( 'INVALID' === $result240['status'] && 'supported_job_limit_exceeded' === $result240['reason'], '1001 refused before approval' );
echo "#240 scale: 1000 selected, 2 changed / 998 unchanged, eligible Undo 2, 1001 rejected PASS\n";
