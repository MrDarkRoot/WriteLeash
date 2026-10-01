<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded durable job worker.
 *
 * One invocation processes at most `max_items` items and stops before
 * `budget_seconds` to stay inside ordinary host limits. Every item mutation is
 * the short #108 transaction; this class owns orchestration, never price SQL,
 * selector queries or arithmetic.
 *
 * Fence boundary: the job lease (owner + generation) is verified before each
 * claim, again before dispatch, and finally inside the #108 item transaction by
 * a job-row lock held until that transaction COMMIT/ROLLBACK. An item
 * transaction that successfully acquires the current fence lock may finish
 * before takeover; takeover cannot become authoritative until it commits or
 * rolls back. Once a newer generation is authoritative, an older worker cannot
 * begin or commit a new item mutation: the inner fence returns FENCE_LOST with
 * zero Woo writes and no journal APPLIED transition.
 */
final class Job_Worker {
	public const DEFAULT_MAX_ITEMS = 10;
	public const DEFAULT_BUDGET_SECONDS = 15;

	/** Action Scheduler callback. Never throws: terminal domain states must not retry forever. */
	public static function callback( $job_id ): void {
		try { self::run( (int) $job_id, array() ); }
		catch ( \Throwable $error ) {
			// run() persists its own typed pause/review state; nothing safe to rethrow.
		}
	}

	public static function limits( array $override = array() ): array {
		$limits = apply_filters( 'writeleash_job_limits', array( 'max_items' => self::DEFAULT_MAX_ITEMS, 'budget_seconds' => self::DEFAULT_BUDGET_SECONDS ) );
		$limits = is_array( $limits ) ? $limits : array();
		$limits = array_merge( array( 'max_items' => self::DEFAULT_MAX_ITEMS, 'budget_seconds' => self::DEFAULT_BUDGET_SECONDS ), $limits, $override );
		return array( 'max_items' => max( 1, min( 100, (int) $limits['max_items'] ) ), 'budget_seconds' => max( 1, min( 60, (int) $limits['budget_seconds'] ) ) );
	}

	/** Queue an approved job and record a truthful wait state when the scheduler cannot accept it. */
	public static function queue_job( int $job_id ): string {
		$job = Job_Repository::read( $job_id );
		if ( ! $job || ! in_array( $job['status'], array( Job_State::READY, Job_State::QUEUED ), true ) ) { return 'NOT_EXECUTABLE'; }
		if ( 0 === Job_Scheduler::enqueue( $job_id ) ) {
			Job_Repository::mark_paused( $job_id, 'SCHEDULER_UNAVAILABLE' );
			return 'SCHEDULER_UNAVAILABLE';
		}
		Job_Repository::transition_unlocked( $job_id, $job['status'], Job_State::QUEUED, 'SCHEDULED' );
		return 'SCHEDULED';
	}

