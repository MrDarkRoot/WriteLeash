<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST integration for the one certified Redirection operation (#87).
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
 * Everything else keeps stock Redirection behavior: item scoped `items=[...]`,
 * `global=false`, Enable, Reset, Delete, filtered `global=true` variants,
 * single-item edits, hit/stat writes and every other route are untouched
 * because the matcher requires the exact route, method, Redirection callback,
 * `bulk=disable`, truthy `global` and no `filterBy` conditions.
 *
 * Budget source for #87 is one operation-specific integer option, validated as
 * a canonical nonnegative integer. Missing/invalid configuration fails closed
 * with a 503 instead of falling back to unguarded Redirection. #78/#60 will
 * replace this with the product budget source; no Admin SQL, table or method
 * name is configurable here.
 */
final class Redirection_Bulk_Disable_Rest {
	public const BUDGET_OPTION = 'commitcap_operation_budget_redirection_5_5_2_bulk_disable';
	private const ROUTE         = '/redirection/v1/bulk/redirect/disable';

	public static function boot(): void {
		add_filter( 'rest_dispatch_request', array( __CLASS__, 'dispatch' ), 10, 4 );
	}

	/**
	 * @param mixed $result Short-circuit result from an earlier filter, else null.
	 * @param \WP_REST_Request $request Matched request.
	 * @param string $route Matched route.
	 * @param array $handler Matched route handler.
	 * @return mixed Non-null only for the exact certified request.
	 */
	public static function dispatch( $result, $request, $route, $handler ) {
		if ( null !== $result ) {
			return $result;
		}
		if ( ! self::is_certified_request( $request, $route, $handler ) ) {
			return null;
		}
		return self::handle( $request, $handler );
	}

	/** Exact route/shape match only; never a general Redirection REST hook. */
	private static function is_certified_request( $request, $route, $handler ): bool {
		if ( ! $request instanceof \WP_REST_Request ) {
			return false;
		}
		// The concrete requested path; `$route` here is the registered pattern
		// (`/redirection/v1/bulk/redirect/(?P<bulk>...)`), so the handler identity
		// check below is what pins this to Redirection's own callback.
		if ( self::ROUTE !== $request->get_route() ) {
			return false;
		}
		if ( 0 !== strpos( (string) $route, '/redirection/v1/bulk/redirect/' ) ) {
			return false;
		}
		if ( 'POST' !== strtoupper( $request->get_method() ) ) {
			return false;
		}
		if ( '5.5.2' !== Redirection_Bulk_Disable::detected_version() || ! Redirection_Bulk_Disable::plugin_class_available() ) {
			return false;
		}
		// The matched handler must be Redirection's own bulk handler.
		if ( ! isset( $handler['callback'] ) || ! is_array( $handler['callback'] ) ||
			! isset( $handler['callback'][0], $handler['callback'][1] ) ||
			! is_object( $handler['callback'][0] ) || ! is_a( $handler['callback'][0], 'Redirection_Api_Redirect' ) ||
			'route_bulk' !== $handler['callback'][1] ) {
			return false;
		}
		if ( 'disable' !== $request['bulk'] ) {
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
		// the unfiltered select-all-matching update is certified.
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

	/** Certified path: budget -> restricted runtime -> adapter -> REST response. */
	private static function handle( \WP_REST_Request $request, array $handler ) {
		$budget = self::configured_budget();
		if ( null === $budget ) {
			return self::error(
				'commitcap_budget_not_configured',
				'CommitCap has no configured budget for the certified global Disable operation; no redirects were changed.',
				503
			);
		}
		$runtime = self::runtime_connection();
		if ( null === $runtime ) {
			return self::error(
				'commitcap_runtime_unavailable',
				'CommitCap shared runtime connection is unavailable; the certified global Disable was not executed.',
				503
			);
		}

		$result  = ( new Redirection_Bulk_Disable( $runtime, $budget ) )->run();
		$outcome = isset( $result['outcome'] ) ? (string) $result['outcome'] : 'UNKNOWN';

		if ( 'COMMITTED' === $outcome ) {
			$response = self::stock_list( $handler, $request );
			if ( is_array( $response ) ) {
				$response['commitcap'] = self::evidence( $result );
			}
			return $response;
		}
		if ( 'DENIED' === $outcome ) {
			return self::error( 'commitcap_budget_denied', self::denied_message( $result ), 409, self::evidence( $result ) );
		}
		if ( 'ERROR' === $outcome ) {
			return self::error(
				'commitcap_operation_error',
				'CommitCap could not complete the certified global Disable; no changes were committed.',
				500,
				self::evidence( $result )
			);
		}
		return self::error(
			'commitcap_operation_unavailable',
			'CommitCap could not certify the runtime policy for global Disable; the operation was not executed.',
			503,
			self::evidence( $result )
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
		return 'CommitCap denied the certified global Disable (' . $kind . ' limit ' . $budget . '); no changes were committed.';
	}

	private static function error( string $code, string $message, int $status, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
	}

	/**
	 * Operation budget: one canonical nonnegative integer option. Absent or
	 * malformed configuration returns null and the caller fails closed.
	 */
	private static function configured_budget(): ?int {
		$raw = get_option( self::BUDGET_OPTION, null );
		if ( is_int( $raw ) ) {
			return $raw >= 0 ? $raw : null;
		}
		if ( is_string( $raw ) && preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $raw ) ) {
			$value = (int) $raw;
			return $value <= 2147483647 ? $value : null;
		}
		return null;
	}

	/**
	 * Restricted shared runtime connection from the operator's protected
	 * configuration, on the same DB server as the normal WordPress connection.
	 * `COMMITCAP_DB_HOST` from the provisioning snippet is the account host
	 * restriction and is not a connect host; the normal connection's host is
	 * used here. Missing or unusable configuration returns null.
	 */
	private static function runtime_connection(): ?\wpdb {
		if ( ! defined( 'COMMITCAP_DB_USER' ) || ! defined( 'COMMITCAP_DB_PASSWORD' ) ) {
			return null;
		}
		$user     = (string) constant( 'COMMITCAP_DB_USER' );
		$password = (string) constant( 'COMMITCAP_DB_PASSWORD' );
		if ( '' === $user ) {
			return null;
		}
		$normal = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \wpdb ? $GLOBALS['wpdb'] : null;
		$name   = defined( 'COMMITCAP_DB_NAME' ) && '' !== (string) constant( 'COMMITCAP_DB_NAME' )
			? (string) constant( 'COMMITCAP_DB_NAME' )
			: ( null !== $normal ? (string) $normal->dbname : '' );
		$host   = null !== $normal ? (string) $normal->dbhost : 'localhost';
		if ( '' === $name || '' === $host ) {
			return null;
		}
		try {
			$db = new \wpdb( $user, $password, $name, $host );
		} catch ( \Throwable $error ) {
			return null;
		}
		$db->suppress_errors( true );
		if ( null !== $normal ) {
			$db->set_prefix( (string) $normal->prefix );
		}
		$probe = $db->get_var( 'SELECT 1' );
		if ( ! $db->ready || '1' !== (string) $probe ) {
			return null;
		}
		return $db;
	}
}
