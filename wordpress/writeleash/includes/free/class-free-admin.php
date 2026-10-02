<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #111 Free WooCommerce bulk-price Admin workflow.
 *
 * This is the only planning entry surface: selector form, server-side
 * paginated preview of the immutable #107 plan, action-bound approval,
 * durable #109 execution with truthful progress, bounded manual resume,
 * #110 history and eligible conflict-aware Undo.
 *
 * Server authority rules enforced here:
 * - Workers never rerun selector logic; the previewed concrete IDs plus the
 *   persisted absolute target strings are the execution plan. Percentage
 *   math is never recomputed outside #107.
 * - Approval binds the exact immutable plan identity (plan ID + schema/hash
 *   versions + fingerprint). Any selection/operation/policy change requires
 *   a new plan; there is no mutable approved-plan pathway.
 * - A policy BLOCKED plan cannot be approved or executed.
 * - Progress, history and Undo read the durable repositories on every load;
 *   browser state is never durable truth and HTTP 200 never means applied.
 * - Every mutation is authenticated POST with capability checks, an
 *   action-specific nonce, strict typed validation and job authorization.
 *   Job and plan IDs are identifiers, never access tokens. No GET mutation.
 * - Input is read through filter_input only; this file holds no superglobal
 *   variable literal and performs no direct price storage write.
 */
