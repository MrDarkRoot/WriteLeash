<?php
// #61 final threat-model adversarial proof on the production surfaces.
// Everything here attacks the cooperative envelope or records its bounds.
// It never broadens the runtime's authority or the product claim.
require_once WP_PLUGIN_DIR . '/commitcap/commitcap.php';
require_once __DIR__ . '/helpers.php';

use CommitCap\Certified_Operation as Operation;
use CommitCap\Certified_Operation_Status as Status;
use CommitCap\Disposable_Demo as Demo;
use CommitCap\Disposable_Demo_Setup as Setup;
use CommitCap\Guard;
use CommitCap\Guard_Error;
use CommitCap\Operation_Config as Config;
use CommitCap\Provisioning_Plan as Plan;
use CommitCap\Redirection_Bulk_Disable as Adapter;
use CommitCap\Update_Engine as Engine;

$host = getenv( 'CC_ENGINE_HOST' );
cc87_assert( in_array( $host, array( 'mysql', 'mariadb' ), true ), '#61 pinned host' );
$normal = $GLOBALS['wpdb'];
$normal->suppress_errors( true );
$normal_id = (int) $normal->get_var( 'SELECT CONNECTION_ID()' );
$root = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$root->suppress_errors( true );
$runtime = new wpdb( 'cc87_writer', 'cc87_secret', 'wp_test', $host );
$runtime->suppress_errors( true );
$runtime->set_prefix( (string) $normal->prefix );
$runtime_id = (int) $runtime->get_var( 'SELECT CONNECTION_ID()' );
$operation = Operation::redirection_5_5_2_bulk_disable();
$table = (string) $normal->prefix . 'redirection_items';
$trigger = Engine::trigger_name( $table );
$demo_table = Demo::table( (string) $normal->prefix );
$setup = new Setup( $root, 'cc87_writer', '%' );
$engine = new Engine( $root );
$setup->setup();
$setup->reset();
Config::set_logical_budget( $operation, 5 );
Config::set_enabled( $operation, true );
cc87_assert( 'READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'baseline production READY' );
wp_set_current_user( 1 );
cc87_assert( $normal_id !== $runtime_id, 'normal and restricted connections are distinct' );
cc87_assert( 'wp_test@%' === $normal->get_var( 'SELECT CURRENT_USER()' ) &&
	'cc87_writer@%' === $runtime->get_var( 'SELECT CURRENT_USER()' ), 'distinct matched DB identities' );

/** A certified candidate must 503 with zero stock or adapter mutation. */
function cc61_rest_refused( $root, $label, ?string $expected_reason = null ) {
	cc87_seed_bulk( $root, 3 );
	list( $response, $threads ) = cc87_trace_all( $root, static function () {
		return rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
	} );
	$body = $response->get_data();
	cc87_assert( 503 === $response->get_status(), $label . ' status: ' . $response->get_status() . ' ' . json_encode( $body ) );
	if ( null !== $expected_reason ) {
		cc87_assert( $expected_reason === ( $body['data']['reason'] ?? null ), $label . ' reason: ' . json_encode( $body['data'] ?? $body ) );
	}
	// The readiness Doctor may legitimately CALL reviewed helper routines while
	// refusing; the invariant is zero Redirection plugin UPDATE anywhere.
	list( $updates, ) = cc87_disable_updates( $threads );
	cc87_assert( 0 === $updates, $label . ' plugin UPDATE executed (normal or restricted)' );
	cc87_assert( array( 3, 0 ) === cc87_counts( $root ), $label . ' durable mutation' );
}

// ---------------------------------------------------------------------------
// Recorded external facts: isolation level and the observed write graph.
// ---------------------------------------------------------------------------
$isolation = $root->get_var( 'SELECT @@transaction_isolation' );
if ( ! is_string( $isolation ) || '' === $isolation ) {
	$isolation = $root->get_var( 'SELECT @@tx_isolation' );
}
cc87_assert( is_string( $isolation ) && '' !== $isolation, 'isolation level readable' );
$target_meta = $root->get_row( $root->prepare(
	'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table
), ARRAY_A );
cc87_assert( is_array( $target_meta ) && 'InnoDB' === $target_meta['ENGINE'], 'supported target is InnoDB' );
$fk_count = (int) $root->get_var( $root->prepare(
	'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND ((TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s) OR (REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = %s))', $table, $table
) );
$partition_count = (int) $root->get_var( $root->prepare(
	'SELECT COUNT(*) FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND PARTITION_NAME IS NOT NULL', $table
) );
cc87_assert( 0 === $fk_count && 0 === $partition_count, 'observed Redirection write graph has no FK/partition' );
echo "#61 $host: recorded isolation=$isolation; target engine=InnoDB; FK=0; partitions=0\n";

// Unreviewed FK cascade graph is refused by the trusted installer path.
cc87_query( $root, 'DROP TABLE IF EXISTS cc61_child' );
cc87_query( $root, 'DROP TABLE IF EXISTS cc61_parent' );
cc87_query( $root, 'CREATE TABLE cc61_parent (id INT PRIMARY KEY) ENGINE=InnoDB' );
cc87_query( $root, 'CREATE TABLE cc61_child (id INT PRIMARY KEY, parent_id INT NOT NULL, CONSTRAINT cc61_fk FOREIGN KEY (parent_id) REFERENCES cc61_parent(id) ON UPDATE CASCADE) ENGINE=InnoDB' );
$fk_refused = false;
try {
	Plan::add_target( 'wp_test', 'cc87_writer', '%', 'cc61_child', 5 )->apply( $root );
} catch ( RuntimeException $error ) {
	$fk_refused = true;
}
cc87_assert( $fk_refused, 'unreviewed FK graph must be refused, not certified' );
cc87_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'cc61_child'" ), 'FK graph received a policy trigger' );
cc87_assert( 0 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = \"'cc87_writer'@'%'\" AND TABLE_NAME = 'cc61_child'" ), 'FK graph received target grants' );
cc87_query( $root, 'DROP TABLE cc61_child' );
cc87_query( $root, 'DROP TABLE cc61_parent' );

