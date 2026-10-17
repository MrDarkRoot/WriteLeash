<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Restricted #299 submission route for v0.3.1 grant-bound jobs.
 *
 * Authenticates the WordPress user (Application Passwords are that user).
 * Validates schema, grant binding, and scope before Grant_Repository::accept_plan.
 * Execution is Job_Worker::queue_job only. This class does not write prices.
 */
final class Agent_Jobs_Rest {
	public const ROUTE = '/grants/(?P<grant_id>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/jobs';

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_rest_route(
			'writeleash/v1',
			self::ROUTE,
			array(
				'methods' => 'POST',
				'callback' => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/** Logged-in product manager. No separate agent principal. */
	public static function permission( \WP_REST_Request $request ) {
		unset( $request );
		if ( ! is_user_logged_in() || get_current_user_id() < 1 ) {
			return self::error( 'UNAUTHENTICATED', 401 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_products' ) ) {
			return self::error( 'PERMISSION_DENIED', 403 );
		}
		return true;
	}

	public static function handle( \WP_REST_Request $request ) {
		$parsed = self::parse_submission( self::json_object( $request ) );
		if ( isset( $parsed['error'] ) ) {
			return self::error( $parsed['error'], 400 );
		}
		$grant_id = (string) $request['grant_id'];
		if ( ! preg_match( Grant_Repository::PUBLIC_ID_REGEX, $grant_id ) ) {
			return self::error( 'GRANT_NOT_FOUND', 404 );
		}
		global $wpdb;
		$support = Free_Support_Contract::execution_reason( $wpdb );
		if ( null !== $support ) {
			return self::error( 'SUPPORT_UNAVAILABLE', 503 );
		}
		try {
			$context = Price_Store_Context::current();
		} catch ( Price_Validation_Error $error ) {
			unset( $error );
			return self::error( 'SUPPORT_UNAVAILABLE', 503 );
		}
		if ( self::fraction_too_long( $parsed['target'], (int) $context->data()['price_decimals'] ) ) {
			return self::error( 'MALFORMED', 400 );
		}
		$issuer = (int) get_current_user_id();
		if ( ! Grant_Repository::is_authenticated_self( $issuer ) ) {
			return self::error( 'PERMISSION_DENIED', 403 );
		}
		$grant = Grant_Repository::read_by_public_id( $grant_id );
		if ( ! $grant ) {
			return self::error( 'GRANT_NOT_FOUND', 404 );
		}
		if ( (int) $grant['issuer_id'] !== $issuer ) {
			return self::error( 'PERMISSION_DENIED', 403 );
		}
		if ( (int) $grant['actor_id'] !== $parsed['actor_id'] ) {
			return self::error( 'GRANT_IDENTITY_INVALID', 403 );
		}
		$replay = Grant_Repository::has_idempotency( (int) $grant['id'], $parsed['idempotency_key'] );
		if ( ! $replay ) {
			$pre = self::pre_accept( $grant, $parsed['grant_version'] );
			if ( null !== $pre ) {
				return self::error( $pre['reason'], $pre['status'] );
			}
			$actor = get_userdata( $parsed['actor_id'] );
			if ( ! $actor || ! user_can( $actor, 'manage_woocommerce' ) || ! user_can( $actor, 'edit_products' ) ) {
				return self::error( 'GRANT_IDENTITY_INVALID', 403 );
			}
		}
		try {
			$plan = self::freeze_plan( $grant, $parsed, $context );
			$result = Grant_Repository::accept_plan( $plan, $grant_id, $issuer, $parsed['idempotency_key'], $parsed['grant_version'] );
		} catch ( Grant_Error $error ) {
			return self::error( $error->reason(), self::status_for( $error->reason() ) );
		} catch ( Price_Validation_Error $error ) {
			unset( $error );
			return self::error( 'GRANT_SCOPE_DENIED', 409 );
		} catch ( \Throwable $error ) {
			unset( $error );
			return self::error( 'GRANT_STORE_UNAVAILABLE', 503 );
		}
		$job = $result['job'];
		if ( Job_State::READY === ( $job['status'] ?? '' ) ) {
			$queued = Job_Worker::queue_job( (int) $job['id'] );
			$fresh = Job_Repository::read( (int) $job['id'] );
			if ( $fresh ) {
				$job = $fresh;
			}
			if ( 'SCHEDULER_UNAVAILABLE' === $queued ) {
				$response = self::payload( $job, (bool) $result['replayed'] );
				return new \WP_Error( 'writeleash_agent_scheduler_unavailable', self::message( 'SCHEDULER_UNAVAILABLE' ), array( 'status' => 503, 'reason' => 'SCHEDULER_UNAVAILABLE', 'job' => $response['job'] ) );
			}
		}
		$status = $result['replayed'] ? 200 : 201;
		return new \WP_REST_Response( self::payload( $job, (bool) $result['replayed'] ), $status );
	}

	/**
	 * Strict object schema. No selectors, callbacks, meta keys, or percent ops.
	 *
	 * @param mixed $body
	 * @return array<string,mixed>
	 */
	public static function parse_submission( $body ): array {
		if ( ! is_array( $body ) || array() === $body || self::is_list( $body ) ) {
			return array( 'error' => 'MALFORMED' );
		}
		$allowed = array( 'idempotency_key', 'actor_id', 'grant_version', 'price_field', 'operation', 'target', 'product_ids' );
		foreach ( array_keys( $body ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return array( 'error' => 'MALFORMED' );
			}
		}
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $body ) ) {
				return array( 'error' => 'MALFORMED' );
			}
		}
		$key = $body['idempotency_key'];
		if ( ! is_string( $key ) || 1 !== preg_match( Grant_Repository::IDEMPOTENCY_REGEX, $key ) ) {
			return array( 'error' => 'GRANT_IDEMPOTENCY_INVALID' );
		}
		if ( ! is_int( $body['actor_id'] ) || $body['actor_id'] < 1 || ! is_int( $body['grant_version'] ) || $body['grant_version'] < 1 ) {
			return array( 'error' => 'MALFORMED' );
		}
		if ( Price_Operation::FIELD_REGULAR !== $body['price_field'] && Price_Operation::FIELD_SALE !== $body['price_field'] ) {
			return array( 'error' => 'MALFORMED' );
		}
		if ( Price_Operation::SET !== $body['operation'] ) {
			return array( 'error' => 'UNSUPPORTED_OPERATION' );
		}
		if ( ! is_string( $body['target'] ) || '' === $body['target'] ) {
			return array( 'error' => 'MALFORMED' );
		}
		try {
			Price_Decimal::parse( $body['target'] );
		} catch ( Price_Validation_Error $error ) {
			unset( $error );
			return array( 'error' => 'MALFORMED' );
		}
		if ( ! is_array( $body['product_ids'] ) || array() === $body['product_ids'] || ! self::is_list( $body['product_ids'] ) || count( $body['product_ids'] ) > Product_Price_Selector::MAX_SELECTED ) {
			return array( 'error' => 'MALFORMED' );
		}
		$ids = array();
		foreach ( $body['product_ids'] as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return array( 'error' => 'MALFORMED' );
			}
			$ids[] = $id;
		}
		$unique = array_values( array_unique( $ids ) );
		if ( count( $unique ) !== count( $ids ) ) {
			return array( 'error' => 'MALFORMED' );
		}
		sort( $unique, SORT_NUMERIC );
		return array(
			'idempotency_key' => $key,
			'actor_id' => $body['actor_id'],
			'grant_version' => $body['grant_version'],
			'price_field' => $body['price_field'],
			'operation' => Price_Operation::SET,
			'target' => $body['target'],
			'product_ids' => $unique,
		);
	}

	/** @param mixed $body */
	private static function json_object( \WP_REST_Request $request ) {
		$type = (string) $request->get_header( 'content-type' );
		if ( false === strpos( $type, 'application/json' ) ) {
			return null;
		}
		$body = $request->get_json_params();
		if ( is_array( $body ) && array() !== $body ) {
			return $body;
		}
		$decoded = json_decode( $request->get_body(), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/** @param array<string,mixed> $grant @return array{reason:string,status:int}|null */
	private static function pre_accept( array $grant, int $version ): ?array {
		if ( Grant_Repository::is_disabled() ) {
			return array( 'reason' => 'GRANT_DISABLED', 'status' => 403 );
		}
		if ( 'active' !== ( $grant['status'] ?? '' ) ) {
			return array( 'reason' => 'GRANT_REVOKED', 'status' => 409 );
		}
		if ( (int) $grant['version'] !== $version ) {
			return array( 'reason' => 'GRANT_VERSION_MISMATCH', 'status' => 409 );
		}
		$expires = (string) ( $grant['expires_at'] ?? '' );
		if ( '' === $expires || strcmp( $expires, gmdate( 'Y-m-d H:i:s' ) ) <= 0 ) {
			return array( 'reason' => 'GRANT_EXPIRED', 'status' => 409 );
		}
		return null;
	}

	/**
	 * Freeze concrete IDs and one absolute SET target. Rejects parent expansion.
	 *
	 * @param array<string,mixed> $grant
	 * @param array<string,mixed> $parsed
	 */
	private static function freeze_plan( array $grant, array $parsed, Price_Store_Context $context ): Change_Plan {
		$ids = $parsed['product_ids'];
		$selection = Price_Selection_Spec::ids( $ids );
		$resolved = Product_Price_Selector::resolve_with_outcome( $selection );
		$resolved_ids = array();
		foreach ( $resolved['snapshots'] as $snapshot ) {
			$resolved_ids[] = (int) $snapshot->data()['product_id'];
		}
		sort( $resolved_ids, SORT_NUMERIC );
		if ( $resolved_ids !== $ids ) {
			throw new Grant_Error( 'GRANT_SCOPE_DENIED' );
		}
		$actor_id = (int) $grant['actor_id'];
		$issuer = (int) get_current_user_id();
		foreach ( $resolved['population'] as $snapshot ) {
			$row = $snapshot->data();
			$id = (int) $row['product_id'];
			if ( ( empty( $row['exists'] ) && empty( $row['unreadable'] ) ) || ! user_can( $issuer, 'edit_post', $id ) || ! user_can( $actor_id, 'edit_post', $id ) ) {
				throw new Grant_Error( 'GRANT_SCOPE_DENIED' );
			}
		}
		if ( $context->data() !== Price_Store_Context::current()->data() ) {
			throw new Price_Validation_Error( 'store_context_changed_during_planning' );
		}
		$operation = new Price_Operation( Price_Operation::SET, $parsed['target'], $parsed['price_field'] );
		$policy = new Safety_Policy( Free_Support_Contract::MAX_JOB_PRODUCTS, '100000000000', '100000000000', false, '100000000000' );
		$plan = Change_Plan::create( self::plan_id( (string) $grant['public_id'], $parsed['idempotency_key'] ), gmdate( 'Y-m-d\TH:i:s\Z' ), $actor_id, $context, $selection, $operation, $policy, $resolved['snapshots'], $resolved['outcome'] );
		if ( 'PREVIEW' !== ( $plan->data()['status'] ?? '' ) ) {
			throw new Grant_Error( 'GRANT_SCOPE_DENIED' );
		}
		$changing = array();
		foreach ( $plan->data()['items'] as $item ) {
			if ( 'CHANGING' !== ( $item['result'] ?? '' ) ) {
				throw new Grant_Error( 'GRANT_SCOPE_DENIED' );
			}
			$changing[] = (int) $item['product_id'];
		}
		sort( $changing, SORT_NUMERIC );
		if ( $changing !== $ids ) {
			throw new Grant_Error( 'GRANT_SCOPE_DENIED' );
		}
		Grant_Repository::assert_scope( $plan );
		return $plan;
	}

	public static function plan_id( string $grant_id, string $key ): string {
		return 'ag' . substr( hash( 'sha256', $grant_id . '|' . $key ), 0, 32 );
	}

	/** @param array<string,mixed> $job @return array{job:array<string,mixed>} */
	private static function payload( array $job, bool $replayed ): array {
		return array(
			'job' => array(
				'public_id' => (string) $job['public_id'],
				'status' => (string) $job['status'],
				'status_reason' => (string) $job['status_reason'],
				'approval_mode' => (string) ( $job['approval_mode'] ?? '' ),
				'approver_id' => (int) $job['approver_id'],
				'grant_public_id' => (string) ( $job['grant_public_id'] ?? '' ),
				'grant_version' => (int) ( $job['grant_version'] ?? 0 ),
				'replayed' => $replayed,
			),
		);
	}

	private static function fraction_too_long( string $target, int $decimals ): bool {
		$parts = explode( '.', $target );
		return isset( $parts[1] ) && strlen( $parts[1] ) > $decimals;
	}

	/** @param array<mixed> $value */
	private static function is_list( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private static function status_for( string $reason ): int {
		if ( 'GRANT_NOT_FOUND' === $reason ) {
			return 404;
		}
		if ( in_array( $reason, array( 'PERMISSION_DENIED', 'GRANT_IDENTITY_INVALID', 'GRANT_DISABLED' ), true ) ) {
			return 403;
		}
		if ( in_array( $reason, array( 'GRANT_STORE_UNAVAILABLE', 'SUPPORT_UNAVAILABLE', 'SCHEDULER_UNAVAILABLE' ), true ) ) {
			return 503;
		}
		if ( in_array( $reason, array( 'MALFORMED', 'GRANT_IDEMPOTENCY_INVALID', 'UNSUPPORTED_OPERATION' ), true ) ) {
			return 400;
		}
		return 409;
	}

	private static function error( string $reason, int $status ) {
		return new \WP_Error( 'writeleash_agent_' . strtolower( $reason ), self::message( $reason ), array( 'status' => $status, 'reason' => $reason ) );
	}

	private static function message( string $reason ): string {
		$messages = array(
			'UNAUTHENTICATED' => __( 'Authentication is required. No prices were changed.', 'writeleash' ),
			'PERMISSION_DENIED' => __( 'You do not have permission to submit this job. No prices were changed.', 'writeleash' ),
			'GRANT_NOT_FOUND' => __( 'No standing grant matches that identifier. No prices were changed.', 'writeleash' ),
			'GRANT_IDENTITY_INVALID' => __( 'The grant actor does not match this request. No prices were changed.', 'writeleash' ),
			'GRANT_EXPIRED' => __( 'This standing grant has expired. No prices were changed.', 'writeleash' ),
			'GRANT_REVOKED' => __( 'This standing grant is revoked. No prices were changed.', 'writeleash' ),
			'GRANT_DISABLED' => __( 'Standing grants are disabled. No prices were changed.', 'writeleash' ),
			'GRANT_SCOPE_DENIED' => __( 'The request is outside this grant’s price scope. No prices were changed.', 'writeleash' ),
			'GRANT_BUDGET_EXHAUSTED' => __( 'This standing grant has no remaining budget. No prices were changed.', 'writeleash' ),
			'GRANT_IDEMPOTENCY_MISMATCH' => __( 'That idempotency key was already used for a different request. No prices were changed.', 'writeleash' ),
			'GRANT_IDEMPOTENCY_INVALID' => __( 'The idempotency key is not valid. No prices were changed.', 'writeleash' ),
			'GRANT_VERSION_MISMATCH' => __( 'The standing grant version does not match. No prices were changed.', 'writeleash' ),
			'GRANT_STORE_UNAVAILABLE' => __( 'Standing grant storage is unavailable. No prices were changed.', 'writeleash' ),
			'GRANT_PLAN_EXISTS' => __( 'This request conflicts with an existing plan. No prices were changed.', 'writeleash' ),
			'MALFORMED' => __( 'The request is not a valid absolute price job. No prices were changed.', 'writeleash' ),
			'UNSUPPORTED_OPERATION' => __( 'Only an absolute price set is accepted. No prices were changed.', 'writeleash' ),
			'SCHEDULER_UNAVAILABLE' => __( 'The job was accepted but could not be queued. No prices were changed.', 'writeleash' ),
			'SUPPORT_UNAVAILABLE' => __( 'This store cannot run a standing-grant job. No prices were changed.', 'writeleash' ),
		);
		return $messages[ $reason ] ?? __( 'The request was refused. No prices were changed.', 'writeleash' );
	}
}
