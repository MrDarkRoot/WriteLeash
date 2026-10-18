<?php namespace BulkPriceEditor\History;

/**
 * CRUD over the runs and run-prices tables.
 *
 * Queries name the tables as {$wpdb->prefix}bpe_* inline: that is the one
 * interpolation the WordPress coding standards accept inside a prepared
 * statement, so every query stays auditable without suppressions. Only the
 * IN ( … ) lists need a generated placeholder list, and those are marked.
 */
class RunsRepository {

	/* ---------------------------------------------------------------------
	 * Runs
	 * ------------------------------------------------------------------- */

	public static function insertRun( array $data ): int {
		global $wpdb;

		$data = wp_parse_args( $data, array(
			'type'       => Run::TYPE_UPDATE,
			'status'     => Run::STATUS_RUNNING,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		) );

		$wpdb->insert( Schema::runsTable(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}

	public static function updateRun( int $runId, array $data ): void {
		global $wpdb;

		$wpdb->update( Schema::runsTable(), $data, array( 'id' => $runId ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function getRun( int $runId ): ?Run {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bpe_runs WHERE id = %d",
			$runId
		), ARRAY_A );

		return $row ? new Run( $row ) : null;
	}

	/**
	 * @param  bool|null  $scheduled  true: only scheduled runs (soonest first);
	 *                                false: past runs only; null: everything.
	 *
	 * @return Run[]
	 */
	public static function getRuns( int $limit = 20, int $offset = 0, ?bool $scheduled = null ): array {
		global $wpdb;

		if ( is_null( $scheduled ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bpe_runs ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit, $offset
			), ARRAY_A );
		} elseif ( $scheduled ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bpe_runs WHERE status = %s ORDER BY scheduled_at ASC, id ASC LIMIT %d OFFSET %d",
				Run::STATUS_SCHEDULED, $limit, $offset
			), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bpe_runs WHERE status != %s ORDER BY id DESC LIMIT %d OFFSET %d",
				Run::STATUS_SCHEDULED, $limit, $offset
			), ARRAY_A );
		}

		return array_map( function ( $row ) {
			return new Run( $row );
		}, (array) $rows );
	}

	public static function countRuns( ?bool $scheduled = null ): int {
		global $wpdb;

		if ( is_null( $scheduled ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bpe_runs" );
		}

		if ( $scheduled ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bpe_runs WHERE status = %s",
				Run::STATUS_SCHEDULED
			) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bpe_runs WHERE status != %s",
			Run::STATUS_SCHEDULED
		) );
	}

	/**
	 * Atomically add processed products and return the fresh run.
	 */
	public static function incrementProcessed( int $runId, int $by ): ?Run {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}bpe_runs SET processed = processed + %d WHERE id = %d",
			$by, $runId
		) );

		return self::getRun( $runId );
	}

	public static function deleteRun( int $runId ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::runsTable(), array( 'id' => $runId ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::runPricesTable(), array( 'run_id' => $runId ) );
	}

	/**
	 * Keep the history from growing forever: prune the oldest finished runs
	 * (and their snapshots) beyond the configured cap. Scheduled and running
	 * runs are never pruned.
	 */
	public static function pruneOldRuns(): void {
		global $wpdb;

		$maxRuns = (int) apply_filters( 'bulk_price_editor/history/max_runs', 50 );

		if ( $maxRuns < 1 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$staleIds = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}bpe_runs
			 WHERE status NOT IN ( %s, %s )
			 ORDER BY id DESC
			 LIMIT 100 OFFSET %d",
			Run::STATUS_SCHEDULED, Run::STATUS_RUNNING, $maxRuns
		) );

		foreach ( array_map( 'intval', (array) $staleIds ) as $staleId ) {
			self::deleteRun( $staleId );
		}
	}

	/* ---------------------------------------------------------------------
	 * Price snapshots
	 * ------------------------------------------------------------------- */

	public static function insertPriceRow( int $runId, int $productId, array $data ): void {
		global $wpdb;

		$data['run_id']     = $runId;
		$data['product_id'] = $productId;

		$wpdb->insert( Schema::runPricesTable(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function getPriceRow( int $runId, int $productId ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bpe_run_prices WHERE run_id = %d AND product_id = %d LIMIT 1",
			$runId, $productId
		), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Snapshot rows for a set of products of one run, keyed by product id.
	 */
	public static function getPriceRowsForProducts( int $runId, array $productIds ): array {
		global $wpdb;

		$productIds = array_values( array_filter( array_map( 'intval', $productIds ) ) );

		if ( empty( $productIds ) ) {
			return array();
		}

		$placeholders = self::placeholders( count( $productIds ) );

		// $placeholders is a generated "%d, %d, …" list; every id is an integer.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bpe_run_prices WHERE run_id = %d AND product_id IN ( {$placeholders} )",
			...array_merge( array( $runId ), $productIds )
		), ARRAY_A );
		// phpcs:enable

		$keyed = array();

		foreach ( (array) $rows as $row ) {
			$keyed[ (int) $row['product_id'] ] = $row;
		}

		return $keyed;
	}

	public static function getPriceRowsByIds( int $runId, array $rowIds ): array {
		global $wpdb;

		$rowIds = array_values( array_filter( array_map( 'intval', $rowIds ) ) );

		if ( empty( $rowIds ) ) {
			return array();
		}

		$placeholders = self::placeholders( count( $rowIds ) );

		// $placeholders is a generated "%d, %d, …" list; every id is an integer.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bpe_run_prices WHERE run_id = %d AND id IN ( {$placeholders} )",
			...array_merge( array( $runId ), $rowIds )
		), ARRAY_A );
		// phpcs:enable

		return (array) $rows;
	}

	public static function getPriceRows( int $runId, int $limit = 500, int $offset = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bpe_run_prices WHERE run_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
			$runId, $limit, $offset
		), ARRAY_A );

		return (array) $rows;
	}

	/**
	 * Ids of the snapshot rows where the run actually changed something —
	 * the rows a revert needs to restore.
	 */
	public static function getChangedRowIds( int $runId ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}bpe_run_prices
			 WHERE run_id = %d
			 AND ( new_regular IS NOT NULL OR new_sale IS NOT NULL
				OR new_sale_from IS NOT NULL OR new_sale_to IS NOT NULL )
			 ORDER BY id ASC",
			$runId
		) );

		return array_map( 'intval', (array) $ids );
	}

	public static function hasChangedRows( int $runId ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}bpe_run_prices
			 WHERE run_id = %d
			 AND ( new_regular IS NOT NULL OR new_sale IS NOT NULL
				OR new_sale_from IS NOT NULL OR new_sale_to IS NOT NULL )
			 LIMIT 1",
			$runId
		) );
	}

	public static function countPriceRows( int $runId ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bpe_run_prices WHERE run_id = %d",
			$runId
		) );
	}

	/**
	 * "%d, %d, …" for an IN ( … ) list of $count integers.
	 */
	protected static function placeholders( int $count ): string {
		return implode( ', ', array_fill( 0, max( 1, $count ), '%d' ) );
	}
}