// Nontransactional storage is refused by the same trusted installer path.
cc87_query( $root, 'DROP TABLE IF EXISTS cc61_myisam' );
cc87_query( $root, 'CREATE TABLE cc61_myisam (id INT PRIMARY KEY) ENGINE=MyISAM' );
$myisam_refused = false;
try {
	Plan::add_target( 'wp_test', 'cc87_writer', '%', 'cc61_myisam', 5 )->apply( $root );
} catch ( RuntimeException $error ) {
	$myisam_refused = true;
}
cc87_assert( $myisam_refused, 'nontransactional target must be refused' );
cc87_query( $root, 'DROP TABLE cc61_myisam' );
echo "#61 $host: unreviewed FK cascade and MyISAM storage refused by the installer path: PASS\n";

// ---------------------------------------------------------------------------
// Identity boundary: normal wpdb keeps ordinary authority; runtime is least.
// ---------------------------------------------------------------------------
cc87_query( $root, 'DROP TABLE IF EXISTS cc61_normal_probe' );
cc87_query( $root, 'CREATE TABLE cc61_normal_probe (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB' );
cc87_query( $root, 'INSERT INTO cc61_normal_probe (id, v) VALUES (1, 0)' );
cc87_assert( false !== $normal->query( 'UPDATE cc61_normal_probe SET v = 1 WHERE id = 1' ) &&
	1 === (int) $root->get_var( 'SELECT v FROM cc61_normal_probe WHERE id = 1' ), 'normal WordPress identity keeps ordinary application authority' );
