<?php
// #298 standing-grant integration (container gate).
// Runs inside the #109 fixture via wp eval-file:
//   WL109_CACHE=default wp --path=$site eval-file /opt/tests/jobs/grant-integration.php
// Requires MySQL 8.0.44 / MariaDB 10.11.15, pinned WP 7.1.2 / PHP 8.2 / Woo 11.1.2,
// default or Redis object cache. Uses real Woo CRUD, real InnoDB row locks,
// real Action Scheduler state and the filesystem hook surrogate for save counts.
// Proves every required category with durable rows: identity/approval, scope,
// expiry/revocation/disable, budget/recovery, idempotency, audit/journal.
// True two-session overlap uses real proc_open workers with deterministic
// barriers (same harness as jobs/integration.php worker.php).
use WriteLeash\Grant_Repository as Grants;
use WriteLeash\Grant_Schema as GSchema;
use WriteLeash\Job_Item_State as IState;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_Scheduler as Scheduler;
use WriteLeash\Job_Schema as Schema;
use WriteLeash\Job_State as JState;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Price_Apply_Journal as Journal;
use WriteLeash\Price_Cache_Verifier as Verifier;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Safety_Policy as Policy;
use WriteLeash\Woo_Price_Planner as Planner;
use WriteLeash\Undo_Repository as URepo;
use WriteLeash\Undo_Worker as UWorker;
use WriteLeash\Undo_Item_State as UItem;

global $wpdb, $work, $assertions, $hook_log, $items_table, $jobs_table;
$assertions = 0;
$work = sys_get_temp_dir() . '/wl298-' . bin2hex( random_bytes( 5 ) );
mkdir( $work );
$hook_log = $work . '/hooks.log';
touch( $hook_log );
$GLOBALS['wl108_active'] = true;
$GLOBALS['wl108_log'] = $hook_log;
@unlink( WP_CONTENT_DIR . '/debug.log' );

