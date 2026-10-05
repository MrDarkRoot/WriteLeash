<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** Typed orchestration failure. Machine reason codes are stable; display copy is separate. */
final class Job_Error extends \RuntimeException {
	private string $reason;
	public function __construct( string $reason ) {
		$this->reason = $reason;
		parent::__construct( $reason );
	}
	public function reason(): string { return $this->reason; }
}

/**
 * Durable job state machine. Illegal transitions are rejected by name; the
 * repository persists only transitions this table authorizes. No aliases.
 */
final class Job_State {
	public const DRAFT = 'DRAFT';
	public const PLANNING = 'PLANNING';
	public const PLANNED = 'PLANNED';
	public const BLOCKED = 'BLOCKED';
	public const READY = 'READY';
	public const QUEUED = 'QUEUED';
	public const RUNNING = 'RUNNING';
	public const PAUSED = 'PAUSED';
	public const COMPLETED = 'COMPLETED';
	public const COMPLETED_WITH_ISSUES = 'COMPLETED_WITH_ISSUES';
	public const NEEDS_REVIEW = 'NEEDS_REVIEW';
	public const CANCELLED = 'CANCELLED';

	private const TRANSITIONS = array(
		self::DRAFT => array( self::PLANNED, self::BLOCKED, self::CANCELLED ),
		self::PLANNING => array( self::PLANNED, self::BLOCKED, self::CANCELLED ),
		self::PLANNED => array( self::READY, self::BLOCKED, self::CANCELLED ),
		self::BLOCKED => array( self::CANCELLED ),
		self::READY => array( self::QUEUED, self::RUNNING, self::PAUSED, self::CANCELLED ),
		self::QUEUED => array( self::QUEUED, self::RUNNING, self::PAUSED, self::CANCELLED ),
		self::RUNNING => array( self::RUNNING, self::QUEUED, self::PAUSED, self::COMPLETED, self::COMPLETED_WITH_ISSUES, self::NEEDS_REVIEW, self::CANCELLED ),
		self::PAUSED => array( self::PAUSED, self::QUEUED, self::RUNNING, self::CANCELLED ),
		self::NEEDS_REVIEW => array( self::NEEDS_REVIEW, self::PAUSED, self::RUNNING, self::CANCELLED ),
		self::COMPLETED => array(),
		self::COMPLETED_WITH_ISSUES => array(),
		self::CANCELLED => array(),
	);

	public static function assert_transition( string $from, string $to ): void {
		if ( ! isset( self::TRANSITIONS[$from] ) || ! in_array( $to, self::TRANSITIONS[$from], true ) ) {
			throw new Job_Error( 'INVALID_TRANSITION' );
		}
	}
	public static function is_terminal( string $status ): bool {
		return in_array( $status, array( self::COMPLETED, self::COMPLETED_WITH_ISSUES, self::CANCELLED ), true );
	}
	/** Scheduler wake-ups may execute these states without an operator. */
	public static function can_auto_run( string $status ): bool {
		return in_array( $status, array( self::READY, self::QUEUED, self::RUNNING ), true );
	}
	/** Manual resume adds operator-owned resumable states only. */
	public static function can_manual_run( string $status ): bool {
		return self::can_auto_run( $status ) || in_array( $status, array( self::PAUSED, self::NEEDS_REVIEW ), true );
	}
	/** A cancelled or completed job never re-enters execution. */
	public static function is_executable( string $status ): bool {
		return self::can_auto_run( $status ) || in_array( $status, array( self::PAUSED, self::NEEDS_REVIEW ), true );
	}
}

/** Durable per-product state. CONFLICT and NEEDS_REVIEW never return to PENDING. */
final class Job_Item_State {
	public const PENDING = 'PENDING';
	public const APPLYING = 'APPLYING';
	public const APPLIED = 'APPLIED';
	public const UNCHANGED = 'UNCHANGED';
	public const CONFLICT = 'CONFLICT';
	public const FAILED = 'FAILED';
	public const NEEDS_REVIEW = 'NEEDS_REVIEW';
	public const UNSUPPORTED = 'UNSUPPORTED';

	private const TRANSITIONS = array(
		self::PENDING => array( self::APPLYING, self::APPLIED, self::CONFLICT, self::FAILED, self::NEEDS_REVIEW ),
		self::APPLYING => array( self::PENDING, self::APPLIED, self::CONFLICT, self::FAILED, self::NEEDS_REVIEW ),
		self::APPLIED => array(),
		self::UNCHANGED => array(),
		self::UNSUPPORTED => array(),
		self::CONFLICT => array(),
		self::FAILED => array(),
		self::NEEDS_REVIEW => array(),
	);

	public static function assert_transition( ?string $from, string $to ): void {
		if ( null === $from ) {
			if ( ! in_array( $to, array( self::PENDING, self::UNCHANGED, self::UNSUPPORTED ), true ) ) { throw new Job_Error( 'INVALID_ITEM_TRANSITION' ); }
			return;
		}
		if ( ! isset( self::TRANSITIONS[$from] ) || ! in_array( $to, self::TRANSITIONS[$from], true ) ) { throw new Job_Error( 'INVALID_ITEM_TRANSITION' ); }
	}
	public static function initial_for_result( string $result ): string {
		if ( 'CHANGING' === $result ) { return self::PENDING; }
		if ( 'UNCHANGED' === $result ) { return self::UNCHANGED; }
		if ( 'UNSUPPORTED' === $result ) { return self::UNSUPPORTED; }
		throw new Job_Error( 'INVALID_PLAN_ITEM_RESULT' );
	}
	public static function is_terminal( string $state ): bool {
		return ! in_array( $state, array( self::PENDING, self::APPLYING ), true );
	}
}

