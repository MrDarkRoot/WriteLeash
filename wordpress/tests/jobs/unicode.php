<?php
// #171 real Woo/DB Unicode storage, upgrade and unchanged-authority regression.
use WriteLeash\Free_Admin as A171;
use WriteLeash\Job_Repository as R171;
use WriteLeash\Job_Schema as S171;
use WriteLeash\Job_Worker as W171;
use WriteLeash\Price_Apply_Journal as J171;
use WriteLeash\Price_Cache_Verifier as V171;
use WriteLeash\Undo_Schema as US171;
use WriteLeash\Undo_Repository as UR171;
use WriteLeash\Undo_Worker as UW171;

global $wpdb, $u171_assertions;
$u171_assertions = 0;
function u171_eq( $actual, $expected, string $label ): void {
 global $u171_assertions; ++$u171_assertions;
 if ( $actual !== $expected ) { throw new RuntimeException( '#171 ' . $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function u171_product( string $name, string $sku = '' ): int {
 $p = new WC_Product_Simple(); $p->set_name( $name ); $p->set_sku( $sku ); $p->set_status( 'publish' ); $p->set_regular_price( '100.00' ); $p->save(); return $p->get_id();
}
function u171_preview( array $ids ): array {
 return A171::process_preview( array( '_wpnonce' => wp_create_nonce( A171::ACTION_PREVIEW ), 'selector' => 'ids', 'ids' => implode( ',', $ids ), 'operation' => 'DECREASE_PERCENT', 'amount' => '20', 'max_products' => '100', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '100' ), 'POST' );
}
function u171_tables(): array {
 global $wpdb;
 return array( S171::jobs_table( $wpdb ) => array( 'plan_id', 'public_id' ), S171::items_table( $wpdb ) => array( 'plan_id' ), J171::table( $wpdb ) => array( 'plan_id' ), US171::operations_table( $wpdb ) => array( 'plan_id', 'public_id' ), US171::items_table( $wpdb ) => array( 'plan_id', 'fingerprint' ) );
}
function u171_rows(): array {
 global $wpdb; $rows = array();
 foreach ( u171_tables() as $table => $binary ) { $rows[$table] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A ); }
 return $rows;
}
function u171_install(): void { S171::install(); J171::install(); US171::install(); }
function u171_shape(): array {
 global $wpdb; $shapes = array();
 foreach ( u171_tables() as $table => $binary ) {
  $cols = $wpdb->get_results( $wpdb->prepare( 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION', $table ), ARRAY_A );
  foreach ( $cols as $column ) {
   if ( null !== $column['CHARACTER_SET_NAME'] ) { u171_eq( $column['CHARACTER_SET_NAME'], 'utf8mb4', 'uniform durable charset ' . $table . '.' . $column['COLUMN_NAME'] ); }
   if ( in_array( $column['COLUMN_NAME'], $binary, true ) ) { u171_eq( $column['COLLATION_NAME'], 'utf8mb4_bin', 'binary identity collation ' . $table . '.' . $column['COLUMN_NAME'] ); }
  }
  $shapes[$table] = $cols;
 }
 return $shapes;
}
function u171_legacy(): void {
 global $wpdb;
 foreach ( u171_tables() as $table => $binary ) {
  $clauses = array();
  foreach ( $binary as $column ) {
   $type = 'public_id' === $column ? 'char(36)' : ( 'fingerprint' === $column ? 'char(64)' : 'varchar(64)' );
   $clauses[] = $wpdb->prepare( 'MODIFY %i ' . $type . ' CHARACTER SET ascii COLLATE ascii_bin NOT NULL', $column );
  }
  u171_eq( false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ', $table ) . implode( ', ', $clauses ) ), true, 'fixture recreates untouched-main mixed schema' );
  WriteLeash\Price_Apply_Connection::forget_table_metadata( $wpdb, $table );
 }
}
wp_set_current_user( 1 );
$cache171 = wp_using_ext_object_cache() ? 'persistent' : 'default';
$tag171 = wp_generate_uuid4();
$ascii171 = u171_product( 'Coffee ' . $tag171 );
$ascii_result171 = u171_preview( array( $ascii171 ) );
u171_eq( $ascii_result171['status'], 'OK', 'ASCII still imports' );
$names171 = array( 'Café', 'Cà phê sữa đá', '咖啡', 'Coffee 🍵', "Café '\\ <script>咖啡🍵</script> &" );
$ids171 = array(); $identity171 = array();
foreach ( $names171 as $i171 => $name171 ) {
 $id171 = u171_product( $name171, 'SKU-' . $tag171 . '-Café-' . $i171 ); $ids171[] = $id171;
 $p171 = wc_get_product( $id171 ); $identity171[$id171] = array( 'name' => $p171->get_name( 'edit' ), 'sku' => $p171->get_sku( 'edit' ) );
}
$saves171 = 0; $counter171 = static function () use ( &$saves171 ) { ++$saves171; };
add_action( 'woocommerce_before_product_object_save', $counter171 );
$result171 = u171_preview( $ids171 );
u171_eq( $result171['status'], 'OK', 'Unicode preview/import succeeds: ' . ( $result171['reason'] ?? '' ) );
u171_eq( $saves171, 0, 'successful Unicode preview/import performs zero product saves' );
$job171 = R171::read_by_public_id( $result171['public_id'] ); $plan171 = R171::hydrate_plan( $job171 ); $json171 = $job171['plan_json'];
u171_eq( $json171, $plan171->json(), 'exact canonical UTF-8 bytes stored, no alternate serialization' );
u171_eq( $plan171->data()['resolved_product_ids'], $ids171, 'exact frozen population' );
foreach ( $plan171->data()['items'] as $item171 ) {
 u171_eq( $item171['snapshot']['name'], $identity171[$item171['product_id']]['name'], 'name round-trip' ); u171_eq( $item171['snapshot']['sku'], $identity171[$item171['product_id']]['sku'], 'SKU round-trip' ); u171_eq( $item171['planned_regular_price'], '80.00', 'absolute target unchanged' );
}
ob_start(); A171::render_view( 'preview', $job171['public_id'], 0 ); $html171 = ob_get_clean();
u171_eq( str_contains( $html171, 'Cà phê sữa đá' ), true, 'saved preview reopens Vietnamese identity' );
u171_eq( str_contains( $html171, '<script>咖啡' ), false, 'identity output remains escaped' );
u171_eq( str_contains( $html171, '&lt;script&gt;' ), true, 'security-shaped identity shown as text' );
$approval171 = A171::process_approve( array( '_wpnonce' => wp_create_nonce( A171::ACTION_APPROVE . '_' . $job171['plan_id'] ), 'job' => $job171['public_id'] ), 'POST' );
u171_eq( $approval171['status'], 'OK', 'approval seeds Unicode journal material' );
u171_eq( $saves171, 0, 'approval/journal seeding performs zero Woo saves' );
$first171 = W171::run( (int) $job171['id'], array( 'max_items' => 2, 'budget_seconds' => 20 ), true );
u171_eq( $first171['counts']['applied'], 2, 'first bounded execution chunk' );
u171_eq( $first171['counts']['pending'], 3, 'remaining work stays durable' );
$observer171 = V171::observer();
u171_eq( $observer171->get_var( $observer171->prepare( 'SELECT plan_json FROM %i WHERE id=%d', S171::jobs_table( $observer171 ), $job171['id'] ) ), $json171, 'fresh independent connection reopens the same UTF-8 plan' ); $observer171->close();
for ( $i171 = 0; $i171 < 3; ++$i171 ) { W171::run( (int) $job171['id'], array( 'max_items' => 2, 'budget_seconds' => 20 ), true ); }
u171_eq( R171::counts( (int) $job171['id'] )['applied'], 5, 'Resume completes exact frozen targets' );
u171_eq( $saves171, 5, 'one apply save per Unicode product, no replay' );
foreach ( $ids171 as $id171 ) { u171_eq( J171::read( $wpdb, $job171['plan_id'], $id171 )['plan_json'], $json171, 'journal retains exact UTF-8 frozen plan' ); }
$history171 = UR171::history_job( (int) $job171['id'] ); u171_eq( $history171['undo_eligible'], true, 'Unicode job History/Undo eligibility truthful' );
$undo171 = UR171::initiate( (int) $job171['id'], 1 );
u171_eq( in_array( 'name', WriteLeash\Undo_Fingerprint::fields(), true ), false, 'name is not a conflict fingerprint' );
u171_eq( in_array( 'sku', WriteLeash\Undo_Fingerprint::fields(), true ), false, 'SKU is not a conflict fingerprint' );
foreach ( $ids171 as $id171 ) {
 $row171 = UR171::read_item( $wpdb, (int) $job171['id'], $id171 );
 u171_eq( WriteLeash\Undo_Fingerprint::fingerprint_of( json_decode( $row171['provenance'], true ) ), $row171['fingerprint'], 'Undo provenance unchanged' );
}
remove_action( 'woocommerce_before_product_object_save', $counter171 );

// Old main's physical encoding, with real ASCII/Unicode jobs, APPLIED journal,
// partial execution and pending Undo. The migration may not rewrite any row.
US171::install(); $fresh_shape171 = u171_shape();
$partial_ids171 = array( u171_product( 'Partial Cà phê 🍵' ), u171_product( 'Partial 咖啡' ), u171_product( 'Partial Café' ) );
$partial_preview171 = u171_preview( $partial_ids171 ); $partial_job171 = R171::read_by_public_id( $partial_preview171['public_id'] );
R171::approve( (int) $partial_job171['id'], 1 );
W171::run( (int) $partial_job171['id'], array( 'max_items' => 1, 'budget_seconds' => 20 ), true );
u171_eq( R171::counts( (int) $partial_job171['id'] )['pending'], 2, 'real queued/partial state exists before migration' );
$before171 = u171_rows(); u171_legacy();
$wpdb->get_col_charset( S171::jobs_table( $wpdb ), 'plan_json' );
u171_eq( $wpdb->table_charset[strtolower( S171::jobs_table( $wpdb ) )], 'ascii', 'fixture warms Core legacy charset cache' );
u171_eq( S171::ready( $wpdb ), true, 'known legacy ASCII jobs remain readable before explicit setup migration' );
u171_install();
u171_eq( u171_rows(), $before171, 'upgrade preserves every job/item/journal/Undo value and timestamp' );
u171_eq( u171_shape(), $fresh_shape171, 'fresh and upgraded effective schema converge' );
u171_eq( $wpdb->get_col_charset( S171::jobs_table( $wpdb ), 'plan_json' ), 'utf8mb4', 'same-request metadata cache refreshed' );
u171_eq( $wpdb->table_charset[strtolower( S171::jobs_table( $wpdb ) )], 'utf8mb4', 'Core whole-table classification now utf8mb4' );
u171_install(); u171_install(); u171_eq( u171_rows(), $before171, 'already-correct/repeated migration preserves rows' );
u171_eq( R171::hydrate_plan( R171::read_by_public_id( $ascii_result171['public_id'] ) )->data()['items'][0]['snapshot']['name'], 'Coffee ' . $tag171, 'pre-existing ASCII job readable after migration' );
// Name/SKU edits stay provenance-only across upgrade; Resume never re-plans.
$renamed171 = wc_get_product( $partial_ids171[1] ); $renamed171->set_name( 'Renamed 咖啡 🍵' ); $renamed171->set_sku( 'changed-' . $tag171 ); $renamed171->save();
W171::run( (int) $partial_job171['id'], array( 'max_items' => 2, 'budget_seconds' => 20 ), true );
u171_eq( R171::counts( (int) $partial_job171['id'] )['applied'], 3, 'upgraded partial job resumes; identity text is not a conflict fingerprint' );
u171_eq( R171::read_by_public_id( $partial_job171['public_id'] )['plan_json'], $partial_job171['plan_json'], 'Resume retains old frozen JSON and targets' );
$post_upgrade171 = u171_preview( $ids171 ); u171_eq( $post_upgrade171['status'], 'OK', 'new Unicode import works on upgraded install in same request' );
for ( $i171 = 0; $i171 < 4; ++$i171 ) { UW171::run( (int) $undo171['id'], array( 'max_items' => 2, 'budget_seconds' => 20 ), true ); }
foreach ( $ids171 as $id171 ) { V171::invalidate( $id171 ); u171_eq( (float) wc_get_product( $id171 )->get_regular_price( 'edit' ), 100.0, 'pending eligible Undo still works after encoding migration' ); }
u171_eq( R171::read_by_public_id( $job171['public_id'] )['plan_json'], $json171, 'Undo never changes frozen Unicode material' );

// Unknown byte storage must fail before DDL; never discard or decode a BLOB.
$table171 = S171::jobs_table( $wpdb );
u171_eq( false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY plan_json longblob NOT NULL', $table171 ) ), true, 'unknown byte-storage fixture' );
$raw171 = $wpdb->get_var( $wpdb->prepare( 'SELECT HEX(plan_json) FROM %i WHERE id=%d', $table171, $job171['id'] ) );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET plan_json=0xFF WHERE id=%d', $table171, $job171['id'] ) );
$invalid171 = $wpdb->get_var( $wpdb->prepare( 'SELECT HEX(plan_json) FROM %i WHERE id=%d', $table171, $job171['id'] ) );
u171_eq( $invalid171, 'FF', 'fixture contains genuinely undecodable bytes' );
$failed171 = false;
try { S171::install(); } catch ( Throwable $error171 ) { $failed171 = true; }
u171_eq( $failed171, true, 'unsupported byte-storage migration explicitly refused' );
u171_eq( $wpdb->get_var( $wpdb->prepare( 'SELECT HEX(plan_json) FROM %i WHERE id=%d', $table171, $job171['id'] ) ), $invalid171, 'failed migration retains exact undecodable evidence bytes' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET plan_json=UNHEX(%s) WHERE id=%d', $table171, $raw171, $job171['id'] ) );
$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i MODIFY plan_json longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL', $table171 ) ); u171_install();
// A failed second-table ALTER must retain every row; retry finishes the family.
u171_legacy(); $retry_rows171 = u171_rows(); $mode171 = $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
$fault_table171 = $wpdb->prepare( 'ALTER TABLE %i ', S171::items_table( $wpdb ) );
$fault171 = static function ( $sql ) use ( $fault_table171 ) {
 if ( str_starts_with( $sql, $fault_table171 ) ) { return $sql . ' INVALID_CONTROLLED_ENCODING_DDL'; }
 return $sql;
};
add_filter( 'query', $fault171 ); $suppressed171 = $wpdb->suppress_errors( true ); $failed171 = false;
try { S171::install(); } catch ( Throwable $error171 ) { $failed171 = true; }
finally { remove_filter( 'query', $fault171 ); $wpdb->suppress_errors( $suppressed171 ); }
u171_eq( $failed171, true, 'interrupted family migration fails explicitly' );
u171_eq( $wpdb->get_var( 'SELECT @@SESSION.sql_mode' ), $mode171, 'failed ALTER restores original session mode' );
u171_eq( u171_rows(), $retry_rows171, 'failed/partial ALTER does not reset any durable state' );
u171_install(); u171_eq( u171_rows(), $retry_rows171, 'retry converges without row rewrites' ); u171_shape();

// Migration DDL must not implicitly commit caller-owned work.
u171_legacy(); $transaction_rows171 = u171_rows();
$wpdb->query( 'START TRANSACTION' ); $wpdb->query( 'SAVEPOINT u171_caller' );
$failed171 = false;
try { WriteLeash\Durable_Charset::migrate( $wpdb, S171::jobs_table( $wpdb ) ); } catch ( Throwable $error171 ) { $failed171 = true; }
u171_eq( $failed171, true, 'encoding migration refuses an ambient transaction' );
u171_eq( false !== $wpdb->query( 'ROLLBACK TO SAVEPOINT u171_caller' ), true, 'caller savepoint survives: migration did not commit caller work' );
$wpdb->query( 'ROLLBACK' );
u171_eq( u171_rows(), $transaction_rows171, 'ambient refusal does not change durable records' );
u171_install(); u171_shape();

echo '#171 Unicode/fresh/upgraded/default-or-Redis durable regression: PASS (' . $u171_assertions . ' assertions; cache=' . $cache171 . "; DB=" . $wpdb->get_var( 'SELECT VERSION()' ) . ")\n";
