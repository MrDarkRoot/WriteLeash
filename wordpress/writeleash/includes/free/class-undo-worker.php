<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Bounded durable Undo worker.
 *
 * One invocation processes at most `max_items` Undo items and stops before
 * `budget_seconds`. Every Undo mutation is the short Woo_Undo_Mutator
 * transaction; this class owns orchestration, never price SQL, selector
 * queries or arithmetic. It consumes only hydrated frozen #107 material and
 * durable Undo provenance.
 *
 * Fence boundary: the Undo lease (owner + generation) is verified before each
 * claim, again before dispatch, and finally inside the Undo item transaction
 * by an operation-row lock plus the shared apply job-row lock, both held
 * until COMMIT/ROLLBACK. An Undo transaction that successfully acquires both
 * locks may finish before takeover; takeover cannot become authoritative
 * until it commits or rolls back. Once a newer generation is authoritative,
 * an older worker cannot begin or commit a new Undo mutation.
 *
 * Apply/Undo mutual exclusion: Undo initiation and every Undo claim require
 * the parent apply job to be COMPLETED/COMPLETED_WITH_ISSUES, a state in
 * which no apply worker can acquire a lease or claim an item. Inside the
 * Undo transaction the shared job-row lock additionally serializes against
 * any in-flight apply item transaction.
 */
final class Undo_Worker {
	public const DEFAULT_MAX_ITEMS = 10;
	public const DEFAULT_BUDGET_SECONDS = 15;

	/** Action Scheduler callback. Never throws: terminal domain states must not retry forever. */
	public static function callback( $undo_id ): void {
		try { self::run( (int) $undo_id, array() ); }
		catch ( \Throwable $error ) {
			// run() persists its own typed pause/review state; nothing safe to rethrow.
		}
	}

	public static function limits( array $override = array() ): array {
		$limits = apply_filters( 'writeleash_undo_limits', array( 'max_items' => self::DEFAULT_MAX_ITEMS, 'budget_seconds' => self::DEFAULT_BUDGET_SECONDS ) );
		$limits = is_array( $limits ) ? $limits : array();
		$limits = array_merge( array( 'max_items' => self::DEFAULT_MAX_ITEMS, 'budget_seconds' => self::DEFAULT_BUDGET_SECONDS ), $limits, $override );
		return array( 'max_items' => max( 1, min( 100, (int) $limits['max_items'] ) ), 'budget_seconds' => max( 1, min( 60, (int) $limits['budget_seconds'] ) ) );
	}

	/** Queue an initiated Undo operation and record a truthful wait state when the scheduler cannot accept it. */
	public static function queue_undo( int $undo_id ): string {
		$operation = Undo_Repository::read_operation( $undo_id );
		if ( ! $operation || ! in_array( $operation['status'], array( Undo_State::PENDING, Undo_State::RUNNING ), true ) ) { return 'NOT_EXECUTABLE'; }
		if ( 0 === Undo_Scheduler::enqueue( $undo_id ) ) {
			Undo_Repository::mark_paused( $undo_id, 'SCHEDULER_UNAVAILABLE' );
			return 'SCHEDULER_UNAVAILABLE';
		}
		return 'SCHEDULED';
	}

