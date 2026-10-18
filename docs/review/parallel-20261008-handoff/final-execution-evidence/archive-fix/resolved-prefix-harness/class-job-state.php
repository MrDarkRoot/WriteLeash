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
			'APPROVED' => 'The preview was approved and is ready to run.',
			'SCHEDULED' => 'The price change is queued to run in the background.',
			'ACTIVE' => 'WriteLeash is applying the price change now.',
			'BUDGET_EXHAUSTED' => 'The time limit for this background step was reached; more products remain.',
			'BATCH_LIMIT' => 'The step reached its product limit; more products remain.',
			'RETRY_BACKOFF' => 'Some products are waiting briefly before the next attempt.',
			'ALL_ITEMS_CLEAN' => 'Every product was processed with no conflicts.',
			'ITEM_CONFLICTS' => 'One or more products changed after review or could not be changed; review the product list.',
			'ITEM_NEEDS_REVIEW' => 'At least one product outcome could not be confirmed; check the product list.',
			'PERMISSION_REVOKED' => 'The person who approved this change can no longer edit one or more products; remaining products are paused.',
			'DEPENDENCY_UNAVAILABLE' => 'WriteLeash requires an active, initialized ' . Free_Support_Contract::range_text() . '; no product was changed.',
			'WOOCOMMERCE_VERSION_UNSUPPORTED' => 'The installed WooCommerce version is outside the supported ' . Free_Support_Contract::range_text() . ' range; update or roll back WooCommerce, then resume. No product was changed.',
			'MULTISITE_UNSUPPORTED' => 'Multisite is not supported; run WriteLeash on a single-site installation, then resume. No product was changed.',
			'DB_TRANSACTIONS_UNSUPPORTED' => 'The database cannot hold the item transaction (non-mysqli connection or non-InnoDB tables); restore a standard transactional setup, then resume. No product was changed.',
			'SCHEMA_UNAVAILABLE' => 'WriteLeash’s saved data tables are missing or incomplete; no product was changed.',
			'SCHEDULER_UNAVAILABLE' => 'Background processing is not ready; use Resume remaining products to continue.',
			'LEASE_RECOVERY' => 'The previous background step stopped unexpectedly; Resume continues from the saved progress.',
			'MANUAL_PAUSE' => 'An operator paused this job.',
			'MANUAL_RESUME' => 'An operator resumed this job.',
			'OPERATOR_CANCELLED' => 'An operator cancelled this job; applied products are retained.',
			'DEACTIVATED' => 'The plugin is deactivated; no new items are claimed.',
			'WORKER_EXCEPTION' => 'The background step stopped with an unexpected error; saved progress was kept.',
			'RECONCILE_UNAVAILABLE' => 'WriteLeash could not check the saved product outcomes; no new products were processed.',
			'BLOCKED_BY_POLICY' => 'The safety settings block every changing product in this preview; it cannot be approved.',
			'LEASE_HELD' => 'Another background step is already running for this job.',
			'FENCE_LOST' => 'The background step stopped safely before changing anything; no product was changed.',
			'INVALID_FENCE' => 'The saved safety checks were incomplete; no product was changed.',
			'NOT_EXECUTABLE' => 'This job cannot run in its current state.',
			'NO_ITEMS' => 'No claimable items remain.',
			'DURABLE_APPLIED' => 'The saved record already shows this product as changed.',
			'DURABLE_RECONCILED' => 'A saved record was checked and matched after an interruption.',
			'WOO_CRUD_VERIFIED' => 'WooCommerce saved and verified the planned price.',
			'ITEM_CONFLICT' => 'The product changed after review; the newer stored price was not overwritten.',
			'RETRY_BUDGET_EXHAUSTED' => 'Automatic retries were exhausted; the item requires review.',
			'RECONCILED_NO_COMMIT' => 'A previous attempt made no saved change and the product returned to the queue.',
			'PLAN_POLICY_BLOCKED' => 'The safety settings block this product; it cannot be changed.',
			'JOB_MATERIAL_MISMATCH' => 'This saved job no longer matches its approved preview; no product was changed. Start a new preview.',
			'LOOKUP_MISMATCH' => 'WooCommerce’s product price lookup data does not match this product’s saved price, so WriteLeash did not change it. Open the product in WooCommerce and save it again to rebuild its lookup data; if many products are affected, run WooCommerce’s product lookup table update. Then create a new preview.',
			'UNSUPPORTED_PRODUCT_STATE' => 'This product’s saved price data is missing, duplicated, or malformed, so WriteLeash refused to change it. Open the product in WooCommerce, correct or save it again, then create a new preview. No stored price was overwritten.',
			'TRANSACTION_UNAVAILABLE' => 'WriteLeash could not start a safe database change for this product; nothing was changed.',
			'FAILED' => 'This product could not be changed; a retry may be safe.',
			'UNEXPECTED_MUTATION_RESULT' => 'The mutation primitive returned an unclassified result; the item needs review.',
			'JOB_TERMINAL' => 'This job has already finished.',
			'PERMISSION_DENIED' => 'The requester is not authorized for this job operation.',
		);
	}
	public static function message( string $reason ): string {
		$all = self::messages();
		return $all[$reason] ?? 'WriteLeash job state: ' . $reason . '.';
	}
}
