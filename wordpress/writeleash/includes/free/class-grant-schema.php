<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** Typed grant orchestration failure. Machine reason codes are stable; display copy is separate. */
final class Grant_Error extends \RuntimeException {
	private string $reason;
	public function __construct( string $reason ) {
		$this->reason = $reason;
		parent::__construct( $reason );
	}
	public function reason(): string { return $this->reason; }
}

/**
 * #298 durable standing-grant tables.
 *
 * Separate from Change_Plan, the job row and the apply journal. Installation
 * uses the normal WordPress connection and existing plugin-level table rights
 * only: no secondary user, triggers, routines or manual SQL.
 *
 * Schema version 2. Version 1 installs upgrade in place: dbDelta adds the
 * per-principal #309 restriction-removal marker to the grants table without
 * dropping or rewriting existing grant evidence. Unknown/partial state fails
 * closed: grant acceptance and grant-bound item execution mutate zero
 * products and persist a typed denial.
 *
 * Tables (per site, InnoDB):
 * - `<prefix>writeleash_grants`: one row per standing grant (budget, expiry,
 *   revocation, actor/issuer binding, version, restriction-removal marker).
 * - `<prefix>writeleash_grant_reservations`: one row per (grant, job, product)
 *   with RESERVED/CONSUMED/RELEASED lifecycle.
 * - `<prefix>writeleash_grant_idempotency`: durable (grant, key) -> accepted
 *   job/plan binding with canonical payload hash.
 * - `<prefix>writeleash_grant_audit`: append-only event log (no updates).
 */
final class Grant_Schema {
	public const SCHEMA_VERSION = 2;
	public const OPTION = 'writeleash_grant_schema';
	public const SETUP_OPTION = 'writeleash_grant_setup';
	/** Durable global kill-switch option. `1` means restricted writes are disabled. */
	public const DISABLE_OPTION = 'writeleash_grants_disabled';

	public static function grants_table( \wpdb $db ): string {
		return self::identifier( $db->prefix . 'writeleash_grants' );
	}
	public static function reservations_table( \wpdb $db ): string {
		return self::identifier( $db->prefix . 'writeleash_grant_reservations' );
	}
	public static function idempotency_table( \wpdb $db ): string {
		return self::identifier( $db->prefix . 'writeleash_grant_idempotency' );
	}
	public static function audit_table( \wpdb $db ): string {
		return self::identifier( $db->prefix . 'writeleash_grant_audit' );
	}
	private static function identifier( string $name ): string {
		if ( ! preg_match( '/\A[a-zA-Z0-9_]+\z/D', $name ) ) { throw new Grant_Error( 'SCHEMA_UNAVAILABLE' ); }
		return $name;
	}

