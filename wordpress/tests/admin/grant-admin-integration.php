<?php
// #309 admin-surface integration (container gate). Runs via wp eval-file:
//   WL111_CACHE=default wp --path=$site eval-file /opt/tests/admin/grant-admin-integration.php
// Proves the reviewed operator matrix against the real registered surface:
// capability+method+nonce gate on every mutation, the create contract
// (actor ≠ issuer, budget 1..10000, future UTC expiry), durable revoke with
// the principal staying restricted, the separate remove-restriction action
// with its explicit warning and capability re-check, and the audit row with
// the target user id.
use WriteLeash\Grant_Admin as Admin;
use WriteLeash\Grant_Repository as Grants;
use WriteLeash\Grant_Schema as GSchema;

global $wpdb, $assertions;
$assertions = 0;

function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function ok( bool $condition, string $label ): void { eq( $condition, true, $label ); }
function marker( string $label ): void {
	global $assertions;
	echo "#309 $label: PASS ($assertions assertions)\n";
}
function future_expiry(): string { return gmdate( 'Y-m-d H:i:s', time() + 86400 ); }
function ensure_users(): array {
	$ids = array();
	foreach ( array( 'wl309-operator', 'wl309-spectator', 'wl309-agent', 'wl309-second' ) as $login ) {
		$existing = get_user_by( 'login', $login );
		if ( $existing ) { $ids[ $login ] = (int) $existing->ID; continue; }
		$role = 'wl309-spectator' === $login || 'wl309-agent' === $login ? 'subscriber' : 'shop_manager';
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'role' => $role, 'user_email' => $login . '@example.test' ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'user creation failed: ' . $login ); }
		$ids[ $login ] = (int) $id;
	}
	ok( 4 === count( array_unique( $ids ) ), 'four distinct identities' );
	return array( 'operator' => $ids['wl309-operator'], 'spectator' => $ids['wl309-spectator'], 'agent' => $ids['wl309-agent'], 'second' => $ids['wl309-second'] );
}
function nonce( string $action ): string { return wp_create_nonce( $action ); }
function audit_rows( int $actor_id ): array {
	global $wpdb;
	$table = GSchema::audit_table( $wpdb );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event='GRANT_RESTRICTION_REMOVED' AND actor_id=%d ORDER BY id ASC", $actor_id ), ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}

eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
$users = ensure_users();
$operator = $users['operator'];
$spectator = $users['spectator'];
$agent = $users['agent'];
$second = $users['second'];

// ---------------------------------------------------------------------------
// Gate matrix: capability, method and nonce on every mutation.
// ---------------------------------------------------------------------------
$post = array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $agent, 'budget' => '5', 'expires' => future_expiry() );
wp_set_current_user( 0 );
eq( Admin::process_create( $post, 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'anonymous create is forbidden' );
eq( Admin::process_revoke( array( '_wpnonce' => nonce( Admin::ACTION_REVOKE ), 'grant' => str_repeat( 'a', 36 ) ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'anonymous revoke is forbidden' );
eq( Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => (string) $agent ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'anonymous remove is forbidden' );
wp_set_current_user( $spectator );
eq( Admin::process_create( $post, 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'capability-less create is forbidden' );
eq( Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => (string) $agent ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'capability-less remove is forbidden' );
wp_set_current_user( $operator );
eq( Admin::process_create( $post, 'GET' ), array( 'status' => 'INVALID', 'reason' => 'post_required' ), 'GET create is refused' );
eq( Admin::process_create( array( '_wpnonce' => 'tampered' ) + $post, 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ), 'forged nonce is refused' );
eq( Admin::process_create( array_diff_key( $post, array( '_wpnonce' => '' ) ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ), 'missing nonce is refused' );
eq( Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $agent ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ), 'action-crossed nonce is refused' );
ok( Grants::is_restricted_actor( $agent ) === false, 'gate matrix issued no grant' );
marker( 'capability+nonce gate matrix' );

// ---------------------------------------------------------------------------
// Create contract: actor ≠ issuer, existing actor, budget 1..10000, future UTC.
// ---------------------------------------------------------------------------
wp_set_current_user( $operator );
$bad = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $operator, 'budget' => '5', 'expires' => future_expiry() ), 'POST' );
eq( $bad, array( 'status' => 'INVALID', 'reason' => 'GRANT_IDENTITY_INVALID' ), 'self-issued grant refused' );
$bad = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => '0', 'budget' => '5', 'expires' => future_expiry() ), 'POST' );
eq( $bad['reason'], 'GRANT_IDENTITY_INVALID', 'zero actor refused' );
$bad = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => '99999999', 'budget' => '5', 'expires' => future_expiry() ), 'POST' );
eq( $bad['reason'], 'GRANT_IDENTITY_INVALID', 'guessed/nonexistent actor refused' );
$bad = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $agent, 'budget' => '0', 'expires' => future_expiry() ), 'POST' );
eq( $bad['reason'], 'GRANT_BUDGET_INVALID', 'zero budget refused' );
$bad = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $agent, 'budget' => '10001', 'expires' => future_expiry() ), 'POST' );
eq( $bad['reason'], 'GRANT_BUDGET_INVALID', 'over-cap budget refused' );
$bad = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $agent, 'budget' => '5', 'expires' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), 'POST' );
eq( $bad['reason'], 'GRANT_EXPIRY_INVALID', 'past expiry refused' );
$bad = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $agent, 'budget' => '5', 'expires' => 'tomorrow' ), 'POST' );
eq( $bad['reason'], 'GRANT_EXPIRY_INVALID', 'malformed expiry refused' );
$ceiling = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $agent, 'budget' => '10000', 'expires' => future_expiry() ), 'POST' );
eq( $ceiling['status'], 'OK', '10000-item budget accepted' );
$grant = Grants::read_by_public_id( (string) $ceiling['public_id'] );
ok( is_array( $grant ), 'created grant row is durable' );
eq( (int) $grant['issuer_id'], $operator, 'authenticated operator recorded as issuer' );
eq( (int) $grant['actor_id'], $agent, 'frozen actor recorded' );
eq( (int) $grant['budget_max'], 10000, 'budget ceiling stored' );
ok( Grants::is_restricted_actor( $agent ), 'issued grant restricts the actor' );
$floor = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $agent, 'budget' => '1', 'expires' => future_expiry() ), 'POST' );
eq( $floor['status'], 'OK', 'one-item budget accepted' );
marker( 'create contract' );

