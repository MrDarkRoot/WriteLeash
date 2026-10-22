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
// #308 audit-store fault injection: the production seam may throw for the
// named event so revoke-all's whole-batch rollback is provable.
$GLOBALS['wl308_audit_fault'] = null;
add_action( 'writeleash_grant_audit_before_insert', function ( $event ) {
	if ( ( $GLOBALS['wl308_audit_fault'] ?? null ) === $event ) { throw new RuntimeException( 'controlled-audit-fault' ); }
} );

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
function audit_count(): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', GSchema::audit_table( $wpdb ) ) );
}
function audit_event_count( string $event ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event=%s', GSchema::audit_table( $wpdb ), $event ) );
}
function ensure_users(): array {
	$ids = array();
	$roles = array( 'wl309-remover' => 'administrator', 'wl309-leashed-manager' => 'shop_manager', 'wl309-plain-manager' => 'shop_manager', 'wl309-leashed-admin' => 'administrator' );
	foreach ( array( 'wl309-remover', 'wl309-agent', 'wl309-bystander', 'wl309-bare', 'wl309-leashed-manager', 'wl309-plain-manager', 'wl309-leashed-admin' ) as $login ) {
		$existing = get_user_by( 'login', $login );
		if ( $existing ) { $ids[ $login ] = (int) $existing->ID; continue; }
		$role = $roles[ $login ] ?? 'subscriber';
		$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'role' => $role, 'user_email' => $login . '@example.test' ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'user creation failed: ' . $login ); }
		$ids[ $login ] = (int) $id;
	}
	ok( 7 === count( array_unique( $ids ) ), 'seven distinct identities' );
	return array( 'remover' => $ids['wl309-remover'], 'agent' => $ids['wl309-agent'], 'bystander' => $ids['wl309-bystander'], 'bare' => $ids['wl309-bare'], 'manager' => $ids['wl309-leashed-manager'], 'plain_manager' => $ids['wl309-plain-manager'], 'leashed_admin' => $ids['wl309-leashed-admin'] );
}

eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
$users = ensure_users();
$remover = $users['remover'];
$agent = $users['agent'];
$bystander = $users['bystander'];
$bare = $users['bare'];
$manager = $users['manager'];
$plain_manager = $users['plain_manager'];
$leashed_admin = $users['leashed_admin'];

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
wp_set_current_user( 0 );
try { Grants::remove_restriction( $agent, 0 ); ok( false, 'anonymous operator refused' ); }
catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'PERMISSION_DENIED', 'anonymous operator refused with PERMISSION_DENIED' ); }
wp_set_current_user( $bare );
try { Grants::remove_restriction( $agent, $bare ); ok( false, 'non-product-manager operator refused' ); }
catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'PERMISSION_DENIED', 'capability-less operator refused' ); }
try { Grants::remove_restriction( 0, $remover ); ok( false, 'zero target refused' ); }
catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'GRANT_IDENTITY_INVALID', 'zero target refused' ); }
wp_set_current_user( $remover );
ok( Grants::is_restricted_actor( $agent ), 'refusals left the predicate intact' );
marker( 'capability gate' );

// ---------------------------------------------------------------------------
// Self-un-leash is never allowed. The leashed shop manager holds
// edit_products + manage_woocommerce (the #307 restricted-principal profile)
// and must not be able to lift its own leash; un-leashing anyone now also
// requires manage_options on top of the product-management pair.
// ---------------------------------------------------------------------------
wp_set_current_user( $remover );
$manager_grant = Grants::create_grant( $remover, $manager, 5, future_expiry() );
ok( Grants::is_restricted_actor( $manager ), 'leashed shop manager is restricted' );
wp_set_current_user( $manager );
try { Grants::remove_restriction( $manager, $manager ); ok( false, 'self-un-leash refused' ); }
catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'PERMISSION_DENIED', 'self-un-leash refused with PERMISSION_DENIED' ); }
ok( Grants::is_restricted_actor( $manager ), 'self attempt left the manager predicate intact' );
try { Grants::remove_restriction( $bystander, $manager ); ok( false, 'shop manager cannot un-leash another principal' ); }
catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'PERMISSION_DENIED', 'un-leash without manage_options refused' ); }
ok( Grants::is_restricted_actor( $bystander ), 'manager attempt left the bystander predicate intact' );
wp_set_current_user( $remover );
marker( 'self-un-leash and manage_options policy' );

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