	public static function run( int $undo_id, array $limits = array(), bool $manual = false ): array {
		global $wpdb;
		$limits = self::limits( $limits );
		$started = microtime( true );
		if ( ! self::schema_ok() ) {
			if ( Undo_Schema::operations_table_present( $wpdb ) ) { self::pause_unleased( $undo_id, 'SCHEMA_UNAVAILABLE' ); }
			return self::result( $undo_id, 'SCHEMA_UNAVAILABLE', 0, false, null );
		}
		$operation = Undo_Repository::read_operation( $undo_id );
		if ( ! $operation ) { return self::result( $undo_id, 'UNDO_NOT_ELIGIBLE', 0, null, null ); }
		$job = Job_Repository::read( (int) $operation['job_id'] );
		if ( ! $job || ! in_array( $job['status'], array( Job_State::COMPLETED, Job_State::COMPLETED_WITH_ISSUES ), true ) || Undo_Repository::retention_expired_for( $job, $operation ) ) {
			self::pause_unleased( $undo_id, 'UNDO_NOT_ELIGIBLE' );
			return self::result( $undo_id, 'UNDO_NOT_ELIGIBLE', 0, null, null );
		}
		if ( ! self::dependency_ok() ) {
			self::pause_unleased( $undo_id, 'DEPENDENCY_UNAVAILABLE' );
			return self::result( $undo_id, 'DEPENDENCY_UNAVAILABLE', 0, null, null );
		}
		if ( ! self::runner_active() ) {
			self::pause_unleased( $undo_id, 'DEACTIVATED' );
			return self::result( $undo_id, 'DEACTIVATED', 0, null, null );
		}
		$executable = $manual ? Undo_State::can_manual_run( $operation['status'] ) : Undo_State::can_auto_run( $operation['status'] );
		if ( ! $executable ) {
			$stop = Undo_State::is_terminal( $operation['status'] ) ? 'UNDO_TERMINAL' : 'NOT_EXECUTABLE';
			return self::result( $undo_id, $stop, 0, $operation, null );
		}
		$owner = wp_generate_uuid4();
		$lease = Undo_Repository::acquire_lease( $undo_id, $owner, $manual );
		if ( ! $lease ) { return self::result( $undo_id, 'LEASE_HELD', 0, Undo_Repository::read_operation( $undo_id ), null ); }
		$generation = (int) $lease['generation'];
		$processed = 0;
		try {
			self::checkpoint( 'AFTER_LEASE', $undo_id, (int) $job['id'], 0, $generation, '' );
			$plan = Job_Repository::hydrate_plan( Job_Repository::read( (int) $job['id'] ) );
			$reconcile = self::reconcile( $undo_id, $generation );
			if ( null !== $reconcile ) {
				Undo_Repository::finish_chunk( Undo_Repository::read_operation( $undo_id ), $owner, $generation, Undo_State::PAUSED, $reconcile );
				return self::result( $undo_id, $reconcile, 0, null, $generation );
			}
			$loop = self::process( $undo_id, (int) $job['id'], $owner, $generation, $plan, $limits, $started );
			$processed = $loop['processed'];
		} catch ( \Throwable $error ) {
			$current = Undo_Repository::read_operation( $undo_id );
			if ( $current && Undo_Repository::fence( $undo_id, $owner, $generation ) ) {
				try { Undo_Repository::finish_chunk( $current, $owner, $generation, Undo_State::NEEDS_REVIEW, 'UNDO_WORKER_EXCEPTION' ); }
				catch ( \Throwable $inner ) {}
			}
			return self::result( $undo_id, 'UNDO_WORKER_EXCEPTION', $processed, null, $generation );
		}
		$current = Undo_Repository::read_operation( $undo_id );
		if ( ! $current || ! Undo_Repository::fence( $undo_id, $owner, $generation ) ) {
			return self::result( $undo_id, 'FENCE_LOST', $processed, $current, $generation );
		}
		if ( null !== $loop['pause'] ) {
			$status = $loop['review'] ? Undo_State::NEEDS_REVIEW : Undo_State::PAUSED;
			Undo_Repository::finish_chunk( $current, $owner, $generation, $status, $loop['pause'] );
			return self::result( $undo_id, $loop['pause'], $processed, null, $generation );
		}
		if ( ! self::runner_active() ) {
			Undo_Repository::finish_chunk( $current, $owner, $generation, Undo_State::PAUSED, 'DEACTIVATED' );
			return self::result( $undo_id, 'DEACTIVATED', $processed, null, $generation );
		}
		$counts = Undo_Repository::counts( $undo_id );
		if ( 0 === $counts['pending'] && 0 === $counts['applying'] ) {
			$terminal = Undo_Repository::derive_terminal_status( $counts );
			$reason = Undo_Repository::terminal_reason( $terminal );
			Undo_Repository::finish_chunk( $current, $owner, $generation, $terminal, $reason );
			return self::result( $undo_id, $reason, $processed, null, $generation, $counts );
		}
		$stop = 'NO_ITEMS' === $loop['stop'] && $counts['pending'] > 0 ? 'UNDO_RETRY_BACKOFF' : $loop['stop'];
		Undo_Repository::finish_chunk( $current, $owner, $generation, Undo_State::PENDING, $stop );
		if ( 0 === Undo_Scheduler::enqueue( $undo_id ) ) {
			Undo_Repository::mark_paused( $undo_id, 'SCHEDULER_UNAVAILABLE' );
			$stop = 'SCHEDULER_UNAVAILABLE';
		}
		return self::result( $undo_id, $stop, $processed, null, $generation, $counts );
	}

