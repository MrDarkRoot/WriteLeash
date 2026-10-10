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
	public const ACTION_EXPORT_PREVIEW = 'writeleash_free_export_preview';
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
		add_action( 'admin_post_' . self::ACTION_EXPORT_PREVIEW, array( __CLASS__, 'handle_export_preview' ) );
	}

	/**
	 * Woo-first entry point: next to Products when WooCommerce registers the
	 * product post type, else under WooCommerce, else Tools with a dependency
	 * notice. Visibility needs product editing; every mutation additionally
	 * requires manage_woocommerce plus job authorization.
	 */
	public static function menu(): void {
		$title = __( 'WriteLeash Bulk Prices', 'writeleash' );
		if ( post_type_exists( 'product' ) ) {
			add_submenu_page( 'edit.php?post_type=product', $title, esc_html__( 'Bulk Prices', 'writeleash' ), 'edit_products', self::SLUG, array( __CLASS__, 'render' ) );
		} elseif ( function_exists( 'wc_get_product' ) ) {
			add_submenu_page( 'woocommerce', $title, esc_html__( 'Bulk Prices', 'writeleash' ), 'edit_products', self::SLUG, array( __CLASS__, 'render' ) );
		} else {
			add_management_page( $title, esc_html__( 'WriteLeash Bulk Prices', 'writeleash' ), 'edit_products', self::SLUG, array( __CLASS__, 'render' ) );
		}
	}

	/** Woo enhancement assets are isolated to this Admin screen; native form remains usable. */
	public static function assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'product_page_' . self::SLUG, 'woocommerce_page_' . self::SLUG, 'tools_page_' . self::SLUG ), true ) ) { return; }
		wp_enqueue_style( 'writeleash-free-selection', plugins_url( 'includes/free/free-selection.css', WRITELEASH_PLUGIN_FILE ), array(), WRITELEASH_VERSION );
		if ( ! self::can_mutate() || ! self::dependency_ok() ) { return; }
		wp_enqueue_script( 'writeleash-free-progress', plugins_url( 'includes/free/free-progress.js', WRITELEASH_PLUGIN_FILE ), array( 'wp-i18n' ), WRITELEASH_VERSION, true );
		wp_set_script_translations( 'writeleash-free-progress', 'writeleash', dirname( WRITELEASH_PLUGIN_FILE ) . '/languages' );
		if ( ! wp_script_is( 'selectWoo', 'registered' ) ) { return; }
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'writeleash-free-selection', plugins_url( 'includes/free/free-selection.js', WRITELEASH_PLUGIN_FILE ), array( 'jquery', 'selectWoo', 'wp-i18n' ), WRITELEASH_VERSION, true );
		wp_set_script_translations( 'writeleash-free-selection', 'writeleash', dirname( WRITELEASH_PLUGIN_FILE ) . '/languages' );
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
			'ending' => self::post_field( 'ending' ),
			'max_products' => self::post_field( 'max_products' ),
			'max_increase' => self::post_field( 'max_increase' ),
			'max_decrease' => self::post_field( 'max_decrease' ),
			'warning_threshold' => self::post_field( 'warning_threshold' ),
			'block_zero' => self::post_field( 'block_zero' ),
			'job' => self::post_field( 'job' ),
			'source_job' => self::post_field( 'source_job' ),
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
		$view = 'OK' === ( $result['status'] ?? '' ) ? 'preview' : '';
		if ( '' === $view && is_string( $post['source_job'] ?? null ) && preg_match( Job_Repository::PUBLIC_ID_REGEX, $post['source_job'] ) ) { $view = 'recovery'; $public_id = $post['source_job']; }
		return self::finish( $result, $view, $public_id );
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
			// The poll performs no live Woo product reads: eligibility context
			// comes from raw post/postmeta facts through the same shared
			// sentences, never from a WooCommerce product object.
			$observation = array( 'context' => self::poll_observation_context( (int) $item['product_id'], $plan->price_field() ) );
			$rows[] = array(
				'id' => (int) $item['product_id'],

				'name' => sprintf( /* translators: 1: frozen product name, 2: product ID. */ __( '%1$s · #%2$d · as reviewed', 'writeleash' ), self::identity_name( $frozen['snapshot'] ), (int) $item['product_id'] ),
				'expected' => self::money_display( self::expected_display( $frozen, $item, $plan->price_field() ), $plan->data()['store'], false, $plan->price_field() ),
				'planned' => self::money_display( $item['planned_price'], $plan->data()['store'], false, $plan->price_field() ),
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
			$undo_summary = self::undo_summary( $undo );
			return array(
				'status' => 'OK', 'counts' => $counts, 'undo_counts' => $undo,
				'label' => $complete ? self::job_label( $effective ) : __( 'Saved product outcomes unavailable; needs checking', 'writeleash' ),
				'summary' => $complete ? self::result_summary( $counts, $effective ) : __( 'Some saved product evidence is unavailable. Check this job before taking further action.', 'writeleash' ),
				'notice' => $observed['stalled'] ? __( 'Background processing stopped making progress. Resume only remaining products.', 'writeleash' ) : ( in_array( $effective, array( Job_State::PAUSED, Job_State::NEEDS_REVIEW ), true ) ? self::reason_message( $observed['effective_reason'] ) : '' ),
				'undo_label' => $complete ? self::undo_availability( $job, $history ) : __( 'Undo availability cannot be verified while product outcomes are missing.', 'writeleash' ), 'undo_summary' => $undo_summary,
				'undo_notice' => null !== $undo_observed && $undo_observed['stalled'] ? __( 'Undo stopped making progress. Continue Undo only for remaining products.', 'writeleash' ) : '',
				'resume_available' => $complete && Job_State::can_manual_run( $job['status'] ),
				'undo_available' => $complete && $history['undo_eligible'] && ( empty( $undo['operation_status'] ) || Undo_State::can_manual_run( $undo['operation_status'] ) ),
				'resume_nonce' => $complete && Job_State::can_manual_run( $job['status'] ) ? wp_create_nonce( self::ACTION_RESUME . '_' . $public_id ) : null,
				'undo_nonce' => $complete && $history['undo_eligible'] && ( empty( $undo['operation_status'] ) || Undo_State::can_manual_run( $undo['operation_status'] ) ) ? wp_create_nonce( self::ACTION_UNDO . '_' . $public_id ) : null,
				'undo_button' => empty( $undo['operation_status'] ) ? __( 'Restore eligible prices (Undo)', 'writeleash' ) : __( 'Continue Undo', 'writeleash' ),
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
		$range = self::build_price_range( $post );
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
			return Price_Selection_Spec::ids( $ids, $range );
		}
		if ( 'sku' === $kind ) {
			$sku = $post['sku'] ?? null;
			if ( ! is_string( $sku ) ) {
				throw new Price_Validation_Error( 'invalid_sku' );
			}
			return Price_Selection_Spec::sku( $sku, $range );
		}
		if ( 'category' === $kind ) {
			$raw = $post['category'] ?? null;
			if ( ! is_string( $raw ) || ! preg_match( '/\A[0-9]{1,10}\z/', $raw ) || (int) $raw < 1 ) {
				throw new Price_Validation_Error( 'invalid_category' );
			}
			$include_children = $post['include_subcategories'] ?? null;
			if ( null !== $include_children && '1' !== $include_children ) { throw new Price_Validation_Error( 'invalid_category' ); }
			return Price_Selection_Spec::category( (int) $raw, '1' === $include_children, $range );
		}
		throw new Price_Validation_Error( 'invalid_selector' );
	}

	/**
	 * #234 optional inclusive price-range filter from the merchant form.
	 *
	 * The basis defaults to the form's own target price field when the
	 * merchant leaves the basis control empty, so the default basis follows
	 * the field being edited unless an explicit choice was made. A disabled
	 * filter ignores every bound/basis input, so stale values can never
	 * narrow a population silently.
	 */
	public static function build_price_range( array $post ): Price_Range_Filter {
		$field = $post['price_field'] ?? Price_Operation::FIELD_REGULAR;
		if ( ! is_string( $field ) || ! in_array( $field, Price_Operation::FIELDS, true ) ) {
			$field = Price_Operation::FIELD_REGULAR;
		}
		return Price_Range_Filter::from_inputs( $post['range_enabled'] ?? null, $post['range_min'] ?? null, $post['range_max'] ?? null, $post['range_basis'] ?? null, $field );
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
		foreach ( array( 'source_job', 'selector', 'ids', 'sku', 'category', 'include_subcategories', 'range_enabled', 'range_min', 'range_max', 'range_basis', 'operation', 'price_field', 'amount', 'max_products', 'max_increase', 'max_decrease', 'warning_threshold', 'block_zero', 'product_search', 'category_search', 'product_page', 'category_page' ) as $key ) {
			$value = $post[$key] ?? null;
			if ( is_string( $value ) && strlen( $value ) <= ( 'ids' === $key ? 20000 : 100 ) ) { $values[$key] = $value; }
		}
		// An unchecked recovery suggestion must not be restored from the source default.
		if ( isset( $values['source_job'] ) && ! array_key_exists( 'block_zero', $values ) ) { $values['block_zero'] = ''; }
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
		$amount = $post['amount'] ?? ( Price_Operation::CLEAR_SALE === $type ? '' : null );
		$field = $post['price_field'] ?? Price_Operation::FIELD_REGULAR;
		if ( ! is_string( $field ) || ! in_array( $field, Price_Operation::FIELDS, true ) ) {
			throw new Price_Validation_Error( 'unsupported_price_field' );
		}
		if ( ! is_string( $type ) || ! in_array( $type, array( Price_Operation::SET, Price_Operation::INCREASE_FIXED, Price_Operation::DECREASE_FIXED, Price_Operation::INCREASE_PERCENT, Price_Operation::DECREASE_PERCENT, Price_Operation::CLEAR_SALE, Price_Operation::SALE_DISCOUNT_PERCENT ), true ) ) {
			throw new Price_Validation_Error( 'unsupported_operation' );
		}
		if ( ! is_string( $amount ) ) {
			throw new Price_Validation_Error( 'malformed_decimal' );
		}
		return new Price_Operation( $type, $amount, $field, $post['ending'] ?? 'default' );
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

	/** Authenticated bounded read of actual Apply conflicts; never installs or updates records. */
	public static function recovery_source( $public_id ): array {
		if ( get_current_user_id() < 1 || ! self::can_mutate() ) { throw new Price_Validation_Error( 'permission_denied' ); }
		if ( ! is_string( $public_id ) || ! preg_match( Job_Repository::PUBLIC_ID_REGEX, $public_id ) || ! self::jobs_installed() ) { throw new Price_Validation_Error( 'recovery_unavailable' ); }
		$job = self::load_job_for_view( $public_id );
		if ( null === $job ) { throw new Price_Validation_Error( 'permission_denied' ); }
		$plan = Job_Repository::hydrate_plan( $job );
		Free_Support_Contract::assert_job_size( $plan->summary()['selected'] );
		if ( (int) $job['approver_id'] < 1 || Job_Repository::counts( (int) $job['id'] )['planned'] !== $plan->summary()['selected'] ) { throw new Price_Validation_Error( 'recovery_unavailable' ); }
		$rows = array();
		for ( $offset = 0; $offset < Free_Support_Contract::MAX_JOB_PRODUCTS; $offset += Job_Repository::PAGE_LIMIT ) {
			$page = Job_Repository::items( (int) $job['id'], Job_Item_State::CONFLICT, $offset, Job_Repository::PAGE_LIMIT );
			foreach ( $page as $row ) {
				$id = (int) $row['product_id'];
				Job_Repository::assert_item_material( $plan, $row );
				if ( 'CHANGING' !== $plan->item( $id )->data()['result'] ) { throw new Price_Validation_Error( 'recovery_unavailable' ); }
				// Missing products retain an explanation; existing products require current rights.
				if ( get_post( $id ) && ! current_user_can( 'edit_post', $id ) ) { continue; }
				$rows[$id] = $row;
			}
			if ( count( $page ) < Job_Repository::PAGE_LIMIT ) { break; }
		}
		return array( 'job' => $job, 'plan' => $plan, 'rows' => $rows );
	}

	private static function render_recovery_view( string $public_id, array $form ): void {
		try { $source = self::recovery_source( $public_id ); }
		catch ( \Throwable $error ) { echo '<p>' . esc_html__( 'No recoverable Apply conflicts are visible to your account for this job.', 'writeleash' ) . '</p>'; return; }

		echo '<h2>' . esc_html__( 'Re-preview conflicted products', 'writeleash' ) . '</h2><p><strong>' . esc_html( sprintf( /* translators: %s: complete saved price-operation description. */ __( 'Original operation: %s', 'writeleash' ), self::task_description( $source['plan']->data() ) ) ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Prices or eligibility may have changed. Choose the products to review. The operation and safety settings below are editable suggestions. A fresh Preview reads current values; you must approve it separately before Apply.', 'writeleash' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( self::page_url( 'job', $public_id ) ) . '">' . esc_html__( 'Cancel and return to results', 'writeleash' ) . '</a></p>';
		if ( ! $source['rows'] ) { echo '<p>' . esc_html__( 'No eligible Apply conflicts remain.', 'writeleash' ) . '</p>'; return; }
		$d = $source['plan']->data(); $p = $d['policy_snapshot'];
		$values = array( 'operation' => $d['operation']['type'], 'price_field' => $source['plan']->price_field(), 'amount' => $d['operation']['input'],
			'ending' => $d['operation']['ending'] ?? 'default',
			'max_products' => (string) $p['max_products_changed'], 'max_increase' => $p['max_increase_percent'], 'max_decrease' => $p['max_decrease_percent'], 'warning_threshold' => $p['warning_threshold_percent'], 'block_zero' => $p['block_zero'] ? '1' : '', 'product_ids' => array() );
		if ( ( $form['source_job'] ?? null ) === $public_id ) { $values = array_merge( $values, $form ); }
		self::render_selector_form( $values, $source );
	}

	private static function render_recovery_rows( array $source, array $values ): void {
		echo '<fieldset><legend>' . esc_html__( '1. Choose Apply-conflict products', 'writeleash' ) . '</legend><p>' . esc_html__( 'No products are selected by default. Only checked products enter the fresh Preview.', 'writeleash' ) . '</p><ul>';
		foreach ( $source['rows'] as $id => $row ) {
			$item = $source['plan']->item( $id )->data();
			$label = self::identity_name( $item['snapshot'] ) . ' · #' . $id;
			echo '<li><label><input type="checkbox" name="product_ids[]" value="' . esc_attr( (string) $id ) . '"' . ( in_array( (string) $id, $values['product_ids'] ?? array(), true ) ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label>';
			echo '<p>' . esc_html( self::outcome_conflict_copy( 'CONFLICT' ) . ' ' . self::reason_message( (string) $row['reason'] ) ) . '</p>';
			$observation = self::product_observation( $id, $source['plan']->price_field() );
			if ( ! get_post( $id ) ) { $observation['context'] = __( 'This product no longer exists. Preview will mark it as missing and will not apply it.', 'writeleash' ); }

			echo '<p>' . esc_html( sprintf( /* translators: 1: current stored price, 2: current eligibility explanation. */ __( 'At page load: %1$s. %2$s', 'writeleash' ), $observation['price'], $observation['context'] ) ) . '</p>';
			echo '<p>' . esc_html__( 'Current values may have changed again. Preview will explain missing or unsupported products and will never apply them.', 'writeleash' ) . '</p></li>';
		}
		echo '</ul></fieldset>';
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
			$source = null;
			if ( null !== ( $post['source_job'] ?? null ) ) {
				$source = self::recovery_source( $post['source_job'] );
				$ids = self::picker_ids( $post );
				foreach ( $ids as $id ) { if ( ! isset( $source['rows'][$id] ) ) { throw new Price_Validation_Error( 'invalid_conflict_selection' ); } }
				$selection = Price_Selection_Spec::ids( $ids );
			} else { $selection = self::build_selection( $post ); }
			$operation = self::build_operation( $post );
			$policy = self::build_policy( $post );
			$plan = Woo_Price_Planner::preview( $selection, $operation, $policy );
			if ( null !== $source ) {
				// Never expand a changed parent into unrelated children or repeat siblings.
				if ( $plan->data()['resolved_product_ids'] !== $selection->data()['ids'] ) { throw new Price_Validation_Error( 'invalid_conflict_selection' ); }
				foreach ( $plan->data()['items'] as $item ) {
					$old = $source['plan']->item( $item['product_id'] )->data()['snapshot'];
					$now = $item['snapshot'];
					if ( 'UNSUPPORTED' !== $item['result'] && ( $old['type'] !== $now['type'] || ( $old['parent_id'] ?? 0 ) !== ( $now['parent_id'] ?? 0 ) ) ) { throw new Price_Validation_Error( 'invalid_conflict_selection' ); }
				}
				// Re-read source membership after planning; it grants no execution approval.
				$latest = self::recovery_source( $post['source_job'] );
				foreach ( $selection->data()['ids'] as $id ) { if ( ! isset( $latest['rows'][$id] ) ) { throw new Price_Validation_Error( 'invalid_conflict_selection' ); } }
				$plan = $plan->with_source_job( $source['job']['public_id'] );
			}
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
			wp_die( esc_html( __( 'You are not allowed to edit products.', 'writeleash' ) ), '', array( 'response' => 403 ) );
		}
		$view_raw = filter_input( INPUT_GET, 'wl_view' );
		$view = is_string( $view_raw ) ? $view_raw : '';
		$job_raw = filter_input( INPUT_GET, 'wl_job' );
		$job_param = is_string( $job_raw ) ? $job_raw : '';
		$filter_raw = filter_input( INPUT_GET, 'wl_filter' );
		$filter = is_string( $filter_raw ) ? $filter_raw : '';
		$offset_raw = filter_input( INPUT_GET, 'wl_offset' );
		$offset = is_string( $offset_raw ) && preg_match( '/\A[0-9]{1,7}\z/', $offset_raw ) ? (int) $offset_raw : 0;
		echo '<div class="wrap writeleash-admin"><h1 class="writeleash-heading"><img src="' . esc_url( plugins_url( 'includes/free/admin-logo.png', WRITELEASH_PLUGIN_FILE ) ) . '" width="64" height="64" alt="" decoding="async"><span>' . esc_html__( 'WriteLeash Bulk Prices', 'writeleash' ) . '</span></h1>';
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
			echo '<div class="notice notice-error"><p>' . esc_html( __( 'You are not allowed to edit products.', 'writeleash' ) ) . '</p></div>';
			return;
		}
		self::render_notice();
		if ( ! self::dependency_ok() ) {
			echo '<div class="notice notice-error" role="alert"><p>';
			echo esc_html( self::reason_message( (string) Free_Support_Contract::woocommerce_reason() ) );
			echo '</p></div>';
			return;
		}
		if ( 'recovery' === $view ) {
			self::render_recovery_view( $job_param, $form );
		} elseif ( 'preview' === $view ) {
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
			echo '<p>' . esc_html( sprintf( /* translators: %d: products processed in this step. */ _n( 'Processed %d product in this step. Check the results below for remaining work and anything needing attention.', 'Processed %d products in this step. Check the results below for remaining work and anything needing attention.', (int) $notice['processed'], 'writeleash' ), (int) $notice['processed'] ) ) . '</p>';
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
			if ( null === $selected ) {
				/* translators: %d: supported products per new job. */
				return sprintf( __( 'WriteLeash supports up to %d products per job in the tested configuration. Narrow the selection and build a new preview. No additional job or journal was created and no product was changed.', 'writeleash' ), Free_Support_Contract::MAX_JOB_PRODUCTS );
			}
			/* translators: 1: supported products per job, 2: selected products. */
			return sprintf( _n( 'WriteLeash supports up to %1$d products per job in the tested configuration. %2$d product was selected. Narrow the selection and build a new preview. No additional job or journal was created and no product was changed.', 'WriteLeash supports up to %1$d products per job in the tested configuration. %2$d products were selected. Narrow the selection and build a new preview. No additional job or journal was created and no product was changed.', $selected, 'writeleash' ), Free_Support_Contract::MAX_JOB_PRODUCTS, $selected );
		}
		if ( 'woocommerce_version_unsupported' === $reason ) {
			/* translators: 1: supported WooCommerce version range, 2: installed version. */
			return sprintf( __( 'WriteLeash supports %1$s for price mutations. Installed version: %2$s. No job was created and no product was changed.', 'writeleash' ), Free_Support_Contract::range_text(), defined( 'WC_VERSION' ) ? (string) WC_VERSION : __( 'unavailable', 'writeleash' ) );
		}
		if ( 'multisite_unsupported' === $reason ) {
			return __( 'WriteLeash does not support multisite. Use a single-site installation. No job was created and no product was changed.', 'writeleash' );
		}
		if ( 'db_transactions_unsupported' === $reason ) {
			return __( 'WriteLeash needs a standard transactional database connection (mysqli with InnoDB tables). No job was created and no product was changed.', 'writeleash' );
		}
		$messages = array(
			'invalid_price' => __( 'This product has an invalid stored price. Open it in WooCommerce, correct the price and save it before creating a new preview.', 'writeleash' ),
			'capability_required' => __( 'You need WooCommerce product management capabilities for this action.', 'writeleash' ),
			'post_required' => __( 'This action requires an authenticated POST request.', 'writeleash' ),
			'invalid_nonce' => __( 'The security token is missing or invalid. Reload the page and try again.', 'writeleash' ),
			'woocommerce_unavailable' => __( 'WooCommerce must be active and initialized before bulk-price planning. Install and activate WooCommerce, then reopen this page. No job was created and no product was changed.', 'writeleash' ),
			'permission_denied' => __( 'You do not have permission to plan these product edits.', 'writeleash' ),
			'permission_revoked' => __( 'The approved actor can no longer edit one or more products; nothing was executed.', 'writeleash' ),
			'not_authorized' => __( 'Only the job creator, approver or an administrator may perform this action.', 'writeleash' ),
			'invalid_selector' => __( 'Choose selected products, a named category or an advanced exact SKU/manual ID selection.', 'writeleash' ),
			'invalid_discovery' => __( 'Use a shorter product or category search and start again from the first page.', 'writeleash' ),
			'discovery_unavailable' => __( 'Search is unavailable. Try again, reload if your session expired, or use the native search controls.', 'writeleash' ),

			'invalid_selection_size' => sprintf( /* translators: %d: maximum products allowed. */ __( 'Choose between one and %d products.', 'writeleash' ), Free_Support_Contract::MAX_JOB_PRODUCTS ),
			'invalid_product_id' => __( 'Product IDs must be comma-separated positive integers.', 'writeleash' ),
			'invalid_sku' => __( 'Use a non-empty exact SKU without whitespace or markup.', 'writeleash' ),
			'invalid_category' => __( 'Select an existing product category.', 'writeleash' ),
			'invalid_price_range' => __( 'Check the optional price range: enable it, enter a minimum and/or maximum price as an unsigned decimal with a dot separator, and keep the minimum at or below the maximum.', 'writeleash' ),
			'unsupported_operation' => __( 'Select one of the five supported price operations.', 'writeleash' ),
			'malformed_decimal' => __( 'Use an unsigned decimal amount string with a dot separator.', 'writeleash' ),

			'invalid_product_limit' => sprintf( /* translators: %d: maximum changing products allowed. */ __( 'The changed-product safety limit must be between zero and %d. It cannot raise the Free supported-job boundary.', 'writeleash' ), Free_Support_Contract::MAX_JOB_PRODUCTS ),
			'invalid_policy_percent' => __( 'Policy percentages must be unsigned decimal strings.', 'writeleash' ),
			'invalid_policy_flag' => __( 'The zero-price flag is invalid.', 'writeleash' ),
			'invalid_job' => __( 'No WriteLeash job matches that identifier.', 'writeleash' ),
			'invalid_input' => __( 'A request field is malformed; nothing was changed.', 'writeleash' ),
			'plan_blocked' => __( 'The safety policy blocks this plan; it cannot be approved.', 'writeleash' ),
			'preview_ready' => __( 'Preview ready. No prices have changed yet. Review these saved prices before approving.', 'writeleash' ),
			'plan_policy_blocked' => __( 'Your safety limits block every changing product in this preview; it cannot be approved.', 'writeleash' ),
			'already_approved' => __( 'This plan was already approved; no duplicate approval was recorded.', 'writeleash' ),
			'job_material_mismatch' => __( 'This saved job no longer matches its approved preview; no product was changed. Start a new preview.', 'writeleash' ),
			'job_terminal' => __( 'This job has already finished.', 'writeleash' ),
			'job_not_resumable' => __( 'This job cannot be continued from its current state.', 'writeleash' ),
			'job_not_approvable' => __( 'This job cannot be approved in its current state.', 'writeleash' ),
			'approved' => __( 'Approved. WriteLeash will apply the reviewed prices in the background. Reload this page to check progress.', 'writeleash' ),
			'approved_scheduler_unavailable' => __( 'Approved, but background processing could not start. This job is paused; use Resume remaining products to continue it.', 'writeleash' ),
			'resume_chunk' => __( 'Results updated after this step. Check changes, remaining products and anything needing attention below.', 'writeleash' ),
			'undo_chunk' => __( 'Undo results updated. Check restorations, remaining products and anything needing attention below.', 'writeleash' ),
			'undo_terminal' => __( 'This Undo has already finished.', 'writeleash' ),
			'undo_unavailable' => __( 'Undo is currently unavailable; no price was restored.', 'writeleash' ),
			'worker_failed' => __( 'The background step could not run. Saved progress was kept; try Resume remaining products again.', 'writeleash' ),
			'action_failed' => __( 'The action could not be completed; nothing was changed.', 'writeleash' ),
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
		return __( 'The outcome is unavailable. Check the saved results before taking further action.', 'writeleash' );
	}

	/** Merchant copy over existing states; unrecognized states never imply success. */
	public static function job_label( string $state ): string {
		$labels = array(
			'PLANNED' => __( 'Awaiting review', 'writeleash' ), 'BLOCKED' => __( 'This plan cannot be executed.', 'writeleash' ),
			'DRAFT' => __( 'Preparing preview', 'writeleash' ), 'PLANNING' => __( 'Preparing preview', 'writeleash' ),
			'READY' => __( 'Approved; waiting to start', 'writeleash' ), 'QUEUED' => __( 'Waiting to continue', 'writeleash' ),
			'RUNNING' => __( 'In progress', 'writeleash' ), 'PAUSED' => __( 'Paused', 'writeleash' ), 'COMPLETED' => __( 'Finished', 'writeleash' ),
			'COMPLETED_WITH_ISSUES' => __( 'Finished with products needing attention', 'writeleash' ),
			'NEEDS_REVIEW' => __( 'Outcome needs checking', 'writeleash' ), 'CANCELLED' => __( 'Stopped by an operator', 'writeleash' ),
		);
		return $labels[ $state ] ?? __( 'Outcome unavailable; needs checking', 'writeleash' );
	}

	public static function item_label( ?string $state, string $job_state ): string {
		if ( null === $state ) { return __( 'Not started', 'writeleash' ); }
		if ( in_array( $state, array( 'PENDING', 'CHANGING' ), true ) ) {
			if ( 'BLOCKED' === $job_state ) { return __( 'Will not run', 'writeleash' ); }
			if ( 'PLANNED' === $job_state ) { return __( 'Planned change; awaiting review', 'writeleash' ); }
			if ( Job_State::is_terminal( $job_state ) ) { return __( 'Not processed', 'writeleash' ); }
			return __( 'Remaining', 'writeleash' );
		}
		$labels = array(
			'APPLIED' => __( 'Changed', 'writeleash' ), 'UNCHANGED' => __( 'Already at the target price', 'writeleash' ),
			'UNSUPPORTED' => __( 'Skipped at preview', 'writeleash' ), 'BLOCKED' => __( 'Will not run', 'writeleash' ),
			'APPLYING' => __( 'In progress; outcome not yet confirmed', 'writeleash' ), 'CONFLICT' => __( 'Not changed', 'writeleash' ),
			'FAILED' => __( 'Needs attention; not changed', 'writeleash' ), 'NEEDS_REVIEW' => __( 'Outcome uncertain; needs checking', 'writeleash' ),
			'UNDO_PENDING' => __( 'Awaiting restoration', 'writeleash' ), 'UNDO_APPLYING' => __( 'Restoration in progress; outcome not yet confirmed', 'writeleash' ),
			'UNDONE' => __( 'Restored', 'writeleash' ), 'UNDO_CONFLICT' => __( 'Not restored', 'writeleash' ),
			'UNDO_FAILED' => __( 'Restoration needs checking', 'writeleash' ), 'UNDO_NEEDS_REVIEW' => __( 'Restoration uncertain; needs checking', 'writeleash' ),
		);
		return $labels[ $state ] ?? __( 'Outcome unavailable; needs checking', 'writeleash' );
	}

	/** Count phrases form a parallel summary list; each phrase has its own plural rule. */
	public static function count_copy( string $kind, int $count ): string {
		switch ( $kind ) {
			case 'changed':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d changed', '%d changed', $count, 'writeleash' ), $count );
			case 'unchanged':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d already at the target price', '%d already at the target price', $count, 'writeleash' ), $count );
			case 'skipped':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d skipped', '%d skipped', $count, 'writeleash' ), $count );
			case 'conflict':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d conflict', '%d conflicts', $count, 'writeleash' ), $count );
			case 'blocked':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d will not run', '%d will not run', $count, 'writeleash' ), $count );
			case 'planned':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d awaiting approval', '%d awaiting approval', $count, 'writeleash' ), $count );
			case 'unprocessed':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d not processed', '%d not processed', $count, 'writeleash' ), $count );
			case 'pending':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d remaining', '%d remaining', $count, 'writeleash' ), $count );
			case 'applying':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d in progress', '%d in progress', $count, 'writeleash' ), $count );
			case 'failed':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d needing attention', '%d needing attention', $count, 'writeleash' ), $count );
			case 'review':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d needs checking', '%d need checking', $count, 'writeleash' ), $count );
			case 'restored':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d restored', '%d restored', $count, 'writeleash' ), $count );
			case 'changing':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d planned change', '%d planned changes', $count, 'writeleash' ), $count );
			case 'warning':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( '%d product with warnings', '%d products with warnings', $count, 'writeleash' ), $count );
			case 'large_increase':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( 'Large increases %d', 'Large increases %d', $count, 'writeleash' ), $count );
			case 'large_decrease':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( 'large decreases %d', 'large decreases %d', $count, 'writeleash' ), $count );
			case 'zero_target':
				/* translators: %d: number of products in this outcome. */
				return sprintf( _n( 'zero-price targets %d', 'zero-price targets %d', $count, 'writeleash' ), $count );
		}
		return '';
	}

	public static function result_summary( array $counts, string $state, bool $compact = false ): string {
		$pending_kind = 'BLOCKED' === $state ? 'blocked' : ( 'PLANNED' === $state ? 'planned' : ( Job_State::is_terminal( $state ) ? 'unprocessed' : 'pending' ) );
		$parts = array();
		foreach ( array( 'applied' => 'changed', 'unchanged' => 'unchanged', 'unsupported' => 'skipped', 'conflict' => 'conflict', 'pending' => $pending_kind, 'applying' => 'applying', 'failed' => 'failed', 'needs_review' => 'review' ) as $key => $kind ) {
			$count = (int) $counts[$key];
			if ( ! $compact || $count > 0 ) { $parts[] = self::count_copy( $kind, $count ); }
		}
		/* translators: 1: planned products, 2: list of saved outcome counts. */
		return sprintf( _n( 'Planned %1$d product: %2$s.', 'Planned %1$d products: %2$s.', (int) $counts['planned'], 'writeleash' ), (int) $counts['planned'], implode( ' · ', $parts ) );
	}

	public static function undo_summary( array $undo ): string {
		$parts = array();
		foreach ( array( 'undone' => 'restored', 'conflict' => 'conflict', 'pending' => 'pending', 'failed' => 'failed', 'applying' => 'applying', 'needs_review' => 'review' ) as $key => $kind ) { $parts[] = self::count_copy( $kind, (int) $undo[$key] ); }
		return implode( ' · ', $parts );
	}

	public static function selection_count_message( array $count ): string {
		$range = ( isset( $count['range'] ) && is_array( $count['range'] ) && ! empty( $count['range']['enabled'] ) ) ? $count['range'] : null;
		$excluded = (int) ( $count['excluded_by_range'] ?? 0 );
		if ( null !== $range ) {
			$basis = Price_Operation::FIELD_SALE === ( $range['basis'] ?? '' ) ? __( 'sale prices', 'writeleash' ) : __( 'regular prices', 'writeleash' );
			if ( 0 === $count['selected'] ) {
				/* translators: 1: price basis (regular/sale prices), 2: excluded targets. */
				return sprintf( __( 'No products match this price range. The range was compared against each product’s stored %1$s; %2$d readable products fall outside it. Adjust the range or the selection. Preview explains skipped or unsupported products.', 'writeleash' ), $basis, $excluded );
			}
			/* translators: 1: matched price targets, 2: price basis, 3: excluded targets, 4: unreadable targets, 5: missing targets. */
			return sprintf( _n( 'At last check: %1$d deduplicated price target matches the range on stored %2$s. %3$d excluded by the range; %4$d unreadable; %5$d missing. Preview explains skipped or unsupported products.', 'At last check: %1$d deduplicated price targets match the range on stored %2$s. %3$d excluded by the range; %4$d unreadable; %5$d missing. Preview explains skipped or unsupported products.', $count['selected'], 'writeleash' ), $count['selected'], $basis, $excluded, $count['unreadable'], $count['missing'] );
		}
		if ( 0 === $count['selected'] ) { return __( 'No products match this selection. Choose products or another category. Preview explains skipped or unsupported products.', 'writeleash' ); }
		/* translators: 1: deduplicated price targets, 2: unreadable targets, 3: missing targets. */
		return sprintf( _n( 'At last check: %1$d deduplicated price target after variation expansion. %2$d unreadable; %3$d missing. Preview explains skipped or unsupported products.', 'At last check: %1$d deduplicated price targets after variation expansion. %2$d unreadable; %3$d missing. Preview explains skipped or unsupported products.', $count['selected'], 'writeleash' ), $count['selected'], $count['unreadable'], $count['missing'] );
	}

	private static function support_details( string $text ): void {
		echo '<details><summary>' . esc_html__( 'Support details', 'writeleash' ) . '</summary><p>' . esc_html( $text ) . '</p></details>';
	}

	/**
	 * #234 frozen range provenance for the review page. Rendered from the
	 * hashed plan material only: the bounds use the store display, the
	 * counts restate the frozen matched/excluded populations, and the copy
	 * states that Apply cannot add products. Legacy plans without provenance
	 * show no paragraph.
	 */
	public static function price_range_summary( array $context, array $store ): string {
		$filter = ( isset( $context['filter'] ) && is_array( $context['filter'] ) ) ? $context['filter'] : array();
		$basis = Price_Operation::FIELD_SALE === ( $filter['basis'] ?? '' ) ? __( 'Sale prices', 'writeleash' ) : __( 'Regular prices', 'writeleash' );
		$min = ( isset( $filter['min'] ) && is_string( $filter['min'] ) && '' !== $filter['min'] ) ? self::money_display( $filter['min'], $store ) : __( 'no minimum', 'writeleash' );
		$max = ( isset( $filter['max'] ) && is_string( $filter['max'] ) && '' !== $filter['max'] ) ? self::money_display( $filter['max'], $store ) : __( 'no maximum', 'writeleash' );
		// The matched total rides in a plain variable: the official catalog
		// extractor skips _n() calls whose count argument it cannot trace.
		$matched = (int) ( $context['matched'] ?? 0 );
		/* translators: 1: matched products, 2: price basis, 3: minimum price, 4: maximum price, 5: excluded products, 6: unsupported, unreadable or missing products. */
		return sprintf( _n( '%1$d matching product frozen for review · price range on stored %2$s, inclusive: %3$s to %4$s · %5$d excluded by the range · %6$d unsupported, unreadable or missing. Apply changes only these frozen products and never adds more.', '%1$d matching products frozen for review · price range on stored %2$s, inclusive: %3$s to %4$s · %5$d excluded by the range · %6$d unsupported, unreadable or missing. Apply changes only these frozen products and never adds more.', $matched, 'writeleash' ), $matched, $basis, $min, $max, (int) ( $context['excluded_by_range'] ?? 0 ), (int) ( $context['unsupported'] ?? 0 ) );
	}

	public static function task_description( array $plan ): string {
		$op = $plan['operation'];
		$sale = Price_Operation::FIELD_SALE === ( $op['field'] ?? Price_Operation::FIELD_REGULAR );
		$amount = in_array( $op['type'], array( 'INCREASE_PERCENT', 'DECREASE_PERCENT', Price_Operation::SALE_DISCOUNT_PERCENT ), true ) ? self::percentage_display( $op['input'] ) : self::money_display( $op['input'], $plan['store'] );
		$templates = array(

			'SET' => $sale ? /* translators: %s: formatted price or percentage. */ __( 'Set sale prices to %s', 'writeleash' ) : /* translators: %s: formatted price or percentage. */ __( 'Set regular prices to %s', 'writeleash' ),
			'INCREASE_FIXED' => $sale ? /* translators: %s: formatted price or percentage. */ __( 'Increase sale prices by %s', 'writeleash' ) : /* translators: %s: formatted price or percentage. */ __( 'Increase regular prices by %s', 'writeleash' ),

			'DECREASE_FIXED' => $sale ? /* translators: %s: formatted price or percentage. */ __( 'Decrease sale prices by %s', 'writeleash' ) : /* translators: %s: formatted price or percentage. */ __( 'Decrease regular prices by %s', 'writeleash' ),
			'INCREASE_PERCENT' => $sale ? /* translators: %s: formatted price or percentage. */ __( 'Increase sale prices by %s', 'writeleash' ) : /* translators: %s: formatted price or percentage. */ __( 'Increase regular prices by %s', 'writeleash' ),

			'DECREASE_PERCENT' => $sale ? /* translators: %s: formatted price or percentage. */ __( 'Decrease sale prices by %s', 'writeleash' ) : /* translators: %s: formatted price or percentage. */ __( 'Decrease regular prices by %s', 'writeleash' ),
		);

		$operation = sprintf( $templates[$op['type']] ?? /* translators: %s: formatted price or percentage. */ __( 'Price operation: %s', 'writeleash' ), $amount );
		if ( Price_Operation::CLEAR_SALE === $op['type'] ) { $operation = __( 'Clear sale prices to blank; preserve sale schedules', 'writeleash' ); }
		if ( Price_Operation::SALE_DISCOUNT_PERCENT === $op['type'] ) {
			/* translators: %s: exact reviewed percentage, with dot decimal separator. */
			$operation = sprintf( __( 'Set sale prices to %s%% below each product’s reviewed Regular Price', 'writeleash' ), $op['input'] );
		}
		if ( isset( $op['ending'] ) ) {
			/* translators: 1: operation description, 2: selected ending or whole-number label. */
			$operation = sprintf( __( '%1$s; nearest Price Ending %2$s (ties upward)', 'writeleash' ), $operation, 'whole' === $op['ending'] ? __( 'Whole-number price', 'writeleash' ) : '.' . $op['ending'] );
		}
		$total = count( $plan['items'] );
		$variations = 0;
		foreach ( $plan['items'] as $item ) { if ( ! empty( $item['snapshot']['core_variation'] ) ) { ++$variations; } }
		/* translators: 1: complete operation description, 2: number of targets. */
		if ( $variations === $total ) { return sprintf( _n( '%1$s · %2$d variation', '%1$s · %2$d variations', $total, 'writeleash' ), $operation, $total ); }
		/* translators: 1: complete operation description, 2: number of targets. */
		$text = sprintf( _n( '%1$s · %2$d product', '%1$s · %2$d products', $total, 'writeleash' ), $operation, $total );
		if ( $variations > 0 ) {
			/* translators: 1: operation and product count, 2: included variations. */
			$text = sprintf( _n( '%1$s (%2$d variation)', '%1$s (%2$d variations)', $variations, 'writeleash' ), $text, $variations );
		}
		return $text;
	}

	/** Display only: string arithmetic keeps exact decimals out of floating point. */
	public static function money_display( $value, array $store, bool $signed = false, string $field = Price_Operation::FIELD_REGULAR ): string {
		if ( '' === $value && Price_Operation::FIELD_SALE === $field ) { return __( 'Blank (no sale price)', 'writeleash' ); }
		$currency = $store['currency'] ?? null;
		$precision = $store['price_decimals'] ?? null;
		if ( ! is_string( $value ) || ! preg_match( '/\A(-?)([0-9]+)(?:\.([0-9]{1,6}))?\z/D', $value, $parts )
			|| ! is_string( $currency ) || ! preg_match( '/\A[A-Z]{3}\z/D', $currency ) || ! is_int( $precision ) || $precision < 0 || $precision > 6 ) { return __( 'Unavailable', 'writeleash' ); }
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

			$text = sprintf( /* translators: 1: formatted price, 2: exact decimal value, 3: ISO currency code. */ __( '%1$s (exact: %2$s %3$s)', 'writeleash' ), $text, $sign . Price_Decimal::natural( $parts[2] ) . '.' . $fraction, $currency );
		}
		return $text;
	}

	/** The frozen six-place ratio is unchanged; only its Admin label is rounded. */
	public static function percentage_display( $value, ?string $numerator = null ): string {
		if ( ! is_string( $value ) || ! preg_match( '/\A(-?)([0-9]+)(?:\.([0-9]{1,6}))?\z/D', $value, $parts ) ) { return __( 'Unavailable', 'writeleash' ); }
		$units = Price_Decimal::natural( $parts[2] . str_pad( $parts[3] ?? '', 6, '0' ) );
		$nonzero = '0' !== $units || ( null !== $numerator && '0' !== Price_Decimal::natural( ltrim( $numerator, '-' ) ) );
		$negative = '-' === $parts[1] || ( null !== $numerator && str_starts_with( $numerator, '-' ) );
		if ( $nonzero && Price_Decimal::compare( $units, '10000' ) < 0 ) { return null === $numerator ? '<0.01%' : ( $negative ? __( 'Decrease <0.01%', 'writeleash' ) : __( 'Increase <0.01%', 'writeleash' ) ); }
		$rounded = Price_Decimal::divide_round( $units, '10000' );
		$approximate = Price_Decimal::compare( Price_Decimal::multiply( $rounded, '10000' ), $units ) !== 0;
		return ( $approximate ? '≈ ' : '' ) . ( $negative && $nonzero ? '-' : '' ) . str_replace( '.', wc_get_price_decimal_separator(), Price_Decimal::format( $rounded, 2, true ) ) . '%';
	}

	private static function operator_name( int $id ): string {
		if ( $id < 1 ) { return __( 'Not yet approved', 'writeleash' ); }
		$user = get_userdata( $id );

		return $user ? $user->display_name : sprintf( /* translators: %d: deleted WordPress user ID. */ __( 'Deleted user (User #%d)', 'writeleash' ), $id );
	}

	public static function site_time( ?string $utc ): string {
		if ( ! $utc ) { return __( 'Unavailable', 'writeleash' ); }
		$timestamp = strtotime( $utc . ' +00:00' );
		return false === $timestamp ? __( 'Unavailable', 'writeleash' ) : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp, wp_timezone() ) . ' (' . wp_timezone_string() . ')';
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
		echo '<strong>' . esc_html( '' === $name ? __( 'Name unavailable at preview', 'writeleash' ) : $name ) . '</strong><br><span class="description">';
		$sku = (string) ( $identity['sku'] ?? '' );

		echo esc_html( ( '' === $sku ? '' : sprintf( /* translators: %s: product SKU. */ __( 'SKU: %s', 'writeleash' ), $sku ) . ' · ' ) . sprintf( $variation ? /* translators: %d: variation ID. */ __( 'Variation #%d · as reviewed', 'writeleash' ) : /* translators: %d: product ID. */ __( 'Product #%d · as reviewed', 'writeleash' ), $id ) ) . '</span>';
		// No current-name fallback. A native edit link requires a current Woo post and per-product permission.
		$post = get_post( $id );
		if ( $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) && current_user_can( 'edit_post', $id ) ) {
			$link = get_edit_post_link( $id, 'raw' );
			if ( $link ) { echo '<br><a href="' . esc_url( $link ) . '">' . esc_html__( 'Review product', 'writeleash' ) . '</a>'; }
		}
	}

	private static function expected_display( array $frozen, array $item, string $field ): string {
		$meta = Price_Operation::meta_key( $field );
		return (string) ( $frozen['snapshot'][ $meta ] ?? $frozen['stored_price'] ?? $item['expected_price'] ?? __( 'Unavailable', 'writeleash' ) );
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
		if ( '' === $regular ) { return __( 'Unavailable', 'writeleash' ); }
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
			} catch ( Price_Validation_Error $error ) { return __( 'Unavailable', 'writeleash' ); }
		}
		return $active;
	}

	private static function undo_item_label( array $item, string $job_state ): string {
		return null === $item['undo_state'] && 'APPLIED' !== $item['apply_state'] ? __( 'Unavailable: no confirmed WriteLeash change to restore', 'writeleash' ) : self::item_label( $item['undo_state'], $job_state );
	}

	/** Single source for conflict guidance: the server table and polled rows must not diverge. */
	private static function outcome_conflict_copy( ?string $state ): string {
		if ( 'CONFLICT' === $state ) { return __( 'This product’s price or other conditions changed after you reviewed the preview. WriteLeash left the newer value unchanged.', 'writeleash' ); }
		if ( 'UNDO_CONFLICT' === $state ) { return __( 'This product changed after WriteLeash applied its price. WriteLeash preserved the newer value instead of restoring over it.', 'writeleash' ); }
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
			/* translators: 1: opening link to a new preview, 2: closing link. */
			echo '<p>' . sprintf( esc_html__( 'Review the product, then %1$sCreate a new preview%2$s if you still want to change it.', 'writeleash' ), '<a href="' . esc_url( self::page_url() ) . '">', '</a>' ) . '</p>';
		} elseif ( in_array( $state, array( 'NEEDS_REVIEW', 'UNKNOWN', 'UNDO_NEEDS_REVIEW', 'FAILED', 'UNDO_FAILED' ), true ) ) {
			echo '<p>' . esc_html__( 'Check the product and saved results before taking further action. This outcome is not confirmed as unchanged.', 'writeleash' ) . '</p>';
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
		if ( Undo_State::COMPLETED === $status ) { return __( 'Undo finished: eligible prices restored.', 'writeleash' ); }
		if ( Undo_State::COMPLETED_WITH_ISSUES === $status ) { return __( 'Undo finished with conflicts or failed restorations. Review the affected products.', 'writeleash' ); }
		if ( Undo_State::CANCELLED === $status ) { return __( 'Undo stopped by an operator. Already restored prices remain restored.', 'writeleash' ); }
		if ( $history['undo_expires_at'] && $history['undo_expires_at'] <= gmdate( 'Y-m-d H:i:s' ) ) { return __( 'Undo expired: the restoration window has ended.', 'writeleash' ); }
		if ( 'BLOCKED' === $job['status'] ) { return __( 'Undo unavailable: this plan cannot be executed.', 'writeleash' ); }
		if ( 'PLANNED' === $job['status'] ) { return __( 'Undo unavailable: this plan is awaiting review and has not run.', 'writeleash' ); }
		if ( ! in_array( $job['status'], array( Job_State::COMPLETED, Job_State::COMPLETED_WITH_ISSUES ), true ) ) { return __( 'Undo unavailable: Apply has not safely finished. Check or finish the remaining work first.', 'writeleash' ); }
		if ( (int) $job['applied'] < 1 ) { return __( 'Undo unavailable: no products were changed by this job.', 'writeleash' ); }
		if ( Undo_State::NEEDS_REVIEW === $status ) { return __( 'Undo needs checking: a restoration outcome is uncertain. Continuing will not retry uncertain or conflicted products.', 'writeleash' ); }
		if ( Undo_State::PAUSED === $status ) { return __( 'Undo paused. Continue Undo for remaining products.', 'writeleash' ); }
		if ( in_array( $status, array( Undo_State::PENDING, Undo_State::RUNNING ), true ) ) { return __( 'Undo in progress. Reload to check results or continue remaining products.', 'writeleash' ); }
		if ( null !== $status ) { return __( 'Undo outcome unavailable; needs checking.', 'writeleash' ); }
		return $history['undo_eligible'] ? __( 'Undo available for prices changed by this job.', 'writeleash' ) : __( 'Undo unavailable: saved restoration evidence is unavailable.', 'writeleash' );
	}

	private static function render_history_table( array $entries ): void {
		echo '<div class="writeleash-table-scroll" role="region" aria-label="' . esc_attr__( 'Job history', 'writeleash' ) . '" tabindex="0"><table class="widefat striped writeleash-history"><thead><tr><th scope="col">' . esc_html__( 'Task / operator / time', 'writeleash' ) . '</th><th scope="col">' . esc_html__( 'Outcome', 'writeleash' ) . '</th><th scope="col">' . esc_html__( 'Undo', 'writeleash' ) . '</th><th scope="col">' . esc_html__( 'Action', 'writeleash' ) . '</th></tr></thead><tbody>';
		if ( ! $entries ) { echo '<tr><td colspan="4">' . esc_html__( 'No jobs visible to your account yet. Create a new preview to start.', 'writeleash' ) . '</td></tr>'; }
		foreach ( $entries as $entry ) {
			$job = Job_Repository::read( (int) $entry['job_id'] );
			if ( ! $job || ! self::authorized_for_job( $job, get_current_user_id() ) ) { continue; }
			try { $description = self::task_description( Job_Repository::hydrate_plan( $job )->data() ); }
			catch ( \Throwable $error ) { $description = __( 'Saved task details unavailable; needs checking', 'writeleash' ); }
			echo '<tr><td><strong>' . esc_html( $description ) . '</strong><br>';

			echo esc_html( sprintf( /* translators: %s: creator display name. */ __( 'Created by %s', 'writeleash' ), self::operator_name( (int) $job['creator_id'] ) ) );
			if ( (int) $job['approver_id'] > 0 ) { echo '<br>' . esc_html( sprintf( /* translators: %s: approver display name. */ __( 'Approved by %s', 'writeleash' ), self::operator_name( (int) $job['approver_id'] ) ) ); }
			echo '<br><span class="description">' . esc_html( self::site_time( $job['created_at'] ) ) . '</span>';
			self::support_details( $job['public_id'] ); echo '</td>';
			echo '<td><strong>' . esc_html( self::job_label( $job['status'] ) ) . '</strong><br>' . esc_html( self::result_summary( $entry['apply'], $job['status'], true ) ) . '</td>';
			echo '<td>' . esc_html( self::undo_availability( $job, $entry ) );

			if ( $entry['undo_eligible'] ) { echo '<br>' . esc_html( sprintf( /* translators: %s: localized expiry date and time. */ __( 'Until %s', 'writeleash' ), self::site_time( $entry['undo_expires_at'] ) ) ); }
			echo '</td><td>'; self::job_action_link( $job ); self::render_export_form( $job ); echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Native POST download bound to the verified final Preview, independent of its page. */
	private static function render_preview_export_form( array $job ): void {
		if ( ! self::can_mutate() ) { return; }
		echo '<p>' . esc_html__( 'This is a Preview snapshot, not the current live catalog and not an offline approval.', 'writeleash' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_EXPORT_PREVIEW ) . '"><input type="hidden" name="job" value="' . esc_attr( $job['public_id'] ) . '">';
		wp_nonce_field( self::ACTION_EXPORT_PREVIEW . '_' . $job['public_id'] . '_' . $job['plan_hash'] );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Download Preview CSV', 'writeleash' ) . '</button></p></form>';
	}

	/** Read only: authorize before rehydrating private Plan material. No client Plan selector. */
	public static function export_preview( array $post, string $method ): array {
		if ( ! self::can_mutate() ) { return array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ); }
		if ( 'POST' !== $method ) { return array( 'status' => 'INVALID', 'reason' => 'post_required' ); }
		if ( ! self::jobs_installed() ) { return array( 'status' => 'INVALID', 'reason' => 'invalid_job' ); }
		$job = self::job_from_post( $post );
		if ( ! $job || ! self::authorized_for_job( $job, get_current_user_id() ) ) { return array( 'status' => 'FORBIDDEN', 'reason' => 'not_authorized' ); }
		$nonce = self::normalize_nonce( $post['_wpnonce'] ?? null );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::ACTION_EXPORT_PREVIEW . '_' . $job['public_id'] . '_' . $job['plan_hash'] ) ) { return array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ); }
		if ( ! self::reviewable( $job ) ) { return array( 'status' => 'INVALID', 'reason' => 'invalid_job' ); }
		try {
			$plan = Job_Repository::hydrate_plan( $job );
			if ( $plan->summary()['selected'] > Free_Support_Contract::MAX_JOB_PRODUCTS ) { throw new \RuntimeException( 'Preview limit exceeded' ); }
		} catch ( \Throwable $error ) { return array( 'status' => 'INVALID', 'reason' => 'job_material_mismatch' ); }
		return array( 'status' => 'OK', 'job' => $job, 'plan' => $plan );
	}

	/** Text is always guarded; only strict decimal strings in amount columns bypass quoting. */
	private static function preview_csv_cell( $value, bool $amount = false ): string {
		$text = null === $value ? '' : (string) $value;
		if ( $amount && preg_match( '/\A-?[0-9]+(?:\.[0-9]+)?\z/D', $text ) ) { return $text; }
		// Guard any leading whitespace/control/format character, including Unicode disguises.
		$unsafe = preg_match( '/\A[=+@\-\s\p{Z}\p{C}]/u', $text );
		return false === $unsafe || 1 === $unsafe ? "'" . $text : $text;
	}

	/** Frozen Preview pages only. At most 100 row copies; no result/history or catalog queries. */
	public static function write_preview_csv( $stream, Change_Plan $plan ): void {
		$data = $plan->data();
		$exported = gmdate( 'Y-m-d\TH:i:s\Z' );
		$header = array( 'plan_id', 'product_id', 'product_name_at_preview', 'sku_at_preview', 'price_field', 'expected_price', 'planned_price', 'delta', 'percent_delta', 'result', 'reason', 'currency', 'exported_at_utc', 'plan_hash', 'plan_status', 'warnings', 'plan_warnings', 'regular_price_at_preview', 'stored_price_at_preview', 'plan_blockers' );
		if ( false === fputcsv( $stream, $header, ',', '"', '' ) ) { throw new \RuntimeException( 'CSV unavailable' ); }
		$offset = 0;
		do {
			$page = $plan->preview_page( $offset, 100 );
			foreach ( $page['items'] as $item ) {
				$reasons = $item['blockers'];
				if ( null !== $item['eligibility']['reason'] ) { array_unshift( $reasons, $item['eligibility']['reason'] ); }
				if ( 'BLOCKED' === $data['status'] && 'CHANGING' === $item['result'] ) { array_unshift( $reasons, 'plan_policy_blocked' ); }
				$row = array(
					$data['plan_id'], $item['product_id'], $item['snapshot']['name'] ?? '', $item['snapshot']['sku'] ?? '', $plan->price_field(),
					$item['expected_regular_price'], $item['planned_regular_price'], $item['absolute_delta'], $item['percentage_delta']['display'] ?? null,
					$item['result'], implode( ';', $reasons ), $data['store']['currency'], $exported, $plan->hash(), $data['status'],
					implode( ';', $item['warnings'] ), Plan_Hasher::canonical_json( array( 'selection' => $data['selection']['warnings'], 'policy' => $data['policy_result']['warnings'] ) ),
					$item['snapshot']['regular_price'], $item['stored_price'], Plan_Hasher::canonical_json( $data['policy_result']['blockers'] ),
				);
				foreach ( $row as $column => $value ) { $row[$column] = self::preview_csv_cell( $value, in_array( $column, array( 5, 6, 7, 8, 17, 18 ), true ) ); }
				if ( false === fputcsv( $stream, $row, ',', '"', '' ) ) { throw new \RuntimeException( 'CSV unavailable' ); }
			}
			$offset = $page['next_offset'];
		} while ( null !== $offset );
	}

	public static function handle_export_preview(): void {
		$result = self::export_preview( self::post_input(), self::request_method() );
		if ( 'OK' !== $result['status'] ) { wp_die( esc_html( self::reason_message( $result['reason'] ) ), '', array( 'response' => 'FORBIDDEN' === $result['status'] ? 403 : 400 ) ); }
		// Spill to disk beyond 1 MiB and complete validation before sending any private bytes.
		$stream = fopen( 'php://temp/maxmemory:1048576', 'w+' );
		try {
			if ( false === $stream ) { throw new \RuntimeException( 'CSV unavailable' ); }
			self::write_preview_csv( $stream, $result['plan'] );
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Generated response stream, not a filesystem operation.
			if ( is_resource( $stream ) ) { fclose( $stream ); }
			wp_die( esc_html__( 'Saved export evidence is unavailable. No products were changed.', 'writeleash' ), '', array( 'response' => 409 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="writeleash-preview-' . $result['job']['public_id'] . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		rewind( $stream );
		fpassthru( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Generated response stream, not a filesystem operation.
		fclose( $stream );
		exit;
	}

	private static function render_export_form( array $job ): void {
		if ( ! self::can_mutate() ) { return; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_EXPORT ) . '"><input type="hidden" name="job" value="' . esc_attr( $job['public_id'] ) . '">';
		wp_nonce_field( self::ACTION_EXPORT . '_' . $job['public_id'] );
		echo '<p><button type="submit" class="button button-link">' . esc_html__( 'Download job CSV', 'writeleash' ) . '</button></p></form>';
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
			wp_die( esc_html( __( 'Saved export evidence is unavailable. No products were changed.', 'writeleash' ) ), '', array( 'response' => 409 ) );
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
		echo esc_html( __( 'Preview bulk regular or sale prices before they happen. Run them safely. If the price or a checked setting changed after review, WriteLeash leaves the newer value alone. Undo changes that are still safe to restore.', 'writeleash' ) );
		echo '</p>';
		echo '<p>';
		echo esc_html( __( 'Change the stored price of published simple products and variations of published variable products in the base store currency. Variations can be selected as a whole product or individually. Sale dates, stock, orders and subscriptions are not changed.', 'writeleash' ) );
		echo '</p>';
		echo '<p>';

		echo esc_html( sprintf( /* translators: %d: maximum selected products per new job. */ __( 'Up to %d selected products per new job in the tested configuration. Planned prices are not guaranteed shopper prices: taxes, currency settings, dynamic pricing and later edits still apply. You can close this page at any time; saved progress appears again when you return.', 'writeleash' ), Free_Support_Contract::MAX_JOB_PRODUCTS ) );
		echo '</p>';
		if ( ! self::can_mutate() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( __( 'Your role can view this page but cannot preview or apply bulk price changes.', 'writeleash' ) ) . '</p></div>';
		}
		echo '<h2>' . esc_html__( 'New price change', 'writeleash' ) . '</h2>';
		self::render_selector_form( $form );
		echo '<h2>' . esc_html__( 'Recent jobs', 'writeleash' ) . '</h2>';
		self::render_recent_jobs();
		echo '<p><a class="button" href="' . esc_url( self::page_url( 'history' ) ) . '">' . esc_html( __( 'Open full history', 'writeleash' ) ) . '</a></p>';
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

	/**
	 * #234 optional inclusive price-range filter controls. Native inputs
	 * only: the no-JavaScript form submits the same fields, and the server
	 * re-applies the filter at Preview time. Values come from retained
	 * inputs, so a validation failure redisplays exactly what was entered.
	 */
	private static function render_price_range( array $values ): void {
		$currency = '';
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			try { $candidate = (string) get_woocommerce_currency(); }
			catch ( \Throwable $error ) { $candidate = ''; }
			if ( preg_match( '/\A[A-Z]{3}\z/', $candidate ) ) { $currency = $candidate; }
		}
		$unit = '' !== $currency ? ' (' . $currency . ')' : '';
		$basis = '' !== $values['range_basis'] ? $values['range_basis'] : $values['price_field'];
		echo '<div id="writeleash-free-price-range">';
		echo '<p><input id="writeleash-free-range-enabled" name="range_enabled" type="checkbox" value="1"' . checked( $values['range_enabled'], '1', false ) . ' aria-describedby="writeleash-free-range-help"> <label for="writeleash-free-range-enabled">' . esc_html__( 'Only change products in a price range', 'writeleash' ) . '</label></p>';
		echo '<p id="writeleash-free-range-help">' . esc_html__( 'Optional. When enabled, Preview includes only products whose stored price falls within the inclusive range: the minimum and maximum both count as matches. Each variation is judged by its own price, not its parent’s. Products without a stored sale price never match a sale-price range. Use unsigned numbers with a dot for decimals; leave out currency symbols.', 'writeleash' ) . '</p>';
		echo '<p><label for="writeleash-free-range-basis">' . esc_html__( 'Compare prices using', 'writeleash' ) . '</label><br><select id="writeleash-free-range-basis" name="range_basis" aria-describedby="writeleash-free-range-help">';
		foreach ( array( Price_Operation::FIELD_REGULAR => __( 'Regular price', 'writeleash' ), Price_Operation::FIELD_SALE => __( 'Sale price', 'writeleash' ) ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $basis, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		/* translators: %s: ISO currency code in parentheses, or empty. */
		self::selector_field( 'range_min', sprintf( __( 'Minimum price%s', 'writeleash' ), $unit ), 'text', $values['range_min'], 'writeleash-free-range-help' );
		/* translators: %s: ISO currency code in parentheses, or empty. */
		self::selector_field( 'range_max', sprintf( __( 'Maximum price%s', 'writeleash' ), $unit ), 'text', $values['range_max'], 'writeleash-free-range-help' );
		echo '</div>';
	}

	private static function render_selector_form( array $values = array(), ?array $recovery = null ): void {
		if ( ! self::can_mutate() ) { return; }
		$defaults = array( 'selector' => 'ids', 'ids' => '', 'sku' => '', 'category' => '', 'include_subcategories' => '', 'range_enabled' => '', 'range_min' => '', 'range_max' => '', 'range_basis' => '', 'operation' => Price_Operation::SET, 'price_field' => Price_Operation::FIELD_REGULAR, 'amount' => '', 'max_products' => (string) Free_Support_Contract::MAX_JOB_PRODUCTS, 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '20', 'product_search' => '', 'category_search' => '', 'product_page' => '1', 'category_page' => '1' );
		$values = array_merge( $defaults, $values );
		$values['ending'] = $values['ending'] ?? 'default';
		if ( null === $recovery ) {
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
			echo '<fieldset><legend>' . esc_html__( '1. Select products', 'writeleash' ) . '</legend>';

			echo '<p id="writeleash-free-selector-help">' . esc_html( sprintf( /* translators: %d: maximum selected products per new job. */ __( 'Choose specific products or one category. Categories include direct members only by default; subcategories are not included unless you enable Include subcategories. Search matches are not automatically selected and do not guarantee eligibility. Preview checks every selected product. Maximum %d selected products.', 'writeleash' ), Free_Support_Contract::MAX_JOB_PRODUCTS ) ) . '</p>';
			echo '<p><label for="writeleash-free-selector">' . esc_html__( 'Selection method', 'writeleash' ) . '</label><br><select id="writeleash-free-selector" name="selector" aria-describedby="writeleash-free-selector-help">';
			foreach ( array( 'ids' => __( 'Choose products by name or SKU', 'writeleash' ), 'category' => __( 'Product category', 'writeleash' ), 'sku' => __( 'One exact SKU', 'writeleash' ), 'manual_ids' => __( 'Advanced: manual product IDs', 'writeleash' ) ) as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $values['selector'], $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></p><p id="writeleash-free-discovery-status" role="status" aria-live="polite">' . esc_html__( 'Search and choose products. If live suggestions are unavailable, use the search without live suggestions.', 'writeleash' ) . '</p>';
			echo '<div id="writeleash-free-product-picker">';
			echo '<details id="writeleash-free-products-fallback" open><summary>' . esc_html__( 'Search products without live suggestions', 'writeleash' ) . '</summary>';
			self::selector_field( 'product_search', __( 'Product name or partial SKU', 'writeleash' ), 'search', $values['product_search'], 'writeleash-free-selector-help' );
			echo '<input type="hidden" name="product_page" value="' . esc_attr( $values['product_page'] ) . '">';
			self::selection_button( 'search-products', __( 'Search products', 'writeleash' ) );
			if ( $matches['more'] ) { self::selection_button( 'next-products', __( 'More product matches', 'writeleash' ) ); }
			if ( '' !== $values['product_search'] && ! $matches['results'] ) { echo '<p>' . esc_html__( 'No matches on this page. Try another product name or SKU.', 'writeleash' ) . '</p>'; }
			if ( ! empty( $matches['capped'] ) ) { echo '<p>' . esc_html__( 'Search limit reached. Use a more specific name or SKU.', 'writeleash' ) . '</p>'; }
			echo '<p>' . esc_html__( 'Use Ctrl/Command to select several matches below, then Update selected products. Selected products stay on subsequent search pages.', 'writeleash' ) . '</p>';
			self::selection_button( 'update-products', __( 'Update selected products', 'writeleash' ) );
			echo '</details>';
			echo '<p><label for="writeleash-free-products">' . esc_html__( 'Choose products', 'writeleash' ) . '</label><br><select id="writeleash-free-products" name="product_ids[]" multiple size="6" style="width:100%;max-width:600px" aria-describedby="writeleash-free-products-help">';
			$options = $selected;
			foreach ( $matches['results'] as $item ) { $options[(int) $item['id']] = $item; }
			foreach ( $options as $id => $item ) { echo '<option value="' . esc_attr( (string) $id ) . '"' . ( isset( $selected[$id] ) ? ' selected' : '' ) . '>' . esc_html( $item['text'] ) . '</option>'; }
			echo '</select></p><p id="writeleash-free-products-help">' . esc_html__( 'Check the selected products below. Preview checks their eligibility before any prices change.', 'writeleash' ) . '</p>';
			echo '<h3>' . esc_html__( 'Selected products', 'writeleash' ) . '</h3><ul id="writeleash-free-selected">';

			foreach ( $selected as $id => $item ) { echo '<li>' . esc_html( $item['text'] ) . ' '; self::selection_button( 'remove:' . $id, __( 'Remove', 'writeleash' ), sprintf( /* translators: %s: product description. */ __( 'Remove %s', 'writeleash' ), $item['text'] ) ); echo '</li>'; }
			if ( ! $selected ) { echo '<li>' . esc_html__( 'No products selected. Search and choose products to add them.', 'writeleash' ) . '</li>'; }
			echo '</ul><button id="writeleash-free-clear" class="button" type="submit" name="selection_action" value="clear-products" formaction="' . esc_url( self::page_url() ) . '" formnovalidate>' . esc_html__( 'Clear selected products', 'writeleash' ) . '</button>';
			echo '</div><div id="writeleash-free-category-picker"><details id="writeleash-free-categories-fallback" open><summary>' . esc_html__( 'Search categories without live suggestions', 'writeleash' ) . '</summary>';
			self::selector_field( 'category_search', __( 'Category name', 'writeleash' ), 'search', $values['category_search'], 'writeleash-free-selector-help' );
			echo '<input type="hidden" name="category_page" value="' . esc_attr( $values['category_page'] ) . '">';
			self::selection_button( 'search-categories', __( 'Search categories', 'writeleash' ) );
			if ( $categories['more'] ) { self::selection_button( 'next-categories', __( 'More category matches', 'writeleash' ) ); }
			if ( ! empty( $categories['capped'] ) ) { echo '<p>' . esc_html__( 'Search limit reached. Use a more specific category name.', 'writeleash' ) . '</p>'; }
			if ( ! $categories['results'] ) { echo '<p>' . esc_html__( 'No categories match this page. Try another category name.', 'writeleash' ) . '</p>'; }
			echo '</details>';
			echo '<p><label for="writeleash-free-category">' . esc_html__( 'Product category', 'writeleash' ) . '</label><br><select id="writeleash-free-category" name="category" style="width:100%;max-width:600px" aria-describedby="writeleash-free-selector-help"><option value="">' . esc_html__( 'Choose a category', 'writeleash' ) . '</option>';
			$category_options = array();
			foreach ( $categories['results'] as $item ) { $category_options[$item['id']] = $item; }
			if ( preg_match( '/\A[0-9]{1,10}\z/', $values['category'] ) ) {
				try { $item = Product_Discovery::category( (int) $values['category'] ); if ( $item ) { $category_options[$item['id']] = $item; } } catch ( \Throwable $error ) { /* Dependency/permission notice above; no guessed label. */ }
			}
			foreach ( $category_options as $id => $item ) { echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $values['category'], (string) $id, false ) . '>' . esc_html( $item['text'] ) . '</option>'; }
			echo '</select></p><p><input id="writeleash-free-include-subcategories" name="include_subcategories" type="checkbox" value="1"' . checked( $values['include_subcategories'], '1', false ) . '> <label for="writeleash-free-include-subcategories">' . esc_html__( 'Include subcategories', 'writeleash' ) . '</label></p><p>' . esc_html__( 'When enabled, products in all nested subcategories are included once. Variable parents expand to their variations.', 'writeleash' ) . '</p></div>';
			echo '<p>'; self::selection_button( 'count-targets', __( 'Check selection count', 'writeleash' ) ); echo '</p><p id="writeleash-free-selection-count" role="status" aria-live="polite">';
			$count = $values['selection_count'] ?? null;
			if ( is_array( $count ) && ! empty( $count['over_limit'] ) ) {

				echo esc_html( sprintf( /* translators: %d: maximum selected products per new job. */ __( 'This selection exceeds the supported limit of %d price targets. Choose a smaller selection before Preview.', 'writeleash' ), Free_Support_Contract::MAX_JOB_PRODUCTS ) );
			} elseif ( is_array( $count ) && isset( $count['selected'], $count['unreadable'], $count['missing'] ) ) {
				echo esc_html( self::selection_count_message( $count ) );
			} else { echo esc_html( __( 'Check the number of price targets before Preview, including variations.', 'writeleash' ) ); }
			echo '</p><p>' . esc_html__( 'Counts are informational and can change. Preview resolves the selection again and freezes the exact products for your review; no count authorizes a price change.', 'writeleash' ) . '</p>';
			echo '<details id="writeleash-free-advanced-selection"' . ( in_array( $values['selector'], array( 'sku', 'manual_ids' ), true ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Exact SKU or manual product IDs', 'writeleash' ) . '</summary><p>' . esc_html__( 'Enter one complete SKU or comma-separated product IDs for the selection method chosen above.', 'writeleash' ) . '</p>';
			self::selector_field( 'ids', __( 'Explicit product IDs (advanced), e.g. 12,34,56', 'writeleash' ), 'text', $values['ids'], 'writeleash-free-selector-help' );
			self::selector_field( 'sku', __( 'One exact SKU', 'writeleash' ), 'text', $values['sku'], 'writeleash-free-selector-help' );
			echo '</details>';
			self::render_price_range( $values );
			echo '</fieldset>';
		} else {
			echo '<form id="writeleash-conflict-recovery-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_PREVIEW ) . '"><input type="hidden" name="source_job" value="' . esc_attr( $recovery['job']['public_id'] ) . '">';
			wp_nonce_field( self::ACTION_PREVIEW );
			self::render_recovery_rows( $recovery, $values );
		}
		echo '<fieldset><legend>' . esc_html__( '2. Choose the price change', 'writeleash' ) . '</legend><p><label for="writeleash-free-price-field">' . esc_html__( 'Price to change', 'writeleash' ) . '</label><br><select id="writeleash-free-price-field" name="price_field" aria-describedby="writeleash-free-price-field-help">';
		foreach ( array( Price_Operation::FIELD_REGULAR => __( 'Regular price', 'writeleash' ), Price_Operation::FIELD_SALE => __( 'Sale price', 'writeleash' ) ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $values['price_field'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p id="writeleash-free-price-field-help">' . esc_html( __( 'Regular price is the everyday price. Sale price is the discounted price during a sale. WriteLeash changes only the price you choose and keeps the other one.', 'writeleash' ) ) . '</p><p><label for="writeleash-free-operation">' . esc_html__( 'Operation', 'writeleash' ) . '</label><br><select id="writeleash-free-operation" name="operation">';
		foreach ( array( Price_Operation::SET => __( 'Set an exact price', 'writeleash' ), Price_Operation::INCREASE_FIXED => __( 'Increase by fixed amount', 'writeleash' ), Price_Operation::DECREASE_FIXED => __( 'Decrease by fixed amount', 'writeleash' ), Price_Operation::INCREASE_PERCENT => __( 'Increase by percent', 'writeleash' ), Price_Operation::DECREASE_PERCENT => __( 'Decrease by percent', 'writeleash' ), Price_Operation::CLEAR_SALE => __( 'Clear Sale Price', 'writeleash' ), Price_Operation::SALE_DISCOUNT_PERCENT => __( 'Sale discount from Regular Price (%)', 'writeleash' ) ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $values['operation'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		$amount_label = Price_Operation::CLEAR_SALE === $values['operation'] ? __( 'No amount — leave blank', 'writeleash' ) : ( Price_Operation::SET === $values['operation'] ? __( 'New price', 'writeleash' ) : ( in_array( $values['operation'], array( Price_Operation::INCREASE_PERCENT, Price_Operation::DECREASE_PERCENT, Price_Operation::SALE_DISCOUNT_PERCENT ), true ) ? __( 'Percentage (e.g. 8 for 8%)', 'writeleash' ) : ( Price_Operation::INCREASE_FIXED === $values['operation'] ? __( 'Amount to increase by', 'writeleash' ) : __( 'Amount to decrease by', 'writeleash' ) ) ) );
		echo '<p><label for="writeleash-free-ending">' . esc_html__( 'Price Ending (optional)', 'writeleash' ) . '</label><br><select id="writeleash-free-ending" name="ending" aria-describedby="writeleash-free-ending-help">';
		foreach ( array( 'default' => __( 'Unchanged / Default', 'writeleash' ), '99' => __( 'End in .99', 'writeleash' ), '95' => __( 'End in .95', 'writeleash' ), '90' => __( 'End in .90', 'writeleash' ), 'whole' => __( 'Whole-number price', 'writeleash' ) ) as $ending => $label ) {
			echo '<option value="' . esc_attr( $ending ) . '"' . selected( $values['ending'], $ending, false ) . ( Price_Operation::ending_supported( $ending, wc_get_price_decimals() ) ? '' : ' disabled' ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p id="writeleash-free-ending-help" class="description">' . esc_html__( 'Nearest nonnegative matching price; exact ties go upward. Below the first ending, use 0.99, 0.95 or 0.90; whole prices may become zero. Default keeps existing calculations. .99/.95 need two decimal places; .90 needs one. Clear Sale ignores endings and stays blank. Preview checks the final rounded price against all safety limits.', 'writeleash' ) . '</p>';
		// The server requires numeric input for every operation except Clear.
		// Optional HTML input also supports choosing Clear without JavaScript.
		self::selector_field( 'amount', $amount_label, 'text', $values['amount'], 'writeleash-free-amount-help' );
		echo '<p id="writeleash-free-amount-help" class="description">' . esc_html__( 'Enter a positive number or zero. Use a dot for decimals; leave out currency symbols and the % sign.', 'writeleash' ) . '</p>';
		echo '<p id="writeleash-free-sale-operation-help" class="description">' . esc_html__( 'Choose Sale price for Clear or Sale discount. Clear makes Sale Price blank and preserves sale schedules; safety caps check the return to Regular Price. Sale discount requires an explicit 0–100 percentage (up to six decimal places), calculated from each product’s own reviewed Regular Price. A changed Regular Price conflicts at Apply; the approved target is never recalculated. Zero or a sale at Regular Price still must pass eligibility and safety checks.', 'writeleash' ) . '</p>';
		$custom_limits = array_diff_assoc( array_intersect_key( $values, array_flip( array( 'max_products', 'max_increase', 'max_decrease', 'warning_threshold' ) ) ), $defaults ) || ! empty( $values['block_zero'] );
		echo '</fieldset><details id="writeleash-free-safety-limits"' . ( $custom_limits ? ' open' : '' ) . '><summary>' . esc_html__( 'Safety limits (optional)', 'writeleash' ) . '</summary><fieldset class="writeleash-safety"><legend>' . esc_html__( 'Safety limits', 'writeleash' ) . '</legend><p class="writeleash-full-width">' . esc_html__( 'A change beyond these limits blocks the whole preview.', 'writeleash' ) . '</p>';
		$maximum = Free_Support_Contract::MAX_JOB_PRODUCTS;

		self::selector_field( 'max_products', sprintf( /* translators: %d: maximum products allowed. */ __( 'Maximum changing products (0-%d)', 'writeleash' ), $maximum ), 'number', $values['max_products'], 'writeleash-free-operation', true, 0, $maximum );
		foreach ( array( 'max_increase' => __( 'Maximum increase percent', 'writeleash' ), 'max_decrease' => __( 'Maximum decrease percent', 'writeleash' ), 'warning_threshold' => __( 'Warning threshold percent', 'writeleash' ) ) as $name => $label ) { self::selector_field( $name, $label, 'text', $values[$name], 'writeleash-free-operation', true ); }
		echo '<p class="writeleash-full-width"><input id="writeleash-free-block-zero" name="block_zero" type="checkbox" value="1"' . checked( $values['block_zero'] ?? '', '1', false ) . '> <label for="writeleash-free-block-zero">' . esc_html__( 'Block a preview that sets any changing price to zero', 'writeleash' ) . '</label></p></fieldset></details>';
		echo '<p class="writeleash-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Preview price changes', 'writeleash' ) . '</button> <span class="description">' . esc_html( __( 'Preview does not change any prices.', 'writeleash' ) ) . '</span></p></form>';
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
			echo '<p>' . esc_html( __( 'No price changes yet. Preview your first change above to start.', 'writeleash' ) ) . '</p>';
			return;
		}
		$user_id = get_current_user_id();
		try {
			// Actor-scoped in SQL: the page contains only caller-visible jobs.
			$page = Undo_Repository::history_jobs( 0, 5, $user_id );
		} catch ( \Throwable $error ) {
			echo '<p>' . esc_html( __( 'History is unavailable right now. Reload the page to try again.', 'writeleash' ) ) . '</p>';
			return;
		}
		self::render_history_table( $page['jobs'] );
	}

	private static function reviewable( array $job ): bool {
		return in_array( $job['status'], array( Job_State::PLANNED, Job_State::BLOCKED ), true );
	}

	private static function job_action_link( array $job, bool $primary = false ): void {
		$label = Job_State::PLANNED === $job['status'] ? __( 'Continue review', 'writeleash' ) : ( Job_State::BLOCKED === $job['status'] ? __( 'Review blocked plan', 'writeleash' ) : __( 'Open progress/results', 'writeleash' ) );
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
			echo '<div class="notice notice-error"><p>' . esc_html( __( 'No preview is visible to your account for that identifier.', 'writeleash' ) ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( __( 'Back to bulk prices', 'writeleash' ) ) . '</a></p>';
			return;
		}
		$job = self::load_job_for_view( $public_id );
		if ( null === $job ) {
			echo '<div class="notice notice-error"><p>' . esc_html( __( 'No preview is visible to your account for that identifier.', 'writeleash' ) ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( __( 'Back to bulk prices', 'writeleash' ) ) . '</a></p>';
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
		if ( isset( $data['source_job'] ) ) {
			echo '<p>' . esc_html__( 'Fresh Preview from chosen Apply conflicts. Original results stay unchanged.', 'writeleash' ) . '';
			if ( null !== self::load_job_for_view( $data['source_job'] ) ) { echo ' <a href="' . esc_url( self::page_url( 'job', $data['source_job'] ) ) . '">' . esc_html__( 'View original results', 'writeleash' ) . '</a>'; }
			echo '</p>';
		}
		$summary = $plan->summary();
		if ( $summary['selected'] > Free_Support_Contract::MAX_JOB_PRODUCTS ) {
			if ( self::is_legacy_oversized_job( $job, $summary['selected'] ) ) {
				self::render_legacy_oversize_warning();
				echo '<p><a href="' . esc_url( self::page_url( 'job', $job['public_id'] ) ) . '">' . esc_html( __( 'Open progress and available Undo', 'writeleash' ) ) . '</a></p>';
			} else {
				echo '<div class="notice notice-error" role="alert"><p>' . esc_html( self::reason_message( 'supported_job_limit_exceeded', $summary['selected'] ) ) . '</p></div>';
			}
			return;
		}
		$blocked = 'BLOCKED' === $data['status'];
		echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( __( 'Back to bulk prices', 'writeleash' ) ) . '</a></p>';
		echo '<h2>' . esc_html( __( 'Review price change', 'writeleash' ) ) . '</h2><div class="writeleash-summary">';
		echo '<p><strong>' . esc_html( self::task_description( $data ) ) . '</strong></p>';
		self::support_details( $data['plan_id'] . ' · ' . $plan->hash() );
		if ( 'CATEGORY' === ( $data['selection']['type'] ?? '' ) ) {
			echo '<p>' . esc_html( ( ! empty( $data['selection']['include_children'] ) ? __( 'Category scope: direct members and all nested subcategories. This preview freezes the reviewed product IDs, including expanded variations.', 'writeleash' ) : __( 'Category scope: direct members only; subcategories are not included. This preview freezes the reviewed product IDs, including expanded variations.', 'writeleash' ) ) ) . '</p>';
		}
		$range_context = $plan->price_range_context();
		if ( is_array( $range_context ) && ! empty( $range_context['filter']['enabled'] ) ) {
			echo '<p>' . esc_html( self::price_range_summary( $range_context, $data['store'] ) ) . '</p>';
		}

		echo '<p>' . esc_html( sprintf( /* translators: 1: selected products, 2: translated outcome counts. */ _n( 'Selected %1$d product: %2$s.', 'Selected %1$d products: %2$s.', $summary['selected'], 'writeleash' ), $summary['selected'], implode( ' · ', array( self::count_copy( 'changing', $summary['changing'] ), self::count_copy( 'unchanged', $summary['unchanged'] ), self::count_copy( 'skipped', $summary['unsupported'] ) ) ) ) ) . '</p>';
		$extra_counts = self::preview_extra_counts( $data['items'] );
		echo '<p>' . esc_html( implode( ' · ', array( self::count_copy( 'large_increase', $extra_counts['large_increase'] ), self::count_copy( 'large_decrease', $extra_counts['large_decrease'] ), self::count_copy( 'zero_target', $extra_counts['zero_target'] ), self::count_copy( 'warning', $summary['warning_items'] ) ) ) . '.' ) . '</p></div>';
		if ( $blocked ) {
			echo '<div class="notice notice-error" role="alert"><p><strong>' . esc_html( __( 'This plan cannot be executed.', 'writeleash' ) ) . '</strong>: ' . esc_html__( 'Your safety limits block every changing product. You cannot approve this preview; build a new one with different settings.', 'writeleash' ) . '</p>';
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

				echo '<p>' . esc_html( sprintf( /* translators: 1: displayed products, 2: additional blocked products. */ _n( 'Showing the first %1$d blocked products in this summary. Each product page below shows its own reason; %2$d more blocked product is not listed here.', 'Showing the first %1$d blocked products in this summary. Each product page below shows its own reason; %2$d more blocked products are not listed here.', count( $blocked_ids ) - self::PREVIEW_PAGE_SIZE, 'writeleash' ), self::PREVIEW_PAGE_SIZE, count( $blocked_ids ) - self::PREVIEW_PAGE_SIZE ) ) . '</p>';
			}
			echo '</div>';
		}
		$limit = self::PREVIEW_PAGE_SIZE;
		try {
			$page = $plan->preview_page( $offset, $limit );
		} catch ( \Throwable $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( __( 'Invalid preview page; use a nonnegative offset.', 'writeleash' ) ) . '</p></div>';
			return;
		}
		$field = $plan->price_field();
		$field_label = Price_Operation::label( $field );

		echo '<p>' . esc_html( ( Price_Operation::FIELD_SALE === $field ? sprintf( /* translators: %s: translated price-field name. */ __( 'Price to change: %s. The regular price is the sale baseline and is preserved.', 'writeleash' ), $field_label ) : sprintf( /* translators: %s: translated price-field name. */ __( 'Price to change: %s. The sale price and schedule are preserved.', 'writeleash' ), $field_label ) ) ) . '</p>';
		echo '<div class="writeleash-table-scroll" role="region" aria-label="' . esc_attr__( 'Product prices and outcomes', 'writeleash' ) . '" tabindex="0"><table class="widefat striped writeleash-prices"><thead><tr><th scope="col">' . esc_html( __( 'Product', 'writeleash' ) ) . '</th><th scope="col">' . esc_html( sprintf( /* translators: %s: translated price-field name. */ __( '%s before', 'writeleash' ), $field_label ) ) . '</th><th scope="col">' . esc_html( sprintf( /* translators: %s: translated price-field name. */ __( '%s after', 'writeleash' ), $field_label ) ) . '</th><th scope="col">' . esc_html( __( 'Delta', 'writeleash' ) ) . '</th><th scope="col">' . esc_html( __( 'Change %', 'writeleash' ) ) . '</th><th scope="col">' . esc_html( __( 'Shoppers would pay after Apply', 'writeleash' ) ) . '</th><th scope="col">' . esc_html( __( 'What will happen', 'writeleash' ) ) . '</th></tr></thead><tbody>';
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
			$ratio_text = ( is_array( $ratio ) && isset( $ratio['display'] ) && is_string( $ratio['display'] ) ) ? self::percentage_display( $ratio['display'], $ratio['numerator'] ?? null ) : __( 'Unavailable', 'writeleash' );
			echo '<tr><td>'; self::render_identity( $item ); echo '</td>';
			echo '<td>' . esc_html( self::money_display( $item['stored_price'], $data['store'], false, $field ) ) . '</td>';
			echo '<td>' . esc_html( self::money_display( $item['planned_regular_price'], $data['store'], false, $field ) ) . '</td>';
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
		self::render_preview_export_form( $job );
		echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Create a new preview', 'writeleash' ) . '</a> ' . esc_html( __( 'Changing the selection or price settings creates a new preview; this saved preview stays unchanged.', 'writeleash' ) ) . '</p>';
		if ( ! $blocked && Job_State::PLANNED === $job['status'] && self::can_mutate() ) {
			echo '<h2>' . esc_html( __( 'Approve this preview', 'writeleash' ) ) . '</h2>';
			echo '<p>' . esc_html( __( 'Approving applies exactly the products and prices shown above. Planned prices are not guaranteed shopper prices. If you change any setting, build a new preview.', 'writeleash' ) ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_APPROVE ) . '">';
			echo '<input type="hidden" name="job" value="' . esc_attr( $job['public_id'] ) . '">';
			wp_nonce_field( self::ACTION_APPROVE . '_' . $job['plan_id'] );
			echo '<p><button type="submit" class="button button-primary">' . esc_html( __( 'Approve and apply', 'writeleash' ) ) . '</button></p></form>';
		} elseif ( ! $blocked ) {

			echo '<p>' . esc_html( sprintf( /* translators: %s: translated job status. */ __( 'Current status: %s.', 'writeleash' ), self::job_label( $job['status'] ) ) . ' ' ) . '<a href="' . esc_url( self::page_url( 'job', $job['public_id'] ) ) . '">' . esc_html( __( 'View progress and results', 'writeleash' ) ) . '</a></p>';
		}
	}

	private static function render_pager( string $view, string $public_id, int $offset, int $limit, $next_offset, string $filter = '' ): void {
		echo '<p>';
		if ( $offset > 0 ) {
			$prev = max( 0, $offset - $limit );
			echo '<a class="button" href="' . esc_url( self::page_url( $view, $public_id, $prev, $filter ) ) . '">' . esc_html( __( 'Previous page', 'writeleash' ) ) . '</a> ';
		} else {
			echo '<button type="button" class="button" disabled aria-disabled="true">' . esc_html( __( 'Previous page', 'writeleash' ) ) . '</button> ';
		}
		if ( null !== $next_offset ) {
			echo '<a class="button" href="' . esc_url( self::page_url( $view, $public_id, (int) $next_offset, $filter ) ) . '">' . esc_html( __( 'Next page', 'writeleash' ) ) . '</a>';
		} else {
			echo '<button type="button" class="button" disabled aria-disabled="true">' . esc_html( __( 'Next page', 'writeleash' ) ) . '</button>';
		}
		echo '</p>';
	}

	/**
	 * Shared eligibility-context sentences for server rows and polled rows.
	 * Facts use snapshot semantics; the literals live exactly once, here.
	 */
	private static function observation_context( array $facts, string $field ): string {
		if ( ! $facts['simple'] && ! $facts['variation'] ) { return __( 'At page load, this product is no longer a supported core simple product or variation.', 'writeleash' ); }
		if ( $facts['variation'] && ( 'publish' !== $facts['parent_status'] || ! $facts['parent_variable'] ) ) { return __( 'At page load, this variation’s parent is no longer a published core variable product.', 'writeleash' ); }
		if ( 'publish' !== $facts['status'] ) { return __( 'At page load, this product is no longer published.', 'writeleash' ); }
		if ( Price_Operation::FIELD_REGULAR === $field && ( '' !== $facts['sale_price'] || null !== $facts['sale_from'] || null !== $facts['sale_to'] ) ) { return __( 'At page load, this product has a sale price or schedule; a matching regular price alone does not authorize overwriting it.', 'writeleash' ); }
		return '';
	}

	/**
	 * Poll-safe observation context: raw post/postmeta reads only, never a
	 * WooCommerce product object, so background polls cannot trigger live
	 * product reads. Sale/status/type facts are exact; exotic Woo extension
	 * subclasses that keep core type terms are the documented boundary (the
	 * server page-load read still reports those; labels, reasons, counts and
	 * next-action links always come from durable state in both paths).
	 */
	private static function poll_observation_context( int $product_id, string $field ): string {
		try {
			$post = get_post( $product_id );
			if ( ! is_object( $post ) || ! isset( $post->post_type, $post->post_status ) ) { return __( 'Current product details are unavailable.', 'writeleash' ); }
			$slugs = static function ( $id ) {
				$terms = get_the_terms( $id, 'product_type' );
				if ( ! is_array( $terms ) ) { return array(); }
				$slugs = array();
				foreach ( $terms as $term ) { if ( is_object( $term ) && isset( $term->slug ) ) { $slugs[] = (string) $term->slug; } }
				return $slugs;
			};
			$is_variation_post = 'product_variation' === $post->post_type;
			$parent_status = null; $parent_variable = false;
			if ( $is_variation_post ) {
				$parent = (int) ( $post->post_parent ?? 0 ) > 0 ? get_post( (int) $post->post_parent ) : null;
				$parent_status = is_object( $parent ) && isset( $parent->post_status ) ? (string) $parent->post_status : '';
				$parent_slugs = $parent ? $slugs( (int) $parent->ID ) : array();
				$parent_variable = $parent && 'product' === ( $parent->post_type ?? '' ) && in_array( 'variable', $parent_slugs, true );
			}
			$own_slugs = $slugs( $product_id );
			$sale_price = (string) get_post_meta( $product_id, '_sale_price', true );
			$sale_from_raw = get_post_meta( $product_id, '_sale_price_dates_from', true );
			$sale_to_raw = get_post_meta( $product_id, '_sale_price_dates_to', true );
			return self::observation_context(
				array(
					'simple' => 'product' === $post->post_type && in_array( 'simple', $own_slugs, true ),
					'variation' => $is_variation_post && in_array( 'variation', $own_slugs, true ),
					'status' => (string) $post->post_status,
					'parent_status' => $parent_status,
					'parent_variable' => $parent_variable,
					'sale_price' => $sale_price,
					'sale_from' => '' === $sale_from_raw ? null : $sale_from_raw,
					'sale_to' => '' === $sale_to_raw ? null : $sale_to_raw,
				),
				$field
			);
		} catch ( \Throwable $error ) { return __( 'Current product details are unavailable.', 'writeleash' ); }
	}

	/**
	 * Page-row display only: a new Woo edit-context read after targeted cache
	 * eviction, using the same decimal interpretation as price verification.
	 * Unlike execution/recovery invalidation, this does not delete transients
	 * or touch product, journal, job or Undo storage. Never falls back to a plan.
	 */
	private static function product_observation( int $product_id, string $field ): array {
		$unavailable = array( 'price' => __( 'Unavailable', 'writeleash' ), 'context' => __( 'Current product details are unavailable.', 'writeleash' ) );
		try {
			if ( ! current_user_can( 'edit_post', $product_id ) || ! empty( $GLOBALS['_wp_suspend_cache_invalidation'] ) ) { return $unavailable; }
			$product = Product_Price_Snapshot::fresh_product( $product_id );
			if ( ! $product instanceof \WC_Product || $product->get_id() !== $product_id ) { return $unavailable; }
		$snapshot = Product_Price_Snapshot::read( $product_id, $product )->data();
			$context = self::observation_context(
				array(
					'simple' => ! empty( $snapshot['core_simple'] ),
					'variation' => ! empty( $snapshot['core_variation'] ),
					'status' => (string) $snapshot['status'],
					'parent_status' => $snapshot['parent_status'] ?? null,
					'parent_variable' => ! empty( $snapshot['parent_core_variable'] ),
					'sale_price' => (string) $snapshot['sale_price'],
					'sale_from' => $snapshot['sale_from'],
					'sale_to' => $snapshot['sale_to'],
				),
				$field
			);
			$price = $snapshot[ Price_Operation::meta_key( $field ) ];
			try { Price_Decimal::parse( $price ); } catch ( \Throwable $error ) { $price = __( 'Unavailable', 'writeleash' ); }
			return array( 'price' => $price, 'context' => $context );
		} catch ( \Throwable $error ) { return $unavailable; }
	}

	private static function render_job_view( string $public_id, int $offset, string $filter = '' ): void {
		if ( ! self::jobs_installed() ) {
			echo '<div class="notice notice-error"><p>' . esc_html( __( 'No job is visible to your account for that identifier.', 'writeleash' ) ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( __( 'Back to bulk prices', 'writeleash' ) ) . '</a></p>';
			return;
		}
		$job = self::load_job_for_view( $public_id );
		if ( null === $job ) {
			echo '<div class="notice notice-error"><p>' . esc_html( __( 'No job is visible to your account for that identifier.', 'writeleash' ) ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( __( 'Back to bulk prices', 'writeleash' ) ) . '</a></p>';
			return;
		}
		try {
			$observed = Job_Repository::observe( (int) $job['id'] );
			$history = Undo_Repository::history_job( (int) $job['id'] );
			$plan = Job_Repository::hydrate_plan( $job );
			$selected = $plan->summary()['selected'];
			$identities = array_column( $plan->data()['items'], null, 'product_id' );
		} catch ( \Throwable $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( __( 'Saved progress and results are unavailable right now. Reload this page to try again; if it persists, ask an administrator to check the saved records.', 'writeleash' ) ) . '</p></div>';
			return;
		}
		$counts = $observed['counts'];
		$effective = $observed['effective_status'];
		$complete_evidence = $counts['planned'] === $selected;
		echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( __( 'Back to bulk prices', 'writeleash' ) ) . '</a> ';
		echo '<a class="button" href="' . esc_url( self::page_url( 'history' ) ) . '">' . esc_html( __( 'Open history', 'writeleash' ) ) . '</a></p>';
		if ( self::reviewable( $job ) ) { self::job_action_link( $job, true ); echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Create a new preview', 'writeleash' ) . '</a></p>'; }
		echo '<div data-writeleash-progress="1" data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-job="' . esc_attr( $public_id ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::ACTION_PROGRESS . '_' . $public_id ) ) . '" data-action-url="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-offset="' . esc_attr( (string) $offset ) . '" data-filter="' . esc_attr( $filter ) . '">';
		echo '<p><a class="button" href="' . esc_url( self::page_url( 'job', $public_id, $offset, $filter ) ) . '">' . esc_html__( 'Refresh saved progress', 'writeleash' ) . '</a> <span data-progress-connection>' . esc_html__( 'Automatic updates require JavaScript. Refresh to read saved progress.', 'writeleash' ) . '</span></p><p class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true" data-progress-announcement></p>';
		echo '<h2>' . esc_html__( 'Progress and results', 'writeleash' ) . '</h2><div class="writeleash-summary"><p><strong>' . esc_html( self::task_description( $plan->data() ) ) . '</strong></p>';
		echo '<p><strong data-progress-label>' . esc_html( $complete_evidence ? self::job_label( $effective ) : __( 'Saved product outcomes unavailable; needs checking', 'writeleash' ) ) . '</strong></p>';
		if ( $complete_evidence ) { echo '<p data-progress-summary>' . esc_html( self::result_summary( $counts, $effective, true ) ) . '</p>'; }
		else { echo '<p data-progress-summary>' . esc_html( sprintf( /* translators: %d: planned products. */ _n( 'Planned %d product. Some saved product evidence is unavailable. Check this job before taking further action.', 'Planned %d products. Some saved product evidence is unavailable. Check this job before taking further action.', $selected, 'writeleash' ), $selected ) ) . '</p>'; }
		if ( in_array( $effective, array( Job_State::PAUSED, Job_State::NEEDS_REVIEW ), true ) ) { echo '<p data-progress-initial-notice>' . esc_html( self::reason_message( $observed['effective_reason'] ) ) . '</p>'; }
		echo '<div data-progress-initial-notice>'; self::support_details( $effective . ' · ' . $observed['effective_reason'] . ' · ' . $job['public_id'] . ' · pending ' . $counts['pending'] . ' · applied ' . $counts['applied'] ); echo '</div>';
		echo '</div>';
		echo '<p data-progress-notice></p>';
		if ( $observed['stalled'] ) {
			echo '<div data-progress-initial-notice class="notice notice-warning" role="alert"><p>' . esc_html( __( 'Background processing stopped making progress. Use Resume remaining products to continue; completed changes are kept.', 'writeleash' ) ) . '</p></div>';
		}
		if ( in_array( $effective, array( Job_State::COMPLETED_WITH_ISSUES, Job_State::NEEDS_REVIEW ), true ) ) {
			echo '<div data-progress-initial-notice class="notice notice-warning" role="alert"><p>' . esc_html( Job_State::NEEDS_REVIEW === $effective ? __( 'An outcome needs checking. Review uncertain products before continuing remaining work; uncertain products will not be retried.', 'writeleash' ) : __( 'The price change finished with products needing attention. Review conflicts and failed products below, then create a new preview if needed.', 'writeleash' ) ) . '</p></div>';
		}
		if ( self::is_legacy_oversized_job( $job, $selected ) ) {
			self::render_legacy_oversize_warning();
		} elseif ( $selected > Free_Support_Contract::MAX_JOB_PRODUCTS ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html( self::reason_message( 'supported_job_limit_exceeded', $selected ) ) . '</p></div>';
		}
		echo '<div data-progress-resume>';
		if ( $complete_evidence && Job_State::can_manual_run( $job['status'] ) && self::can_mutate() ) {
			if ( in_array( $effective, array( Job_State::READY, Job_State::QUEUED ), true ) && ! $observed['stalled'] ) { echo '<p data-progress-initial-notice>' . esc_html( __( 'Waiting for background processing. Use Resume remaining products to run the next step now.', 'writeleash' ) ) . '</p>'; }
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RESUME ) . '">';
			echo '<input type="hidden" name="job" value="' . esc_attr( $job['public_id'] ) . '">';
			wp_nonce_field( self::ACTION_RESUME . '_' . $job['public_id'] );
			echo '<p><button type="submit" class="button button-primary">' . esc_html( __( 'Resume remaining products', 'writeleash' ) ) . '</button> ';
			echo esc_html( __( 'Runs only the remaining products; completed changes are kept. Products changed since Preview are left alone.', 'writeleash' ) ) . '</p></form>';
		}
		echo '</div>';
		try {
			$recovery = self::recovery_source( $public_id );
			if ( $recovery['rows'] ) { echo '<p><a class="button" href="' . esc_url( self::page_url( 'recovery', $public_id ) ) . '">' . esc_html__( 'Re-preview conflicted products', 'writeleash' ) . '</a></p>'; }
		} catch ( \Throwable $error ) { /* No recovery action without verified authority and evidence. */ }
		self::render_export_form( $job );
		echo '<section class="writeleash-undo"><h2>' . esc_html( _x( 'Undo', 'merchant price workflow label', 'writeleash' ) ) . '</h2>';
		if ( $complete_evidence ) { self::render_undo_section( $job, $history, $plan->price_field() ); }
		else { echo '<p>' . esc_html__( 'Undo availability cannot be verified while product outcomes are missing. Reload this job or ask an administrator to check its saved records.', 'writeleash' ) . '</p>'; }
		echo '</section>';
		echo '<h2>' . esc_html( __( 'Products', 'writeleash' ) ) . '</h2>';
		$filters = array( '' => array( __( 'All products', 'writeleash' ), null, null ), 'conflict' => array( __( 'Conflicts', 'writeleash' ), 'CONFLICT', null ), 'attention' => array( __( 'Changes needing attention', 'writeleash' ), 'FAILED', null ), 'review' => array( __( 'Uncertain changes', 'writeleash' ), 'NEEDS_REVIEW', null ), 'undo_conflict' => array( __( 'Undo conflicts', 'writeleash' ), null, 'UNDO_CONFLICT' ), 'undo_review' => array( __( 'Uncertain restorations', 'writeleash' ), null, 'UNDO_NEEDS_REVIEW' ) );
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
			echo '<p>' . esc_html( __( 'Item details are unavailable for this page.', 'writeleash' ) ) . '</p>';
			$items = null;
		}
		if ( null !== $items ) {
			$field = $plan->price_field();
			$field_name = Price_Operation::label( $field );
			$field_label = $field_name;

			echo '<p>' . esc_html( sprintf( /* translators: %s: translated price-field name. */ __( 'Expected and planned are %1$s values. Now shows the stored %1$s at page load, not the shopper price. Unavailable means the product or price could not be read.', 'writeleash' ), $field_label ) ) . '</p>';
			echo '<div class="writeleash-table-scroll" role="region" aria-label="' . esc_attr__( 'Product prices and outcomes', 'writeleash' ) . '" tabindex="0"><table class="widefat striped writeleash-prices writeleash-results" data-writeleash-results="1"><thead><tr><th scope="col">' . esc_html( __( 'Product', 'writeleash' ) ) . '</th><th scope="col">' . esc_html( sprintf( /* translators: %s: translated price-field name. */ __( '%s expected', 'writeleash' ), $field_name ) ) . '</th><th scope="col">' . esc_html( sprintf( /* translators: %s: translated price-field name. */ __( '%s now', 'writeleash' ), $field_name ) ) . '</th><th scope="col">' . esc_html( sprintf( /* translators: %s: translated price-field name. */ __( '%s planned', 'writeleash' ), $field_name ) ) . '</th><th scope="col">' . esc_html( _x( 'Apply', 'merchant price workflow label', 'writeleash' ) ) . '</th><th scope="col">' . esc_html( _x( 'Undo', 'merchant price workflow label', 'writeleash' ) ) . '</th></tr></thead><tbody>';
			if ( ! $items['items'] ) { echo '<tr><td colspan="6">' . esc_html__( 'No retained products on this page match this view.', 'writeleash' ) . '</td></tr>'; }
			foreach ( $items['items'] as $item ) {
				$id = (int) $item['product_id'];
				$frozen = $identities[ $id ] ?? array( 'product_id' => $id );
				$observation = self::product_observation( $id, $field );
				echo '<tr data-product-id="' . esc_attr( (string) $id ) . '"><td>'; self::render_identity( $frozen ); echo '</td>';
				echo '<td>' . esc_html( self::money_display( self::expected_display( $frozen, $item, $field ), $plan->data()['store'], false, $field ) ) . '</td>';
				echo '<td>' . esc_html( self::money_display( $observation['price'], $plan->data()['store'], false, $field ) ) . '</td>';
				echo '<td>' . esc_html( self::money_display( $item['planned_price'], $plan->data()['store'], false, $field ) ) . '</td>';
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

		return sprintf( /* translators: %d: maximum selected products per new job. */ __( 'This older job was already approved. The current WriteLeash limit of %1$d products applies to new work: new jobs above %1$d cannot be created or approved. You can still continue this existing job and restore prices that are safe to restore.', 'writeleash' ), Free_Support_Contract::MAX_JOB_PRODUCTS );
	}

	private static function render_legacy_oversize_warning(): void {
		echo '<div class="notice notice-warning" role="alert"><p><strong>' . esc_html( __( 'Older large job', 'writeleash' ) ) . '</strong></p><p>' . esc_html( self::legacy_oversize_message() ) . '</p></div>';
	}

	private static function render_undo_section( array $job, array $history, string $field ): void {
		$field_label = Price_Operation::label( $field );

		echo '<p>' . esc_html( sprintf( /* translators: %s: translated price-field name. */ __( 'Undo restores eligible %s values changed by this job to their values before this job. Later price or sale-setting changes are left alone. Undo does not reverse: orders, completed sales or effects in other plugins and services.', 'writeleash' ), $field_label ) ) . '</p>';
		echo '<p><strong data-progress-undo-label>' . esc_html( self::undo_availability( $job, $history ) ) . '</strong></p>';
		if ( ! empty( $history['undo_expires_at'] ) ) {

			echo '<p>' . esc_html( sprintf( /* translators: %s: localized expiry date and time. */ __( 'Undo window ends: %s', 'writeleash' ), self::site_time( $history['undo_expires_at'] ) ) ) . '</p>';
		}
		echo '<p data-progress-undo-summary></p><p data-progress-undo-notice></p>';
		if ( ! empty( $history['undo']['operation_status'] ) ) {
			$undo = $history['undo'];
			$undo_review = (int) $undo['needs_review'];
			echo '<p data-progress-initial-undo>' . esc_html( self::undo_summary( $undo ) ) . '</p>';
			echo '<div data-progress-initial-undo>'; self::support_details( $undo['operation_status'] . ' · ' . $undo['operation_reason'] ); echo '</div>';
		}

		echo '<div data-progress-undo>';
		if ( $history['undo_eligible'] && self::can_mutate() && ( empty( $history['undo']['operation_status'] ) || Undo_State::can_manual_run( $history['undo']['operation_status'] ) ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_UNDO ) . '">';
			echo '<input type="hidden" name="job" value="' . esc_attr( $job['public_id'] ) . '">';
			wp_nonce_field( self::ACTION_UNDO . '_' . $job['public_id'] );
			echo '<p><button type="submit" class="button' . ( empty( $history['undo']['operation_status'] ) ? ' button-secondary' : ' button-primary' ) . '">' . esc_html( empty( $history['undo']['operation_status'] ) ? __( 'Restore eligible prices (Undo)', 'writeleash' ) : __( 'Continue Undo', 'writeleash' ) ) . '</button> ';
			echo esc_html( __( 'Newer prices and sale settings are left alone.', 'writeleash' ) ) . '</p></form>';
		} elseif ( $history['undo_eligible'] && ! self::can_mutate() ) {
			echo '<p>' . esc_html__( 'You do not have permission to restore prices.', 'writeleash' ) . '</p>';
		}
		echo '</div>';
	}

	private static function render_history_view( int $offset ): void {
		echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html( __( 'Back to bulk prices', 'writeleash' ) ) . '</a></p>';
		echo '<h2>' . esc_html( __( 'History', 'writeleash' ) ) . '</h2>';
		if ( ! self::jobs_installed() ) {
			echo '<p>' . esc_html( __( 'No price changes yet. Preview a change to start.', 'writeleash' ) ) . '</p>';
			return;
		}
		try {
			$page = Undo_Repository::history_jobs( $offset, self::HISTORY_PAGE_SIZE, get_current_user_id() );
		} catch ( \Throwable $error ) {
			echo '<p>' . esc_html( __( 'History is unavailable right now. Reload the page to try again.', 'writeleash' ) ) . '</p>';
			return;
		}
		self::render_history_table( $page['jobs'] );
		self::render_pager( 'history', '', $offset, self::HISTORY_PAGE_SIZE, $page['next_offset'] );
	}
}
