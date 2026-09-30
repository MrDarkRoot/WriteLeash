<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** Disposable item journal for #108; no scheduler, approval endpoint or job lifecycle. */
final class Price_Apply_Journal {
	public const SCHEMA_VERSION = 1;
	public static function table( \wpdb $db ): string {
		$name = $db->prefix . 'writeleash_price_items';
		if ( ! preg_match( '/\A[a-zA-Z0-9_]+\z/D', $name ) ) { throw new Price_Apply_Error( 'TRANSACTION_UNAVAILABLE' ); }
		return $name;
	}
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table( $wpdb );
		dbDelta( "CREATE TABLE $table (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 schema_version int unsigned NOT NULL,
 plan_hash char(64) NOT NULL,
 product_id bigint unsigned NOT NULL,
 plan_json longtext NOT NULL,
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
 UNIQUE KEY plan_product (plan_hash,product_id)
) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';' );
		update_option( 'writeleash_price_journal_schema', self::SCHEMA_VERSION, false );
	}
	/** Trusted frozen-plan fixture only. Approval persistence belongs to #109/#111. */
	public static function seed( Change_Plan $plan ): void {
		global $wpdb;
		if ( 'BLOCKED' === $plan->data()['status'] ) { throw new Price_Apply_Error( 'PLAN_POLICY_BLOCKED' ); }
		$table = self::table( $wpdb );
		foreach ( $plan->data()['items'] as $item ) {
			if ( 'CHANGING' !== $item['result'] ) { continue; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A custom journal INSERT has no WordPress object API; durable journal authority cannot use object cache. All identifiers and values are prepared.
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO %i (schema_version,plan_hash,product_id,plan_json,expected_price,target_price,state,evidence,created_at,updated_at) VALUES (%d,%s,%d,%s,%s,%s,'PENDING','',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=id", $table, self::SCHEMA_VERSION, $plan->hash(), $item['product_id'], $plan->json(), $item['expected_regular_price'], $item['planned_regular_price'] ) ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
		}
	}
	public static function read( \wpdb $db, string $hash, int $id, bool $lock = false ): ?array {
		$table = self::table( $db );
		$row = $db->get_row( $db->prepare( "SELECT * FROM %i WHERE plan_hash=%s AND product_id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $table, $hash, $id ), ARRAY_A );
		return $row ?: null;
	}
	public static function transition( \wpdb $db, string $hash, int $id, string $state, string $reason, string $attempt, string $evidence = '' ): void {
		$table = self::table( $db );
		if ( 1 !== $db->query( $db->prepare( "UPDATE %i SET state=%s,reason=%s,attempt_id=%s,evidence=%s,updated_at=UTC_TIMESTAMP(),applied_at=IF(%s='APPLIED',UTC_TIMESTAMP(),NULL) WHERE plan_hash=%s AND product_id=%d", $table, $state, $reason, $attempt, $evidence, $state, $hash, $id ) ) ) { throw new Price_Apply_Error( 'JOURNAL_MISMATCH' ); }
	}
}
