<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-owned durable Undo tables. Installation uses the normal WordPress
 * database connection and the plugin's existing table-creation rights only:
 * no secondary user, triggers, routines or manual SQL.
 *
 * Apply history is never rewritten: the #108 journal row and the #109 job
 * item row stay APPLIED forever. Undo is a new durable action recorded here.
 *
 * Schema version 1. Unknown or partial schema state fails closed: the Undo
 * worker then mutates zero products and persists a typed pause reason.
 */
final class Undo_Schema {
	public const SCHEMA_VERSION = 1;
	public const OPTION = 'writeleash_undo_schema';
	public const SETUP_OPTION = 'writeleash_undo_setup';

	public static function operations_table( \wpdb $db ): string {
		return self::identifier( $db->prefix . 'writeleash_undo_operations' );
	}
	public static function items_table( \wpdb $db ): string {
		return self::identifier( $db->prefix . 'writeleash_undo_items' );
	}
	private static function identifier( string $name ): string {
		if ( ! preg_match( '/\A[a-zA-Z0-9_]+\z/D', $name ) ) { throw new Undo_Error( 'SCHEMA_UNAVAILABLE' ); }
		return $name;
	}

	/**
	 * Explicit Free ownership allowlist. uninstall.php may delete exactly these
	 * option names; durable tables are retained through uninstall as operator
	 * lifecycle (the reviewed uninstall SQL classifier forbids DDL there), so
	 * no worker can lose its journal while still holding mutation authority.
	 */
	public static function owned_options(): array {
		return array( self::OPTION, self::SETUP_OPTION );
	}