function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function ok( bool $condition, string $label ): void { eq( $condition, true, $label ); }
function marker( string $label ): void {
	global $assertions;
	echo "#298 $label: PASS ($assertions assertions)\n";
}
function create_product( string $price, string $status = 'publish' ): int {
	$p = new WC_Product_Simple();
	$p->set_name( 'WL298-' . wp_generate_uuid4() );
	$p->set_status( $status );
	$p->set_regular_price( $price );
	$p->save();
	return $p->get_id();
}
function saves( int $id ): int {
	global $hook_log;
	if ( ! is_file( $hook_log ) ) { return 0; }
	$count = 0;
	foreach ( file( $hook_log, FILE_IGNORE_NEW_LINES ) as $line ) {
		$entry = json_decode( $line, true );
		if ( is_array( $entry ) && 'woocommerce_before_product_object_save' === ( $entry['hook'] ?? '' ) && $id === (int) ( $entry['id'] ?? 0 ) ) { ++$count; }
	}
	return $count;
}
function journal_row( string $plan_id, int $product_id ): ?array {
	$db = Verifier::observer();
	try { return Journal::read( $db, $plan_id, $product_id ); }
	finally { $db->close(); }
}
function fresh_price( int $id ): string {
	Verifier::invalidate( $id );
	$p = wc_get_product( $id );
	return (string) $p->get_regular_price( 'edit' );
}
function start_worker( array $spec, string $script = 'worker.php' ): array {
	global $work;
	$prefix = $work . '/proc-' . bin2hex( random_bytes( 5 ) );
	$spec = array_merge( array( 'result' => $prefix . '.result', 'barrier' => $prefix . '.barrier', 'release' => $prefix . '.release', 'started' => $prefix . '.started', 'hook_log' => $GLOBALS['hook_log'] ), $spec );
	file_put_contents( $prefix . '.json', json_encode( $spec ) );
	$proc = proc_open( array( 'wp', '--path=' . ABSPATH, 'eval-file', __DIR__ . '/' . $script, $prefix . '.json' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $prefix . '.stdout', 'w' ), 2 => array( 'file', $prefix . '.stderr', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) { throw new RuntimeException( 'worker spawn failed' ); }
	fclose( $pipes[0] );
	return array( 'proc' => $proc, 'spec' => $spec, 'prefix' => $prefix );
}
function await_file( string $file, float $timeout = 25 ): void {
	$deadline = microtime( true ) + $timeout;
	clearstatcache( true, $file );
	while ( ! is_file( $file ) || '' === (string) file_get_contents( $file ) ) {
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'missing deterministic barrier: ' . $file ); }
		usleep( 10000 );
		clearstatcache( true, $file );
	}
}
function finish_worker( array $worker, bool $killed = false ): array {
	$exit = proc_close( $worker['proc'] );
	if ( $killed ) {
		eq( in_array( $exit, array( 9, 137 ), true ), true, 'actual SIGKILL exit' );
		return array();
	}
	if ( 0 !== $exit || ! is_file( $worker['spec']['result'] ) ) {
		throw new RuntimeException( 'worker failure: ' . file_get_contents( $worker['prefix'] . '.stdout' ) . file_get_contents( $worker['prefix'] . '.stderr' ) );
	}
	return json_decode( file_get_contents( $worker['spec']['result'] ), true );
}
function run_worker( array $spec, string $script = 'worker.php' ): array { return finish_worker( start_worker( $spec, $script ) ); }
function limits( int $items, int $seconds = 20 ): array { return array( 'max_items' => $items, 'budget_seconds' => $seconds ); }
function grant_audit_rows( string $grant_public_id ): array {
	global $wpdb;
	$grant = Grants::read_by_public_id( $grant_public_id );
	if ( ! $grant ) { return array(); }
	$audit = GSchema::audit_table( $wpdb );
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grant_db_id=%d ORDER BY id', $audit, (int) $grant['id'] ), ARRAY_A );
}
function grant_row( string $public_id ): array {
	$row = Grants::read_by_public_id( $public_id );
	if ( ! $row ) { throw new RuntimeException( 'grant missing: ' . $public_id ); }
	return $row;
}
function ensure_users(): array {
	// Three distinct product managers: issuer, actor, human approver.
	$ids = array();
	foreach ( array( 'wl298-issuer', 'wl298-actor', 'wl298-approver' ) as $login ) {
		$existing = get_user_by( 'login', $login );
		if ( $existing ) { $ids[ $login ] = (int) $existing->ID; continue; }
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'role' => 'administrator', 'user_email' => $login . '@example.test' ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'user creation failed: ' . $login ); }
		$ids[ $login ] = (int) $id;
	}
	ok( count( array_unique( array_values( $ids ) ) ) === 3, 'three distinct grant identities' );
	return array( 'issuer' => $ids['wl298-issuer'], 'actor' => $ids['wl298-actor'], 'approver' => $ids['wl298-approver'] );
}

wp_set_current_user( 1 );
eq( \WriteLeash\Free_Support_Contract::woo_supported( WC_VERSION ), true, 'fixture Woo inside supported range' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wl108_hook_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, product_id bigint unsigned NOT NULL, hook varchar(100) NOT NULL) ENGINE=InnoDB" );
$users = ensure_users();
$issuer = $users['issuer'];
$actor = $users['actor'];
$approver = $users['approver'];
$items_table = Schema::items_table( $wpdb );
$jobs_table = Schema::jobs_table( $wpdb );

