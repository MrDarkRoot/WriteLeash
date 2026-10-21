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
	$roles = array( 'wl309-operator' => 'shop_manager', 'wl309-spectator' => 'subscriber', 'wl309-agent' => 'subscriber', 'wl309-second' => 'shop_manager', 'wl309-admin2' => 'administrator' );
	foreach ( array_keys( $roles ) as $login ) {
		$existing = get_user_by( 'login', $login );
		if ( $existing ) { $ids[ $login ] = (int) $existing->ID; continue; }
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'role' => $roles[ $login ], 'user_email' => $login . '@example.test' ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'user creation failed: ' . $login ); }
		$ids[ $login ] = (int) $id;
	}
	ok( 5 === count( array_unique( $ids ) ), 'five distinct identities' );
	return array( 'operator' => $ids['wl309-operator'], 'spectator' => $ids['wl309-spectator'], 'agent' => $ids['wl309-agent'], 'second' => $ids['wl309-second'], 'admin2' => $ids['wl309-admin2'] );
}
function nonce( string $action ): string { return wp_create_nonce( $action ); }
function audit_rows( int $actor_id ): array {
	global $wpdb;
	$table = GSchema::audit_table( $wpdb );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event='GRANT_RESTRICTION_REMOVED' AND actor_id=%d ORDER BY id ASC", $actor_id ), ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}
function grant_audit_rows( string $public_id ): array {
	global $wpdb;
	$grant = Grants::read_by_public_id( $public_id );
	if ( ! $grant ) { return array(); }
	$table = GSchema::audit_table( $wpdb );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE grant_db_id=%d ORDER BY id ASC", (int) $grant['id'] ), ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}
function active_grant_count(): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status=%s', GSchema::grants_table( $wpdb ), 'active' ) );
}

eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
$users = ensure_users();
$operator = $users['operator'];
$spectator = $users['spectator'];
$agent = $users['agent'];
$second = $users['second'];
$admin2 = $users['admin2'];

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
wp_set_current_user( 1 );
eq( Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $agent ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ), 'action-crossed nonce is refused' );
wp_set_current_user( $operator );
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
// Remove restriction: administrator-only and never self, separate from
// revoke, explicit warning rendered, audited with the target user id, and
// the predicate flips only for that user.
// ---------------------------------------------------------------------------

// A leashed shop manager holds edit_products + manage_woocommerce but not
// manage_options: it can never remove a restriction, and un-leashing is
// refused even through a forged request with a valid nonce.
wp_set_current_user( $operator );
$manager_attempt = Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => (string) $agent ), 'POST' );
eq( $manager_attempt, array( 'status' => 'FORBIDDEN', 'reason' => 'manage_options_required' ), 'shop manager cannot remove a restriction' );
ok( Grants::is_restricted_actor( $agent ), 'manager attempt left the predicate intact' );

// A restricted administrator cannot lift its own leash (repository would
// refuse transactionally; the admin core refuses before it).
$self_grant = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $admin2, 'budget' => '5', 'expires' => future_expiry() ), 'POST' );
eq( $self_grant['status'], 'OK', 'an administrator principal can be leashed' );
wp_set_current_user( $admin2 );
$self_attempt = Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => (string) $admin2 ), 'POST' );
eq( $self_attempt, array( 'status' => 'FORBIDDEN', 'reason' => 'self_removal_denied' ), 'restricted administrator cannot remove its own restriction' );
ok( Grants::is_restricted_actor( $admin2 ), 'self attempt left the predicate intact' );

// Render visibility follows the policy: administrators see the remove form,
// shop managers do not, a restricted administrator's own view hides it, and
// a non-issuer shop manager sees no revoke control.
wp_set_current_user( 1 );
ob_start();
Admin::render();
$page = (string) ob_get_clean();
ok( str_contains( $page, 'returns this user to ordinary WooCommerce capability rules' ), 'the standing removal warning is rendered for administrators' );
ok( substr_count( $page, 'value="' . Admin::ACTION_REMOVE . '"' ) === 1, 'exactly one remove action form' );
ok( substr_count( $page, 'value="' . Admin::ACTION_CREATE . '"' ) === 1, 'exactly one create action form' );
ok( strpos( $page, 'returns this user to ordinary WooCommerce capability rules' ) < (int) strpos( $page, 'value="' . Admin::ACTION_REMOVE . '"' ), 'the warning precedes the remove form' );
ok( str_contains( $page, 'name="grant" value="' . (string) $floor['public_id'] . '"' ), 'administrator sees the revoke form for a foreign grant' );
wp_set_current_user( $operator );
ob_start();
Admin::render();
$operator_page = (string) ob_get_clean();
ok( ! str_contains( $operator_page, 'value="' . Admin::ACTION_REMOVE . '"' ), 'shop manager sees no remove form' );
ok( ! str_contains( $operator_page, 'returns this user to ordinary WooCommerce capability rules' ), 'shop manager sees no removal warning' );
wp_set_current_user( $admin2 );
ob_start();
Admin::render();
$restricted_admin_page = (string) ob_get_clean();
ok( ! str_contains( $restricted_admin_page, 'value="' . Admin::ACTION_REMOVE . '"' ), 'a restricted administrator sees no remove form' );
wp_set_current_user( $second );
ob_start();
Admin::render();
$second_page = (string) ob_get_clean();
ok( ! str_contains( $second_page, 'name="grant" value="' . (string) $floor['public_id'] . '"' ), 'non-issuer shop manager sees no revoke form' );
ok( str_contains( $second_page, 'Issuer only' ), 'the non-issuer row explains the revoke restriction' );

