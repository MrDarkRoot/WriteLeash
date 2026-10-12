<?php
// Exact installed-artifact 1,000 selected targets, two changing / 998 unchanged.
use WriteLeash\Free_Admin as Admin;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Price_Cache_Verifier as V;
global $wpdb, $scale_saves240;
wp_set_current_user( 1 );
$check240 = static function ( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( '#240 scale: ' . $label ); } };
$ids240 = array();
for ( $i240 = 0; $i240 < 1001; ++$i240 ) {
	$p240 = new WC_Product_Simple(); $p240->set_name( 'Scale240-' . wp_generate_uuid4() );
	// The unselected 1,001st sentinel would change if accidentally included.
	$p240->set_status( 'publish' ); $p240->set_regular_price( $i240 < 2 ? '110' : ( 1000 === $i240 ? '120' : '100' ) ); $p240->save(); $ids240[] = $p240->get_id();
}
$price240 = static function ( int $id ): string {
	V::invalidate( $id ); return WriteLeash\Price_Decimal::parse( wc_get_product( $id )->get_regular_price( 'edit' ) );
};
foreach ( $ids240 as $i240 => $id240 ) { $check240( ( $i240 < 2 ? '110' : ( 1000 === $i240 ? '120' : '100' ) ) === $price240( $id240 ), 'actual original price' ); }
$scale_saves240 = array();
$watch240 = static function ( $p ) use ( &$scale_saves240 ): void {
	$id = $p->get_id(); $scale_saves240[$id] = ( $scale_saves240[$id] ?? 0 ) + 1;
};
add_action( 'woocommerce_before_product_object_save', $watch240 );
$members240 = static function ( string $table, string $key, $value ) use ( $wpdb ): array {
	$rows = $wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM %i WHERE $key=%s ORDER BY product_id", $table, $value ) );
	return array_map( 'intval', $rows );
};
$eligible240 = array_slice( $ids240, 0, 2 ); sort( $eligible240, SORT_NUMERIC );
$post240 = array( '_wpnonce' => wp_create_nonce( Admin::ACTION_PREVIEW ), 'selector' => 'manual_ids',
	'ids' => implode( ',', array_slice( $ids240, 0, 1000 ) ), 'operation' => 'SET', 'price_field' => 'regular_price', 'amount' => '100',
	'max_products' => '1000', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '100', 'block_zero' => '1' );
$result240 = Admin::process_preview( $post240, 'POST' ); $check240( 'OK' === $result240['status'], '1000 Preview' );
$job240 = Repo::read( $result240['job_id'] ); $plan240 = Repo::hydrate_plan( $job240 );
$check240( 1000 === $plan240->summary()['selected'], 'actual 1000 frozen targets' );
$check240( array_slice( $ids240, 0, 1000 ) === $plan240->data()['resolved_product_ids'], 'exact frozen selected membership; sentinel excluded' );
$result240 = Admin::process_approve( array( 'job' => $job240['public_id'], '_wpnonce' => wp_create_nonce( Admin::ACTION_APPROVE . '_' . $job240['plan_id'] ) ), 'POST' );
$check240( 'OK' === $result240['status'], 'separate approval' );
$check240( array() === $scale_saves240, 'Preview/approval zero product writes' );
$check240( $eligible240 === $members240( WriteLeash\Price_Apply_Journal::table( $wpdb ), 'plan_id', $job240['plan_id'] ), 'only changing targets journaled' );
for ( $i240 = 0; $i240 < 110 && ! WriteLeash\Job_State::is_terminal( Repo::read( (int) $job240['id'] )['status'] ); ++$i240 ) {
	Worker::run( (int) $job240['id'], array( 'max_items' => 50, 'budget_seconds' => 20 ), true );
}
$counts240 = Repo::counts( (int) $job240['id'] );
$check240( 2 === $counts240['applied'] && 998 === $counts240['unchanged'], 'truthful completed population' );
foreach ( $ids240 as $i240 => $id240 ) { $check240( ( 1000 === $i240 ? '120' : '100' ) === $price240( $id240 ), 'expected post-Apply price or excluded sentinel' ); }
foreach ( $eligible240 as $id240 ) {
	$row240 = WriteLeash\Price_Apply_Journal::read( $wpdb, $job240['plan_id'], $id240 );
	$check240( 'APPLIED' === $row240['state'] && '110' === WriteLeash\Price_Decimal::parse( $row240['expected_price'] ) &&
		'100' === WriteLeash\Price_Decimal::parse( $row240['target_price'] ), 'exact applied journal values' );
}
$written240 = array_keys( $scale_saves240 ); sort( $written240, SORT_NUMERIC );
$check240( $eligible240 === $written240, 'Apply writes only eligible selected targets; observer active' );
$before_undo240 = $scale_saves240;
$op240 = WriteLeash\Undo_Repository::initiate( (int) $job240['id'], 1 );
for ( $i240 = 0; $i240 < 10 && ! WriteLeash\Undo_State::is_terminal( WriteLeash\Undo_Repository::read_operation( (int) $op240['id'] )['status'] ); ++$i240 ) {
	WriteLeash\Undo_Worker::run( (int) $op240['id'], array( 'max_items' => 10, 'budget_seconds' => 20 ), true );
}
$check240( 2 === WriteLeash\Undo_Repository::counts( (int) $op240['id'] )['undone'], 'eligible Undo count' );
foreach ( $ids240 as $i240 => $id240 ) {
	$check240( ( $i240 < 2 ? '110' : ( 1000 === $i240 ? '120' : '100' ) ) === $price240( $id240 ), 'actual restored price or unchanged target' );
	if ( $i240 < 2 ) {
		$check240( ( $scale_saves240[$id240] ?? 0 ) > ( $before_undo240[$id240] ?? 0 ), 'eligible Undo observer active' );
		$check240( 'UNDONE' === WriteLeash\Undo_Repository::read_item( $wpdb, (int) $job240['id'], $id240 )['state'], 'eligible durable Undo state' );
	} else { $check240( ! isset( $scale_saves240[$id240] ), 'unchanged/excluded target zero Apply/Undo writes' ); }
}
$check240( $eligible240 === $members240( WriteLeash\Undo_Schema::items_table( $wpdb ), 'job_id', (int) $job240['id'] ), 'exact eligible Undo membership' );
$check240( $eligible240 === $members240( WriteLeash\Price_Apply_Journal::table( $wpdb ), 'plan_id', $job240['plan_id'] ), 'journal membership preserved after Undo' );
$written240 = array_keys( $scale_saves240 ); sort( $written240, SORT_NUMERIC );
$check240( $eligible240 === $written240, 'Undo writes only eligible targets' );
$before_refusal240 = $scale_saves240;
$post240['ids'] = implode( ',', $ids240 );
$result240 = Admin::process_preview( $post240, 'POST' );
$check240( 'INVALID' === $result240['status'] && 'supported_job_limit_exceeded' === $result240['reason'], '1001 refused before approval' );
$check240( $before_refusal240 === $scale_saves240, '1001 refusal zero product writes' );
foreach ( $ids240 as $i240 => $id240 ) { $check240( ( $i240 < 2 ? '110' : ( 1000 === $i240 ? '120' : '100' ) ) === $price240( $id240 ), 'refused selection preserves prices' ); }
remove_action( 'woocommerce_before_product_object_save', $watch240 );
echo "#240 scale: 1000 selected, 2 changed / 998 unchanged, restored 110/110, exact journal/Undo membership, zero ineligible writes, 1001 rejected PASS\n";
