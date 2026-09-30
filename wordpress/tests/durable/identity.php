<?php
use WriteLeash\Price_Apply_Journal as J;
use WriteLeash\Price_Cache_Verifier as V;
use WriteLeash\Woo_Price_Planner as Planner;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Safety_Policy as Policy;

/** Plan-instance regressions only. Uses the real #107 factory and real #108 workers. */
function identity_fixture( array $source, WriteLeash\Change_Plan $plan ): array {
	global $work;
	$prefix = $work . '/identity-' . $plan->data()['plan_id'];
	file_put_contents( $prefix . '.plan', serialize( $plan ) );
	return array( 'id' => $source['id'], 'plan' => $prefix . '.plan', 'object' => $plan, 'log' => $prefix . '.hooks' );
}
function identity_plan( array $source, string $plan_id, string $percent = '20' ): WriteLeash\Change_Plan {
	$old = $source['object']->data();
	V::invalidate( $source['id'] );
	return WriteLeash\Change_Plan::create( $plan_id, $old['created_at'], $old['actor_id'], WriteLeash\Price_Store_Context::current(), Selection::ids( array( $source['id'] ) ), new Operation( Operation::DECREASE_PERCENT, $percent ), new Policy( 2, '100', '100', true, '100' ), array( WriteLeash\Product_Price_Snapshot::read( $source['id'], wc_get_product( $source['id'] ) ) ) );
}
function identity_seed_mismatch( WriteLeash\Change_Plan $plan ): void {
	try { J::seed( $plan ); }
	catch ( WriteLeash\Price_Apply_Error $error ) { eq( $error->getMessage(), 'JOURNAL_MISMATCH', 'seed fails closed' ); return; }
	throw new RuntimeException( 'Seed accepted conflicting plan material' );
}

// Isolate upgrade records from deliberate journal corruption in earlier cache variants.
$table = J::table( $wpdb ); $backup = $table . '_identity_fixture_backup';
$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $table, $backup ) );
J::install();
// Exact v1 shape, with real APPLIED and PENDING records. No product price SQL.
$m1 = fixture(); $m2 = fixture();
eq( run_item( $m1 )['code'], 'APPLIED', 'migration applied fixture' );
$before = J::read( $wpdb, $m1['object']->data()['plan_id'], $m1['id'] );
$legacy_evidence = json_decode( $before['evidence'], true );
unset( $legacy_evidence['plan_id'], $legacy_evidence['plan_schema_version'], $legacy_evidence['plan_hash_version'] );
$table = J::table( $wpdb );
$wpdb->update( $table, array( 'evidence' => WriteLeash\Plan_Hasher::canonical_json( $legacy_evidence ) ), array( 'id' => $before['id'] ) );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET schema_version=1', $table ) );
$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX plan_instance_product, DROP COLUMN plan_id, DROP COLUMN plan_schema_version, DROP COLUMN plan_hash_version, ADD UNIQUE KEY plan_product (plan_hash,product_id)', $table ) );
update_option( 'writeleash_price_journal_schema', 1, false );
J::install(); J::install(); J::assert_schema( $wpdb );
$upgraded = truth( $m1, '80', 'APPLIED' ); truth( $m2, '100', 'PENDING' );
eq( (int) $upgraded['schema_version'], J::SCHEMA_VERSION, 'v2 journal schema' );
eq( $upgraded['plan_id'], $m1['object']->data()['plan_id'], 'v1 identity recovered from JSON' );
eq( (int) $upgraded['plan_schema_version'], WriteLeash\Change_Plan::SCHEMA_VERSION, 'existing #107 schema constant' );
eq( $upgraded['plan_hash_version'], WriteLeash\Change_Plan::HASH_VERSION, 'existing #107 hash constant' );
foreach ( array( 'id', 'plan_hash', 'plan_json', 'attempt_id', 'state', 'applied_at', 'created_at', 'updated_at' ) as $field ) { eq( $upgraded[$field], $before[$field], 'migration preserves ' . $field ); }
eq( json_decode( $upgraded['evidence'], true )['plan_id'], $upgraded['plan_id'], 'migration binds evidence identity' );
eq( run_item( $m1 )['code'], 'ALREADY_APPLIED', 'migrated APPLIED recovery' );
eq( hook_count( $m1 ), 1, 'migration no Woo save replay' );
$columns = $wpdb->get_results( $wpdb->prepare( "SELECT COLUMN_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME IN ('plan_id','plan_schema_version','plan_hash_version')", $table ), ARRAY_A );
eq( count( $columns ), 3, 'three plan binding columns' );
eq( array_unique( array_column( $columns, 'IS_NULLABLE' ) ), array( 'NO' ), 'binding columns nonnullable' );
marker( 'journal schema 1→2: APPLIED/PENDING/evidence preserved; install replay idempotent' );
$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $backup, $table ) );
J::assert_schema( $wpdb );

