<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Durable job repository. This is the only place job/item SQL and domain
 * transitions live. Action Scheduler and object caches are never authority.
 *
 * Lease model: `acquire_lease()` atomically increments `lease_generation` with
 * a conditional UPDATE. A worker's fence is `(lease_owner, lease_generation)`.
 * Item claims are separate CAS updates; the #108 journal row lock remains the
 * final mutation serialization, so a stale worker that slipped past a fence
 * check can only converge through the journal (ALREADY_APPLIED), never
 * double-apply a product.
 */
final class Job_Repository {
	public const LEASE_TTL = 60;
	public const MAX_ATTEMPTS = 3;
	public const RETRY_BACKOFF = 5;
	public const PAGE_LIMIT = 100;
	public const PUBLIC_ID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D';

	/**
	 * Idempotent setup for the default supported path. Fresh installs create
	 * plugin-owned tables with normal WordPress privileges; unknown/partial
	 * schema state fails closed with a typed reason and zero job execution.
	 */
	private static function ensure_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		try {
			if ( ! Job_Schema::ready( $wpdb ) ) { Job_Schema::install(); }
			if ( ! self::journal_ready( $wpdb ) ) { Price_Apply_Journal::install(); }
			Job_Schema::assert_schema( $wpdb );
			Price_Apply_Journal::assert_schema( $wpdb );
			update_option( 'writeleash_job_setup', 'READY', false );
		} catch ( \Throwable $error ) {
			$reason = $error instanceof Job_Error ? $error->reason() : ( $error instanceof Price_Apply_Error ? $error->getMessage() : 'SCHEMA_UNAVAILABLE' );
			try { update_option( 'writeleash_job_setup', $reason, false ); } catch ( \Throwable $inner ) {}
			throw $error instanceof Job_Error ? $error : new Job_Error( 'SCHEMA_UNAVAILABLE' );
		}
	}
	private static function journal_ready( \wpdb $db ): bool {
		try { $table = Price_Apply_Journal::table( $db ); }
		catch ( \Throwable $error ) { return false; }
		$exists = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( 1 !== $exists ) { return false; }
		try { Price_Apply_Journal::assert_schema( $db ); return true; }
		catch ( \Throwable $error ) { return false; }
	}

	/** Idempotent import of a trusted #107 plan. No selector/planner/arithmetic call. */
	public static function create_from_plan( Change_Plan $plan, int $creator_id ): array {
		global $wpdb;
		self::ensure_schema();
		if ( $creator_id < 1 ) { throw new Job_Error( 'PERMISSION_DENIED' ); }
		$data = $plan->data();
		if ( (int) $data['actor_id'] !== $creator_id ) { throw new Job_Error( 'PERMISSION_DENIED' ); }
		$original = $wpdb;
		$tx = null;
		try {
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			$existing = self::read_by_plan( $tx, $data['plan_id'], true );
			if ( $existing ) {
				self::assert_binding( $existing, $plan );
				self::assert_items_complete( $tx, (int) $existing['id'], $plan );
				$tx->commit();
				$wpdb = $original;
				return self::read( (int) $existing['id'] );
			}
			$jobs = Job_Schema::jobs_table( $tx );
			$status = 'BLOCKED' === $data['status'] ? Job_State::BLOCKED : Job_State::PLANNED;
			$reason = 'BLOCKED' === $data['status'] ? 'BLOCKED_BY_POLICY' : '';
			$summary = $data['summary'];
			$store = $data['store'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom import INSERT requires durable DB authority; identifiers and values are prepared.
			if ( 1 !== $tx->query( $tx->prepare(
				"INSERT INTO %i (schema_version,public_id,plan_id,plan_schema_version,plan_hash_version,plan_hash,plan_json,creator_id,approver_id,status,status_reason,currency,price_decimals,wordpress_version,woocommerce_version,total_selected,total_eligible,total_changing,total_unchanged,total_unsupported,total_blocked,planned,pending,unchanged,unsupported,created_at,updated_at) VALUES (%d,%s,%s,%d,%s,%s,%s,%d,0,%s,%s,%s,%d,%s,%s,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
				$jobs, Job_Schema::SCHEMA_VERSION, wp_generate_uuid4(), $data['plan_id'], Change_Plan::SCHEMA_VERSION, Change_Plan::HASH_VERSION, $plan->hash(), $plan->json(), $creator_id, $status, $reason, $store['currency'], (int) $store['price_decimals'], $store['wordpress_version'], $store['woocommerce_version'],
				(int) $summary['selected'], (int) $summary['eligible'], (int) $summary['changing'], (int) $summary['unchanged'], (int) $summary['unsupported'], (int) $summary['blocked'], (int) $summary['selected'], (int) $summary['changing'], (int) $summary['unchanged'], (int) $summary['unsupported']
			) ) ) {
				throw new Job_Error( 'IMPORT_FAILED' );
			}
			$job_id = (int) $tx->insert_id;
			if ( $job_id < 1 ) { throw new Job_Error( 'IMPORT_FAILED' ); }
			self::insert_items( $tx, $job_id, $plan );
			self::assert_items_complete( $tx, $job_id, $plan );
			self::refresh_counters( $tx, $job_id );
			$tx->commit();
			$wpdb = $original;
			return self::read( $job_id );
		} catch ( \Throwable $error ) {
			if ( $tx && $tx->owns_attempt() ) { $tx->rollback(); }
			$wpdb = $original;
			$existing = self::read_by_plan( $original, $data['plan_id'], false );
			if ( $existing ) {
				self::assert_binding( $existing, $plan );
				return $existing;
			}
			throw $error instanceof Job_Error ? $error : new Job_Error( 'IMPORT_FAILED' );
		} finally {
			$wpdb = $original;
			if ( $tx ) { $tx->rollback(); }
		}
	}

	private static function insert_items( \wpdb $tx, int $job_id, Change_Plan $plan ): void {
		$items_table = Job_Schema::items_table( $tx );
		$sequence = 0;
		foreach ( $plan->data()['items'] as $item ) {
			++$sequence;
			$expected = $item['expected_regular_price'] ?? '';
			$planned = $item['planned_regular_price'] ?? '';
			$state = Job_Item_State::initial_for_result( $item['result'] );
			$reason = 'PENDING' === $state ? '' : ( 'UNCHANGED' === $state ? 'PLAN_UNCHANGED' : (string) ( $item['eligibility']['reason'] ?? 'PLAN_UNSUPPORTED' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom idempotent INSERT requires durable DB authority; identifiers and values are prepared.
			if ( false === $tx->query( $tx->prepare(
				"INSERT INTO %i (schema_version,job_id,plan_id,product_id,sequence,plan_result,eligibility_state,eligibility_reason,blockers,warnings,expected_price,planned_price,state,reason,created_at,updated_at) VALUES (%d,%d,%s,%d,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=id",
				$items_table, Job_Schema::SCHEMA_VERSION, $job_id, $plan->data()['plan_id'], (int) $item['product_id'], $sequence, $item['result'], $item['eligibility']['state'], $item['eligibility']['reason'], Plan_Hasher::canonical_json( $item['blockers'] ), Plan_Hasher::canonical_json( $item['warnings'] ), $expected, $planned, $state, $reason
			) ) ) {
				throw new Job_Error( 'IMPORT_FAILED' );
			}
		}
	}

	/** Every frozen plan item must exist exactly once; partial import is not executable. */
	private static function assert_items_complete( \wpdb $db, int $job_id, Change_Plan $plan ): void {
		$items_table = Job_Schema::items_table( $db );
		$rows = $db->get_results( $db->prepare( 'SELECT product_id, sequence FROM %i WHERE job_id=%d ORDER BY sequence ASC', $items_table, $job_id ), ARRAY_A );
		$expected = $plan->data()['resolved_product_ids'];
		$sequence = 0;
		foreach ( $rows as $row ) {
			if ( ++$sequence !== (int) $row['sequence'] || ! isset( $expected[$sequence - 1] ) || (int) $row['product_id'] !== $expected[$sequence - 1] ) { throw new Job_Error( 'IMPORT_INCOMPLETE' ); }
		}
		if ( count( $rows ) !== count( $expected ) ) { throw new Job_Error( 'IMPORT_INCOMPLETE' ); }
	}

	/** Server-owned approval transition: PLANNED -> READY, with the item journal seeded. */
	public static function approve( int $job_id, int $approver_id ): array {
		global $wpdb;
		self::ensure_schema();
		if ( $approver_id < 1 ) { throw new Job_Error( 'PERMISSION_DENIED' ); }
		$original = $wpdb;
		$tx = null;
		$committed = false;
		try {
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			$wpdb = $tx;
			$jobs = Job_Schema::jobs_table( $tx );
			$job = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', $jobs, $job_id ), ARRAY_A );
			if ( ! $job ) { throw new Job_Error( 'JOB_NOT_FOUND' ); }
			if ( Job_State::PLANNED !== $job['status'] ) {
				$tx->rollback();
				$wpdb = $original;
				if ( Job_State::is_terminal( $job['status'] ) ) { throw new Job_Error( 'JOB_TERMINAL' ); }
				return $job;
			}
			$plan = self::hydrate_plan( $job );
			// Journal seed shares this transaction: a partial approval is not executable.
			Price_Apply_Journal::seed( $plan );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fenced CAS transition; values are prepared.
			if ( 1 !== $tx->query( $tx->prepare( "UPDATE %i SET status=%s,status_reason=%s,approver_id=%d,approved_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=%d AND status=%s", $jobs, Job_State::READY, 'APPROVED', $approver_id, $job_id, Job_State::PLANNED ) ) ) {
				throw new Job_Error( 'INVALID_TRANSITION' );
			}
			$tx->commit();
			$committed = true;
			$wpdb = $original;
			return self::read( $job_id );
		} catch ( \Throwable $error ) {
			$wpdb = $original;
			if ( ! $committed && $tx && $tx->owns_attempt() ) { $tx->rollback(); }
			// A lost acknowledgement may still have committed; re-read before failing.
			$job = self::read( $job_id );
			if ( $job && in_array( $job['status'], array( Job_State::READY, Job_State::QUEUED, Job_State::RUNNING, Job_State::PAUSED, Job_State::NEEDS_REVIEW ), true ) ) { return $job; }
			throw $error instanceof Job_Error ? $error : new Job_Error( 'APPROVAL_FAILED' );
		} finally {
			$wpdb = $original;
			if ( $tx ) { $tx->rollback(); }
		}
	}

	public static function read( int $job_id ): ?array {
		global $wpdb;
		return self::read_by_column( $wpdb, 'id', $job_id );
	}
	public static function read_by_public_id( string $public_id ): ?array {
		if ( ! preg_match( self::PUBLIC_ID_REGEX, $public_id ) ) { return null; }
		return self::read_by_column( self::db(), 'public_id', $public_id );
	}
	private static function read_by_column( \wpdb $db, string $column, $value ): ?array {
		if ( ! in_array( $column, array( 'id', 'public_id', 'plan_id' ), true ) ) { throw new Job_Error( 'JOB_NOT_FOUND' ); }
		$table = Job_Schema::jobs_table( $db );
		$row = $db->get_row( $db->prepare( "SELECT * FROM %i WHERE $column=%s", $table, $value ), ARRAY_A );
		return $row ?: null;
	}
	private static function read_by_plan( \wpdb $db, string $plan_id, bool $lock ): ?array {
		$table = Job_Schema::jobs_table( $db );
		$row = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE plan_id=%s' . ( $lock ? ' FOR UPDATE' : '' ), $table, $plan_id ), ARRAY_A );
		return $row ?: null;
	}
	private static function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/** Authoritative stored material vs the supplied frozen plan. */
	public static function assert_binding( array $job, Change_Plan $plan ): void {
		$data = $plan->data();
		if ( (int) $job['schema_version'] !== Job_Schema::SCHEMA_VERSION || $job['plan_id'] !== $data['plan_id'] || $job['plan_hash'] !== $plan->hash() || $job['plan_json'] !== $plan->json() ||
			(int) $job['plan_schema_version'] !== Change_Plan::SCHEMA_VERSION || $job['plan_hash_version'] !== Change_Plan::HASH_VERSION || (int) $job['creator_id'] !== (int) $data['actor_id'] ) {
			throw new Job_Error( 'JOB_MATERIAL_MISMATCH' );
		}
		$store = $data['store'];
		if ( $job['currency'] !== $store['currency'] || (int) $job['price_decimals'] !== (int) $store['price_decimals'] || $job['wordpress_version'] !== $store['wordpress_version'] || $job['woocommerce_version'] !== $store['woocommerce_version'] ) {
			throw new Job_Error( 'JOB_MATERIAL_MISMATCH' );
		}
	}

	/** Rehydrate stored canonical material and independently verify the record binding. */
	public static function hydrate_plan( array $job ): Change_Plan {
		$data = json_decode( (string) $job['plan_json'], true );
		if ( ! is_array( $data ) ) { throw new Job_Error( 'JOB_MATERIAL_MISMATCH' ); }
		try { $plan = Change_Plan::hydrate( $data ); }
		catch ( Price_Validation_Error $error ) { throw new Job_Error( 'JOB_MATERIAL_MISMATCH' ); }
		self::assert_binding( $job, $plan );
		return $plan;
	}

	/** One stored item must match the hydrated frozen plan before any mutation. */
	public static function assert_item_material( Change_Plan $plan, array $item ): void {
		try { $frozen = $plan->item( (int) $item['product_id'] )->data(); }
		catch ( Price_Validation_Error $error ) { throw new Job_Error( 'JOB_MATERIAL_MISMATCH' ); }
		if ( $item['plan_id'] !== $plan->data()['plan_id'] || (int) $item['sequence'] < 1 || $item['plan_result'] !== $frozen['result'] ||
			$item['expected_price'] !== ( $frozen['expected_regular_price'] ?? '' ) || $item['planned_price'] !== ( $frozen['planned_regular_price'] ?? '' ) ) {
			throw new Job_Error( 'JOB_MATERIAL_MISMATCH' );
		}
	}

	/** Bounded, deterministic item reads for progress/history. */
	public static function items( int $job_id, ?string $state = null, int $offset = 0, int $limit = 50 ): array {
		$db = self::db();
		if ( $offset < 0 || $limit < 1 || $limit > self::PAGE_LIMIT ) { throw new Job_Error( 'INVALID_PAGE' ); }
		if ( null !== $state && ! in_array( $state, self::item_states(), true ) ) { throw new Job_Error( 'INVALID_ITEM_STATE' ); }
		$table = Job_Schema::items_table( $db );
		$sql = 'SELECT * FROM %i WHERE job_id=%d' . ( null === $state ? '' : ' AND state=%s' ) . ' ORDER BY sequence ASC, product_id ASC LIMIT %d OFFSET %d';
		$args = null === $state ? array( $table, $job_id, $limit, $offset ) : array( $table, $job_id, $state, $limit, $offset );
		return $db->get_results( $db->prepare( $sql, ...$args ), ARRAY_A );
	}
	private static function item_states(): array {
		return array( Job_Item_State::PENDING, Job_Item_State::APPLYING, Job_Item_State::APPLIED, Job_Item_State::UNCHANGED, Job_Item_State::CONFLICT, Job_Item_State::FAILED, Job_Item_State::NEEDS_REVIEW, Job_Item_State::UNSUPPORTED );
	}

	/** Durable item truth. Denominators are never trusted from stale counters. */
	public static function counts( int $job_id ): array {
		return self::derive_counts( self::db(), $job_id );
	}
	private static function derive_counts( \wpdb $db, int $job_id ): array {
		$counts = array( 'planned' => 0, 'pending' => 0, 'applying' => 0, 'applied' => 0, 'unchanged' => 0, 'conflict' => 0, 'failed' => 0, 'needs_review' => 0, 'unsupported' => 0 );
		$table = Job_Schema::items_table( $db );
		foreach ( $db->get_results( $db->prepare( 'SELECT state, COUNT(*) AS total FROM %i WHERE job_id=%d GROUP BY state', $table, $job_id ), ARRAY_A ) as $row ) {
			$key = strtolower( $row['state'] );
			if ( isset( $counts[$key] ) ) { $counts[$key] = (int) $row['total']; }
			$counts['planned'] += (int) $row['total'];
		}
		return $counts;
	}
	/** Absolute refresh from durable rows: no incremental double-count is possible. */
	public static function refresh_counters( \wpdb $db, int $job_id ): array {
		$counts = self::derive_counts( $db, $job_id );
		$jobs = Job_Schema::jobs_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Absolute truth repair from item rows; values are prepared.
		$db->query( $db->prepare(
			'UPDATE %i SET planned=%d,pending=%d,applying=%d,applied=%d,unchanged=%d,conflict=%d,failed=%d,needs_review=%d,unsupported=%d,updated_at=UTC_TIMESTAMP() WHERE id=%d',
			$jobs, $counts['planned'], $counts['pending'], $counts['applying'], $counts['applied'], $counts['unchanged'], $counts['conflict'], $counts['failed'], $counts['needs_review'], $counts['unsupported'], $job_id
		) );
		return $counts;
	}

	/**
	 * Atomic job lease acquisition. The generation increase is the fence used by
	 * every later claim/status write. Expired leases are recoverable; live ones
	 * are not, regardless of Action Scheduler state.
	 */
	public static function acquire_lease( int $job_id, string $owner, bool $manual ): array {
		if ( ! preg_match( '/\A[a-zA-Z0-9-]{1,64}\z/D', $owner ) ) { throw new Job_Error( 'INVALID_LEASE_OWNER' ); }
		$db = self::db();
		$statuses = $manual ? array( Job_State::READY, Job_State::QUEUED, Job_State::RUNNING, Job_State::PAUSED, Job_State::NEEDS_REVIEW ) : array( Job_State::READY, Job_State::QUEUED, Job_State::RUNNING );
		$jobs = Job_Schema::jobs_table( $db );
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lease CAS; identifiers and values are prepared.
		$updated = $db->query( $db->prepare(
			"UPDATE %i SET lease_owner=%s,lease_generation=lease_generation+1,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),last_worker_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),status=%s,status_reason=%s,started_at=IFNULL(started_at,UTC_TIMESTAMP()) WHERE id=%d AND status IN ($placeholders) AND (lease_owner='' OR lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())",
			array_merge( array( $jobs, $owner, self::LEASE_TTL, Job_State::RUNNING, 'ACTIVE', $job_id ), $statuses )
		) );
		if ( 1 !== $updated ) { return array(); }
		$row = $db->get_row( $db->prepare( 'SELECT lease_owner,lease_generation,lease_expires_at FROM %i WHERE id=%d', $jobs, $job_id ), ARRAY_A );
		if ( ! $row || $row['lease_owner'] !== $owner ) { return array(); }
		return array( 'owner' => $owner, 'generation' => (int) $row['lease_generation'], 'expires_at' => $row['lease_expires_at'] );
	}

	/** Fence identity (owner + generation). Expiry only gates takeover, not continuation. */
	public static function fence( int $job_id, string $owner, int $generation ): bool {
		$db = self::db();
		$jobs = Job_Schema::jobs_table( $db );
		return 1 === (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i WHERE id=%d AND lease_owner=%s AND lease_generation=%d', $jobs, $job_id, $owner, $generation ) );
	}

	public static function renew_lease( int $job_id, string $owner, int $generation ): bool {
		$db = self::db();
		$jobs = Job_Schema::jobs_table( $db );
		// MySQL reports zero changed rows when no column value differs (same
		// second); ownership is confirmed by the fence read instead.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fenced lease renewal; values are prepared.
		$db->query( $db->prepare( 'UPDATE %i SET lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),last_worker_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=%d AND lease_owner=%s AND lease_generation=%d', $jobs, self::LEASE_TTL, $job_id, $owner, $generation ) );
		return self::fence( $job_id, $owner, $generation );
	}

	/** Fenced terminal/chunk-boundary transition that also releases the lease. */
	public static function finish_chunk( array $job, string $owner, int $generation, string $status, string $reason ): bool {
		$db = self::db();
		Job_State::assert_transition( $job['status'], $status );
		$jobs = Job_Schema::jobs_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fenced status + lease release; values are prepared.
		return 1 === $db->query( $db->prepare(
			"UPDATE %i SET status=%s,status_reason=%s,lease_owner='',lease_expires_at=NULL,updated_at=UTC_TIMESTAMP(),queued_at=IF(%s='QUEUED',UTC_TIMESTAMP(),queued_at),completed_at=IF(%s IN ('COMPLETED','COMPLETED_WITH_ISSUES'),UTC_TIMESTAMP(),completed_at),paused_at=IF(%s='PAUSED',UTC_TIMESTAMP(),paused_at) WHERE id=%d AND status=%s AND lease_owner=%s AND lease_generation=%d",
			$jobs, $status, $reason, $status, $status, $status, (int) $job['id'], $job['status'], $owner, $generation
		) );
	}

	/** Unlocked scheduler transition; never clobbers a job a worker is running. */
	public static function transition_unlocked( int $job_id, string $from_status, string $to_status, string $reason ): bool {
		$db = self::db();
		Job_State::assert_transition( $from_status, $to_status );
		$jobs = Job_Schema::jobs_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded scheduler transition; values are prepared.
		return 1 === $db->query( $db->prepare( 'UPDATE %i SET status=%s,status_reason=%s,updated_at=UTC_TIMESTAMP(),queued_at=IF(%s=%s,UTC_TIMESTAMP(),queued_at),paused_at=IF(%s=%s,UTC_TIMESTAMP(),paused_at) WHERE id=%d AND status=%s AND lease_owner=%s', $jobs, $to_status, $reason, $to_status, Job_State::QUEUED, $to_status, Job_State::PAUSED, $job_id, $from_status, '' ) );
	}

	public static function mark_paused( int $job_id, string $reason ): bool {
		$job = self::read( $job_id );
		if ( ! $job || ! in_array( $job['status'], array( Job_State::READY, Job_State::QUEUED ), true ) || '' !== $job['lease_owner'] ) { return false; }
		return self::transition_unlocked( $job_id, $job['status'], Job_State::PAUSED, $reason );
	}

	/** Reconcile dead workers after activation: pause RUNNING jobs with no live lease. */
	public static function reap_stalled_leases(): int {
		$db = self::db();
		if ( ! Job_Schema::ready( $db ) ) { return 0; }
		$jobs = Job_Schema::jobs_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded lease recovery on activation; no product state is touched.
		$reaped = $db->query( $db->prepare( "UPDATE %i SET status=%s,status_reason=%s,lease_owner='',lease_expires_at=NULL,lease_generation=lease_generation+1,paused_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE status=%s AND (lease_owner='' OR lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())", $jobs, Job_State::PAUSED, 'LEASE_RECOVERY', Job_State::RUNNING ) );
		return is_int( $reaped ) ? $reaped : 0;
	}

	/** Pause a RUNNING job whose worker is gone (expired or absent lease). */
	public static function pause_stalled( int $job_id, string $reason ): bool {
		$db = self::db();
		$jobs = Job_Schema::jobs_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Stalled-lease revocation CAS; values are prepared.
		return 1 === $db->query( $db->prepare( "UPDATE %i SET status=%s,status_reason=%s,lease_owner='',lease_expires_at=NULL,lease_generation=lease_generation+1,paused_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=%d AND status=%s AND (lease_owner='' OR lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())", $jobs, Job_State::PAUSED, $reason, $job_id, Job_State::RUNNING ) );
	}

	/**
	 * Claim the next bounded item with a CAS that is itself fenced to the live
	 * job lease. A worker whose generation was stolen cannot claim an item even
	 * if it skipped its own pre-claim fence check. Returns null when none is claimable.
	 */
	public static function claim_next_item( int $job_id, string $owner, string $token, int $generation ): ?array {
		if ( ! preg_match( '/\A[a-zA-Z0-9-]{1,64}\z/D', $token ) ) { throw new Job_Error( 'INVALID_CLAIM_TOKEN' ); }
		$db = self::db();
		$items = Job_Schema::items_table( $db );
		$jobs = Job_Schema::jobs_table( $db );
		// Bounded automatic retries: retire exhausted pending items before selecting.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded retry retirement; values are prepared.
		$db->query( $db->prepare( "UPDATE %i SET state=%s,reason=%s,updated_at=UTC_TIMESTAMP() WHERE job_id=%d AND state=%s AND attempt_count>=%d", $items, Job_Item_State::FAILED, 'RETRY_BUDGET_EXHAUSTED', $job_id, Job_Item_State::PENDING, self::MAX_ATTEMPTS ) );
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$candidate = $db->get_row( $db->prepare( "SELECT id FROM %i WHERE job_id=%d AND state=%s AND attempt_count<%d AND (next_attempt_after IS NULL OR next_attempt_after<=UTC_TIMESTAMP()) ORDER BY sequence ASC, product_id ASC LIMIT 1", $items, $job_id, Job_Item_State::PENDING, self::MAX_ATTEMPTS ), ARRAY_A );
			if ( ! $candidate ) { return null; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fenced item claim CAS; identifiers and values are prepared.
			$claimed = $db->query( $db->prepare(
				"UPDATE %i SET state=%s,claim_token=%s,claim_generation=%d,attempt_count=attempt_count+1,last_attempt_at=UTC_TIMESTAMP(),next_attempt_after=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d AND state=%s AND attempt_count<%d AND EXISTS (SELECT 1 FROM %i j WHERE j.id=%d AND j.lease_owner=%s AND j.lease_generation=%d)",
				$items, Job_Item_State::APPLYING, $token, $generation, (int) $candidate['id'], Job_Item_State::PENDING, self::MAX_ATTEMPTS, $jobs, $job_id, $owner, $generation
			) );
			if ( 1 === $claimed ) {
				return $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE id=%d', $items, (int) $candidate['id'] ), ARRAY_A );
			}
		}
		return null;
	}

	/** Terminal or retry transition for an item under this worker's claim token. */
	public static function record_item( array $item, string $token, string $state, string $reason, ?string $applied_at = null, bool $retry = false ): bool {
		if ( ! in_array( $state, self::item_states(), true ) || ! preg_match( '/\A[A-Z0-9_]{1,64}\z/D', $reason ) ) { throw new Job_Error( 'INVALID_ITEM_STATE' ); }
		Job_Item_State::assert_transition( $item['state'], $state );
		$db = self::db();
		$items = Job_Schema::items_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Claim-token CAS item transition; values are prepared.
		return 1 === $db->query( $db->prepare(
			'UPDATE %i SET state=%s,reason=%s,claim_token=\'\',claim_generation=0,applied_at=IF(%s=%s,COALESCE(%s,applied_at),applied_at),next_attempt_after=IF(%d=1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),NULL),updated_at=UTC_TIMESTAMP() WHERE id=%d AND state=%s AND claim_token=%s',
			$items, $state, $reason, $state, Job_Item_State::APPLIED, $applied_at, $retry ? 1 : 0, self::RETRY_BACKOFF, (int) $item['id'], $item['state'], $token
		) );
	}

	/** Reconciliation of a stale claim after a lost worker: journal truth wins. */
	public static function reconcile_item( array $item, int $max_generation, string $state, string $reason, ?string $applied_at = null ): bool {
		if ( ! in_array( $state, self::item_states(), true ) || ! preg_match( '/\A[A-Z0-9_]{1,64}\z/D', $reason ) ) { throw new Job_Error( 'INVALID_ITEM_STATE' ); }
		Job_Item_State::assert_transition( $item['state'], $state );
		$db = self::db();
		$items = Job_Schema::items_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Stale-generation reconciliation CAS; values are prepared.
		return 1 === $db->query( $db->prepare(
			"UPDATE %i SET state=%s,reason=%s,claim_token='',claim_generation=0,applied_at=IF(%s='APPLIED',COALESCE(%s,applied_at),applied_at),next_attempt_after=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d AND state=%s AND claim_generation<%d",
			$items, $state, $reason, $state, $applied_at, (int) $item['id'], $item['state'], $max_generation
		) );
	}

	/** Stale claimed items for reconciliation at a new lease generation. */
	public static function stale_claims( int $job_id, int $generation ): array {
		$db = self::db();
		$items = Job_Schema::items_table( $db );
		return $db->get_results( $db->prepare( "SELECT * FROM %i WHERE job_id=%d AND claim_generation>0 AND claim_generation<%d AND state IN ('APPLYING','PENDING') ORDER BY sequence ASC", $items, $job_id, $generation ), ARRAY_A );
	}

	/** Job status derivation: durable item truth decides, never a cached counter. */
	public static function derive_terminal_status( array $counts ): string {
		if ( $counts['pending'] > 0 || $counts['applying'] > 0 ) { throw new Job_Error( 'JOB_NOT_TERMINAL' ); }
		if ( $counts['needs_review'] > 0 ) { return Job_State::NEEDS_REVIEW; }
		if ( $counts['conflict'] > 0 || $counts['failed'] > 0 ) { return Job_State::COMPLETED_WITH_ISSUES; }
		return Job_State::COMPLETED;
	}
	public static function terminal_reason( string $status ): string {
		if ( Job_State::COMPLETED === $status ) { return 'ALL_ITEMS_CLEAN'; }
		if ( Job_State::COMPLETED_WITH_ISSUES === $status ) { return 'ITEM_CONFLICTS'; }
		return 'ITEM_NEEDS_REVIEW';
	}

	/** Operator cancel: stop claims, retain applied facts, never roll back. The caller authorizes; $actor is provenance. */
	public static function cancel( int $job_id, int $actor ): bool {
		$job = self::read( $job_id );
		if ( ! $job ) { throw new Job_Error( 'JOB_NOT_FOUND' ); }
		Job_State::assert_transition( $job['status'], Job_State::CANCELLED );
		$db = self::db();
		$jobs = Job_Schema::jobs_table( $db );
		// Revoking the lease generation fences any in-flight worker at its next boundary.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Operator cancel CAS; values are prepared.
		return 1 === $db->query( $db->prepare( "UPDATE %i SET status=%s,status_reason=%s,lease_owner='',lease_expires_at=NULL,lease_generation=lease_generation+1,updated_at=UTC_TIMESTAMP() WHERE id=%d AND status=%s", $jobs, Job_State::CANCELLED, 'OPERATOR_CANCELLED', $job_id, $job['status'] ) );
	}

	/**
	 * Read-only truthful view. A dead worker's lease is reported as stalled
	 * instead of pretending the job is actively running; nothing writes here.
	 */
	public static function observe( int $job_id ): array {
		$job = self::read( $job_id );
		if ( ! $job ) { throw new Job_Error( 'JOB_NOT_FOUND' ); }
		$db = self::db();
		$jobs = Job_Schema::jobs_table( $db );
		$staleness = $db->get_row( $db->prepare( 'SELECT (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP()) AS lease_expired, (updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL %d SECOND)) AS quiet FROM %i WHERE id=%d', self::LEASE_TTL, $jobs, $job_id ), ARRAY_A );
		$stalled = in_array( $job['status'], array( Job_State::RUNNING, Job_State::QUEUED ), true ) && '1' === (string) ( $staleness['lease_expired'] ?? '0' ) && '1' === (string) ( $staleness['quiet'] ?? '0' );
		return array(
			'job' => $job,
			'counts' => self::counts( $job_id ),
			'stalled' => $stalled,
			'effective_status' => $stalled ? Job_State::PAUSED : $job['status'],
			'effective_reason' => $stalled ? 'LEASE_RECOVERY' : $job['status_reason'],
			'waiting' => in_array( $job['status'], array( Job_State::QUEUED, Job_State::PAUSED ), true ),
		);
	}
}