	public static function run( int $job_id, array $limits = array(), bool $manual = false ): array {
		global $wpdb;
		$limits = self::limits( $limits );
		$started = microtime( true );
		// Schema gates precede the first job-table read so a missing table is a
		// typed refusal, never a database error against an unknown shape.
		if ( ! self::schema_ok() ) {
			if ( Job_Schema::jobs_table_present( $wpdb ) ) { self::pause_unleased( $job_id, 'SCHEMA_UNAVAILABLE' ); }
			return self::result( $job_id, 'SCHEMA_UNAVAILABLE', 0, false, null );
		}
		$job = Job_Repository::read( $job_id );
		if ( ! $job ) { return self::result( $job_id, 'JOB_NOT_FOUND', 0, null, null ); }
		if ( ! self::dependency_ok() ) {
			self::pause_unleased( $job_id, 'DEPENDENCY_UNAVAILABLE' );
			return self::result( $job_id, 'DEPENDENCY_UNAVAILABLE', 0, null, null );
		}
		if ( ! self::runner_active() ) {
			self::pause_unleased( $job_id, 'DEACTIVATED' );
			return self::result( $job_id, 'DEACTIVATED', 0, null, null );
		}
		$executable = $manual ? Job_State::can_manual_run( $job['status'] ) : Job_State::can_auto_run( $job['status'] );
		if ( ! $executable ) {
			$stop = Job_State::is_terminal( $job['status'] ) ? 'JOB_TERMINAL' : 'NOT_EXECUTABLE';
			return self::result( $job_id, $stop, 0, $job, null );
		}
		$owner = wp_generate_uuid4();
		$lease = Job_Repository::acquire_lease( $job_id, $owner, $manual );
		if ( ! $lease ) { return self::result( $job_id, 'LEASE_HELD', 0, Job_Repository::read( $job_id ), null ); }
		$generation = (int) $lease['generation'];
		$processed = 0;
		try {
			self::checkpoint( 'AFTER_LEASE', $job_id, 0, $generation, '' );
			$plan = Job_Repository::hydrate_plan( Job_Repository::read( $job_id ) );
			$reconcile = self::reconcile( $job_id, $generation, $plan );
			if ( null !== $reconcile ) {
				Job_Repository::finish_chunk( Job_Repository::read( $job_id ), $owner, $generation, Job_State::PAUSED, $reconcile );
				return self::result( $job_id, $reconcile, 0, null, $generation );
			}
			$loop = self::process( $job_id, $owner, $generation, $plan, $limits, $started );
			$processed = $loop['processed'];
		} catch ( \Throwable $error ) {
			$reason = $error instanceof Job_Error && 'JOB_MATERIAL_MISMATCH' === $error->reason() ? 'JOB_MATERIAL_MISMATCH' : 'WORKER_EXCEPTION';
			$current = Job_Repository::read( $job_id );
			if ( $current && Job_Repository::fence( $job_id, $owner, $generation ) ) {
				try { Job_Repository::finish_chunk( $current, $owner, $generation, Job_State::NEEDS_REVIEW, $reason ); }
				catch ( \Throwable $inner ) {}
			}
			return self::result( $job_id, $reason, $processed, null, $generation );
		}
		$current = Job_Repository::read( $job_id );
		if ( ! $current || ! Job_Repository::fence( $job_id, $owner, $generation ) ) {
			return self::result( $job_id, 'FENCE_LOST', $processed, $current, $generation );
		}
		if ( null !== $loop['pause'] ) {
			$status = $loop['review'] ? Job_State::NEEDS_REVIEW : Job_State::PAUSED;
			Job_Repository::finish_chunk( $current, $owner, $generation, $status, $loop['pause'] );
			return self::result( $job_id, $loop['pause'], $processed, null, $generation );
		}
		$counts = Job_Repository::counts( $job_id );
		if ( 0 === $counts['pending'] && 0 === $counts['applying'] ) {
			$terminal = Job_Repository::derive_terminal_status( $counts );
			$reason = Job_Repository::terminal_reason( $terminal );
			Job_Repository::finish_chunk( $current, $owner, $generation, $terminal, $reason );
			return self::result( $job_id, $reason, $processed, null, $generation, $counts );
		}
		// Release the fence before enqueueing: a wake-up can never be stale-lost
		// while this worker still holds an unexpired lease.
		$stop = 'NO_ITEMS' === $loop['stop'] && $counts['pending'] > 0 ? 'RETRY_BACKOFF' : $loop['stop'];
		Job_Repository::finish_chunk( $current, $owner, $generation, Job_State::QUEUED, $stop );
		if ( 0 === Job_Scheduler::enqueue( $job_id ) ) {
			Job_Repository::mark_paused( $job_id, 'SCHEDULER_UNAVAILABLE' );
			$stop = 'SCHEDULER_UNAVAILABLE';
		}
		return self::result( $job_id, $stop, $processed, null, $generation, $counts );
	}

