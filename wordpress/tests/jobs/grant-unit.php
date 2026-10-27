<?php
// #298 hermetic standing-grant regression.
// Runs with plain PHP. No WordPress, no database, no network:
//   php wordpress/tests/jobs/grant-unit.php
// Covers pure contract edges deterministically: identity/approval modes,
// scope allow/deny, canonical idempotency payload, budget reservation state
// machine (single-process; true two-session row-lock overlap is covered by
// jobs/integration.php grant sections and the MySQL+MariaDB container matrix
// in CI), audit shape, and the price-write boundary tripwire.
// True concurrency, revoke-vs-write races, crash recovery and zero-save
// denial are NOT proven here; see wordpress/tests/jobs/grant-integration.php.

define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

class wpdb {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $last_error = '';
	public $queries = array();
	/** @var int[] actor ids the fresh restriction read must report */
	public $restricted_actors = array();
	public $table_count = 0;
	public $fail_reads = false;
	public function prepare( $query, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[isd]/',
			static function ( $m ) use ( &$i, $args ) {
				$a = $args[ $i++ ];
				if ( '%i' === $m[0] ) { return '`' . $a . '`'; }
				if ( '%d' === $m[0] ) { return (string) (int) $a; }
				return "'" . str_replace( "'", "''", (string) $a ) . "'";
			},
			$query
		);
	}
	public function get_var( $query ) {
		$this->queries[] = $query;
		$this->last_error = '';
		if ( $this->fail_reads ) {
			$this->last_error = 'forced read failure';
			return null;
		}
		if ( false !== strpos( $query, 'information_schema.TABLES' ) ) { return (string) $this->table_count; }
		return '0';
	}
	public function get_results( $query, $output = null ) {
		unset( $output );
		$this->queries[] = $query;
		$this->last_error = '';
		if ( $this->fail_reads ) {
			$this->last_error = 'forced read failure';
			return null;
		}
		if ( false === strpos( $query, 'restriction_removed_at' ) ) { return array(); }
		if ( 1 !== preg_match( '/actor_id=([0-9]+)/', $query, $match ) ) { return array(); }
		if ( ! in_array( (int) $match[1], $this->restricted_actors, true ) ) { return array(); }
		return array( array( 'id' => '1' ) );
	}
}
class WP_User {
	public $ID;
	public function __construct( $id ) { $this->ID = (int) $id; }
}
function user_can( $user, $cap ) {
	$id = $user instanceof WP_User ? $user->ID : (int) $user;
	// Hermetic fixture: users 11 (issuer), 22 (actor), 33 (approver/admin) hold
	// product-management capability; user 99 (subscriber) does not.
	if ( in_array( $id, array( 11, 22, 33 ), true ) && in_array( $cap, array( 'manage_woocommerce', 'edit_products', 'manage_options' ), true ) ) { return true; }
	if ( 33 === $id && 'manage_options' === $cap ) { return true; }
	return false;
}
function wp_generate_uuid4() {
	return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
}
function __( $text, $domain = null ) { return $text; }
$GLOBALS['wl_option_values'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wl_option_values'] ) ? $GLOBALS['wl_option_values'][ $name ] : $default;
}

require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-price-decimal.php';
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-price-operation.php';
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-price-range.php';
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-safety-policy.php';
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-product-snapshot.php';
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-product-selector.php';
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-change-plan.php';

use WriteLeash\Change_Plan as Plan;
use WriteLeash\Plan_Hasher as Hasher;
use WriteLeash\Price_Decimal as Decimal;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Price_Store_Context as Store;
use WriteLeash\Product_Price_Snapshot as Snapshot;
use WriteLeash\Safety_Policy as Policy;

$assertions = 0;
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

