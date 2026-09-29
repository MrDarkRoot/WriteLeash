<?php
namespace WriteLeash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST integration for the one certified Redirection operation (#87), driven
 * by the #78 descriptor and config.
 *
 * Intercepts exactly:
 *
 *   POST /redirection/v1/bulk/redirect/disable
 *   Redirection 5.5.2, global=true, no filterBy conditions, no items
 *
 * WordPress runs `rest_dispatch_request` only after the matched route's
 * permission callback has succeeded, so this hooks the smallest safe point:
 * Redirection's own `permission_callback_bulk` still decides who may call the
 * operation, and returning a non-null result from this filter skips the
 * Redirection handler entirely. The stock `route_bulk()` therefore cannot run
 * its unbounded UPDATE on the normal connection for a certified request.
 *
 * Candidate route and certification are deliberately separate:
 *
 *   candidate dangerous route/shape -> WriteLeash owns it, never stock fallback
 *     -> Certified_Operation_Status::check() decides:
 *          READY        -> restricted runtime -> adapter -> Guard
 *          DISABLED     -> 503, no mutation
 *          MISCONFIGURED-> 503, no mutation
 *          UNSUPPORTED  -> 503, no mutation
 *          NOT_READY    -> 503, no mutation
 *
 * The dangerous route is derived from the descriptor; callback identity is NOT
 * required for the version/readiness refusals, so a later-build handler
 * refactor cannot turn the protection back into a stock unbounded update. The
 * logical budget and enabled flag have exactly one authority:
 * Operation_Config (plus the immutable descriptor); the legacy #87 budget
 * option is no longer read.
 *
 * Everything else keeps stock Redirection behavior: item scoped `items=[...]`,
 * `global=false`, Enable, Reset, Delete, filtered `global=true` variants,
 * single-item edits, hit/stat writes and every other route are untouched.
 *
 * Not `final`: the test suite extends this class only to override
 * `plugin_version()` and prove version-drift fail-closed behavior through the
 * real REST route; production always runs the base class.
 */
class Redirection_Bulk_Disable_Rest {
	public static function boot(): void {
		add_filter( 'rest_dispatch_request', array( __CLASS__, 'dispatch' ), 10, 4 );
	}

	/**
	 * @param mixed $result Short-circuit result from an earlier filter, else null.
	 * @param \WP_REST_Request $request Matched request.
	 * @param string $route Matched route.
	 * @param array $handler Matched route handler.
	 * @return mixed Non-null only for an owned candidate (certified or refused).
	 */
	public static function dispatch( $result, $request, $route, $handler ) {
		if ( null !== $result ) {
			return $result;
		}
		$operation = Certified_Operation::find( Certified_Operation::REDIRECTION_BULK_DISABLE_ID );
		if ( null === $operation || ! self::is_candidate_global_disable( $operation, $request, $route ) ) {
			return null;
		}
		return self::handle( $operation, $request, $route, $handler );
	}

	/**
	 * Detected Redirection version. Protected static so the test suite can
	 * simulate version drift with a narrow subclass; production always reads
	 * the loaded plugin through the adapter.
	 */
	protected static function plugin_version(): ?string {
		return Redirection_Bulk_Disable::detected_version();
	}

	/**
	 * The dangerous route/shape WriteLeash claims, derived from the descriptor.
	 * No callback identity here: the version/readiness refusal requires only
	 * the concrete route and select-all-matching request shape.
	 */
	private static function is_candidate_global_disable( Certified_Operation $operation, $request, $route ): bool {
		if ( ! $request instanceof \WP_REST_Request ) {
			return false;
		}
		// The concrete requested path; `$route` is the registered pattern.
		if ( ! $operation->matches_rest( (string) $request->get_route(), (string) $request->get_method() ) ) {
			return false;
		}
		if ( 0 !== strpos( (string) $route, $operation->rest_route_prefix() ) ) {
			return false;
		}
		if ( $operation->rest_bulk_action() !== $request['bulk'] ) {
			return false;
		}
		// Mirrors Redirection's own `if ( $params['global'] )` branch test.
		if ( empty( $request['global'] ) ) {
			return false;
		}
		$items = $request['items'];
		if ( is_array( $items ) && count( $items ) > 0 ) {
			return false;
		}
		// `Red_Item_Filters` turns filterBy values into WHERE conditions; only
		// the unfiltered select-all-matching update is owned.
		$filter_by = $request['filterBy'];
		if ( is_array( $filter_by ) ) {
			foreach ( $filter_by as $value ) {
				if ( is_array( $value ) ? count( $value ) > 0 : ( '' !== trim( (string) $value ) ) ) {
					return false;
				}
			}
		} elseif ( null !== $filter_by && '' !== trim( (string) $filter_by ) ) {
			return false;
		}
		return true;
	}

	/** The matched handler must be Redirection's own bulk handler. */
	private static function is_certified_handler( Certified_Operation $operation, $route, $handler ): bool {
		if ( 0 !== strpos( (string) $route, $operation->rest_route_prefix() ) ) {
			return false;
		}
		if ( ! isset( $handler['callback'] ) || ! is_array( $handler['callback'] ) ||
			! isset( $handler['callback'][0], $handler['callback'][1] ) ||
			! is_object( $handler['callback'][0] ) || ! is_a( $handler['callback'][0], 'Redirection_Api_Redirect' ) ||
			'route_bulk' !== $handler['callback'][1] ) {
			return false;
		}
		return true;
	}

	/** Certified path: readiness -> restricted runtime -> adapter -> REST response. */
	private static function handle( Certified_Operation $operation, \WP_REST_Request $request, $route, array $handler ) {
		$readiness = Certified_Operation_Status::check( $operation, static::plugin_version() );
		if ( Certified_Operation_Status::READY !== $readiness['status'] ) {
			return self::readiness_error( $readiness );
		}
		if ( ! self::is_certified_handler( $operation, $route, $handler ) ) {
			return self::error(
				'writeleash_operation_unavailable',
				'WriteLeash could not certify the matched Redirection handler for global Disable; the operation was not executed.',
				503,
				self::readiness_evidence( $readiness )
			);
		}
		$runtime = $readiness['runtime'];
		$budget  = $readiness['logical_budget'];
		if ( ! $runtime instanceof \wpdb || ! is_int( $budget ) ) {
			return self::error(
				'writeleash_operation_unavailable',
				'WriteLeash could not certify the runtime policy for global Disable; the operation was not executed.',
				503,
				self::readiness_evidence( $readiness )
			);
		}

		$result  = ( new Redirection_Bulk_Disable( $runtime, $budget ) )->run();
		$outcome = isset( $result['outcome'] ) ? (string) $result['outcome'] : 'UNKNOWN';
		// After Guard returns, best-effort informational storage. A failed option
		// write must never change the certified outcome or REST response.
		try {
			Last_Outcome::record( $result );
		} catch ( \Throwable $error ) {
			// Local evidence is optional; Guard's decision is authoritative.
		}

		if ( 'COMMITTED' === $outcome ) {
			$response = self::stock_list( $handler, $request );
			if ( is_array( $response ) ) {
				$response['writeleash'] = self::evidence( $result );
			}
			return $response;
		}
		if ( 'DENIED' === $outcome ) {
			return self::error( 'writeleash_budget_denied', self::denied_message( $result ), 409, self::evidence( $result ) );
		}
		if ( 'ERROR' === $outcome ) {
			return self::error(
				'writeleash_operation_error',
				'WriteLeash could not complete the certified global Disable; no changes were committed.',
				500,
				self::evidence( $result )
			);
		}
		return self::error(
			'writeleash_operation_unavailable',
			'WriteLeash could not certify the runtime policy for global Disable; the operation was not executed.',
			503,
			self::evidence( $result )
		);
	}

	/** Map a non-READY readiness result to the explicit fail-closed REST error. */
	private static function readiness_error( array $readiness ) {
		$data = self::readiness_evidence( $readiness );
		switch ( isset( $readiness['reason'] ) ? $readiness['reason'] : '' ) {
			case 'redirection_version_unsupported':
				return self::error(
					'writeleash_redirection_version_unsupported',
					'WriteLeash does not certify this Redirection version for global Disable; the unbounded update was not executed.',
					503,
					$data
				);
			case 'operation_disabled':
				return self::error(
					'writeleash_operation_disabled',
					'The WriteLeash certified global Disable operation is disabled; no redirects were changed.',
					503,
					$data
				);
			case 'config_invalid':
			case 'logical_budget_missing':
			case 'logical_budget_invalid':
				return self::error(
					'writeleash_operation_misconfigured',
					'WriteLeash operation configuration is invalid; no redirects were changed.',
					503,
					$data
				);
			case 'runtime_unavailable':
				return self::error(
					'writeleash_runtime_unavailable',
					'WriteLeash shared runtime connection is unavailable; the certified global Disable was not executed.',
					503,
					$data
				);
			case 'physical_ceiling_mismatch':
				return self::error(
					'writeleash_physical_ceiling_mismatch',
					'The installed physical ceiling differs from the certified operation descriptor; the operation was not executed.',
					503,
					$data
				);
			case 'adapter_unavailable':
			case 'target_privileges_mismatch':
			case 'doctor_not_ready':
			default:
				return self::error(
					'writeleash_operation_unavailable',
					'WriteLeash could not certify the runtime policy for global Disable; the operation was not executed.',
					503,
					$data
				);
		}
	}

	/** Secret-free readiness facts for REST error data. */
	private static function readiness_evidence( array $readiness ): array {
		return array(
			'operation_id'              => Certified_Operation::REDIRECTION_BULK_DISABLE_ID,
			// Named `readiness_status`: `status` in WP_Error data is the numeric
			// HTTP status consumed by error_to_response().
			'readiness_status'          => isset( $readiness['status'] ) ? $readiness['status'] : null,
			'reason'                    => isset( $readiness['reason'] ) ? $readiness['reason'] : null,
			'logical_budget'            => isset( $readiness['logical_budget'] ) ? $readiness['logical_budget'] : null,
			'expected_physical_ceiling' => isset( $readiness['physical_ceiling'] ) ? $readiness['physical_ceiling'] : null,
			'actual_physical_ceiling'   => isset( $readiness['actual_physical_ceiling'] ) ? $readiness['actual_physical_ceiling'] : null,
			'detected_version'          => isset( $readiness['detected_version'] ) ? $readiness['detected_version'] : null,
			'detail'                    => isset( $readiness['detail'] ) ? $readiness['detail'] : null,
		);
	}

	/**
	 * Stock response body via Redirection's own read-only list handler. The
	 * mutation is skipped entirely by returning a non-null dispatch result.
	 */
	private static function stock_list( array $handler, \WP_REST_Request $request ) {
		try {
			$list = $handler['callback'][0]->route_list( $request );
		} catch ( \Throwable $error ) {
			$list = null;
		}
		return is_array( $list ) ? $list : array( 'items' => array(), 'total' => 0 );
	}

	/** @param array<string, mixed> $result Adapter result. */
	private static function evidence( array $result ): array {
		$fields = array(
			'operation_id',
			'outcome',
			'denial_kind',
			'logical_budget',
			'physical_ceiling',
			'consumed',
			'attempted',
			'reason',
			'transaction_rollback_attempted',
			'guard_rollback_completed',
			'durability_verified_by_fresh_observer',
		);
		$data = array();
		foreach ( $fields as $field ) {
			$data[ $field ] = isset( $result[ $field ] ) ? $result[ $field ] : null;
		}
		return $data;
	}

	/** @param array<string, mixed> $result Adapter result. */
	private static function denied_message( array $result ): string {
		$kind   = isset( $result['denial_kind'] ) && is_string( $result['denial_kind'] ) ? $result['denial_kind'] : 'budget';
		$budget = isset( $result['logical_budget'] ) ? (int) $result['logical_budget'] : 0;
		return 'WriteLeash denied the certified global Disable (' . $kind . ' limit ' . $budget . '); no changes were committed.';
	}

	private static function error( string $code, string $message, int $status, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
	}
}
