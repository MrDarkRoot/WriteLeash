<?php namespace BulkPriceEditor\History;

/**
 * Custom tables for the update history: one row per bulk update run and one
 * row per product snapshot (the prices before and after the run). Snapshots
 * are what make one-click revert and scheduled auto-revert possible.
 *
 * Note: tables are intentionally NOT dropped on uninstall — history must
 * survive switching between the free and premium versions.
 */
class Schema {

	const DB_VERSION = '2';
	const DB_VERSION_OPTION = 'bpe_db_version';

	public static function runsTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'bpe_runs';
	}

	public static function runPricesTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'bpe_run_prices';
	}

	public static function priceLogTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'bpe_price_log';
	}

	/**
	 * Create/upgrade the tables when the stored schema version is behind.
	 * Cheap check (autoloaded option), safe to call on every request.
	 */
	public static function maybeUpgrade(): void {

		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charsetCollate = $wpdb->get_charset_collate();

		$runsTable      = self::runsTable();
		$runPricesTable = self::runPricesTable();

		dbDelta( "CREATE TABLE {$runsTable} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT 'update',
			status varchar(20) NOT NULL DEFAULT 'pending',
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			modifier_type varchar(64) NOT NULL DEFAULT '',
			modifier_args longtext NULL,
			filters longtext NULL,
			total int(11) NOT NULL DEFAULT 0,
			processed int(11) NOT NULL DEFAULT 0,
			created_at datetime NULL,
			scheduled_at datetime NULL,
			revert_at datetime NULL,
			started_at datetime NULL,
			completed_at datetime NULL,
			reverted_at datetime NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charsetCollate};" );

		dbDelta( "CREATE TABLE {$runPricesTable} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			old_regular varchar(60) NULL,
			old_sale varchar(60) NULL,
			new_regular varchar(60) NULL,
			new_sale varchar(60) NULL,
			old_sale_from varchar(32) NULL,
			old_sale_to varchar(32) NULL,
			new_sale_from varchar(32) NULL,
			new_sale_to varchar(32) NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY run_product (run_id,product_id)
		) {$charsetCollate};" );

		$priceLogTable = self::priceLogTable();

		dbDelta( "CREATE TABLE {$priceLogTable} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			changed_at datetime NULL,
			old_regular varchar(60) NULL,
			new_regular varchar(60) NULL,
			old_sale varchar(60) NULL,
			new_sale varchar(60) NULL,
			source varchar(32) NOT NULL DEFAULT '',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY product_date (product_id,changed_at)
		) {$charsetCollate};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}
}