	/** One bounded chunk under an acquired fence. */
	private static function process( int $job_id, string $owner, int $generation, Change_Plan $plan, array $limits, float $started ): array {
		global $wpdb;
		$processed = 0;
		$stop = 'NO_ITEMS';
		$pause = null;
		$review = false;
		while ( true ) {
			if ( $processed >= $limits['max_items'] ) { $stop = 'BATCH_LIMIT'; break; }
			if ( microtime( true ) - $started >= $limits['budget_seconds'] ) { $stop = 'BUDGET_EXHAUSTED'; break; }
			if ( ! self::runner_active() ) { $pause = 'DEACTIVATED'; break; }
			if ( ! Job_Repository::renew_lease( $job_id, $owner, $generation ) ) { $stop = 'FENCE_LOST'; break; }
			$token = wp_generate_uuid4();
			$item = Job_Repository::claim_next_item( $job_id, $owner, $token, $generation );
			if ( ! $item ) {
				$stop = Job_Repository::fence( $job_id, $owner, $generation ) ? 'NO_ITEMS' : 'FENCE_LOST';
				break;
			}
			self::checkpoint( 'AFTER_CLAIM', $job_id, (int) $item['id'], $generation, $token );
			if ( ! Job_Repository::fence( $job_id, $owner, $generation ) ) {
				// The claimed item is not mutated; return it for the new generation.
				Job_Repository::record_item( $item, $token, Job_Item_State::PENDING, 'RECONCILED_NO_COMMIT' );
				$stop = 'FENCE_LOST';
				break;
			}
			$outcome = self::attempt_item( $job_id, $owner, $generation, $plan, $item, $token );
			if ( ! empty( $outcome['fence_lost'] ) ) { $stop = 'FENCE_LOST'; break; }
			++$processed;
			Job_Repository::refresh_counters( $wpdb, $job_id );
			self::checkpoint( 'AFTER_ITEM', $job_id, (int) $item['id'], $generation, $token );
			if ( null !== $outcome['pause'] ) { $pause = $outcome['pause']; $review = $outcome['review']; break; }
		}
		return array( 'processed' => $processed, 'stop' => $stop, 'pause' => $pause, 'review' => $review );
	}

	/** One #108 item transaction plus durable outcome recording. */
	private static function attempt_item( int $job_id, string $owner, int $generation, Change_Plan $plan, array $item, string $token ): array {
		try { Job_Repository::assert_item_material( $plan, $item ); }
		catch ( \Throwable $error ) {
			Job_Repository::record_item( $item, $token, Job_Item_State::NEEDS_REVIEW, 'JOB_MATERIAL_MISMATCH' );
			return array( 'pause' => 'JOB_MATERIAL_MISMATCH', 'review' => true );
		}
		$product_id = (int) $item['product_id'];
		$previous_user = get_current_user_id();
		// The frozen actor is re-authorized fresh inside #108; this is not root.
		wp_set_current_user( (int) $plan->data()['actor_id'] );
		self::checkpoint( 'BEFORE_MUTATION', $job_id, (int) $item['id'], $generation, $token );
		try {
			// Transactional job-row fence: same connection, same transaction,
			// held until #108 COMMIT/ROLLBACK. A lost fence writes no Woo data.
			$result = Woo_Price_Mutator::apply( $plan, $product_id, new Job_Transaction_Fence( $job_id, $owner, $generation ) );
		} catch ( \Throwable $error ) {
			$result = array( 'code' => 'NEEDS_REVIEW', 'reason' => 'UNEXPECTED_MUTATION_RESULT' );
		} finally {
			wp_set_current_user( $previous_user );
		}
		$code = is_array( $result ) && isset( $result['code'] ) ? (string) $result['code'] : 'NEEDS_REVIEW';
		$reason = is_array( $result ) && isset( $result['reason'] ) && preg_match( '/\A[A-Z0-9_]{1,64}\z/D', (string) $result['reason'] ) ? (string) $result['reason'] : 'UNEXPECTED_MUTATION_RESULT';
		if ( 'FENCE_LOST' === $code ) {
			// A newer generation is authoritative; this worker must not retry
			// or claim another item. Returning the item to PENDING is best
			// effort: the new generation's reconciliation owns it.
			Job_Repository::record_item( $item, $token, Job_Item_State::PENDING, 'FENCE_LOST' );
			return array( 'pause' => null, 'review' => false, 'fence_lost' => true );
		}
		if ( 'APPLIED' === $code || 'ALREADY_APPLIED' === $code ) {
			Job_Repository::record_item( $item, $token, Job_Item_State::APPLIED, 'APPLIED' === $code ? 'WOO_CRUD_VERIFIED' : 'DURABLE_APPLIED', self::durable_applied_at( $plan, $product_id ) );
			return array( 'pause' => null, 'review' => false );
		}
		if ( 'CONFLICT' === $code ) {
			Job_Repository::record_item( $item, $token, Job_Item_State::CONFLICT, 'ITEM_CONFLICT' );
			return array( 'pause' => null, 'review' => false );
		}
		if ( 'PERMISSION_DENIED' === $code ) {
			Job_Repository::record_item( $item, $token, Job_Item_State::FAILED, 'PERMISSION_DENIED' );
			return array( 'pause' => 'PERMISSION_REVOKED', 'review' => false );
		}
		if ( 'UNSUPPORTED_PRODUCT_STATE' === $code ) {
			Job_Repository::record_item( $item, $token, Job_Item_State::FAILED, 'UNSUPPORTED_PRODUCT_STATE' );
			return array( 'pause' => null, 'review' => false );
		}
		if ( 'PLAN_POLICY_BLOCKED' === $code ) {
			Job_Repository::record_item( $item, $token, Job_Item_State::FAILED, 'PLAN_POLICY_BLOCKED' );
			return array( 'pause' => 'BLOCKED_BY_POLICY', 'review' => false );
		}
		if ( 'TRANSACTION_UNAVAILABLE' === $code ) {
			Job_Repository::record_item( $item, $token, Job_Item_State::PENDING, 'TRANSACTION_UNAVAILABLE', null, true );
			return array( 'pause' => 'SCHEMA_UNAVAILABLE', 'review' => false );
		}
		if ( 'FAILED' === $code ) {
			// #108 known caught rollback: journal is PENDING, bounded retry is safe.
			Job_Repository::record_item( $item, $token, Job_Item_State::PENDING, 'FAILED', null, true );
			return array( 'pause' => null, 'review' => false );
		}
		Job_Repository::record_item( $item, $token, Job_Item_State::NEEDS_REVIEW, $reason );
		return array( 'pause' => 'ITEM_NEEDS_REVIEW', 'review' => true );
	}

