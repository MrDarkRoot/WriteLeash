<?php
// #78 acceptance suite: the immutable certified-operation descriptor, the tiny
// validated mutable config, live readiness and the no-DDL logical-budget
// change, exercised against the real pinned fixture. Runs after cases.php on
// each engine (separate WP-CLI process, same disposable site).
require_once WP_PLUGIN_DIR . '/commitcap-for-wordpress/commitcap-for-wordpress.php';
require_once __DIR__ . '/helpers.php';

use CommitCap\Certified_Operation;
use CommitCap\Certified_Operation_Status as Status;
use CommitCap\Compatibility_Grants;
use CommitCap\Operation_Config;
use CommitCap\Provisioning_Plan as Plan;
use CommitCap\Redirection_Bulk_Disable as Adapter;
use CommitCap\Update_Engine as Engine;

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
cc87_assert( 'CommitCap\\Redirection_Bulk_Disable' === $operation->adapter_class(), 'adapter class' );
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
if ( ! defined( 'COMMITCAP_DB_USER' ) ) {
	define( 'COMMITCAP_DB_USER', CC78_RUNTIME_USER );
	define( 'COMMITCAP_DB_PASSWORD', CC78_RUNTIME_SECRET );
	define( 'COMMITCAP_DB_NAME', 'wp_test' );
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

// The legacy #87 budget option is not an authority.
update_option( 'commitcap_operation_budget_redirection_5_5_2_bulk_disable', 1 );
$legacy_ignored = Status::check( $operation, '5.5.2', $runtime );
cc87_assert( Status::READY === $legacy_ignored['status'] && 5 === $legacy_ignored['logical_budget'], 'legacy budget option must be ignored' );
delete_option( 'commitcap_operation_budget_redirection_5_5_2_bulk_disable' );
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
		cc87_assert( 503 === $response->get_status() && 'commitcap_operation_unavailable' === $body['code'], 'extra ' . $extra . ' REST must fail closed: ' . json_encode( $body ) );
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
cc87_assert( 409 === $response->get_status() && 'commitcap_budget_denied' === $body['code'], 'new-L first run must deny: ' . json_encode( $body ) );
list( $disable_updates, ) = cc87_disable_updates( $threads );
cc87_assert( 1 === $disable_updates, 'new-L first run must execute the plugin UPDATE once' );

Operation_Config::set_logical_budget( $operation, 25 );
list( $response, $threads ) = cc87_trace_all( $root, function () {
	return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
} );
$body = $response->get_data();
cc87_assert( 200 === $response->get_status() && isset( $body['commitcap'] ) && 'COMMITTED' === $body['commitcap']['outcome'], 'new-L second run must commit: ' . json_encode( $body ) );
cc87_assert( 25 === $body['commitcap']['logical_budget'], 'REST evidence uses the configured L' );
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
