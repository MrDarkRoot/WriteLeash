<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** Typed Undo orchestration failure. Machine reason codes are stable; display copy is separate. */
final class Undo_Error extends \RuntimeException {
	private string $reason;
	public function __construct( string $reason ) {
		$this->reason = $reason;
		parent::__construct( $reason );
	}
	public function reason(): string { return $this->reason; }
}

/**
 * Durable Undo operation state machine (one row per apply job).
 *
 * The original apply job result is never rewritten: this operation is additive
 * history. Illegal transitions are rejected by name. No aliases.
 */
final class Undo_State {
	public const PENDING = 'UNDO_PENDING';
	public const RUNNING = 'UNDO_RUNNING';
	public const PAUSED = 'UNDO_PAUSED';
	public const COMPLETED = 'UNDO_COMPLETED';
	public const COMPLETED_WITH_ISSUES = 'UNDO_COMPLETED_WITH_ISSUES';
	public const NEEDS_REVIEW = 'UNDO_NEEDS_REVIEW';
	public const CANCELLED = 'UNDO_CANCELLED';

	private const TRANSITIONS = array(
		self::PENDING => array( self::RUNNING, self::PAUSED, self::CANCELLED ),
		self::RUNNING => array( self::RUNNING, self::PENDING, self::PAUSED, self::COMPLETED, self::COMPLETED_WITH_ISSUES, self::NEEDS_REVIEW, self::CANCELLED ),
		self::PAUSED => array( self::PAUSED, self::RUNNING, self::CANCELLED ),
		self::NEEDS_REVIEW => array( self::NEEDS_REVIEW, self::PAUSED, self::RUNNING, self::CANCELLED ),
		self::COMPLETED => array(),
		self::COMPLETED_WITH_ISSUES => array(),
		self::CANCELLED => array(),
	);

	public static function assert_transition( string $from, string $to ): void {
		if ( ! isset( self::TRANSITIONS[$from] ) || ! in_array( $to, self::TRANSITIONS[$from], true ) ) {
			throw new Undo_Error( 'INVALID_UNDO_TRANSITION' );
		}
	}
	public static function is_terminal( string $status ): bool {
		return in_array( $status, array( self::COMPLETED, self::COMPLETED_WITH_ISSUES, self::CANCELLED ), true );
	}
	/** Scheduler wake-ups may execute these states without an operator. */
	public static function can_auto_run( string $status ): bool {
		return in_array( $status, array( self::PENDING, self::RUNNING ), true );
	}
	/** Manual resume adds operator-owned resumable states only. */
	public static function can_manual_run( string $status ): bool {
		return self::can_auto_run( $status ) || in_array( $status, array( self::PAUSED, self::NEEDS_REVIEW ), true );
	}
	public static function is_executable( string $status ): bool {
		return self::can_manual_run( $status );
	}
}

/**
 * Durable per-product Undo state. UNDO_CONFLICT and UNDO_NEEDS_REVIEW never
 * return to UNDO_PENDING. UNDONE is never overwritten.
 */
final class Undo_Item_State {
	public const PENDING = 'UNDO_PENDING';
	public const APPLYING = 'UNDO_APPLYING';
	public const UNDONE = 'UNDONE';
	public const CONFLICT = 'UNDO_CONFLICT';
	public const FAILED = 'UNDO_FAILED';
	public const NEEDS_REVIEW = 'UNDO_NEEDS_REVIEW';

	private const TRANSITIONS = array(
		self::PENDING => array( self::APPLYING, self::UNDONE, self::CONFLICT, self::FAILED, self::NEEDS_REVIEW ),
		self::APPLYING => array( self::PENDING, self::UNDONE, self::CONFLICT, self::FAILED, self::NEEDS_REVIEW ),
		self::UNDONE => array(),
		self::CONFLICT => array(),
		self::FAILED => array(),
		self::NEEDS_REVIEW => array(),
	);

	public static function assert_transition( ?string $from, string $to ): void {
		if ( null === $from ) {
			if ( self::PENDING !== $to ) { throw new Undo_Error( 'INVALID_UNDO_ITEM_TRANSITION' ); }
			return;
		}
		if ( ! isset( self::TRANSITIONS[$from] ) || ! in_array( $to, self::TRANSITIONS[$from], true ) ) {
			throw new Undo_Error( 'INVALID_UNDO_ITEM_TRANSITION' );
		}
	}
	public static function is_terminal( string $state ): bool {
		return ! in_array( $state, array( self::PENDING, self::APPLYING ), true );
	}
}