	/** Replayable v1 install. Never drops or rewrites existing Undo evidence. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$stored = get_option( self::OPTION, null );
		if ( null !== $stored && (int) $stored !== self::SCHEMA_VERSION ) { throw new Undo_Error( 'SCHEMA_UNAVAILABLE' ); }
		if ( null === $stored ) {
			$present = ( self::table_exists( $wpdb, self::operations_table( $wpdb ) ) ? 1 : 0 ) + ( self::table_exists( $wpdb, self::items_table( $wpdb ) ) ? 1 : 0 );
			// A completely absent pair is a fresh install; one present table is partial/unknown state.
			if ( 1 === $present ) { throw new Undo_Error( 'SCHEMA_UNAVAILABLE' ); }
		}
		$operations = self::operations_table( $wpdb );
		$items = self::items_table( $wpdb );
		$charset = Durable_Charset::table_clause( $wpdb );
		Durable_Charset::migrate( $wpdb, $operations );
		Durable_Charset::migrate( $wpdb, $items );
		dbDelta( "CREATE TABLE $operations (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 schema_version int unsigned NOT NULL,
 job_id bigint unsigned NOT NULL,
 plan_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 public_id char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 initiator_id bigint unsigned NOT NULL,
 status varchar(32) NOT NULL,
 status_reason varchar(64) NOT NULL DEFAULT '',
 undo_eligible int unsigned NOT NULL DEFAULT 0,
 undo_pending int unsigned NOT NULL DEFAULT 0,
 undo_applying int unsigned NOT NULL DEFAULT 0,
 undone int unsigned NOT NULL DEFAULT 0,
 undo_conflict int unsigned NOT NULL DEFAULT 0,
 undo_failed int unsigned NOT NULL DEFAULT 0,
 undo_needs_review int unsigned NOT NULL DEFAULT 0,
 lease_owner varchar(64) NOT NULL DEFAULT '',
 lease_generation bigint unsigned NOT NULL DEFAULT 0,
 lease_expires_at datetime NULL,
 last_worker_at datetime NULL,
 created_at datetime NOT NULL,
 started_at datetime NULL,
 updated_at datetime NOT NULL,
 completed_at datetime NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY undo_job (job_id),
 UNIQUE KEY undo_public_id (public_id)
) ENGINE=InnoDB " . $charset . ';' );
		dbDelta( "CREATE TABLE $items (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 schema_version int unsigned NOT NULL,
 job_id bigint unsigned NOT NULL,
 undo_id bigint unsigned NOT NULL,
 plan_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 product_id bigint unsigned NOT NULL,
 sequence int unsigned NOT NULL,
 expected_price varchar(32) NOT NULL,
 applied_price varchar(32) NOT NULL,
 apply_attempt_id varchar(36) NOT NULL,
 state varchar(24) NOT NULL,
 reason varchar(64) NOT NULL DEFAULT '',
 attempt_id varchar(36) NOT NULL DEFAULT '',
 attempt_count int unsigned NOT NULL DEFAULT 0,
 claim_token char(36) NOT NULL DEFAULT '',
 claim_generation bigint unsigned NOT NULL DEFAULT 0,
 next_attempt_after datetime NULL,
 provenance longtext NOT NULL,
 fingerprint char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 evidence longtext NOT NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 undone_at datetime NULL,
 last_attempt_at datetime NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY undo_job_product (job_id,product_id),
 KEY undo_operation_state (undo_id,state,product_id)
) ENGINE=InnoDB " . $charset . ';' );
		Price_Apply_Connection::forget_table_metadata( $wpdb, $operations );
		Price_Apply_Connection::forget_table_metadata( $wpdb, $items );
		self::assert_schema( $wpdb );
		update_option( self::OPTION, self::SCHEMA_VERSION, false );
	}

	/** The operations table alone is enough to persist a typed pause; items may be missing. */
	public static function operations_table_present( \wpdb $db ): bool {
		try { $table = self::operations_table( $db ); }
		catch ( \Throwable $error ) { return false; }
		return 1 === (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
	}

	/** Installed version and verified shape. Anything else mutates zero products. */
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
		$operations = self::operations_table( $db );
		$items = self::items_table( $db );
		foreach ( array( $operations, $items ) as $table ) {
			$engine = $db->get_var( $db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( 'InnoDB' !== $engine ) { throw new Undo_Error( 'SCHEMA_UNAVAILABLE' ); }
		}
		$operation_columns = array( 'id', 'schema_version', 'job_id', 'plan_id', 'public_id', 'initiator_id', 'status', 'status_reason', 'undo_eligible', 'undo_pending', 'undo_applying', 'undone', 'undo_conflict', 'undo_failed', 'undo_needs_review', 'lease_owner', 'lease_generation', 'created_at', 'updated_at' );
		$item_columns = array( 'id', 'schema_version', 'job_id', 'undo_id', 'plan_id', 'product_id', 'sequence', 'expected_price', 'applied_price', 'apply_attempt_id', 'state', 'reason', 'attempt_id', 'attempt_count', 'claim_token', 'claim_generation', 'provenance', 'fingerprint', 'evidence', 'created_at', 'updated_at' );
		self::assert_columns( $db, $operations, $operation_columns );
		self::assert_columns( $db, $items, $item_columns );
		self::assert_indexes( $db, $operations, array( 'undo_job' => array( array( 'job_id' ), true ), 'undo_public_id' => array( array( 'public_id' ), true ) ) );
		self::assert_indexes( $db, $items, array( 'undo_job_product' => array( array( 'job_id', 'product_id' ), true ), 'undo_operation_state' => array( array( 'undo_id', 'state', 'product_id' ), false ) ) );
		try {
			Durable_Charset::assert_table( $db, $operations, array( 'plan_id', 'public_id' ), true );
			Durable_Charset::assert_table( $db, $items, array( 'plan_id', 'fingerprint' ), true );
		} catch ( \Throwable $error ) { throw new Undo_Error( 'SCHEMA_UNAVAILABLE' ); }
		foreach ( array( $operations, $items ) as $table ) {
			$collation = $db->get_var( $db->prepare( "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME='plan_id'", $table ) );
			if ( ! in_array( $collation, array( 'ascii_bin', 'utf8mb4_bin' ), true ) ) { throw new Undo_Error( 'SCHEMA_UNAVAILABLE' ); }
		}
	}

	private static function table_exists( \wpdb $db, string $table ): bool {
		return (bool) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
	}
	private static function assert_columns( \wpdb $db, string $table, array $required ): void {
		$rows = $db->get_results( $db->prepare( 'SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ), ARRAY_A );
		$found = array();
		foreach ( $rows as $row ) { $found[ $row['COLUMN_NAME'] ] = $row['IS_NULLABLE']; }
		foreach ( $required as $column ) {
			if ( ! isset( $found[$column] ) || 'NO' !== $found[$column] ) { throw new Undo_Error( 'SCHEMA_UNAVAILABLE' ); }
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
			if ( ! isset( $indexes[$name] ) ) { throw new Undo_Error( 'SCHEMA_UNAVAILABLE' ); }
			$rows = $indexes[$name];
			usort( $rows, static function ( $a, $b ) { return (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index']; } );
			if ( array_column( $rows, 'Column_name' ) !== $columns || array_unique( array_column( $rows, 'Non_unique' ) ) !== array( $unique ? '0' : '1' ) ) { throw new Undo_Error( 'SCHEMA_UNAVAILABLE' ); }
		}
	}
}
