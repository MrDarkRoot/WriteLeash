<?php
// #78 acceptance suite: the immutable certified-operation descriptor, the tiny
// validated mutable config, live readiness and the no-DDL logical-budget
// change, exercised against the real pinned fixture. Runs after cases.php on
// each engine (separate WP-CLI process, same disposable site).
require_once WP_PLUGIN_DIR . '/writeleash/writeleash.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../old-identity-fixture.php';

use WriteLeash\Certified_Operation;
use WriteLeash\Certified_Operation_Status as Status;
use WriteLeash\Compatibility_Grants;
use WriteLeash\Operation_Config;
use WriteLeash\Provisioning_Plan as Plan;
use WriteLeash\Redirection_Bulk_Disable as Adapter;
use WriteLeash\Update_Engine as Engine;

define( 'CC78_RUNTIME_USER', 'cc87_writer' );
define( 'CC78_RUNTIME_SECRET', 'cc87_secret' );
define( 'CC78_TABLE', 'wp_redirection_items' );

$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), 'unknown fixture host' );
echo "--- Begin #78 certified operation descriptor tests ($host) ---\n";

$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$normal = $GLOBALS['wpdb'];
$normal->suppress_errors( true );
$version = (string) $root->get_var( 'SELECT VERSION()' );
cc87_assert( 0 === strpos( $version, 'mysql' === $host ? '8.0.44' : '10.11.15' ), 'fixture version drift: ' . $version );

// ---------------------------------------------------------------------------
// Descriptor: immutable, exact, code-known facts only.
// ---------------------------------------------------------------------------
$operation = Certified_Operation::redirection_5_5_2_bulk_disable();
cc87_assert( 'redirection-5.5.2-bulk-disable-global' === $operation->id(), 'descriptor id' );
cc87_assert( '1' === $operation->descriptor_version(), 'descriptor version' );
cc87_assert( 'redirection' === $operation->plugin_slug(), 'plugin slug' );
cc87_assert( '5.5.2' === $operation->plugin_version(), 'plugin version' );
cc87_assert( Adapter::SUPPORTED_VERSION === $operation->plugin_version(), 'descriptor and adapter share one version constant' );
cc87_assert( 'bulk_status_update' === $operation->operation_kind(), 'operation kind' );
cc87_assert( '/redirection/v1/bulk/redirect/disable' === $operation->rest_route(), 'rest route' );
cc87_assert( 'POST' === $operation->rest_method(), 'rest method' );
cc87_assert( 'disable' === $operation->rest_bulk_action(), 'rest bulk action' );
cc87_assert( '/redirection/v1/bulk/redirect/' === $operation->rest_route_prefix(), 'rest route prefix' );
cc87_assert( 'redirection_items' === $operation->table_suffix(), 'table suffix' );
cc87_assert( 'UPDATE' === $operation->mutation(), 'mutation' );
cc87_assert( array( 'SELECT', 'UPDATE' ) === $operation->target_privileges(), 'target privileges' );
cc87_assert( 'WriteLeash\\Redirection_Bulk_Disable' === $operation->adapter_class(), 'adapter class' );
cc87_assert( 2000 === $operation->physical_ceiling(), 'physical ceiling' );
cc87_assert( 0 === $operation->logical_budget_min() && 2000 === $operation->logical_budget_max(), 'logical budget bounds' );
cc87_assert( $operation->matches_rest( '/redirection/v1/bulk/redirect/disable', 'POST' ), 'rest match positive' );
cc87_assert( ! $operation->matches_rest( '/redirection/v1/bulk/redirect/disable', 'GET' ), 'rest match rejects GET' );
cc87_assert( ! $operation->matches_rest( '/redirection/v1/bulk/redirect/enable', 'POST' ), 'rest match rejects enable' );
cc87_assert( $operation->supports_logical_budget( 0 ) && $operation->supports_logical_budget( 2000 ), 'budget bounds accept edges' );
cc87_assert( ! $operation->supports_logical_budget( 2001 ) && ! $operation->supports_logical_budget( '5' ), 'budget bounds reject non-int/out-of-range' );