// An administrator frees the restricted agent; the audit carries the target.
wp_set_current_user( 1 );
$before = count( audit_rows( $agent ) );
$removed = Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => (string) $agent ), 'POST' );
eq( $removed['status'], 'OK', 'remove accepted for the site administrator' );
eq( $removed['reason'], 'restriction_removed', 'remove reports the fresh stamp' );
ok( Grants::is_restricted_actor( $agent ) === false, 'removed principal is no longer restricted' );
$after_rows = array_slice( audit_rows( $agent ), $before );
foreach ( $after_rows as $row ) {
	$details = json_decode( (string) $row['details'], true );
	eq( (int) ( $details['actor_id'] ?? 0 ), $agent, 'audit row carries the target user id' );
	eq( (int) ( $details['operator_id'] ?? 0 ), 1, 'audit row carries the administrator operator id' );
}
$again = Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => (string) $agent ), 'POST' );
eq( $again, array( 'status' => 'OK', 'reason' => 'restriction_already_removed', 'actor_id' => $agent, 'removed_rows' => 0 ), 'repeat remove is a no-op' );
$absent = Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => '99999999' ), 'POST' );
eq( $absent['reason'], 'GRANT_NOT_FOUND', 'user without grant rows refuses' );
wp_set_current_user( $spectator );
eq( Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => (string) $agent ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'capability re-checked at the remove action' );
// Cleanup: the leashed administrator is freed by the other administrator.
wp_set_current_user( 1 );
eq( Admin::process_remove( array( '_wpnonce' => nonce( Admin::ACTION_REMOVE ), 'actor' => (string) $admin2 ), 'POST' )['status'], 'OK', 'another administrator frees the restricted administrator' );
ok( Grants::is_restricted_actor( $admin2 ) === false, 'restricted administrator freed by a different administrator' );
marker( 'remove restriction boundary' );

// ---------------------------------------------------------------------------
// #308 emergency controls: pause/resume reuse the durable kill-switch and
// revoke-all requires a server-side confirmation. Neither action un-leashes.
// ---------------------------------------------------------------------------
wp_set_current_user( $operator );
$pause_grant = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $agent, 'budget' => '5', 'expires' => future_expiry() ), 'POST' );
eq( $pause_grant['status'], 'OK', 'fixture grant for emergency controls created' );

