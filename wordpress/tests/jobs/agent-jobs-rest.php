<?php
// #299 restricted grant submission. Container gate, same fixture as #109/#298.
require __DIR__ . '/../durable/hook-torture.php';
use WriteLeash\Agent_Jobs_Rest as AgentRest;
use WriteLeash\Grant_Repository as Grants;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_State as JState;
use WriteLeash\Job_Worker as Worker;
use WriteLeash\Price_Apply_Journal as Journal;
use WriteLeash\Price_Cache_Verifier as Verifier;

global $wpdb, $assertions, $hook_log;
$assertions = 0;
$hook_log = sys_get_temp_dir() . '/wl299-' . bin2hex( random_bytes( 4 ) ) . '.log';
touch( $hook_log );
$GLOBALS['wl108_active'] = true;
$GLOBALS['wl108_log'] = $hook_log;
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wl108_hook_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, product_id bigint unsigned NOT NULL, hook varchar(100) NOT NULL) ENGINE=InnoDB" );

function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function ok( bool $condition, string $label ): void { eq( $condition, true, $label ); }
function marker( string $label ): void {
	global $assertions;
	echo "#299 $label: PASS ($assertions assertions)\n";
}
function saves( int $id ): int {
	global $hook_log;
	$count = 0;
	foreach ( file( $hook_log, FILE_IGNORE_NEW_LINES ) as $line ) {
		$entry = json_decode( $line, true );
		if ( is_array( $entry ) && 'woocommerce_before_product_object_save' === ( $entry['hook'] ?? '' ) && $id === (int) ( $entry['id'] ?? 0 ) ) { ++$count; }
	}
	return $count;
}
function user_id( string $login, string $role ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) { return (int) $existing->ID; }
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'role' => $role, 'user_email' => $login . '@example.test' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( 'user failed: ' . $login ); }
	return (int) $id;
}
function product( string $price, string $status = 'publish' ): int {
	$p = new WC_Product_Simple();
	$p->set_name( 'WL299-' . wp_generate_uuid4() );
	$p->set_status( $status );
	$p->set_regular_price( $price );
	$p->save();
	return $p->get_id();
}
function payload( int $actor, int $version, array $ids, string $key, string $target = '80.00', string $operation = 'SET' ): array {
	return array( 'idempotency_key' => $key, 'actor_id' => $actor, 'grant_version' => $version, 'price_field' => 'regular_price', 'operation' => $operation, 'target' => $target, 'product_ids' => $ids );
}
function submit( int $user, string $grant, array $body ): \WP_REST_Response {
	wp_set_current_user( $user );
	$request = new \WP_REST_Request( 'POST', '/writeleash/v1/grants/' . $grant . '/jobs' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	return rest_do_request( $request );
}
function reason_of( \WP_REST_Response $response ): string {
	$data = $response->get_data();
	if ( ! is_array( $data ) ) { return ''; }
	return (string) ( $data['data']['reason'] ?? $data['reason'] ?? '' );
}
function leaked( \WP_REST_Response $response, string $secret ): bool {
	return false !== strpos( wp_json_encode( $response->get_data() ), $secret );
}

eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
eq( WC_VERSION, '11.1.2', 'pinned WooCommerce' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
$issuer = user_id( 'wl299-issuer', 'administrator' );
$actor = user_id( 'wl299-actor', 'administrator' );
$other = user_id( 'wl299-other', 'subscriber' );
ok( $issuer !== $actor, 'issuer and actor differ' );

wp_set_current_user( 0 );
$anon = rest_do_request( new \WP_REST_Request( 'POST', '/writeleash/v1/grants/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/jobs' ) );
eq( $anon->get_status(), 401, 'unauthenticated denied' );
eq( reason_of( $anon ), 'UNAUTHENTICATED', 'unauthenticated reason' );

wp_set_current_user( $issuer );
$grant = Grants::create_grant( $issuer, $actor, 2, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
$version = (int) $grant['version'];
$secret_name = 'WL299-secret-' . wp_generate_uuid4();
$product_id = product( '100.00' );
wp_update_post( array( 'ID' => $product_id, 'post_title' => $secret_name ) );
$before = saves( $product_id );

$denied = submit( $other, $grant['public_id'], payload( $actor, $version, array( $product_id ), 'cap-1' ) );
eq( $denied->get_status(), 403, 'missing capability denied' );
eq( saves( $product_id ), $before, 'capability denial writes nothing' );
eq( leaked( $denied, $secret_name ), false, 'capability denial leaks no product name' );

$bad = submit( $issuer, $grant['public_id'], payload( $actor, $version, array( $product_id ), 'bad-op', '80.00', 'INCREASE_PERCENT' ) );
eq( $bad->get_status(), 400, 'percent operation denied' );
eq( reason_of( $bad ), 'UNSUPPORTED_OPERATION', 'percent reason' );
eq( saves( $product_id ), $before, 'malformed denial writes nothing' );

$draft = product( '50.00', 'draft' );
$draft_before = saves( $draft );
$scope = submit( $issuer, $grant['public_id'], payload( $actor, $version, array( $draft ), 'draft-1' ) );
eq( $scope->get_status(), 409, 'draft outside scope' );
eq( reason_of( $scope ), 'GRANT_SCOPE_DENIED', 'draft reason' );
eq( saves( $draft ), $draft_before, 'scope denial writes nothing' );
eq( leaked( $scope, 'WL299-' ), false, 'scope denial leaks no product title' );

$wrong_actor = submit( $issuer, $grant['public_id'], payload( $issuer, $version, array( $product_id ), 'actor-1' ) );
eq( $wrong_actor->get_status(), 403, 'issuer cannot substitute itself as actor' );
eq( reason_of( $wrong_actor ), 'GRANT_IDENTITY_INVALID', 'actor mismatch reason' );
eq( saves( $product_id ), $before, 'identity denial writes nothing' );

$wrong_version = submit( $issuer, $grant['public_id'], payload( $actor, $version + 9, array( $product_id ), 'ver-1' ) );
eq( $wrong_version->get_status(), 409, 'wrong grant version denied' );
eq( reason_of( $wrong_version ), 'GRANT_VERSION_MISMATCH', 'version reason' );
eq( saves( $product_id ), $before, 'version denial writes nothing' );

$expired = Grants::create_grant( $issuer, $actor, 2, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at=%s WHERE public_id=%s', \WriteLeash\Grant_Schema::grants_table( $wpdb ), gmdate( 'Y-m-d H:i:s', time() - 60 ), $expired['public_id'] ) );
$expired_response = submit( $issuer, $expired['public_id'], payload( $actor, (int) $expired['version'], array( $product_id ), 'exp-1' ) );
eq( $expired_response->get_status(), 409, 'expired grant denied' );
eq( reason_of( $expired_response ), 'GRANT_EXPIRED', 'expired reason' );
eq( saves( $product_id ), $before, 'expiry denial writes nothing' );

$revoked = Grants::create_grant( $issuer, $actor, 2, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
Grants::revoke( $revoked['public_id'], $issuer );
$revoked_response = submit( $issuer, $revoked['public_id'], payload( $actor, (int) $revoked['version'], array( $product_id ), 'rev-1' ) );
eq( $revoked_response->get_status(), 409, 'revoked grant denied' );
eq( reason_of( $revoked_response ), 'GRANT_REVOKED', 'revoked reason' );
eq( saves( $product_id ), $before, 'revocation denial writes nothing' );

$accepted = submit( $issuer, $grant['public_id'], payload( $actor, $version, array( $product_id ), 'ok-1' ) );
eq( $accepted->get_status(), 201, 'authorized submission accepted' );
$job = $accepted->get_data()['job'];
eq( $job['approval_mode'], 'grant', 'grant mode is not human approval' );
eq( (int) $job['approver_id'], 0, 'no fabricated approver' );
eq( $job['grant_public_id'], $grant['public_id'], 'grant pin returned' );
eq( (int) $job['grant_version'], $version, 'accepted version pinned' );
eq( $job['replayed'], false, 'first submit is not a replay' );
eq( leaked( $accepted, $secret_name ), false, 'acceptance leaks no product title' );
eq( saves( $product_id ), $before, 'acceptance itself writes no price' );
$row = Repo::read_by_public_id( $job['public_id'] );
eq( (int) $row['creator_id'], $actor, 'frozen actor is the grant actor' );
eq( (int) $row['approver_id'], 0, 'stored approver stays zero' );

$replay = submit( $issuer, $grant['public_id'], payload( $actor, $version, array( $product_id ), 'ok-1' ) );
eq( $replay->get_status(), 200, 'identical retry replays' );
eq( $replay->get_data()['job']['public_id'], $job['public_id'], 'replay returns the same job' );
eq( $replay->get_data()['job']['replayed'], true, 'replay flag set' );
eq( saves( $product_id ), $before, 'replay writes no price' );

$mismatch = submit( $issuer, $grant['public_id'], payload( $actor, $version, array( $product_id ), 'ok-1', '70.00' ) );
eq( $mismatch->get_status(), 409, 'same key different payload rejected' );
eq( reason_of( $mismatch ), 'GRANT_IDEMPOTENCY_MISMATCH', 'mismatch reason' );
eq( saves( $product_id ), $before, 'mismatch writes no price' );

$second_id = product( '40.00' );
$budget = Grants::create_grant( $issuer, $actor, 1, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
$first_budget = submit( $issuer, $budget['public_id'], payload( $actor, (int) $budget['version'], array( $second_id ), 'budget-a', '30.00' ) );
eq( $first_budget->get_status(), 201, 'last budget unit accepted' );
$other_id = product( '40.00' );
$other_before = saves( $other_id );
$exhausted = submit( $issuer, $budget['public_id'], payload( $actor, (int) $budget['version'], array( $other_id ), 'budget-b', '30.00' ) );
eq( $exhausted->get_status(), 409, 'exhausted budget denied' );
eq( reason_of( $exhausted ), 'GRANT_BUDGET_EXHAUSTED', 'budget reason' );
eq( saves( $other_id ), $other_before, 'budget denial writes nothing' );

Verifier::invalidate( $product_id );
$applied = (string) wc_get_product( $product_id )->get_regular_price( 'edit' );
if ( '80.00' !== $applied ) {
	\WriteLeash\Job_Scheduler::unschedule_all();
	Worker::run( (int) $row['id'], array( 'max_items' => 2, 'budget_seconds' => 20 ), true );
	Verifier::invalidate( $product_id );
	$applied = (string) wc_get_product( $product_id )->get_regular_price( 'edit' );
}
eq( $applied, '80.00', 'worker applied the frozen absolute target' );
eq( saves( $product_id ), $before + 1, 'one Woo save for the accepted item' );
$plan_id = (string) $row['plan_id'];
$db = Verifier::observer();
try { $journal = Journal::read( $db, $plan_id, $product_id ); }
finally { $db->close(); }
eq( $journal['state'], 'APPLIED', 'journal is the durable outcome' );

$race_grant = Grants::create_grant( $issuer, $actor, 2, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
$race_id = product( '100.00' );
$race_before = saves( $race_id );
$race_body = wp_json_encode( payload( $actor, (int) $race_grant['version'], array( $race_id ), 'race-1' ) );
$work = sys_get_temp_dir() . '/wl299-race-' . bin2hex( random_bytes( 3 ) );
mkdir( $work );
$spec = array( 'grant' => $race_grant['public_id'], 'issuer' => $issuer, 'body' => $race_body, 'checkpoint' => 'AFTER_RESERVE', 'fault' => 'wait', 'result' => $work . '/b.result', 'barrier' => $work . '/b.barrier', 'release' => $work . '/b.release', 'started' => $work . '/b.started' );
file_put_contents( $work . '/b.json', json_encode( $spec ) );
$proc_b = proc_open( array( 'wp', '--path=' . ABSPATH, 'eval-file', __DIR__ . '/agent-jobs-rest-worker.php', $work . '/b.json' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $work . '/b.stdout', 'w' ), 2 => array( 'file', $work . '/b.stderr', 'w' ) ), $pipes );
fclose( $pipes[0] );
$deadline = microtime( true ) + 25;
while ( ! is_file( $spec['barrier'] ) ) {
	if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'race barrier missing: ' . file_get_contents( $work . '/b.stderr' ) ); }
	usleep( 10000 );
}
$spec_a = $spec;
$spec_a['result'] = $work . '/a.result';
$spec_a['started'] = $work . '/a.started';
unset( $spec_a['checkpoint'], $spec_a['fault'] );
file_put_contents( $work . '/a.json', json_encode( $spec_a ) );
$proc_a = proc_open( array( 'wp', '--path=' . ABSPATH, 'eval-file', __DIR__ . '/agent-jobs-rest-worker.php', $work . '/a.json' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $work . '/a.stdout', 'w' ), 2 => array( 'file', $work . '/a.stderr', 'w' ) ), $pipes_a );
fclose( $pipes_a[0] );
$deadline = microtime( true ) + 10;
while ( ! is_file( $spec_a['started'] ) ) {
	if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'second submit did not start' ); }
	usleep( 10000 );
}
usleep( 400000 );
eq( is_file( $spec_a['result'] ), false, 'concurrent duplicate blocks on the grant lock' );
file_put_contents( $spec['release'], 'go' );
eq( proc_close( $proc_b ), 0, 'lock holder exited' );
eq( proc_close( $proc_a ), 0, 'waiter exited' );
$held = json_decode( file_get_contents( $spec['result'] ), true );
$waited = json_decode( file_get_contents( $spec_a['result'] ), true );
eq( $held['public_id'], $waited['public_id'], 'concurrent submits share one job' );
eq( $waited['replayed'], true, 'concurrent second submit is a replay' );
eq( saves( $race_id ), $race_before, 'concurrent accept writes no price' );

$inflight = Grants::create_grant( $issuer, $actor, 2, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
$inflight_id = product( '90.00' );
$inflight_before = saves( $inflight_id );
$inflight_response = submit( $issuer, $inflight['public_id'], payload( $actor, (int) $inflight['version'], array( $inflight_id ), 'flight-1', '70.00' ) );
eq( $inflight_response->get_status(), 201, 'in-flight job accepted' );
\WriteLeash\Job_Scheduler::unschedule_all();
Grants::revoke( $inflight['public_id'], $issuer );
$inflight_job = Repo::read_by_public_id( $inflight_response->get_data()['job']['public_id'] );
Worker::run( (int) $inflight_job['id'], array( 'max_items' => 2, 'budget_seconds' => 20 ), true );
Verifier::invalidate( $inflight_id );
eq( (string) wc_get_product( $inflight_id )->get_regular_price( 'edit' ), '90.00', 'revoke before execution leaves the price' );
eq( saves( $inflight_id ), $inflight_before, 'revoke before execution writes nothing' );
eq( Repo::read( (int) $inflight_job['id'] )['status'], JState::PAUSED, 'revoked execution pauses the job' );

function nonce_gate( ?string $nonce ): array {
	if ( null === $nonce ) { unset( $_SERVER['HTTP_X_WP_NONCE'] ); } else { $_SERVER['HTTP_X_WP_NONCE'] = $nonce; }
	$GLOBALS['wp_rest_auth_cookie'] = true;
	$result = rest_cookie_check_errors( null );
	unset( $GLOBALS['wp_rest_auth_cookie'] );
	if ( true === $result ) { return array( 200, '' ); }
	$data = is_wp_error( $result ) ? $result->get_error_data() : array();
	return array( (int) ( $data['status'] ?? 0 ), is_wp_error( $result ) ? $result->get_error_code() : '' );
}
wp_set_current_user( $issuer );
eq( nonce_gate( null ), array( 200, '' ), 'missing cookie nonce downgrades before dispatch' );
eq( get_current_user_id(), 0, 'missing nonce is not an authenticated principal' );
$downgraded = rest_do_request( new \WP_REST_Request( 'POST', '/writeleash/v1/grants/' . $grant['public_id'] . '/jobs' ) );
eq( $downgraded->get_status(), 401, 'downgraded cookie request is unauthenticated' );
eq( nonce_gate( 'deadbeefdead' ), array( 403, 'rest_cookie_invalid_nonce' ), 'wrong cookie nonce denied before dispatch' );

marker( 'restricted grant submission' );
echo "#299 agent jobs REST: PASS ($assertions assertions)\n";