// Exact known-ID lookup only; never a dynamic class/table resolution.
cc87_assert( $operation === Certified_Operation::find( 'redirection-5.5.2-bulk-disable-global' ), 'known id lookup' );
cc87_assert( null === Certified_Operation::find( 'arbitrary-operation' ), 'unknown id rejected' );
cc87_assert( null === Certified_Operation::find( '' ), 'empty id rejected' );
cc87_assert( null === Certified_Operation::find( 'Redirection-5.5.2-bulk-disable-global' ), 'case-variant id rejected' );
cc87_assert( null === Certified_Operation::find( null ), 'null id rejected' );
cc87_assert( null === Certified_Operation::find( array( 'id' => $operation->id() ) ), 'array id rejected' );
echo "  descriptor facts, immutability boundary and unknown-ID rejection: PASS\n";

// ---------------------------------------------------------------------------
// Config: strict round trip, validation and no secrets.
// ---------------------------------------------------------------------------
$state_option = Operation_Config::STATE_OPTION;
Operation_Config::reset( $operation );
$state = Operation_Config::read( $operation );
cc87_assert( 'absent' === $state['state'] && false === $state['enabled'] && null === $state['logical_budget'], 'default state is disabled' );

$state = Operation_Config::set_logical_budget( $operation, 5 );
cc87_assert( true === $state['enabled'] || false === $state['enabled'], 'state read shape' );
cc87_assert( false === $state['enabled'] && 5 === $state['logical_budget'], 'budget-only write stays disabled' );
$state = Operation_Config::set_enabled( $operation, true );
cc87_assert( true === $state['enabled'] && 5 === $state['logical_budget'], 'enable round trip' );
$state = Operation_Config::set_logical_budget( $operation, 25 );
cc87_assert( 25 === $state['logical_budget'], 'budget 5 -> 25' );
$state = Operation_Config::set_enabled( $operation, false );
cc87_assert( false === $state['enabled'] && 25 === $state['logical_budget'], 'disable keeps budget' );
$state = Operation_Config::set_enabled( $operation, true );
cc87_assert( true === $state['enabled'], 're-enable' );

// Stored payload is exactly the three authorized fields, with int budget and
// no secret material.
$raw = get_option( $state_option );
cc87_assert( is_array( $raw ), 'stored state is an array' );
$keys = array_keys( $raw );
sort( $keys );
cc87_assert( array( 'enabled', 'logical_budget', 'operation_id' ) === $keys, 'stored fields exact' );
cc87_assert( 25 === $raw['logical_budget'] && is_int( $raw['logical_budget'] ), 'stored budget normalized to int' );
$stored_json = wp_json_encode( $raw );
cc87_assert( false === strpos( $stored_json, CC78_RUNTIME_SECRET ), 'no runtime secret stored' );
cc87_assert( false === strpos( $stored_json, 'disposable_root_password' ), 'no installer secret stored' );
cc87_assert( false === strpos( $stored_json, 'wp_redirection_items' ), 'no table name stored' );
cc87_assert( false === strpos( $stored_json, 'Redirection_Bulk_Disable' ), 'no adapter/callback stored' );
cc87_assert( false === strpos( $stored_json, '2000' ), 'no physical ceiling stored' );

// Canonical values accepted and normalized.
foreach ( array( 0, 1, 5, 2000, '0', '1', '5', '2000' ) as $valid ) {
	$state = Operation_Config::set_logical_budget( $operation, $valid );
	cc87_assert( (int) $valid === $state['logical_budget'] && is_int( $state['logical_budget'] ), 'canonical budget accepted: ' . var_export( $valid, true ) );
}
// Malformed values rejected without touching stored state.
$invalid_budgets = array( -1, 2001, 2147483648, '2001', '01', '+5', ' 5', '5 ', '1.5', '5x', '', null, true, false, 5.0, array( 5 ), new stdClass() );
foreach ( $invalid_budgets as $bad ) {
	$before = get_option( $state_option );
	$threw = false;
	try {
		Operation_Config::set_logical_budget( $operation, $bad );
	} catch ( InvalidArgumentException $error ) {
		$threw = true;
	}
	cc87_assert( $threw, 'malformed budget rejected: ' . var_export( $bad, true ) );
	cc87_assert( $before === get_option( $state_option ), 'malformed budget left stored state unchanged: ' . var_export( $bad, true ) );
}
foreach ( array( 'true', 'false', 1, 0, '1', null, array(), new stdClass() ) as $bad ) {
	$threw = false;
	try {
		Operation_Config::set_enabled( $operation, $bad );
	} catch ( InvalidArgumentException $error ) {
		$threw = true;
	}
	cc87_assert( $threw, 'malformed enabled rejected: ' . var_export( $bad, true ) );
}