// Gate matrix: unauthorized callers are refused before any mutation.
$active_before = active_grant_count();
wp_set_current_user( 0 );
eq( Admin::process_disable( array( '_wpnonce' => nonce( Admin::ACTION_DISABLE ) ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'anonymous pause refused' );
eq( Admin::process_enable( array( '_wpnonce' => nonce( Admin::ACTION_ENABLE ) ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'anonymous resume refused' );
eq( Admin::process_revoke_all( array( '_wpnonce' => nonce( Admin::ACTION_REVOKE_ALL ), 'confirm' => '1' ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'anonymous revoke-all refused' );
wp_set_current_user( $spectator );
eq( Admin::process_revoke_all( array( '_wpnonce' => nonce( Admin::ACTION_REVOKE_ALL ), 'confirm' => '1' ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'capability-less revoke-all refused' );
eq( active_grant_count(), $active_before, 'refusals left the grant store untouched' );
wp_set_current_user( $operator );
eq( Admin::process_disable( array( '_wpnonce' => nonce( Admin::ACTION_DISABLE ) ), 'GET' ), array( 'status' => 'INVALID', 'reason' => 'post_required' ), 'GET pause refused' );
eq( Admin::process_revoke_all( array( '_wpnonce' => nonce( Admin::ACTION_REVOKE_ALL ), 'confirm' => '1' ), 'GET' ), array( 'status' => 'INVALID', 'reason' => 'post_required' ), 'GET revoke-all refused' );
eq( Admin::process_disable( array( '_wpnonce' => 'tampered' ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ), 'forged pause nonce refused' );
eq( Admin::process_enable( array(), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ), 'missing resume nonce refused' );
eq( Admin::process_revoke_all( array( '_wpnonce' => nonce( Admin::ACTION_DISABLE ), 'confirm' => '1' ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ), 'crossed revoke-all nonce refused' );
eq( active_grant_count(), $active_before, 'gate matrix left the grant store untouched' );
marker( 'emergency control capability+method+nonce matrix' );

// Pause/resume option posture: the repository predicate is authoritative and
// only an explicit '0' enables.
$paused = Admin::process_disable( array( '_wpnonce' => nonce( Admin::ACTION_DISABLE ) ), 'POST' );
eq( $paused, array( 'status' => 'OK', 'reason' => 'grants_paused', 'disabled' => true ), 'pause accepted' );
eq( Grants::is_disabled(), true, 'pause reflected by is_disabled' );
eq( (string) get_option( GSchema::DISABLE_OPTION ), '1', 'pause stores 1' );
update_option( GSchema::DISABLE_OPTION, 'malformed', false );
eq( Grants::is_disabled(), true, 'malformed option fails closed' );
$resumed = Admin::process_enable( array( '_wpnonce' => nonce( Admin::ACTION_ENABLE ) ), 'POST' );
eq( $resumed, array( 'status' => 'OK', 'reason' => 'grants_resumed', 'disabled' => false ), 'resume accepted' );
eq( Grants::is_disabled(), false, 'resume reflected by is_disabled' );
eq( (string) get_option( GSchema::DISABLE_OPTION ), '0', 'resume stores explicit 0' );
update_option( GSchema::DISABLE_OPTION, '', false );
eq( Grants::is_disabled(), true, 'empty option fails closed' );
update_option( GSchema::DISABLE_OPTION, '0', false );
eq( Grants::is_disabled(), false, 'explicit 0 restores enabled' );
marker( 'pause/resume option posture' );

// Render: one toggle reflecting the durable state, and a revoke-all form with
// a server-side confirmation field. The remove-restriction path stays separate.
update_option( GSchema::DISABLE_OPTION, '1', false );
ob_start();
Admin::render();
$paused_page = (string) ob_get_clean();
ok( str_contains( $paused_page, 'Restricted execution is paused.' ), 'paused toggle copy reflects is_disabled' );
ok( str_contains( $paused_page, 'value="' . Admin::ACTION_ENABLE . '"' ), 'paused toggle posts enable' );
ok( ! str_contains( $paused_page, 'value="' . Admin::ACTION_DISABLE . '"' ), 'paused toggle does not post disable' );
ok( str_contains( $paused_page, 'name="confirm" value="1"' ), 'revoke-all form carries the server confirmation field' );
ok( substr_count( $paused_page, 'value="' . Admin::ACTION_REVOKE_ALL . '"' ) === 1, 'exactly one revoke-all form' );
ok( str_contains( $paused_page, 'Principals stay restricted' ), 'revoke-all warning names the un-leash boundary' );
Grants::enable( $operator );
ob_start();
Admin::render();
$active_page = (string) ob_get_clean();
ok( str_contains( $active_page, 'Restricted execution is active.' ), 'active toggle copy reflects is_disabled' );
ok( str_contains( $active_page, 'value="' . Admin::ACTION_DISABLE . '"' ), 'active toggle posts disable' );
ok( ! str_contains( $active_page, 'value="' . Admin::ACTION_ENABLE . '"' ), 'active toggle does not post enable' );
wp_set_current_user( $spectator );
ob_start();
Admin::render();
$spectator_page = (string) ob_get_clean();
ok( ! str_contains( $spectator_page, 'Pause restricted execution' ), 'capability-less user sees no pause toggle' );
ok( ! str_contains( $spectator_page, 'value="' . Admin::ACTION_REVOKE_ALL . '"' ), 'capability-less user sees no revoke-all form' );
marker( 'render toggle + revoke-all confirmation surface' );

// Revoke-all: confirm is mandatory and checked server-side before mutation.
wp_set_current_user( $operator );
$bulk_a = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $agent, 'budget' => '5', 'expires' => future_expiry() ), 'POST' );
$bulk_b = Admin::process_create( array( '_wpnonce' => nonce( Admin::ACTION_CREATE ), 'actor' => (string) $second, 'budget' => '5', 'expires' => future_expiry() ), 'POST' );
eq( $bulk_a['status'], 'OK', 'first bulk fixture grant created' );
eq( $bulk_b['status'], 'OK', 'second bulk fixture grant created' );
$bulk_a_before = Grants::read_by_public_id( (string) $bulk_a['public_id'] );
$bulk_b_before = Grants::read_by_public_id( (string) $bulk_b['public_id'] );
$bulk_a_audit = count( grant_audit_rows( (string) $bulk_a['public_id'] ) );
$bulk_b_audit = count( grant_audit_rows( (string) $bulk_b['public_id'] ) );
$active_before = active_grant_count();
eq( Admin::process_revoke_all( array( '_wpnonce' => nonce( Admin::ACTION_REVOKE_ALL ) ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'confirmation_required' ), 'missing confirm refused server-side' );
eq( Admin::process_revoke_all( array( '_wpnonce' => nonce( Admin::ACTION_REVOKE_ALL ), 'confirm' => '0' ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'confirmation_required' ), 'confirm=0 refused server-side' );
eq( active_grant_count(), $active_before, 'unconfirmed revoke-all left every active grant in place' );
eq( (int) Grants::read_by_public_id( (string) $bulk_a['public_id'] )['version'], (int) $bulk_a_before['version'], 'unconfirmed revoke-all did not bump a version' );
$bulk = Admin::process_revoke_all( array( '_wpnonce' => nonce( Admin::ACTION_REVOKE_ALL ), 'confirm' => '1' ), 'POST' );
eq( $bulk['status'], 'OK', 'confirmed revoke-all accepted' );
eq( $bulk['reason'], 'grants_revoked_all', 'revoke-all reports its reason' );
ok( (int) $bulk['revoked'] >= 2, 'revoke-all revoked the known active grants' );
eq( (int) $bulk['revoked'], $active_before, 'revoke-all reports exactly the active set' );
eq( active_grant_count(), 0, 'zero active grants remain' );
foreach ( array( array( $bulk_a, $bulk_a_before, $bulk_a_audit ), array( $bulk_b, $bulk_b_before, $bulk_b_audit ) ) as $fixture ) {
	list( $created, $before_row, $before_audit ) = $fixture;
	$row = Grants::read_by_public_id( (string) $created['public_id'] );
	eq( (string) $row['status'], 'revoked', 'bulk grant is revoked' );
	eq( (string) $row['revoke_reason'], Grants::EMERGENCY_REVOKE_REASON, 'bulk grant records the emergency reason' );
	ok( null !== $row['revoked_at'], 'bulk grant records revoked_at' );
	eq( (int) $row['version'], (int) $before_row['version'] + 1, 'bulk grant version bumped exactly once' );
	$rows = grant_audit_rows( (string) $created['public_id'] );
	eq( count( $rows ), $before_audit + 1, 'one audit row appended per revoked grant' );
	$last = $rows[ count( $rows ) - 1 ];
	eq( (string) $last['event'], 'GRANT_REVOKED', 'bulk audit event is a revocation' );
	$details = json_decode( (string) $last['details'], true );
	eq( (string) ( $details['reason'] ?? '' ), Grants::EMERGENCY_REVOKE_REASON, 'bulk audit carries the emergency reason' );
	eq( (int) ( $details['revoker_id'] ?? 0 ), $operator, 'bulk audit carries the operator id' );
	ok( ! empty( $details['bulk'] ), 'bulk audit marks the batch' );
}
ok( Grants::is_restricted_actor( $agent ), 'revoke-all leaves the agent restricted' );
ok( Grants::is_restricted_actor( $second ), 'revoke-all leaves the second principal restricted' );
$again = Admin::process_revoke_all( array( '_wpnonce' => nonce( Admin::ACTION_REVOKE_ALL ), 'confirm' => '1' ), 'POST' );
eq( $again, array( 'status' => 'OK', 'reason' => 'grants_revoked_all', 'revoked' => 0 ), 'repeat revoke-all is an idempotent no-op' );
eq( active_grant_count(), 0, 'repeat leaves zero active grants' );
eq( count( grant_audit_rows( (string) $bulk_a['public_id'] ) ), $bulk_a_audit + 1, 'repeat appended no audit evidence' );
eq( (int) Grants::read_by_public_id( (string) $bulk_a['public_id'] )['version'], (int) $bulk_a_before['version'] + 1, 'repeat did not bump the version' );
marker( 'revoke-all CAS + audit + no un-leash' );

echo "#309/#308 grant admin surface gate: PASS ($assertions assertions)\n";