// ---------------------------------------------------------------------------
// #308 emergency revoke-all: one transaction, per-grant CAS, one audit row
// per grant, idempotent on zero active, and never an un-leash.
// ---------------------------------------------------------------------------
wp_set_current_user( $remover );
$bulk_a = Grants::create_grant( $remover, $agent, 5, future_expiry() );
$bulk_b = Grants::create_grant( $remover, $bystander, 5, future_expiry() );
$bulk_a_before = Grants::read_by_public_id( (string) $bulk_a['public_id'] );
$bulk_b_before = Grants::read_by_public_id( (string) $bulk_b['public_id'] );
$bulk_a_audit = count( grant_audit_rows( (string) $bulk_a['public_id'] ) );
$bulk_b_audit = count( grant_audit_rows( (string) $bulk_b['public_id'] ) );
$active_before = active_grant_count();
$audit_before = audit_count();
$revoked_count = Grants::revoke_all( $remover );
eq( $revoked_count, $active_before, 'revoke-all returns exactly the active set size' );
ok( $revoked_count >= 2, 'revoke-all covers multiple active grants' );
eq( active_grant_count(), 0, 'revoke-all leaves zero active grants' );
eq( audit_count(), $audit_before + $revoked_count, 'one audit row per revoked grant in the batch' );
foreach ( array( array( $bulk_a, $bulk_a_before, $bulk_a_audit ), array( $bulk_b, $bulk_b_before, $bulk_b_audit ) ) as $fixture ) {
	list( $created, $before_row, $before_audit ) = $fixture;
	$row = Grants::read_by_public_id( (string) $created['public_id'] );
	eq( (string) $row['status'], 'revoked', 'bulk grant is revoked' );
	eq( (string) $row['revoke_reason'], Grants::EMERGENCY_REVOKE_REASON, 'bulk grant records EMERGENCY_REVOKE_ALL' );
	ok( null !== $row['revoked_at'], 'bulk grant records revoked_at' );
	eq( (int) $row['version'], (int) $before_row['version'] + 1, 'bulk grant version bumped exactly once' );
	$rows = grant_audit_rows( (string) $created['public_id'] );
	eq( count( $rows ), $before_audit + 1, 'exactly one audit row appended for the grant' );
	$last = $rows[ count( $rows ) - 1 ];
	eq( (string) $last['event'], 'GRANT_REVOKED', 'bulk audit event is a revocation' );
	$details = json_decode( (string) $last['details'], true );
	eq( (string) ( $details['reason'] ?? '' ), Grants::EMERGENCY_REVOKE_REASON, 'bulk audit carries the emergency reason' );
	eq( (int) ( $details['revoker_id'] ?? 0 ), $remover, 'bulk audit carries the operator id' );
	ok( ! empty( $details['bulk'] ), 'bulk audit marks the batch' );
}
ok( Grants::is_restricted_actor( $agent ), 'revoke-all leaves the agent restricted' );
ok( Grants::is_restricted_actor( $bystander ), 'revoke-all leaves the bystander restricted' );
ok( Grants::restriction_removed( $agent ) === false, 'revoke-all never stamps the removal marker' );
$total_after = audit_count();
$repeat = Grants::revoke_all( $remover );
eq( $repeat, 0, 'zero-active revoke-all is a no-op' );
eq( audit_count(), $total_after, 'zero-active revoke-all writes no audit' );
eq( (int) Grants::read_by_public_id( (string) $bulk_a['public_id'] )['version'], (int) $bulk_a_before['version'] + 1, 'zero-active revoke-all does not bump versions' );
marker( 'revoke-all CAS/audit/no un-leash and idempotence' );