// Unknown stored fields fail closed and are never silently repaired.
update_option( $state_option, array( 'operation_id' => $operation->id(), 'enabled' => true, 'logical_budget' => 5, 'table_name' => 'wp_redirection_items' ) );
$state = Operation_Config::read( $operation );
cc87_assert( 'invalid' === $state['state'] && 'config_invalid' === $state['reason'], 'unknown field invalid' );
$threw = false;
try {
	Operation_Config::set_logical_budget( $operation, 5 );
} catch ( RuntimeException $error ) {
	$threw = true;
}
cc87_assert( $threw, 'invalid stored state refuses writes until reset' );
update_option( $state_option, array( 'operation_id' => 'other-operation', 'enabled' => true, 'logical_budget' => 5 ) );
cc87_assert( 'invalid' === Operation_Config::read( $operation )['state'], 'foreign operation id invalid' );
update_option( $state_option, array( 'operation_id' => $operation->id(), 'enabled' => 'true', 'logical_budget' => 5 ) );
cc87_assert( 'invalid' === Operation_Config::read( $operation )['state'], 'string enabled invalid' );
update_option( $state_option, array( 'operation_id' => $operation->id(), 'enabled' => true, 'logical_budget' => '05' ) );
cc87_assert( 'invalid' === Operation_Config::read( $operation )['state'], 'non-canonical stored budget invalid' );
Operation_Config::reset( $operation );
cc87_assert( 'absent' === Operation_Config::read( $operation )['state'], 'reset restores disabled default' );
echo "  config round trip, strict validation, unknown-field rejection and secret-free storage: PASS\n";

// ---------------------------------------------------------------------------
// Fixture: ensure the descriptor policy (P = 2000) and runtime connection.
// ---------------------------------------------------------------------------
if ( ! defined( 'WRITELEASH_DB_USER' ) ) {
	define( 'WRITELEASH_DB_USER', CC78_RUNTIME_USER );
	define( 'WRITELEASH_DB_PASSWORD', CC78_RUNTIME_SECRET );
	define( 'WRITELEASH_DB_NAME', 'wp_test' );
}
$runtime = new wpdb( CC78_RUNTIME_USER, CC78_RUNTIME_SECRET, 'wp_test', $host );
$runtime->suppress_errors( true );
$runtime->set_prefix( (string) $normal->prefix );
if ( ! $runtime->ready ) {
	Plan::install( 'wp_test', CC78_RUNTIME_USER, '%', CC78_RUNTIME_SECRET )->apply( $root );
	$runtime = new wpdb( CC78_RUNTIME_USER, CC78_RUNTIME_SECRET, 'wp_test', $host );
	$runtime->suppress_errors( true );
	$runtime->set_prefix( (string) $normal->prefix );
}
cc87_assert( $runtime->ready, '#78 runtime connection' );
$provisioned = Plan::verify( array( CC78_TABLE => 2000 ), $runtime );
if ( 'PASS' !== $provisioned['overall'] ) {
	Plan::remove_target( 'wp_test', CC78_RUNTIME_USER, '%', CC78_TABLE, 2000 )->apply( $root );
	Plan::add_target( 'wp_test', CC78_RUNTIME_USER, '%', CC78_TABLE, 2000 )->apply( $root );
}

function cc78_configure( $operation, $enabled, $budget ) {
	Operation_Config::reset( $operation );
	if ( null !== $budget ) {
		Operation_Config::set_logical_budget( $operation, $budget );
	}
	Operation_Config::set_enabled( $operation, $enabled );
}

function cc78_expect( array $state, $status, $reason, $label ) {
	cc87_assert( $status === $state['status'], $label . ' status: ' . json_encode( $state ) );
	cc87_assert( $reason === $state['reason'], $label . ' reason: ' . json_encode( $state['reason'] ) );
}

// ---------------------------------------------------------------------------
// Readiness statuses.
// ---------------------------------------------------------------------------
Operation_Config::reset( $operation );
cc78_expect( Status::check( $operation, '5.5.2', $runtime ), Status::DISABLED, 'operation_disabled', 'absent config' );

cc78_configure( $operation, true, null );
cc78_expect( Status::check( $operation, '5.5.2', $runtime ), Status::MISCONFIGURED, 'logical_budget_missing', 'enabled without budget' );