	/**
	 * Reconcile stale claims before any new claim. Journal truth dominates:
	 * APPLIED is adopted without replay; other durable states propagate; a
	 * durable PENDING claim with no commit returns to PENDING.
	 */
	private static function reconcile( int $job_id, int $generation, Change_Plan $plan ): ?string {
		global $wpdb;
		$stale = Job_Repository::stale_claims( $job_id, $generation );
		if ( ! $stale ) { return null; }
		try { $observer = Price_Cache_Verifier::observer(); }
		catch ( \Throwable $error ) { return 'RECONCILE_UNAVAILABLE'; }
		try {
			foreach ( $stale as $item ) {
				$product_id = (int) $item['product_id'];
				$row = Price_Apply_Journal::read( $observer, $plan->data()['plan_id'], $product_id );
				$state = Job_Item_State::PENDING;
				$reason = 'RECONCILED_NO_COMMIT';
				$applied_at = null;
				if ( $row ) {
					try { Price_Apply_Journal::assert_binding( $row, $plan, $product_id ); }
					catch ( \Throwable $error ) {
						Job_Repository::reconcile_item( $item, $generation, Job_Item_State::NEEDS_REVIEW, 'JOB_MATERIAL_MISMATCH' );
						continue;
					}
					if ( 'APPLIED' === $row['state'] ) {
						$state = Job_Item_State::APPLIED;
						$reason = 'DURABLE_RECONCILED';
						$applied_at = $row['applied_at'] ?: null;
					} elseif ( 'NEEDS_REVIEW' === $row['state'] ) {
						$state = Job_Item_State::NEEDS_REVIEW;
						$reason = 'ITEM_NEEDS_REVIEW';
					} elseif ( 'CONFLICT' === $row['state'] ) {
						$state = Job_Item_State::CONFLICT;
						$reason = 'ITEM_CONFLICT';
					} elseif ( 'FAILED' === $row['state'] ) {
						$state = Job_Item_State::FAILED;
						$reason = preg_match( '/\A[A-Z0-9_]{1,64}\z/D', (string) $row['reason'] ) ? (string) $row['reason'] : 'FAILED';
					} elseif ( ! in_array( $row['state'], array( 'PENDING' ), true ) ) {
						// A durable APPLYING row has no commit evidence: never auto-retry.
						$state = Job_Item_State::NEEDS_REVIEW;
						$reason = 'UNEXPECTED_MUTATION_RESULT';
					}
				} else {
					$state = Job_Item_State::NEEDS_REVIEW;
					$reason = 'JOB_MATERIAL_MISMATCH';
				}
				Job_Repository::reconcile_item( $item, $generation, $state, $reason, $applied_at );
			}
			Job_Repository::refresh_counters( $wpdb, $job_id );
		} finally {
			$observer->close();
		}
		return null;
	}