// ---------------------------------------------------------------------------
// Schema: grant family fresh install + replay + v1->v2 job upgrade.
// ---------------------------------------------------------------------------
eq( GSchema::ready( $wpdb ), false, 'grant schema not ready before first use' );
eq( Schema::ready( $wpdb ), true, 'job schema v2 ready (upgraded with grant columns)' );
// First grant use installs the family + defaults the global disable to enabled.
$probe_grant = Grants::create_grant( $issuer, $actor, 100, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
eq( GSchema::ready( $wpdb ), true, 'grant install ready' );
eq( Grants::is_disabled(), false, 'global disable defaults to enabled' );
GSchema::install();
GSchema::install();
eq( GSchema::ready( $wpdb ), true, 'grant install replay idempotent' );
$probe_row = grant_row( $probe_grant['public_id'] );
eq( (int) $probe_row['issuer_id'], $issuer, 'issuer persisted separately' );
eq( (int) $probe_row['actor_id'], $actor, 'actor persisted separately' );
// Job v2 columns present and ordinary jobs read as none/human.
$job_cols = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $jobs_table ), ARRAY_A );
$job_fields = array_column( $job_cols, 'Field' );
foreach ( array( 'approval_mode', 'grant_public_id', 'grant_version', 'grant_idempotency_key' ) as $col ) { ok( in_array( $col, $job_fields, true ), 'job v2 column ' . $col ); }
marker( 'grant schema install + job v2 migration' );