final class Free_Admin {
	public const SLUG = 'writeleash-bulk-prices';
	public const ACTION_PREVIEW = 'writeleash_free_preview';
	public const ACTION_APPROVE = 'writeleash_free_approve';
	public const ACTION_RESUME = 'writeleash_free_resume';
	public const ACTION_UNDO = 'writeleash_free_undo';
	public const PREVIEW_PAGE_SIZE = 20;
	public const HISTORY_PAGE_SIZE = 20;
	public const ITEM_PAGE_SIZE = 50;
	private const NOTICE_SECONDS = 120;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION_PREVIEW, array( __CLASS__, 'handle_preview' ) );
		add_action( 'admin_post_' . self::ACTION_APPROVE, array( __CLASS__, 'handle_approve' ) );
		add_action( 'admin_post_' . self::ACTION_RESUME, array( __CLASS__, 'handle_resume' ) );
		add_action( 'admin_post_' . self::ACTION_UNDO, array( __CLASS__, 'handle_undo' ) );
	}

	/**
	 * Woo-first entry point: next to Products when WooCommerce registers the
	 * product post type, else under WooCommerce, else Tools with a dependency
	 * notice. Visibility needs product editing; every mutation additionally
	 * requires manage_woocommerce plus job authorization.
	 */
	public static function menu(): void {
		$title = 'WriteLeash Bulk Prices';
		if ( post_type_exists( 'product' ) ) {
			add_submenu_page( 'edit.php?post_type=product', $title, 'Bulk Prices', 'edit_products', self::SLUG, array( __CLASS__, 'render' ) );
		} elseif ( function_exists( 'wc_get_product' ) ) {
			add_submenu_page( 'woocommerce', $title, 'Bulk Prices', 'edit_products', self::SLUG, array( __CLASS__, 'render' ) );
		} else {
			add_management_page( $title, 'WriteLeash Bulk Prices', 'edit_products', self::SLUG, array( __CLASS__, 'render' ) );
		}
	}

	/** Whether both mutation capabilities are present for the current actor. */
	public static function can_mutate(): bool {
		return current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_products' );
	}

	/** Whether the supported WooCommerce planning context is available. The planner re-validates currency and settings on every preview. */
	public static function dependency_ok(): bool {
		return Free_Support_Contract::woocommerce_ok();
	}

	private static function dependency_refusal(): ?array {
		$reason = Free_Support_Contract::woocommerce_reason();
		return null === $reason ? null : array( 'status' => 'INVALID', 'reason' => $reason );
	}

	/** Creator, approver, or an administrator. Possession of an ID grants nothing. */
	public static function authorized_for_job( array $job, int $user_id ): bool {
		return Undo_Repository::authorized( $job, $user_id );
	}

	// ------------------------------------------------------------------
	// HTTP handlers: thin filter_input extraction, then the testable
	// process_* core, then a transient notice plus safe redirect (PRG).
	// ------------------------------------------------------------------

	private static function request_method(): string {
		// $_SERVER is routing metadata, not processed input; unslash and
		// sanitize exactly like the reviewed legacy Admin handler.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
		}
		return '';
	}

	private static function post_field( string $key ): ?string {
		$value = filter_input( INPUT_POST, $key );
		return is_string( $value ) ? $value : null;
	}

	private static function post_input(): array {
		return array(
			'_wpnonce' => self::post_field( '_wpnonce' ),
			'selector' => self::post_field( 'selector' ),
			'ids' => self::post_field( 'ids' ),
			'sku' => self::post_field( 'sku' ),
			'category' => self::post_field( 'category' ),
			'operation' => self::post_field( 'operation' ),
			'amount' => self::post_field( 'amount' ),
			'max_products' => self::post_field( 'max_products' ),
			'max_increase' => self::post_field( 'max_increase' ),
			'max_decrease' => self::post_field( 'max_decrease' ),
			'warning_threshold' => self::post_field( 'warning_threshold' ),
			'block_zero' => self::post_field( 'block_zero' ),
			'job' => self::post_field( 'job' ),
		);
	}

	private static function finish( array $result, string $view, ?string $public_id ): array {
		$user_id = get_current_user_id();
		if ( $user_id > 0 && isset( $result['status'], $result['reason'] ) ) {
			set_transient( self::notice_key( $user_id ), self::notice_facts( $result ), self::NOTICE_SECONDS );
		}
		$target = self::SLUG;
		if ( '' !== $view ) {
			$target .= '&wl_view=' . $view;
		}
		if ( is_string( $public_id ) && '' !== $public_id ) {
			$target .= '&wl_job=' . $public_id;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . $target ) );
		return $result;
	}

	public static function handle_preview(): array {
		$result = self::process_preview( self::post_input(), self::request_method() );
		$public_id = 'OK' === ( $result['status'] ?? '' ) ? (string) ( $result['public_id'] ?? '' ) : null;
		return self::finish( $result, 'OK' === ( $result['status'] ?? '' ) ? 'preview' : '', $public_id );
	}

	public static function handle_approve(): array {
		$result = self::process_approve( self::post_input(), self::request_method() );
		$public_id = isset( $result['public_id'] ) && is_string( $result['public_id'] ) ? $result['public_id'] : null;
		return self::finish( $result, null !== $public_id ? 'job' : '', $public_id );
	}

	public static function handle_resume(): array {
		$result = self::process_resume( self::post_input(), self::request_method() );
		$public_id = isset( $result['public_id'] ) && is_string( $result['public_id'] ) ? $result['public_id'] : null;
		return self::finish( $result, null !== $public_id ? 'job' : '', $public_id );
	}

	public static function handle_undo(): array {
		$result = self::process_undo( self::post_input(), self::request_method() );
		$public_id = isset( $result['public_id'] ) && is_string( $result['public_id'] ) ? $result['public_id'] : null;
		return self::finish( $result, null !== $public_id ? 'job' : '', $public_id );
	}

	private static function notice_key( int $user_id ): string {
		return 'writeleash_free_notice_' . $user_id;
	}

	/** Only bounded scalar facts cross the PRG redirect; no plan JSON or identifiers beyond the job reference. */
	private static function notice_facts( array $result ): array {
		$facts = array(
			'status' => isset( $result['status'] ) && is_string( $result['status'] ) ? $result['status'] : 'INVALID',
			'reason' => isset( $result['reason'] ) && is_string( $result['reason'] ) ? $result['reason'] : 'action_failed',
		);
		foreach ( array( 'processed', 'plan_id', 'job_status', 'stop', 'selected_count' ) as $key ) {
			if ( isset( $result[ $key ] ) && ( is_string( $result[ $key ] ) || is_int( $result[ $key ] ) ) ) {
				$facts[ $key ] = $result[ $key ];
			}
		}
		return $facts;
	}

	// ------------------------------------------------------------------
	// Mutation core. Each method validates POST, capabilities, nonce and
	// strict typed inputs before touching any repository. Tests call these
	// directly with explicit arrays.
	// ------------------------------------------------------------------

	private static function gate( string $nonce_action, array $post, string $method ): ?array {
		if ( ! self::can_mutate() ) {
			return array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' );
		}
		if ( 'POST' !== $method ) {
			return array( 'status' => 'INVALID', 'reason' => 'post_required' );
		}
		$nonce = $post['_wpnonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			return array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' );
		}
		return self::dependency_refusal();
	}

	private static function invalid( \Throwable $error ): array {
		if ( $error instanceof Free_Job_Limit_Error ) {
			return array( 'status' => 'INVALID', 'reason' => 'supported_job_limit_exceeded', 'selected_count' => $error->selected() );
		}
		$reason = 'invalid_input';
		if ( $error instanceof Price_Validation_Error ) {
			$reason = $error->reason();
		} elseif ( $error instanceof Job_Error ) {
			$reason = $error->reason();
		} elseif ( $error instanceof Undo_Error ) {
			$reason = $error->reason();
		}
		return array( 'status' => 'INVALID', 'reason' => $reason );
	}

	/** Build the trusted selector from exactly the proved #107 selector kinds. */
	public static function build_selection( array $post ): Price_Selection_Spec {
		$kind = $post['selector'] ?? null;
		if ( 'ids' === $kind ) {
			$raw = $post['ids'] ?? null;
			if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
				throw new Price_Validation_Error( 'invalid_selection_size' );
			}
			$ids = array();
			foreach ( explode( ',', $raw ) as $token ) {
				$token = trim( $token );
				if ( '' === $token || ! preg_match( '/\A[0-9]{1,10}\z/', $token ) || (int) $token < 1 ) {
					throw new Price_Validation_Error( 'invalid_product_id' );
				}
				$ids[] = (int) $token;
			}
			Free_Support_Contract::assert_job_size( count( $ids ) );
			return Price_Selection_Spec::ids( $ids );
		}
		if ( 'sku' === $kind ) {
			$sku = $post['sku'] ?? null;
			if ( ! is_string( $sku ) ) {
				throw new Price_Validation_Error( 'invalid_sku' );
			}
			return Price_Selection_Spec::sku( $sku );
		}
		if ( 'category' === $kind ) {
			$raw = $post['category'] ?? null;
			if ( ! is_string( $raw ) || ! preg_match( '/\A[0-9]{1,10}\z/', $raw ) || (int) $raw < 1 ) {
				throw new Price_Validation_Error( 'invalid_category' );
			}
			return Price_Selection_Spec::category( (int) $raw );
		}
		throw new Price_Validation_Error( 'invalid_selector' );
	}

	public static function build_operation( array $post ): Price_Operation {
		$type = $post['operation'] ?? null;
		$amount = $post['amount'] ?? null;
		if ( ! is_string( $type ) || ! in_array( $type, array( Price_Operation::SET, Price_Operation::INCREASE_FIXED, Price_Operation::DECREASE_FIXED, Price_Operation::INCREASE_PERCENT, Price_Operation::DECREASE_PERCENT ), true ) ) {
			throw new Price_Validation_Error( 'unsupported_operation' );
		}
		if ( ! is_string( $amount ) ) {
			throw new Price_Validation_Error( 'malformed_decimal' );
		}
		return new Price_Operation( $type, $amount );
	}

	public static function build_policy( array $post ): Safety_Policy {
		$max_products_raw = $post['max_products'] ?? null;
		$max_increase = $post['max_increase'] ?? null;
		$max_decrease = $post['max_decrease'] ?? null;
		$warning_threshold = $post['warning_threshold'] ?? null;
		$block_zero_raw = $post['block_zero'] ?? null;
		if ( ! is_string( $max_products_raw ) || ! preg_match( '/\A[0-9]{1,4}\z/', $max_products_raw ) || (int) $max_products_raw > Free_Support_Contract::MAX_JOB_PRODUCTS ) {
			throw new Price_Validation_Error( 'invalid_product_limit' );
		}
		if ( ! is_string( $max_increase ) || ! is_string( $max_decrease ) || ! is_string( $warning_threshold ) ) {
			throw new Price_Validation_Error( 'invalid_policy_percent' );
		}
		if ( null === $block_zero_raw ) {
			$block_zero = false;
		} elseif ( '1' === $block_zero_raw ) {
			$block_zero = true;
		} else {
			throw new Price_Validation_Error( 'invalid_policy_flag' );
		}
		return new Safety_Policy( (int) $max_products_raw, $max_increase, $max_decrease, $block_zero, $warning_threshold );
	}

	/**
	 * Preview step: plan with #107, import as a PLANNED (or BLOCKED) #109
	 * job. Blocked plans are returned for inspection and can never approve.
	 */
	public static function process_preview( array $post, string $method ): array {
		$refused = self::gate( self::ACTION_PREVIEW, $post, $method );
		if ( null !== $refused ) {
			return $refused;
		}
		try {
			$selection = self::build_selection( $post );
			$operation = self::build_operation( $post );
			$policy = self::build_policy( $post );
			$plan = Woo_Price_Planner::preview( $selection, $operation, $policy );
			// The frozen selected population is authoritative even if the policy
			// blocks it or most selected items are unchanged/unsupported.
			Free_Support_Contract::assert_job_size( $plan->summary()['selected'] );
			$job = Job_Repository::create_from_plan( $plan, get_current_user_id() );
		} catch ( Price_Validation_Error $error ) {
			if ( 'permission_denied' === $error->reason() ) {
				return array( 'status' => 'FORBIDDEN', 'reason' => 'permission_denied' );
			}
			if ( 'selection_limit_exceeded' === $error->reason() ) {
				// The engineering resolver already proved this category exceeds
				// its larger bound. Do not expose that bound as a Free promise.
				return array( 'status' => 'INVALID', 'reason' => 'supported_job_limit_exceeded' );
			}
			return array( 'status' => 'INVALID', 'reason' => $error->reason() );
		} catch ( \Throwable $error ) {
			return self::invalid( $error );
		}
		$data = $plan->data();
		return array(
			'status' => 'OK',
			'reason' => 'BLOCKED' === $data['status'] ? 'plan_blocked' : 'preview_ready',
			'job_id' => (int) $job['id'],
			'public_id' => $job['public_id'],
			'plan_id' => $data['plan_id'],
			'plan_hash' => $plan->hash(),
			'blocked' => 'BLOCKED' === $data['status'],
			'job_status' => $job['status'],
		);
	}

	/** The current actor must still be able to edit every changing product. The worker re-verifies fresh under locks. */
	private static function precheck_product_rights( Change_Plan $plan ): ?array {
		foreach ( $plan->data()['items'] as $item ) {
			if ( 'CHANGING' !== ( $item['result'] ?? '' ) ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', (int) $item['product_id'] ) ) {
				return array( 'status' => 'FORBIDDEN', 'reason' => 'permission_revoked' );
			}
		}
		return null;
	}

	private static function job_from_post( array $post ): ?array {
		$public_id = $post['job'] ?? null;
		if ( ! is_string( $public_id ) || ! preg_match( Job_Repository::PUBLIC_ID_REGEX, $public_id ) ) {
			return null;
		}
		return Job_Repository::read_by_public_id( $public_id );
	}

	/**
	 * Approval step: binds the exact immutable plan (nonce action carries the
	 * stored plan ID and the repository re-verifies the full material
	 * binding), refuses BLOCKED plans, then queues the #109 worker wake-up.
	 * No whole-job synchronous execution happens here.
	 */
	public static function process_approve( array $post, string $method ): array {
		if ( ! self::can_mutate() ) {
			return array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' );
		}
		if ( 'POST' !== $method ) {
			return array( 'status' => 'INVALID', 'reason' => 'post_required' );
		}
		$dependency = self::dependency_refusal();
		if ( null !== $dependency ) { return $dependency; }
		$job = self::job_from_post( $post );
		if ( null === $job ) {
			return array( 'status' => 'INVALID', 'reason' => 'invalid_job' );
		}
		$nonce = $post['_wpnonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::ACTION_APPROVE . '_' . $job['plan_id'] ) ) {
			return array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' );
		}
		$user_id = get_current_user_id();
		if ( ! self::authorized_for_job( $job, $user_id ) ) {
			return array( 'status' => 'FORBIDDEN', 'reason' => 'not_authorized' );
		}
		if ( Job_State::BLOCKED === $job['status'] ) {
			return array( 'status' => 'INVALID', 'reason' => 'plan_policy_blocked', 'public_id' => $job['public_id'] );
		}
		try {
			$plan = Job_Repository::hydrate_plan( $job );
			Job_Repository::assert_binding( $job, $plan );
			// Stale/internal PLANNED jobs must be refused before approve() can
			// call Price_Apply_Journal::seed(). Never trust only total_selected.
			Free_Support_Contract::assert_job_size( $plan->summary()['selected'] );
		} catch ( Free_Job_Limit_Error $error ) {
			return self::invalid( $error );
		} catch ( \Throwable $error ) {
			return array( 'status' => 'INVALID', 'reason' => 'job_material_mismatch', 'public_id' => $job['public_id'] );
		}
		if ( Job_State::PLANNED !== $job['status'] ) {
			return array( 'status' => 'OK', 'reason' => 'already_approved', 'public_id' => $job['public_id'], 'job_status' => $job['status'] );
		}
		$rights = self::precheck_product_rights( $plan );
		if ( null !== $rights ) {
			$rights['public_id'] = $job['public_id'];
			return $rights;
		}
		try {
			$approved = Job_Repository::approve( (int) $job['id'], $user_id );
		} catch ( \Throwable $error ) {
			return array( 'status' => 'INVALID', 'reason' => self::invalid( $error )['reason'], 'public_id' => $job['public_id'] );
		}
		$queued = Job_Worker::queue_job( (int) $job['id'] );
		return array(
			'status' => 'OK',
			'reason' => 'SCHEDULED' === $queued ? 'approved' : 'approved_scheduler_unavailable',
			'public_id' => $job['public_id'],
			'job_status' => $approved['status'],
			'scheduler' => $queued,
		);
	}

	/**
	 * Bounded manual resume: one worker chunk under the #109 lease/fence,
	 * never a whole-job synchronous run. Stalled leases surface as PAUSED
	 * with LEASE_RECOVERY; the same action retries them.
	 */
	public static function process_resume( array $post, string $method ): array {
		if ( ! self::can_mutate() ) {
			return array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' );
		}
		if ( 'POST' !== $method ) {
			return array( 'status' => 'INVALID', 'reason' => 'post_required' );
		}
		$dependency = self::dependency_refusal();
		if ( null !== $dependency ) { return $dependency; }
		$job = self::job_from_post( $post );
		if ( null === $job ) {
			return array( 'status' => 'INVALID', 'reason' => 'invalid_job' );
		}
		$nonce = $post['_wpnonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::ACTION_RESUME . '_' . $job['public_id'] ) ) {
			return array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' );
		}
		$user_id = get_current_user_id();
		if ( ! self::authorized_for_job( $job, $user_id ) ) {
			return array( 'status' => 'FORBIDDEN', 'reason' => 'not_authorized' );
		}
		if ( Job_State::is_terminal( $job['status'] ) ) {
			return array( 'status' => 'INVALID', 'reason' => 'job_terminal', 'public_id' => $job['public_id'], 'job_status' => $job['status'] );
		}
		if ( ! Job_State::can_manual_run( $job['status'] ) ) {
			return array( 'status' => 'INVALID', 'reason' => 'job_not_resumable', 'public_id' => $job['public_id'], 'job_status' => $job['status'] );
		}
		try {
			$plan = Job_Repository::hydrate_plan( $job );
			// can_manual_run() already requires a post-approval state. Size
			// limits new work, not recovery of this verified historical plan.
		} catch ( \Throwable $error ) {
			return array( 'status' => 'INVALID', 'reason' => 'job_material_mismatch', 'public_id' => $job['public_id'] );
		}
		$rights = self::precheck_product_rights( $plan );
		if ( null !== $rights ) {
			$rights['public_id'] = $job['public_id'];
			return $rights;
		}
		try {
			$run = Job_Worker::run( (int) $job['id'], array(), true );
		} catch ( \Throwable $error ) {
			return array( 'status' => 'INVALID', 'reason' => 'worker_failed', 'public_id' => $job['public_id'] );
		}
		$current = Job_Repository::read( (int) $job['id'] );
		return array(
			'status' => 'OK',
			'reason' => 'resume_chunk',
			'public_id' => $job['public_id'],
			'job_status' => $current ? $current['status'] : $job['status'],
			'processed' => isset( $run['processed'] ) ? (int) $run['processed'] : 0,
			'stop' => isset( $run['stop'] ) && is_string( $run['stop'] ) ? $run['stop'] : '',
		);
	}

	/**
	 * Undo step: initiate the #110 operation for a safely stopped apply job
	 * and run one bounded chunk. Only exposed where the repository reports
	 * eligibility; expiry and conflicts never expose a Restore action.
	 */
	public static function process_undo( array $post, string $method ): array {
		if ( ! self::can_mutate() ) {
			return array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' );
		}
		if ( 'POST' !== $method ) {
			return array( 'status' => 'INVALID', 'reason' => 'post_required' );
		}
		$dependency = self::dependency_refusal();
		if ( null !== $dependency ) { return $dependency; }
		$job = self::job_from_post( $post );
		if ( null === $job ) {
			return array( 'status' => 'INVALID', 'reason' => 'invalid_job' );
		}
		$nonce = $post['_wpnonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::ACTION_UNDO . '_' . $job['public_id'] ) ) {
			return array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' );
		}
		$user_id = get_current_user_id();
		if ( ! self::authorized_for_job( $job, $user_id ) ) {
			return array( 'status' => 'FORBIDDEN', 'reason' => 'not_authorized' );
		}
		try {
			// Keep immutable binding verification. #110's durable state,
			// provenance, expiry and conflict checks authorize restoration;
			// a new-work support ceiling must not strand historical Undo.
			Job_Repository::hydrate_plan( $job );
			$operation = Undo_Repository::initiate( (int) $job['id'], $user_id );
		} catch ( Undo_Error $error ) {
			return array( 'status' => 'INVALID', 'reason' => $error->reason(), 'public_id' => $job['public_id'] );
		} catch ( \Throwable $error ) {
			return array( 'status' => 'INVALID', 'reason' => 'undo_unavailable', 'public_id' => $job['public_id'] );
		}
		if ( Undo_State::is_terminal( $operation['status'] ) ) {
			return array( 'status' => 'INVALID', 'reason' => 'undo_terminal', 'public_id' => $job['public_id'] );
		}
		try {
			$run = Undo_Worker::run( (int) $operation['id'], array(), true );
		} catch ( \Throwable $error ) {
			return array( 'status' => 'INVALID', 'reason' => 'worker_failed', 'public_id' => $job['public_id'] );
		}
		$current = Undo_Repository::read_operation( (int) $operation['id'] );
		return array(
			'status' => 'OK',
			'reason' => 'undo_chunk',
			'public_id' => $job['public_id'],
			'job_status' => $job['status'],
			'undo_status' => $current ? $current['status'] : $operation['status'],
			'processed' => isset( $run['processed'] ) ? (int) $run['processed'] : 0,
			'stop' => isset( $run['stop'] ) && is_string( $run['stop'] ) ? $run['stop'] : '',
		);
	}

	// ------------------------------------------------------------------
	// Read-only views. Every number comes from the plan or the durable
	// repositories; nothing is reconstructed in the browser.
	// ------------------------------------------------------------------

	public static function page_url( string $view = '', ?string $public_id = null, int $offset = 0 ): string {
		$args = array( 'page' => self::SLUG );
		if ( '' !== $view ) {
			$args['wl_view'] = $view;
		}
		if ( is_string( $public_id ) && '' !== $public_id ) {
			$args['wl_job'] = $public_id;
		}
		if ( $offset > 0 ) {
			$args['wl_offset'] = $offset;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html( 'You are not allowed to edit products.' ), '', array( 'response' => 403 ) );
		}
		$view_raw = filter_input( INPUT_GET, 'wl_view' );
		$view = is_string( $view_raw ) ? $view_raw : '';
		$job_raw = filter_input( INPUT_GET, 'wl_job' );
		$job_param = is_string( $job_raw ) ? $job_raw : '';
		$offset_raw = filter_input( INPUT_GET, 'wl_offset' );
		$offset = is_string( $offset_raw ) && preg_match( '/\A[0-9]{1,7}\z/', $offset_raw ) ? (int) $offset_raw : 0;
		echo '<div class="wrap"><h1>WriteLeash Bulk Prices</h1>';
		self::render_view( $view, $job_param, $offset );
		echo '</div>';
	}

	/**
	 * Testable view dispatch, including the transient notice and the Woo
	 * dependency gate. render() only adds the wrap heading around it.
	 */
	public static function render_view( string $view, string $job_param, int $offset ): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'You are not allowed to edit products.' ) . '</p></div>';
			return;
		}
		self::render_notice();
		if ( ! self::dependency_ok() ) {
			echo '<div class="notice notice-error" role="alert"><p>';
			echo esc_html( self::reason_message( (string) Free_Support_Contract::woocommerce_reason() ) );
			echo '</p></div>';
			return;
		}
		if ( 'preview' === $view ) {
			self::render_preview_view( $job_param, $offset );
		} elseif ( 'job' === $view ) {
			self::render_job_view( $job_param, $offset );
		} elseif ( 'history' === $view ) {
			self::render_history_view( $offset );
		} else {
			self::render_home_view();
		}
	}

	private static function render_notice(): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}
		$notice = get_transient( self::notice_key( $user_id ) );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( self::notice_key( $user_id ) );
		$status = isset( $notice['status'] ) && is_string( $notice['status'] ) ? $notice['status'] : 'INVALID';
		$reason = isset( $notice['reason'] ) && is_string( $notice['reason'] ) ? $notice['reason'] : 'action_failed';
		$class = 'OK' === $status ? 'notice-success' : ( 'FORBIDDEN' === $status ? 'notice-error' : 'notice-warning' );
		$selected = isset( $notice['selected_count'] ) && is_int( $notice['selected_count'] ) ? $notice['selected_count'] : null;
		echo '<div class="notice ' . esc_attr( $class ) . '" role="alert"><p><strong>' . esc_html( $status ) . '</strong>: ' . esc_html( self::reason_message( $reason, $selected ) ) . '</p>';
		if ( isset( $notice['processed'] ) ) {
			echo '<p>' . esc_html( 'Bounded chunk processed ' . (int) $notice['processed'] . ' item(s). Remaining work stays queued; use Resume again if items remain.' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Counts large/zero outcomes from frozen plan material only: warning
	 * codes are read, never re-evaluated, and zero is a canonical string
	 * comparison, never recomputed arithmetic.
	 */
	public static function preview_extra_counts( array $items ): array {
		$counts = array( 'large_increase' => 0, 'large_decrease' => 0, 'zero_target' => 0 );
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( (array) ( $item['warnings'] ?? array() ) as $warning ) {
				if ( 'large_price_increase' === $warning ) {
					++$counts['large_increase'];
				} elseif ( 'large_price_decrease' === $warning ) {
					++$counts['large_decrease'];
				}
			}
			$planned = $item['planned_regular_price'] ?? null;
			if ( 'CHANGING' === ( $item['result'] ?? '' ) && is_string( $planned ) ) {
				try {
					if ( '0' === Price_Decimal::parse( $planned ) ) {
						++$counts['zero_target'];
					}
				} catch ( \Throwable $error ) {
					// Unparseable planned prices cannot be changing items; ignore.
				}
			}
		}
		return $counts;
	}

	/** Display copy is separate from stable machine reason codes. */
	public static function reason_message( string $reason, ?int $selected = null ): string {
		if ( 'supported_job_limit_exceeded' === $reason ) {
			return 'WriteLeash Free 1.0 supports up to ' . Free_Support_Contract::MAX_JOB_PRODUCTS . ' products per job in the tested configuration. '
				. ( null === $selected ? '' : $selected . ' products were selected. ' )
				. 'Narrow the selection and build a new preview. No additional job or journal was created and no product was changed.';
		}
		if ( 'woocommerce_version_unsupported' === $reason ) {
			return 'WriteLeash Free 1.0 currently supports WooCommerce ' . Free_Support_Contract::WOOCOMMERCE_VERSION . ' for price mutations. Installed version: '
				. ( defined( 'WC_VERSION' ) ? (string) WC_VERSION : 'unavailable' ) . '. No job was created and no product was changed.';
		}
		$messages = array(
			'capability_required' => 'You need WooCommerce product management capabilities for this action.',
			'post_required' => 'This action requires an authenticated POST request.',
			'invalid_nonce' => 'The security token is missing or invalid. Reload the page and try again.',
			'woocommerce_unavailable' => 'WooCommerce must be active and initialized before bulk-price planning. Install and activate WooCommerce, then reopen this page. No job was created and no product was changed.',
			'permission_denied' => 'You do not have permission to plan these product edits.',
			'permission_revoked' => 'The approved actor can no longer edit one or more products; nothing was executed.',
			'not_authorized' => 'Only the job creator, approver or an administrator may perform this action.',
			'invalid_selector' => 'Select one of category, exact SKU or explicit product IDs.',
			'invalid_selection_size' => 'Select between one and ' . Free_Support_Contract::MAX_JOB_PRODUCTS . ' explicit IDs.',
			'invalid_product_id' => 'Product IDs must be comma-separated positive integers.',
			'invalid_sku' => 'Use a non-empty exact SKU without whitespace or markup.',
			'invalid_category' => 'Select an existing product category.',
			'unsupported_operation' => 'Select one of the five supported price operations.',
			'malformed_decimal' => 'Use an unsigned decimal amount string with a dot separator.',
			'invalid_product_limit' => 'The changed-product safety limit must be between zero and ' . Free_Support_Contract::MAX_JOB_PRODUCTS . '. It cannot raise the Free supported-job boundary.',
			'invalid_policy_percent' => 'Policy percentages must be unsigned decimal strings.',
			'invalid_policy_flag' => 'The zero-price flag is invalid.',
			'invalid_job' => 'No WriteLeash job matches that identifier.',
			'invalid_input' => 'A request field is malformed; nothing was changed.',
			'plan_blocked' => 'The safety policy blocks this plan; it cannot be approved.',
			'preview_ready' => 'Preview ready. Review the frozen plan before approving.',
			'plan_policy_blocked' => 'The plan policy blocks every changing product; it cannot be approved.',
			'already_approved' => 'This plan was already approved; no duplicate approval was recorded.',
			'job_material_mismatch' => 'Stored job material no longer matches its frozen plan; no product was changed.',
			'job_terminal' => 'The job already reached a terminal state.',
			'job_not_resumable' => 'The job state does not permit a manual resume.',
			'job_not_approvable' => 'The job state does not permit approval.',
			'approved' => 'The frozen plan was approved and a background wake-up was queued.',
			'approved_scheduler_unavailable' => 'Approved, but the scheduler is unavailable; the job is paused and the protected Resume action continues it.',
			'resume_chunk' => 'One bounded chunk ran. Review durable progress below.',
			'undo_chunk' => 'One bounded Undo chunk ran. Review durable Undo progress below.',
			'undo_terminal' => 'The Undo operation already reached a terminal state.',
			'undo_unavailable' => 'Undo is currently unavailable; no price was restored.',
			'worker_failed' => 'The bounded worker could not run; durable state was preserved.',
			'action_failed' => 'The action could not be completed; nothing was changed.',
		);
		if ( isset( $messages[ $reason ] ) ) {
			return $messages[ $reason ];
		}
		$price_messages = Price_Reason_Messages::all();
		if ( isset( $price_messages[ $reason ] ) ) {
			return $price_messages[ $reason ];
		}
		if ( '' !== $reason && preg_match( '/\A[A-Z0-9_]+\z/', $reason ) ) {
			$job_messages = Job_Reason::messages();
			if ( isset( $job_messages[ $reason ] ) ) {
				return $job_messages[ $reason ];
			}
			$undo_messages = Undo_Reason::messages();
			if ( isset( $undo_messages[ $reason ] ) ) {
				return $undo_messages[ $reason ];
			}
		}
		return 'The action could not be completed; nothing was changed.';
	}

	private static function render_home_view(): void {
		echo '<p>';
		echo esc_html( 'Change stored regular prices for published core simple products in the base store currency. Variations, sale prices, stock, orders and subscriptions are out of scope and are excluded with reasons.' );
		echo '</p>';
		echo '<p>' . esc_html( 'WriteLeash Free 1.0 supports up to ' . Free_Support_Contract::MAX_JOB_PRODUCTS . ' selected products per job in the tested configuration.' ) . '</p>';
		echo '<p>';
		echo esc_html( 'Planned prices are not guaranteed shopper prices: tax display, multi-currency, dynamic pricing and later concurrent edits still apply. A closed browser never loses truth: progress is rebuilt from the durable job store on every load.' );
		echo '</p>';
		if ( ! self::can_mutate() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( 'Your role can view this page but cannot plan or execute bulk-price changes.' ) . '</p></div>';
		}
		echo '<h2>1. Select products and operation</h2>';
		self::render_selector_form();
		echo '<h2>2. Recent jobs</h2>';
		self::render_recent_jobs();
		echo '<p><a class="button" href="' . esc_url( self::page_url( 'history' ) ) . '">' . esc_html( 'Open full history' ) . '</a></p>';
	}

	private static function selector_field( string $name, string $label, string $type, string $value, string $describedby, bool $required = false, ?int $min = null, ?int $max = null ): void {
		echo '<p><label for="writeleash-free-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><br>';
		echo '<input id="writeleash-free-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '" aria-describedby="' . esc_attr( $describedby ) . '"';
		if ( 'number' === $type ) {
			echo ' step="1"';
		}
		if ( $required ) {
			echo ' required';
		}
		if ( null !== $min ) {
			echo ' min="' . esc_attr( (string) $min ) . '"';
		}
		if ( null !== $max ) {
			echo ' max="' . esc_attr( (string) $max ) . '"';
		}
		echo '></p>';
	}

	private static function render_selector_form(): void {
		if ( ! self::can_mutate() ) {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_PREVIEW ) . '">';
		wp_nonce_field( self::ACTION_PREVIEW );
		echo '<fieldset><legend>' . esc_html( 'Product selection (one selector per preview)' ) . '</legend>';
		echo '<p id="writeleash-free-selector-help">' . esc_html( 'Category uses direct membership without descendants. SKU matches byte-for-byte. Explicit IDs are comma-separated. Workers never re-run the selector: the previewed IDs are the execution population.' ) . '</p>';
		echo '<p><label for="writeleash-free-selector">' . esc_html( 'Selector' ) . '</label><br>';
		echo '<select id="writeleash-free-selector" name="selector" aria-describedby="writeleash-free-selector-help">';
		foreach ( array( 'ids' => 'Explicit product IDs', 'sku' => 'Exact SKU', 'category' => 'Product category' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		self::selector_field( 'ids', 'Explicit IDs, e.g. 12,34,56', 'text', '', 'writeleash-free-selector-help' );
		self::selector_field( 'sku', 'Exact SKU', 'text', '', 'writeleash-free-selector-help' );
		self::selector_field( 'category', 'Category term ID', 'number', '', 'writeleash-free-selector-help', false, 1 );
		echo '</fieldset>';
		echo '<fieldset><legend>' . esc_html( 'Price operation' ) . '</legend>';
		echo '<p><label for="writeleash-free-operation">' . esc_html( 'Operation' ) . '</label><br>';
		echo '<select id="writeleash-free-operation" name="operation">';
		foreach ( array(
			Price_Operation::SET => 'Set regular price to amount',
			Price_Operation::INCREASE_FIXED => 'Increase by fixed amount',
			Price_Operation::DECREASE_FIXED => 'Decrease by fixed amount',
			Price_Operation::INCREASE_PERCENT => 'Increase by percent',
			Price_Operation::DECREASE_PERCENT => 'Decrease by percent',
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		self::selector_field( 'amount', 'Unsigned amount or percent, e.g. 80.00 or 20 (no % sign)', 'text', '', 'writeleash-free-operation', true );
		echo '</fieldset>';
		echo '<fieldset><legend>' . esc_html( 'Safety limits (a breach blocks the whole plan)' ) . '</legend>';
		$maximum = Free_Support_Contract::MAX_JOB_PRODUCTS;
		self::selector_field( 'max_products', 'Maximum changing products (0-' . $maximum . ')', 'number', (string) $maximum, 'writeleash-free-operation', true, 0, $maximum );
		self::selector_field( 'max_increase', 'Maximum increase percent', 'text', '50', 'writeleash-free-operation', true );
		self::selector_field( 'max_decrease', 'Maximum decrease percent', 'text', '50', 'writeleash-free-operation', true );
		self::selector_field( 'warning_threshold', 'Warning threshold percent', 'text', '20', 'writeleash-free-operation', true );
		echo '<p><input id="writeleash-free-block-zero" name="block_zero" type="checkbox" value="1"> <label for="writeleash-free-block-zero">' . esc_html( 'Block plans that set any changing price to zero' ) . '</label></p>';
		echo '</fieldset>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html( 'Build frozen preview' ) . '</button></p></form>';
	}

	/** Job tables exist yet. Pure page views never create tables; the first preview import installs them. */
	private static function jobs_installed(): bool {
		global $wpdb;
		try {
			return Job_Schema::ready( $wpdb );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	private static function render_recent_jobs(): void {
		if ( ! self::jobs_installed() ) {
			echo '<p>' . esc_html( 'No jobs yet. Build a frozen preview above to start.' ) . '</p>';
			return;
		}
		$user_id = get_current_user_id();
		try {
			// Actor-scoped in SQL: the page contains only caller-visible jobs.
			$page = Undo_Repository::history_jobs( 0, 5, $user_id );
		} catch ( \Throwable $error ) {
			echo '<p>' . esc_html( 'Job history is unavailable; the job tables may not be installed yet.' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html( 'Job' ) . '</th><th scope="col">' . esc_html( 'Status' ) . '</th><th scope="col">' . esc_html( 'Applied' ) . '</th><th scope="col">' . esc_html( 'Undo' ) . '</th></tr></thead><tbody>';
		if ( ! $page['jobs'] ) {
			echo '<tr><td colspan="4">' . esc_html( 'No jobs visible to your account yet.' ) . '</td></tr>';
		}
		foreach ( $page['jobs'] as $entry ) {
			$job = Job_Repository::read( (int) $entry['job_id'] );
			if ( null === $job ) {
				continue;
			}
			echo '<tr><td><a href="' . esc_url( self::page_url( 'job', $job['public_id'] ) ) . '">' . esc_html( substr( (string) $job['public_id'], 0, 8 ) ) . '</a></td>';
			echo '<td>' . esc_html( $job['status'] ) . ' (' . esc_html( $job['status_reason'] ) . ')</td>';
			echo '<td>' . esc_html( (string) $job['applied'] . ' / ' . (string) $job['planned'] ) . '</td>';
			echo '<td>' . esc_html( $entry['undo_eligible'] ? 'eligible' : 'not eligible' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function load_job_for_view( string $public_id ): ?array {
		if ( '' === $public_id || ! preg_match( Job_Repository::PUBLIC_ID_REGEX, $public_id ) ) {
			return null;
		}
		$job = Job_Repository::read_by_public_id( $public_id );
		if ( null === $job || ! self::authorized_for_job( $job, get_current_user_id() ) ) {
			return null;
		}
		return $job;
	}

	private static function render_preview_view( string $public_id, int $offset ): void {
		if ( ! self::jobs_installed() ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'No preview is visible to your account for that identifier.' ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( 'Back to bulk prices' ) . '</a></p>';
			return;
		}
		$job = self::load_job_for_view( $public_id );
		if ( null === $job ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'No preview is visible to your account for that identifier.' ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( 'Back to bulk prices' ) . '</a></p>';
			return;
		}
		try {
			$plan = Job_Repository::hydrate_plan( $job );
		} catch ( \Throwable $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'Stored job material no longer matches its frozen plan; no product was changed.' ) . '</p></div>';
			return;
		}
		$data = $plan->data();
		$summary = $plan->summary();
		if ( $summary['selected'] > Free_Support_Contract::MAX_JOB_PRODUCTS ) {
			if ( self::is_legacy_oversized_job( $job, $summary['selected'] ) ) {
				self::render_legacy_oversize_warning();
				echo '<p><a href="' . esc_url( self::page_url( 'job', $job['public_id'] ) ) . '">' . esc_html( 'Open existing durable progress and eligible Undo' ) . '</a></p>';
			} else {
				echo '<div class="notice notice-error" role="alert"><p>' . esc_html( self::reason_message( 'supported_job_limit_exceeded', $summary['selected'] ) ) . '</p></div>';
			}
			return;
		}
		$blocked = 'BLOCKED' === $data['status'];
		echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( 'Back to bulk prices' ) . '</a></p>';
		echo '<h2>' . esc_html( 'Frozen preview' ) . '</h2>';
		echo '<p>' . esc_html( 'Plan ' . $data['plan_id'] . ' · fingerprint ' . substr( $plan->hash(), 0, 12 ) . ' · operation ' . $data['operation']['type'] . ' ' . $data['operation']['input'] . '.' ) . '</p>';
		echo '<p>' . esc_html( 'Selected ' . $summary['selected'] . ' · eligible ' . $summary['eligible'] . ' · changing ' . $summary['changing'] . ' · unchanged ' . $summary['unchanged'] . ' · unsupported ' . $summary['unsupported'] . ' · blocked ' . $summary['blocked'] . ' · warning items ' . $summary['warning_items'] . '.' ) . '</p>';
		$extra_counts = self::preview_extra_counts( $data['items'] );
		echo '<p>' . esc_html( 'Large increases ' . $extra_counts['large_increase'] . ' · large decreases ' . $extra_counts['large_decrease'] . ' · zero-price targets ' . $extra_counts['zero_target'] . ' · conflicts ' . $summary['conflicted'] . ' (a preview never carries conflicts; they surface during execution).' ) . '</p>';
		if ( $blocked ) {
			echo '<div class="notice notice-error" role="alert"><p><strong>' . esc_html( 'BLOCKED' ) . '</strong>: ' . esc_html( 'the safety policy blocks every changing product. Approval and execution are impossible for this plan; build a new preview with different inputs.' ) . '</p>';
			foreach ( $data['policy_result']['blockers'] as $blocker ) {
				$product = isset( $blocker['product_id'] ) && null !== $blocker['product_id'] ? 'product ' . (int) $blocker['product_id'] . ': ' : '';
				echo '<p>' . esc_html( $product . (string) ( $blocker['reason'] ?? 'blocked' ) ) . '</p>';
			}
			echo '</div>';
		}
		$limit = self::PREVIEW_PAGE_SIZE;
		try {
			$page = $plan->preview_page( $offset, $limit );
		} catch ( \Throwable $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'Invalid preview page; use a nonnegative offset.' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html( 'Product' ) . '</th><th scope="col">' . esc_html( 'SKU' ) . '</th><th scope="col">' . esc_html( 'Before' ) . '</th><th scope="col">' . esc_html( 'After' ) . '</th><th scope="col">' . esc_html( 'Delta' ) . '</th><th scope="col">' . esc_html( 'Change %' ) . '</th><th scope="col">' . esc_html( 'State / reason' ) . '</th></tr></thead><tbody>';
		foreach ( $page['items'] as $item ) {
			$state = (string) $item['result'];
			$detail = array();
			if ( isset( $item['eligibility']['reason'] ) && null !== $item['eligibility']['reason'] ) {
				$detail[] = (string) $item['eligibility']['reason'];
			}
			foreach ( $item['blockers'] as $blocker ) {
				$detail[] = is_array( $blocker ) ? (string) ( $blocker['reason'] ?? 'blocked' ) : (string) $blocker;
			}
			foreach ( $item['warnings'] as $warning ) {
				$detail[] = (string) $warning;
			}
			// The percentage figure is the frozen plan display value, shown as-is.
			$ratio = $item['percentage_delta'] ?? null;
			$ratio_text = ( is_array( $ratio ) && isset( $ratio['display'] ) && is_string( $ratio['display'] ) ) ? $ratio['display'] . '%' : '—';
			echo '<tr><td>' . esc_html( (string) $item['product_id'] . ' · ' . (string) ( $item['name'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $item['sku'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $item['stored_regular_price'] ) . '</td>';
			echo '<td>' . esc_html( null === $item['planned_regular_price'] ? '—' : (string) $item['planned_regular_price'] ) . '</td>';
			echo '<td>' . esc_html( null === $item['absolute_delta'] ? '—' : (string) $item['absolute_delta'] ) . '</td>';
			echo '<td>' . esc_html( $ratio_text ) . '</td>';
			echo '<td>' . esc_html( $state . ( $detail ? ' (' . implode( ', ', $detail ) . ')' : '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::render_pager( 'preview', $job['public_id'], $offset, $limit, $page['next_offset'] );
		if ( ! $blocked && Job_State::PLANNED === $job['status'] && self::can_mutate() ) {
			echo '<h2>' . esc_html( 'Approve exact plan' ) . '</h2>';
			echo '<p>' . esc_html( 'Approval executes exactly the frozen IDs and absolute target prices above. Planned prices are not guaranteed shopper prices. Any selection, operation or policy change requires a new preview; this approval cannot be reused for different inputs.' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_APPROVE ) . '">';
			echo '<input type="hidden" name="job" value="' . esc_attr( $job['public_id'] ) . '">';
			wp_nonce_field( self::ACTION_APPROVE . '_' . $job['plan_id'] );
			echo '<p><button type="submit" class="button button-primary">' . esc_html( 'Approve and queue execution' ) . '</button></p></form>';
		} elseif ( ! $blocked ) {
			echo '<p>' . esc_html( 'Current job state: ' . $job['status'] . '. ' ) . '<a href="' . esc_url( self::page_url( 'job', $job['public_id'] ) ) . '">' . esc_html( 'Open durable progress' ) . '</a></p>';
		}
	}

	private static function render_pager( string $view, string $public_id, int $offset, int $limit, $next_offset ): void {
		echo '<p>';
		if ( $offset > 0 ) {
			$prev = max( 0, $offset - $limit );
			echo '<a class="button" href="' . esc_url( self::page_url( $view, $public_id, $prev ) ) . '">' . esc_html( 'Previous page' ) . '</a> ';
		} else {
			echo '<button type="button" class="button" disabled aria-disabled="true">' . esc_html( 'Previous page' ) . '</button> ';
		}
		if ( null !== $next_offset ) {
			echo '<a class="button" href="' . esc_url( self::page_url( $view, $public_id, (int) $next_offset ) ) . '">' . esc_html( 'Next page' ) . '</a>';
		} else {
			echo '<button type="button" class="button" disabled aria-disabled="true">' . esc_html( 'Next page' ) . '</button>';
		}
		echo '</p>';
	}

	private static function render_job_view( string $public_id, int $offset ): void {
		if ( ! self::jobs_installed() ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'No job is visible to your account for that identifier.' ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( 'Back to bulk prices' ) . '</a></p>';
			return;
		}
		$job = self::load_job_for_view( $public_id );
		if ( null === $job ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'No job is visible to your account for that identifier.' ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( 'Back to bulk prices' ) . '</a></p>';
			return;
		}
		try {
			$observed = Job_Repository::observe( (int) $job['id'] );
			$history = Undo_Repository::history_job( (int) $job['id'] );
			$selected = Job_Repository::hydrate_plan( $job )->summary()['selected'];
		} catch ( \Throwable $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'Durable job state is unavailable; the job tables may be incomplete.' ) . '</p></div>';
			return;
		}
		$counts = $observed['counts'];
		$effective = $observed['effective_status'];
		echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( 'Back to bulk prices' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( self::page_url( 'history' ) ) . '">' . esc_html( 'Open history' ) . '</a></p>';
		echo '<h2>' . esc_html( 'Durable progress' ) . '</h2>';
		echo '<p>' . esc_html( 'State: ' . $effective . ' (' . $observed['effective_reason'] . ').' ) . '</p>';
		echo '<p>' . esc_html( 'Planned ' . $counts['planned'] . ' · pending ' . $counts['pending'] . ' · applying ' . $counts['applying'] . ' · applied ' . $counts['applied'] . ' · unchanged ' . $counts['unchanged'] . ' · conflict ' . $counts['conflict'] . ' · failed ' . $counts['failed'] . ' · needs review ' . $counts['needs_review'] . ' · unsupported ' . $counts['unsupported'] . '.' ) . '</p>';
		if ( $observed['stalled'] ) {
			echo '<div class="notice notice-warning" role="alert"><p>' . esc_html( 'The worker lease expired without progress; the job is paused and a protected Resume continues it. Nothing was marked successful without durable proof.' ) . '</p></div>';
		}
		if ( in_array( $effective, array( Job_State::COMPLETED_WITH_ISSUES, Job_State::NEEDS_REVIEW ), true ) ) {
			echo '<div class="notice notice-warning" role="alert"><p>' . esc_html( 'This job finished with conflicts or items needing review. Review the item list below; partial results are never reported as generic success.' ) . '</p></div>';
		}
		if ( self::is_legacy_oversized_job( $job, $selected ) ) {
			self::render_legacy_oversize_warning();
		} elseif ( $selected > Free_Support_Contract::MAX_JOB_PRODUCTS ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( self::reason_message( 'supported_job_limit_exceeded', $selected ) ) . '</p></div>';
		}
		if ( Job_State::can_manual_run( $job['status'] ) && self::can_mutate() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RESUME ) . '">';
			echo '<input type="hidden" name="job" value="' . esc_attr( $job['public_id'] ) . '">';
			wp_nonce_field( self::ACTION_RESUME . '_' . $job['public_id'] );
			echo '<p><button type="submit" class="button button-primary">' . esc_html( 'Run bounded resume chunk' ) . '</button> ';
			echo esc_html( 'Runs at most one bounded chunk under the job lease, then re-queues if items remain.' ) . '</p></form>';
		}
		echo '<h2>' . esc_html( 'Items' ) . '</h2>';
		try {
			$items = Undo_Repository::history_items( (int) $job['id'], null, null, $offset, self::ITEM_PAGE_SIZE );
		} catch ( \Throwable $error ) {
			echo '<p>' . esc_html( 'Item details are unavailable for this page.' ) . '</p>';
			$items = null;
		}
		if ( null !== $items ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html( 'Product' ) . '</th><th scope="col">' . esc_html( 'Expected' ) . '</th><th scope="col">' . esc_html( 'Planned' ) . '</th><th scope="col">' . esc_html( 'Apply' ) . '</th><th scope="col">' . esc_html( 'Undo' ) . '</th></tr></thead><tbody>';
			foreach ( $items['items'] as $item ) {
				echo '<tr><td>' . esc_html( (string) $item['product_id'] ) . '</td>';
				echo '<td>' . esc_html( (string) $item['expected_price'] ) . '</td>';
				echo '<td>' . esc_html( (string) $item['planned_price'] ) . '</td>';
				echo '<td>' . esc_html( (string) $item['apply_state'] . ' (' . (string) $item['apply_reason'] . ')' ) . '</td>';
				echo '<td>' . esc_html( null === $item['undo_state'] ? '—' : (string) $item['undo_state'] . ' (' . (string) $item['undo_reason'] . ')' ) . '</td></tr>';
			}
			echo '</tbody></table>';
			self::render_pager( 'job', $job['public_id'], $offset, self::ITEM_PAGE_SIZE, $items['next_offset'] );
		}
		echo '<h2>' . esc_html( 'Undo' ) . '</h2>';
		self::render_undo_section( $job, $history );
	}

	/** Post-approval states are durable authority, not a new size certification. */
	private static function is_legacy_oversized_job( array $job, int $selected ): bool {
		return $selected > Free_Support_Contract::MAX_JOB_PRODUCTS && (
			Job_State::is_executable( $job['status'] ) ||
			in_array( $job['status'], array( Job_State::COMPLETED, Job_State::COMPLETED_WITH_ISSUES ), true )
		);
	}

	public static function legacy_oversize_message(): string {
		return 'This existing durable job was already approved. The current Free 1.0 limit of ' . Free_Support_Contract::MAX_JOB_PRODUCTS
			. ' products applies to new work: new jobs above ' . Free_Support_Contract::MAX_JOB_PRODUCTS . ' cannot be created or approved. '
			. 'Bounded recovery and conflict-aware eligible Undo remain available only to safely finish or restore this existing job.';
	}

	private static function render_legacy_oversize_warning(): void {
		echo '<div class="notice notice-warning" role="alert"><p><strong>' . esc_html( 'Legacy oversized job' ) . '</strong></p><p>' . esc_html( self::legacy_oversize_message() ) . '</p></div>';
	}

	private static function render_undo_section( array $job, array $history ): void {
		echo '<p>' . esc_html( 'Undo restores eligible stored regular-price values changed by WriteLeash. Undo does not reverse: orders, completed sales, email, webhook, HTTP side effects, or arbitrary plugin side effects.' ) . '</p>';
		if ( ! empty( $history['undo_expires_at'] ) ) {
			echo '<p>' . esc_html( 'History expires: ' . (string) $history['undo_expires_at'] . '.' ) . '</p>';
		}
		if ( ! empty( $history['undo']['operation_status'] ) ) {
			echo '<p>' . esc_html( 'Undo operation: ' . (string) $history['undo']['operation_status'] . ' (' . (string) $history['undo']['operation_reason'] . ') · undone ' . (int) $history['undo']['undone'] . ' · conflict ' . (int) $history['undo']['conflict'] . ' · failed ' . (int) $history['undo']['failed'] . ' · needs review ' . (int) $history['undo']['needs_review'] . '.' ) . '</p>';
		}
		if ( $history['undo_eligible'] && self::can_mutate() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_UNDO ) . '">';
			echo '<input type="hidden" name="job" value="' . esc_attr( $job['public_id'] ) . '">';
			wp_nonce_field( self::ACTION_UNDO . '_' . $job['public_id'] );
			echo '<p><button type="submit" class="button button-secondary">' . esc_html( 'Restore eligible prices (Undo)' ) . '</button> ';
			echo esc_html( 'Later supported WooCommerce edits are never overwritten: conflicts stay conflicts.' ) . '</p></form>';
		} elseif ( ! $history['undo_eligible'] ) {
			echo '<p>' . esc_html( 'Undo is not eligible for this job (no applied items, active processing, a finished Undo operation, or expired history). No Restore action is exposed.' ) . '</p>';
		}
	}

	private static function render_history_view( int $offset ): void {
		echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( 'Back to bulk prices' ) . '</a></p>';
		echo '<h2>' . esc_html( 'History' ) . '</h2>';
		if ( ! self::jobs_installed() ) {
			echo '<p>' . esc_html( 'No jobs yet. Build a frozen preview to start.' ) . '</p>';
			return;
		}
		try {
			$page = Undo_Repository::history_jobs( $offset, self::HISTORY_PAGE_SIZE, get_current_user_id() );
		} catch ( \Throwable $error ) {
			echo '<p>' . esc_html( 'Job history is unavailable; the job tables may not be installed yet.' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html( 'Job' ) . '</th><th scope="col">' . esc_html( 'Status' ) . '</th><th scope="col">' . esc_html( 'Apply counts' ) . '</th><th scope="col">' . esc_html( 'Undo' ) . '</th><th scope="col">' . esc_html( 'Expires' ) . '</th></tr></thead><tbody>';
		if ( ! $page['jobs'] ) {
			echo '<tr><td colspan="5">' . esc_html( 'No jobs visible to your account yet.' ) . '</td></tr>';
		}
		foreach ( $page['jobs'] as $entry ) {
			$job = Job_Repository::read( (int) $entry['job_id'] );
			if ( null === $job ) {
				continue;
			}
			$apply = $entry['apply'];
			echo '<tr><td><a href="' . esc_url( self::page_url( 'job', $job['public_id'] ) ) . '">' . esc_html( substr( (string) $job['public_id'], 0, 8 ) ) . '</a></td>';
			echo '<td>' . esc_html( $job['status'] ) . ' (' . esc_html( $job['status_reason'] ) . ')</td>';
			echo '<td>' . esc_html( 'applied ' . (int) $apply['applied'] . ' / planned ' . (int) $apply['planned'] . ' · conflict ' . (int) $apply['conflict'] . ' · failed ' . (int) $apply['failed'] . ' · review ' . (int) $apply['needs_review'] ) . '</td>';
			echo '<td>' . esc_html( $entry['undo_eligible'] ? 'eligible' : 'not eligible' ) . '</td>';
			echo '<td>' . esc_html( null === $entry['undo_expires_at'] ? '—' : (string) $entry['undo_expires_at'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::render_pager( 'history', '', $offset, self::HISTORY_PAGE_SIZE, $page['next_offset'] );
	}
}