// ---------------------------------------------------------------------------
// Revoke: durable, principal stays restricted, non-issuer without
// manage_options refuses, administrator may revoke another issuer's grant.
// ---------------------------------------------------------------------------
wp_set_current_user( $operator );
$own = Admin::process_revoke( array( '_wpnonce' => nonce( Admin::ACTION_REVOKE ), 'grant' => (string) $ceiling['public_id'] ), 'POST' );
eq( $own['status'], 'OK', 'issuer revoke accepted' );
eq( (string) Grants::read_by_public_id( (string) $ceiling['public_id'] )['status'], 'revoked', 'revoke persisted' );
ok( Grants::is_restricted_actor( $agent ) === true, 'revoked principal stays restricted' );
wp_set_current_user( $second );
$foreign = Admin::process_revoke( array( '_wpnonce' => nonce( Admin::ACTION_REVOKE ), 'grant' => (string) $floor['public_id'] ), 'POST' );
eq( $foreign, array( 'status' => 'INVALID', 'reason' => 'PERMISSION_DENIED' ), 'non-issuer shop manager cannot revoke a foreign grant' );
$unknown = Admin::process_revoke( array( '_wpnonce' => nonce( Admin::ACTION_REVOKE ), 'grant' => str_repeat( '0', 36 ) ), 'POST' );
eq( $unknown['reason'], 'GRANT_NOT_FOUND', 'unknown public id refused' );
wp_set_current_user( 1 );
$admin_revoked = Admin::process_revoke( array( '_wpnonce' => nonce( Admin::ACTION_REVOKE ), 'grant' => (string) $floor['public_id'] ), 'POST' );
eq( $admin_revoked['status'], 'OK', 'administrator revokes a foreign grant' );
eq( (string) Grants::read_by_public_id( (string) $floor['public_id'] )['status'], 'revoked', 'administrator revoke persisted' );
marker( 'revoke boundary' );

// ---------------------------------------------------------------------------
// Remove restriction: separate privileged action, explicit warning rendered,
// audited with the target user id, predicate flips only for that user.
// ---
wp_set_current_user( $operator );
ob_start();
Admin::render();
$page = (string) ob_get_clean();
ok( str_contains( $page, 'returns this user to ordinary WooCommerce capability rules' ), 'the standing removal warning is rendered' );
ok( substr_count( $page, 'value="' . Admin::ACTION_REMOVE . '"' ) === 1, 'exactly one remove action form' );
ok( substr_count( $page, 'value="' . Admin::ACTION_CREATE . '"' ) === 1, 'exactly one create action form' );
ok( strpos( $page, 'returns this user to ordinary WooCommerce capability rules' ) < (int) strpos( $page, 'value="' . Admin::ACTION_REMOVE . '"' ), 'the warning precedes the remove form' );

$before = count( audit_rows( $agent ) );
$removed = Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => (string) $agent ), 'POST' );
eq( $removed['status'], 'OK', 'remove accepted for the product-manager operator' );
eq( $removed['reason'], 'restriction_removed', 'remove reports the fresh stamp' );
ok( Grants::is_restricted_actor( $agent ) === false, 'removed principal is no longer restricted' );
$after_rows = array_slice( audit_rows( $agent ), $before );
foreach ( $after_rows as $row ) {
	$details = json_decode( (string) $row['details'], true );
	eq( (int) ( $details['actor_id'] ?? 0 ), $agent, 'audit row carries the target user id' );
	eq( (int) ( $details['operator_id'] ?? 0 ), $operator, 'audit row carries the operator id' );
}
$again = Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => (string) $agent ), 'POST' );
eq( $again, array( 'status' => 'OK', 'reason' => 'restriction_already_removed', 'actor_id' => $agent, 'removed_rows' => 0 ), 'repeat remove is a no-op' );
$absent = Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => '99999999' ), 'POST' );
eq( $absent['reason'], 'GRANT_NOT_FOUND', 'user without grant rows refuses' );
wp_set_current_user( $spectator );
eq( Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => (string) $agent ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'capability re-checked at the remove action' );
wp_set_current_user( $operator );
marker( 'remove restriction boundary' );

echo "#309 grant admin surface gate: PASS ($assertions assertions)\n";