// Honest boundary: the normal WordPress identity holds ordinary broad
// application grants in this fixture (schema-wide EXECUTE), so it is outside
// the cooperative envelope and not constrained by CommitCap.
cc87_assert( false !== $normal->query( "CALL commitcap_v01_open('cc61_probe')" ), 'fixture normal identity has schema-wide routine EXECUTE' );
cc87_query( $root, "DELETE FROM commitcap_v01_state WHERE policy_id = 'cc61_probe'" ); // trusted cleanup of the identity probe row
cc87_assert( false === $runtime->query( "SELECT option_value FROM {$normal->options} LIMIT 1" ), 'runtime identity cannot read WordPress options' );
cc87_assert( false === $runtime->query( 'UPDATE commitcap_v01_state SET consumed = 99 WHERE connection_id = 0' ), 'runtime identity cannot write helper state directly' );
cc87_query( $root, 'DROP TABLE cc61_normal_probe' );
echo "#61 $host: normal identity retains broad ordinary authority (outside contract); restricted identity cannot reach options/helper: PASS\n";

// ---------------------------------------------------------------------------
// Runtime direct tamper probes: no extra authority, no state change.
// ---------------------------------------------------------------------------
$grants_before_probe = $root->get_results( "SHOW GRANTS FOR 'cc87_writer'@'%'", ARRAY_N );
$helper_before_probe = (int) $root->get_var( 'SELECT COUNT(*) FROM commitcap_v01_state' );
$probes = array(
	'helper INSERT'       => "INSERT INTO commitcap_v01_state (connection_id, policy_id, consumed) VALUES (99999, 'cc61', 0)",
	'helper UPDATE'       => 'UPDATE commitcap_v01_state SET consumed = 99 WHERE connection_id = 0',
	'helper DELETE'       => 'DELETE FROM commitcap_v01_state WHERE connection_id = 0',
	'helper DROP'         => 'DROP TABLE commitcap_v01_state',
	'helper ALTER'        => 'ALTER TABLE commitcap_v01_state ADD COLUMN cc61 INT',
	'routine DROP'        => 'DROP PROCEDURE commitcap_v01_open',
	'routine ALTER'       => 'ALTER PROCEDURE commitcap_v01_open SQL SECURITY INVOKER',
	'routine CREATE'      => 'CREATE PROCEDURE cc61_malicious() SELECT 1',
	'function CREATE'     => 'CREATE FUNCTION cc61_malicious() RETURNS INT RETURN 1',
	'trigger DROP'        => 'DROP TRIGGER ' . Engine::trigger_name( $table ),
	'trigger CREATE'      => 'CREATE TRIGGER cc61_bad BEFORE UPDATE ON ' . $table . ' FOR EACH ROW SET @cc61 = 1',
	'self GRANT'          => "GRANT ALL PRIVILEGES ON wp_test.* TO 'cc87_writer'@'%'",
	'CREATE USER'         => "CREATE USER 'cc61_escalate'@'%' IDENTIFIED BY 'cc61_escalate'",
);
foreach ( $probes as $label => $sql ) {
	cc87_assert( false === $runtime->query( $sql ), 'runtime probe must fail: ' . $label . ' ' . $runtime->last_error );
}
cc87_assert( $grants_before_probe === $root->get_results( "SHOW GRANTS FOR 'cc87_writer'@'%'", ARRAY_N ), 'runtime probes changed grants' );
cc87_assert( $helper_before_probe === (int) $root->get_var( 'SELECT COUNT(*) FROM commitcap_v01_state' ), 'runtime probes changed helper state' );
cc87_assert( 5 === (int) $root->get_var( "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE 'commitcap_v01_%'" ), 'runtime probes changed routine count' );
// Unguarded direct target UPDATE is physically denied for the runtime identity.
cc87_seed_bulk( $root, 3 );
cc87_assert( false === $runtime->query( "UPDATE `$table` SET status = 'disabled'" ), 'runtime must not bypass Guard on the target' );
cc87_assert( array( 3, 0 ) === cc87_counts( $root ), 'unguarded runtime target update committed' );
echo "#61 $host: 13 runtime tamper probes denied; grants/helper/routines and target unchanged: PASS\n";

