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
			'UNDO_INITIATED' => __( 'Undo started for the prices that are safe to restore.', 'writeleash' ),
			'UNDO_ACTIVE' => __( 'WriteLeash is restoring eligible prices now.', 'writeleash' ),
			'UNDO_BUDGET_EXHAUSTED' => __( 'The time limit for this Undo step was reached; more products remain.', 'writeleash' ),
			'UNDO_BATCH_LIMIT' => __( 'The Undo step reached its product limit; more products remain.', 'writeleash' ),
			'UNDO_RETRY_BACKOFF' => __( 'Some products are waiting briefly before the next Undo attempt.', 'writeleash' ),
			'UNDO_ALL_CLEAN' => __( 'Every eligible price was restored to its previous value.', 'writeleash' ),
			'UNDO_ITEM_CONFLICTS' => __( 'One or more products changed after WriteLeash applied its price; those values were not overwritten.', 'writeleash' ),
			'UNDO_ITEM_NEEDS_REVIEW' => __( 'At least one restoration could not be confirmed; check the product list.', 'writeleash' ),
			'UNDO_NOT_ELIGIBLE' => __( 'This job has no safely finished price changes to restore.', 'writeleash' ),
			'UNDO_JOB_RUNNING' => __( 'Price changes have not finished; Undo cannot start yet.', 'writeleash' ),
			'UNDO_ALREADY' => __( 'This product was already restored by this Undo operation.', 'writeleash' ),
			'UNDO_RESTORED' => __( 'WooCommerce saved and verified the restored price.', 'writeleash' ),
			'UNDO_CONFLICT' => __( 'The product changed after WriteLeash applied its price; the stored price was not overwritten.', 'writeleash' ),
			'UNDO_FINGERPRINT_MISMATCH' => __( 'The product no longer matches the state recorded when WriteLeash changed it; the stored price was not overwritten.', 'writeleash' ),
			'UNDO_PROVENANCE_MISMATCH' => __( 'The saved restoration details no longer match; no price was changed.', 'writeleash' ),
			'PERMISSION_REVOKED' => __( 'The person who started Undo can no longer edit one or more products; remaining products are paused.', 'writeleash' ),
			'PERMISSION_DENIED' => __( 'The requester is not authorized for this Undo operation.', 'writeleash' ),
			'UNDO_EXPIRED' => __( 'The Undo window has ended; saved restoration details are gone and Restore is unavailable.', 'writeleash' ),
			'UNDO_PURGED' => __( 'Expired history was removed after its retention period.', 'writeleash' ),
			'UNDO_PURGE_SKIPPED' => __( 'Active, paused, review or incomplete evidence is never purged automatically.', 'writeleash' ),
			'UNDO_CANCELLED' => __( 'An operator cancelled this Undo operation; restored products are retained.', 'writeleash' ),
			'DEACTIVATED' => __( 'The plugin is deactivated; no new Undo items are claimed.', 'writeleash' ),
			'UNDO_WORKER_EXCEPTION' => __( 'Undo stopped with an unexpected error; saved progress was kept.', 'writeleash' ),
			'UNDO_RECONCILE_UNAVAILABLE' => __( 'WriteLeash could not check the saved restoration outcomes; no new products were processed.', 'writeleash' ),
			'SCHEMA_UNAVAILABLE' => __( 'WriteLeash’s saved data tables are missing or incomplete; no product was changed.', 'writeleash' ),
			'DEPENDENCY_UNAVAILABLE' => sprintf( /* translators: %s: supported WooCommerce version range. */ __( 'WriteLeash requires an active, initialized %s; no product was changed.', 'writeleash' ), Free_Support_Contract::range_text() ),
			'WOOCOMMERCE_VERSION_UNSUPPORTED' => sprintf( /* translators: %s: supported WooCommerce version range. */ __( 'The installed WooCommerce version is outside the supported %s range; update or roll back WooCommerce, then resume. No product was changed.', 'writeleash' ), Free_Support_Contract::range_text() ),
			'MULTISITE_UNSUPPORTED' => __( 'Multisite is not supported; run WriteLeash on a single-site installation, then resume. No product was changed.', 'writeleash' ),
			'DB_TRANSACTIONS_UNSUPPORTED' => __( 'The database cannot hold the item transaction (non-mysqli connection or non-InnoDB tables); restore a standard transactional setup, then resume. No product was changed.', 'writeleash' ),
			'SCHEDULER_UNAVAILABLE' => __( 'Background processing is not ready; continue with Restore eligible prices (Undo).', 'writeleash' ),
			'LEASE_RECOVERY' => __( 'The previous background step stopped unexpectedly; Continue Undo resumes from the saved progress.', 'writeleash' ),
			'LEASE_HELD' => __( 'Another Undo step is already running for this job.', 'writeleash' ),
			'FENCE_LOST' => __( 'Undo stopped safely before changing anything; no price was changed.', 'writeleash' ),
			'INVALID_FENCE' => __( 'The saved safety checks were incomplete; no product was changed.', 'writeleash' ),
			'NOT_EXECUTABLE' => __( 'Undo cannot run in its current state.', 'writeleash' ),
			'NO_ITEMS' => __( 'No claimable Undo items remain.', 'writeleash' ),
			'DURABLE_UNDONE' => __( 'The saved record already shows this price as restored.', 'writeleash' ),
			'DURABLE_RECONCILED' => __( 'A saved record was checked and matched after an interruption.', 'writeleash' ),
			'RETRY_BUDGET_EXHAUSTED' => __( 'Automatic Undo retries were exhausted; the item requires review.', 'writeleash' ),
			'RECONCILED_NO_COMMIT' => __( 'A previous attempt made no saved change and the product returned to the queue.', 'writeleash' ),
			'JOB_MATERIAL_MISMATCH' => __( 'This saved job no longer matches its approved preview; no product was changed. Start a new preview.', 'writeleash' ),
			'TRANSACTION_UNAVAILABLE' => __( 'WriteLeash could not start a safe database change for this product; no price was changed.', 'writeleash' ),
			'FAILED' => __( 'This price could not be restored; a retry may be safe.', 'writeleash' ),
			'UNEXPECTED_MUTATION_RESULT' => __( 'The restoration result could not be classified; check the product.', 'writeleash' ),
			'UNDO_TERMINAL' => __( 'This Undo has already finished.', 'writeleash' ),
			'PRODUCT_MISSING' => __( 'The product no longer exists; nothing was restored.', 'writeleash' ),
			'PRODUCT_TYPE_CHANGED' => __( 'The product is no longer a core simple product; nothing was restored.', 'writeleash' ),
			'PRODUCT_STATUS_CHANGED' => __( 'The product is no longer published; nothing was restored and nothing was republished.', 'writeleash' ),
			'SALE_CONFIGURED' => __( 'A sale configuration is present; restoration through an unsupported context was refused.', 'writeleash' ),
		);
	}
	public static function message( string $reason ): string {
		$all = self::messages();
		return $all[$reason] ?? sprintf( /* translators: %s: untranslated machine reason code. */ __( 'WriteLeash Undo state: %s.', 'writeleash' ), $reason );
	}
}