// Capability gate: anonymous and capability-less operators mutate nothing.
wp_set_current_user( 0 );
try { Grants::revoke_all( 0 ); ok( false, 'anonymous revoke-all refused' ); }
catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'PERMISSION_DENIED', 'anonymous revoke-all refused with PERMISSION_DENIED' ); }
wp_set_current_user( $bare );
try { Grants::revoke_all( $bare ); ok( false, 'capability-less revoke-all refused' ); }
catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'PERMISSION_DENIED', 'capability-less revoke-all refused' ); }
wp_set_current_user( $remover );
eq( active_grant_count(), 0, 'refused revoke-all calls mutated nothing' );
marker( 'revoke-all capability gate' );

// ---------------------------------------------------------------------------
// #308 emergency-control operator policy: disable, enable and revoke_all also
// require manage_options and refuse a restricted principal. That closes the
// containment bypass where the #307 restricted profile — exactly a shop
// manager holding manage_woocommerce + edit_products — could resume an
// operator's emergency pause or flush every principal's grant.
// ---------------------------------------------------------------------------
wp_set_current_user( $remover );
$emergency_fixture = Grants::create_grant( $remover, $agent, 5, future_expiry() );
$leashed_admin_grant = Grants::create_grant( $remover, $leashed_admin, 5, future_expiry() );
ok( Grants::is_restricted_actor( $manager ), 'the leashed shop manager is restricted' );
ok( Grants::is_restricted_actor( $plain_manager ) === false, 'the plain shop manager is not restricted' );
ok( Grants::is_restricted_actor( $leashed_admin ), 'the leashed administrator is restricted' );
$policy_active = active_grant_count();
$policy_disabled = Grants::is_disabled();
$policy_version = (int) Grants::read_by_public_id( (string) $emergency_fixture['public_id'] )['version'];
$policy_audit = audit_count();
ok( $policy_active >= 2, 'emergency policy fixtures are active' );
foreach ( array(
	'leashed shop manager' => $manager,
	'non-restricted shop manager' => $plain_manager,
	'restricted administrator' => $leashed_admin,
) as $policy_label => $policy_caller ) {
	wp_set_current_user( $policy_caller );
	try { Grants::disable( $policy_caller ); ok( false, $policy_label . ' pause refused' ); }
	catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'PERMISSION_DENIED', $policy_label . ' pause refused with PERMISSION_DENIED' ); }
	try { Grants::enable( $policy_caller ); ok( false, $policy_label . ' resume refused' ); }
	catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'PERMISSION_DENIED', $policy_label . ' resume refused with PERMISSION_DENIED' ); }
	try { Grants::revoke_all( $policy_caller ); ok( false, $policy_label . ' revoke-all refused' ); }
	catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'PERMISSION_DENIED', $policy_label . ' revoke-all refused with PERMISSION_DENIED' ); }
	eq( active_grant_count(), $policy_active, $policy_label . ' attempts left the grant states unchanged' );
	eq( Grants::is_disabled(), $policy_disabled, $policy_label . ' attempts left the kill-switch unchanged' );
	eq( (int) Grants::read_by_public_id( (string) $emergency_fixture['public_id'] )['version'], $policy_version, $policy_label . ' attempts left the grant version unchanged' );
	eq( audit_count(), $policy_audit, $policy_label . ' attempts appended no audit evidence' );
}
marker( 'emergency control operator refusals (leashed and plain shop manager, restricted administrator)' );

// The same three actions succeed for a non-restricted administrator, with the
// audit and version behaviour unchanged.
wp_set_current_user( $remover );
ok( Grants::is_restricted_actor( $remover ) === false, 'the acting administrator is not restricted' );
$disabled_events = audit_event_count( 'GRANT_DISABLED' );
$enabled_events = audit_event_count( 'GRANT_ENABLED' );
Grants::disable( $remover );
eq( Grants::is_disabled(), true, 'non-restricted administrator pause succeeds' );
eq( audit_event_count( 'GRANT_DISABLED' ), $disabled_events + 1, 'administrator pause appends its audit event' );
Grants::enable( $remover );
eq( Grants::is_disabled(), false, 'non-restricted administrator resume succeeds' );
eq( audit_event_count( 'GRANT_ENABLED' ), $enabled_events + 1, 'administrator resume appends its audit event' );
$policy_revoked = Grants::revoke_all( $remover );
eq( $policy_revoked, $policy_active, 'non-restricted administrator revoke-all revokes the active set' );
eq( active_grant_count(), 0, 'administrator revoke-all leaves zero active grants' );
eq( (int) Grants::read_by_public_id( (string) $emergency_fixture['public_id'] )['version'], $policy_version + 1, 'administrator revoke-all bumps the version exactly once' );
eq( (string) Grants::read_by_public_id( (string) $emergency_fixture['public_id'] )['revoke_reason'], Grants::EMERGENCY_REVOKE_REASON, 'administrator revoke-all records the emergency reason' );
eq( (int) Grants::read_by_public_id( (string) $leashed_admin_grant['public_id'] )['version'], 2, 'the leashed administrator grant is revoked once' );
marker( 'emergency control administrator path' );