// ---------------------------------------------------------------------------
// Snapshot helper: reflection-built immutable snapshots without Woo.
// ---------------------------------------------------------------------------
function snap( int $id, array $overrides = array() ): Snapshot {
	$base = array(
		'product_id' => $id, 'exists' => true, 'unreadable' => false, 'name' => 'P' . $id, 'sku' => 'SKU' . $id,
		'type' => 'simple', 'status' => 'publish', 'core_simple' => true,
		'core_variation' => false, 'parent_id' => 0, 'parent_type' => '', 'parent_status' => '', 'parent_core_variable' => false, 'variation_label' => '',
		'regular_price' => '100.00', 'sale_price' => '', 'sale_from' => null, 'sale_to' => null, 'category_ids' => array(),
	);
	$values = array_merge( $base, $overrides );
	$ref = new ReflectionClass( Snapshot::class );
	$obj = $ref->newInstanceWithoutConstructor();
	$prop = $ref->getProperty( 'values' );
	$prop->setAccessible( true );
	$prop->setValue( $obj, $values );
	return $obj;
}
function store(): Store {
	$ref = new ReflectionClass( Store::class );
	$obj = $ref->newInstanceWithoutConstructor();
	$prop = $ref->getProperty( 'values' );
	$prop->setAccessible( true );
	$prop->setValue( $obj, array( 'currency' => 'USD', 'price_decimals' => 2, 'wordpress_version' => '7.1.2', 'woocommerce_version' => '11.1.2', 'base_currency_context' => true ) );
	return $obj;
}
function plan_for( array $snapshots, int $actor = 22, $operation = null, $selection = null ): Plan {
	$ids = array_map( static fn( $s ) => $s->data()['product_id'], $snapshots );
	sort( $ids, SORT_NUMERIC );
	$operation = $operation ?: new Operation( Operation::SET, '80.00' );
	$selection = $selection ?: Selection::resolved( $ids, array() );
	return Plan::create( 'plan-' . implode( '-', $ids ) . '-' . bin2hex( random_bytes( 3 ) ), gmdate( 'Y-m-d\TH:i:s\Z' ), $actor, store(), $selection, $operation, new Policy( 1000, '100', '100', false, '100' ), $snapshots );
}

// Grant_Repository needs Job_Repository::PUBLIC_ID_REGEX const only for the
// regex; stub the class to avoid pulling wpdb-transport dependencies.
if ( ! class_exists( 'WriteLeash\Job_Repository' ) ) {
	eval( 'namespace WriteLeash; final class Job_Repository { public const PUBLIC_ID_REGEX = \'/\\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\\z/D\'; }' );
}
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-grant-schema.php';
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-grant-repository.php';

use WriteLeash\Grant_Repository as Grants;
use WriteLeash\Grant_Schema as GSchema;

