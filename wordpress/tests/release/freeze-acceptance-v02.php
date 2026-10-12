<?php
// Exact-installed-ZIP focused acceptance, including retained state and no-write gates.
use WriteLeash\Free_Admin as A;
use WriteLeash\Job_Repository as R;
use WriteLeash\Job_Worker as W;
use WriteLeash\Price_Cache_Verifier as V;
use WriteLeash\Price_Decimal as D;
use WriteLeash\Undo_Repository as U;
global $freeze_saves240;
wp_set_current_user( 1 );
$check240 = static function ( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( '#240 freeze: ' . $label ); } };
$price240 = static function ( int $id ): string { V::invalidate( $id ); return D::parse( wc_get_product( $id )->get_regular_price( 'edit' ) ); };
$p240 = new WC_Product_Simple(); $p240->set_name( 'Freeze240-' . wp_generate_uuid4() ); $p240->set_status( 'publish' ); $p240->set_regular_price( '100' ); $p240->save(); $id240 = $p240->get_id();
$freeze_saves240 = array();
$watch240 = static function ( $p ) use ( &$freeze_saves240 ): void { $id = $p->get_id(); $freeze_saves240[$id] = ( $freeze_saves240[$id] ?? 0 ) + 1; };
add_action( 'woocommerce_before_product_object_save', $watch240 );
try {
	$form240 = array( '_wpnonce' => wp_create_nonce( A::ACTION_PREVIEW ), 'selector' => 'manual_ids', 'ids' => (string) $id240,
		'operation' => 'INCREASE_PERCENT', 'price_field' => 'regular_price', 'amount' => '10', 'max_products' => '1000',
		'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '100', 'block_zero' => '1' );
	$bad240 = $form240; $bad240['_wpnonce'] = 'invalid';
	$check240( 'INVALID' === A::process_preview( $bad240, 'POST' )['status'], 'bad nonce refused' );
	$check240( 'INVALID' === A::process_preview( $form240, 'GET' )['status'], 'GET refused' );
	wp_set_current_user( 0 ); $check240( 'FORBIDDEN' === A::process_preview( $form240, 'POST' )['status'], 'anonymous refused' ); wp_set_current_user( 1 );
	$result240 = A::process_preview( $form240, 'POST' ); $check240( 'OK' === $result240['status'], 'Preview' );
	$job240 = R::read( (int) $result240['job_id'] ); $plan240 = R::hydrate_plan( $job240 );
	$check240( '100' === $price240( $id240 ) && array() === $freeze_saves240, 'Preview/refusals zero writes' );
	$hash240 = $plan240->hash(); $bytes240 = $plan240->json();
	$result240 = A::process_approve( array( 'job' => $job240['public_id'], '_wpnonce' => wp_create_nonce( A::ACTION_APPROVE . '_' . $job240['plan_id'] ) ), 'POST' );
	$check240( 'OK' === $result240['status'] && array() === $freeze_saves240, 'separate approval zero writes' );
	for ( $i240 = 0; $i240 < 10 && ! WriteLeash\Job_State::is_terminal( R::read( (int) $job240['id'] )['status'] ); ++$i240 ) { W::run( (int) $job240['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true ); }
	$check240( '110' === $price240( $id240 ) && 1 === R::counts( (int) $job240['id'] )['applied'], 'Apply actual price/outcome' );
	$check240( ( $freeze_saves240[$id240] ?? 0 ) > 0, 'same-product observer active' );
	$history240 = U::history_job( (int) $job240['id'] ); $check240( $history240['undo_eligible'], 'History eligible Undo' );
	ob_start(); A::render_view( 'history', $job240['public_id'], 0 ); $html240 = ob_get_clean();
	$check240( false !== strpos( $html240, $job240['public_id'] ), 'History contains durable job identity' );
	$before240 = $freeze_saves240;
	$check240( R::hydrate_plan( R::read( (int) $job240['id'] ) )->hash() === $hash240 && R::hydrate_plan( R::read( (int) $job240['id'] ) )->json() === $bytes240, 'persistent plan unchanged' );
	$check240( $before240 === $freeze_saves240, 'History/read zero writes' );
	$op240 = U::initiate( (int) $job240['id'], 1 );
	for ( $i240 = 0; $i240 < 10 && ! WriteLeash\Undo_State::is_terminal( U::read_operation( (int) $op240['id'] )['status'] ); ++$i240 ) { WriteLeash\Undo_Worker::run( (int) $op240['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true ); }
	$check240( '100' === $price240( $id240 ) && 1 === U::counts( (int) $op240['id'] )['undone'], 'Undo restored price and durable outcome' );
	$check240( ( $freeze_saves240[$id240] ?? 0 ) > ( $before240[$id240] ?? 0 ), 'Undo save observer active' );
	echo '#240 exact-ZIP Preview/Approve/Apply/History/Undo, plan preservation, nonce/capability and no-write controls PASS; PHP=' . PHP_VERSION . '; Woo=' . WC_VERSION . "\n";
} finally { remove_action( 'woocommerce_before_product_object_save', $watch240 ); }
