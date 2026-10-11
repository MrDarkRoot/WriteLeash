<?php
// Run the existing #229 regression against a ZIP-installed plugin in-process.
global $wpdb, $saves240;
wp_set_current_user( 1 );
$saves240 = array();
add_action( 'woocommerce_before_product_object_save', static function ( $p ) use ( &$saves240 ): void {
	$id = $p->get_id(); $saves240[$id] = ( $saves240[$id] ?? 0 ) + 1;
} );
function eq( $actual, $expected, string $label ): void { if ( $actual !== $expected ) { throw new RuntimeException( $label ); } }
function saves( int $id ): int { global $saves240; return $saves240[$id] ?? 0; }
function limits( int $items ): array { return array( 'max_items' => $items, 'budget_seconds' => 20 ); }
function run_worker( array $data ): void {
	if ( 'apply' === $data['mode'] ) { WriteLeash\Job_Worker::run( $data['job_id'], $data['limits'], true ); }
	else { WriteLeash\Undo_Worker::run( $data['undo_id'], $data['limits'], true ); }
}
function journal_row( string $plan_id, int $id ): array { global $wpdb; return WriteLeash\Price_Apply_Journal::read( $wpdb, $plan_id, $id ); }
function start_undo( int $job_id ): array { return WriteLeash\Undo_Repository::initiate( $job_id, 1 ); }
function undo_row( int $job_id, int $id ): array { global $wpdb; return WriteLeash\Undo_Repository::read_item( $wpdb, $job_id, $id ); }
function marker( string $label ): void { echo $label . ": PASS\n"; }
require __DIR__ . '/../undo/parent-state.php';
if ( count( array_filter( array_keys( $saves240 ), static fn( $id ) => $id > 0 ) ) < 4 ) {
	throw new RuntimeException( '#240 save observer did not measure the successful controls' );
}
echo "#240 save observer: successful controls measured; refused Apply/Undo zero-save checks are active PASS\n";
