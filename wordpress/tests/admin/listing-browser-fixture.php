<?php
// #182 deterministic listing capture fixture. Seeds one of each merchant state
// on the existing disposable Woo Admin site; no screenshots are committed here.
$mode = (string) getenv( 'WL167_LISTING_MODE' );
$file = (string) getenv( 'WL167_LISTING_FIXTURE' );
if ( 'seed' !== $mode || ! $file ) { throw new RuntimeException( 'Explicit #182 disposable listing fixture required' ); }
wp_set_current_user( 1 );

function listing182_product( string $name, string $price, string $sale = '' ): int {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( 'publish' );
	$p->set_regular_price( $price );
	if ( '' !== $sale ) { $p->set_sale_price( $sale ); }
	$p->save();
	return $p->get_id();
}

function listing182_plan( array $ids, string $operation, string $amount, string $field = 'regular_price', array $extra = array() ): array {
	$result = WriteLeash\Free_Admin::process_preview( array_merge( array(
		'_wpnonce' => wp_create_nonce( WriteLeash\Free_Admin::ACTION_PREVIEW ),
		'selector' => 'ids', 'ids' => implode( ',', $ids ),
		'operation' => $operation, 'amount' => $amount, 'price_field' => $field,
		'max_products' => '100', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '100',
	), $extra ), 'POST' );
	if ( 'OK' !== $result['status'] ) { throw new RuntimeException( wp_json_encode( $result ) ); }
	return WriteLeash\Job_Repository::read_by_public_id( $result['public_id'] );
}

function listing182_approve( array $job ): void {
	WriteLeash\Job_Repository::approve( (int) $job['id'], get_current_user_id() );
}

function listing182_finish( array $job ): void {
	for ( $step = 0; $step < 15; ++$step ) {
		WriteLeash\Job_Worker::run( (int) $job['id'], array( 'max_items' => 10, 'budget_seconds' => 20 ), true );
		if ( WriteLeash\Job_State::is_terminal( WriteLeash\Job_Repository::read( (int) $job['id'] )['status'] ) ) { return; }
	}
	throw new RuntimeException( 'Listing fixture failed to finish Apply' );
}

$search = 'WL Listing';

// 1) Preview with regular targets: simple products plus one variable product so
// the preview lists real variations by product name and attributes.
$regular = array();
foreach ( array( 'Tee', 'Mug', 'Tote', 'Cap', 'Socks', 'Notebook' ) as $name ) { $regular[] = listing182_product( $search . ' ' . $name, '18.00' ); }
$regular[] = listing182_product( $search . ' Apron', '24.00', '19.20' );
$parent = new WC_Product_Variable();
$parent->set_name( $search . ' Shirt' );
$parent->set_status( 'publish' );
$parent->set_attributes( array(
	'color' => array( 'name' => 'Color', 'value' => 'Blue | Red', 'position' => 0, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 0 ),
	'size' => array( 'name' => 'Size', 'value' => 'M | L', 'position' => 1, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 0 ),
) );
$parent->save();
$parent_id = $parent->get_id();
foreach ( array( array( 'color' => 'Blue', 'size' => 'M', 'price' => '18.00' ), array( 'color' => 'Red', 'size' => 'L', 'price' => '22.00' ) ) as $attrs ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent_id );
	$variation->set_regular_price( $attrs['price'] );
	$variation->set_attributes( array( 'color' => $attrs['color'], 'size' => $attrs['size'] ) );
	$variation->save();
}
$regular_preview = listing182_plan( array_merge( $regular, array( $parent_id ) ), 'DECREASE_PERCENT', '20', 'regular_price' );

// 2) Preview with sale targets: existing sales plus one first sale price.
$sale_ids = array(
	listing182_product( $search . ' Sale A', '18.00', '16.00' ),
	listing182_product( $search . ' Sale B', '24.00', '21.00' ),
	listing182_product( $search . ' First Sale', '20.00' ),
);
$sale_preview = listing182_plan( $sale_ids, 'SET', '14.40', 'sale_price' );

// 3) Large-job progress: one bounded step leaves most products remaining.
$progress_ids = array();
for ( $i = 1; $i <= 24; ++$i ) { $progress_ids[] = listing182_product( $search . ' Progress ' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ), '100.00' ); }
$progress_job = listing182_plan( $progress_ids, 'DECREASE_PERCENT', '10', 'regular_price' );
listing182_approve( $progress_job );
WriteLeash\Job_Worker::run( (int) $progress_job['id'], array( 'max_items' => 8, 'budget_seconds' => 20 ), true );

// 4) Finished results: changed products plus one already at the target price.
$results_ids = array( listing182_product( $search . ' Result A', '18.00' ), listing182_product( $search . ' Result B', '18.00' ), listing182_product( $search . ' Result C', '18.00' ), listing182_product( $search . ' Result D', '14.40' ) );
$results_job = listing182_plan( $results_ids, 'SET', '14.40', 'regular_price' );
listing182_approve( $results_job );
listing182_finish( $results_job );

// 5) Conflicts: one product changes between review and apply.
$conflict_ids = array( listing182_product( $search . ' Conflict A', '100.00' ), listing182_product( $search . ' Conflict B', '100.00' ), listing182_product( $search . ' Conflict C', '100.00' ) );
$conflict_job = listing182_plan( $conflict_ids, 'SET', '80.00', 'regular_price' );
listing182_approve( $conflict_job );
$changed = wc_get_product( $conflict_ids[0] );
$changed->set_regular_price( '75.00' );
$changed->save();
listing182_finish( $conflict_job );

$fixture = array(
	'username' => 'admin', 'password' => 'disposable_admin_password', 'search' => $search, 'parent' => $parent_id,
	'jobs' => array(
		'regular_preview' => $regular_preview['public_id'],
		'sale_preview' => $sale_preview['public_id'],
		'progress' => $progress_job['public_id'],
		'results' => $results_job['public_id'],
		'conflicts' => $conflict_job['public_id'],
	),
);
file_put_contents( $file, wp_json_encode( $fixture ) );
chmod( $file, 0600 );
echo '#182 listing fixture seeded';
