<?php
// #309 removal-marker integration (container gate). Runs inside the #109
// fixture via wp eval-file:
//   WL109_CACHE=default wp --path=$site eval-file /opt/tests/jobs/grant-removal-integration.php
// Proves the #307 restricted-principal predicate flip for the #309 operator
// action with durable rows: the marker excludes only the removed principal,
// revoked/expired grants stay restricted, a later grant re-imposes the
// restriction, the audit row carries the target user id, and the operator
// capability re-check refuses anonymous and non-product-manager callers.
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
function audit_rows( int $actor_id ): array {
	global $wpdb;
	$table = GSchema::audit_table( $wpdb );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event='GRANT_RESTRICTION_REMOVED' AND actor_id=%d ORDER BY id ASC", $actor_id ), ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}
function ensure_users(): array {
	$ids = array();
	foreach ( array( 'wl309-remover', 'wl309-agent', 'wl309-bystander', 'wl309-bare' ) as $login ) {
		$existing = get_user_by( 'login', $login );
		if ( $existing ) { $ids[ $login ] = (int) $existing->ID; continue; }
		$role = 'wl309-remover' === $login ? 'administrator' : 'subscriber';
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'role' => $role, 'user_email' => $login . '@example.test' ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'user creation failed: ' . $login ); }
		$ids[ $login ] = (int) $id;
	}
	ok( 4 === count( array_unique( $ids ) ), 'four distinct identities' );
	return array( 'remover' => $ids['wl309-remover'], 'agent' => $ids['wl309-agent'], 'bystander' => $ids['wl309-bystander'], 'bare' => $ids['wl309-bare'] );
}

eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
$users = ensure_users();
$remover = $users['remover'];
$agent = $users['agent'];
$bystander = $users['bystander'];
$bare = $users['bare'];

// ---------------------------------------------------------------------------
// Simulated v1 store: the marker column is dropped and the stored version
// pinned back. The first grant operation must upgrade the store in place.
// ---------------------------------------------------------------------------
$grants_table = GSchema::grants_table( $wpdb );
$wpdb->query( "ALTER TABLE {$grants_table} DROP COLUMN restriction_removed_at" );
update_option( GSchema::OPTION, 1 );
ok( GSchema::ready( $wpdb ) === false, 'v1 store is not ready for the v2 predicate' );
wp_set_current_user( $remover );
$grant_upgrade = Grants::create_grant( $remover, $agent, 5, future_expiry() );
ok( GSchema::ready( $wpdb ), 'first grant operation upgraded the v1 store in place' );
eq( (int) get_option( GSchema::OPTION ), GSchema::SCHEMA_VERSION, 'stored schema version upgraded' );
ok( Grants::is_restricted_actor( $agent ), 'grant issued through the upgrade restricts the actor' );
marker( 'v1 to v2 in-place migration' );

// A standing grant makes the agent a restricted principal.
wp_set_current_user( $remover );
$grant_a = Grants::create_grant( $remover, $agent, 5, future_expiry() );
$grant_b = Grants::create_grant( $remover, $bystander, 5, future_expiry() );
ok( Grants::is_restricted_actor( $agent ), 'granted agent is restricted' );
ok( Grants::is_restricted_actor( $bystander ), 'granted bystander is restricted' );
ok( Grants::restriction_removed( $agent ) === false, 'removal marker absent before the operator action' );
marker( 'restricted-principal baseline' );

// Capability gate: anonymous and non-product-manager operators refuse.
$previous = get_current_user_id();
wp_set_current_user( 0 );
try { Grants::remove_restriction( $agent, 0 ); ok( false, 'anonymous operator refused' ); }
catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'PERMISSION_DENIED', 'anonymous operator refused with PERMISSION_DENIED' ); }
$subscriber = get_user_by( 'login', 'wl309-agent' );
wp_set_current_user( (int) $subscriber->ID );
try { Grants::remove_restriction( $agent, (int) $subscriber->ID ); ok( false, 'non-product-manager operator refused' ); }
catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'PERMISSION_DENIED', 'capability-less operator refused' ); }
try { Grants::remove_restriction( 0, $remover ); ok( false, 'zero target refused' ); }
catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'GRANT_IDENTITY_INVALID', 'zero target refused' ); }
wp_set_current_user( $remover );
ok( Grants::is_restricted_actor( $agent ), 'refusals left the predicate intact' );
marker( 'capability gate' );

// A principal with zero grant rows has nothing to remove.
try { Grants::remove_restriction( $bare, $remover ); ok( false, 'bare principal refused' ); }
catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'GRANT_NOT_FOUND', 'principal without grant rows refuses with GRANT_NOT_FOUND' ); }
ok( Grants::is_restricted_actor( $bare ) === false, 'bare principal is not restricted' );
marker( 'bare principal' );

// Durable removal: one audit row per stamped grant, predicate flips only for
// the target, and the same-request cache observes the flip immediately.
$before_agent = count( audit_rows( $agent ) );
$outcome = Grants::remove_restriction( $agent, $remover );
ok( $outcome['already_removed'] === false, 'removal reports a fresh stamp' );
ok( $outcome['removed_rows'] >= 1, 'at least one grant row stamped' );
ok( Grants::is_restricted_actor( $agent ) === false, 'removed principal is no longer restricted' );
ok( Grants::is_restricted_actor( $bystander ) === true, 'other restricted principal is untouched' );
ok( Grants::restriction_removed( $agent ) === true, 'removal marker set for every grant row of the agent' );
ok( Grants::restriction_removed( $bystander ) === false, 'bystander marker absent' );
$agent_audit = audit_rows( $agent );
ok( count( $agent_audit ) > $before_agent, 'removal appended audit evidence' );
$removal_rows = array_slice( $agent_audit, $before_agent );
foreach ( $removal_rows as $row ) {
	$details = json_decode( (string) $row['details'], true );
	eq( (int) ( $details['actor_id'] ?? 0 ), $agent, 'audit row carries the target user id' );
	eq( (int) ( $details['operator_id'] ?? 0 ), $remover, 'audit row carries the operator id' );
}
ok( count( $removal_rows ) === $outcome['removed_rows'], 'one audit row per stamped grant' );
marker( 'durable removal flips only the target' );

// Idempotent repeat: no state change, no new evidence.
$audit_count = count( audit_rows( $agent ) );
$repeat = Grants::remove_restriction( $agent, $remover );
eq( $repeat, array( 'actor_id' => $agent, 'removed_rows' => 0, 'already_removed' => true ), 'repeat removal is an audited-silent no-op' );
eq( count( audit_rows( $agent ) ), $audit_count, 'repeat removal appended no evidence' );
ok( Grants::is_restricted_actor( $agent ) === false, 'repeat removal kept the predicate off' );
marker( 'repeat removal no-op' );

// A later grant re-imposes the restriction; revocation alone never un-restricts.
$grant_c = Grants::create_grant( $remover, $agent, 5, future_expiry() );
ok( Grants::is_restricted_actor( $agent ) === true, 'a later grant re-imposes the restriction' );
ok( Grants::restriction_removed( $agent ) === false, 'the new unstamped row clears the removed label' );
$revoked = Grants::revoke( (string) $grant_c['public_id'], $remover );
eq( (string) $revoked['status'], 'revoked', 'revoke persisted' );
ok( Grants::is_restricted_actor( $agent ) === true, 'revoked grant keeps the principal restricted' );
marker( 'later grant re-imposes; revocation is not removal' );

echo "#309 grant removal marker gate: PASS ($assertions assertions)\n";