/** Stable machine reasons with separate display copy. Consumers must escape output. */
final class Job_Reason {
	public static function messages(): array {
		return array(
			'APPROVED' => 'The frozen plan was approved for execution.',
			'SCHEDULED' => 'A background wake-up is queued for this job.',
			'ACTIVE' => 'A worker is processing this job.',
			'BUDGET_EXHAUSTED' => 'The worker reached its time budget; more items remain.',
			'BATCH_LIMIT' => 'The worker reached its item limit; more items remain.',
			'RETRY_BACKOFF' => 'Items are waiting for their bounded retry backoff.',
			'ALL_ITEMS_CLEAN' => 'Every executable item completed with no conflicts.',
			'ITEM_CONFLICTS' => 'One or more products conflicted or failed; review the item list.',
			'ITEM_NEEDS_REVIEW' => 'At least one product outcome could not be proven and needs review.',
			'PERMISSION_REVOKED' => 'The approved actor can no longer edit one or more products; remaining items were paused.',
			'DEPENDENCY_UNAVAILABLE' => 'WriteLeash requires an active, initialized ' . Free_Support_Contract::range_text() . '; no product was changed.',
			'WOOCOMMERCE_VERSION_UNSUPPORTED' => 'The installed WooCommerce version is outside the supported ' . Free_Support_Contract::range_text() . ' range; update or roll back WooCommerce, then resume. No product was changed.',
			'MULTISITE_UNSUPPORTED' => 'Multisite is not supported; run WriteLeash on a single-site installation, then resume. No product was changed.',
			'DB_TRANSACTIONS_UNSUPPORTED' => 'The database cannot hold the item transaction (non-mysqli connection or non-InnoDB tables); restore a standard transactional setup, then resume. No product was changed.',
			'SCHEMA_UNAVAILABLE' => 'The WriteLeash job tables are missing or incomplete; no product was changed.',
			'SCHEDULER_UNAVAILABLE' => 'Action Scheduler is not ready; use the protected resume action.',
			'LEASE_RECOVERY' => 'A previous worker lease expired; a new worker may take over.',
			'MANUAL_PAUSE' => 'An operator paused this job.',
			'MANUAL_RESUME' => 'An operator resumed this job.',
			'OPERATOR_CANCELLED' => 'An operator cancelled this job; applied products are retained.',
			'DEACTIVATED' => 'The plugin is deactivated; no new items are claimed.',
			'WORKER_EXCEPTION' => 'The worker stopped on an unexpected internal error; durable state was preserved.',
			'RECONCILE_UNAVAILABLE' => 'Durable item reconciliation could not run; no new items were claimed.',
			'BLOCKED_BY_POLICY' => 'The plan policy blocks every changing product; it cannot be approved.',
			'LEASE_HELD' => 'Another live worker holds this job lease.',
			'FENCE_LOST' => 'This worker lost its lease; the item transaction was refused before any product change.',
			'INVALID_FENCE' => 'The transactional fence parameters are invalid; no product was changed.',
			'NOT_EXECUTABLE' => 'The job state does not permit background execution.',
			'NO_ITEMS' => 'No claimable items remain.',
			'DURABLE_APPLIED' => 'The durable price journal already records this item as applied.',
			'DURABLE_RECONCILED' => 'A durable journal record was reconciled after a worker interruption.',
			'WOO_CRUD_VERIFIED' => 'WooCommerce CRUD committed and verified the frozen target price.',
			'ITEM_CONFLICT' => 'The product changed after approval; the stored price was not overwritten.',
			'RETRY_BUDGET_EXHAUSTED' => 'Automatic retries were exhausted; the item requires review.',
			'RECONCILED_NO_COMMIT' => 'A claimed attempt left no durable mutation and returned to pending.',
			'PLAN_POLICY_BLOCKED' => 'The plan policy blocks execution of this item.',
			'JOB_MATERIAL_MISMATCH' => 'Stored job material no longer matches its frozen plan; no product was changed.',
			'UNSUPPORTED_PRODUCT_STATE' => 'The product is no longer in a supported state; the item is terminal.',
			'TRANSACTION_UNAVAILABLE' => 'The item transaction environment is unavailable; no product was changed.',
			'FAILED' => 'The item attempt failed; a bounded retry may be safe.',
			'UNEXPECTED_MUTATION_RESULT' => 'The mutation primitive returned an unclassified result; the item needs review.',
			'JOB_TERMINAL' => 'The job already reached a terminal state.',
			'PERMISSION_DENIED' => 'The requester is not authorized for this job operation.',
		);
	}
	public static function message( string $reason ): string {
		$all = self::messages();
		return $all[$reason] ?? 'WriteLeash job state: ' . $reason . '.';
	}
}