cc78_configure( $operation, true, 5 );
cc78_expect( Status::check( $operation, '5.5.3', $runtime ), Status::UNSUPPORTED, 'redirection_version_unsupported', 'newer version' );
cc78_expect( Status::check( $operation, '5.4.0', $runtime ), Status::UNSUPPORTED, 'redirection_version_unsupported', 'older version' );
cc78_expect( Status::check( $operation, null, $runtime ), Status::UNSUPPORTED, 'redirection_version_unsupported', 'unavailable version' );

$bad_runtime = new wpdb( 'cc78_absent_user', 'cc78_absent_secret', 'wp_test', $host );
$bad_runtime->suppress_errors( true );
cc78_expect( Status::check( $operation, '5.5.2', $bad_runtime ), Status::NOT_READY, 'runtime_unavailable', 'unready runtime' );

$ready = Status::check( $operation, '5.5.2', $runtime );
cc78_expect( $ready, Status::READY, 'ok', 'fully provisioned' );
cc87_assert( 5 === $ready['logical_budget'] && 2000 === $ready['actual_physical_ceiling'], 'READY evidence' );
cc87_assert( $runtime === $ready['runtime'], 'READY returns the verified runtime' );

// Live detector path is READY on the pinned fixture; an unavailable version
// (null) is a fail-closed value, never "detect live".
cc78_expect( Status::check( $operation, Adapter::detected_version(), $runtime ), Status::READY, 'ok', 'live detector' );

// Doctor failure: dropped policy trigger.
$trigger = Engine::trigger_name( CC78_TABLE );
cc87_query( $root, "DROP TRIGGER `$trigger`" );
cc78_expect( Status::check( $operation, '5.5.2', $runtime ), Status::NOT_READY, 'doctor_not_ready', 'doctor failure' );
Plan::add_target( 'wp_test', CC78_RUNTIME_USER, '%', CC78_TABLE, 2000 )->apply( $root );

// Physical ceiling mismatch: Doctor itself would pass (L=5 <= 1999) but the
// descriptor requires exactly 2000.
$installer = new Engine( $root );
$installer->remove_owned_policy( CC78_TABLE, 2000, CC78_RUNTIME_USER );
$installer->install_policy( CC78_TABLE, 1999, CC78_RUNTIME_USER );
$mismatch = Status::check( $operation, '5.5.2', $runtime );
cc78_expect( $mismatch, Status::NOT_READY, 'physical_ceiling_mismatch', 'P mismatch' );
cc87_assert( 1999 === $mismatch['actual_physical_ceiling'] && 2000 === $mismatch['physical_ceiling'], 'P mismatch evidence' );
$installer->remove_owned_policy( CC78_TABLE, 1999, CC78_RUNTIME_USER );
$installer->install_policy( CC78_TABLE, 2000, CC78_RUNTIME_USER );
cc78_expect( Status::check( $operation, '5.5.2', $runtime ), Status::READY, 'ok', 'P restored' );

// ---------------------------------------------------------------------------
// #97 old low-level DB graph refusal. The canonical WriteLeash graph is the
// only trusted identity: the pre-rebrand CommitCap graph is never migrated,
// never renamed and never accepted, and stale routine EXECUTE grants cannot
// satisfy readiness. Runs with real Redirection so the refusal is exact.
// ---------------------------------------------------------------------------
$cc78_canonical_trigger = Engine::trigger_name( CC78_TABLE );
$cc78_canonical_comment = (string) $root->get_var( $root->prepare(
	'SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', Engine::STATE
) );
$cc78_canonical_routines = Engine::routines();
$cc78_old_name = static function ( string $name ): string {
	return str_replace( 'writeleash_v01_', 'commitcap_v01_', $name );
};