	/** One bounded chunk under an acquired fence. */
	private static function process( int $undo_id, int $job_id, string $owner, int $generation, Change_Plan $plan, array $limits, float $started ): array {
		global $wpdb;
		$processed = 0;
		$stop = 'NO_ITEMS';
		$pause = null;
		$review = false;
		while ( true ) {
			if ( $processed >= $limits['max_items'] ) { $stop = 'UNDO_BATCH_LIMIT'; break; }
			if ( microtime( true ) - $started >= $limits['budget_seconds'] ) { $stop = 'UNDO_BUDGET_EXHAUSTED'; break; }
			if ( ! self::runner_active() ) { $pause = 'DEACTIVATED'; break; }
			self::checkpoint( 'AFTER_RUNNER_ACTIVE_BEFORE_CLAIM', $undo_id, $job_id, 0, $generation, '' );
			if ( ! Undo_Repository::renew_lease( $undo_id, $owner, $generation ) ) { $stop = 'FENCE_LOST'; break; }
			$token = wp_generate_uuid4();
			$item = Undo_Repository::claim_next_item( $undo_id, $owner, $token, $generation );
			if ( ! $item ) {
				if ( ! self::runner_active() ) { $pause = 'DEACTIVATED'; break; }
				$stop = Undo_Repository::fence( $undo_id, $owner, $generation ) ? 'NO_ITEMS' : 'FENCE_LOST';
				break;
			}
			self::checkpoint( 'AFTER_CLAIM', $undo_id, $job_id, (int) $item['id'], $generation, $token );
			if ( ! Undo_Repository::fence( $undo_id, $owner, $generation ) ) {
				Undo_Repository::reconcile_item( $item, $generation, Undo_Item_State::PENDING, 'RECONCILED_NO_COMMIT' );
				$stop = 'FENCE_LOST';
				break;
			}
			$outcome = self::attempt_item( $undo_id, $job_id, $owner, $generation, $plan, $item, $token );
			if ( ! empty( $outcome['lifecycle_lost'] ) ) {
				Undo_Repository::refresh_counters( $wpdb, $undo_id );
				$pause = 'DEACTIVATED';
				break;
			}
			if ( ! empty( $outcome['fence_lost'] ) ) { $stop = 'FENCE_LOST'; break; }
			++$processed;
			Undo_Repository::refresh_counters( $wpdb, $undo_id );
			self::checkpoint( 'AFTER_ITEM', $undo_id, $job_id, (int) $item['id'], $generation, $token );
			if ( null !== $outcome['pause'] ) { $pause = $outcome['pause']; $review = $outcome['review']; break; }
		}
		return array( 'processed' => $processed, 'stop' => $stop, 'pause' => $pause, 'review' => $review );
	}

