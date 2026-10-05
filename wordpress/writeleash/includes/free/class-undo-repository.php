<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Durable Undo repository. This is the only place Undo operation/item SQL and
 * domain transitions live. Action Scheduler and object caches are never
 * authority. Apply history (`#108` journal rows, `#109` job/item rows) is
 * read here but never written: Undo is additive.
 *
 * Lease model mirrors #109: `acquire_lease()` atomically increments
 * `lease_generation` with a conditional UPDATE. A worker's fence is
 * `(lease_owner, lease_generation)`. Undo item claims are separate CAS
 * updates; the Undo item transaction additionally locks the Undo operation
 * row AND the parent apply job row (`SELECT ... FOR UPDATE`, same
 * connection) before any Woo write, so an apply worker and an Undo worker
 * can never hold overlapping item transactions on one job.
 */
final class Undo_Repository {
	public const LEASE_TTL = 60;
	public const MAX_ATTEMPTS = 3;
	public const RETRY_BACKOFF = 5;
	public const PAGE_LIMIT = 100;
	public const PURGE_BATCH = 100;
	/** Hard ceiling of the frozen #107 selection contract (1000 products). */
	public const MAX_EVIDENCE_ROWS = 1000;
	public const PUBLIC_ID_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D';

	/** Bounded retention in days. Filterable internally; 30 is the justified initial value. */
	public static function retention_days(): int {
		$days = apply_filters( 'writeleash_history_retention_days', 30 );
		$days = is_int( $days ) ? $days : 30;
		return max( 1, min( 3650, $days ) );
	}

