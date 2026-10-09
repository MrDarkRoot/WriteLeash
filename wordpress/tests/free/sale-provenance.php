<?php
// Exercise the actual Undo provenance assembler with a read-only journal double.
// This specifically detects numeric-only parsing of a cleared applied target.
require __DIR__ . '/sale-operations.php';
require __DIR__ . '/../../writeleash/includes/free/class-undo-repository.php';
define( 'ARRAY_A', 'ARRAY_A' );
class wpdb {
 public string $prefix = 'wp_';
 public array $row = array();
 public function prepare( $query, ...$args ) { return $query; }
 public function get_row( $query, $output ) { return $this->row; }
}
function get_post( $id ) { return null; }
$db207 = new wpdb();
$assemble207 = new ReflectionMethod( WriteLeash\Undo_Repository::class, 'provenance_facts' );
foreach ( array( new WriteLeash\Price_Operation( 'CLEAR_SALE', '', 'sale_price' ), new WriteLeash\Price_Operation( 'SALE_DISCOUNT_PERCENT', '20', 'sale_price' ) ) as $operation207 ) {
 $plan207 = wl178_plan( array( wl178_product( 607, '100', '90' ) ), $operation207 );
 $item207 = $plan207->item( 607 )->data();
 $job_item207 = array( 'product_id' => 607, 'expected_price' => $item207['expected_regular_price'], 'planned_price' => $item207['planned_regular_price'] );
 $data207 = $plan207->data();
 $job207 = array_merge( $data207['store'], array( 'id' => 1, 'creator_id' => 1 ) );
 $evidence207 = array( 'attempt_id' => 'attempt', 'target' => $job_item207['planned_price'], 'price_field' => 'sale_price', 'field_value' => $job_item207['planned_price'], 'regular' => '100', 'sale' => $job_item207['planned_price'] );
 $db207->row = array( 'schema_version' => WriteLeash\Price_Apply_Journal::SCHEMA_VERSION, 'plan_id' => $data207['plan_id'], 'plan_schema_version' => $data207['schema_version'], 'plan_hash_version' => $data207['hash_version'], 'plan_hash' => $plan207->hash(), 'product_id' => 607, 'plan_json' => null, 'plan_fingerprint' => hash( 'sha256', $plan207->json() ), 'price_field' => 'sale_price', 'expected_price' => $job_item207['expected_price'], 'target_price' => $job_item207['planned_price'], 'state' => 'APPLIED', 'attempt_id' => 'attempt', 'applied_at' => '2026-10-09 00:00:00', 'evidence' => json_encode( $evidence207 ) );
 $facts207 = $assemble207->invoke( null, $db207, $job207, $plan207, $job_item207, 1 );
 wl178_equal( $facts207['applied_price'], $job_item207['planned_price'], 'real assembler retains blank/numeric target distinctly' );
 wl178_equal( $facts207['regular_context'], '100', 'assembler preserves approved regular context' );
 $capture207 = WriteLeash\Undo_Fingerprint::capture( $facts207 );
 wl178_equal( json_decode( $capture207['provenance'], true )['applied_price'], $job_item207['planned_price'], 'durable provenance retains exact applied value' );
 foreach ( array( '0', 'bad', null ) as $wrong207 ) {
  $bad207 = $evidence207; $bad207['target'] = $wrong207; $db207->row['evidence'] = json_encode( $bad207 );
  wl178_undo_error( static fn() => $assemble207->invoke( null, $db207, $job207, $plan207, $job_item207, 1 ), 'UNDO_PROVENANCE_MISMATCH' );
 }
}
echo "#207 actual Undo provenance assembler: PASS (read-only journal double, no DB runtime claim)\n";