// Replace the canonical graph with an exact pre-rebrand replica.
cc87_query( $root, "DROP TRIGGER `$cc78_canonical_trigger`" );
foreach ( Engine::routine_names() as $cc78_routine ) {
	cc87_query( $root, "DROP PROCEDURE `$cc78_routine`" );
}
cc87_query( $root, 'DROP TABLE writeleash_v01_state' );
cc87_query( $root, 'CREATE TABLE `commitcap_v01_state` (connection_id BIGINT UNSIGNED NOT NULL, policy_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, consumed BIGINT UNSIGNED NOT NULL, PRIMARY KEY (connection_id, policy_id)) ENGINE=InnoDB COMMENT=' . $root->prepare( '%s', str_replace( 'WriteLeash', 'CommitCap', $cc78_canonical_comment ) ) );
foreach ( $cc78_canonical_routines as $cc78_name => $cc78_body ) {
	cc87_query( $root, "CREATE PROCEDURE `" . $cc78_old_name( $cc78_name ) . '` ' . Engine::routine_params( $cc78_name ) . ' SQL SECURITY DEFINER ' . cc97_old_identity_sql( $cc78_body ) );
}
cc87_query( $root, 'CREATE TRIGGER `' . $cc78_old_name( $cc78_canonical_trigger ) . '` BEFORE UPDATE ON `' . CC78_TABLE . '` FOR EACH ROW ' . cc97_old_identity_sql( Engine::trigger_body( CC78_TABLE, 2000, CC78_RUNTIME_USER ) ) );
foreach ( Engine::routine_names() as $cc78_routine ) {
	cc87_query( $root, "GRANT EXECUTE ON PROCEDURE wp_test." . $cc78_old_name( $cc78_routine ) . " TO 'cc87_writer'@'%'" );
}
cc87_query( $root, "GRANT SELECT ON wp_test.commitcap_v01_state TO 'cc87_writer'@'%'" );

// #97 regression assertions: the old fixture bodies must be the exact old
// identity, not old names wrapping canonical WriteLeash internals.
$cc78_old_body_expectations = array(
	'writeleash_v01_open'   => array( 'commitcap_v01_state' ),
	'writeleash_v01_close'  => array( 'commitcap_v01_state', '@commitcap_v01_denied' ),
	'writeleash_v01_count'  => array( 'commitcap_v01_state' ),
	'writeleash_v01_policy' => array( 'commitcap_v01_state' ),
	'writeleash_v01_attest' => array( 'commitcap_v01_open' ),
);
foreach ( $cc78_old_body_expectations as $cc78_routine => $cc78_expected ) {
	$cc78_old_def = (string) $root->get_var( $root->prepare(
		"SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = %s",
		$cc78_old_name( $cc78_routine )
	) );
	cc87_assert( '' !== $cc78_old_def, 'old routine fixture exists: ' . $cc78_routine );
	foreach ( $cc78_expected as $cc78_token ) {
		cc87_assert( false !== strpos( $cc78_old_def, $cc78_token ), 'old routine body uses the old identity: ' . $cc78_routine . ' missing ' . $cc78_token );
	}
	cc87_assert( false === strpos( $cc78_old_def, 'writeleash_v01_' ), 'old routine body leaked the canonical identity: ' . $cc78_routine );
}
$cc78_old_trigger_body = (string) $root->get_var( $root->prepare(
	"SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s",
	$cc78_old_name( $cc78_canonical_trigger )
) );
cc87_assert( false !== strpos( $cc78_old_trigger_body, '@commitcap_v01_denied' ) && false !== strpos( $cc78_old_trigger_body, 'commitcap_v01_state' ), 'old trigger body uses the old identity' );
cc87_assert( false === strpos( $cc78_old_trigger_body, 'writeleash_v01_' ), 'old trigger body leaked the canonical identity' );

cc87_seed_bulk( $root, 2 );
$cc78_old_graph = Status::check( $operation, '5.5.2', $runtime );
cc87_assert( Status::NOT_READY === $cc78_old_graph['status'], 'old CommitCap-only graph must be NOT_READY: ' . json_encode( $cc78_old_graph ) );
cc87_assert( Status::READY !== Status::can_enable( $operation, '5.5.2', $runtime )['status'], 'enable preflight accepted the old CommitCap-only graph' );
wp_set_current_user( 1 );
$cc78_old_response = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
cc87_assert( 503 === $cc78_old_response->get_status() && array( 2, 0 ) === cc87_counts( $root ), 'old graph REST request was not fail-closed with zero mutation: ' . $cc78_old_response->get_status() );