	private static function ensure_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		try {
			if ( ! Undo_Schema::ready( $wpdb ) ) { Undo_Schema::install(); }
			Durable_Charset::migrate( $wpdb, Undo_Schema::operations_table( $wpdb ) );
			Durable_Charset::migrate( $wpdb, Undo_Schema::items_table( $wpdb ) );
			Undo_Schema::assert_schema( $wpdb );
			update_option( Undo_Schema::SETUP_OPTION, 'READY', false );
		} catch ( \Throwable $error ) {
			$reason = $error instanceof Undo_Error ? $error->reason() : 'SCHEMA_UNAVAILABLE';
			try { update_option( Undo_Schema::SETUP_OPTION, $reason, false ); } catch ( \Throwable $inner ) {}
			throw $error instanceof Undo_Error ? $error : new Undo_Error( 'SCHEMA_UNAVAILABLE' );
		}
	}

	private static function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	// ------------------------------------------------------------------
	// Reads.
	// ------------------------------------------------------------------

	public static function read_operation( int $undo_id ): ?array {
		$db = self::db();
		$table = Undo_Schema::operations_table( $db );
		$row = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE id=%d', $table, $undo_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function read_operation_by_job( int $job_id ): ?array {
		$db = self::db();
		$table = Undo_Schema::operations_table( $db );
		$row = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE job_id=%d', $table, $job_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function read_operation_by_public_id( string $public_id ): ?array {
		if ( ! preg_match( self::PUBLIC_ID_REGEX, $public_id ) ) { return null; }
		$db = self::db();
		$table = Undo_Schema::operations_table( $db );
		$row = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE public_id=%s', $table, $public_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function read_item( \wpdb $db, int $job_id, int $product_id ): ?array {
		$table = Undo_Schema::items_table( $db );
		$row = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE job_id=%d AND product_id=%d', $table, $job_id, $product_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function read_item_locked( \wpdb $db, int $job_id, int $product_id ): ?array {
		$table = Undo_Schema::items_table( $db );
		$row = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE job_id=%d AND product_id=%d FOR UPDATE', $table, $job_id, $product_id ), ARRAY_A );
		return $row ?: null;
	}

	/** Creator, approver, or an administrator. Possession of an ID grants nothing. */
	public static function authorized( array $job, int $user_id ): bool {
		if ( $user_id < 1 ) { return false; }
		if ( user_can( $user_id, 'manage_options' ) ) { return true; }
		return $user_id === (int) $job['creator_id'] || ( (int) $job['approver_id'] > 0 && $user_id === (int) $job['approver_id'] );
	}

	// ------------------------------------------------------------------
	// Initiation: record one Undo operation per safely stopped apply job.
	// ------------------------------------------------------------------

	/**
	 * Narrow backend initiation helper for tests and later #111 integration.
	 * POST-only callers must authenticate, check capabilities and bind a
	 * nonce before reaching this method. Idempotent per job.
	 *
	 * Concurrency (repair for #110 blocker 3): the authoritative creation
	 * transaction locks the parent apply job row first, then the Undo
	 * operation row, then re-evaluates terminal status, plan binding and
	 * retention under those locks. The retention purge takes the same job
	 * row first, so initiation and purge have exactly one serialized
	 * outcome: either initiation commits its operation/items and purge
	 * observes them and removes nothing, or purge commits complete deletion
	 * and initiation refuses cleanly because the job row is gone.
	 *
	 * @return array the Undo operation row.
	 */
	public static function initiate( int $job_id, int $initiator_id ): array {
		self::ensure_schema();
		if ( $initiator_id < 1 ) { throw new Undo_Error( 'PERMISSION_DENIED' ); }
		$job = Job_Repository::read( $job_id );
		if ( ! $job ) { throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' ); }
		if ( ! in_array( $job['status'], array( Job_State::COMPLETED, Job_State::COMPLETED_WITH_ISSUES ), true ) ) { throw new Undo_Error( 'UNDO_JOB_RUNNING' ); }
		if ( ! self::authorized( $job, $initiator_id ) ) { throw new Undo_Error( 'PERMISSION_DENIED' ); }
		$user = new \WP_User( $initiator_id );
		if ( ! user_can( $user, 'manage_woocommerce' ) || ! user_can( $user, 'edit_products' ) ) { throw new Undo_Error( 'PERMISSION_DENIED' ); }
		$plan = null;
		try { $plan = Job_Repository::hydrate_plan( $job ); }
		catch ( Job_Error $error ) { throw new Undo_Error( 'JOB_MATERIAL_MISMATCH' ); }
		$existing = self::read_operation_by_job( $job_id );
		if ( $existing ) {
			if ( $existing['plan_id'] !== $plan->data()['plan_id'] ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
			return $existing;
		}
		if ( self::retention_expired_for( $job, null ) ) { throw new Undo_Error( 'UNDO_EXPIRED' ); }

		global $wpdb;
		$original = self::db();
		$tx = null;
		$committed = false;
		try {
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			$wpdb = $tx;
			self::initiate_checkpoint( 'INITIATE_BEFORE_LOCK', $job_id );
			// 1. Parent apply job row first: the deterministic lock shared
			// with purge and the Undo/apply mutation fences.
			$jobs = Job_Schema::jobs_table( $tx );
			$locked_job = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', $jobs, $job_id ), ARRAY_A );
			if ( ! $locked_job ) { throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' ); }
			if ( ! in_array( $locked_job['status'], array( Job_State::COMPLETED, Job_State::COMPLETED_WITH_ISSUES ), true ) ) { throw new Undo_Error( 'UNDO_JOB_RUNNING' ); }
			try { Job_Repository::assert_binding( $locked_job, $plan ); }
			catch ( Job_Error $error ) { throw new Undo_Error( 'JOB_MATERIAL_MISMATCH' ); }
			// 2. Undo operation row under the same lock (idempotent re-read).
			$again = self::read_operation_by_job_locked( $tx, $job_id );
			if ( $again ) {
				if ( $again['plan_id'] !== $plan->data()['plan_id'] ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
				$tx->commit();
				$committed = true;
				$wpdb = $original;
				return self::read_operation_by_job( $job_id );
			}
			// 3. Re-evaluate retention under the lock. A winning purge either
			// committed before this lock (job row already gone, refused above)
			// or waits behind it and will observe the committed operation.
			if ( self::retention_expired_for( $locked_job, null ) ) { throw new Undo_Error( 'UNDO_EXPIRED' ); }
			self::initiate_checkpoint( 'INITIATE_AFTER_JOB_LOCK', $job_id );
			$operations = Undo_Schema::operations_table( $tx );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable Undo initiation INSERT requires DB authority; identifiers and values are prepared.
			if ( 1 !== $tx->query( $tx->prepare(
				"INSERT INTO %i (schema_version,job_id,plan_id,public_id,initiator_id,status,status_reason,created_at,updated_at) VALUES (%d,%d,%s,%s,%d,%s,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
				$operations, Undo_Schema::SCHEMA_VERSION, $job_id, $plan->data()['plan_id'], wp_generate_uuid4(), $initiator_id, Undo_State::PENDING, 'UNDO_INITIATED'
			) ) ) {
				throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' );
			}
			$undo_id = (int) $tx->insert_id;
			if ( $undo_id < 1 ) { throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' ); }
			// Reads inside insert_items use the pinned transaction connection:
			// `$wpdb` is `$tx` here, so the frozen apply evidence is read
			// while the job row lock excludes a concurrent purge.
			$eligible = self::insert_items( $tx, $undo_id, $locked_job, $plan, $initiator_id );
			if ( 0 === $eligible ) {
				throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' );
			}
			self::refresh_counters( $tx, $undo_id );
			$tx->commit();
			$committed = true;
			$wpdb = $original;
			$undo = self::read_operation( $undo_id );
			if ( ! $undo ) { throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' ); }
			return $undo;
		} catch ( \Throwable $error ) {
			$wpdb = $original;
			if ( ! $committed && $tx && $tx->owns_attempt() ) { $tx->rollback(); }
			if ( $plan ) {
				$existing = self::read_operation_by_job( $job_id );
				if ( $existing && $existing['plan_id'] === $plan->data()['plan_id'] ) { return $existing; }
			}
			throw $error instanceof Undo_Error ? $error : new Undo_Error( 'UNDO_NOT_ELIGIBLE' );
		} finally {
			$wpdb = $original;
			if ( $tx ) { $tx->rollback(); }
		}
	}

	private static function read_operation_by_job_locked( \wpdb $db, int $job_id ): ?array {
		$table = Undo_Schema::operations_table( $db );
		$row = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE job_id=%d FOR UPDATE', $table, $job_id ), ARRAY_A );
		return $row ?: null;
	}

	private static function initiate_checkpoint( string $point, int $job_id ): void {
		do_action( 'writeleash_undo_initiate_checkpoint', $point, $job_id );
	}

	private static function purge_checkpoint( string $point, int $job_id ): void {
		do_action( 'writeleash_purge_checkpoint', $point, $job_id );
	}

	/**
	 * One Undo item per journal-proven APPLIED job item, with durable
	 * provenance and the conservative post-apply fingerprint. Items in any
	 * other apply state are never Undo-eligible and are not inserted.
	 */
	private static function insert_items( \wpdb $tx, int $undo_id, array $job, Change_Plan $plan, int $initiator_id ): int {
		$job_id = (int) $job['id'];
		$eligible = 0;
		$offset = 0;
		$items_table = Undo_Schema::items_table( $tx );
		while ( true ) {
			$batch = Job_Repository::items( $job_id, Job_Item_State::APPLIED, $offset, self::PAGE_LIMIT );
			if ( ! $batch ) { break; }
			foreach ( $batch as $job_item ) {
				$product_id = (int) $job_item['product_id'];
				$facts = self::provenance_facts( $tx, $job, $plan, $job_item, $initiator_id );
				$captured = Undo_Fingerprint::capture( $facts );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotent Undo INSERT requires durable DB authority; identifiers and values are prepared.
				if ( false === $tx->query( $tx->prepare(
					"INSERT INTO %i (schema_version,job_id,undo_id,plan_id,product_id,sequence,expected_price,applied_price,apply_attempt_id,state,reason,provenance,fingerprint,evidence,created_at,updated_at) VALUES (%d,%d,%d,%s,%d,%d,%s,%s,%s,%s,%s,%s,%s,'',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=id",
					$items_table, Undo_Schema::SCHEMA_VERSION, $job_id, $undo_id, $plan->data()['plan_id'], $product_id, (int) $job_item['sequence'], $job_item['expected_price'], $job_item['planned_price'], $facts['apply_attempt_id'], Undo_Item_State::PENDING, 'UNDO_INITIATED', $captured['provenance'], $captured['fingerprint']
				) ) ) {
					throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' );
				}
				$row = self::read_item( $tx, $job_id, $product_id );
				if ( ! $row || $row['fingerprint'] !== $captured['fingerprint'] || $row['expected_price'] !== $job_item['expected_price'] || $row['applied_price'] !== $job_item['planned_price'] ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
				++$eligible;
			}
			$offset += count( $batch );
		}
		return $eligible;
	}

	/**
	 * Assemble apply-time provenance from durable records only: the #108
	 * journal COMMIT evidence (regular/active/lookup at COMMIT; `matches()`
	 * proved onsale=0 in that same transaction), the frozen #107 plan
	 * snapshot (proven MATCH by the apply precondition), and the frozen job
	 * store context. Fresh Woo state is never an input.
	 */
	private static function provenance_facts( \wpdb $tx, array $job, Change_Plan $plan, array $job_item, int $initiator_id ): array {
		$product_id = (int) $job_item['product_id'];
		$journal = Price_Apply_Journal::read( $tx, $plan->data()['plan_id'], $product_id );
		if ( ! $journal || 'APPLIED' !== $journal['state'] ) { throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' ); }
		try { Price_Apply_Journal::assert_binding( $journal, $plan, $product_id ); }
		catch ( Price_Apply_Error $error ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		$evidence = json_decode( $journal['evidence'], true );
		if ( ! is_array( $evidence ) || empty( $evidence['attempt_id'] ) ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		$field = $evidence['price_field'] ?? null;
		try {
			$frozen = $plan->item( $product_id )->data();
			$applied = Price_Decimal::parse( $job_item['planned_price'] );
			if ( Price_Decimal::parse( $evidence['target'] ) !== $applied || $journal['target_price'] !== $job_item['planned_price'] ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
			if ( null === $field ) {
				// Legacy evidence (no price field): strict sale-free proof exactly as before.
				foreach ( array( 'regular', 'active', 'lookup_min', 'lookup_max' ) as $key ) {
					if ( ! isset( $evidence[$key] ) || Price_Decimal::parse( $evidence[$key] ) !== $applied ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
				}
				if ( ! isset( $evidence['sale'] ) ) { $evidence['sale'] = ''; }
			} else {
				Price_Operation::assert_field( $field );
				if ( ! is_string( $evidence['field_value'] ?? null ) || ! Price_Decimal::equal( $evidence['field_value'], $job_item['planned_price'] )
					|| ! is_string( $evidence['regular'] ?? null ) || ! is_string( $evidence['sale'] ?? null ) ) {
					throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' );
				}
				if ( Price_Operation::FIELD_REGULAR === $field ) {
					if ( ! Price_Decimal::equal( $evidence['regular'], $job_item['planned_price'] ) ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
				} else {
					if ( ! Price_Decimal::equal( $evidence['sale'], $job_item['planned_price'] ) || '' === $evidence['regular'] ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
					try { Price_Decimal::parse( $evidence['regular'] ); }
					catch ( Price_Validation_Error $error ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
				}
			}
		} catch ( Price_Validation_Error $error ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		$snapshot = $frozen['snapshot'];
		$facts = array(
			'job_id' => (int) $job['id'],
			'plan_id' => $plan->data()['plan_id'],
			'product_id' => $product_id,
			'expected_price' => $job_item['expected_price'],
			'applied_price' => $job_item['planned_price'],
			'apply_attempt_id' => $journal['attempt_id'],
			'applied_at' => $journal['applied_at'],
			'actor_id' => (int) $job['creator_id'],
			'initiator_id' => $initiator_id,
			'currency' => $job['currency'],
			'price_decimals' => (int) $job['price_decimals'],
			'wordpress_version' => $job['wordpress_version'],
			'woocommerce_version' => $job['woocommerce_version'],
			'product_type' => $snapshot['type'],
			'core_simple' => (bool) $snapshot['core_simple'],
			'status' => $snapshot['status'],
			'initiated_post_modified_gmt' => self::advisory_modified_gmt( $product_id ),
		);
		if ( null === $field ) {
			$facts['sale_price'] = $snapshot['sale_price'];
			$facts['sale_from'] = $snapshot['sale_from'];
			$facts['sale_to'] = $snapshot['sale_to'];
			$facts['active_price'] = $evidence['active'];
			$facts['lookup_min'] = $evidence['lookup_min'];
			$facts['lookup_max'] = $evidence['lookup_max'];
			$facts['lookup_onsale'] = '0';
		} else {
			$facts['price_field'] = $field;
			if ( Price_Operation::FIELD_SALE === $field ) { $facts['regular_context'] = $evidence['regular']; }
		}
		return $facts;
	}

	/** Advisory only: never a blocking safety claim. */
	private static function advisory_modified_gmt( int $product_id ) {
		try {
			$post = get_post( $product_id );
			return $post ? (string) $post->post_modified_gmt : null;
		} catch ( \Throwable $error ) { return null; }
	}

	// ------------------------------------------------------------------
	// Binding: Undo row vs durable apply truth.
	// ------------------------------------------------------------------

	/**
	 * Full binding inside the Undo item transaction, under row locks. Any
	 * tampering with stored Undo provenance, prices, identity or the source
	 * apply evidence fails closed with zero Woo mutation.
	 */
	public static function assert_item_binding( \wpdb $tx, array $undo, Change_Plan $plan, array $job ): void {
		$data = $plan->data();
		if ( (int) $undo['schema_version'] !== Undo_Schema::SCHEMA_VERSION || $undo['plan_id'] !== $data['plan_id'] || $undo['plan_id'] !== $job['plan_id'] ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		$product_id = (int) $undo['product_id'];
		$job_items = Job_Schema::items_table( $tx );
		$job_item = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE job_id=%d AND product_id=%d FOR UPDATE', $job_items, (int) $job['id'], $product_id ), ARRAY_A );
		if ( ! $job_item || Job_Item_State::APPLIED !== $job_item['state'] || $job_item['plan_id'] !== $data['plan_id'] ||
			$job_item['expected_price'] !== $undo['expected_price'] || $job_item['planned_price'] !== $undo['applied_price'] ||
			(int) $job_item['sequence'] !== (int) $undo['sequence'] ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		$journal = Price_Apply_Journal::read( $tx, $data['plan_id'], $product_id, true );
		if ( ! $journal || 'APPLIED' !== $journal['state'] ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		try { Price_Apply_Journal::assert_binding( $journal, $plan, $product_id ); }
		catch ( Price_Apply_Error $error ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		if ( $journal['target_price'] !== $undo['applied_price'] ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		$provenance = json_decode( $undo['provenance'], true );
		if ( ! is_array( $provenance ) || (int) ( $provenance['job_id'] ?? 0 ) !== (int) $job['id'] || ( $provenance['plan_id'] ?? '' ) !== $data['plan_id'] ||
			(int) ( $provenance['product_id'] ?? 0 ) !== $product_id || ( $provenance['expected_price'] ?? '' ) !== $undo['expected_price'] ||
			( $provenance['applied_price'] ?? '' ) !== $undo['applied_price'] || ( $provenance['apply_attempt_id'] ?? '' ) !== $journal['attempt_id'] ||
			( $provenance['apply_attempt_id'] ?? '' ) !== $undo['apply_attempt_id'] ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
	}

	/** Lightweight binding for the post-rollback refusal path. */
	public static function assert_item_binding_lite( \wpdb $db, array $row ): void {
		if ( (int) $row['schema_version'] !== Undo_Schema::SCHEMA_VERSION || '' === $row['plan_id'] || (int) $row['product_id'] < 1 ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		$provenance = json_decode( $row['provenance'], true );
		if ( ! is_array( $provenance ) || Undo_Fingerprint::fingerprint_of( $provenance ) !== $row['fingerprint'] ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
	}

	/** In-transaction Undo item transition; the caller holds the row lock. */
	public static function transition_item( \wpdb $db, array $row, string $state, string $reason, string $attempt, string $evidence = '' ): void {
		Undo_Item_State::assert_transition( $row['state'], $state );
		if ( ! preg_match( '/\A[A-Z0-9_]{1,64}\z/D', $reason ) ) { throw new Undo_Error( 'INVALID_UNDO_ITEM_TRANSITION' ); }
		$table = Undo_Schema::items_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Locked in-transaction Undo transition; values are prepared.
		if ( 1 !== $db->query( $db->prepare(
			"UPDATE %i SET state=%s,reason=%s,attempt_id=%s,evidence=IF(%s<>'',%s,evidence),last_attempt_at=UTC_TIMESTAMP(),undone_at=IF(%s='UNDONE',UTC_TIMESTAMP(),undone_at),updated_at=UTC_TIMESTAMP() WHERE id=%d AND state=%s",
			$table, $state, $reason, $attempt, $evidence, $evidence, $state, (int) $row['id'], $row['state']
		) ) ) {
			throw new Undo_Error( 'INVALID_UNDO_ITEM_TRANSITION' );
		}
	}

	// ------------------------------------------------------------------
	// Claims, reconciliation, counters, leases.
	// ------------------------------------------------------------------

	/** Bounded, deterministic Undo item reads for progress/history. */
	public static function items( int $undo_id, ?string $state = null, int $offset = 0, int $limit = 50 ): array {
		$db = self::db();
		if ( $offset < 0 || $limit < 1 || $limit > self::PAGE_LIMIT ) { throw new Undo_Error( 'INVALID_PAGE' ); }
		if ( null !== $state && ! in_array( $state, self::item_states(), true ) ) { throw new Undo_Error( 'INVALID_UNDO_ITEM_STATE' ); }
		$table = Undo_Schema::items_table( $db );
		$sql = 'SELECT * FROM %i WHERE undo_id=%d' . ( null === $state ? '' : ' AND state=%s' ) . ' ORDER BY sequence ASC, product_id ASC LIMIT %d OFFSET %d';
		$args = null === $state ? array( $table, $undo_id, $limit, $offset ) : array( $table, $undo_id, $state, $limit, $offset );
		return $db->get_results( $db->prepare( $sql, ...$args ), ARRAY_A );
	}

	private static function item_states(): array {
		return array( Undo_Item_State::PENDING, Undo_Item_State::APPLYING, Undo_Item_State::UNDONE, Undo_Item_State::CONFLICT, Undo_Item_State::FAILED, Undo_Item_State::NEEDS_REVIEW );
	}

	public static function counts( int $undo_id ): array {
		return self::derive_counts( self::db(), $undo_id );
	}

	private static function derive_counts( \wpdb $db, int $undo_id ): array {
		$states = array(
			Undo_Item_State::PENDING => 'pending',
			Undo_Item_State::APPLYING => 'applying',
			Undo_Item_State::UNDONE => 'undone',
			Undo_Item_State::CONFLICT => 'conflict',
			Undo_Item_State::FAILED => 'failed',
			Undo_Item_State::NEEDS_REVIEW => 'needs_review',
		);
		$counts = array( 'eligible' => 0, 'pending' => 0, 'applying' => 0, 'undone' => 0, 'conflict' => 0, 'failed' => 0, 'needs_review' => 0 );
		$table = Undo_Schema::items_table( $db );
		foreach ( $db->get_results( $db->prepare( 'SELECT state, COUNT(*) AS total FROM %i WHERE undo_id=%d GROUP BY state', $table, $undo_id ), ARRAY_A ) as $row ) {
			if ( isset( $states[ $row['state'] ] ) ) { $counts[ $states[ $row['state'] ] ] = (int) $row['total']; }
			$counts['eligible'] += (int) $row['total'];
		}
		return $counts;
	}

	/** Absolute refresh from durable rows: no incremental double-count is possible. */
	public static function refresh_counters( \wpdb $db, int $undo_id ): array {
		$counts = self::derive_counts( $db, $undo_id );
		$operations = Undo_Schema::operations_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Absolute truth repair from Undo item rows; values are prepared.
		$db->query( $db->prepare(
			'UPDATE %i SET undo_eligible=%d,undo_pending=%d,undo_applying=%d,undone=%d,undo_conflict=%d,undo_failed=%d,undo_needs_review=%d,updated_at=UTC_TIMESTAMP() WHERE id=%d',
			$operations, $counts['eligible'], $counts['pending'], $counts['applying'], $counts['undone'], $counts['conflict'], $counts['failed'], $counts['needs_review'], $undo_id
		) );
		return $counts;
	}

	/**
	 * Claim the next bounded Undo item with a CAS that is itself fenced to the
	 * live Undo lease AND the locked, indexed lifecycle option row. Shutdown
	 * cannot delete/update the row between the check and the claim COMMIT.
	 */
	public static function claim_next_item( int $undo_id, string $owner, string $token, int $generation ): ?array {
		if ( ! preg_match( '/\A[a-zA-Z0-9-]{1,64}\z/D', $token ) ) { throw new Undo_Error( 'INVALID_CLAIM_TOKEN' ); }
		$original = self::db();
		$tx = null;
		try {
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			if ( ! Runner_Authority::lock_active( $tx ) ) { $tx->rollback(); return null; }
			$items = Undo_Schema::items_table( $tx );
			$operations = Undo_Schema::operations_table( $tx );
			$source = $tx->get_row( $tx->prepare( 'SELECT job_id FROM %i WHERE id=%d', $operations, $undo_id ), ARRAY_A );
			if ( ! $source ) { $tx->rollback(); return null; }
			$jobs = Job_Schema::jobs_table( $tx );
			$job = $tx->get_row( $tx->prepare( 'SELECT id FROM %i WHERE id=%d FOR UPDATE', $jobs, (int) $source['job_id'] ), ARRAY_A );
			if ( ! $job ) { $tx->rollback(); return null; }
			$lease = $tx->get_row( $tx->prepare( 'SELECT lease_owner,lease_generation FROM %i WHERE id=%d FOR UPDATE', $operations, $undo_id ), ARRAY_A );
			if ( ! $lease || $lease['lease_owner'] !== $owner || (int) $lease['lease_generation'] !== $generation ) { $tx->rollback(); return null; }
			$tx->query( $tx->prepare( "UPDATE %i SET state=%s,reason=%s,updated_at=UTC_TIMESTAMP() WHERE undo_id=%d AND state=%s AND attempt_count>=%d", $items, Undo_Item_State::FAILED, 'RETRY_BUDGET_EXHAUSTED', $undo_id, Undo_Item_State::PENDING, self::MAX_ATTEMPTS ) );
			for ( $attempt = 0; $attempt < 5; ++$attempt ) {
				$candidate = $tx->get_row( $tx->prepare( "SELECT id FROM %i WHERE undo_id=%d AND state=%s AND attempt_count<%d AND (next_attempt_after IS NULL OR next_attempt_after<=UTC_TIMESTAMP()) ORDER BY sequence ASC, product_id ASC LIMIT 1", $items, $undo_id, Undo_Item_State::PENDING, self::MAX_ATTEMPTS ), ARRAY_A );
				if ( ! $candidate ) { $tx->commit(); return null; }
				$claimed = $tx->query( $tx->prepare(
					"UPDATE %i SET state=%s,claim_token=%s,claim_generation=%d,attempt_count=attempt_count+1,last_attempt_at=UTC_TIMESTAMP(),next_attempt_after=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d AND state=%s AND attempt_count<%d AND EXISTS (SELECT 1 FROM %i o WHERE o.id=%d AND o.lease_owner=%s AND o.lease_generation=%d) AND EXISTS (SELECT 1 FROM %i r WHERE r.option_name=%s AND r.option_value=%s)",
					$items, Undo_Item_State::APPLYING, $token, $generation, (int) $candidate['id'], Undo_Item_State::PENDING, self::MAX_ATTEMPTS, $operations, $undo_id, $owner, $generation, $tx->options, Runner_Authority::OPTION, 'active'
				) );
				if ( 1 === $claimed ) {
					$row = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE id=%d', $items, (int) $candidate['id'] ), ARRAY_A );
					if ( ! $row ) { throw new Undo_Error( 'CLAIM_UNAVAILABLE' ); }
					$tx->commit();
					return $row;
				}
			}
			$tx->commit();
			return null;
		} catch ( \Throwable $error ) {
			if ( $tx ) { $tx->rollback(); }
			throw new Undo_Error( 'CLAIM_UNAVAILABLE' );
		} finally { if ( $tx ) { $tx->rollback(); } }
	}

	/** Known lifecycle refusal BEFORE Woo restore: refund only our own claim. */
	public static function release_lifecycle_claim( array $item, string $token ): bool {
		$db = self::db();
		$items = Undo_Schema::items_table( $db );
		return 1 === $db->query( $db->prepare( "UPDATE %i SET state=%s,reason=%s,claim_token='',claim_generation=0,attempt_count=IF(attempt_count>0,attempt_count-1,0),next_attempt_after=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d AND state IN (%s,%s) AND claim_token=%s AND claim_generation=%d", $items, Undo_Item_State::PENDING, 'DEACTIVATED', (int) $item['id'], Undo_Item_State::PENDING, Undo_Item_State::APPLYING, $token, (int) $item['claim_generation'] ) );
	}

	/** Terminal or retry transition for an Undo item under this worker's claim token. */
	public static function record_item( array $item, string $token, string $state, string $reason, ?string $undone_at = null, bool $retry = false ): bool {
		if ( ! in_array( $state, self::item_states(), true ) || ! preg_match( '/\A[A-Z0-9_]{1,64}\z/D', $reason ) ) { throw new Undo_Error( 'INVALID_UNDO_ITEM_STATE' ); }
		Undo_Item_State::assert_transition( $item['state'], $state );
		if ( Undo_Item_State::UNDONE === $state && null === $undone_at ) { $undone_at = gmdate( 'Y-m-d H:i:s' ); }
		$db = self::db();
		$items = Undo_Schema::items_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Claim-token CAS Undo item transition; values are prepared.
		return 1 === $db->query( $db->prepare(
			'UPDATE %i SET state=%s,reason=%s,claim_token=\'\',claim_generation=0,undone_at=IF(%s=%s,COALESCE(%s,undone_at),undone_at),next_attempt_after=IF(%d=1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),NULL),updated_at=UTC_TIMESTAMP() WHERE id=%d AND state=%s AND claim_token=%s',
			$items, $state, $reason, $state, Undo_Item_State::UNDONE, $undone_at, $retry ? 1 : 0, self::RETRY_BACKOFF, (int) $item['id'], $item['state'], $token
		) );
	}

	/** Reconciliation of a stale claim after a lost worker: Undo journal truth wins. */
	public static function reconcile_item( array $item, int $max_generation, string $state, string $reason, ?string $undone_at = null ): bool {
		if ( ! in_array( $state, self::item_states(), true ) || ! preg_match( '/\A[A-Z0-9_]{1,64}\z/D', $reason ) ) { throw new Undo_Error( 'INVALID_UNDO_ITEM_STATE' ); }
		Undo_Item_State::assert_transition( $item['state'], $state );
		if ( Undo_Item_State::UNDONE === $state && null === $undone_at ) { $undone_at = gmdate( 'Y-m-d H:i:s' ); }
		$db = self::db();
		$items = Undo_Schema::items_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Stale-generation Undo reconciliation CAS; values are prepared.
		return 1 === $db->query( $db->prepare(
			"UPDATE %i SET state=%s,reason=%s,claim_token='',claim_generation=0,undone_at=IF(%s='UNDONE',COALESCE(%s,undone_at),undone_at),next_attempt_after=NULL,updated_at=UTC_TIMESTAMP() WHERE id=%d AND state=%s AND claim_generation<%d",
			$items, $state, $reason, $state, $undone_at, (int) $item['id'], $item['state'], $max_generation
		) );
	}

	/** Stale claimed Undo items for reconciliation at a new lease generation. */
	public static function stale_claims( int $undo_id, int $generation ): array {
		$db = self::db();
		$items = Undo_Schema::items_table( $db );
		return $db->get_results( $db->prepare( 'SELECT * FROM %i WHERE undo_id=%d AND claim_generation>0 AND claim_generation<%d ORDER BY sequence ASC', $items, $undo_id, $generation ), ARRAY_A );
	}

	/** Best-effort release of this worker's claim token on any state. Never changes domain state. */
	public static function clear_claim( int $item_id, string $token ): bool {
		if ( '' === $token ) { return false; }
		$db = self::db();
		$items = Undo_Schema::items_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Claim-token housekeeping; values are prepared.
		return 1 === $db->query( $db->prepare( "UPDATE %i SET claim_token='',claim_generation=0,updated_at=UTC_TIMESTAMP() WHERE id=%d AND claim_token=%s", $items, $item_id, $token ) );
	}

	/** Arm the bounded retry backoff on a pending Undo item and release the claim. */
	public static function defer_item( int $item_id, string $token ): bool {
		$db = self::db();
		$items = Undo_Schema::items_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded Undo retry backoff; values are prepared.
		return 1 === $db->query( $db->prepare( "UPDATE %i SET claim_token='',claim_generation=0,next_attempt_after=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),updated_at=UTC_TIMESTAMP() WHERE id=%d AND claim_token=%s AND state=%s", $items, self::RETRY_BACKOFF, $item_id, $token, Undo_Item_State::PENDING ) );
	}

	/** Undo operation status derivation: durable Undo item truth decides. */
	public static function derive_terminal_status( array $counts ): string {
		if ( $counts['pending'] > 0 || $counts['applying'] > 0 ) { throw new Undo_Error( 'UNDO_NOT_TERMINAL' ); }
		if ( $counts['needs_review'] > 0 ) { return Undo_State::NEEDS_REVIEW; }
		if ( $counts['conflict'] > 0 || $counts['failed'] > 0 ) { return Undo_State::COMPLETED_WITH_ISSUES; }
		return Undo_State::COMPLETED;
	}

	public static function terminal_reason( string $status ): string {
		if ( Undo_State::COMPLETED === $status ) { return 'UNDO_ALL_CLEAN'; }
		if ( Undo_State::COMPLETED_WITH_ISSUES === $status ) { return 'UNDO_ITEM_CONFLICTS'; }
		return 'UNDO_ITEM_NEEDS_REVIEW';
	}

	/**
	 * Atomic Undo lease acquisition. Same fence semantics as #109: the
	 * generation increase is the fence; expiry only gates takeover.
	 */
	public static function acquire_lease( int $undo_id, string $owner, bool $manual ): array {
		if ( ! preg_match( '/\A[a-zA-Z0-9-]{1,64}\z/D', $owner ) ) { throw new Undo_Error( 'INVALID_LEASE_OWNER' ); }
		$db = self::db();
		$statuses = $manual ? array( Undo_State::PENDING, Undo_State::RUNNING, Undo_State::PAUSED, Undo_State::NEEDS_REVIEW ) : array( Undo_State::PENDING, Undo_State::RUNNING );
		$operations = Undo_Schema::operations_table( $db );
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic Undo lease CAS; identifiers and values are prepared.
		$updated = $db->query( $db->prepare(
			"UPDATE %i SET lease_owner=%s,lease_generation=lease_generation+1,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),last_worker_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),status=%s,status_reason=%s,started_at=IFNULL(started_at,UTC_TIMESTAMP()) WHERE id=%d AND status IN ($placeholders) AND (lease_owner='' OR lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())",
			array_merge( array( $operations, $owner, self::LEASE_TTL, Undo_State::RUNNING, 'UNDO_ACTIVE', $undo_id ), $statuses )
		) );
		if ( 1 !== $updated ) { return array(); }
		$row = $db->get_row( $db->prepare( 'SELECT lease_owner,lease_generation,lease_expires_at FROM %i WHERE id=%d', $operations, $undo_id ), ARRAY_A );
		if ( ! $row || $row['lease_owner'] !== $owner ) { return array(); }
		return array( 'owner' => $owner, 'generation' => (int) $row['lease_generation'], 'expires_at' => $row['lease_expires_at'] );
	}

	/** Fence identity (owner + generation). Expiry only gates takeover, not continuation. */
	public static function fence( int $undo_id, string $owner, int $generation ): bool {
		$db = self::db();
		$operations = Undo_Schema::operations_table( $db );
		return 1 === (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i WHERE id=%d AND lease_owner=%s AND lease_generation=%d', $operations, $undo_id, $owner, $generation ) );
	}

	public static function renew_lease( int $undo_id, string $owner, int $generation ): bool {
		$db = self::db();
		$operations = Undo_Schema::operations_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fenced Undo lease renewal; values are prepared.
		$db->query( $db->prepare( 'UPDATE %i SET lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),last_worker_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=%d AND lease_owner=%s AND lease_generation=%d', $operations, self::LEASE_TTL, $undo_id, $owner, $generation ) );
		return self::fence( $undo_id, $owner, $generation );
	}

	/** Fenced terminal/chunk-boundary transition that also releases the lease. */
	public static function finish_chunk( array $operation, string $owner, int $generation, string $status, string $reason ): bool {
		$db = self::db();
		Undo_State::assert_transition( $operation['status'], $status );
		$operations = Undo_Schema::operations_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fenced Undo status + lease release; values are prepared.
		return 1 === $db->query( $db->prepare(
			"UPDATE %i SET status=%s,status_reason=%s,lease_owner='',lease_expires_at=NULL,updated_at=UTC_TIMESTAMP(),completed_at=IF(%s IN ('UNDO_COMPLETED','UNDO_COMPLETED_WITH_ISSUES'),UTC_TIMESTAMP(),completed_at) WHERE id=%d AND status=%s AND lease_owner=%s AND lease_generation=%d",
			$operations, $status, $reason, $status, (int) $operation['id'], $operation['status'], $owner, $generation
		) );
	}

	/** Unlocked scheduler transition; never clobbers an operation a worker is running. */
	public static function transition_unlocked( int $undo_id, string $from_status, string $to_status, string $reason ): bool {
		$db = self::db();
		Undo_State::assert_transition( $from_status, $to_status );
		$operations = Undo_Schema::operations_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded Undo scheduler transition; values are prepared.
		return 1 === $db->query( $db->prepare( 'UPDATE %i SET status=%s,status_reason=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d AND status=%s AND lease_owner=%s', $operations, $to_status, $reason, $undo_id, $from_status, '' ) );
	}

	public static function mark_paused( int $undo_id, string $reason ): bool {
		$operation = self::read_operation( $undo_id );
		if ( ! $operation || Undo_State::PENDING !== $operation['status'] || '' !== $operation['lease_owner'] ) { return false; }
		return self::transition_unlocked( $undo_id, $operation['status'], Undo_State::PAUSED, $reason );
	}

	/** Reconcile dead Undo workers after activation: pause RUNNING operations with no live lease. */
	public static function reap_stalled_leases(): int {
		$db = self::db();
		if ( ! Undo_Schema::ready( $db ) ) { return 0; }
		$operations = Undo_Schema::operations_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded Undo lease recovery on activation; no product state is touched.
		$reaped = $db->query( $db->prepare( "UPDATE %i SET status=%s,status_reason=%s,lease_owner='',lease_expires_at=NULL,lease_generation=lease_generation+1,updated_at=UTC_TIMESTAMP() WHERE status=%s AND (lease_owner='' OR lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())", $operations, Undo_State::PAUSED, 'LEASE_RECOVERY', Undo_State::RUNNING ) );
		return is_int( $reaped ) ? $reaped : 0;
	}

	/** Pause a RUNNING operation whose worker is gone (expired or absent lease). */
	public static function pause_stalled( int $undo_id, string $reason ): bool {
		$db = self::db();
		$operations = Undo_Schema::operations_table( $db );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Stalled Undo lease revocation CAS; values are prepared.
		return 1 === $db->query( $db->prepare( "UPDATE %i SET status=%s,status_reason=%s,lease_owner='',lease_expires_at=NULL,lease_generation=lease_generation+1,updated_at=UTC_TIMESTAMP() WHERE id=%d AND status=%s AND (lease_owner='' OR lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())", $operations, Undo_State::PAUSED, $reason, $undo_id, Undo_State::RUNNING ) );
	}

	/** Operator cancel: stop claims, retain UNDONE facts. Never re-applies. */
	public static function cancel( int $undo_id, int $actor ): bool {
		$operation = self::read_operation( $undo_id );
		if ( ! $operation ) { throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' ); }
		Undo_State::assert_transition( $operation['status'], Undo_State::CANCELLED );
		$db = self::db();
		$operations = Undo_Schema::operations_table( $db );
		// Revoking the lease generation fences any in-flight worker at its next
		// boundary. This UPDATE waits behind an in-flight Undo item's fence row
		// lock, so cancel cannot revoke ownership mid-commit.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Operator Undo cancel CAS; values are prepared.
		return 1 === $db->query( $db->prepare( "UPDATE %i SET status=%s,status_reason=%s,lease_owner='',lease_expires_at=NULL,lease_generation=lease_generation+1,updated_at=UTC_TIMESTAMP() WHERE id=%d AND status=%s", $operations, Undo_State::CANCELLED, 'UNDO_CANCELLED', $undo_id, $operation['status'] ) );
	}

	/**
	 * Read-only truthful view. A dead worker's lease is reported as stalled
	 * instead of pretending the operation is actively running.
	 */
	public static function observe( int $undo_id ): array {
		$operation = self::read_operation( $undo_id );
		if ( ! $operation ) { throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' ); }
		$db = self::db();
		$operations = Undo_Schema::operations_table( $db );
		$staleness = $db->get_row( $db->prepare( 'SELECT (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP()) AS lease_expired, (updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL %d SECOND)) AS quiet FROM %i WHERE id=%d', self::LEASE_TTL, $operations, $undo_id ), ARRAY_A );
		$stalled = in_array( $operation['status'], array( Undo_State::RUNNING, Undo_State::PENDING ), true ) && '1' === (string) ( $staleness['lease_expired'] ?? '0' ) && '1' === (string) ( $staleness['quiet'] ?? '0' );
		return array(
			'operation' => $operation,
			'counts' => self::counts( $undo_id ),
			'stalled' => $stalled,
			'effective_status' => $stalled ? Undo_State::PAUSED : $operation['status'],
			'effective_reason' => $stalled ? 'LEASE_RECOVERY' : $operation['status_reason'],
		);
	}

	// ------------------------------------------------------------------
	// History: backend view models for #111. No HTML. Reads are scoped by
	// the caller through `authorized()`; possession of an ID grants nothing.
	//
	// Reads never install schema: no CREATE/ALTER/DROP, no option writes.
	// Before any Undo schema exists the Undo portion of every response is
	// empty/not-yet-initialized while apply truth stays fully visible.
	// Schema installation happens only on explicit state-changing paths
	// (`initiate()`, job import/approval), never on GET/render reads.
	// ------------------------------------------------------------------

	/** True only when the Undo tables are installed and verified; never installs. */
	private static function undo_ready(): bool {
		try {
			return Undo_Schema::ready( self::db() );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** True only when the job tables are installed and verified; never installs. */
	private static function jobs_ready(): bool {
		try {
			return Job_Schema::ready( self::db() );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Recent jobs page scoped to the viewer BEFORE pagination: the actor
	 * predicate is part of the SQL result set, so LIMIT/OFFSET and the
	 * total/next_offset describe only caller-visible jobs. Administrators
	 * (manage_options override) see the global set; anyone else sees only
	 * jobs they created or approved. Unknown viewers see an empty page.
	 * Deterministic newest-first ordering, bounded page size.
	 */
	public static function history_jobs( int $offset = 0, int $limit = 20, int $viewer_id = 0 ): array {
		$db = self::db();
		if ( $offset < 0 || $limit < 1 || $limit > self::PAGE_LIMIT ) { throw new Undo_Error( 'INVALID_PAGE' ); }
		$empty = array( 'offset' => $offset, 'limit' => $limit, 'total' => 0, 'jobs' => array(), 'next_offset' => null );
		if ( $viewer_id < 1 || ! self::jobs_ready() ) {
			return $empty;
		}
		$jobs = Job_Schema::jobs_table( $db );
		if ( user_can( $viewer_id, 'manage_options' ) ) {
			$where = '1=1';
			$scope = array();
		} else {
			$where = '(creator_id=%d OR approver_id=%d)';
			$scope = array( $viewer_id, $viewer_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Actor-scoped history read over plugin-owned tables; identifiers and values are prepared.
		$total = (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $jobs, ...$scope ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Actor-scoped history read over plugin-owned tables; identifiers and values are prepared.
		$rows = $db->get_results( $db->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", $jobs, ...array_merge( $scope, array( $limit, $offset ) ) ), ARRAY_A );
		$jobs_page = array();
		foreach ( $rows as $job ) {
			$jobs_page[] = self::history_job( (int) $job['id'] );
		}
		$returned = count( $jobs_page );
		return array(
			'offset' => $offset,
			'limit' => $limit,
			'total' => $total,
			'jobs' => $jobs_page,
			'next_offset' => $offset + $returned < $total ? $offset + $returned : null,
		);
	}

	/** One job summary with apply counts, Undo counts, eligibility and expiry. */
	public static function history_job( int $job_id ): array {
		if ( ! self::jobs_ready() ) { throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' ); }
		$job = Job_Repository::read( $job_id );
		if ( ! $job ) { throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' ); }
		$operation = self::undo_ready() ? self::read_operation_by_job( $job_id ) : null;
		$undo_counts = $operation ? self::counts( (int) $operation['id'] ) : array( 'eligible' => 0, 'pending' => 0, 'applying' => 0, 'undone' => 0, 'conflict' => 0, 'failed' => 0, 'needs_review' => 0 );
		return array(
			'job_id' => (int) $job['id'],
			'public_id' => $job['public_id'],
			'plan_id' => $job['plan_id'],
			'creator_id' => (int) $job['creator_id'],
			'approver_id' => (int) $job['approver_id'],
			'status' => $job['status'],
			'status_reason' => $job['status_reason'],
			'created_at' => $job['created_at'],
			'approved_at' => $job['approved_at'],
			'completed_at' => $job['completed_at'],
			'currency' => $job['currency'],
			'price_decimals' => (int) $job['price_decimals'],
			'apply' => array(
				'planned' => (int) $job['planned'],
				'pending' => (int) $job['pending'],
				'applying' => (int) $job['applying'],
				'applied' => (int) $job['applied'],
				'unchanged' => (int) $job['unchanged'],
				'conflict' => (int) $job['conflict'],
				'failed' => (int) $job['failed'],
				'needs_review' => (int) $job['needs_review'],
				'unsupported' => (int) $job['unsupported'],
			),
			'undo' => array_merge(
				$undo_counts,
				array(
					'operation_id' => $operation ? (int) $operation['id'] : null,
					'operation_public_id' => $operation ? $operation['public_id'] : null,
					'operation_status' => $operation ? $operation['status'] : null,
					'operation_reason' => $operation ? $operation['status_reason'] : null,
					'initiator_id' => $operation ? (int) $operation['initiator_id'] : null,
					'completed_at' => $operation ? $operation['completed_at'] : null,
				)
			),
			'undo_eligible' => self::undo_eligible_for( $job, $operation ),
			'undo_expires_at' => self::retention_expires_at_for( $job, $operation ),
		);
	}

	/**
	 * Item page for one job: frozen apply facts joined with Undo facts and
	 * typed reasons.
	 *
	 * Pagination is evaluated over the complete logical result set. The #107
	 * supported selection range reaches 1000 products, so filtering and
	 * slicing an in-memory first page would silently truncate both plain and
	 * filtered pages. `apply_state`/`undo_state` predicates run inside the
	 * database query instead, and `total`/`next_offset` describe the filtered
	 * logical set. Deterministic frozen `sequence ASC, product_id ASC` order,
	 * bounded `limit <= 100`.
	 *
	 * Before any Undo schema exists the Undo columns read as empty without
	 * querying a missing table; an `undo_state` filter then matches nothing.
	 */
	public static function history_items( int $job_id, ?string $apply_state = null, ?string $undo_state = null, int $offset = 0, int $limit = 50 ): array {
		$db = self::db();
		if ( $offset < 0 || $limit < 1 || $limit > self::PAGE_LIMIT ) { throw new Undo_Error( 'INVALID_PAGE' ); }
		if ( null !== $apply_state && ! in_array( $apply_state, self::apply_states(), true ) ) { throw new Undo_Error( 'INVALID_ITEM_STATE' ); }
		if ( null !== $undo_state && ! in_array( $undo_state, self::item_states(), true ) ) { throw new Undo_Error( 'INVALID_UNDO_ITEM_STATE' ); }
		if ( ! self::jobs_ready() ) { throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' ); }
		$job = Job_Repository::read( $job_id );
		if ( ! $job ) { throw new Undo_Error( 'UNDO_NOT_ELIGIBLE' ); }
		if ( ! self::undo_ready() ) {
			return self::history_items_apply_only( $db, $job_id, $apply_state, $undo_state, $offset, $limit );
		}
		$job_items = Job_Schema::items_table( $db );
		$undo_items = Undo_Schema::items_table( $db );
		$where = 'i.job_id=%d';
		$args = array( $job_id );
		if ( null !== $apply_state ) { $where .= ' AND i.state=%s'; $args[] = $apply_state; }
		if ( null !== $undo_state ) { $where .= ' AND u.state=%s'; $args[] = $undo_state; }
		$join = 'FROM %i i LEFT JOIN %i u ON u.job_id=i.job_id AND u.product_id=i.product_id WHERE ' . $where;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifier-qualified join over plugin-owned tables; identifiers and values are prepared.
		$total = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) ' . $join, $job_items, $undo_items, ...$args ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifier-qualified join over plugin-owned tables; identifiers and values are prepared.
		$rows = $db->get_results( $db->prepare( 'SELECT i.product_id,i.sequence,i.expected_price,i.planned_price,i.state AS apply_state,i.reason AS apply_reason,i.applied_at,u.state AS undo_state,u.reason AS undo_reason,u.undone_at ' . $join . ' ORDER BY i.sequence ASC, i.product_id ASC LIMIT %d OFFSET %d', $job_items, $undo_items, ...array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A );
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'product_id' => (int) $row['product_id'],
				'sequence' => (int) $row['sequence'],
				'expected_price' => $row['expected_price'],
				'planned_price' => $row['planned_price'],
				'apply_state' => $row['apply_state'],
				'apply_reason' => $row['apply_reason'],
				'applied_at' => $row['applied_at'],
				'undo_state' => $row['undo_state'],
				'undo_reason' => $row['undo_reason'],
				'undo_reason_message' => null !== $row['undo_reason'] ? Undo_Reason::message( (string) $row['undo_reason'] ) : null,
				'restored_price' => Undo_Item_State::UNDONE === $row['undo_state'] ? $row['expected_price'] : null,
				'undone_at' => $row['undone_at'],
			);
		}
		$returned = count( $items );
		return array(
			'job_id' => $job_id,
			'offset' => $offset,
			'limit' => $limit,
			'total' => $total,
			'items' => $items,
			'next_offset' => $offset + $returned < $total ? $offset + $returned : null,
		);
	}

	/**
	 * Apply-only item page used while the Undo tables do not exist yet.
	 * Reads the job items table only; every Undo field is empty and an
	 * `undo_state` filter matches nothing, which is exactly the durable
	 * truth (no Undo row can exist without its tables).
	 */
	private static function history_items_apply_only( \wpdb $db, int $job_id, ?string $apply_state, ?string $undo_state, int $offset, int $limit ): array {
		$empty = array( 'job_id' => $job_id, 'offset' => $offset, 'limit' => $limit, 'total' => 0, 'items' => array(), 'next_offset' => null );
		if ( null !== $undo_state ) {
			return $empty;
		}
		$job_items = Job_Schema::items_table( $db );
		$where = 'job_id=%d';
		$args = array( $job_id );
		if ( null !== $apply_state ) { $where .= ' AND state=%s'; $args[] = $apply_state; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only history page over the plugin-owned job items table; identifiers and values are prepared.
		$total = (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $job_items, ...$args ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only history page over the plugin-owned job items table; identifiers and values are prepared.
		$rows = $db->get_results( $db->prepare( "SELECT product_id,sequence,expected_price,planned_price,state AS apply_state,reason AS apply_reason,applied_at FROM %i WHERE $where ORDER BY sequence ASC, product_id ASC LIMIT %d OFFSET %d", $job_items, ...array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A );
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'product_id' => (int) $row['product_id'],
				'sequence' => (int) $row['sequence'],
				'expected_price' => $row['expected_price'],
				'planned_price' => $row['planned_price'],
				'apply_state' => $row['apply_state'],
				'apply_reason' => $row['apply_reason'],
				'applied_at' => $row['applied_at'],
				'undo_state' => null,
				'undo_reason' => null,
				'undo_reason_message' => null,
				'restored_price' => null,
				'undone_at' => null,
			);
		}
		$returned = count( $items );
		return array(
			'job_id' => $job_id,
			'offset' => $offset,
			'limit' => $limit,
			'total' => $total,
			'items' => $items,
			'next_offset' => $offset + $returned < $total ? $offset + $returned : null,
		);
	}

	/** Apply item states, used only to validate history filters before querying. */
	private static function apply_states(): array {
		return array( Job_Item_State::PENDING, Job_Item_State::APPLYING, Job_Item_State::APPLIED, Job_Item_State::UNCHANGED, Job_Item_State::CONFLICT, Job_Item_State::FAILED, Job_Item_State::NEEDS_REVIEW, Job_Item_State::UNSUPPORTED );
	}

	// ------------------------------------------------------------------
	// Retention: bounded, terminal-only, never active or ambiguous.
	// ------------------------------------------------------------------

	/**
	 * Retention clock basis: terminal apply `completed_at`; an Undo
	 * completion extends it. Never `created_at`: evidence must survive a
	 * full retention window after the last durable outcome.
	 */
	public static function retention_expires_at_for( array $job, ?array $operation ): ?string {
		$base = $job['completed_at'] ?: $job['updated_at'];
		if ( $operation && $operation['completed_at'] && $operation['completed_at'] > $base ) { $base = $operation['completed_at']; }
		if ( ! $base ) { return null; }
		$timestamp = strtotime( (string) $base . ' +00:00' );
		if ( false === $timestamp ) { return null; }
		return gmdate( 'Y-m-d H:i:s', $timestamp + self::retention_days() * 86400 );
	}

	public static function retention_expired_for( array $job, ?array $operation ): bool {
		$expires = self::retention_expires_at_for( $job, $operation );
		if ( null === $expires ) { return false; }
		return $expires <= gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Honest Undo eligibility for #111: apply processing safely stopped, not
	 * expired, at least one APPLIED item, and no finished Undo operation.
	 * An expired or fully undone job exposes no Restore action.
	 */
	public static function undo_eligible_for( array $job, ?array $operation ): bool {
		if ( ! in_array( $job['status'], array( Job_State::COMPLETED, Job_State::COMPLETED_WITH_ISSUES ), true ) ) { return false; }
		if ( (int) $job['applied'] < 1 ) { return false; }
		if ( self::retention_expired_for( $job, $operation ) ) { return false; }
		if ( $operation && Undo_State::is_terminal( $operation['status'] ) ) { return false; }
		return true;
	}

	/**
	 * Bounded retention purge. Deletes only terminal, expiry-crossed history
	 * in safe logical order (Undo items, Undo operations, job items, price
	 * journal rows, jobs). Never purges apply RUNNING/QUEUED/PAUSED/
	 * NEEDS_REVIEW, active Undo, incomplete items, or any NEEDS_REVIEW /
	 * UNDO_NEEDS_REVIEW evidence.
	 *
	 * Each candidate is removed in one short transaction
	 * (`purge_job_atomic()`), so a failure can never leave partially deleted
	 * authoritative evidence and can never be reported as success. Two
	 * concurrent purge workers are harmless: the parent job row lock
	 * serializes them and a candidate already gone is a no-op.
	 *
	 * Fresh (non-expired) rows never starve expired ones: candidates are
	 * scanned oldest-first with bounded keyset pagination (`id > last`, never
	 * `OFFSET`, which deletions would shift) until the purge batch is filled
	 * or candidates run out.
	 *
	 * @return array purged counts plus `purge_failed` (candidates whose
	 *               transaction rolled back) and `skipped_active` (expired
	 *               candidates still holding active/review evidence).
	 */
	public static function purge_expired( int $batch = 20 ): array {
		$purged = array( 'jobs' => 0, 'job_items' => 0, 'journal_rows' => 0, 'undo_operations' => 0, 'undo_items' => 0, 'skipped_active' => 0, 'purge_failed' => 0 );
		if ( $batch < 1 || $batch > self::PURGE_BATCH ) { throw new Undo_Error( 'INVALID_PAGE' ); }
		$db = self::db();
		if ( ! Job_Schema::ready( $db ) || ! Undo_Schema::ready( $db ) ) { return $purged; }
		$jobs = Job_Schema::jobs_table( $db );
		$processed = 0;
		$last_id = 0;
		while ( $processed < $batch ) {
			$scan = max( 2, ( $batch - $processed ) * 2 );
			// Keyset pagination: deletions shift OFFSET windows and would skip
			// unexamined rows, while `id > last` never skips and stays bounded.
			// Non-expired/fresh candidates never consume the purge batch, so
			// they cannot starve older expired history.
			$candidates = $db->get_results( $db->prepare( "SELECT id FROM %i WHERE status IN ('COMPLETED','COMPLETED_WITH_ISSUES','CANCELLED') AND id>%d ORDER BY id ASC LIMIT %d", $jobs, $last_id, $scan ), ARRAY_A );
			if ( ! $candidates ) { break; }
			foreach ( $candidates as $candidate ) {
				$last_id = max( $last_id, (int) $candidate['id'] );
				if ( $processed >= $batch ) { break 2; }
				$job = Job_Repository::read( (int) $candidate['id'] );
				if ( ! $job ) { continue; }
				$operation = self::read_operation_by_job( (int) $job['id'] );
				if ( ! self::purgeable( $job, $operation ) ) {
					if ( self::retention_expired_for( $job, $operation ) ) { ++$purged['skipped_active']; }
					continue;
				}
				$purged_job = self::purge_job_atomic( (int) $job['id'] );
				if ( null === $purged_job ) { ++$purged['purge_failed']; continue; }
				foreach ( $purged_job as $key => $count ) { $purged[$key] += $count; }
				++$processed;
			}
		}
		return $purged;
	}

	/**
	 * Unlocked pre-check: terminal apply status, expiry crossed, no live or
	 * ambiguous evidence anywhere. This decides whether a candidate is worth
	 * locking; the authoritative eligibility decision is re-evaluated from
	 * locked rows inside `purge_job_atomic()`.
	 */
	private static function purgeable( array $job, ?array $operation ): bool {
		if ( ! in_array( $job['status'], array( Job_State::COMPLETED, Job_State::COMPLETED_WITH_ISSUES, Job_State::CANCELLED ), true ) ) { return false; }
		if ( ! self::retention_expired_for( $job, $operation ) ) { return false; }
		$counts = Job_Repository::counts( (int) $job['id'] );
		if ( $counts['pending'] > 0 || $counts['applying'] > 0 || $counts['needs_review'] > 0 ) { return false; }
		if ( $operation ) {
			if ( ! Undo_State::is_terminal( $operation['status'] ) ) { return false; }
			if ( Undo_State::NEEDS_REVIEW === $operation['status'] ) { return false; }
			$undo_counts = self::counts( (int) $operation['id'] );
			if ( $undo_counts['pending'] > 0 || $undo_counts['applying'] > 0 || $undo_counts['needs_review'] > 0 ) { return false; }
		}
		return true;
	}

	/**
	 * One purge candidate = one short bounded transaction (repair for #110
	 * blocker 2). Lock order, shared with initiation and the Undo mutation
	 * fence: parent apply job row -> Undo operation row -> Undo item rows ->
	 * apply job item rows -> journal rows. Eligibility is re-evaluated from
	 * the locked rows; every DELETE must affect exactly the locked row count;
	 * any SQL error, invariant mismatch, lost transaction or unexpected
	 * state rolls the complete candidate back. The parent job row is deleted
	 * last, so a failed child/evidence delete can never orphan or half-purge
	 * history.
	 *
	 * @return array deletion counts, or `null` for a real failure that the
	 *               caller must report as `purge_failed`, never as success.
	 */
	private static function purge_job_atomic( int $job_id ): ?array {
		global $wpdb;
		$original = $wpdb;
		$tx = null;
		$committed = false;
		$zero = array( 'jobs' => 0, 'job_items' => 0, 'journal_rows' => 0, 'undo_operations' => 0, 'undo_items' => 0 );
		try {
			$tx = new Price_Apply_Connection( $original );
			$tx->begin();
			$wpdb = $tx;
			self::purge_checkpoint( 'PURGE_AFTER_BEGIN', $job_id );
			// 1. Parent apply job row first: the deterministic lock shared
			// with Undo initiation and the Undo/apply mutation fences.
			$jobs = Job_Schema::jobs_table( $tx );
			$locked_job = $tx->get_row( $tx->prepare( 'SELECT id,schema_version,status,plan_id,completed_at,updated_at FROM %i WHERE id=%d FOR UPDATE', $jobs, $job_id ), ARRAY_A );
			if ( ! $locked_job ) { return $zero; }
			self::purge_checkpoint( 'PURGE_AFTER_JOB_LOCK', $job_id );
			// 2. Undo operation row (unique by job_id) under the same lock.
			$operations = Undo_Schema::operations_table( $tx );
			$locked_operation = $tx->get_row( $tx->prepare( 'SELECT * FROM %i WHERE job_id=%d FOR UPDATE', $operations, $job_id ), ARRAY_A );
			// 3. Authoritative eligibility from the locked rows.
			if ( ! in_array( $locked_job['status'], array( Job_State::COMPLETED, Job_State::COMPLETED_WITH_ISSUES, Job_State::CANCELLED ), true ) ) { return $zero; }
			if ( ! self::retention_expired_for( $locked_job, $locked_operation ) ) { return $zero; }
			if ( $locked_operation && ( ! Undo_State::is_terminal( $locked_operation['status'] ) || Undo_State::NEEDS_REVIEW === $locked_operation['status'] ) ) { return $zero; }
			// 4. Lock the dependent authoritative evidence.
			$job_items = Job_Schema::items_table( $tx );
			$locked_items = $tx->get_results( $tx->prepare( 'SELECT id,state FROM %i WHERE job_id=%d FOR UPDATE', $job_items, $job_id ), ARRAY_A );
			if ( count( $locked_items ) > self::MAX_EVIDENCE_ROWS ) { return null; }
			foreach ( $locked_items as $row ) {
				if ( in_array( $row['state'], array( Job_Item_State::PENDING, Job_Item_State::APPLYING, Job_Item_State::NEEDS_REVIEW ), true ) ) { return $zero; }
			}
			$locked_undo_items = array();
			if ( $locked_operation ) {
				$undo_items = Undo_Schema::items_table( $tx );
				$locked_undo_items = $tx->get_results( $tx->prepare( 'SELECT id,state FROM %i WHERE undo_id=%d FOR UPDATE', $undo_items, (int) $locked_operation['id'] ), ARRAY_A );
				if ( count( $locked_undo_items ) > self::MAX_EVIDENCE_ROWS ) { return null; }
				foreach ( $locked_undo_items as $row ) {
					if ( in_array( $row['state'], array( Undo_Item_State::PENDING, Undo_Item_State::APPLYING, Undo_Item_State::NEEDS_REVIEW ), true ) ) { return $zero; }
				}
			}
			$journal = Price_Apply_Journal::table( $tx );
			$locked_journal = $tx->get_results( $tx->prepare( 'SELECT id FROM %i WHERE plan_id=%s FOR UPDATE', $journal, $locked_job['plan_id'] ), ARRAY_A );
			if ( count( $locked_journal ) > self::MAX_EVIDENCE_ROWS ) { return null; }
			// 5. Delete in the reviewed logical order; each statement must
			// affect exactly the locked set or the whole candidate rolls back.
			$purged = $zero;
			if ( $locked_operation ) {
				$undo_items = Undo_Schema::items_table( $tx );
				self::purge_checkpoint( 'PURGE_BEFORE_UNDO_ITEMS_DELETE', $job_id );
				if ( count( $locked_undo_items ) !== (int) $tx->query( $tx->prepare( 'DELETE FROM %i WHERE undo_id=%d', $undo_items, (int) $locked_operation['id'] ) ) ) { throw new Undo_Error( 'PURGE_INVARIANT' ); }
				self::purge_checkpoint( 'PURGE_AFTER_UNDO_ITEMS_DELETE', $job_id );
				self::purge_checkpoint( 'PURGE_BEFORE_UNDO_OPERATION_DELETE', $job_id );
				if ( 1 !== (int) $tx->query( $tx->prepare( 'DELETE FROM %i WHERE id=%d', $operations, (int) $locked_operation['id'] ) ) ) { throw new Undo_Error( 'PURGE_INVARIANT' ); }
				self::purge_checkpoint( 'PURGE_AFTER_UNDO_OPERATION_DELETE', $job_id );
				$purged['undo_items'] = count( $locked_undo_items );
				$purged['undo_operations'] = 1;
			}
			self::purge_checkpoint( 'PURGE_BEFORE_JOB_ITEMS_DELETE', $job_id );
			if ( count( $locked_items ) !== (int) $tx->query( $tx->prepare( 'DELETE FROM %i WHERE job_id=%d', $job_items, $job_id ) ) ) { throw new Undo_Error( 'PURGE_INVARIANT' ); }
			self::purge_checkpoint( 'PURGE_AFTER_JOB_ITEMS_DELETE', $job_id );
			self::purge_checkpoint( 'PURGE_BEFORE_JOURNAL_DELETE', $job_id );
			if ( count( $locked_journal ) !== (int) $tx->query( $tx->prepare( 'DELETE FROM %i WHERE plan_id=%s', $journal, $locked_job['plan_id'] ) ) ) { throw new Undo_Error( 'PURGE_INVARIANT' ); }
			self::purge_checkpoint( 'PURGE_AFTER_JOURNAL_DELETE', $job_id );
			self::purge_checkpoint( 'PURGE_BEFORE_JOB_DELETE', $job_id );
			if ( 1 !== (int) $tx->query( $tx->prepare( 'DELETE FROM %i WHERE id=%d', $jobs, $job_id ) ) ) { throw new Undo_Error( 'PURGE_INVARIANT' ); }
			self::purge_checkpoint( 'PURGE_AFTER_JOB_DELETE', $job_id );
			$purged['jobs'] = 1;
			$purged['job_items'] = count( $locked_items );
			$purged['journal_rows'] = count( $locked_journal );
			$tx->commit();
			$committed = true;
			$wpdb = $original;
			return $purged;
		} catch ( \Throwable $error ) {
			$wpdb = $original;
			if ( ! $committed && $tx && $tx->owns_attempt() ) { $tx->rollback(); }
			return null;
		} finally {
			$wpdb = $original;
			if ( $tx ) { $tx->rollback(); }
		}
	}

	/**
	 * Storage evidence for the retention design: exact row counts and table
	 * bytes for the five Free tables. information_schema row estimates are
	 * deliberately not used: InnoDB estimates go stale after deletes, which
	 * would make retention accounting untruthful. No product/customer data
	 * is read.
	 */
	public static function storage_estimate(): array {
		$db = self::db();
		$tables = array(
			'jobs' => Job_Schema::jobs_table( $db ),
			'job_items' => Job_Schema::items_table( $db ),
			'journal' => Price_Apply_Journal::table( $db ),
			'undo_operations' => Undo_Schema::operations_table( $db ),
			'undo_items' => Undo_Schema::items_table( $db ),
		);
		$estimate = array( 'tables' => array(), 'total_rows' => 0, 'total_bytes' => 0 );
		foreach ( $tables as $name => $table ) {
			$rows = (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			$sizes = $db->get_row( $db->prepare( 'SELECT DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ), ARRAY_A );
			$bytes = $sizes ? ( (int) $sizes['data_bytes'] + (int) $sizes['index_bytes'] ) : 0;
			$estimate['tables'][$name] = array( 'rows' => $rows, 'bytes' => $bytes );
			$estimate['total_rows'] += $rows;
			$estimate['total_bytes'] += $bytes;
		}
		$estimate['retention_days'] = self::retention_days();
		return $estimate;
	}
}
