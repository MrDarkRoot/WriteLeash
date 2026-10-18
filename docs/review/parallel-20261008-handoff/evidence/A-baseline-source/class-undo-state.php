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
			'UNDO_INITIATED' => 'Undo started for the prices that are safe to restore.',
			'UNDO_ACTIVE' => 'WriteLeash is restoring eligible prices now.',
			'UNDO_BUDGET_EXHAUSTED' => 'The time limit for this Undo step was reached; more products remain.',
			'UNDO_BATCH_LIMIT' => 'The Undo step reached its product limit; more products remain.',
			'UNDO_RETRY_BACKOFF' => 'Some products are waiting briefly before the next Undo attempt.',
			'UNDO_ALL_CLEAN' => 'Every eligible price was restored to its previous value.',
			'UNDO_ITEM_CONFLICTS' => 'One or more products changed after WriteLeash applied its price; those values were not overwritten.',
			'UNDO_ITEM_NEEDS_REVIEW' => 'At least one restoration could not be confirmed; check the product list.',
			'UNDO_NOT_ELIGIBLE' => 'This job has no safely finished price changes to restore.',
			'UNDO_JOB_RUNNING' => 'Price changes have not finished; Undo cannot start yet.',
			'UNDO_ALREADY' => 'This product was already restored by this Undo operation.',
			'UNDO_RESTORED' => 'WooCommerce saved and verified the restored price.',
			'UNDO_CONFLICT' => 'The product changed after WriteLeash applied its price; the stored price was not overwritten.',
			'UNDO_FINGERPRINT_MISMATCH' => 'The product no longer matches the state recorded when WriteLeash changed it; the stored price was not overwritten.',
			'UNDO_PROVENANCE_MISMATCH' => 'The saved restoration details no longer match; no price was changed.',
			'PERMISSION_REVOKED' => 'The person who started Undo can no longer edit one or more products; remaining products are paused.',
			'PERMISSION_DENIED' => 'The requester is not authorized for this Undo operation.',
			'UNDO_EXPIRED' => 'The Undo window has ended; saved restoration details are gone and Restore is unavailable.',
			'UNDO_PURGED' => 'Expired history was removed after its retention period.',
			'UNDO_PURGE_SKIPPED' => 'Active, paused, review or incomplete evidence is never purged automatically.',
			'UNDO_CANCELLED' => 'An operator cancelled this Undo operation; restored products are retained.',
			'DEACTIVATED' => 'The plugin is deactivated; no new Undo items are claimed.',
			'UNDO_WORKER_EXCEPTION' => 'Undo stopped with an unexpected error; saved progress was kept.',
			'UNDO_RECONCILE_UNAVAILABLE' => 'WriteLeash could not check the saved restoration outcomes; no new products were processed.',
			'SCHEMA_UNAVAILABLE' => 'WriteLeash’s saved data tables are missing or incomplete; no product was changed.',
			'DEPENDENCY_UNAVAILABLE' => 'WriteLeash requires an active, initialized ' . Free_Support_Contract::range_text() . '; no product was changed.',
			'WOOCOMMERCE_VERSION_UNSUPPORTED' => 'The installed WooCommerce version is outside the supported ' . Free_Support_Contract::range_text() . ' range; update or roll back WooCommerce, then resume. No product was changed.',
			'MULTISITE_UNSUPPORTED' => 'Multisite is not supported; run WriteLeash on a single-site installation, then resume. No product was changed.',
			'DB_TRANSACTIONS_UNSUPPORTED' => 'The database cannot hold the item transaction (non-mysqli connection or non-InnoDB tables); restore a standard transactional setup, then resume. No product was changed.',
			'SCHEDULER_UNAVAILABLE' => 'Background processing is not ready; continue with Restore eligible prices (Undo).',
			'LEASE_RECOVERY' => 'The previous background step stopped unexpectedly; Continue Undo resumes from the saved progress.',
			'LEASE_HELD' => 'Another Undo step is already running for this job.',
			'FENCE_LOST' => 'Undo stopped safely before changing anything; no price was changed.',
			'INVALID_FENCE' => 'The saved safety checks were incomplete; no product was changed.',
			'NOT_EXECUTABLE' => 'Undo cannot run in its current state.',
			'NO_ITEMS' => 'No claimable Undo items remain.',
			'DURABLE_UNDONE' => 'The saved record already shows this price as restored.',
			'DURABLE_RECONCILED' => 'A saved record was checked and matched after an interruption.',
			'RETRY_BUDGET_EXHAUSTED' => 'Automatic Undo retries were exhausted; the item requires review.',
			'RECONCILED_NO_COMMIT' => 'A previous attempt made no saved change and the product returned to the queue.',
			'JOB_MATERIAL_MISMATCH' => 'This saved job no longer matches its approved preview; no product was changed. Start a new preview.',
			'TRANSACTION_UNAVAILABLE' => 'WriteLeash could not start a safe database change for this product; no price was changed.',
			'FAILED' => 'This price could not be restored; a retry may be safe.',
			'UNEXPECTED_MUTATION_RESULT' => 'The restoration result could not be classified; check the product.',
			'UNDO_TERMINAL' => 'This Undo has already finished.',
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