	/** One Undo item transaction plus durable outcome handling. */
	private static function attempt_item( int $undo_id, int $job_id, string $owner, int $generation, Change_Plan $plan, array $item, string $token ): array {
		$operation = Undo_Repository::read_operation( $undo_id );
		if ( ! $operation || $operation['plan_id'] !== $plan->data()['plan_id'] || (int) $item['undo_id'] !== $undo_id || (int) $item['job_id'] !== $job_id ) {
			Undo_Repository::reconcile_item( $item, $generation, Undo_Item_State::NEEDS_REVIEW, 'UNDO_PROVENANCE_MISMATCH' );
			return array( 'pause' => 'UNDO_ITEM_NEEDS_REVIEW', 'review' => true );
		}
		$product_id = (int) $item['product_id'];
		$previous_user = get_current_user_id();
		// The recorded initiator is re-authorized fresh inside the mutator;
		// this is not root and never a substitute identity.
		wp_set_current_user( (int) $operation['initiator_id'] );
		self::checkpoint( 'BEFORE_MUTATION', $undo_id, $job_id, (int) $item['id'], $generation, $token );
		try {
			// Transactional Undo fence: same connection, same transaction,
			// held until COMMIT/ROLLBACK. A lost fence writes no Woo data.
			$result = Woo_Undo_Mutator::restore( $job_id, $undo_id, $product_id, wp_generate_uuid4(), new Undo_Transaction_Fence( $undo_id, $job_id, $owner, $generation ) );
		} catch ( \Throwable $error ) {
			$result = array( 'code' => 'NEEDS_REVIEW', 'reason' => 'UNEXPECTED_MUTATION_RESULT' );
		} finally {
			wp_set_current_user( $previous_user );
		}
		$code = is_array( $result ) && isset( $result['code'] ) ? (string) $result['code'] : 'NEEDS_REVIEW';
		$reason = is_array( $result ) && isset( $result['reason'] ) && preg_match( '/\A[A-Z0-9_]{1,64}\z/D', (string) $result['reason'] ) ? (string) $result['reason'] : 'UNEXPECTED_MUTATION_RESULT';
		// The mutator persists terminal states itself; adopt durable truth.
		$fresh = Undo_Repository::read_item( self::db(), $job_id, $product_id );
		$own_attempt = is_array( $result ) && isset( $result['attempt_id'] ) ? (string) $result['attempt_id'] : '';
		if ( 'DEACTIVATED' === $code ) {
			if ( ! $fresh || ! Undo_Repository::release_lifecycle_claim( $fresh, $token ) ) {
				return array( 'pause' => null, 'review' => false, 'fence_lost' => true );
			}
			return array( 'pause' => 'DEACTIVATED', 'review' => false, 'lifecycle_lost' => true );
		}
		if ( $fresh && Undo_Item_State::UNDONE === $fresh['state'] ) {
			Undo_Repository::clear_claim( (int) $fresh['id'], $token );
			// Our own commit with a lost acknowledgement is ambiguous, never
			// silent success: pause for review; a later run adopts ALREADY_UNDONE.
			if ( 'NEEDS_REVIEW' === $code && '' !== $own_attempt && $fresh['attempt_id'] === $own_attempt ) {
				return array( 'pause' => 'UNDO_ITEM_NEEDS_REVIEW', 'review' => true );
			}
			return array( 'pause' => null, 'review' => false );
		}
		if ( 'FENCE_LOST' === $code ) {
			if ( $fresh && in_array( $fresh['state'], array( Undo_Item_State::PENDING, Undo_Item_State::APPLYING ), true ) ) {
				Undo_Repository::clear_claim( (int) $fresh['id'], $token );
			}
			return array( 'pause' => null, 'review' => false, 'fence_lost' => true );
		}
		if ( 'UNDONE' === $code || 'ALREADY_UNDONE' === $code ) {
			if ( $fresh ) { Undo_Repository::clear_claim( (int) $fresh['id'], $token ); }
			return array( 'pause' => null, 'review' => false );
		}
		if ( 'UNDO_CONFLICT' === $code ) {
			if ( $fresh && Undo_Item_State::CONFLICT === $fresh['state'] ) { Undo_Repository::clear_claim( (int) $fresh['id'], $token ); }
			elseif ( $fresh && in_array( $fresh['state'], array( Undo_Item_State::PENDING, Undo_Item_State::APPLYING ), true ) ) {
				Undo_Repository::record_item( $fresh, $token, Undo_Item_State::CONFLICT, $reason );
			}
			return array( 'pause' => null, 'review' => false );
		}
		if ( 'PERMISSION_DENIED' === $code ) {
			if ( $fresh && in_array( $fresh['state'], array( Undo_Item_State::PENDING, Undo_Item_State::APPLYING ), true ) ) {
				Undo_Repository::record_item( $fresh, $token, Undo_Item_State::FAILED, 'PERMISSION_DENIED' );
			} elseif ( $fresh ) { Undo_Repository::clear_claim( (int) $fresh['id'], $token ); }
			return array( 'pause' => 'PERMISSION_REVOKED', 'review' => false );
		}
		if ( 'UNSUPPORTED_PRODUCT_STATE' === $code ) {
			if ( $fresh && in_array( $fresh['state'], array( Undo_Item_State::PENDING, Undo_Item_State::APPLYING ), true ) ) {
				Undo_Repository::record_item( $fresh, $token, Undo_Item_State::FAILED, 'UNSUPPORTED_PRODUCT_STATE' );
			} elseif ( $fresh ) { Undo_Repository::clear_claim( (int) $fresh['id'], $token ); }
			return array( 'pause' => null, 'review' => false );
		}
		if ( 'TRANSACTION_UNAVAILABLE' === $code ) {
			if ( $fresh ) { Undo_Repository::clear_claim( (int) $fresh['id'], $token ); }
			return array( 'pause' => 'SCHEMA_UNAVAILABLE', 'review' => false );
		}
		if ( 'UNDO_EXPIRED' === $code || 'UNDO_NOT_ELIGIBLE' === $code ) {
			if ( $fresh ) { Undo_Repository::clear_claim( (int) $fresh['id'], $token ); }
			return array( 'pause' => 'UNDO_EXPIRED', 'review' => false );
		}
		if ( 'FAILED' === $code ) {
			// Known rollback: the refusal left UNDO_PENDING; arm the backoff.
			if ( $fresh ) {
				Undo_Repository::defer_item( (int) $fresh['id'], $token );
			}
			return array( 'pause' => null, 'review' => false );
		}
		if ( $fresh && in_array( $fresh['state'], array( Undo_Item_State::PENDING, Undo_Item_State::APPLYING ), true ) ) {
			Undo_Repository::record_item( $fresh, $token, Undo_Item_State::NEEDS_REVIEW, $reason );
		} elseif ( $fresh ) { Undo_Repository::clear_claim( (int) $fresh['id'], $token ); }
		return array( 'pause' => 'UNDO_ITEM_NEEDS_REVIEW', 'review' => true );
	}

