<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-durable-charset.php';

/** Disposable item journal for #108; no scheduler, approval endpoint or job lifecycle. */
final class Price_Apply_Journal {
	public const SCHEMA_VERSION = 3;
	public static function table( \wpdb $db ): string {
		$name = $db->prefix . 'writeleash_price_items';
		if ( ! preg_match( '/\A[a-zA-Z0-9_]+\z/D', $name ) ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		return $name;
	}
	/** Detect incomplete upgrades before any item is claimed or seeded. */
	public static function assert_schema( \wpdb $db, bool $allow_legacy = true ): void {
		$table = self::table( $db );
		$index = $db->get_results( $db->prepare( "SHOW INDEX FROM %i WHERE Key_name='plan_instance_product'", $table ), ARRAY_A );
		if ( 2 !== count( $index ) || array_column( $index, 'Column_name' ) !== array( 'plan_id', 'product_id' ) || array_column( $index, 'Non_unique' ) !== array( '0', '0' ) || $db->get_results( $db->prepare( "SHOW INDEX FROM %i WHERE Key_name='plan_product'", $table ) ) ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		$collation = $db->get_var( $db->prepare( "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME='plan_id'", $table ) );
		$required = $db->get_var( $db->prepare( "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME IN ('plan_id','plan_schema_version','plan_hash_version') AND IS_NULLABLE='NO'", $table ) );
		try { Durable_Charset::assert_table( $db, $table, array( 'plan_id' ), true ); }
		catch ( \Throwable $error ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		if ( 3 !== (int) $required || ! in_array( $collation, array( 'ascii_bin', 'utf8mb4_bin' ), true ) ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		$compact = (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME IN ('plan_fingerprint','price_field') AND IS_NULLABLE='NO'", $table ) );
		$legacy_json = $db->get_var( $db->prepare( "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME='plan_json'", $table ) );
		if ( 2 === $compact && 'YES' === $legacy_json ) { return; }
		// Already-approved v2 work remains readable before explicit setup upgrades it.
		if ( $allow_legacy && 0 === $compact && 'NO' === $legacy_json ) { return; }
		throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' );
	}
	/** Branch-local v1 upgrade. Preserve history; never derive identity from a hash. */
	private static function upgrade_v1( \wpdb $db ): void {
		$table = self::table( $db );
		$exists = $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( ! $exists ) { return; }
		$column = $db->get_var( $db->prepare( "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME='plan_id'", $table ) );
		if ( ! $column && false === $db->query( $db->prepare( 'ALTER TABLE %i ADD plan_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL, ADD plan_schema_version int unsigned NULL, ADD plan_hash_version varchar(64) NULL', $table ) ) ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		foreach ( $db->get_results( $db->prepare( 'SELECT * FROM %i WHERE schema_version=1', $table ), ARRAY_A ) as $row ) {
			$plan = json_decode( $row['plan_json'], true );
			if ( ! is_array( $plan ) || ! preg_match( '/\A[a-zA-Z0-9_-]{1,64}\z/D', $plan['plan_id'] ?? '' ) || ( $plan['schema_version'] ?? null ) !== Change_Plan::SCHEMA_VERSION || ( $plan['hash_version'] ?? '' ) !== Change_Plan::HASH_VERSION || ( $plan['plan_hash'] ?? '' ) !== $row['plan_hash'] ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			$material = $plan;
			unset( $material['plan_id'], $material['created_at'], $material['plan_hash'], $material['summary'] );
			if ( Plan_Hasher::hash( $material ) !== $row['plan_hash'] ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			$items = array_filter( $plan['items'] ?? array(), static function ( $item ) use ( $row ) { return ( $item['product_id'] ?? 0 ) === (int) $row['product_id']; } );
			$item = reset( $items );
			if ( 1 !== count( $items ) || $item['expected_regular_price'] !== $row['expected_price'] || $item['planned_regular_price'] !== $row['target_price'] ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			$update = array( 'schema_version' => 2, 'plan_id' => $plan['plan_id'], 'plan_schema_version' => $plan['schema_version'], 'plan_hash_version' => $plan['hash_version'] );
			if ( '' !== $row['evidence'] || 'APPLIED' === $row['state'] ) {
				$evidence = json_decode( $row['evidence'], true );
				if ( ! is_array( $evidence ) || ( $evidence['plan_hash'] ?? '' ) !== $row['plan_hash'] || ( $evidence['product_id'] ?? 0 ) !== (int) $row['product_id'] || ( $evidence['target'] ?? '' ) !== $row['target_price'] || ( $evidence['attempt_id'] ?? '' ) !== $row['attempt_id'] ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
				$update['evidence'] = Plan_Hasher::canonical_json( array_merge( $evidence, array( 'plan_id' => $plan['plan_id'], 'plan_schema_version' => $plan['schema_version'], 'plan_hash_version' => $plan['hash_version'] ) ) );
			}
			if ( 1 !== $db->update( $table, $update, array( 'id' => $row['id'], 'schema_version' => 1 ) ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
		}
		// dbDelta compares column types/defaults, not NULL constraints; tighten explicitly.
		$nullable = $db->get_var( $db->prepare( "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME IN ('plan_id','plan_schema_version','plan_hash_version') AND IS_NULLABLE='YES'", $table ) );
		if ( $nullable && false === $db->query( $db->prepare( 'ALTER TABLE %i MODIFY plan_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, MODIFY plan_schema_version int unsigned NOT NULL, MODIFY plan_hash_version varchar(64) NOT NULL', $table ) ) ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		// dbDelta adds indexes but does not remove obsolete ones.
		if ( $db->get_results( $db->prepare( "SHOW INDEX FROM %i WHERE Key_name='plan_product'", $table ) ) && false === $db->query( $db->prepare( 'ALTER TABLE %i DROP INDEX plan_product', $table ) ) ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
	}
	/**
	 * Compact only validated job-backed v2 rows. Preserve orphaned primitive
	 * evidence verbatim. One plan/row is read at a time, in job -> journal lock
	 * order; any inconsistent material rolls back the entire data migration.
	 */
	private static function compact_legacy( \wpdb $db ): void {
		$table = self::table( $db );
		$engine = $db->get_var( $db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( 'InnoDB' !== $engine ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		if ( (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i WHERE schema_version NOT IN (2,3)', $table ) ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
		if ( $db->get_var( $db->prepare( "SELECT id FROM %i WHERE schema_version=2 AND plan_id='' LIMIT 1", $table ) ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
		$jobs = Job_Schema::jobs_table( $db );
		$has_jobs = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $jobs ) );
		if ( $has_jobs && ! Job_Schema::ready( $db ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
		$tx = new Price_Apply_Connection( $db );
		try {
			$tx->begin();
			$after_plan = '';
			while ( null !== ( $plan_id = $tx->get_var( $tx->prepare( 'SELECT plan_id FROM %i WHERE schema_version=2 AND plan_id>%s ORDER BY plan_id LIMIT 1', $table, $after_plan ) ) ) ) {
				$job = $has_jobs ? $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE plan_id=%s FOR UPDATE', $jobs, $plan_id ), ARRAY_A ) : null;
				$plan = $job ? Job_Repository::hydrate_plan( $job ) : null;
				$json = $plan ? $plan->json() : null;
				$fingerprint = $json ? hash( 'sha256', $json ) : null;
				$after_id = 0;
				while ( $row = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE plan_id=%s AND schema_version=2 AND id>%d ORDER BY id LIMIT 1 FOR UPDATE', $table, $plan_id, $after_id ), ARRAY_A ) ) {
					if ( null === $plan ) {
						$stored = json_decode( (string) $row['plan_json'], true );
						if ( ! is_array( $stored ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
						$plan = Change_Plan::hydrate( $stored );
						$json = $plan->json();
						$fingerprint = hash( 'sha256', $json );
					}
					self::assert_material( $row, $plan, (int) $row['product_id'], $json, $fingerprint );
					if ( 'BLOCKED' === $plan->data()['status'] || 'CHANGING' !== $plan->item( (int) $row['product_id'] )->data()['result'] ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
					self::assert_legacy_evidence( $row, $plan );
					if ( $job && 1 !== $tx->update( $table, array( 'schema_version' => self::SCHEMA_VERSION, 'plan_fingerprint' => $fingerprint, 'price_field' => $plan->price_field(), 'plan_json' => null ), array( 'id' => $row['id'], 'schema_version' => 2 ) ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
					$after_id = (int) $row['id'];
				}
				$after_plan = $plan_id;
			}
			$tx->commit();
		} catch ( \Throwable $error ) {
			$tx->rollback();
			throw new Price_Apply_Error( 'JOURNAL_MISMATCH' );
		} finally { $tx->rollback(); }
	}
	private static function assert_legacy_evidence( array $row, Change_Plan $plan ): void {
		if ( '' === $row['evidence'] && 'APPLIED' !== $row['state'] ) { return; }
		$evidence = json_decode( $row['evidence'], true );
		$data = $plan->data();
		if ( ! is_array( $evidence ) || ( $evidence['plan_id'] ?? '' ) !== $data['plan_id'] || ( $evidence['plan_schema_version'] ?? null ) !== $data['schema_version'] || ( $evidence['plan_hash_version'] ?? '' ) !== $data['hash_version'] || ( $evidence['plan_hash'] ?? '' ) !== $plan->hash() || ( $evidence['product_id'] ?? null ) !== (int) $row['product_id'] || ( $evidence['target'] ?? null ) !== $row['target_price'] || ( $evidence['attempt_id'] ?? '' ) !== $row['attempt_id'] || ( $evidence['price_field'] ?? Price_Operation::FIELD_REGULAR ) !== $plan->price_field() ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
	}
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table( $wpdb );
		Durable_Charset::migrate( $wpdb, $table );
		self::upgrade_v1( $wpdb );
		$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( $exists ) {
			$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
			$changes = array( 'MODIFY plan_json longtext NULL' );
			if ( ! in_array( 'plan_fingerprint', $columns, true ) ) { $changes[] = "ADD plan_fingerprint char(64) NOT NULL DEFAULT ''"; }
			if ( ! in_array( 'price_field', $columns, true ) ) { $changes[] = "ADD price_field varchar(16) NOT NULL DEFAULT ''"; }
			if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ', $table ) . implode( ', ', $changes ) ) ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
			Price_Apply_Connection::forget_table_metadata( $wpdb, $table );
			self::compact_legacy( $wpdb );
		}
		dbDelta( "CREATE TABLE $table (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 schema_version int unsigned NOT NULL,
 plan_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 plan_schema_version int unsigned NOT NULL,
 plan_hash_version varchar(64) NOT NULL,
 plan_hash char(64) NOT NULL,
 product_id bigint unsigned NOT NULL,
 plan_fingerprint char(64) NOT NULL,
 price_field varchar(16) NOT NULL,
 plan_json longtext NULL,
 expected_price varchar(32) NOT NULL,
 target_price varchar(32) NOT NULL,
 state varchar(24) NOT NULL,
 attempt_id varchar(36) NOT NULL DEFAULT '',
 reason varchar(64) NOT NULL DEFAULT '',
 evidence longtext NOT NULL,
 created_at datetime NOT NULL,
 updated_at datetime NOT NULL,
 applied_at datetime NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY plan_instance_product (plan_id,product_id)
) ENGINE=InnoDB " . Durable_Charset::table_clause( $wpdb ) . ';' );
		Price_Apply_Connection::forget_table_metadata( $wpdb, $table );
		self::assert_schema( $wpdb, false );
		update_option( 'writeleash_price_journal_schema', self::SCHEMA_VERSION, false );
	}
	/** Identity locates a row; all immutable material must independently match. */
	public static function assert_binding( array $row, Change_Plan $plan, int $id ): void {
		$json = $plan->json();
		self::assert_material( $row, $plan, $id, $json, hash( 'sha256', $json ) );
	}
	private static function assert_material( array $row, Change_Plan $plan, int $id, string $json, string $fingerprint ): void {
		$data = $plan->data();
		$item = $plan->item( $id )->data();
		$schema = (int) $row['schema_version'];
		$material_matches = 2 === $schema ? ( $row['plan_json'] ?? null ) === $json : ( self::SCHEMA_VERSION === $schema && ( $row['plan_json'] ?? null ) === null && ( $row['plan_fingerprint'] ?? null ) === $fingerprint && ( $row['price_field'] ?? null ) === $plan->price_field() );
		if ( ! $material_matches || $row['plan_id'] !== $data['plan_id'] || (int) $row['product_id'] !== $id || (int) $row['plan_schema_version'] !== Change_Plan::SCHEMA_VERSION || (int) $row['plan_schema_version'] !== $data['schema_version'] || $row['plan_hash_version'] !== Change_Plan::HASH_VERSION || $row['plan_hash_version'] !== $data['hash_version'] || $row['plan_hash'] !== $plan->hash() || $row['expected_price'] !== $item['expected_regular_price'] || $row['target_price'] !== $item['planned_regular_price'] ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
	}
	/** Trusted frozen-plan fixture only. Approval persistence belongs to #109/#111. */
	public static function seed( Change_Plan $plan ): void {
		global $wpdb;
		if ( 'BLOCKED' === $plan->data()['status'] ) { throw new Price_Apply_Error( 'PLAN_POLICY_BLOCKED' ); }
		self::assert_schema( $wpdb, false );
		$table = self::table( $wpdb );
		$data = $plan->data();
		$json = $plan->json();
		$fingerprint = hash( 'sha256', $json );
		$field = $plan->price_field();
		foreach ( $data['items'] as $item ) {
			if ( 'CHANGING' !== $item['result'] ) { continue; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom journal INSERT requires durable DB authority. The duplicate is verified below; identifiers and values are prepared.
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO %i (schema_version,plan_id,plan_schema_version,plan_hash_version,plan_hash,product_id,plan_fingerprint,price_field,expected_price,target_price,state,evidence,created_at,updated_at) VALUES (%d,%s,%d,%s,%s,%d,%s,%s,%s,%s,'PENDING','',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=id", $table, self::SCHEMA_VERSION, $data['plan_id'], Change_Plan::SCHEMA_VERSION, Change_Plan::HASH_VERSION, $plan->hash(), $item['product_id'], $fingerprint, $field, $item['expected_regular_price'], $item['planned_regular_price'] ) ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			$row = self::read( $wpdb, $data['plan_id'], $item['product_id'] );
			if ( ! $row ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
			// No immutable field or execution state is overwritten by a duplicate seed.
			self::assert_material( $row, $plan, $item['product_id'], $json, $fingerprint );
		}
	}
	public static function read( \wpdb $db, string $plan_id, int $id, bool $lock = false ): ?array {
		$table = self::table( $db );
		$row = $db->get_row( $db->prepare( "SELECT * FROM %i WHERE plan_id=%s AND product_id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $table, $plan_id, $id ), ARRAY_A );
		return $row ?: null;
	}
	public static function transition( \wpdb $db, string $plan_id, int $id, string $state, string $reason, string $attempt, string $evidence = '' ): void {
		$table = self::table( $db );
		if ( 1 !== $db->query( $db->prepare( "UPDATE %i SET state=%s,reason=%s,attempt_id=%s,evidence=%s,updated_at=UTC_TIMESTAMP(),applied_at=IF(%s='APPLIED',UTC_TIMESTAMP(),NULL) WHERE plan_id=%s AND product_id=%d", $table, $state, $reason, $attempt, $evidence, $state, $plan_id, $id ) ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
	}
}