/** Stable machine reasons with separate display copy. Consumers must escape output. */
final class Undo_Reason {
	public static function messages(): array {
		return array(
			'UNDO_INITIATED' => 'An Undo operation was recorded for the eligible applied products.',
			'UNDO_ACTIVE' => 'An Undo worker is processing this operation.',
			'UNDO_BUDGET_EXHAUSTED' => 'The Undo worker reached its time budget; more items remain.',
			'UNDO_BATCH_LIMIT' => 'The Undo worker reached its item limit; more items remain.',
			'UNDO_RETRY_BACKOFF' => 'Undo items are waiting for their bounded retry backoff.',
			'UNDO_ALL_CLEAN' => 'Every eligible product was restored to its pre-apply regular price.',
			'UNDO_ITEM_CONFLICTS' => 'One or more products no longer match the applied state; those prices were not overwritten.',
			'UNDO_ITEM_NEEDS_REVIEW' => 'At least one Undo outcome could not be proven and needs review.',
			'UNDO_NOT_ELIGIBLE' => 'This job has no safely stopped applied state to undo.',
			'UNDO_JOB_RUNNING' => 'Application processing has not safely stopped; Undo cannot start yet.',
			'UNDO_ALREADY' => 'This product was already restored by this Undo operation.',
			'UNDO_RESTORED' => 'WooCommerce CRUD committed and verified the restored regular price.',
			'UNDO_CONFLICT' => 'The product changed after WriteLeash applied its price; the stored price was not overwritten.',
			'UNDO_FINGERPRINT_MISMATCH' => 'The post-apply fingerprint no longer matches; the stored price was not overwritten.',
			'UNDO_PROVENANCE_MISMATCH' => 'Stored Undo provenance no longer matches durable apply evidence; no price was changed.',
			'PERMISSION_REVOKED' => 'The Undo initiator can no longer edit one or more products; remaining items were paused.',
			'PERMISSION_DENIED' => 'The requester is not authorized for this Undo operation.',
			'UNDO_EXPIRED' => 'History retention expired; Undo evidence is gone and Restore is unavailable.',
			'UNDO_PURGED' => 'Expired terminal history was removed by bounded retention.',
			'UNDO_PURGE_SKIPPED' => 'Active, paused, review or incomplete evidence is never purged automatically.',
			'UNDO_CANCELLED' => 'An operator cancelled this Undo operation; restored products are retained.',
			'DEACTIVATED' => 'The plugin is deactivated; no new Undo items are claimed.',
			'UNDO_WORKER_EXCEPTION' => 'The Undo worker stopped on an unexpected internal error; durable state was preserved.',
			'UNDO_RECONCILE_UNAVAILABLE' => 'Durable Undo reconciliation could not run; no new items were claimed.',
			'SCHEMA_UNAVAILABLE' => 'The WriteLeash Undo tables are missing or incomplete; no product was changed.',
			'DEPENDENCY_UNAVAILABLE' => 'WriteLeash requires an active, initialized ' . Free_Support_Contract::range_text() . '; no product was changed.',
			'WOOCOMMERCE_VERSION_UNSUPPORTED' => 'The installed WooCommerce version is outside the supported ' . Free_Support_Contract::range_text() . ' range; update or roll back WooCommerce, then resume. No product was changed.',
			'MULTISITE_UNSUPPORTED' => 'Multisite is not supported; run WriteLeash on a single-site installation, then resume. No product was changed.',
			'DB_TRANSACTIONS_UNSUPPORTED' => 'The database cannot hold the item transaction (non-mysqli connection or non-InnoDB tables); restore a standard transactional setup, then resume. No product was changed.',
			'SCHEDULER_UNAVAILABLE' => 'Action Scheduler is not ready; use the protected start action.',
			'LEASE_RECOVERY' => 'A previous Undo worker lease expired; a new worker may take over.',
			'LEASE_HELD' => 'Another live worker holds this Undo lease.',
			'FENCE_LOST' => 'This worker lost its lease; the Undo transaction was refused before any product change.',
			'INVALID_FENCE' => 'The transactional fence parameters are invalid; no product was changed.',
			'NOT_EXECUTABLE' => 'The Undo operation state does not permit background execution.',
			'NO_ITEMS' => 'No claimable Undo items remain.',
			'DURABLE_UNDONE' => 'The durable Undo journal already records this product as restored.',
			'DURABLE_RECONCILED' => 'A durable Undo record was reconciled after a worker interruption.',
			'RETRY_BUDGET_EXHAUSTED' => 'Automatic Undo retries were exhausted; the item requires review.',
			'RECONCILED_NO_COMMIT' => 'A claimed Undo attempt left no durable mutation and returned to pending.',
			'JOB_MATERIAL_MISMATCH' => 'Stored job material no longer matches its frozen plan; no product was changed.',
			'TRANSACTION_UNAVAILABLE' => 'The Undo transaction environment is unavailable; no product was changed.',
			'FAILED' => 'The Undo attempt failed; a bounded retry may be safe.',
			'UNEXPECTED_MUTATION_RESULT' => 'The Undo primitive returned an unclassified result; the item needs review.',
			'UNDO_TERMINAL' => 'The Undo operation already reached a terminal state.',
			'PRODUCT_MISSING' => 'The product no longer exists; nothing was restored.',
			'PRODUCT_TYPE_CHANGED' => 'The product is no longer a core simple product; nothing was restored.',
			'PRODUCT_STATUS_CHANGED' => 'The product is no longer published; nothing was restored and nothing was republished.',
			'SALE_CONFIGURED' => 'A sale configuration is present; restoration through an unsupported context was refused.',
		);
	}
	public static function message( string $reason ): string {
		$all = self::messages();
		return $all[$reason] ?? 'WriteLeash Undo state: ' . $reason . '.';
	}
}