// ---------------------------------------------------------------------------
// Shared-runtime sibling matrix: corrupt demo sibling fails production closed;
// an absent/unprovisioned sibling is not the same as a corrupt reachable one.
// ---------------------------------------------------------------------------
$demo_trigger = Engine::trigger_name( $demo_table );
// Fully unprovisioned sibling (no reachable target grant) is not corruption.
$setup->cleanup();
$absent_status = Status::check( $operation, '5.5.2', $runtime );
cc87_assert( 'READY' === $absent_status['status'], 'absent demo sibling must not fail production: ' . json_encode( $absent_status ) );
$setup->setup();
$setup->reset();
// Reachable-but-corrupt sibling: P drift.
$engine->remove_owned_policy( $demo_table, Demo::PHYSICAL_CEILING, 'cc87_writer' );
$engine->install_policy( $demo_table, Demo::PHYSICAL_CEILING - 1, 'cc87_writer' );
cc87_assert( 'NOT_READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'demo P drift must fail production closed' );
cc61_rest_refused( $root, '#61 demo P drift' );
$engine->remove_owned_policy( $demo_table, Demo::PHYSICAL_CEILING - 1, 'cc87_writer' );
$engine->install_policy( $demo_table, Demo::PHYSICAL_CEILING, 'cc87_writer' );
cc87_query( $root, "DROP TRIGGER `$demo_trigger`" );
cc87_assert( 'NOT_READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'missing demo trigger must fail production closed' );
cc61_rest_refused( $root, '#61 demo trigger missing' );
$setup->setup();
cc87_query( $root, "GRANT INSERT ON wp_test.`$demo_table` TO 'cc87_writer'@'%'" );
cc87_assert( 'NOT_READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'extra demo grant must fail production closed' );
cc61_rest_refused( $root, '#61 demo extra grant' );
cc87_query( $root, "REVOKE INSERT ON wp_test.`$demo_table` FROM 'cc87_writer'@'%'" );
cc87_query( $root, "DROP TRIGGER `$demo_trigger`" );
cc87_query( $root, "CREATE TRIGGER `$demo_trigger` BEFORE UPDATE ON `$demo_table` FOR EACH ROW SET @cc61_bad_demo = 1" );
cc87_assert( 'NOT_READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'foreign-bodied demo trigger must fail production closed' );
cc61_rest_refused( $root, '#61 demo foreign trigger' );
cc87_query( $root, "DROP TRIGGER `$demo_trigger`" );
$setup->setup();
$setup->reset();
cc87_assert( 'READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'production READY after demo restore' );
echo "#61 $host: absent demo sibling READY; demo P/trigger/grant corruption fails production 503 with zero mutation: PASS\n";

// Reachable unreviewed trigger/path on a foreign runtime-writable object.
cc87_query( $root, 'DROP TABLE IF EXISTS cc61_foreign' );
cc87_query( $root, 'CREATE TABLE cc61_foreign (id INT PRIMARY KEY, v INT NOT NULL) ENGINE=InnoDB' );
cc87_query( $root, "GRANT SELECT, UPDATE ON wp_test.cc61_foreign TO 'cc87_writer'@'%'" );
cc87_query( $root, 'CREATE TRIGGER cc61_foreign_trig BEFORE UPDATE ON cc61_foreign FOR EACH ROW SET @cc61_foreign = 1' );
cc87_assert( 'NOT_READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'foreign triggered write path must not silently PASS' );
cc61_rest_refused( $root, '#61 foreign triggered path' );
cc87_query( $root, 'DROP TRIGGER cc61_foreign_trig' );
cc87_query( $root, "REVOKE SELECT, UPDATE ON wp_test.cc61_foreign FROM 'cc87_writer'@'%'" );
cc87_query( $root, 'DROP TABLE cc61_foreign' );
cc87_assert( 'READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'READY after foreign path removal' );
echo "#61 $host: foreign runtime-writable triggered path -> NOT_READY/503 zero mutation: PASS\n";

// ---------------------------------------------------------------------------
// Production target trigger tampering: missing, body, P, extra trigger.
// ---------------------------------------------------------------------------
cc87_query( $root, "DROP TRIGGER `$trigger`" );
cc87_assert( 'NOT_READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'missing production trigger' );
cc61_rest_refused( $root, '#61 production trigger missing' );
Plan::add_target( 'wp_test', 'cc87_writer', '%', $table, $operation->physical_ceiling() )->apply( $root );
cc87_query( $root, "DROP TRIGGER `$trigger`" );
cc87_query( $root, "CREATE TRIGGER `$trigger` BEFORE UPDATE ON `$table` FOR EACH ROW SET @cc61_bad = 1" );
cc87_assert( 'NOT_READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'foreign-bodied production trigger' );
cc61_rest_refused( $root, '#61 production trigger body' );
cc87_query( $root, "DROP TRIGGER `$trigger`" );
Plan::add_target( 'wp_test', 'cc87_writer', '%', $table, $operation->physical_ceiling() )->apply( $root );
$engine->remove_owned_policy( $table, $operation->physical_ceiling(), 'cc87_writer' );
$engine->install_policy( $table, $operation->physical_ceiling() - 1, 'cc87_writer' );
$p_status = Status::check( $operation, '5.5.2', $runtime );
cc87_assert( 'NOT_READY' === $p_status['status'] && 'physical_ceiling_mismatch' === $p_status['reason'], 'production P drift: ' . json_encode( $p_status ) );
cc61_rest_refused( $root, '#61 production P drift', 'physical_ceiling_mismatch' );
$engine->remove_owned_policy( $table, $operation->physical_ceiling() - 1, 'cc87_writer' );
$engine->install_policy( $table, $operation->physical_ceiling(), 'cc87_writer' );
cc87_query( $root, "CREATE TRIGGER cc61_extra BEFORE UPDATE ON `$table` FOR EACH ROW SET @cc61_extra = 1" );
cc87_assert( 'NOT_READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'unreviewed extra trigger must not silently PASS' );
cc61_rest_refused( $root, '#61 production extra trigger' );
cc87_query( $root, 'DROP TRIGGER cc61_extra' );
cc87_assert( 'READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'READY after production trigger restore' );
echo "#61 $host: production trigger missing/body/P/extra -> NOT_READY/503; canonical restore READY: PASS\n";

// ---------------------------------------------------------------------------
// Retry: budget is per Guard-owned transaction, not a cross-retry wallet.
// ---------------------------------------------------------------------------
cc87_seed_bulk( $root, 6 );
Config::set_logical_budget( $operation, 5 );
for ( $attempt = 1; $attempt <= 2; ++$attempt ) {
	$response = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
	cc87_assert( 409 === $response->get_status() && 'commitcap_budget_denied' === $response->get_data()['code'], 'retry ' . $attempt . ' must deny independently' );
	cc87_assert( array( 6, 0 ) === cc87_counts( $root ), 'retry ' . $attempt . ' durable mutation' );
}
Config::set_logical_budget( $operation, 10 );
$response = rest_do_request( cc87_rest_bulk_request( 'disable', array( 'global' => true ) ) );
cc87_assert( 200 === $response->get_status() && array( 6, 6 ) === cc87_counts( $root ), 'fresh transaction after denial commits' );
echo "#61 $host: denied retries each get a fresh per-transaction budget; L change commits: PASS\n";

// ---------------------------------------------------------------------------
// Connection death: before the callback and mid-callback (server rollback).
// ---------------------------------------------------------------------------
cc87_seed_bulk( $root, 3 );
$dead = new wpdb( 'cc87_writer', 'cc87_secret', 'wp_test', $host );
$dead->suppress_errors( true );
$dead->set_prefix( (string) $normal->prefix );
$dead_id = (int) $dead->get_var( 'SELECT CONNECTION_ID()' );
cc87_query( $root, 'KILL ' . $dead_id );
$dead_result = ( new Adapter( $dead, 5 ) )->run();
if ( 'COMMITTED' === $dead_result['outcome'] ) {
	// WordPress wpdb transparently reconnects a dropped connection. The
	// reconnected session then runs a complete fresh readiness + Guard path
	// (new connection id, new transaction, new budget), which is supported.
	cc87_assert( 5 === $dead_result['logical_budget'] && 3 === $dead_result['consumed'], 'reconnected run facts: ' . json_encode( $dead_result ) );
	cc87_assert( array( 3, 3 ) === cc87_counts( $root ), 'reconnected run durability' );
	echo "#61 $host: killed connection transparently reconnected; fresh full readiness+Guard path (connection_id-scoped accounting): classified\n";
} else {
	cc87_assert( in_array( $dead_result['outcome'], array( 'UNKNOWN', 'ERROR' ), true ), 'dropped connection fail-closed: ' . json_encode( $dead_result ) );
	cc87_assert( array( 3, 0 ) === cc87_counts( $root ), 'failed run durability' );
	echo "#61 $host: killed connection without reconnect -> typed refusal, zero mutation\n";
}
// Unrecoverable restricted credential (reconnect cannot succeed): typed refusal.
$unrecoverable = new wpdb( 'cc87_writer', 'cc61_wrong_secret', 'wp_test', $host );
$unrecoverable->suppress_errors( true );
$unrecoverable->set_prefix( (string) $normal->prefix );
$unrecoverable_result = ( new Adapter( $unrecoverable, 5 ) )->run();
cc87_assert( in_array( $unrecoverable_result['outcome'], array( 'UNKNOWN', 'ERROR' ), true ), 'unrecoverable credential must fail closed: ' . json_encode( $unrecoverable_result ) );
cc87_assert( array( 3, 3 ) === cc87_counts( $root ), 'unrecoverable-credential run changed durable state' );

$setup->reset(); // demo canonical A
$mid = new wpdb( 'cc87_writer', 'cc87_secret', 'wp_test', $host );
$mid->suppress_errors( true );
$mid->set_prefix( (string) $normal->prefix );
$mid_failed_closed = false;
try {
	Guard::update( $demo_table, 5, static function () use ( $mid, $demo_table ) {
		cc87_assert( false !== $mid->query( "UPDATE `$demo_table` SET value = 1 WHERE id BETWEEN 1 AND 5 AND value = 0" ), 'mid-callback counted UPDATE' );
		$mid->dbh->close(); // Simulated process/connection death before Guard COMMIT.
		return 'closed';
	}, $mid );
} catch ( Guard_Error $error ) {
	$mid_failed_closed = in_array( $error->reason(), array( 'callback_failed', 'database_error', 'guard_failure', 'transaction_lost', 'accounting_invalid', 'connection_changed' ), true );
} catch ( \Throwable $error ) {
	$mid_failed_closed = true;
}
cc87_assert( $mid_failed_closed, 'mid-callback connection death must fail closed' );
$observer = new wpdb( 'root', 'disposable_root_password', 'wp_test', $host );
$observer->suppress_errors( true );
$mid_demo_values = array_map( 'intval', $observer->get_col( "SELECT value FROM `$demo_table` ORDER BY id" ) );
cc87_assert( array( 0, 0, 0, 0, 0, 0 ) === $mid_demo_values, 'mid-callback connection death must leave no durable demo mutation: ' . json_encode( $mid_demo_values ) );
echo "#61 $host: dead connection refused UNKNOWN/ERROR; mid-callback death -> server rollback, fresh observer clean: PASS\n";

// ---------------------------------------------------------------------------
// Installer/root credential material must not be retained in WordPress state.
// ---------------------------------------------------------------------------
$leaked = $root->get_results( $root->prepare(
	"SELECT option_name FROM {$normal->options} WHERE option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s",
	'%disposable_root_password%', '%cc87_secret%', '%cc87_initial_secret%'
), ARRAY_A );
cc87_assert( array() === $leaked, 'credential material retained in wp_options: ' . json_encode( $leaked ) );
$snapshot = \CommitCap\Product_Status::snapshot();
cc87_assert( false === strpos( wp_json_encode( $snapshot ), 'disposable_root_password' ) &&
	false === strpos( wp_json_encode( $snapshot ), 'cc87_secret' ), 'status snapshot leaks credentials' );
ob_start();
\CommitCap\Admin_Page::render();
$admin_html = ob_get_clean();
cc87_assert( false === strpos( $admin_html, 'disposable_root_password' ) && false === strpos( $admin_html, 'cc87_secret' ), 'Admin HTML leaks credentials' );
echo "#61 $host: no installer/runtime credential material in options, status snapshot or Admin HTML: PASS\n";

// ---------------------------------------------------------------------------
// Prefix audit: production PHP derives all target names from wpdb->prefix.
// ---------------------------------------------------------------------------
foreach ( glob( WP_PLUGIN_DIR . '/commitcap/includes/*.php' ) as $file ) {
	// Comments are stripped; string literals remain, so this detects real
	// hard-coded target names, not documentation.
	$source = php_strip_whitespace( $file );
	cc87_assert( false === strpos( $source, 'wp_redirection_items' ), 'hard-coded wp_ target in ' . basename( $file ) );
	cc87_assert( false === strpos( $source, 'wp_commitcap_demo_rows' ), 'hard-coded wp_ demo target in ' . basename( $file ) );
}
echo "#61 $host: no hard-coded wp_ target names in production code: PASS\n";

// ---------------------------------------------------------------------------
// Handler drift (last REST consumer): exact 5.5.2 but an unexpected matched
// handler identity. The overridden route stays for this process only.
// ---------------------------------------------------------------------------
cc87_seed_bulk( $root, 3 );
$wrong_handler_called = false;
$drift_request = cc87_rest_bulk_request( 'disable', array( 'global' => true ) );
$wrong_handler = array(
	'callback'            => static function () use ( &$wrong_handler_called ) {
		$wrong_handler_called = true;
		return array( 'items' => array(), 'total' => 0 );
	},
	'permission_callback' => '__return_true',
);
// Exercise the exact production filter entrypoint WordPress calls, with an
// unexpected matched handler identity for the certified route shape.
list( $drift_result, $drift_threads ) = cc87_trace_all( $root, static function () use ( $drift_request, $wrong_handler ) {
	return \CommitCap\Redirection_Bulk_Disable_Rest::dispatch( null, $drift_request, '/redirection/v1/bulk/redirect/(?P<action>[a-z]+)', $wrong_handler );
} );
cc87_assert( $drift_result instanceof WP_Error && 'commitcap_operation_unavailable' === $drift_result->get_error_code(), 'handler drift must 503: ' . json_encode( is_object( $drift_result ) ? $drift_result->get_error_code() : $drift_result ) );
cc87_assert( 503 === ( $drift_result->get_error_data()['status'] ?? null ), 'handler drift HTTP status' );
cc87_assert( false === $wrong_handler_called, 'uncertified handler executed' );
list( $drift_updates, ) = cc87_disable_updates( $drift_threads );
cc87_assert( 0 === $drift_updates, 'handler drift mutated Redirection' );
cc87_assert( array( 3, 0 ) === cc87_counts( $root ), 'handler drift durable mutation' );
echo "#61 $host: exact 5.5.2 with wrong matched handler -> 503 unavailable, zero mutation: PASS\n";

// ---------------------------------------------------------------------------
// Guard transaction ownership: the reviewed Redirection 5.5.2 call path issues
// no transaction control, so the cooperative contract can own START/COMMIT.
// ---------------------------------------------------------------------------
$transaction_tokens = 0;
$redirection_php = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_PLUGIN_DIR . '/redirection', FilesystemIterator::SKIP_DOTS ) );
foreach ( $redirection_php as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$code = php_strip_whitespace( $file->getPathname() );
	$transaction_tokens += (int) (bool) preg_match( '/\b(START TRANSACTION|COMMIT|ROLLBACK|SAVEPOINT|autocommit)\b/i', $code );
}
cc87_assert( 0 === $transaction_tokens, 'Redirection 5.5.2 contains transaction-control tokens: ' . $transaction_tokens );
echo "#61 $host: static scan: Redirection 5.5.2 PHP issues no transaction control in the reviewed call path: PASS\n";

// Leave the fixture READY at L=5 with the demo at canonical A.
Config::set_logical_budget( $operation, 5 );
Config::set_enabled( $operation, true );
$setup->setup();
$setup->reset();
cc87_assert( 'READY' === Status::check( $operation, '5.5.2', $runtime )['status'], 'final production READY' );
echo "#61 $host: ALL THREAT-MODEL ADVERSARIAL ASSERTIONS PASS\n";