// No automatic migration: the old helper, routines and trigger stay exactly as
// they are, and no canonical object appears without trusted provisioning.
cc87_assert( 1 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'commitcap_v01_state'" ), 'old helper was renamed or dropped' );
cc87_assert( 5 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE 'commitcap_v01\\_%'" ), 'old routines were renamed or dropped' );
cc87_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'writeleash_v01_state'" ), 'old graph was auto-migrated to the canonical helper' );
cc87_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE 'writeleash_v01\\_%'" ), 'canonical routines appeared without trusted provisioning' );
cc87_assert( 1 === (int) $root->get_var( $root->prepare( "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s", $cc78_old_name( $cc78_canonical_trigger ) ) ), 'old trigger was renamed or dropped' );

// Trusted reprovisioning is the only transition to the canonical graph.
// Clear the old object-level grants while the objects still exist: some
// engines keep stale object privileges listed after the object is dropped.
// The target grant is also cleared so the install plan sees exactly the
// reviewed empty surface; add_target re-grants it immediately after.
foreach ( Engine::routine_names() as $cc78_routine ) {
	cc87_query( $root, "REVOKE EXECUTE ON PROCEDURE wp_test." . $cc78_old_name( $cc78_routine ) . " FROM 'cc87_writer'@'%'" );
}
cc87_query( $root, "REVOKE SELECT ON wp_test.commitcap_v01_state FROM 'cc87_writer'@'%'" );
cc87_query( $root, "REVOKE SELECT, UPDATE ON wp_test.`" . CC78_TABLE . "` FROM 'cc87_writer'@'%'" );
cc87_query( $root, 'DROP TRIGGER `' . $cc78_old_name( $cc78_canonical_trigger ) . '`' );
foreach ( Engine::routine_names() as $cc78_routine ) {
	cc87_query( $root, 'DROP PROCEDURE `' . $cc78_old_name( $cc78_routine ) . '`' );
}
cc87_query( $root, 'DROP TABLE commitcap_v01_state' );
Plan::install( 'wp_test', CC78_RUNTIME_USER, '%', CC78_RUNTIME_SECRET )->apply( $root );
Plan::add_target( 'wp_test', CC78_RUNTIME_USER, '%', CC78_TABLE, 2000 )->apply( $root );
cc78_expect( Status::check( $operation, '5.5.2', $runtime ), Status::READY, 'ok', 'trusted canonical reprovision' );
echo "  #97 old CommitCap-only graph: NOT_READY, enable refused, zero mutation, no auto rename/drop, trusted reprovision restores READY: PASS\n";

// The legacy #87 budget option is not an authority.
update_option( 'writeleash_operation_budget_redirection_5_5_2_bulk_disable', 1 );
$legacy_ignored = Status::check( $operation, '5.5.2', $runtime );
cc87_assert( Status::READY === $legacy_ignored['status'] && 5 === $legacy_ignored['logical_budget'], 'legacy budget option must be ignored' );
delete_option( 'writeleash_operation_budget_redirection_5_5_2_bulk_disable' );
echo "  readiness status model (disabled/misconfigured/unsupported/not-ready/READY) and single budget authority: PASS\n";

// ---------------------------------------------------------------------------
// Exact target privilege boundary: the descriptor owns SELECT+UPDATE; any
// unreviewed effective target privilege (table, schema or global scope) is
// NOT_READY and the real REST path fails closed with zero mutations.
// ---------------------------------------------------------------------------
$canonical_privileges = $operation->target_privileges();
$table_name = (string) $runtime->prefix . $operation->table_suffix();
cc78_configure( $operation, true, 5 );
cc78_expect( Status::check( $operation, Adapter::detected_version(), $runtime ), Status::READY, 'ok', 'canonical SELECT+UPDATE' );

// Missing privileges.
cc87_query( $root, "REVOKE UPDATE ON wp_test.`$table_name` FROM 'cc87_writer'@'%'" );
$missing_update = Status::check( $operation, Adapter::detected_version(), $runtime );
cc87_assert( Status::NOT_READY === $missing_update['status'], 'missing UPDATE must not READY: ' . json_encode( $missing_update ) );
cc87_query( $root, "GRANT UPDATE ON wp_test.`$table_name` TO 'cc87_writer'@'%'" );
cc87_assert( Status::READY === Status::check( $operation, Adapter::detected_version(), $runtime )['status'], 'UPDATE restore must READY' );

cc87_query( $root, "REVOKE SELECT ON wp_test.`$table_name` FROM 'cc87_writer'@'%'" );
$missing_select = Status::check( $operation, Adapter::detected_version(), $runtime );
cc87_assert( Status::NOT_READY === $missing_select['status'], 'missing SELECT must not READY: ' . json_encode( $missing_select ) );
cc87_query( $root, "GRANT SELECT ON wp_test.`$table_name` TO 'cc87_writer'@'%'" );
cc87_assert( Status::READY === Status::check( $operation, Adapter::detected_version(), $runtime )['status'], 'SELECT restore must READY' );
echo "  target privileges canonical -> READY; SELECT-only / UPDATE-only -> NOT READY: PASS\n";

wp_set_current_user( 1 );
foreach ( array( 'INSERT', 'DELETE', 'REFERENCES' ) as $extra ) {
	cc87_query( $root, "GRANT $extra ON wp_test.`$table_name` TO 'cc87_writer'@'%'" );
	$state = Status::check( $operation, Adapter::detected_version(), $runtime );
	cc78_expect( $state, Status::NOT_READY, 'target_privileges_mismatch', 'extra ' . $extra );
	// The generic presence layer still sees SELECT+UPDATE; the exact verifier
	// is what rejects the unreviewed privilege.
	$grants = Compatibility_Grants::read( $runtime, 'wp_test' );
	cc87_assert( null !== $grants && 'PASS' === $grants->target_access( $table_name )[0], 'generic presence with extra ' . $extra );
	$exact = $grants->target_access_exact( $table_name, $canonical_privileges );
	cc87_assert( 'FAIL' === $exact[0] && false !== stripos( $exact[1], 'unreviewed' ), 'exact verifier rejects extra ' . $extra . ': ' . $exact[1] );
	if ( in_array( $extra, array( 'INSERT', 'DELETE' ), true ) ) {
		cc87_seed( $root, 3 );
		list( $response, $threads ) = cc87_trace_all( $root, function () {
			return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
		} );
		$body = $response->get_data();
		cc87_assert( 503 === $response->get_status() && 'writeleash_operation_unavailable' === $body['code'], 'extra ' . $extra . ' REST must fail closed: ' . json_encode( $body ) );
		cc87_assert( 'target_privileges_mismatch' === $body['data']['reason'], 'extra ' . $extra . ' REST reason: ' . json_encode( $body['data'] ) );
		list( $disable_updates, ) = cc87_disable_updates( $threads );
		cc87_assert( 0 === $disable_updates && array() === cc87_adapter_statements( $threads ), 'extra ' . $extra . ' REST ran a mutation or the adapter' );
		list( $total, $disabled ) = cc87_counts( $root );
		cc87_assert( 3 === $total && 0 === $disabled, 'extra ' . $extra . ' REST changed durable state' );
	}
	cc87_query( $root, "REVOKE $extra ON wp_test.`$table_name` FROM 'cc87_writer'@'%'" );
	cc87_assert( Status::READY === Status::check( $operation, Adapter::detected_version(), $runtime )['status'], 'revoke ' . $extra . ' must restore READY' );
}
echo "  extra table INSERT/DELETE/REFERENCES -> NOT READY; REST fail closed with zero mutations: PASS\n";

// Broader scope drift: schema-wide INSERT is rejected by the generic
// runtime_grants boundary, so readiness is NOT_READY and REST fails closed.
cc87_query( $root, "GRANT INSERT ON wp_test.* TO 'cc87_writer'@'%'" );
$schema_drift = Status::check( $operation, Adapter::detected_version(), $runtime );
cc87_assert( Status::NOT_READY === $schema_drift['status'], 'schema-wide INSERT must not READY: ' . json_encode( $schema_drift ) );
cc87_seed( $root, 3 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 503 === $response->get_status(), 'schema-wide INSERT REST must fail closed: ' . json_encode( $body ) );
list( $disable_updates, ) = cc87_disable_updates( $threads );
cc87_assert( 0 === $disable_updates, 'schema-wide INSERT REST ran a mutation' );
cc87_query( $root, "REVOKE INSERT ON wp_test.* FROM 'cc87_writer'@'%'" );
cc87_assert( Status::READY === Status::check( $operation, Adapter::detected_version(), $runtime )['status'], 'schema-wide revoke must restore READY' );
echo "  schema-wide INSERT drift -> NOT READY; REST fail closed: PASS\n";

// ---------------------------------------------------------------------------
// Logical budget changes need zero DDL, zero grants and zero runtime SQL.
// ---------------------------------------------------------------------------
function cc78_assert_no_ddl( array $by_thread, $label ) {
	foreach ( $by_thread as $thread => $statements ) {
		foreach ( $statements as $sql ) {
			cc87_assert( ! preg_match( '/\b(CREATE|DROP|ALTER)\b/i', $sql ), $label . ' unexpected DDL: ' . $sql );
			cc87_assert( ! preg_match( '/\b(GRANT|REVOKE)\b/i', $sql ), $label . ' unexpected grant change: ' . $sql );
			cc87_assert( ! preg_match( '/\bTRIGGER\b/i', $sql ), $label . ' unexpected trigger statement: ' . $sql );
		}
	}
}

$trigger_columns = 'TRIGGER_NAME, ACTION_STATEMENT, ACTION_TIMING, EVENT_MANIPULATION, DEFINER';
$metadata_before = $root->get_row( $root->prepare(
	"SELECT $trigger_columns FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s",
	$trigger
), ARRAY_A );
$grants_before = $root->get_results( $root->prepare( "SHOW GRANTS FOR '%s'@'%s'", CC78_RUNTIME_USER, '%' ), ARRAY_N );
$ceiling_before = ( new Engine( $runtime ) )->runtime_ceiling( CC78_TABLE );
cc87_assert( 2000 === $ceiling_before, '#78 physical ceiling before L changes' );

foreach ( array( 5, 25, 0, 2000 ) as $budget ) {
	list( , $threads ) = cc87_trace_all( $root, function () use ( $operation, $budget ) {
		Operation_Config::set_logical_budget( $operation, $budget );
		return true;
	} );
	cc78_assert_no_ddl( $threads, 'L=' . $budget );
	if ( isset( $threads[ (int) $runtime->get_var( 'SELECT CONNECTION_ID()' ) ] ) ) {
		cc87_assert( false, 'L=' . $budget . ' used the restricted runtime connection' );
	}
	$metadata_after = $root->get_row( $root->prepare(
		"SELECT $trigger_columns FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s",
		$trigger
	), ARRAY_A );
	cc87_assert( $metadata_before === $metadata_after, 'L=' . $budget . ' changed policy trigger metadata' );
	cc87_assert( $grants_before === $root->get_results( $root->prepare( "SHOW GRANTS FOR '%s'@'%s'", CC78_RUNTIME_USER, '%' ), ARRAY_N ), 'L=' . $budget . ' changed runtime grants' );
	cc87_assert( 2000 === ( new Engine( $runtime ) )->runtime_ceiling( CC78_TABLE ), 'L=' . $budget . ' changed physical ceiling' );
	$state = Status::check( $operation, '5.5.2', $runtime );
	cc87_assert( Status::READY === $state['status'] && $budget === $state['logical_budget'], 'L=' . $budget . ' readiness' );
}
echo "  L 5->25->0->2000: trigger byte-identical, grants unchanged, P still 2000, zero DDL: PASS\n";

// ---------------------------------------------------------------------------
// Real REST path consumes the new L without any DDL.
// ---------------------------------------------------------------------------
wp_set_current_user( 1 );
cc78_configure( $operation, true, 5 );
cc87_seed( $root, 6 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 409 === $response->get_status() && 'writeleash_budget_denied' === $body['code'], 'new-L first run must deny: ' . json_encode( $body ) );
list( $disable_updates, ) = cc87_disable_updates( $threads );
cc87_assert( 1 === $disable_updates, 'new-L first run must execute the plugin UPDATE once' );

Operation_Config::set_logical_budget( $operation, 25 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 200 === $response->get_status() && isset( $body['writeleash'] ) && 'COMMITTED' === $body['writeleash']['outcome'], 'new-L second run must commit: ' . json_encode( $body ) );
cc87_assert( 25 === $body['writeleash']['logical_budget'], 'REST evidence uses the configured L' );
list( $total, $disabled ) = cc87_counts( $root );
cc87_assert( 6 === $total && 6 === $disabled, 'new-L commit durability' );
$metadata_after = $root->get_row( $root->prepare(
	"SELECT $trigger_columns FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s",
	$trigger
), ARRAY_A );
cc87_assert( $metadata_before === $metadata_after, 'REST L change required trigger DDL' );
echo "  real REST uses the new L (5 -> deny, 25 -> commit) with no trigger DDL: PASS\n";

// Cleanup: leave the disposable fixture in the disabled default.
Operation_Config::reset( $operation );
echo "--- End #78 certified operation descriptor tests ($host): ALL PASS ---\n";