	/** Exact durable APPLIED time from the journal; a display fallback never certifies success. */
	private static function durable_applied_at( Change_Plan $plan, int $product_id ): ?string {
		try {
			$observer = Price_Cache_Verifier::observer();
			try { $row = Price_Apply_Journal::read( $observer, $plan->data()['plan_id'], $product_id ); }
			finally { $observer->close(); }
			return $row && ! empty( $row['applied_at'] ) ? (string) $row['applied_at'] : null;
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	private static function schema_ok(): bool {
		global $wpdb;
		if ( ! Job_Schema::ready( $wpdb ) ) { return false; }
		$journal = Price_Apply_Journal::table( $wpdb );
		// Existence first: assert_schema issues SHOW INDEX, which must not run
		// against an absent table and produce a raw database diagnostic.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- information_schema existence probe; caching it would be wrong.
		$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $journal ) );
		if ( 1 !== $exists ) { return false; }
		try { Price_Apply_Journal::assert_schema( $wpdb ); return true; }
		catch ( \Throwable $error ) { return false; }
	}

	/** WooCommerce and its exact supported version must be active and initialized. */
	private static function dependency_ok(): bool {
		return defined( 'WC_VERSION' ) && '11.1.2' === WC_VERSION && function_exists( 'wc_get_product' ) && did_action( 'woocommerce_init' ) && ! is_multisite();
	}

	/**
	 * Durable lifecycle authority, read uncached directly from the options
	 * table. Fail-closed by design: only an explicit `active` value
	 * authorizes a new claim or mutation boundary. Missing (for example after
	 * uninstall removed the option), empty, `deactivated`, malformed and
	 * unknown values all return false, so a surviving worker stops before its
	 * next claim. An item transaction that already acquired its authoritative
	 * fence may still finish that one boundary, exactly as reviewed in
	 * #108/#109.
	 */
	private static function runner_active(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lifecycle gate; uncached read is intentional.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", 'writeleash_runner_state' ) );
		return 'active' === $value;
	}

	private static function pause_unleased( int $job_id, string $reason ): void {
		try {
			if ( Job_Repository::mark_paused( $job_id, $reason ) ) { return; }
			Job_Repository::pause_stalled( $job_id, $reason );
		} catch ( \Throwable $error ) {
			// Persisting the typed pause is best effort; no product mutation happens either way.
		}
	}

	private static function checkpoint( string $point, int $job_id, int $item_id, int $generation, string $token ): void {
		do_action( 'writeleash_job_checkpoint', $point, $job_id, $item_id, $generation, $token );
	}

	/** @param array|false|null $job false means the schema was unreadable; never re-query then. */
	private static function result( int $job_id, string $stop, int $processed, $job, ?int $generation, ?array $counts = null ): array {
		if ( null === $job ) { try { $job = Job_Repository::read( $job_id ); } catch ( \Throwable $error ) { $job = false; } }
		if ( false === $job ) { $job = null; }
		if ( null === $counts ) { try { $counts = $job ? Job_Repository::counts( $job_id ) : array(); } catch ( \Throwable $error ) { $counts = array(); } }
		return array(
			'job_id' => $job_id,
			'stop' => $stop,
			'processed' => $processed,
			'status' => $job['status'] ?? null,
			'reason' => $job['status_reason'] ?? null,
			'generation' => $generation,
			'counts' => $counts,
		);
	}
}