// ---------------------------------------------------------------------------
// 1. Identity and approval.
// ---------------------------------------------------------------------------
$plan = plan_for( array( snap( 101 ), snap( 102 ) ), 22 );
eq( $plan->data()['actor_id'], 22, 'frozen actor is the plan actor' );
// Issuer must differ from actor; approver 0 with mode grant is not a person.
ok( 11 !== 22, 'issuer differs from actor in fixture' );
$grant_job = array( 'approval_mode' => 'grant', 'approver_id' => 0, 'creator_id' => 22, 'grant_public_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'grant_version' => 3, 'grant_idempotency_key' => 'key-1' );
$human_job = array( 'approval_mode' => 'human', 'approver_id' => 33, 'creator_id' => 22, 'grant_public_id' => '', 'grant_version' => 0, 'grant_idempotency_key' => '' );
$planned_job = array( 'approval_mode' => 'none', 'approver_id' => 0, 'creator_id' => 22, 'grant_public_id' => '', 'grant_version' => 0, 'grant_idempotency_key' => '' );
ok( null !== Grants::job_grant( $grant_job ), 'grant job carries grant pin' );
ok( null === Grants::job_grant( $human_job ), 'human job carries no grant pin' );
ok( null === Grants::job_grant( $planned_job ), 'unapproved PLANNED carries no grant pin' );
Grants::assert_job_grant_binding( $grant_job, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 3, 'key-1' );
Grants::assert_job_grant_binding( $grant_job, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 9, 'key-1' ); // version drift does not rewrite the pin
try { Grants::assert_job_grant_binding( $human_job, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 3, 'key-1' ); throw new RuntimeException( 'human job must not satisfy grant binding' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_BINDING_MISMATCH', 'human job binding mismatch' ); }
	try { Grants::assert_job_grant_binding( $grant_job, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 3, 'other-key' ); throw new RuntimeException( 'wrong key must mismatch' ); }
	catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_BINDING_MISMATCH', 'idempotency key mismatch' ); }
	$blank_key = $grant_job;
	$blank_key['grant_idempotency_key'] = '';
	try { Grants::assert_job_grant_binding( $blank_key, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 3, 'key-1' ); throw new RuntimeException( 'blank stored key must mismatch' ); }
	catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_BINDING_MISMATCH', 'blank idempotency key fails closed' ); }
$label = Grants::operator_label( $grant_job );
ok( false === strpos( $label, 'Approved by' ), 'grant copy never renders approver' );
ok( false !== strpos( $label, 'no human approval' ), 'grant copy names the mode' );
ok( false !== strpos( $label, 'actor #22' ), 'grant copy names the actor' );
eq( Grants::operator_label( $human_job ), 'Approved by user #33', 'human approver is a person' );
eq( Grants::operator_label( $planned_job ), 'Awaiting human approval', 'unapproved copy is not grant' );
marker( 'identity and approval modes stay distinct' );

// ---------------------------------------------------------------------------
// 2. Scope allow/deny.
// ---------------------------------------------------------------------------
eq( Grants::assert_scope( $plan ), 2, 'two changing simples in scope' );
// Unsupported type.
$bad_type = plan_for( array( snap( 201, array( 'core_simple' => false, 'type' => 'grouped' ) ) ), 22 );
try { Grants::assert_scope( $bad_type ); throw new RuntimeException( 'grouped must be denied' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_SCOPE_DENIED', 'unsupported type denied' ); }
// Draft status.
$bad_status = plan_for( array( snap( 202, array( 'status' => 'draft' ) ) ), 22 );
try { Grants::assert_scope( $bad_status ); throw new RuntimeException( 'draft must be denied' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_SCOPE_DENIED', 'draft denied' ); }
// Variation without published core variable parent.
$bad_parent = plan_for( array( snap( 203, array( 'core_simple' => false, 'core_variation' => true, 'type' => 'variation', 'parent_id' => 55, 'parent_core_variable' => false, 'parent_type' => 'variable', 'parent_status' => 'publish' ) ) ), 22 );
try { Grants::assert_scope( $bad_parent ); throw new RuntimeException( 'bad parent must be denied' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_SCOPE_DENIED', 'variation parent denied' ); }
// Eligible variation in scope.
$good_variation = snap( 204, array( 'core_simple' => false, 'core_variation' => true, 'type' => 'variation', 'parent_id' => 55, 'parent_core_variable' => true, 'parent_type' => 'variable', 'parent_status' => 'publish' ) );
$variation_plan = plan_for( array( $good_variation ), 22 );
eq( Grants::assert_scope( $variation_plan ), 1, 'eligible variation in scope' );
// Sale field with absolute target in scope; forged field denied via item check.
$sale_plan = plan_for( array( snap( 205, array( 'sale_price' => '70.00' ) ) ), 22, new Operation( Operation::SET, '60.00', Operation::FIELD_SALE ) );
eq( Grants::assert_scope( $sale_plan ), 1, 'sale absolute target in scope' );
// Per-item scope re-check (no selector/percentage rerun; frozen strings only).
Grants::assert_scope_for_item( $plan, 101 );
try { Grants::assert_scope_for_item( $bad_type, 201 ); throw new RuntimeException( 'per-item type must deny' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_SCOPE_DENIED', 'per-item type denied' ); }
// No write occurs here: scope validation is pure (zero Woo saves by construction).
marker( 'scope allow/deny for each boundary' );

// ---------------------------------------------------------------------------
// 3. Canonical idempotency payload.
// ---------------------------------------------------------------------------
$grant_id = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$payload_a = Grants::canonical_payload( $plan, $grant_id );
$payload_b = Grants::canonical_payload( $plan, $grant_id );
eq( $payload_a, $payload_b, 'same material is deterministic' );
eq( hash( 'sha256', $payload_a ), hash( 'sha256', $payload_b ), 'same key+same payload same hash' );
$other_plan = plan_for( array( snap( 101 ), snap( 103 ) ), 22 );
ok( Grants::canonical_payload( $other_plan, $grant_id ) !== $payload_a, 'different targets different payload' );
ok( Grants::canonical_payload( $plan, 'bbbbbbbb-bbbb-cccc-dddd-eeeeeeeeeeee' ) !== $payload_a, 'different grant different payload' );
ok( preg_match( Grants::IDEMPOTENCY_REGEX, 'key-1_ABC-09' ), 'key grammar accepts' );
ok( ! preg_match( Grants::IDEMPOTENCY_REGEX, 'key with spaces' ), 'key grammar rejects spaces' );
ok( ! preg_match( Grants::IDEMPOTENCY_REGEX, '' ), 'key grammar rejects empty' );
marker( 'canonical idempotency payload' );

// ---------------------------------------------------------------------------
// 4. Budget state machine (in-memory; mirrors the conditional UPDATEs).
// ---------------------------------------------------------------------------
class Fake_Grant_Ledger {
	public $max;
	public $consumed = 0;
	public $reserved = 0;
	public $reservations = array(); // "job|product" => RESERVED/CONSUMED/RELEASED
	public $keys = array(); // key => hash
	public function __construct( int $max ) { $this->max = $max; }
	public function reserve( int $job, array $products ): void {
		$needed = count( $products );
		if ( $this->consumed + $this->reserved + $needed > $this->max ) { throw new WriteLeash\Grant_Error( 'GRANT_BUDGET_EXHAUSTED' ); }
		foreach ( $products as $p ) {
			$k = $job . '|' . $p;
			if ( isset( $this->reservations[ $k ] ) ) { throw new WriteLeash\Grant_Error( 'GRANT_STORE_UNAVAILABLE' ); }
			$this->reservations[ $k ] = 'RESERVED';
		}
		$this->reserved += $needed;
	}
	public function consume( int $job, int $product ): void {
		$k = $job . '|' . $product;
		if ( ( $this->reservations[ $k ] ?? '' ) === 'CONSUMED' ) { return; } // exactly once
		if ( ( $this->reservations[ $k ] ?? '' ) !== 'RESERVED' ) { throw new WriteLeash\Grant_Error( 'GRANT_BUDGET_EXHAUSTED' ); }
		$this->reservations[ $k ] = 'CONSUMED';
		$this->reserved = max( 0, $this->reserved - 1 );
		++$this->consumed;
	}
	public function release( int $job, int $product ): void {
		$k = $job . '|' . $product;
		if ( ( $this->reservations[ $k ] ?? '' ) !== 'RESERVED' ) { return; }
		$this->reservations[ $k ] = 'RELEASED';
		$this->reserved = max( 0, $this->reserved - 1 );
	}
	public function accept_with_key( string $key, string $hash, int $job, array $products ): string {
		if ( isset( $this->keys[ $key ] ) ) {
			if ( $this->keys[ $key ]['hash'] !== $hash ) { throw new WriteLeash\Grant_Error( 'GRANT_IDEMPOTENCY_MISMATCH' ); }
			return 'REPLAYED';
		}
		$this->reserve( $job, $products );
		$this->keys[ $key ] = array( 'hash' => $hash, 'job' => $job );
		return 'ACCEPTED';
	}
}
$ledger = new Fake_Grant_Ledger( 2 );
eq( $ledger->accept_with_key( 'k1', 'h1', 1, array( 101 ) ), 'ACCEPTED', 'first acceptance reserves' );
eq( $ledger->reserved, 1, 'one reserved' );
eq( $ledger->accept_with_key( 'k1', 'h1', 1, array( 101 ) ), 'REPLAYED', 'same key+same payload replays' );
eq( $ledger->reserved, 1, 'replay creates no second reservation' );
try { $ledger->accept_with_key( 'k1', 'DIFFERENT', 2, array( 102 ) ); throw new RuntimeException( 'different payload must reject' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_IDEMPOTENCY_MISMATCH', 'same key+different payload rejected' ); }
// Last-unit contention: only one of two concurrent requests can reserve.
$ledger2 = new Fake_Grant_Ledger( 1 );
$ledger2->reserve( 10, array( 201 ) );
try { $ledger2->reserve( 11, array( 202 ) ); throw new RuntimeException( 'overbook must deny' ); }
catch ( WriteLeash\Grant_Error $e ) { eq( $e->reason(), 'GRANT_BUDGET_EXHAUSTED', 'concurrent last unit has one winner' ); }
eq( $ledger2->reserved, 1, 'denial reserves nothing more' );
// Consume exactly once, including retry/reconciliation.
$ledger->consume( 1, 101 );
eq( $ledger->consumed, 1, 'APPLIED consumes one unit' );
$ledger->consume( 1, 101 );
eq( $ledger->consumed, 1, 'retry/reconciliation never double-consumes' );
// Conflicts, failures, review-only and Undo never consume; definitive
// non-applied releases, ambiguous retains.
$ledger3 = new Fake_Grant_Ledger( 5 );
$ledger3->reserve( 20, array( 301, 302, 303 ) );
$ledger3->release( 20, 301 ); // CONFLICT
$ledger3->release( 20, 302 ); // definitive FAILED
// 303 stays RESERVED (ambiguous NEEDS_REVIEW) until reconciliation.
eq( $ledger3->consumed, 0, 'conflict/failure consume nothing' );
eq( $ledger3->reserved, 1, 'ambiguous retained, definitive released' );
$ledger3->consume( 20, 303 ); // reconciliation establishes APPLIED
eq( $ledger3->consumed, 1, 'reconciled APPLIED consumes once' );
eq( $ledger3->reserved, 0, 'no leaked reservation' );
marker( 'budget reserve/consume/release/retain + idempotency winner' );

// ---------------------------------------------------------------------------
// 5. Audit shape + global disable + price-write boundary.
// ---------------------------------------------------------------------------
$required_audit = array( 'actor_id', 'issuer_id', 'approver_id', 'approval_mode', 'grant', 'version', 'key', 'targets', 'before', 'after', 'reservation', 'outcome' );
$sample = array( 'actor_id' => 22, 'issuer_id' => 11, 'approver_id' => 0, 'approval_mode' => 'grant', 'grant' => $grant_id, 'version' => 3, 'key' => 'k1', 'targets' => array( 101 ), 'before' => '100.00', 'after' => '80.00', 'reservation' => 'RESERVED', 'outcome' => 'APPLIED' );
foreach ( $required_audit as $key ) { ok( array_key_exists( $key, $sample ), 'audit carries ' . $key ); }
// Global disable posture fails closed: only explicit '0' enables. Exercise
// the real repository predicate (not a copied expression) for absent,
// malformed, '0' and '1' stored values.
foreach ( array( array( '0', false ), array( '1', true ), array( '', true ), array( 'malformed', true ), array( null, true ), array( '0 ', true ) ) as $case ) {
	list( $value, $disabled ) = $case;
	if ( null === $value ) {
		unset( $GLOBALS['wl_option_values'][ GSchema::DISABLE_OPTION ] );
	} else {
		$GLOBALS['wl_option_values'][ GSchema::DISABLE_OPTION ] = $value;
	}
	eq( Grants::is_disabled(), $disabled, 'disable fails closed for ' . json_encode( $value ) );
}
// Price-write boundary: grant files add no new writers.
$root = dirname( __DIR__, 2 ) . '/writeleash';
$grant_sources = array(
	file_get_contents( $root . '/includes/free/class-grant-schema.php' ),
	file_get_contents( $root . '/includes/free/class-grant-repository.php' ),
);
foreach ( $grant_sources as $source ) {
	ok( false === strpos( $source, 'set_regular_price' ), 'no price setter in grant family' );
	ok( false === strpos( $source, 'set_sale_price' ), 'no sale setter in grant family' );
	ok( false === strpos( $source, 'update_post_meta(' ), 'no direct meta write in grant family' );
}
// Lock order is documented and enforced in code order: runner -> job ->
// grant -> journal -> Woo. Static tripwire over the mutator.
$mutator = file_get_contents( $root . '/includes/free/class-woo-price-mutator.php' );
$guard_pos = strpos( $mutator, '$guard->acquire' );
$grant_pos = strpos( $mutator, 'assert_grant_for_write' );
$journal_after_grant = $grant_pos !== false ? strpos( $mutator, 'Price_Apply_Journal::read', $grant_pos ) : false;
$consume_pos = strpos( $mutator, 'consume_in_tx' );
ok( $guard_pos !== false && $grant_pos !== false && $journal_after_grant !== false && $consume_pos !== false, 'grant hooks present' );
ok( $guard_pos < $grant_pos && $grant_pos < $journal_after_grant, 'lock order runner/job -> grant -> journal' );
ok( $consume_pos > $journal_after_grant, 'consume with journal APPLIED, never in save hook' );
	ok( false === strpos( $mutator, "add_action( 'woocommerce_before_product_object_save'" ), 'no budget in save hook' );
	ok( false === strpos( $mutator, 'catch ( \\Throwable $ignored )' ), 'grant evidence failure is not swallowed' );
marker( 'audit, disable fail-closed, boundary and lock order' );

// ---------------------------------------------------------------------------
// 6. #306: a non-grant price/stock/undo write must fail closed. The perimeter
// cache and an unreadable store must not authorize the frame.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return 0; }
}
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-price-apply-connection.php';
$cache = new ReflectionProperty( Grants::class, 'restricted_cache' );
$cache->setAccessible( true );
$store = new wpdb();
$GLOBALS['wpdb'] = $store;
$GLOBALS['wl_option_values']['writeleash_grant_schema'] = '2';
$store->restricted_actors = array( 22 );
// Stale fail-open cache says 22 is unrestricted and 33 is restricted. The
// fresh read is authoritative in both directions.
$cache->setValue( null, array( 22 => false, 33 => true, 0 => false ) );
ok( false === Grants::allows_controlled_write( 22, false ), 'stale unrestricted cache cannot authorize a restricted actor' );
ok( true === Grants::allows_controlled_write( 33, false ), 'stale restricted cache cannot deny a fresh unrestricted merchant' );
ok( true === Grants::allows_controlled_write( 22, true ), 'grant-authorized item still allowed' );
ok( true === Grants::allows_controlled_write( 0, false ), 'user 0 stays outside the leash' );
$grant_queries = 0;
foreach ( $store->queries as $sql ) { if ( false !== strpos( $sql, 'restriction_removed_at' ) ) { ++$grant_queries; } }
eq( $grant_queries, 2, 'grant-authorized and user 0 do not read the grant store' );
$cached = $cache->getValue( null );
eq( $cached[22], false, 'controlled write does not rewrite the perimeter cache' );
$store->queries = array();
$store->fail_reads = true;
$threw = '';
try { Grants::allows_controlled_write( 33, false ); }
catch ( WriteLeash\Price_Apply_Error $error ) { $threw = $error->getMessage(); }
eq( $threw, 'GRANT_STORE_UNAVAILABLE', 'unreadable grant store does not authorize a non-grant write' );
$store->fail_reads = false;
$store->queries = array();
unset( $GLOBALS['wl_option_values']['writeleash_grant_schema'] );
$store->table_count = 0;
ok( true === Grants::allows_controlled_write( 22, false ), 'proven-absent grant store leaves merchant writes available' );
$absent_reads = 0;
foreach ( $store->queries as $sql ) { if ( false !== strpos( $sql, 'restriction_removed_at' ) ) { ++$absent_reads; } }
eq( $absent_reads, 0, 'absent store does not classify rows' );
$store->queries = array();
$store->table_count = 1;
$partial = '';
try { Grants::allows_controlled_write( 22, false ); }
catch ( WriteLeash\Price_Apply_Error $error ) { $partial = $error->getMessage(); }
eq( $partial, 'GRANT_STORE_UNAVAILABLE', 'grants table without the schema option fails closed' );
$GLOBALS['wl_option_values']['writeleash_grant_schema'] = '2';
$cache->setValue( null, array() );
$repo = file_get_contents( $root . '/includes/free/class-grant-repository.php' );
ok( false !== strpos( $repo, 'FOR UPDATE' ), 'item-connection classification locks unstamped grant rows' );
ok( false === strpos( $repo, 'return ! self::is_restricted_actor' ), 'controlled write does not call the fail-open predicate' );
$create_at = strpos( $repo, 'function create_grant' );
$create_fn = false === $create_at ? '' : substr( $repo, $create_at, 2500 );
$user_lock_at = strpos( $create_fn, 'SELECT ID FROM {$tx->users} WHERE ID=%d FOR UPDATE' );
$insert_at = strpos( $create_fn, 'INSERT INTO %i' );
ok( false !== $user_lock_at && false !== $insert_at && $user_lock_at < $insert_at, 'create_grant locks the actor user row before the insert' );
$writers = array(
	'class-woo-price-mutator.php' => 'allows_controlled_write( (int) $actor, null !== $grant )',
	'class-woo-stock-mutator.php' => 'allows_controlled_write( (int) $actor, null !== $grant )',
	'class-woo-undo-mutator.php'  => 'allows_controlled_write( (int) $executor, false )',
);
foreach ( $writers as $file => $needle ) {
	$source = file_get_contents( $root . '/includes/free/' . $file );
	$call = strpos( $source, $needle );
	$open = strpos( $source, 'Perimeter_Guard::open_frame' );
	$decided = strpos( $source, "checkpoint( 'RESTRICTION_DECIDED'" );
	ok( false !== $call && false !== $open && $call < $open, $file . ' gates the frame' );
	ok( false !== $decided && $call < $decided && $decided < $open, $file . ' records the decision before the frame' );
	$window = substr( $source, max( 0, $call - 400 ), 400 );
	ok( false !== strpos( $window, '$tx->assert_owned()' ), $file . ' classifies inside the item transaction' );
	ok( false === strpos( $window, '$wpdb = $original' ), $file . ' does not classify on the request connection' );
}
marker( 'restricted actor non-grant write denied before the frame' );

echo "#298 grant-unit PASS ($assertions assertions)\n";