	/**
	 * Replayable install. Version 1 stores upgrade in place; the install never
	 * drops or rewrites existing grant evidence, and a stored version newer
	 * than this code fails closed instead of guessing.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$stored = get_option( self::OPTION, null );
		if ( null !== $stored ) {
			$stored_version = (int) $stored;
			if ( $stored_version < 1 || $stored_version > self::SCHEMA_VERSION ) { throw new Grant_Error( 'SCHEMA_UNAVAILABLE' ); }
		} else {
			$present = 0;
			foreach ( array( self::grants_table( $wpdb ), self::reservations_table( $wpdb ), self::idempotency_table( $wpdb ), self::audit_table( $wpdb ) ) as $table ) {
				if ( self::table_exists( $wpdb, $table ) ) { ++$present; }
			}
			// Completely absent family is a fresh install; a partial family is unknown state.
			if ( 0 !== $present && 4 !== $present ) { throw new Grant_Error( 'SCHEMA_UNAVAILABLE' ); }
		}
		$grants = self::grants_table( $wpdb );
		$reservations = self::reservations_table( $wpdb );
		$idempotency = self::idempotency_table( $wpdb );
		$audit = self::audit_table( $wpdb );
		$charset = Durable_Charset::table_clause( $wpdb );
		foreach ( array( $grants, $reservations, $idempotency, $audit ) as $table ) {
			Durable_Charset::migrate( $wpdb, $table );
		}
		dbDelta( "CREATE TABLE $grants (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 schema_version int unsigned NOT NULL,
 public_id char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 version int unsigned NOT NULL DEFAULT 1,
 issuer_id bigint unsigned NOT NULL,
 actor_id bigint unsigned NOT NULL,
 budget_max int unsigned NOT NULL,
 budget_consumed int unsigned NOT NULL DEFAULT 0,
 budget_reserved int unsigned NOT NULL DEFAULT 0,
 expires_at datetime NOT NULL,
 status varchar(16) NOT NULL DEFAULT 'active',
 revoke_reason varchar(64) NOT NULL DEFAULT '',
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 revoked_at datetime NULL,
 restriction_removed_at datetime NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY grant_public (public_id),
 KEY grant_actor (actor_id),
 KEY grant_issuer (issuer_id)
) ENGINE=InnoDB " . $charset . ';' );
		dbDelta( "CREATE TABLE $reservations (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 schema_version int unsigned NOT NULL,
 grant_db_id bigint unsigned NOT NULL,
 job_id bigint unsigned NOT NULL,
 plan_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 product_id bigint unsigned NOT NULL,
 state varchar(16) NOT NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY grant_job_product (grant_db_id,job_id,product_id),
 KEY grant_state (grant_db_id,state)
) ENGINE=InnoDB " . $charset . ';' );
		dbDelta( "CREATE TABLE $idempotency (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 schema_version int unsigned NOT NULL,
 grant_db_id bigint unsigned NOT NULL,
 idempotency_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 issuer_id bigint unsigned NOT NULL,
 canonical_hash char(64) NOT NULL,
 job_id bigint unsigned NOT NULL,
 plan_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY grant_key (grant_db_id,idempotency_key)
) ENGINE=InnoDB " . $charset . ';' );
		dbDelta( "CREATE TABLE $audit (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 schema_version int unsigned NOT NULL,
 grant_db_id bigint unsigned NOT NULL,
 grant_version int unsigned NOT NULL,
 job_id bigint unsigned NOT NULL DEFAULT 0,
 product_id bigint unsigned NOT NULL DEFAULT 0,
 plan_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
 actor_id bigint unsigned NOT NULL DEFAULT 0,
 issuer_id bigint unsigned NOT NULL DEFAULT 0,
 approver_id bigint unsigned NOT NULL DEFAULT 0,
 approval_mode varchar(16) NOT NULL DEFAULT '',
 idempotency_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
 event varchar(64) NOT NULL,
 details longtext NOT NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY grant_event (grant_db_id,event),
 KEY grant_job (grant_db_id,job_id,product_id)
) ENGINE=InnoDB " . $charset . ';' );
		foreach ( array( $grants, $reservations, $idempotency, $audit ) as $table ) {
			Price_Apply_Connection::forget_table_metadata( $wpdb, $table );
		}
		self::assert_schema( $wpdb );
		update_option( self::OPTION, self::SCHEMA_VERSION, false );
		// Durable global disable defaults to enabled grants (`0`). Absent
		// option fails closed for grant writes (treated as uncertain).
		if ( false === get_option( self::DISABLE_OPTION, false ) ) {
			add_option( self::DISABLE_OPTION, '0', '', false );
		}
		update_option( self::SETUP_OPTION, 'READY', false );
	}

	public static function grants_table_present( \wpdb $db ): bool {
		try { $table = self::grants_table( $db ); }
		catch ( \Throwable $error ) { return false; }
		return 1 === (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
	}

	/** Installed version and verified shape. Anything else denies grant writes. */
	public static function ready( \wpdb $db ): bool {
		try {
			$option = get_option( self::OPTION, null );
			if ( null === $option || (int) $option !== self::SCHEMA_VERSION ) { return false; }
			self::assert_schema( $db );
			return true;
		} catch ( \Throwable $error ) { return false; }
	}

