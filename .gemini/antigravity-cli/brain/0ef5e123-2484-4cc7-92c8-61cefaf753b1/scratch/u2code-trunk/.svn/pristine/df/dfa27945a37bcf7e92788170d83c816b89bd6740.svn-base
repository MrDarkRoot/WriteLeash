<?php namespace BulkPriceEditor\History;

/**
 * CRUD over the per-product price log. One row per price field change: the
 * old/new columns are filled only for the field that changed (the other pair
 * stays NULL), '' means "no price".
 *
 * Queries name the table as {$wpdb->prefix}bpe_price_log inline: that is the
 * one interpolation the WordPress coding standards accept inside a prepared
 * statement. Only the IN ( … ) lists need a generated placeholder list, and
 * those are marked.
 */
class PriceLogRepository {

	public static function isEnabled(): bool {
		return (bool) apply_filters( 'bulk_price_editor/price_log/enabled',
			'yes' === get_option( 'bpe_price_log_enabled', 'yes' ) );
	}

	/**
	 * How many entries to keep per product.
	 */
	public static function maxEntriesPerProduct(): int {

		$max = (int) get_option( 'bpe_price_log_max_per_product', 100 );

		return max( 1, (int) apply_filters( 'bulk_price_editor/price_log/max_entries_per_product', $max ) );
	}

	public static function insert( array $data ): int {
		global $wpdb;

		$data = wp_parse_args( $data, array(
			'changed_at' => gmdate( 'Y-m-d H:i:s' ),
		) );

		$wpdb->insert( Schema::priceLogTable(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$id = (int) $wpdb->insert_id;

		if ( ! empty( $data['product_id'] ) ) {
			self::prune( (int) $data['product_id'] );
		}

		return $id;
	}

	/**
	 * Newest entries for a set of products (a variable product and its
	 * variations are viewed together).
	 */
	public static function getForProducts( array $productIds, int $limit = 15, int $offset = 0 ): array {
		global $wpdb;

		$productIds = array_values( array_filter( array_map( 'intval', $productIds ) ) );

		if ( empty( $productIds ) ) {
			return array();
		}

		$placeholders = self::placeholders( count( $productIds ) );

		// $placeholders is a generated "%d, %d, …" list; every id is an integer.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bpe_price_log WHERE product_id IN ( {$placeholders} ) ORDER BY id DESC LIMIT %d OFFSET %d",
			...array_merge( $productIds, array( $limit, $offset ) )
		), ARRAY_A );
		// phpcs:enable

		return (array) $rows;
	}

	/**
	 * Per-product counts memoized for the request — the preview table primes
	 * them for a whole page in one grouped query instead of one per row.
	 */
	protected static array $primedCounts = array();

	public static function primeCounts( array $productIds ): void {
		global $wpdb;

		$productIds = array_values( array_filter( array_map( 'intval', $productIds ) ) );

		if ( empty( $productIds ) ) {
			return;
		}

		foreach ( $productIds as $productId ) {
			self::$primedCounts[ $productId ] = 0;
		}

		$placeholders = self::placeholders( count( $productIds ) );

		// $placeholders is a generated "%d, %d, …" list; every id is an integer.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT product_id, COUNT(*) AS total FROM {$wpdb->prefix}bpe_price_log WHERE product_id IN ( {$placeholders} ) GROUP BY product_id",
			...$productIds
		), ARRAY_A );
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			self::$primedCounts[ (int) $row['product_id'] ] = (int) $row['total'];
		}
	}

	public static function countForProduct( int $productId ): int {

		if ( ! isset( self::$primedCounts[ $productId ] ) ) {
			self::$primedCounts[ $productId ] = self::countForProducts( array( $productId ) );
		}

		return self::$primedCounts[ $productId ];
	}

	public static function countForProducts( array $productIds ): int {
		global $wpdb;

		$productIds = array_values( array_filter( array_map( 'intval', $productIds ) ) );

		if ( empty( $productIds ) ) {
			return 0;
		}

		$placeholders = self::placeholders( count( $productIds ) );

		// $placeholders is a generated "%d, %d, …" list; every id is an integer.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bpe_price_log WHERE product_id IN ( {$placeholders} )",
			...$productIds
		) );
		// phpcs:enable

		return (int) $count;
	}

	/**
	 * Keep only the newest entries of one product (protects stores where a
	 * repricing tool rewrites prices constantly): find the oldest entry that
	 * still fits under the cap and drop everything older than it.
	 */
	public static function prune( int $productId ): void {
		global $wpdb;

		$max = self::maxEntriesPerProduct();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$oldestKeptId = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}bpe_price_log WHERE product_id = %d ORDER BY id DESC LIMIT 1 OFFSET %d",
			$productId, $max - 1
		) );

		if ( ! $oldestKeptId ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}bpe_price_log WHERE product_id = %d AND id < %d",
			$productId, $oldestKeptId
		) );
	}

	/**
	 * A deleted product's log has nothing to attach to any more.
	 */
	public static function deleteForProduct( int $productId ): void {
		global $wpdb;

		$wpdb->delete( Schema::priceLogTable(), array( 'product_id' => $productId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * "%d, %d, …" for an IN ( … ) list of $count integers.
	 */
	protected static function placeholders( int $count ): string {
		return implode( ', ', array_fill( 0, max( 1, $count ), '%d' ) );
	}
}