	/**
	 * Reconcile stale claims before any new claim. Undo journal truth is the
	 * row itself: UNDONE (or any terminal state) is adopted without Woo
	 * replay; a stale non-terminal row proves no durable mutation exists
	 * (mutation and terminal state share one transaction) and returns to
	 * UNDO_PENDING. Stale tokens on terminal rows are cleared as housekeeping.
	 */
	private static function reconcile( int $undo_id, int $generation ): ?string {
		global $wpdb;
		$stale = Undo_Repository::stale_claims( $undo_id, $generation );
		if ( ! $stale ) { return null; }
		foreach ( $stale as $item ) {
			if ( Undo_Item_State::is_terminal( $item['state'] ) ) {
				Undo_Repository::clear_claim( (int) $item['id'], (string) $item['claim_token'] );
				continue;
			}
			Undo_Repository::reconcile_item( $item, $generation, Undo_Item_State::PENDING, 'RECONCILED_NO_COMMIT' );
		}
		Undo_Repository::refresh_counters( $wpdb, $undo_id );
		return null;
	}

	private static function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	private static function schema_ok(): bool {
		global $wpdb;
		if ( ! Undo_Schema::ready( $wpdb ) || ! Job_Schema::ready( $wpdb ) ) { return false; }
		$journal = Price_Apply_Journal::table( $wpdb );
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
	 * unknown values all return false, so a surviving Undo worker stops
	 * before its next claim. An item transaction that already acquired its
	 * authoritative fence may still finish that one boundary, exactly as
	 * reviewed in #108/#109/#110.
	 */
	private static function runner_active(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lifecycle gate; uncached read is intentional.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", 'writeleash_runner_state' ) );
		return 'active' === $value;
	}

	private static function pause_unleased( int $undo_id, string $reason ): void {
		try {
			if ( Undo_Repository::mark_paused( $undo_id, $reason ) ) { return; }
			Undo_Repository::pause_stalled( $undo_id, $reason );
		} catch ( \Throwable $error ) {
			// Persisting the typed pause is best effort; no product mutation happens either way.
		}
	}

	private static function checkpoint( string $point, int $undo_id, int $job_id, int $item_id, int $generation, string $token ): void {
		do_action( 'writeleash_undo_checkpoint', $point, $undo_id, $job_id, $item_id, $generation, $token );
	}

	/** @param array|false|null $operation false means the schema was unreadable; never re-query then. */
	private static function result( int $undo_id, string $stop, int $processed, $operation, ?int $generation, ?array $counts = null ): array {
		if ( null === $operation ) { try { $operation = Undo_Repository::read_operation( $undo_id ); } catch ( \Throwable $error ) { $operation = false; } }
		if ( false === $operation ) { $operation = null; }
		if ( null === $counts ) { try { $counts = $operation ? Undo_Repository::counts( $undo_id ) : array(); } catch ( \Throwable $error ) { $counts = array(); } }
		return array(
			'undo_id' => $undo_id,
			'stop' => $stop,
			'processed' => $processed,
			'status' => $operation['status'] ?? null,
			'reason' => $operation['status_reason'] ?? null,
			'generation' => $generation,
			'counts' => $counts,
		);
	}
}
