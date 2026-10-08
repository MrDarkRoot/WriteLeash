<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-product-discovery.php';

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
	public const ACTION_EXPORT = 'writeleash_free_export';
	public const ACTION_PROGRESS = 'writeleash_free_progress';
	public const PREVIEW_PAGE_SIZE = 20;
	public const HISTORY_PAGE_SIZE = 20;
	public const ITEM_PAGE_SIZE = 50;
	private const NOTICE_SECONDS = 120;

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		Product_Discovery::boot();
		add_action( 'wp_ajax_' . self::ACTION_PROGRESS, array( __CLASS__, 'handle_progress' ) );
		// Expired sessions receive an explicit refusal; the handler gates before every saved read.
		add_action( 'wp_ajax_nopriv_' . self::ACTION_PROGRESS, array( __CLASS__, 'handle_progress' ) );
		add_action( 'admin_post_' . self::ACTION_PREVIEW, array( __CLASS__, 'handle_preview' ) );
		add_action( 'admin_post_' . self::ACTION_APPROVE, array( __CLASS__, 'handle_approve' ) );
		add_action( 'admin_post_' . self::ACTION_RESUME, array( __CLASS__, 'handle_resume' ) );
		add_action( 'admin_post_' . self::ACTION_UNDO, array( __CLASS__, 'handle_undo' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( __CLASS__, 'handle_export' ) );
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

	/** Woo enhancement assets are isolated to this Admin screen; native form remains usable. */
	public static function assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'product_page_' . self::SLUG, 'woocommerce_page_' . self::SLUG, 'tools_page_' . self::SLUG ), true ) ) { return; }
		wp_enqueue_style( 'writeleash-free-selection', plugins_url( 'includes/free/free-selection.css', WRITELEASH_PLUGIN_FILE ), array(), WRITELEASH_VERSION );
		if ( ! self::can_mutate() || ! self::dependency_ok() ) { return; }
		wp_enqueue_script( 'writeleash-free-progress', plugins_url( 'includes/free/free-progress.js', WRITELEASH_PLUGIN_FILE ), array(), WRITELEASH_VERSION, true );
		if ( ! wp_script_is( 'selectWoo', 'registered' ) ) { return; }
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'writeleash-free-selection', plugins_url( 'includes/free/free-selection.js', WRITELEASH_PLUGIN_FILE ), array( 'jquery', 'selectWoo' ), WRITELEASH_VERSION, true );
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

	public static function request_method(): string {
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

	/** Normalize only nonce input; other mutation fields keep their strict contracts. */
	private static function normalize_nonce( $value ): ?string {
		if ( ! is_string( $value ) ) { return null; }
		return sanitize_text_field( wp_unslash( $value ) );
	}

	private static function post_input(): array {
		return array(
			'_wpnonce' => self::post_field( '_wpnonce' ),
			'selector' => self::post_field( 'selector' ),
			'ids' => self::post_field( 'ids' ),
			'sku' => self::post_field( 'sku' ),
			'category' => self::post_field( 'category' ),
			'include_subcategories' => self::post_field( 'include_subcategories' ),
			'operation' => self::post_field( 'operation' ),
			'price_field' => self::post_field( 'price_field' ),
			'amount' => self::post_field( 'amount' ),
			'max_products' => self::post_field( 'max_products' ),
			'max_increase' => self::post_field( 'max_increase' ),
			'max_decrease' => self::post_field( 'max_decrease' ),
			'warning_threshold' => self::post_field( 'warning_threshold' ),
			'block_zero' => self::post_field( 'block_zero' ),
			'job' => self::post_field( 'job' ),
			'picker_present' => self::post_field( 'picker_present' ),
			'product_ids' => filter_input( INPUT_POST, 'product_ids', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ),
			'discovery_nonce' => self::post_field( 'discovery_nonce' ),
			'selection_action' => self::post_field( 'selection_action' ),
			'product_search' => self::post_field( 'product_search' ),
			'category_search' => self::post_field( 'category_search' ),
			'product_page' => self::post_field( 'product_page' ),
			'category_page' => self::post_field( 'category_page' ),
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
		$post = self::post_input();
		$result = self::process_preview( $post, self::request_method() );
		if ( 'INVALID' === ( $result['status'] ?? '' ) && null === self::gate( self::ACTION_PREVIEW, $post, self::request_method() ) ) {
			set_transient( self::form_key(), self::retained_inputs( $post ), self::NOTICE_SECONDS );
		}
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

	/** Authenticated AJAX is read-only: no schema installer, worker, product read or cache eviction. */
	public static function handle_progress(): void {
		$result = self::progress_snapshot( array(
			'_wpnonce' => self::post_field( '_wpnonce' ), 'job' => self::post_field( 'job' ),
			'offset' => self::post_field( 'offset' ), 'filter' => self::post_field( 'filter' ),
		), self::request_method() );
		nocache_headers();
		if ( 'OK' === $result['status'] ) { wp_send_json_success( $result ); }
		else { wp_send_json_error( array( 'reason' => $result['reason'] ), 'FORBIDDEN' === $result['status'] ? 403 : 503 ); }
	}

	/** A bounded observation of saved Apply/Undo records. IDs and nonces grant no actor override. */
	public static function progress_snapshot( array $post, string $method ): array {
		$refused = array( 'status' => 'FORBIDDEN', 'reason' => 'progress_unavailable' );
		if ( get_current_user_id() < 1 || ! self::can_mutate() || 'POST' !== $method ) { return $refused; }
		$public_id = $post['job'] ?? null;
		$nonce = self::normalize_nonce( $post['_wpnonce'] ?? null );
		if ( ! is_string( $public_id ) || ! preg_match( Job_Repository::PUBLIC_ID_REGEX, $public_id ) ||
			! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::ACTION_PROGRESS . '_' . $public_id ) ) { return $refused; }
		$offset = $post['offset'] ?? '0';
		$filters = array( '' => array( null, null ), 'conflict' => array( 'CONFLICT', null ), 'attention' => array( 'FAILED', null ),
			'review' => array( 'NEEDS_REVIEW', null ), 'undo_conflict' => array( null, 'UNDO_CONFLICT' ), 'undo_review' => array( null, 'UNDO_NEEDS_REVIEW' ) );
		$filter = $post['filter'] ?? '';
		if ( ! is_string( $offset ) || ! preg_match( '/\A(?:0|[1-9][0-9]{0,5})\z/D', $offset ) || (int) $offset > Undo_Repository::MAX_EVIDENCE_ROWS ||
			! is_string( $filter ) || ! isset( $filters[$filter] ) ) { return $refused; }
		try {
			if ( ! self::jobs_installed() ) { return $refused; }
			$job = self::load_job_for_view( $public_id );
			if ( null === $job ) { return $refused; }
			$observed = Job_Repository::observe( (int) $job['id'] );
			$job = $observed['job'];
			$counts = $observed['counts'];
			$plan = Job_Repository::hydrate_plan( $job );
			$history = Undo_Repository::history_job( (int) $job['id'] );
			$complete = $counts['planned'] === $plan->summary()['selected'];
			$undo_observed = null;
			if ( ! empty( $history['undo']['operation_id'] ) ) {
				$undo_observed = Undo_Repository::observe( (int) $history['undo']['operation_id'] );
				$history['undo'] = array_merge( $history['undo'], $undo_observed['counts'], array( 'operation_status' => $undo_observed['effective_status'] ) );
			}
			$identities = array_column( $plan->data()['items'], null, 'product_id' );
			$page = Undo_Repository::history_items( (int) $job['id'], $filters[$filter][0], $filters[$filter][1], (int) $offset, self::ITEM_PAGE_SIZE );
			$rows = array();
			foreach ( $page['items'] as $item ) {
				$frozen = $identities[$item['product_id']] ?? null;
				if ( null === $frozen ) { throw new \RuntimeException( 'Saved identity unavailable' ); }
			$apply_reason = 'UNSUPPORTED' === $item['apply_state'] ? ( $frozen['eligibility']['reason'] ?? $item['apply_reason'] ) : $item['apply_reason'];
			$apply_conflict = self::outcome_conflict_copy( $item['apply_state'] );
			$undo_conflict = self::outcome_conflict_copy( $item['undo_state'] );
			// Polled cells must stay byte-identical to the server-rendered row cells:
			// the same renderer produces both, so live updates keep the outcome
			// emphasis and the conflict next-action links instead of flattening
			// them to plain text. All bytes are server-escaped static copy/links.
			$observation = self::product_observation( (int) $item['product_id'], $plan->price_field() );
			$rows[] = array(
				'id' => (int) $item['product_id'],
				'name' => self::identity_name( $frozen['snapshot'] ) . ' · #' . (int) $item['product_id'] . ' · as reviewed',
				'expected' => self::money_display( self::expected_display( $frozen, $item, $plan->price_field() ), $plan->data()['store'] ),
				'planned' => self::money_display( $item['planned_price'], $plan->data()['store'] ),
				'apply' => self::item_label( $item['apply_state'], $job['status'] ) . ( '' !== $apply_conflict ? ' · ' . $apply_conflict : ( $apply_reason ? ' · ' . self::reason_message( $apply_reason ) : '' ) ),
				'undo' => null === $item['undo_state'] ? self::undo_item_label( $item, $job['status'] ) : self::item_label( $item['undo_state'], $job['status'] ) . ( '' !== $undo_conflict ? ' · ' . $undo_conflict : ( $item['undo_reason'] ? ' · ' . self::reason_message( $item['undo_reason'] ) : '' ) ),
				'apply_html' => self::outcome_cell_html( $item, $frozen, $apply_reason, $job['status'], $observation, true ),
				'undo_html' => self::outcome_cell_html( $item, $frozen, $item['undo_reason'], $job['status'], $observation, false ),
				'apply_attention' => in_array( $item['apply_state'], array( 'CONFLICT', 'FAILED', 'NEEDS_REVIEW' ), true ),
				'undo_attention' => in_array( $item['undo_state'], array( 'UNDO_CONFLICT', 'UNDO_FAILED', 'UNDO_NEEDS_REVIEW' ), true ),
			);
			}
			$effective = $observed['effective_status'];
			$undo = $history['undo'];
			$active = $complete && ! $observed['stalled'] && in_array( $effective, array( Job_State::READY, Job_State::QUEUED, Job_State::RUNNING ), true );
			$undo_active = $complete && null !== $undo_observed && ! $undo_observed['stalled'] && Undo_State::can_auto_run( $undo['operation_status'] );
			$undo_summary = (int) $undo['undone'] . ' restored · ' . (int) $undo['conflict'] . ' conflicts · ' . (int) $undo['pending'] . ' remaining · ' . (int) $undo['failed'] . ' needing attention · ' . (int) $undo['applying'] . ' in progress · ' . (int) $undo['needs_review'] . ' uncertain';
			return array(
				'status' => 'OK', 'counts' => $counts, 'undo_counts' => $undo,
				'label' => $complete ? self::job_label( $effective ) : 'Saved product outcomes unavailable; needs checking',
				'summary' => $complete ? self::result_summary( $counts, $effective ) : 'Some saved product evidence is unavailable. Check this job before taking further action.',
				'notice' => $observed['stalled'] ? 'Background processing stopped making progress. Resume only remaining products.' : ( in_array( $effective, array( Job_State::PAUSED, Job_State::NEEDS_REVIEW ), true ) ? self::reason_message( $observed['effective_reason'] ) : '' ),
				'undo_label' => $complete ? self::undo_availability( $job, $history ) : 'Undo availability cannot be verified while product outcomes are missing.', 'undo_summary' => $undo_summary,
				'undo_notice' => null !== $undo_observed && $undo_observed['stalled'] ? 'Undo stopped making progress. Continue Undo only for remaining products.' : '',
				'resume_available' => $complete && Job_State::can_manual_run( $job['status'] ),
				'undo_available' => $complete && $history['undo_eligible'] && ( empty( $undo['operation_status'] ) || Undo_State::can_manual_run( $undo['operation_status'] ) ),
				'resume_nonce' => $complete && Job_State::can_manual_run( $job['status'] ) ? wp_create_nonce( self::ACTION_RESUME . '_' . $public_id ) : null,
				'undo_nonce' => $complete && $history['undo_eligible'] && ( empty( $undo['operation_status'] ) || Undo_State::can_manual_run( $undo['operation_status'] ) ) ? wp_create_nonce( self::ACTION_UNDO . '_' . $public_id ) : null,
				'undo_button' => empty( $undo['operation_status'] ) ? 'Restore eligible prices (Undo)' : 'Continue Undo',
				'poll' => $active || $undo_active, 'rows' => $rows, 'next_url' => null === $page['next_offset'] ? null : self::page_url( 'job', $public_id, (int) $page['next_offset'], $filter ),
			);
		} catch ( \Throwable $error ) { return array( 'status' => 'UNAVAILABLE', 'reason' => 'saved_progress_unavailable' ); }
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
		$nonce = self::normalize_nonce( $post['_wpnonce'] ?? null );
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
		if ( 'manual_ids' === $kind ) { $kind = 'ids'; }
		elseif ( 'ids' === $kind && '1' === ( $post['picker_present'] ?? '' ) ) {
			$post['ids'] = implode( ',', self::picker_ids( $post ) );
		}
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
			$include_children = $post['include_subcategories'] ?? null;
			if ( null !== $include_children && '1' !== $include_children ) { throw new Price_Validation_Error( 'invalid_category' ); }
			return Price_Selection_Spec::category( (int) $raw, '1' === $include_children );
		}
		throw new Price_Validation_Error( 'invalid_selector' );
	}

	/** Only the merchant picker deduplicates IDs; the established advanced contract stays intact. */
	private static function picker_ids( array $post ): array {
		$raw = $post['product_ids'] ?? array();
		if ( null === $raw ) { $raw = array(); }
		if ( ! is_array( $raw ) || count( $raw ) > Free_Support_Contract::MAX_JOB_PRODUCTS + 1 ) { throw new Price_Validation_Error( 'invalid_selection_size' ); }
		$ids = array();
		foreach ( $raw as $value ) {
			if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]{1,10}\z/', $value ) || (int) $value < 1 ) { throw new Price_Validation_Error( 'invalid_product_id' ); }
			$ids[] = (int) $value;
		}
		$ids = array_values( array_unique( $ids ) );
		Free_Support_Contract::assert_job_size( count( $ids ) );
		return $ids;
	}

	private static function form_key(): string {
		return 'writeleash_free_form_' . get_current_user_id() . '_' . substr( hash( 'sha256', wp_get_session_token() ), 0, 16 );
	}

	/** Bounded, escaped-on-output, session-scoped input recovery; never plan or execution truth. */
	private static function retained_inputs( array $post ): array {
		$values = array();
		foreach ( array( 'selector', 'ids', 'sku', 'category', 'include_subcategories', 'operation', 'price_field', 'amount', 'max_products', 'max_increase', 'max_decrease', 'warning_threshold', 'block_zero', 'product_search', 'category_search', 'product_page', 'category_page' ) as $key ) {
			$value = $post[$key] ?? null;
			if ( is_string( $value ) && strlen( $value ) <= ( 'ids' === $key ? 20000 : 100 ) ) { $values[$key] = $value; }
		}
		try { $values['product_ids'] = array_map( 'strval', self::picker_ids( $post ) ); }
		catch ( \Throwable $error ) { $values['product_ids'] = array(); }
		return $values;
	}

	/** Native fallback form actions are authenticated reads, never preview imports or saves. */
	public static function process_selection( array $post, string $method ): array {
		$gate_post = array( '_wpnonce' => $post['discovery_nonce'] ?? null );
		$refused = self::gate( Product_Discovery::ACTION, $gate_post, $method );
		if ( null !== $refused ) { return $refused; }
		$values = self::retained_inputs( $post );
		try {
			$ids = self::picker_ids( $post );
			$action = $post['selection_action'] ?? '';
			if ( 'clear-products' === $action ) { $ids = array(); }
			elseif ( is_string( $action ) && preg_match( '/\Aremove:([0-9]{1,10})\z/', $action, $match ) ) { $ids = array_values( array_diff( $ids, array( (int) $match[1] ) ) ); }
			elseif ( ! in_array( $action, array( 'search-products', 'next-products', 'search-categories', 'next-categories', 'update-products', 'count-targets' ), true ) ) { throw new Price_Validation_Error( 'invalid_discovery' ); }
			Product_Discovery::selected( $ids );
			$values['product_ids'] = array_map( 'strval', $ids );
			foreach ( array( 'products' => 'product', 'categories' => 'category' ) as $kind => $prefix ) {
				if ( 'search-' . $kind === $action ) { $values[$prefix . '_page'] = '1'; }
				if ( 'next-' . $kind === $action ) { $values[$prefix . '_page'] = (string) min( Product_Discovery::MAX_PAGE, max( 1, (int) ( $values[$prefix . '_page'] ?? 1 ) ) + 1 ); }
			}
			if ( 'count-targets' === $action ) {
				try { $values['selection_count'] = Product_Discovery::selection_count( self::build_selection( $post ) ); }
				catch ( Price_Validation_Error $error ) {
					if ( 'selection_limit_exceeded' === $error->reason() ) { $values['selection_count'] = array( 'over_limit' => true ); }
					else { throw $error; }
				}
			}
			return array( 'status' => 'OK', 'form' => $values );
		} catch ( \Throwable $error ) {
			return array_merge( self::invalid( $error ), array( 'form' => $values ) );
		}
	}

	public static function build_operation( array $post ): Price_Operation {
		$type = $post['operation'] ?? null;
		$amount = $post['amount'] ?? null;
		$field = $post['price_field'] ?? Price_Operation::FIELD_REGULAR;
		if ( ! is_string( $field ) || ! in_array( $field, Price_Operation::FIELDS, true ) ) {
			throw new Price_Validation_Error( 'unsupported_price_field' );
		}
		if ( ! is_string( $type ) || ! in_array( $type, array( Price_Operation::SET, Price_Operation::INCREASE_FIXED, Price_Operation::DECREASE_FIXED, Price_Operation::INCREASE_PERCENT, Price_Operation::DECREASE_PERCENT ), true ) ) {
			throw new Price_Validation_Error( 'unsupported_operation' );
		}
		if ( ! is_string( $amount ) ) {
			throw new Price_Validation_Error( 'malformed_decimal' );
		}
		return new Price_Operation( $type, $amount, $field );
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
		$nonce = self::normalize_nonce( $post['_wpnonce'] ?? null );
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
		$nonce = self::normalize_nonce( $post['_wpnonce'] ?? null );
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
		$nonce = self::normalize_nonce( $post['_wpnonce'] ?? null );
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

	public static function page_url( string $view = '', ?string $public_id = null, int $offset = 0, string $filter = '' ): string {
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
		if ( '' !== $filter ) { $args['wl_filter'] = $filter; }
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
		$filter_raw = filter_input( INPUT_GET, 'wl_filter' );
		$filter = is_string( $filter_raw ) ? $filter_raw : '';
		$offset_raw = filter_input( INPUT_GET, 'wl_offset' );
		$offset = is_string( $offset_raw ) && preg_match( '/\A[0-9]{1,7}\z/', $offset_raw ) ? (int) $offset_raw : 0;
		echo '<div class="wrap writeleash-admin"><h1 class="writeleash-heading"><img src="' . esc_url( plugins_url( 'includes/free/admin-logo.png', WRITELEASH_PLUGIN_FILE ) ) . '" width="64" height="64" alt="" decoding="async"><span>WriteLeash Bulk Prices</span></h1>';
		$form = get_transient( self::form_key() );
		delete_transient( self::form_key() );
		$form = is_array( $form ) ? $form : array();
		if ( 'POST' === self::request_method() && null !== self::post_field( 'selection_action' ) ) {
			$result = self::process_selection( self::post_input(), 'POST' );
			$form = $result['form'] ?? array();
			if ( 'OK' !== $result['status'] ) { echo '<div class="notice notice-error" role="alert"><p>' . esc_html( self::reason_message( $result['reason'] ) ) . '</p></div>'; }
		}
		self::render_view( $view, $job_param, $offset, $form, $filter );
		echo '</div>';
	}

	/**
	 * Testable view dispatch, including the transient notice and the Woo
	 * dependency gate. render() only adds the wrap heading around it.
	 */
	public static function render_view( string $view, string $job_param, int $offset, array $form = array(), string $filter = '' ): void {
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
			self::render_job_view( $job_param, $offset, $filter );
		} elseif ( 'history' === $view ) {
			self::render_history_view( $offset );
		} else {
			self::render_home_view( $form );
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
		echo '<div class="notice ' . esc_attr( $class ) . '" role="alert"><p>' . esc_html( self::reason_message( $reason, $selected ) ) . '</p>';
		if ( isset( $notice['processed'] ) ) {
			echo '<p>' . esc_html( 'Processed ' . (int) $notice['processed'] . ' product(s) in this step. Check the results below for remaining work and anything needing attention.' ) . '</p>';
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
			return 'WriteLeash supports up to ' . Free_Support_Contract::MAX_JOB_PRODUCTS . ' products per job in the tested configuration. '
				. ( null === $selected ? '' : $selected . ' products were selected. ' )
				. 'Narrow the selection and build a new preview. No additional job or journal was created and no product was changed.';
		}
		if ( 'woocommerce_version_unsupported' === $reason ) {
			return 'WriteLeash supports ' . Free_Support_Contract::range_text() . ' for price mutations. Installed version: '
				. ( defined( 'WC_VERSION' ) ? (string) WC_VERSION : 'unavailable' ) . '. No job was created and no product was changed.';
		}
		if ( 'multisite_unsupported' === $reason ) {
			return 'WriteLeash does not support multisite. Use a single-site installation. No job was created and no product was changed.';
		}
		if ( 'db_transactions_unsupported' === $reason ) {
			return 'WriteLeash needs a standard transactional database connection (mysqli with InnoDB tables). No job was created and no product was changed.';
		}
		$messages = array(
			'invalid_price' => 'This product has an invalid stored price. Open it in WooCommerce, correct the price and save it before creating a new preview.',
			'capability_required' => 'You need WooCommerce product management capabilities for this action.',
			'post_required' => 'This action requires an authenticated POST request.',
			'invalid_nonce' => 'The security token is missing or invalid. Reload the page and try again.',
			'woocommerce_unavailable' => 'WooCommerce must be active and initialized before bulk-price planning. Install and activate WooCommerce, then reopen this page. No job was created and no product was changed.',
			'permission_denied' => 'You do not have permission to plan these product edits.',
			'permission_revoked' => 'The approved actor can no longer edit one or more products; nothing was executed.',
			'not_authorized' => 'Only the job creator, approver or an administrator may perform this action.',
			'invalid_selector' => 'Choose selected products, a named category or an advanced exact SKU/manual ID selection.',
			'invalid_discovery' => 'Use a shorter product or category search and start again from the first page.',
			'discovery_unavailable' => 'Search is unavailable. Try again, reload if your session expired, or use the native search controls.',
			'invalid_selection_size' => 'Choose between one and ' . Free_Support_Contract::MAX_JOB_PRODUCTS . ' products.',
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
			'preview_ready' => 'Preview ready. No prices have changed yet. Review these saved prices before approving.',
			'plan_policy_blocked' => 'Your safety limits block every changing product in this preview; it cannot be approved.',
			'already_approved' => 'This plan was already approved; no duplicate approval was recorded.',
			'job_material_mismatch' => 'This saved job no longer matches its approved preview; no product was changed. Start a new preview.',
			'job_terminal' => 'This job has already finished.',
			'job_not_resumable' => 'This job cannot be continued from its current state.',
			'job_not_approvable' => 'This job cannot be approved in its current state.',
			'approved' => 'Approved. WriteLeash will apply the reviewed prices in the background. Reload this page to check progress.',
			'approved_scheduler_unavailable' => 'Approved, but background processing could not start. This job is paused; use Resume remaining products to continue it.',
			'resume_chunk' => 'Results updated after this step. Check changes, remaining products and anything needing attention below.',
			'undo_chunk' => 'Undo results updated. Check restorations, remaining products and anything needing attention below.',
			'undo_terminal' => 'This Undo has already finished.',
			'undo_unavailable' => 'Undo is currently unavailable; no price was restored.',
			'worker_failed' => 'The background step could not run. Saved progress was kept; try Resume remaining products again.',
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
		return 'The outcome is unavailable. Check the saved results before taking further action.';
	}

	/** Merchant copy over existing states; unrecognized states never imply success. */
	public static function job_label( string $state ): string {
		$labels = array(
			'PLANNED' => 'Awaiting review', 'BLOCKED' => 'This plan cannot be executed.',
			'DRAFT' => 'Preparing preview', 'PLANNING' => 'Preparing preview',
			'READY' => 'Approved; waiting to start', 'QUEUED' => 'Waiting to continue',
			'RUNNING' => 'In progress', 'PAUSED' => 'Paused', 'COMPLETED' => 'Finished',
			'COMPLETED_WITH_ISSUES' => 'Finished with products needing attention',
			'NEEDS_REVIEW' => 'Outcome needs checking', 'CANCELLED' => 'Stopped by an operator',
		);
		return $labels[ $state ] ?? 'Outcome unavailable; needs checking';
	}

	public static function item_label( ?string $state, string $job_state ): string {
		if ( null === $state ) { return 'Not started'; }
		if ( in_array( $state, array( 'PENDING', 'CHANGING' ), true ) ) {
			if ( 'BLOCKED' === $job_state ) { return 'Will not run'; }
			if ( 'PLANNED' === $job_state ) { return 'Planned change; awaiting review'; }
			if ( Job_State::is_terminal( $job_state ) ) { return 'Not processed'; }
			return 'Remaining';
		}
		$labels = array(
			'APPLIED' => 'Changed', 'UNCHANGED' => 'Already at the target price',
			'UNSUPPORTED' => 'Skipped at preview', 'BLOCKED' => 'Will not run',
			'APPLYING' => 'In progress; outcome not yet confirmed', 'CONFLICT' => 'Not changed',
			'FAILED' => 'Needs attention; not changed', 'NEEDS_REVIEW' => 'Outcome uncertain; needs checking',
			'UNDO_PENDING' => 'Awaiting restoration', 'UNDO_APPLYING' => 'Restoration in progress; outcome not yet confirmed',
			'UNDONE' => 'Restored', 'UNDO_CONFLICT' => 'Not restored',
			'UNDO_FAILED' => 'Restoration needs checking', 'UNDO_NEEDS_REVIEW' => 'Restoration uncertain; needs checking',
		);
		return $labels[ $state ] ?? 'Outcome unavailable; needs checking';
	}

	public static function result_summary( array $counts, string $state, bool $compact = false ): string {
		$pending = (int) $counts['pending'];
		$review = (int) $counts['needs_review'];
		$parts = array(
			(int) $counts['applied'] . ' changed', (int) $counts['unchanged'] . ' already at the target price',
			(int) $counts['unsupported'] . ' skipped', (int) $counts['conflict'] . ' conflict' . ( 1 === (int) $counts['conflict'] ? '' : 's' ),
			$pending . ( 'BLOCKED' === $state ? ' will not run' : ( 'PLANNED' === $state ? ' awaiting approval' : ( Job_State::is_terminal( $state ) ? ' not processed' : ' remaining' ) ) ),
			(int) $counts['applying'] . ' in progress', (int) $counts['failed'] . ' needing attention',
			$review . ( 1 === $review ? ' needs checking' : ' need checking' ),
		);
		if ( $compact ) { $parts = array_values( array_filter( $parts, static fn( $part ) => ! str_starts_with( $part, '0 ' ) ) ); }
		return 'Planned ' . (int) $counts['planned'] . ( 1 === (int) $counts['planned'] ? ' product: ' : ' products: ' ) . implode( ' · ', $parts ) . '.';
	}

	private static function support_details( string $text ): void {
		echo '<details><summary>Support details</summary><p>' . esc_html( $text ) . '</p></details>';
	}

	public static function task_description( array $plan ): string {
		$op = $plan['operation'];
		$field = $op['field'] ?? Price_Operation::FIELD_REGULAR;
		$label = Price_Operation::FIELD_SALE === $field ? 'sale prices' : 'regular prices';
		$verbs = array( 'SET' => 'Set ' . $label . ' to ', 'INCREASE_FIXED' => 'Increase ' . $label . ' by ', 'DECREASE_FIXED' => 'Decrease ' . $label . ' by ', 'INCREASE_PERCENT' => 'Increase ' . $label . ' by ', 'DECREASE_PERCENT' => 'Decrease ' . $label . ' by ' );
		$type = $op['type'];
		$total = count( $plan['items'] );
		$variations = 0;
		foreach ( $plan['items'] as $item ) {
			if ( ! empty( $item['snapshot']['core_variation'] ) ) { ++$variations; }
		}
		if ( $variations === $total ) {
			$count = $total . ( 1 === $total ? ' variation' : ' variations' );
		} elseif ( $variations > 0 ) {
			$count = $total . ( 1 === $total ? ' product' : ' products' ) . ' (' . $variations . ( 1 === $variations ? ' variation' : ' variations' ) . ')';
		} else {
			$count = $total . ( 1 === $total ? ' product' : ' products' );
		}
		return ( $verbs[ $type ] ?? 'Price operation: ' )
			. ( in_array( $type, array( 'INCREASE_PERCENT', 'DECREASE_PERCENT' ), true ) ? self::percentage_display( $op['input'] ) : self::money_display( $op['input'], $plan['store'] ) )
			. ' · ' . $count;
	}

	/** Display only: string arithmetic keeps exact decimals out of floating point. */
	public static function money_display( $value, array $store, bool $signed = false ): string {
		$currency = $store['currency'] ?? null;
		$precision = $store['price_decimals'] ?? null;
		if ( ! is_string( $value ) || ! preg_match( '/\A(-?)([0-9]+)(?:\.([0-9]{1,6}))?\z/D', $value, $parts )
			|| ! is_string( $currency ) || ! preg_match( '/\A[A-Z]{3}\z/D', $currency ) || ! is_int( $precision ) || $precision < 0 || $precision > 6 ) { return 'Unavailable'; }
		$fraction = rtrim( $parts[3] ?? '', '0' );
		$units = Price_Decimal::natural( $parts[2] . str_pad( $parts[3] ?? '', 6, '0' ) );
		$rounded = Price_Decimal::format( Price_Decimal::divide_round( $units, '1' . str_repeat( '0', 6 - $precision ) ), $precision );
		$number = explode( '.', $rounded );
		$number[0] = preg_replace( '/\B(?=(?:[0-9]{3})+(?![0-9]))/', wc_get_price_thousand_separator(), $number[0] );
		$amount = $number[0] . ( $precision ? wc_get_price_decimal_separator() . $number[1] : '' );
		$sign = '0' === $units ? '' : ( '-' === $parts[1] ? '-' : ( $signed ? '+' : '' ) );
		$symbol = html_entity_decode( get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' );
		$format = str_replace( '&nbsp;', ' ', get_woocommerce_price_format() );
		$text = $sign . sprintf( $format, $symbol, $amount ) . ' ' . $currency;
		// A usual minor-unit display must never conceal a stored difference.
		if ( strlen( $fraction ) > $precision ) {
			$text .= ' (exact: ' . $sign . Price_Decimal::natural( $parts[2] ) . '.' . $fraction . ' ' . $currency . ')';
		}
		return $text;
	}

	/** The frozen six-place ratio is unchanged; only its Admin label is rounded. */
	public static function percentage_display( $value, ?string $numerator = null ): string {
		if ( ! is_string( $value ) || ! preg_match( '/\A(-?)([0-9]+)(?:\.([0-9]{1,6}))?\z/D', $value, $parts ) ) { return 'Unavailable'; }
		$units = Price_Decimal::natural( $parts[2] . str_pad( $parts[3] ?? '', 6, '0' ) );
		$nonzero = '0' !== $units || ( null !== $numerator && '0' !== Price_Decimal::natural( ltrim( $numerator, '-' ) ) );
		$negative = '-' === $parts[1] || ( null !== $numerator && str_starts_with( $numerator, '-' ) );
		if ( $nonzero && Price_Decimal::compare( $units, '10000' ) < 0 ) { return ( null === $numerator ? '' : ( $negative ? 'Decrease ' : 'Increase ' ) ) . '<0.01%'; }
		$rounded = Price_Decimal::divide_round( $units, '10000' );
		$approximate = Price_Decimal::compare( Price_Decimal::multiply( $rounded, '10000' ), $units ) !== 0;
		return ( $approximate ? '≈ ' : '' ) . ( $negative && $nonzero ? '-' : '' ) . str_replace( '.', wc_get_price_decimal_separator(), Price_Decimal::format( $rounded, 2, true ) ) . '%';
	}

	private static function operator_name( int $id ): string {
		if ( $id < 1 ) { return 'Not yet approved'; }
		$user = get_userdata( $id );
		return $user ? $user->display_name : 'Deleted user (User #' . $id . ')';
	}

	public static function site_time( ?string $utc ): string {
		if ( ! $utc ) { return 'Unavailable'; }
		$timestamp = strtotime( $utc . ' +00:00' );
		return false === $timestamp ? 'Unavailable' : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp, wp_timezone() ) . ' (' . wp_timezone_string() . ')';
	}

	/** Variation rows read "Product name — Blue / M" with SKU/ID secondary. */
	private static function identity_name( array $identity ): string {
		$name = (string) ( $identity['variation_label'] ?? '' );
		if ( '' === $name ) { $name = (string) ( $identity['name'] ?? '' ); }
		return $name;
	}

	private static function render_identity( array $frozen ): void {
		$id = (int) $frozen['product_id'];
		$identity = $frozen['snapshot'] ?? $frozen;
		$name = self::identity_name( $identity );
		$variation = ! empty( $identity['core_variation'] );
		echo '<strong>' . esc_html( '' === $name ? 'Name unavailable at preview' : $name ) . '</strong><br><span class="description">';
		$sku = (string) ( $identity['sku'] ?? '' );
		echo esc_html( ( '' === $sku ? '' : 'SKU: ' . $sku . ' · ' ) . ( $variation ? 'Variation' : 'Product' ) . ' #' . $id . ' · as reviewed' ) . '</span>';
		// No current-name fallback. A native edit link requires a current Woo post and per-product permission.
		$post = get_post( $id );
		if ( $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) && current_user_can( 'edit_post', $id ) ) {
			$link = get_edit_post_link( $id, 'raw' );
			if ( $link ) { echo '<br><a href="' . esc_url( $link ) . '">Review product</a>'; }
		}
	}

	private static function expected_display( array $frozen, array $item, string $field ): string {
		$meta = Price_Operation::meta_key( $field );
		return (string) ( $frozen['snapshot'][ $meta ] ?? $frozen['stored_price'] ?? $item['expected_price'] ?? 'Unavailable' );
	}

	/**
	 * Preview-only shopper price implied by the frozen snapshot plus the
	 * planned value of the plan's field. Display helper; never stored.
	 */
	public static function effective_shopper_price( array $item, string $field, int $now = 0 ): string {
		$snapshot = is_array( $item['snapshot'] ?? null ) ? $item['snapshot'] : array();
		$regular = is_string( $snapshot['regular_price'] ?? null ) ? $snapshot['regular_price'] : '';
		$sale = is_string( $snapshot['sale_price'] ?? null ) ? $snapshot['sale_price'] : '';
		$planned = is_string( $item['planned_regular_price'] ?? null ) ? $item['planned_regular_price'] : null;
		if ( Price_Operation::FIELD_SALE === $field && null !== $planned ) { $sale = $planned; }
		if ( Price_Operation::FIELD_REGULAR === $field && null !== $planned ) { $regular = $planned; }
		if ( '' === $regular ) { return 'Unavailable'; }
		$active = $regular;
		if ( '' !== $sale ) {
			try {
				if ( Price_Decimal::compare( Price_Decimal::units( Price_Decimal::parse( $regular ) ), Price_Decimal::units( Price_Decimal::parse( $sale ) ) ) > 0 ) {
					$active = $sale;
					$from = $snapshot['sale_from'] ?? null;
					$to = $snapshot['sale_to'] ?? null;
					$now = $now > 0 ? $now : time();
					if ( ( is_string( $from ) && (int) $from > $now ) || ( is_string( $to ) && (int) $to < $now ) ) { $active = $regular; }
				}
			} catch ( Price_Validation_Error $error ) { return 'Unavailable'; }
		}
		return $active;
	}

	private static function undo_item_label( array $item, string $job_state ): string {
		return null === $item['undo_state'] && 'APPLIED' !== $item['apply_state'] ? 'Unavailable: no confirmed WriteLeash change to restore' : self::item_label( $item['undo_state'], $job_state );
	}

	/** Single source for conflict guidance: the server table and polled rows must not diverge. */
	private static function outcome_conflict_copy( ?string $state ): string {
		if ( 'CONFLICT' === $state ) { return 'This product’s price or other conditions changed after you reviewed the preview. WriteLeash left the newer value unchanged.'; }
		if ( 'UNDO_CONFLICT' === $state ) { return 'This product changed after WriteLeash applied its price. WriteLeash preserved the newer value instead of restoring over it.'; }
		return '';
	}

	/** Polled outcome cells reuse the server cell renderer byte-for-byte. */
	private static function outcome_cell_html( array $item, array $frozen, $reason, string $job_state, array $observation, bool $apply_side ): string {
		if ( $apply_side ) {
			ob_start();
			self::render_item_outcome( $item['apply_state'], $reason, $job_state, $observation );
			return (string) ob_get_clean();
		}
		if ( null === $item['undo_state'] ) {
			return esc_html( self::undo_item_label( $item, $job_state ) );
		}
		ob_start();
		self::render_item_outcome( $item['undo_state'], $reason, $job_state, $observation );
		return (string) ob_get_clean();
	}

	private static function render_item_outcome( ?string $state, ?string $reason, string $job_state, array $observation ): void {
		echo '<strong>' . esc_html( self::item_label( $state, $job_state ) ) . '</strong>';
		if ( 'CONFLICT' === $state || 'UNDO_CONFLICT' === $state ) {
			$copy = self::outcome_conflict_copy( $state );
			echo '<p>' . esc_html( $copy ) . '</p>';
			if ( '' !== $observation['context'] ) { echo '<p>' . esc_html( $observation['context'] ) . '</p>'; }
			if ( 'UNDO_CONFLICT' === $state && in_array( $reason, array( 'PRODUCT_MISSING', 'PRODUCT_TYPE_CHANGED', 'PRODUCT_STATUS_CHANGED', 'SALE_CONFIGURED' ), true ) ) {
				echo '<p>' . esc_html( self::reason_message( $reason ) ) . '</p>';
			}
			echo '<p>Review the product, then <a href="' . esc_url( self::page_url() ) . '">Create a new preview</a> if you still want to change it.</p>';
		} elseif ( in_array( $state, array( 'NEEDS_REVIEW', 'UNKNOWN', 'UNDO_NEEDS_REVIEW', 'FAILED', 'UNDO_FAILED' ), true ) ) {
			echo '<p>Check the product and saved results before taking further action. This outcome is not confirmed as unchanged.</p>';
			// Known apply-time refusals get their exact merchant recovery copy;
			// unknown reasons never invent an explanation.
			if ( null !== $reason && in_array( $reason, array( 'LOOKUP_MISMATCH', 'UNSUPPORTED_PRODUCT_STATE' ), true ) ) {
				echo '<p>' . esc_html( self::reason_message( $reason ) ) . '</p>';
			}
		} elseif ( null !== $reason && in_array( $state, array( 'UNSUPPORTED', 'UNCHANGED' ), true ) ) {
			echo '<p>' . esc_html( self::reason_message( $reason ) ) . '</p>';
		}
		if ( null !== $state ) { self::support_details( $state . ' · ' . (string) $reason ); }
	}

	public static function undo_availability( array $job, array $history ): string {
		$undo = $history['undo'];
		$status = $undo['operation_status'];
		if ( Undo_State::COMPLETED === $status ) { return 'Undo finished: eligible prices restored.'; }
		if ( Undo_State::COMPLETED_WITH_ISSUES === $status ) { return 'Undo finished with conflicts or failed restorations. Review the affected products.'; }
		if ( Undo_State::CANCELLED === $status ) { return 'Undo stopped by an operator. Already restored prices remain restored.'; }
		if ( $history['undo_expires_at'] && $history['undo_expires_at'] <= gmdate( 'Y-m-d H:i:s' ) ) { return 'Undo expired: the restoration window has ended.'; }
		if ( 'BLOCKED' === $job['status'] ) { return 'Undo unavailable: this plan cannot be executed.'; }
		if ( 'PLANNED' === $job['status'] ) { return 'Undo unavailable: this plan is awaiting review and has not run.'; }
		if ( ! in_array( $job['status'], array( Job_State::COMPLETED, Job_State::COMPLETED_WITH_ISSUES ), true ) ) { return 'Undo unavailable: Apply has not safely finished. Check or finish the remaining work first.'; }
		if ( (int) $job['applied'] < 1 ) { return 'Undo unavailable: no products were changed by this job.'; }
		if ( Undo_State::NEEDS_REVIEW === $status ) { return 'Undo needs checking: a restoration outcome is uncertain. Continuing will not retry uncertain or conflicted products.'; }
		if ( Undo_State::PAUSED === $status ) { return 'Undo paused. Continue Undo for remaining products.'; }
		if ( in_array( $status, array( Undo_State::PENDING, Undo_State::RUNNING ), true ) ) { return 'Undo in progress. Reload to check results or continue remaining products.'; }
		if ( null !== $status ) { return 'Undo outcome unavailable; needs checking.'; }
		return $history['undo_eligible'] ? 'Undo available for prices changed by this job.' : 'Undo unavailable: saved restoration evidence is unavailable.';
	}

	private static function render_history_table( array $entries ): void {
		echo '<div class="writeleash-table-scroll" role="region" aria-label="Job history" tabindex="0"><table class="widefat striped writeleash-history"><thead><tr><th scope="col">Task / operator / time</th><th scope="col">Outcome</th><th scope="col">Undo</th><th scope="col">Action</th></tr></thead><tbody>';
		if ( ! $entries ) { echo '<tr><td colspan="4">No jobs visible to your account yet. Create a new preview to start.</td></tr>'; }
		foreach ( $entries as $entry ) {
			$job = Job_Repository::read( (int) $entry['job_id'] );
			if ( ! $job || ! self::authorized_for_job( $job, get_current_user_id() ) ) { continue; }
			try { $description = self::task_description( Job_Repository::hydrate_plan( $job )->data() ); }
			catch ( \Throwable $error ) { $description = 'Saved task details unavailable; needs checking'; }
			echo '<tr><td><strong>' . esc_html( $description ) . '</strong><br>';
			echo esc_html( 'Created by ' . self::operator_name( (int) $job['creator_id'] ) );
			if ( (int) $job['approver_id'] > 0 ) { echo '<br>' . esc_html( 'Approved by ' . self::operator_name( (int) $job['approver_id'] ) ); }
			echo '<br><span class="description">' . esc_html( self::site_time( $job['created_at'] ) ) . '</span>';
			self::support_details( $job['public_id'] ); echo '</td>';
			echo '<td><strong>' . esc_html( self::job_label( $job['status'] ) ) . '</strong><br>' . esc_html( self::result_summary( $entry['apply'], $job['status'], true ) ) . '</td>';
			echo '<td>' . esc_html( self::undo_availability( $job, $entry ) );
			if ( $entry['undo_eligible'] ) { echo '<br>' . esc_html( 'Until ' . self::site_time( $entry['undo_expires_at'] ) ); }
			echo '</td><td>'; self::job_action_link( $job ); self::render_export_form( $job ); echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_export_form( array $job ): void {
		if ( ! self::can_mutate() ) { return; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_EXPORT ) . '"><input type="hidden" name="job" value="' . esc_attr( $job['public_id'] ) . '">';
		wp_nonce_field( self::ACTION_EXPORT . '_' . $job['public_id'] );
		echo '<p><button type="submit" class="button button-link">Download job CSV</button></p></form>';
	}

	/** Read-only export gate. No schema installation, selector or live catalog read. */
	public static function export_job( array $post, string $method ): array {
		if ( ! self::can_mutate() ) { return array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ); }
		if ( 'POST' !== $method ) { return array( 'status' => 'INVALID', 'reason' => 'post_required' ); }
		if ( ! self::jobs_installed() ) { return array( 'status' => 'INVALID', 'reason' => 'invalid_job' ); }
		$job = self::job_from_post( $post );
		if ( ! $job || ! self::authorized_for_job( $job, get_current_user_id() ) ) { return array( 'status' => 'FORBIDDEN', 'reason' => 'not_authorized' ); }
		$nonce = self::normalize_nonce( $post['_wpnonce'] ?? null );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::ACTION_EXPORT . '_' . $job['public_id'] ) ) { return array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ); }
		try { $plan = Job_Repository::hydrate_plan( $job ); }
		catch ( \Throwable $error ) { return array( 'status' => 'INVALID', 'reason' => 'job_material_mismatch' ); }
		return array( 'status' => 'OK', 'job' => $job, 'plan' => $plan );
	}

	private static function csv_cell( $value ): string {
		$text = null === $value ? '' : (string) $value;
		// Include whitespace/control-prefixed formulas and leading tab/newline cells.
		return preg_match( '/\A(?:[\x09\x0a\x0d]|[\s\x00-\x20]*[=+@-])/u', $text ) ? "'" . $text : $text;
	}

	/** Stream bounded retained evidence pages; money strings are never parsed or cast. */
	public static function write_job_csv( $stream, array $job, Change_Plan $plan ): void {
		$identities = array_column( $plan->data()['items'], null, 'product_id' );
		$exported = gmdate( 'Y-m-d H:i:s' );
		$header = array( 'job', 'task', 'price_field', 'product_name_at_preview', 'sku_at_preview', 'product_id', 'regular_price_at_preview', 'expected_price', 'planned_price', 'currency', 'apply_outcome', 'apply_state', 'apply_reason', 'applied_at_utc', 'undo_outcome', 'undo_state', 'undo_reason', 'undone_at_utc', 'creator', 'creator_id', 'approver', 'approver_id', 'created_at_utc', 'approved_at_utc', 'undo_operator', 'undo_operator_id', 'site_timezone', 'exported_at_utc' );
		fputcsv( $stream, $header, ',', '"', '' );
		$history = Undo_Repository::history_job( (int) $job['id'] );
		$undo_actor = (int) ( $history['undo']['initiator_id'] ?? 0 );
		$plan_field = $plan->price_field();
		for ( $offset = 0; $offset < Undo_Repository::MAX_EVIDENCE_ROWS; $offset += Undo_Repository::PAGE_LIMIT ) {
			$page = Undo_Repository::history_items( (int) $job['id'], null, null, $offset, Undo_Repository::PAGE_LIMIT );
			if ( $page['total'] !== count( $identities ) || $page['total'] > Undo_Repository::MAX_EVIDENCE_ROWS ) { throw new \RuntimeException( 'Saved item evidence unavailable' ); }
			foreach ( $page['items'] as $item ) {
				$frozen = $identities[ $item['product_id'] ] ?? null;
				if ( null === $frozen ) { throw new \RuntimeException( 'Saved identity unavailable' ); }
				$row = array(
					$job['public_id'], self::task_description( $plan->data() ), Price_Operation::label( $plan_field ),
					self::identity_name( $frozen['snapshot'] ), $frozen['snapshot']['sku'], $item['product_id'],
					$frozen['snapshot']['regular_price'], $item['expected_price'], $item['planned_price'], $job['currency'],
					self::item_label( $item['apply_state'], $job['status'] ), $item['apply_state'],
					'UNSUPPORTED' === $item['apply_state'] ? ( $frozen['eligibility']['reason'] ?? $item['apply_reason'] ) : $item['apply_reason'],
					$item['applied_at'], self::undo_item_label( $item, $job['status'] ),
					$item['undo_state'], $item['undo_reason'], $item['undone_at'],
					self::operator_name( (int) $job['creator_id'] ), $job['creator_id'],
					self::operator_name( (int) $job['approver_id'] ), $job['approver_id'], $job['created_at'], $job['approved_at'],
					$undo_actor > 0 ? self::operator_name( $undo_actor ) : '', $undo_actor ?: '', wp_timezone_string(), $exported,
				);
				if ( false === fputcsv( $stream, array_map( array( __CLASS__, 'csv_cell' ), $row ), ',', '"', '' ) ) { throw new \RuntimeException( 'CSV unavailable' ); }
			}
			if ( null === $page['next_offset'] ) { return; }
		}
		throw new \RuntimeException( 'CSV evidence limit exceeded' );
	}

	public static function handle_export(): void {
		$result = self::export_job( self::post_input(), self::request_method() );
		if ( 'OK' !== $result['status'] ) { wp_die( esc_html( self::reason_message( $result['reason'] ) ), '', array( 'response' => 'FORBIDDEN' === $result['status'] ? 403 : 400 ) ); }
		$stream = fopen( 'php://temp/maxmemory:1048576', 'w+' );
		try {
			if ( false === $stream ) { throw new \RuntimeException( 'CSV unavailable' ); }
			self::write_job_csv( $stream, $result['job'], $result['plan'] );
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming the generated CSV response; WP_Filesystem is not an output stream.
			if ( is_resource( $stream ) ) { fclose( $stream ); }
			wp_die( esc_html( 'Saved export evidence is unavailable. No products were changed.' ), '', array( 'response' => 409 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="writeleash-' . $result['job']['public_id'] . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		rewind( $stream );
		fpassthru( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming the generated CSV response; WP_Filesystem is not an output stream.
		fclose( $stream );
		exit;
	}

	private static function render_home_view( array $form = array() ): void {
		echo '<p>';
		echo esc_html( 'Preview bulk regular or sale prices before they happen. Run them safely. If the price or a checked setting changed after review, WriteLeash leaves the newer value alone. Undo changes that are still safe to restore.' );
		echo '</p>';
		echo '<p>';
		echo esc_html( 'Change the stored price of published simple products and variations of published variable products in the base store currency. Variations can be selected as a whole product or individually. Sale dates, stock, orders and subscriptions are not changed.' );
		echo '</p>';
		echo '<p>';
		echo esc_html( 'Up to ' . Free_Support_Contract::MAX_JOB_PRODUCTS . ' selected products per new job in the tested configuration. Planned prices are not guaranteed shopper prices: taxes, currency settings, dynamic pricing and later edits still apply. You can close this page at any time; saved progress appears again when you return.' );
		echo '</p>';
		if ( ! self::can_mutate() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( 'Your role can view this page but cannot preview or apply bulk price changes.' ) . '</p></div>';
		}
		echo '<h2>New price change</h2>';
		self::render_selector_form( $form );
		echo '<h2>Recent jobs</h2>';
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

	private static function selection_button( string $action, string $label, string $accessible_label = '' ): void {
		echo '<button class="button" type="submit" name="selection_action" value="' . esc_attr( $action ) . '" formaction="' . esc_url( self::page_url() ) . '"' . ( '' !== $accessible_label ? ' aria-label="' . esc_attr( $accessible_label ) . '"' : '' ) . ' formnovalidate>' . esc_html( $label ) . '</button> ';
	}

	private static function render_selector_form( array $values = array() ): void {
		if ( ! self::can_mutate() ) { return; }
		$defaults = array( 'selector' => 'ids', 'ids' => '', 'sku' => '', 'category' => '', 'include_subcategories' => '', 'operation' => Price_Operation::SET, 'price_field' => Price_Operation::FIELD_REGULAR, 'amount' => '', 'max_products' => (string) Free_Support_Contract::MAX_JOB_PRODUCTS, 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '20', 'product_search' => '', 'category_search' => '', 'product_page' => '1', 'category_page' => '1' );
		$values = array_merge( $defaults, $values );
		$selected = array(); $matches = array( 'results' => array(), 'more' => false ); $categories = $matches;
		try {
			$selected = Product_Discovery::selected( self::picker_ids( $values ) );
			$matches = Product_Discovery::products( $values['product_search'], (int) $values['product_page'] );
			$categories = Product_Discovery::categories( $values['category_search'], (int) $values['category_page'] );
		} catch ( \Throwable $error ) {
			echo '<div class="notice notice-warning" role="alert"><p>' . esc_html( self::reason_message( $error instanceof Price_Validation_Error ? $error->reason() : 'discovery_unavailable' ) ) . '</p></div>';
		}
		echo '<form id="writeleash-free-selection-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-discovery-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-discovery-action="' . esc_attr( Product_Discovery::ACTION ) . '" data-discovery-nonce="' . esc_attr( wp_create_nonce( Product_Discovery::ACTION ) ) . '" data-max-selection="' . esc_attr( (string) Free_Support_Contract::MAX_JOB_PRODUCTS ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_PREVIEW ) . '"><input type="hidden" name="picker_present" value="1">';
		wp_nonce_field( self::ACTION_PREVIEW );
		echo '<input type="hidden" name="discovery_nonce" value="' . esc_attr( wp_create_nonce( Product_Discovery::ACTION ) ) . '">';
		echo '<fieldset><legend>' . esc_html( '1. Select products' ) . '</legend>';
		echo '<p id="writeleash-free-selector-help">' . esc_html( 'Choose specific products or one category. Categories include direct members only by default; subcategories are not included unless you enable Include subcategories. Search matches are not automatically selected and do not guarantee eligibility. Preview checks every selected product. Maximum ' . Free_Support_Contract::MAX_JOB_PRODUCTS . ' selected products.' ) . '</p>';
		echo '<p><label for="writeleash-free-selector">Selection method</label><br><select id="writeleash-free-selector" name="selector" aria-describedby="writeleash-free-selector-help">';
		foreach ( array( 'ids' => 'Choose products by name or SKU', 'category' => 'Product category', 'sku' => 'One exact SKU', 'manual_ids' => 'Advanced: manual product IDs' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $values['selector'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p id="writeleash-free-discovery-status" role="status" aria-live="polite">Search and choose products. If live suggestions are unavailable, use the search without live suggestions.</p>';
		echo '<div id="writeleash-free-product-picker">';
		echo '<details id="writeleash-free-products-fallback" open><summary>Search products without live suggestions</summary>';
		self::selector_field( 'product_search', 'Product name or partial SKU', 'search', $values['product_search'], 'writeleash-free-selector-help' );
		echo '<input type="hidden" name="product_page" value="' . esc_attr( $values['product_page'] ) . '">';
		self::selection_button( 'search-products', 'Search products' );
		if ( $matches['more'] ) { self::selection_button( 'next-products', 'More product matches' ); }
		if ( '' !== $values['product_search'] && ! $matches['results'] ) { echo '<p>No matches on this page. Try another product name or SKU.</p>'; }
		if ( ! empty( $matches['capped'] ) ) { echo '<p>Search limit reached. Use a more specific name or SKU.</p>'; }
		echo '<p>Use Ctrl/Command to select several matches below, then Update selected products. Selected products stay on subsequent search pages.</p>';
		self::selection_button( 'update-products', 'Update selected products' );
		echo '</details>';
		echo '<p><label for="writeleash-free-products">Choose products</label><br><select id="writeleash-free-products" name="product_ids[]" multiple size="6" style="width:100%;max-width:600px" aria-describedby="writeleash-free-products-help">';
		$options = $selected;
		foreach ( $matches['results'] as $item ) { $options[(int) $item['id']] = $item; }
		foreach ( $options as $id => $item ) { echo '<option value="' . esc_attr( (string) $id ) . '"' . ( isset( $selected[$id] ) ? ' selected' : '' ) . '>' . esc_html( $item['text'] ) . '</option>'; }
		echo '</select></p><p id="writeleash-free-products-help">Check the selected products below. Preview checks their eligibility before any prices change.</p>';
		echo '<h3>Selected products</h3><ul id="writeleash-free-selected">';
		foreach ( $selected as $id => $item ) { echo '<li>' . esc_html( $item['text'] ) . ' '; self::selection_button( 'remove:' . $id, 'Remove', 'Remove ' . $item['text'] ); echo '</li>'; }
		if ( ! $selected ) { echo '<li>No products selected. Search and choose products to add them.</li>'; }
		echo '</ul><button id="writeleash-free-clear" class="button" type="submit" name="selection_action" value="clear-products" formaction="' . esc_url( self::page_url() ) . '" formnovalidate>Clear selected products</button>';
		echo '</div><div id="writeleash-free-category-picker"><details id="writeleash-free-categories-fallback" open><summary>Search categories without live suggestions</summary>';
		self::selector_field( 'category_search', 'Category name', 'search', $values['category_search'], 'writeleash-free-selector-help' );
		echo '<input type="hidden" name="category_page" value="' . esc_attr( $values['category_page'] ) . '">';
		self::selection_button( 'search-categories', 'Search categories' );
		if ( $categories['more'] ) { self::selection_button( 'next-categories', 'More category matches' ); }
		if ( ! empty( $categories['capped'] ) ) { echo '<p>Search limit reached. Use a more specific category name.</p>'; }
		if ( ! $categories['results'] ) { echo '<p>No categories match this page. Try another category name.</p>'; }
		echo '</details>';
		echo '<p><label for="writeleash-free-category">Product category</label><br><select id="writeleash-free-category" name="category" style="width:100%;max-width:600px" aria-describedby="writeleash-free-selector-help"><option value="">Choose a category</option>';
		$category_options = array();
		foreach ( $categories['results'] as $item ) { $category_options[$item['id']] = $item; }
		if ( preg_match( '/\A[0-9]{1,10}\z/', $values['category'] ) ) {
			try { $item = Product_Discovery::category( (int) $values['category'] ); if ( $item ) { $category_options[$item['id']] = $item; } } catch ( \Throwable $error ) { /* Dependency/permission notice above; no guessed label. */ }
		}
		foreach ( $category_options as $id => $item ) { echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $values['category'], (string) $id, false ) . '>' . esc_html( $item['text'] ) . '</option>'; }
		echo '</select></p><p><input id="writeleash-free-include-subcategories" name="include_subcategories" type="checkbox" value="1"' . checked( $values['include_subcategories'], '1', false ) . '> <label for="writeleash-free-include-subcategories">Include subcategories</label></p><p>When enabled, products in all nested subcategories are included once. Variable parents expand to their variations.</p></div>';
		echo '<p>'; self::selection_button( 'count-targets', 'Check selection count' ); echo '</p><p id="writeleash-free-selection-count" role="status" aria-live="polite">';
		$count = $values['selection_count'] ?? null;
		if ( is_array( $count ) && ! empty( $count['over_limit'] ) ) {
			echo esc_html( 'This selection exceeds the supported limit of ' . Free_Support_Contract::MAX_JOB_PRODUCTS . ' price targets. Choose a smaller selection before Preview.' );
		} elseif ( is_array( $count ) && isset( $count['selected'], $count['unreadable'], $count['missing'] ) ) {
			echo esc_html( 'At last check: ' . $count['selected'] . ' deduplicated price targets after variation expansion. ' . ( 0 === $count['selected'] ? 'No products match this selection. Choose products or another category. ' : '' ) . $count['unreadable'] . ' unreadable; ' . $count['missing'] . ' missing. Preview explains skipped or unsupported products.' );
		} else { echo esc_html( 'Check the number of price targets before Preview, including variations.' ); }
		echo '</p><p>Counts are informational and can change. Preview resolves the selection again and freezes the exact products for your review; no count authorizes a price change.</p>';
		echo '<details id="writeleash-free-advanced-selection"' . ( in_array( $values['selector'], array( 'sku', 'manual_ids' ), true ) ? ' open' : '' ) . '><summary>Exact SKU or manual product IDs</summary><p>Enter one complete SKU or comma-separated product IDs for the selection method chosen above.</p>';
		self::selector_field( 'ids', 'Explicit product IDs (advanced), e.g. 12,34,56', 'text', $values['ids'], 'writeleash-free-selector-help' );
		self::selector_field( 'sku', 'One exact SKU', 'text', $values['sku'], 'writeleash-free-selector-help' );
		echo '</details></fieldset><fieldset><legend>2. Choose the price change</legend><p><label for="writeleash-free-price-field">Price to change</label><br><select id="writeleash-free-price-field" name="price_field" aria-describedby="writeleash-free-price-field-help">';
		foreach ( array( Price_Operation::FIELD_REGULAR => 'Regular price', Price_Operation::FIELD_SALE => 'Sale price' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $values['price_field'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p id="writeleash-free-price-field-help">' . esc_html( 'Regular price is the everyday price. Sale price is the discounted price during a sale. WriteLeash changes only the price you choose and keeps the other one.' ) . '</p><p><label for="writeleash-free-operation">Operation</label><br><select id="writeleash-free-operation" name="operation">';
		foreach ( array( Price_Operation::SET => 'Set an exact price', Price_Operation::INCREASE_FIXED => 'Increase by fixed amount', Price_Operation::DECREASE_FIXED => 'Decrease by fixed amount', Price_Operation::INCREASE_PERCENT => 'Increase by percent', Price_Operation::DECREASE_PERCENT => 'Decrease by percent' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $values['operation'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		$amount_label = Price_Operation::SET === $values['operation'] ? 'New price' : ( in_array( $values['operation'], array( Price_Operation::INCREASE_PERCENT, Price_Operation::DECREASE_PERCENT ), true ) ? 'Percentage (e.g. 8 for 8%)' : ( Price_Operation::INCREASE_FIXED === $values['operation'] ? 'Amount to increase by' : 'Amount to decrease by' ) );
		self::selector_field( 'amount', $amount_label, 'text', $values['amount'], 'writeleash-free-amount-help', true );
		echo '<p id="writeleash-free-amount-help" class="description">Enter a positive number or zero. Use a dot for decimals; leave out currency symbols and the % sign.</p>';
		$custom_limits = array_diff_assoc( array_intersect_key( $values, array_flip( array( 'max_products', 'max_increase', 'max_decrease', 'warning_threshold' ) ) ), $defaults ) || ! empty( $values['block_zero'] );
		echo '</fieldset><details id="writeleash-free-safety-limits"' . ( $custom_limits ? ' open' : '' ) . '><summary>Safety limits (optional)</summary><fieldset class="writeleash-safety"><legend>Safety limits</legend><p class="writeleash-full-width">A change beyond these limits blocks the whole preview.</p>';
		$maximum = Free_Support_Contract::MAX_JOB_PRODUCTS;
		self::selector_field( 'max_products', 'Maximum changing products (0-' . $maximum . ')', 'number', $values['max_products'], 'writeleash-free-operation', true, 0, $maximum );
		foreach ( array( 'max_increase' => 'Maximum increase percent', 'max_decrease' => 'Maximum decrease percent', 'warning_threshold' => 'Warning threshold percent' ) as $name => $label ) { self::selector_field( $name, $label, 'text', $values[$name], 'writeleash-free-operation', true ); }
		echo '<p class="writeleash-full-width"><input id="writeleash-free-block-zero" name="block_zero" type="checkbox" value="1"' . checked( $values['block_zero'] ?? '', '1', false ) . '> <label for="writeleash-free-block-zero">Block a preview that sets any changing price to zero</label></p></fieldset></details>';
		echo '<p class="writeleash-actions"><button type="submit" class="button button-primary">Preview price changes</button> <span class="description">' . esc_html( 'Preview does not change any prices.' ) . '</span></p></form>';
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
			echo '<p>' . esc_html( 'No price changes yet. Preview your first change above to start.' ) . '</p>';
			return;
		}
		$user_id = get_current_user_id();
		try {
			// Actor-scoped in SQL: the page contains only caller-visible jobs.
			$page = Undo_Repository::history_jobs( 0, 5, $user_id );
		} catch ( \Throwable $error ) {
			echo '<p>' . esc_html( 'History is unavailable right now. Reload the page to try again.' ) . '</p>';
			return;
		}
		self::render_history_table( $page['jobs'] );
	}

	private static function reviewable( array $job ): bool {
		return in_array( $job['status'], array( Job_State::PLANNED, Job_State::BLOCKED ), true );
	}

	private static function job_action_link( array $job, bool $primary = false ): void {
		$label = Job_State::PLANNED === $job['status'] ? 'Continue review' : ( Job_State::BLOCKED === $job['status'] ? 'Review blocked plan' : 'Open progress/results' );
		echo '<a class="button' . ( $primary ? ' button-primary' : '' ) . '" href="' . esc_url( self::page_url( self::reviewable( $job ) ? 'preview' : 'job', $job['public_id'] ) ) . '">' . esc_html( $label ) . '</a>';
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
			echo '<div class="notice notice-error"><p>' . esc_html( self::reason_message( 'job_material_mismatch' ) ) . '</p></div>';
			return;
		}
		if ( ! self::reviewable( $job ) ) { self::render_job_view( $public_id, $offset ); return; }
		$data = $plan->data();
		$summary = $plan->summary();
		if ( $summary['selected'] > Free_Support_Contract::MAX_JOB_PRODUCTS ) {
			if ( self::is_legacy_oversized_job( $job, $summary['selected'] ) ) {
				self::render_legacy_oversize_warning();
				echo '<p><a href="' . esc_url( self::page_url( 'job', $job['public_id'] ) ) . '">' . esc_html( 'Open progress and available Undo' ) . '</a></p>';
			} else {
				echo '<div class="notice notice-error" role="alert"><p>' . esc_html( self::reason_message( 'supported_job_limit_exceeded', $summary['selected'] ) ) . '</p></div>';
			}
			return;
		}
		$blocked = 'BLOCKED' === $data['status'];
		echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( 'Back to bulk prices' ) . '</a></p>';
		echo '<h2>' . esc_html( 'Review price change' ) . '</h2><div class="writeleash-summary">';
		echo '<p><strong>' . esc_html( self::task_description( $data ) ) . '</strong></p>';
		self::support_details( $data['plan_id'] . ' · ' . $plan->hash() );
		if ( 'CATEGORY' === ( $data['selection']['type'] ?? '' ) ) {
			echo '<p>' . esc_html( 'Category scope: ' . ( ! empty( $data['selection']['include_children'] ) ? 'direct members and all nested subcategories' : 'direct members only; subcategories are not included' ) . '. This preview freezes the reviewed product IDs, including expanded variations.' ) . '</p>';
		}
		echo '<p>' . esc_html( 'Selected ' . $summary['selected'] . ' products: ' . ( $summary['changing'] ) . ' planned changes · ' . $summary['unchanged'] . ' already at the target price · ' . $summary['unsupported'] . ' skipped at preview.' ) . '</p>';
		$extra_counts = self::preview_extra_counts( $data['items'] );
		echo '<p>' . esc_html( 'Large increases ' . $extra_counts['large_increase'] . ' · large decreases ' . $extra_counts['large_decrease'] . ' · zero-price targets ' . $extra_counts['zero_target'] . ' · ' . $summary['warning_items'] . ' products with warnings.' ) . '</p></div>';
		if ( $blocked ) {
			echo '<div class="notice notice-error" role="alert"><p><strong>' . esc_html( 'This plan cannot be executed.' ) . '</strong>: ' . esc_html( 'your safety limits block every changing product. You cannot approve this preview; build a new one with different settings.' ) . '</p>';
			$identities = array_column( $data['items'], null, 'product_id' );
			$blockers = $data['policy_result']['blockers'];
			$blocked_ids = array();
			foreach ( $blockers as $blocker ) {
				$id = (int) ( $blocker['product_id'] ?? 0 );
				if ( $id > 0 ) { $blocked_ids[ $id ] = true; }
			}
			$listed_ids = array();
			foreach ( $blockers as $blocker ) {
				$id = (int) ( $blocker['product_id'] ?? 0 );
				if ( $id > 0 ) {
					if ( isset( $listed_ids[ $id ] ) || count( $listed_ids ) >= self::PREVIEW_PAGE_SIZE ) { continue; }
					$listed_ids[ $id ] = true;
				}
				echo '<p>';
				if ( $id > 0 && isset( $identities[ $id ] ) ) { self::render_identity( $identities[ $id ] ); echo '<br>'; }
				echo esc_html( self::reason_message( (string) ( $blocker['reason'] ?? 'blocked' ) ) ) . '</p>';
			}
			if ( count( $blocked_ids ) > self::PREVIEW_PAGE_SIZE ) {
				echo '<p>' . esc_html( 'Showing the first ' . self::PREVIEW_PAGE_SIZE . ' blocked products in this summary. Each product page below shows its own reason; ' . ( count( $blocked_ids ) - self::PREVIEW_PAGE_SIZE ) . ' more blocked products are not listed here.' ) . '</p>';
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
		$field = $plan->price_field();
		$field_label = Price_Operation::label( $field );
		echo '<p>' . esc_html( 'Price to change: ' . $field_label . '. ' . ( Price_Operation::FIELD_SALE === $field ? 'The regular price is the sale baseline and is preserved.' : 'The sale price and schedule are preserved.' ) ) . '</p>';
		echo '<div class="writeleash-table-scroll" role="region" aria-label="Product prices and outcomes" tabindex="0"><table class="widefat striped writeleash-prices"><thead><tr><th scope="col">' . esc_html( 'Product' ) . '</th><th scope="col">' . esc_html( $field_label . ' before' ) . '</th><th scope="col">' . esc_html( $field_label . ' after' ) . '</th><th scope="col">' . esc_html( 'Delta' ) . '</th><th scope="col">' . esc_html( 'Change %' ) . '</th><th scope="col">' . esc_html( 'Shoppers would pay after Apply' ) . '</th><th scope="col">' . esc_html( 'What will happen' ) . '</th></tr></thead><tbody>';
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
			// Format the frozen ratio for display; never feed presentation into the plan.
			$ratio = $item['percentage_delta'] ?? null;
			$ratio_text = ( is_array( $ratio ) && isset( $ratio['display'] ) && is_string( $ratio['display'] ) ) ? self::percentage_display( $ratio['display'], $ratio['numerator'] ?? null ) : 'Unavailable';
			echo '<tr><td>'; self::render_identity( $item ); echo '</td>';
			echo '<td>' . esc_html( self::money_display( $item['stored_price'], $data['store'] ) ) . '</td>';
			echo '<td>' . esc_html( self::money_display( $item['planned_regular_price'], $data['store'] ) ) . '</td>';
			echo '<td>' . esc_html( self::money_display( $item['absolute_delta'], $data['store'], true ) ) . '</td>';
			echo '<td>' . esc_html( $ratio_text ) . '</td>';
			echo '<td>' . esc_html( self::money_display( self::effective_shopper_price( $item, $field ), $data['store'] ) ) . '</td>';
			echo '<td class="' . esc_attr( in_array( $state, array( 'BLOCKED', 'CONFLICT', 'NEEDS_REVIEW' ), true ) ? 'writeleash-attention' : '' ) . '"><strong>' . esc_html( self::item_label( $state, $blocked ? Job_State::BLOCKED : Job_State::PLANNED ) ) . '</strong>';
			foreach ( $detail as $reason ) { echo '<p>' . esc_html( self::reason_message( $reason ) ) . '</p>'; }
			self::support_details( $state . ' · ' . implode( ', ', $detail ) );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		self::render_pager( 'preview', $job['public_id'], $offset, $limit, $page['next_offset'] );
		echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">Create a new preview</a> ' . esc_html( 'Changing the selection or price settings creates a new preview; this saved preview stays unchanged.' ) . '</p>';
		if ( ! $blocked && Job_State::PLANNED === $job['status'] && self::can_mutate() ) {
			echo '<h2>' . esc_html( 'Approve this preview' ) . '</h2>';
			echo '<p>' . esc_html( 'Approving applies exactly the products and prices shown above. Planned prices are not guaranteed shopper prices. If you change any setting, build a new preview.' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_APPROVE ) . '">';
			echo '<input type="hidden" name="job" value="' . esc_attr( $job['public_id'] ) . '">';
			wp_nonce_field( self::ACTION_APPROVE . '_' . $job['plan_id'] );
			echo '<p><button type="submit" class="button button-primary">' . esc_html( 'Approve and apply' ) . '</button></p></form>';
		} elseif ( ! $blocked ) {
			echo '<p>' . esc_html( 'Current status: ' . self::job_label( $job['status'] ) . '. ' ) . '<a href="' . esc_url( self::page_url( 'job', $job['public_id'] ) ) . '">' . esc_html( 'View progress and results' ) . '</a></p>';
		}
	}

	private static function render_pager( string $view, string $public_id, int $offset, int $limit, $next_offset, string $filter = '' ): void {
		echo '<p>';
		if ( $offset > 0 ) {
			$prev = max( 0, $offset - $limit );
			echo '<a class="button" href="' . esc_url( self::page_url( $view, $public_id, $prev, $filter ) ) . '">' . esc_html( 'Previous page' ) . '</a> ';
		} else {
			echo '<button type="button" class="button" disabled aria-disabled="true">' . esc_html( 'Previous page' ) . '</button> ';
		}
		if ( null !== $next_offset ) {
			echo '<a class="button" href="' . esc_url( self::page_url( $view, $public_id, (int) $next_offset, $filter ) ) . '">' . esc_html( 'Next page' ) . '</a>';
		} else {
			echo '<button type="button" class="button" disabled aria-disabled="true">' . esc_html( 'Next page' ) . '</button>';
		}
		echo '</p>';
	}

	/**
	 * Page-row display only: a new Woo edit-context read after targeted cache
	 * eviction, using the same decimal interpretation as price verification.
	 * Unlike execution/recovery invalidation, this does not delete transients
	 * or touch product, journal, job or Undo storage. Never falls back to a plan.
	 */
	private static function product_observation( int $product_id, string $field ): array {
		$unavailable = array( 'price' => 'Unavailable', 'context' => 'Current product details are unavailable.' );
		try {
			if ( ! current_user_can( 'edit_post', $product_id ) || ! empty( $GLOBALS['_wp_suspend_cache_invalidation'] ) ) { return $unavailable; }
			$product = Product_Price_Snapshot::fresh_product( $product_id );
			if ( ! $product instanceof \WC_Product || $product->get_id() !== $product_id ) { return $unavailable; }
			$snapshot = Product_Price_Snapshot::read( $product_id, $product )->data();
			$context = '';
			if ( ! $snapshot['core_simple'] && empty( $snapshot['core_variation'] ) ) { $context = 'At page load, this product is no longer a supported core simple product or variation.'; }
			elseif ( ! empty( $snapshot['core_variation'] ) && ( 'publish' !== ( $snapshot['parent_status'] ?? '' ) || empty( $snapshot['parent_core_variable'] ) ) ) { $context = 'At page load, this variation’s parent is no longer a published core variable product.'; }
			elseif ( 'publish' !== $snapshot['status'] ) { $context = 'At page load, this product is no longer published.'; }
			elseif ( Price_Operation::FIELD_REGULAR === $field && ( '' !== $snapshot['sale_price'] || null !== $snapshot['sale_from'] || null !== $snapshot['sale_to'] ) ) { $context = 'At page load, this product has a sale price or schedule; a matching regular price alone does not authorize overwriting it.'; }
			$price = $snapshot[ Price_Operation::meta_key( $field ) ];
			try { Price_Decimal::parse( $price ); } catch ( \Throwable $error ) { $price = 'Unavailable'; }
			return array( 'price' => $price, 'context' => $context );
		} catch ( \Throwable $error ) { return $unavailable; }
	}

	private static function render_job_view( string $public_id, int $offset, string $filter = '' ): void {
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
			$plan = Job_Repository::hydrate_plan( $job );
			$selected = $plan->summary()['selected'];
			$identities = array_column( $plan->data()['items'], null, 'product_id' );
		} catch ( \Throwable $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'Saved progress and results are unavailable right now. Reload this page to try again; if it persists, ask an administrator to check the saved records.' ) . '</p></div>';
			return;
		}
		$counts = $observed['counts'];
		$effective = $observed['effective_status'];
		$complete_evidence = $counts['planned'] === $selected;
		echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( 'Back to bulk prices' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( self::page_url( 'history' ) ) . '">' . esc_html( 'Open history' ) . '</a></p>';
		if ( self::reviewable( $job ) ) { self::job_action_link( $job, true ); echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">Create a new preview</a></p>'; }
		echo '<div data-writeleash-progress="1" data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-job="' . esc_attr( $public_id ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::ACTION_PROGRESS . '_' . $public_id ) ) . '" data-action-url="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-offset="' . esc_attr( (string) $offset ) . '" data-filter="' . esc_attr( $filter ) . '">';
		echo '<p><a class="button" href="' . esc_url( self::page_url( 'job', $public_id, $offset, $filter ) ) . '">Refresh saved progress</a> <span data-progress-connection>Automatic updates require JavaScript. Refresh to read saved progress.</span></p><p class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-progress-announcement></p>';
		echo '<h2>Progress and results</h2><div class="writeleash-summary"><p><strong>' . esc_html( self::task_description( $plan->data() ) ) . '</strong></p>';
		echo '<p><strong data-progress-label>' . esc_html( $complete_evidence ? self::job_label( $effective ) : 'Saved product outcomes unavailable; needs checking' ) . '</strong></p>';
		if ( $complete_evidence ) { echo '<p data-progress-summary>' . esc_html( self::result_summary( $counts, $effective, true ) ) . '</p>'; }
		else { echo '<p data-progress-summary>' . esc_html( 'Planned ' . $selected . ( 1 === $selected ? ' product.' : ' products.' ) . ' Some saved product evidence is unavailable. Check this job before taking further action.' ) . '</p>'; }
		if ( in_array( $effective, array( Job_State::PAUSED, Job_State::NEEDS_REVIEW ), true ) ) { echo '<p data-progress-initial-notice>' . esc_html( self::reason_message( $observed['effective_reason'] ) ) . '</p>'; }
		echo '<div data-progress-initial-notice>'; self::support_details( $effective . ' · ' . $observed['effective_reason'] . ' · ' . $job['public_id'] . ' · pending ' . $counts['pending'] . ' · applied ' . $counts['applied'] ); echo '</div>';
		echo '</div>';
		echo '<p data-progress-notice></p>';
		if ( $observed['stalled'] ) {
			echo '<div data-progress-initial-notice class="notice notice-warning" role="alert"><p>' . esc_html( 'Background processing stopped making progress. Use Resume remaining products to continue; completed changes are kept.' ) . '</p></div>';
		}
		if ( in_array( $effective, array( Job_State::COMPLETED_WITH_ISSUES, Job_State::NEEDS_REVIEW ), true ) ) {
			echo '<div data-progress-initial-notice class="notice notice-warning" role="alert"><p>' . esc_html( Job_State::NEEDS_REVIEW === $effective ? 'An outcome needs checking. Review uncertain products before continuing remaining work; uncertain products will not be retried.' : 'The price change finished with products needing attention. Review conflicts and failed products below, then create a new preview if needed.' ) . '</p></div>';
		}
		if ( self::is_legacy_oversized_job( $job, $selected ) ) {
			self::render_legacy_oversize_warning();
		} elseif ( $selected > Free_Support_Contract::MAX_JOB_PRODUCTS ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( self::reason_message( 'supported_job_limit_exceeded', $selected ) ) . '</p></div>';
		}
		echo '<div data-progress-resume>';
		if ( $complete_evidence && Job_State::can_manual_run( $job['status'] ) && self::can_mutate() ) {
			if ( in_array( $effective, array( Job_State::READY, Job_State::QUEUED ), true ) && ! $observed['stalled'] ) { echo '<p data-progress-initial-notice>' . esc_html( 'Waiting for background processing. Use Resume remaining products to run the next step now.' ) . '</p>'; }
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RESUME ) . '">';
			echo '<input type="hidden" name="job" value="' . esc_attr( $job['public_id'] ) . '">';
			wp_nonce_field( self::ACTION_RESUME . '_' . $job['public_id'] );
			echo '<p><button type="submit" class="button button-primary">' . esc_html( 'Resume remaining products' ) . '</button> ';
			echo esc_html( 'Runs only the remaining products; completed changes are kept. Products changed since Preview are left alone.' ) . '</p></form>';
		}
		echo '</div>';
		self::render_export_form( $job );
		echo '<section class="writeleash-undo"><h2>' . esc_html( 'Undo' ) . '</h2>';
		if ( $complete_evidence ) { self::render_undo_section( $job, $history, $plan->price_field() ); }
		else { echo '<p>Undo availability cannot be verified while product outcomes are missing. Reload this job or ask an administrator to check its saved records.</p>'; }
		echo '</section>';
		echo '<h2>' . esc_html( 'Products' ) . '</h2>';
		$filters = array( '' => array( 'All products', null, null ), 'conflict' => array( 'Conflicts', 'CONFLICT', null ), 'attention' => array( 'Changes needing attention', 'FAILED', null ), 'review' => array( 'Uncertain changes', 'NEEDS_REVIEW', null ), 'undo_conflict' => array( 'Undo conflicts', null, 'UNDO_CONFLICT' ), 'undo_review' => array( 'Uncertain restorations', null, 'UNDO_NEEDS_REVIEW' ) );
		if ( ! isset( $filters[ $filter ] ) ) { $filter = ''; }
		echo '<p class="writeleash-filters">';
		foreach ( $filters as $key => $choice ) {
			if ( $key === $filter ) { echo '<strong>' . esc_html( $choice[0] ) . '</strong> '; }
			else { echo '<a class="button" href="' . esc_url( self::page_url( 'job', $job['public_id'], 0, $key ) ) . '">' . esc_html( $choice[0] ) . '</a> '; }
		}
		echo '</p>';
		try {
			$items = Undo_Repository::history_items( (int) $job['id'], $filters[ $filter ][1], $filters[ $filter ][2], $offset, self::ITEM_PAGE_SIZE );
		} catch ( \Throwable $error ) {
			echo '<p>' . esc_html( 'Item details are unavailable for this page.' ) . '</p>';
			$items = null;
		}
		if ( null !== $items ) {
			$field = $plan->price_field();
			$field_name = Price_Operation::label( $field );
			$field_label = strtolower( $field_name );
			echo '<p>' . esc_html( 'Expected and planned are ' . $field_label . ' values. Now shows the stored ' . $field_label . ' at page load, not the shopper price. Unavailable means the product or price could not be read.' ) . '</p>';
			echo '<div class="writeleash-table-scroll" role="region" aria-label="Product prices and outcomes" tabindex="0"><table class="widefat striped writeleash-prices writeleash-results" data-writeleash-results="1"><thead><tr><th scope="col">' . esc_html( 'Product' ) . '</th><th scope="col">' . esc_html( $field_name . ' expected' ) . '</th><th scope="col">' . esc_html( $field_name . ' now' ) . '</th><th scope="col">' . esc_html( $field_name . ' planned' ) . '</th><th scope="col">' . esc_html( 'Apply' ) . '</th><th scope="col">' . esc_html( 'Undo' ) . '</th></tr></thead><tbody>';
			if ( ! $items['items'] ) { echo '<tr><td colspan="6">No retained products on this page match this view.</td></tr>'; }
			foreach ( $items['items'] as $item ) {
				$id = (int) $item['product_id'];
				$frozen = $identities[ $id ] ?? array( 'product_id' => $id );
				$observation = self::product_observation( $id, $field );
				echo '<tr data-product-id="' . esc_attr( (string) $id ) . '"><td>'; self::render_identity( $frozen ); echo '</td>';
				echo '<td>' . esc_html( self::money_display( self::expected_display( $frozen, $item, $field ), $plan->data()['store'] ) ) . '</td>';
				echo '<td>' . esc_html( self::money_display( $observation['price'], $plan->data()['store'] ) ) . '</td>';
				echo '<td>' . esc_html( self::money_display( $item['planned_price'], $plan->data()['store'] ) ) . '</td>';
				echo '<td class="' . esc_attr( in_array( $item['apply_state'], array( 'CONFLICT', 'NEEDS_REVIEW', 'FAILED' ), true ) ? 'writeleash-attention' : '' ) . '">'; self::render_item_outcome( $item['apply_state'], 'UNSUPPORTED' === $item['apply_state'] ? ( $frozen['eligibility']['reason'] ?? $item['apply_reason'] ) : $item['apply_reason'], $job['status'], $observation ); echo '</td>';
				echo '<td class="' . esc_attr( in_array( $item['undo_state'], array( 'UNDO_CONFLICT', 'UNDO_NEEDS_REVIEW', 'UNDO_FAILED' ), true ) ? 'writeleash-attention' : '' ) . '">';
				if ( null === $item['undo_state'] ) { echo esc_html( self::undo_item_label( $item, $job['status'] ) ); }
				else { self::render_item_outcome( $item['undo_state'], $item['undo_reason'], $job['status'], $observation ); }
				echo '</td></tr>';
			}

			echo '</tbody></table></div>';
			echo '<div data-progress-pager>';
			self::render_pager( 'job', $job['public_id'], $offset, self::ITEM_PAGE_SIZE, $items['next_offset'], $filter );
			echo '</div>';
		}
		echo '</div>';
	}

	/** Post-approval states are durable authority, not a new size certification. */
	private static function is_legacy_oversized_job( array $job, int $selected ): bool {
		return $selected > Free_Support_Contract::MAX_JOB_PRODUCTS && (
			Job_State::is_executable( $job['status'] ) ||
			in_array( $job['status'], array( Job_State::COMPLETED, Job_State::COMPLETED_WITH_ISSUES ), true )
		);
	}

	public static function legacy_oversize_message(): string {
		return 'This older job was already approved. The current WriteLeash limit of ' . Free_Support_Contract::MAX_JOB_PRODUCTS
			. ' products applies to new work: new jobs above ' . Free_Support_Contract::MAX_JOB_PRODUCTS . ' cannot be created or approved. '
			. 'You can still continue this existing job and restore prices that are safe to restore.';
	}

	private static function render_legacy_oversize_warning(): void {
		echo '<div class="notice notice-warning" role="alert"><p><strong>' . esc_html( 'Older large job' ) . '</strong></p><p>' . esc_html( self::legacy_oversize_message() ) . '</p></div>';
	}

	private static function render_undo_section( array $job, array $history, string $field ): void {
		$field_label = strtolower( Price_Operation::label( $field ) );
		echo '<p>' . esc_html( 'Undo restores eligible ' . $field_label . ' values changed by this job to their values before this job. Later price or sale-setting changes are left alone. Undo does not reverse: orders, completed sales or effects in other plugins and services.' ) . '</p>';
		echo '<p><strong data-progress-undo-label>' . esc_html( self::undo_availability( $job, $history ) ) . '</strong></p>';
		if ( ! empty( $history['undo_expires_at'] ) ) {
			echo '<p>' . esc_html( 'Undo window ends: ' . self::site_time( $history['undo_expires_at'] ) ) . '</p>';
		}
		echo '<p data-progress-undo-summary></p><p data-progress-undo-notice></p>';
		if ( ! empty( $history['undo']['operation_status'] ) ) {
			$undo = $history['undo'];
			$undo_review = (int) $undo['needs_review'];
			echo '<p data-progress-initial-undo>' . esc_html( (int) $undo['undone'] . ' restored · ' . (int) $undo['conflict'] . ' conflict' . ( 1 === (int) $undo['conflict'] ? '' : 's' ) . ' · ' . (int) $undo['pending'] . ' remaining · ' . (int) $undo['failed'] . ' needing attention · ' . (int) $undo['applying'] . ' in progress · ' . $undo_review . ( 1 === $undo_review ? ' needs checking.' : ' need checking.' ) ) . '</p>';
			echo '<div data-progress-initial-undo>'; self::support_details( $undo['operation_status'] . ' · ' . $undo['operation_reason'] ); echo '</div>';
		}

		echo '<div data-progress-undo>';
		if ( $history['undo_eligible'] && self::can_mutate() && ( empty( $history['undo']['operation_status'] ) || Undo_State::can_manual_run( $history['undo']['operation_status'] ) ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_UNDO ) . '">';
			echo '<input type="hidden" name="job" value="' . esc_attr( $job['public_id'] ) . '">';
			wp_nonce_field( self::ACTION_UNDO . '_' . $job['public_id'] );
			echo '<p><button type="submit" class="button' . ( empty( $history['undo']['operation_status'] ) ? ' button-secondary' : ' button-primary' ) . '">' . esc_html( empty( $history['undo']['operation_status'] ) ? 'Restore eligible prices (Undo)' : 'Continue Undo' ) . '</button> ';
			echo esc_html( 'Newer prices and sale settings are left alone.' ) . '</p></form>';
		} elseif ( $history['undo_eligible'] && ! self::can_mutate() ) {
			echo '<p>You do not have permission to restore prices.</p>';
		}
		echo '</div>';
	}

	private static function render_history_view( int $offset ): void {
		echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( 'Back to bulk prices' ) . '</a></p>';
		echo '<h2>' . esc_html( 'History' ) . '</h2>';
		if ( ! self::jobs_installed() ) {
			echo '<p>' . esc_html( 'No price changes yet. Preview a change to start.' ) . '</p>';
			return;
		}
		try {
			$page = Undo_Repository::history_jobs( $offset, self::HISTORY_PAGE_SIZE, get_current_user_id() );
		} catch ( \Throwable $error ) {
			echo '<p>' . esc_html( 'History is unavailable right now. Reload the page to try again.' ) . '</p>';
			return;
		}
		self::render_history_table( $page['jobs'] );
		self::render_pager( 'history', '', $offset, self::HISTORY_PAGE_SIZE, $page['next_offset'] );
	}
}