// Same product, different plan instance, identical material after an external reset.
$p1 = fixture();
J::seed( $p1['object'] );
$seeded = J::read( $wpdb, $p1['object']->data()['plan_id'], $p1['id'] );
J::seed( $p1['object'] );
eq( J::read( $wpdb, $p1['object']->data()['plan_id'], $p1['id'] ), $seeded, 'exact seed idempotence' );
eq( run_item( $p1 )['code'], 'APPLIED', 'P1 independent apply' );
truth( $p1, '80', 'APPLIED' );
eq( run_item( $p1 )['code'], 'ALREADY_APPLIED', 'same plan retry' );
eq( hook_count( $p1 ), 1, 'same-plan save replay=0' );
marker( 'same-plan retry: same plan_id APPLIED / Woo save replay=0' );
edit( $p1, '100.00' );
$p2_plan = Planner::preview( Selection::ids( array( $p1['id'] ) ), new Operation( Operation::DECREASE_PERCENT, '20' ), new Policy( 2, '100', '100', true, '100' ) );
eq( $p1['object']->data()['plan_id'] !== $p2_plan->data()['plan_id'], true, 'different generated plan IDs' );
eq( $p1['object']->hash(), $p2_plan->hash(), 'identical material hash by design' );
$p2 = identity_fixture( $p1, $p2_plan ); J::seed( $p2_plan );
eq( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_id=%d AND plan_hash=%s', $table, $p1['id'], $p2_plan->hash() ) ), 2, 'distinct rows with same fingerprint' );
eq( run_item( $p2 )['code'], 'APPLIED', 'P2 gets independent lifecycle' );
truth( $p2, '80', 'APPLIED' ); truth( $p1, '80', 'APPLIED' );
eq( hook_count( $p2 ), 1, 'P2 one save' );
eq( hook_count( $p1 ), 1, 'P1 remains one save' );
V::observe( $p1['object'], $p1['id'] ); V::observe( $p2['object'], $p2['id'] );
echo '#108 plan identity evidence: ' . json_encode( array( 'P1' => $p1['object']->data()['plan_id'], 'P2' => $p2_plan->data()['plan_id'], 'hash' => $p2_plan->hash(), 'product' => $p1['id'], 'rows' => 2, 'P1' . '_result' => 'APPLIED', 'P2_result' => 'APPLIED', 'P1_saves' => hook_count( $p1 ), 'P2_saves' => hook_count( $p2 ) ) ) . "\n";
marker( 'plan identity: different plan_id / same hash / same product / distinct journal rows' );

// Same ID, different genuine factory material is rejected at seed and apply.
$f = fixture();
$substitute = identity_plan( $f, $f['object']->data()['plan_id'], '30' );
eq( $substitute->hash() !== $f['object']->hash(), true, 'different material hash' );
eq( $substitute->item( $f['id'] )->data()['planned_regular_price'], '70.00', 'factory target differs' );
$original = J::read( $wpdb, $f['object']->data()['plan_id'], $f['id'] );
identity_seed_mismatch( $substitute );
$bad = identity_fixture( $f, $substitute );
$result = run_item( $bad );
eq( $result['reason'], 'JOURNAL_MISMATCH', 'same ID material substitution denied' );
eq( hook_count( $bad ), 0, 'same ID substitution zero Woo saves' );
eq( J::read( $wpdb, $f['object']->data()['plan_id'], $f['id'] ), $original, 'refusal cannot rewrite legitimate journal row' );
truth( $f, '100', 'PENDING' );
marker( 'plan identity mismatch: same plan_id / changed material / JOURNAL_MISMATCH / zero Woo save' );

// Every persisted immutable binding field is verified by seed and the mutator.
foreach ( array( 'plan_hash' => str_repeat( '0', 64 ), 'plan_schema_version' => 99, 'plan_hash_version' => 'unknown', 'plan_json' => '{}', 'expected_price' => '99', 'target_price' => '70.00' ) as $field => $corrupt ) {
	$wpdb->update( $table, array( $field => $corrupt ), array( 'id' => $original['id'] ) );
	identity_seed_mismatch( $f['object'] );
	eq( run_item( $f )['reason'], 'JOURNAL_MISMATCH', 'tampered binding refused: ' . $field );
	eq( hook_count( $f ), 0, 'tampered binding zero mutation' );
	$wpdb->update( $table, array( $field => $original[$field] ), array( 'id' => $original['id'] ) );
}
// Independent observation may not certify another identical-material plan's evidence.
$row2 = J::read( $wpdb, $p2_plan->data()['plan_id'], $p2['id'] );
$wrong_evidence = json_decode( $row2['evidence'], true ); $wrong_evidence['plan_id'] = $p1['object']->data()['plan_id'];
$wpdb->update( $table, array( 'evidence' => WriteLeash\Plan_Hasher::canonical_json( $wrong_evidence ) ), array( 'id' => $row2['id'] ) );
eq( run_item( $p2 )['reason'], 'JOURNAL_MISMATCH', 'observer rejects other plan identity despite identical hash' );
eq( hook_count( $p2 ), 1, 'evidence mismatch no replay' );
$wpdb->update( $table, array( 'evidence' => $row2['evidence'] ), array( 'id' => $row2['id'] ) );
marker( 'plan identity binding: all immutable fields and evidence plan_id verified' );

// IDs are opaque and case-sensitive, consistent with #107's ASCII ID grammar.
$f = fixture();
$upper = identity_plan( $f, 'IdentityCase' ); $lower = identity_plan( $f, 'identitycase' );
J::seed( $upper ); J::seed( $lower );
eq( J::read( $wpdb, 'IdentityCase', $f['id'] )['plan_id'], 'IdentityCase', 'uppercase identity' );
eq( J::read( $wpdb, 'identitycase', $f['id'] )['plan_id'], 'identitycase', 'lowercase identity' );
marker( 'plan identity exactness: case-distinct IDs remain distinct' );

// Different identities must acquire separate journal row authority, not duplicate status.
$f1 = fixture(); $f2plan = identity_plan( $f1, wp_generate_uuid4() );
eq( $f1['object']->hash(), $f2plan->hash(), 'concurrent different identities same fingerprint' );
$f2 = identity_fixture( $f1, $f2plan ); J::seed( $f2plan );
$w1 = start_worker( $f1, array( 'point' => 'ITEM_LOCKED', 'mode' => 'wait' ) ); await_file( $w1['job']['barrier'] );
$w2 = start_worker( $f2, array( 'point' => 'ITEM_LOCKED', 'mode' => 'wait' ) ); await_file( $w2['job']['barrier'] );
file_put_contents( $w1['job']['release'], 'go' ); eq( finish( $w1 )['code'], 'APPLIED', 'first plan applied' );
file_put_contents( $w2['job']['release'], 'go' ); eq( finish( $w2 )['code'], 'CONFLICT', 'other identity observes changed product, not ALREADY_APPLIED' );
eq( hook_count( $f1 ), 1, 'one first-plan save' ); eq( hook_count( $f2 ), 0, 'conflicting independent plan no save' );
truth( $f1, '80', 'APPLIED' ); truth( $f2, '80', 'CONFLICT' );
marker( 'different-plan concurrency: separate row locks / same hash / external-price CONFLICT / not duplicate identity' );
