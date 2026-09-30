<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-owned durable job tables. Installation uses the normal WordPress
 * database connection and the plugin's existing table-creation rights only:
 * no secondary user, triggers, routines or manual SQL.
 *
 * Schema version 1. Unknown or partial schema state fails closed: the worker
 * then mutates zero products and persists a typed pause reason.
 */
final class Job_Schema {
	public const SCHEMA_VERSION = 1;
	public const OPTION = 'writeleash_job_schema';

	public static function jobs_table( \wpdb $db ): string {
		return self::identifier( $db->prefix . 'writeleash_jobs' );
	}
	public static function items_table( \wpdb $db ): string {
		return self::identifier( $db->prefix . 'writeleash_job_items' );
	}
	private static function identifier( string $name ): string {
		if ( ! preg_match( '/\A[a-zA-Z0-9_]+\z/D', $name ) ) { throw new Job_Error( 'SCHEMA_UNAVAILABLE' ); }
		return $name;
	}

	/** Replayable v1 install. Never drops or rewrites existing job evidence. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$stored = get_option( self::OPTION, null );
		if ( null !== $stored && (int) $stored !== self::SCHEMA_VERSION ) { throw new Job_Error( 'SCHEMA_UNAVAILABLE' ); }
		if ( null === $stored ) {
			$present = ( self::table_exists( $wpdb, self::jobs_table( $wpdb ) ) ? 1 : 0 ) + ( self::table_exists( $wpdb, self::items_table( $wpdb ) ) ? 1 : 0 );
			// A completely absent pair is a fresh install; one present table is partial/unknown state.
			if ( 1 === $present ) { throw new Job_Error( 'SCHEMA_UNAVAILABLE' ); }
		}
		$jobs = self::jobs_table( $wpdb );
		$items = self::items_table( $wpdb );
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $jobs (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 schema_version int unsigned NOT NULL,
 public_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 plan_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 plan_schema_version int unsigned NOT NULL,
 plan_hash_version varchar(64) NOT NULL,
 plan_hash char(64) NOT NULL,
 plan_json longtext NOT NULL,
 creator_id bigint unsigned NOT NULL,
 approver_id bigint unsigned NOT NULL DEFAULT 0,
 status varchar(32) NOT NULL,
 status_reason varchar(64) NOT NULL DEFAULT '',
 currency char(3) NOT NULL,
 price_decimals tinyint unsigned NOT NULL,
 wordpress_version varchar(24) NOT NULL,
 woocommerce_version varchar(24) NOT NULL,
 total_selected int unsigned NOT NULL DEFAULT 0,
 total_eligible int unsigned NOT NULL DEFAULT 0,
 total_changing int unsigned NOT NULL DEFAULT 0,
 total_unchanged int unsigned NOT NULL DEFAULT 0,
 total_unsupported int unsigned NOT NULL DEFAULT 0,
 total_blocked int unsigned NOT NULL DEFAULT 0,
 planned int unsigned NOT NULL DEFAULT 0,
 pending int unsigned NOT NULL DEFAULT 0,
 applying int unsigned NOT NULL DEFAULT 0,
 applied int unsigned NOT NULL DEFAULT 0,
 unchanged int unsigned NOT NULL DEFAULT 0,
 conflict int unsigned NOT NULL DEFAULT 0,
 failed int unsigned NOT NULL DEFAULT 0,
 needs_review int unsigned NOT NULL DEFAULT 0,
 unsupported int unsigned NOT NULL DEFAULT 0,
 lease_owner varchar(64) NOT NULL DEFAULT '',
 lease_generation bigint unsigned NOT NULL DEFAULT 0,
 lease_expires_at datetime NULL,
 created_at datetime NOT NULL,
 approved_at datetime NULL,
 queued_at datetime NULL,
 started_at datetime NULL,
 updated_at datetime NOT NULL,
 completed_at datetime NULL,
 paused_at datetime NULL,
 last_worker_at datetime NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY public_id (public_id),
 UNIQUE KEY plan_instance (plan_id)
) ENGINE=InnoDB " . $charset . ';' );
		dbDelta( "CREATE TABLE $items (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 schema_version int unsigned NOT NULL,
 job_id bigint unsigned NOT NULL,
 plan_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 product_id bigint unsigned NOT NULL,
 sequence int unsigned NOT NULL,
 plan_result varchar(16) NOT NULL,
 eligibility_state varchar(16) NOT NULL,
 eligibility_reason varchar(64) NULL,
 blockers longtext NOT NULL,
 warnings longtext NOT NULL,
 expected_price varchar(32) NOT NULL,
 planned_price varchar(32) NOT NULL,
 state varchar(24) NOT NULL,
 reason varchar(64) NOT NULL DEFAULT '',
 attempt_count int unsigned NOT NULL DEFAULT 0,
 claim_token char(36) NOT NULL DEFAULT '',
 claim_generation bigint unsigned NOT NULL DEFAULT 0,
 next_attempt_after datetime NULL,
 applied_at datetime NULL,
 last_attempt_at datetime NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY job_product (job_id,product_id),
 UNIQUE KEY job_sequence (job_id,sequence),
 KEY job_state_sequence (job_id,state,sequence)
) ENGINE=InnoDB " . $charset . ';' );
		self::assert_schema( $wpdb );
		update_option( self::OPTION, self::SCHEMA_VERSION, false );
	}

	/** The jobs table alone is enough to persist a typed pause; items may be missing. */
	public static function jobs_table_present( \wpdb $db ): bool {
		try { $table = self::jobs_table( $db ); }
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
		$jobs = self::jobs_table( $db );
		$items = self::items_table( $db );
		foreach ( array( $jobs, $items ) as $table ) {
			$engine = $db->get_var( $db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( 'InnoDB' !== $engine ) { throw new Job_Error( 'SCHEMA_UNAVAILABLE' ); }
		}
		$job_columns = array( 'id', 'schema_version', 'public_id', 'plan_id', 'plan_schema_version', 'plan_hash_version', 'plan_hash', 'plan_json', 'creator_id', 'approver_id', 'status', 'status_reason', 'currency', 'price_decimals', 'wordpress_version', 'woocommerce_version', 'planned', 'pending', 'applying', 'applied', 'unchanged', 'conflict', 'failed', 'needs_review', 'unsupported', 'lease_owner', 'lease_generation', 'created_at', 'updated_at' );
		$item_columns = array( 'id', 'schema_version', 'job_id', 'plan_id', 'product_id', 'sequence', 'plan_result', 'eligibility_state', 'expected_price', 'planned_price', 'state', 'reason', 'attempt_count', 'claim_token', 'claim_generation', 'created_at', 'updated_at' );
		self::assert_columns( $db, $jobs, $job_columns );
		self::assert_columns( $db, $items, $item_columns );
		self::assert_indexes( $db, $jobs, array( 'public_id' => array( array( 'public_id' ), true ), 'plan_instance' => array( array( 'plan_id' ), true ) ) );
		self::assert_indexes( $db, $items, array( 'job_product' => array( array( 'job_id', 'product_id' ), true ), 'job_sequence' => array( array( 'job_id', 'sequence' ), true ), 'job_state_sequence' => array( array( 'job_id', 'state', 'sequence' ), false ) ) );
		foreach ( array( $jobs, $items ) as $table ) {
			$collation = $db->get_var( $db->prepare( "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME='plan_id'", $table ) );
			if ( 'ascii_bin' !== $collation ) { throw new Job_Error( 'SCHEMA_UNAVAILABLE' ); }
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
			if ( ! isset( $found[$column] ) || 'NO' !== $found[$column] ) { throw new Job_Error( 'SCHEMA_UNAVAILABLE' ); }
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
			if ( ! isset( $indexes[$name] ) ) { throw new Job_Error( 'SCHEMA_UNAVAILABLE' ); }
			$rows = $indexes[$name];
			usort( $rows, static fn( $a, $b ) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
			if ( array_column( $rows, 'Column_name' ) !== $columns || array_unique( array_column( $rows, 'Non_unique' ) ) !== array( $unique ? '0' : '1' ) ) { throw new Job_Error( 'SCHEMA_UNAVAILABLE' ); }
		}
	}
}