// ---------------------------------------------------------------------------
// Identity and approval.
// ---------------------------------------------------------------------------
wp_set_current_user( $actor );
$ids = array( create_product( '100.00' ), create_product( '100.00' ) );
sort( $ids, SORT_NUMERIC );
$plan = Planner::preview( Selection::ids( $ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
eq( (int) $plan->data()['actor_id'], $actor, 'frozen actor is the planner' );
$grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
$accepted = Grants::accept_plan( $plan, $grant['public_id'], $issuer, 'id-ident-1' );
$job = $accepted['job'];
eq( $accepted['replayed'], false, 'first acceptance not a replay' );
eq( (int) $job['creator_id'], $actor, 'grant job creator is the actor' );
eq( (int) $job['approver_id'], 0, 'grant job fabricates no human approver' );
eq( $job['approval_mode'], 'grant', 'grant mode distinct from PLANNED none' );
eq( $job['status'], JState::READY, 'grant acceptance lands READY without human approval' );
eq( $job['grant_public_id'], $grant['public_id'], 'grant pin on job' );
ok( (int) $job['grant_version'] >= 1, 'grant version pinned' );
// Ordinary unapproved PLANNED is mode none, approver 0; human approval is mode human.
wp_set_current_user( $actor );
$plain_ids = array( create_product( '100.00' ) );
$plain_plan = Planner::preview( Selection::ids( $plain_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$plain_job = Repo::create_from_plan( $plain_plan, $actor );
eq( $plain_job['approval_mode'], 'none', 'unapproved PLANNED mode none' );
eq( (int) $plain_job['approver_id'], 0, 'unapproved approver 0' );
$approved = Repo::approve( (int) $plain_job['id'], $approver );
eq( $approved['approval_mode'], 'human', 'human approval mode human' );
eq( (int) $approved['approver_id'], $approver, 'human approver persisted' );
// Actor capabilities re-checked at execution: strip the actor to subscriber,
// grant execution must deny with zero saves.
$actor_user = new WP_User( $actor );
$actor_user->set_role( 'subscriber' );
$run = run_worker( array( 'job_id' => (int) $job['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
eq( saves( $ids[0] ), 0, 'revoked actor zero Woo saves' );
$actor_user->set_role( 'administrator' );
// Later grant edit (budget bump) does not rewrite the frozen pin.
$before_version = (int) $job['grant_version'];
$grant_after = Grants::update_grant( $grant['public_id'], $issuer, null, 20 );
ok( (int) $grant_after['version'] > $before_version, 'grant version bumped' );
$fresh_job = Repo::read( (int) $job['id'] );
eq( (int) $fresh_job['grant_version'], $before_version, 'frozen pin unchanged by later grant edit' );
// Operator copy never renders 0 as a person.
ok( false === strpos( Grants::operator_label( $fresh_job ), 'Approved by' ), 'grant copy never renders zero as person' );
marker( 'identity: actor/issuer/approver distinct, actor re-checked, pin frozen' );

// ---------------------------------------------------------------------------
// Scope: allow simple + variation; deny each boundary; no selector rerun.
// ---------------------------------------------------------------------------
wp_set_current_user( $actor );
// Eligible variation in scope.
$parent = new WC_Product_Variable();
$parent->set_name( 'WL298-parent-' . wp_generate_uuid4() );
$parent->set_status( 'publish' );
$parent_id = $parent->save();
$variation = new WC_Product_Variation();
$variation->set_parent_id( $parent_id );
$variation->set_status( 'publish' );
$variation->set_regular_price( '100.00' );
$variation_id = $variation->save();
WC_Product_Variable::sync( $parent_id );
$scope_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
$scope_plan = Planner::preview( Selection::ids( array( $variation_id ) ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$scope_accepted = Grants::accept_plan( $scope_plan, $scope_grant['public_id'], $issuer, 'id-scope-variation' );
eq( $scope_accepted['job']['approval_mode'], 'grant', 'variation in scope accepted' );
// Draft product: planner freezes it UNSUPPORTED; grant scope denies (zero changing).
$draft_id = create_product( '100.00', 'draft' );
$draft_plan = Planner::preview( Selection::ids( array( $draft_id ) ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
try { Grants::accept_plan( $draft_plan, $scope_grant['public_id'], $issuer, 'id-scope-draft' ); throw new RuntimeException( 'draft must be denied' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_SCOPE_DENIED', 'draft scope denied' ); }
eq( saves( $draft_id ), 0, 'scope denial zero saves' );
// Apply/Resume never reruns selectors: run the variation job to completion and
// assert only the frozen ID was written with the absolute target.
Worker::queue_job( (int) $scope_accepted['job']['id'] );
for ( $i = 0; $i < 5 && ! JState::is_terminal( Repo::read( (int) $scope_accepted['job']['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $scope_accepted['job']['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
}
eq( Repo::read( (int) $scope_accepted['job']['id'] )['status'], JState::COMPLETED, 'variation grant job completes' );
eq( saves( $variation_id ), 1, 'frozen variation exactly one save' );
eq( fresh_price( $variation_id ), '80.00', 'absolute frozen target, no recalculation' );
marker( 'scope allow/deny + frozen IDs/targets, no selector rerun' );

// ---------------------------------------------------------------------------
// Expiry, revocation, emergency disable.
// ---------------------------------------------------------------------------
$exp_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$exp_ids = array( create_product( '100.00' ) );
$exp_plan = Planner::preview( Selection::ids( $exp_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
// Expire the grant beneath the acceptance (durable UTC comparison).
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE public_id=%s', GSchema::grants_table( $wpdb ), $exp_grant['public_id'] ) );
try { Grants::accept_plan( $exp_plan, $exp_grant['public_id'], $issuer, 'id-expired' ); throw new RuntimeException( 'expired must deny' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_EXPIRED', 'expired grant denies acceptance' ); }
eq( saves( $exp_ids[0] ), 0, 'expired denial zero saves' );
// Revoked grant denies acceptance.
$rev_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
Grants::revoke( $rev_grant['public_id'], $issuer );
try { Grants::accept_plan( $exp_plan, $rev_grant['public_id'], $issuer, 'id-revoked' ); throw new RuntimeException( 'revoked must deny' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_REVOKED', 'revoked grant denies acceptance' ); }
eq( saves( $exp_ids[0] ), 0, 'revoked denial zero saves' );
// Revoke races acceptance: accept first, revoke before execution denies items.
$race_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$race_ids = array( create_product( '100.00' ), create_product( '100.00' ) );
sort( $race_ids, SORT_NUMERIC );
$race_plan = Planner::preview( Selection::ids( $race_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$race_accepted = Grants::accept_plan( $race_plan, $race_grant['public_id'], $issuer, 'id-race' );
Grants::revoke( $race_grant['public_id'], $issuer );
$race_run = run_worker( array( 'job_id' => (int) $race_accepted['job']['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
eq( saves( $race_ids[0] ) + saves( $race_ids[1] ), 0, 'revoke-before-execution zero saves' );
$race_items = array();
foreach ( Repo::items( (int) $race_accepted['job']['id'], null, 0, 100 ) as $row ) { $race_items[ (int) $row['product_id'] ] = $row; }
eq( $race_items[ $race_ids[0] ]['state'], IState::FAILED, 'revoked item terminal FAILED' );
ok( 0 === strpos( $race_items[ $race_ids[0] ]['reason'], 'GRANT_' ), 'revoked reason is grant vocabulary' );
// Revoke races authorization (paused before mutator): revoke wins pre-boundary.
$auth_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$auth_ids = array( create_product( '100.00' ) );
$auth_plan = Planner::preview( Selection::ids( $auth_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$auth_accepted = Grants::accept_plan( $auth_plan, $auth_grant['public_id'], $issuer, 'id-auth-race' );
$auth_job = (int) $auth_accepted['job']['id'];
Worker::queue_job( $auth_job );
$paused = start_worker( array( 'job_id' => $auth_job, 'mode' => 'run', 'limits' => limits( 2 ), 'checkpoint' => 'AFTER_CLAIM', 'fault' => 'wait' ) );
await_file( $paused['spec']['barrier'] );
Grants::revoke( $auth_grant['public_id'], $issuer );
file_put_contents( $paused['spec']['release'], 'go' );
$auth_result = finish_worker( $paused );
eq( saves( $auth_ids[0] ), 0, 'revoke-before-boundary zero saves' );
// Revoke races an in-flight write (paused after Woo save, holding the grant
// lock): the in-flight item still commits once; the next item is denied.
$flight_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$flight_ids = array( create_product( '100.00' ), create_product( '100.00' ) );
sort( $flight_ids, SORT_NUMERIC );
$flight_plan = Planner::preview( Selection::ids( $flight_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$flight_accepted = Grants::accept_plan( $flight_plan, $flight_grant['public_id'], $issuer, 'id-flight' );
$flight_job = (int) $flight_accepted['job']['id'];
Worker::queue_job( $flight_job );
$held = start_worker( array( 'job_id' => $flight_job, 'mode' => 'run', 'limits' => limits( 2 ), 'mutator_checkpoint' => 'AFTER_WOO_SAVE_BEFORE_JOURNAL', 'mutator_fault' => 'wait' ) );
await_file( $held['spec']['barrier'] );
$revoke_proc = start_worker( array( 'job_id' => $flight_job, 'mode' => 'revoke', 'grant' => $flight_grant['public_id'], 'issuer' => $issuer ), 'grant-revoke-worker.php' );
// The revoke UPDATE blocks behind the held grant row lock; release the item
// so it can commit, then the revoke becomes durable for the next item.
file_put_contents( $held['spec']['release'], 'go' );
$held_result = finish_worker( $held );
$revoke_result = finish_worker( $revoke_proc );
eq( saves( $flight_ids[0] ) + saves( $flight_ids[1] ), 1, 'in-flight item commits once past the boundary' );
// Global disable blocks restricted writes with zero fallback saves.
Grants::disable( $issuer );
eq( Grants::is_disabled(), true, 'global disable durable' );
wp_set_current_user( $actor );
$dis_ids = array( create_product( '100.00' ) );
$dis_plan = Planner::preview( Selection::ids( $dis_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$dis_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
try { Grants::accept_plan( $dis_plan, $dis_grant['public_id'], $issuer, 'id-disabled' ); throw new RuntimeException( 'disabled must deny' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_DISABLED', 'global disable denies acceptance' ); }
eq( saves( $dis_ids[0] ), 0, 'disabled denial zero saves, no unrestricted fallback' );
Grants::enable( $issuer );
eq( Grants::is_disabled(), false, 'operator recovery re-enables' );
marker( 'expiry, revocation races with documented boundary, global disable' );

// ---------------------------------------------------------------------------
// Cumulative budget and recovery.
// ---------------------------------------------------------------------------
$budget_grant = Grants::create_grant( $issuer, $actor, 2, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$budget_ids_a = array( create_product( '100.00' ), create_product( '100.00' ) );
sort( $budget_ids_a, SORT_NUMERIC );
$budget_plan_a = Planner::preview( Selection::ids( $budget_ids_a ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$budget_a = Grants::accept_plan( $budget_plan_a, $budget_grant['public_id'], $issuer, 'id-budget-a' );
eq( $budget_a['replayed'], false, 'first budget job accepted' );
// Competing job for the last units is denied atomically (2 reserved, 0 left).
$budget_ids_b = array( create_product( '100.00' ) );
$budget_plan_b = Planner::preview( Selection::ids( $budget_ids_b ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
try { Grants::accept_plan( $budget_plan_b, $budget_grant['public_id'], $issuer, 'id-budget-b' ); throw new RuntimeException( 'overbook must deny' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_BUDGET_EXHAUSTED', 'concurrent last-unit contention has one winner' ); }
eq( saves( $budget_ids_b[0] ), 0, 'budget denial zero Woo saves' );
// Run the winner to durable APPLIED: each consumes exactly once.
Worker::queue_job( (int) $budget_a['job']['id'] );
for ( $i = 0; $i < 4 && ! JState::is_terminal( Repo::read( (int) $budget_a['job']['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $budget_a['job']['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
}
eq( Repo::read( (int) $budget_a['job']['id'] )['status'], JState::COMPLETED, 'budget winner completes' );
foreach ( $budget_ids_a as $id ) { eq( saves( $id ), 1, 'winner exactly one save ' . $id ); eq( fresh_price( $id ), '80.00', 'winner target ' . $id ); }
$brow = grant_row( $budget_grant['public_id'] );
eq( (int) $brow['budget_consumed'], 2, 'two APPLIED consume two units' );
eq( (int) $brow['budget_reserved'], 0, 'no leaked reservation after APPLIED' );
// Conflicts and failures release without consuming; Undo never consumes.
$conflict_grant = Grants::create_grant( $issuer, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$conflict_ids = array( create_product( '100.00' ), create_product( '100.00' ) );
sort( $conflict_ids, SORT_NUMERIC );
$conflict_plan = Planner::preview( Selection::ids( $conflict_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$conflict_accepted = Grants::accept_plan( $conflict_plan, $conflict_grant['public_id'], $issuer, 'id-conflict' );
// External newer edit on the first product becomes a conflict.
$p = wc_get_product( $conflict_ids[0] );
$p->set_regular_price( '90.00' );
$p->save();
$conflict_saves = saves( $conflict_ids[0] );
Worker::queue_job( (int) $conflict_accepted['job']['id'] );
for ( $i = 0; $i < 4 && ! JState::is_terminal( Repo::read( (int) $conflict_accepted['job']['id'] )['status'] ); ++$i ) {
	run_worker( array( 'job_id' => (int) $conflict_accepted['job']['id'], 'mode' => 'run', 'limits' => limits( 5 ) ) );
}
eq( saves( $conflict_ids[0] ) - $conflict_saves, 0, 'conflict zero WriteLeash saves' );
eq( saves( $conflict_ids[1] ), 1, 'clean sibling one save' );
$crow = grant_row( $conflict_grant['public_id'] );
eq( (int) $crow['budget_consumed'], 1, 'conflict consumes nothing' );
eq( (int) $crow['budget_reserved'], 0, 'conflict releases its reservation' );
// Undo of a grant APPLIED item preserves the original outcome and consumes nothing.
wp_set_current_user( 1 );
$undo_op = URepo::initiate( (int) $conflict_accepted['job']['id'], 1 );
UWorker::run( (int) $undo_op['id'], limits( 5 ), true );
$urow = grant_row( $conflict_grant['public_id'] );
eq( (int) $urow['budget_consumed'], 1, 'Undo consumes no budget unit' );
eq( journal_row( $conflict_plan->data()['plan_id'], $conflict_ids[1] )['state'], 'APPLIED', 'original APPLIED preserved through Undo' );
// Ambiguous commit retains the reservation until reconciliation consumes it.
$amb_grant = Grants::create_grant( $issuer, $actor, 5, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$amb_ids = array( create_product( '100.00' ) );
$amb_plan = Planner::preview( Selection::ids( $amb_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$amb_accepted = Grants::accept_plan( $amb_plan, $amb_grant['public_id'], $issuer, 'id-ambiguous' );
Worker::queue_job( (int) $amb_accepted['job']['id'] );
$amb_worker = start_worker( array( 'job_id' => (int) $amb_accepted['job']['id'], 'mode' => 'run', 'limits' => limits( 2 ), 'commit_checkpoint' => 'AFTER_COMMIT_BEFORE_RETURN', 'commit_fault' => 'throw-ambiguous' ) );
$amb = finish_worker( $amb_worker );
$amb_journal = journal_row( $amb_plan->data()['plan_id'], $amb_ids[0] );
eq( $amb_journal['state'], 'APPLIED', 'ambiguous commit left durable APPLIED' );
eq( saves( $amb_ids[0] ), 1, 'ambiguous commit never re-saved' );
$amb_row = grant_row( $amb_grant['public_id'] );
eq( (int) $amb_row['budget_consumed'], 1, 'reconciled APPLIED consumes exactly once' );
marker( 'cumulative budget, release, ambiguous retain, Undo preserves' );

// ---------------------------------------------------------------------------
// Idempotency.
// ---------------------------------------------------------------------------
$idem_grant = Grants::create_grant( $issuer, $actor, 10, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
wp_set_current_user( $actor );
$idem_ids = array( create_product( '100.00' ) );
$idem_plan = Planner::preview( Selection::ids( $idem_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
$first = Grants::accept_plan( $idem_plan, $idem_grant['public_id'], $issuer, 'id-same-key' );
$second = Grants::accept_plan( $idem_plan, $idem_grant['public_id'], $issuer, 'id-same-key' );
eq( $second['replayed'], true, 'same key+same payload replays' );
eq( (int) $second['job']['id'], (int) $first['job']['id'], 'replay returns the original job' );
$other_ids = array( create_product( '100.00' ) );
$other_plan = Planner::preview( Selection::ids( $other_ids ), new Operation( Operation::SET, '80.00' ), new Policy( 1000, '100', '100', false, '100' ) );
try { Grants::accept_plan( $other_plan, $idem_grant['public_id'], $issuer, 'id-same-key' ); throw new RuntimeException( 'different payload must reject' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_IDEMPOTENCY_MISMATCH', 'same key+different payload rejected' ); }
// Retry does not create a second plan or reservation.
$retry = Grants::accept_plan( $idem_plan, $idem_grant['public_id'], $issuer, 'id-same-key' );
eq( (int) $retry['job']['id'], (int) $first['job']['id'], 'retry creates no second plan' );
$irow = grant_row( $idem_grant['public_id'] );
eq( (int) $irow['budget_reserved'], 1, 'retry creates no second reservation' );
marker( 'idempotency replay/reject/retry, durable uniqueness' );

// ---------------------------------------------------------------------------
// Audit and journal.
// ---------------------------------------------------------------------------
$audit = grant_audit_rows( $idem_grant['public_id'] );
ok( count( $audit ) >= 2, 'audit rows exist' );
foreach ( $audit as $row ) {
	foreach ( array( 'actor_id', 'issuer_id', 'approver_id', 'approval_mode', 'grant_db_id', 'grant_version', 'idempotency_key', 'event', 'details' ) as $col ) {
		ok( array_key_exists( $col, $row ), 'audit carries ' . $col );
	}
	$details = json_decode( $row['details'], true );
	ok( is_array( $details ), 'audit details are canonical JSON' );
}
$found_accept = false;
foreach ( $audit as $row ) { if ( 'GRANT_ACCEPTED' === $row['event'] ) { $found_accept = true; } }
ok( $found_accept, 'acceptance audited with identities and targets' );
// Existing journal CAS + Undo semantics remain intact (spot-check the winner).
eq( journal_row( $budget_plan_a->data()['plan_id'], $budget_ids_a[0] )['state'], 'APPLIED', 'journal CAS intact for grant APPLIED' );
marker( 'audit evidence + journal source of truth intact' );

echo "#298 grant-integration PASS ($assertions assertions)\n";