	/** Strict structural verification. Version authority is `ready()`. */
	public static function assert_schema( \wpdb $db ): void {
		$grants = self::grants_table( $db );
		$reservations = self::reservations_table( $db );
		$idempotency = self::idempotency_table( $db );
		$audit = self::audit_table( $db );
		foreach ( array( $grants, $reservations, $idempotency, $audit ) as $table ) {
			$engine = $db->get_var( $db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( 'InnoDB' !== $engine ) { throw new Grant_Error( 'SCHEMA_UNAVAILABLE' ); }
		}
		self::assert_columns( $db, $grants, array( 'id', 'schema_version', 'public_id', 'version', 'issuer_id', 'actor_id', 'budget_max', 'budget_consumed', 'budget_reserved', 'expires_at', 'status', 'created_at', 'updated_at' ) );
		// #309: the restriction-removal marker must physically exist before the
		// restricted-principal predicate is trusted; an unstamped read would
		// otherwise fail open for every principal.
		self::assert_present_columns( $db, $grants, array( 'restriction_removed_at' ) );
		self::assert_columns( $db, $reservations, array( 'id', 'schema_version', 'grant_db_id', 'job_id', 'plan_id', 'product_id', 'state', 'created_at', 'updated_at' ) );
		self::assert_columns( $db, $idempotency, array( 'id', 'schema_version', 'grant_db_id', 'idempotency_key', 'issuer_id', 'canonical_hash', 'job_id', 'plan_id', 'created_at' ) );
		self::assert_columns( $db, $audit, array( 'id', 'schema_version', 'grant_db_id', 'grant_version', 'event', 'details', 'created_at' ) );
		self::assert_indexes( $db, $grants, array( 'grant_public' => array( array( 'public_id' ), true ) ) );
		self::assert_indexes( $db, $reservations, array( 'grant_job_product' => array( array( 'grant_db_id', 'job_id', 'product_id' ), true ) ) );
		self::assert_indexes( $db, $idempotency, array( 'grant_key' => array( array( 'grant_db_id', 'idempotency_key' ), true ) ) );
	}

	private static function table_exists( \wpdb $db, string $table ): bool {
		return (bool) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
	}
	private static function assert_columns( \wpdb $db, string $table, array $required ): void {
		$rows = $db->get_results( $db->prepare( 'SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ), ARRAY_A );
		$found = array();
		foreach ( $rows as $row ) { $found[ $row['COLUMN_NAME'] ] = $row['IS_NULLABLE']; }
		foreach ( $required as $column ) {
			if ( ! isset( $found[$column] ) || 'NO' !== $found[$column] ) { throw new Grant_Error( 'SCHEMA_UNAVAILABLE' ); }
		}
	}
	/** Presence check for nullable columns (marker/evidence columns may be NULL). */
	private static function assert_present_columns( \wpdb $db, string $table, array $required ): void {
		$rows = $db->get_results( $db->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ), ARRAY_A );
		$found = array();
		foreach ( $rows as $row ) { $found[ $row['COLUMN_NAME'] ] = true; }
		foreach ( $required as $column ) {
			if ( ! isset( $found[$column] ) ) { throw new Grant_Error( 'SCHEMA_UNAVAILABLE' ); }
		}
	}
	/** @param array<string, array{0: string[], 1: bool}> $expected */
	private static function assert_indexes( \wpdb $db, string $table, array $expected ): void {
		$indexes = array();
		foreach ( $db->get_results( $db->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A ) as $row ) {
			$indexes[ $row['Key_name'] ][] = $row;
		}
		foreach ( $expected as $name => $shape ) {
			list( $columns, $unique ) = $shape;
			if ( ! isset( $indexes[$name] ) ) { throw new Grant_Error( 'SCHEMA_UNAVAILABLE' ); }
			$rows = $indexes[$name];
			usort( $rows, static fn( $a, $b ) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
			if ( array_column( $rows, 'Column_name' ) !== $columns || array_unique( array_column( $rows, 'Non_unique' ) ) !== array( $unique ? '0' : '1' ) ) { throw new Grant_Error( 'SCHEMA_UNAVAILABLE' ); }
		}
	}
}