// Audit failure rolls back every grant transition in the batch.
$fail_a = Grants::create_grant( $remover, $agent, 5, future_expiry() );
$fail_b = Grants::create_grant( $remover, $bystander, 5, future_expiry() );
$fail_a_before = Grants::read_by_public_id( (string) $fail_a['public_id'] );
$fail_b_before = Grants::read_by_public_id( (string) $fail_b['public_id'] );
$audit_before = audit_count();
$GLOBALS['wl308_audit_fault'] = 'GRANT_REVOKED';
try { Grants::revoke_all( $remover ); ok( false, 'audit failure must roll the batch back' ); }
catch ( \WriteLeash\Grant_Error $error ) { eq( $error->reason(), 'GRANT_STORE_UNAVAILABLE', 'audit failure fails closed' ); }
$GLOBALS['wl308_audit_fault'] = null;
eq( active_grant_count(), 2, 'audit failure left both fixture grants active' );
eq( (int) Grants::read_by_public_id( (string) $fail_a['public_id'] )['version'], (int) $fail_a_before['version'], 'audit failure did not bump the first version' );
eq( (int) Grants::read_by_public_id( (string) $fail_b['public_id'] )['version'], (int) $fail_b_before['version'], 'audit failure did not bump the second version' );
eq( (string) Grants::read_by_public_id( (string) $fail_a['public_id'] )['status'], 'active', 'audit failure kept the first grant active' );
eq( audit_count(), $audit_before, 'audit failure appended no evidence' );
$cleanup = Grants::revoke_all( $remover );
ok( $cleanup >= 2, 'cleanup revoke-all covers the fixtures' );
eq( active_grant_count(), 0, 'cleanup leaves zero active grants' );
marker( 'revoke-all audit-in-transaction rollback' );

// Cache discipline: revoke-all must not clear the restricted-principal cache.
// Simulate an external removal stamping every row while the request cache is
// primed, then revoke the still-active grant. The cached restricted verdict
// must survive; only remove_restriction() clears it.
wp_set_current_user( $remover );
$cache_grant = Grants::create_grant( $remover, $manager, 5, future_expiry() );
ok( Grants::is_restricted_actor( $manager ), 'cache probe primed as restricted' );
$wpdb->query( $wpdb->prepare( 'UPDATE %i SET restriction_removed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE actor_id=%d', GSchema::grants_table( $wpdb ), $manager ) );
ok( Grants::restriction_removed( $manager ) === true, 'cache probe simulated an external removal' );
$cache_revoked = Grants::revoke_all( $remover );
ok( $cache_revoked >= 1, 'cache probe revoked the active grant' );
ok( Grants::is_restricted_actor( $manager ) === true, 'revoke-all did not clear the restricted cache' );
// Test-only cleanup: clear the request memo so this process does not carry
// the simulated stale verdict into any later assertion. The durable marker
// (not this cleanup) is the store truth.
$cache_prop = new ReflectionProperty( Grants::class, 'restricted_cache' );
$cache_prop->setAccessible( true );
$cache_prop->setValue( null, array() );
ok( Grants::is_restricted_actor( $manager ) === false, 'the simulated removal is durable in the store' );
marker( 'revoke-all preserves the restricted-principal cache' );

echo "#309/#308 grant removal marker gate: PASS ($assertions assertions)\n";
