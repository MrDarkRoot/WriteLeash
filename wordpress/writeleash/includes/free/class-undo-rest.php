<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Narrow #110 Undo initiation endpoint. This is deliberately not the #111
 * Admin experience: one POST route that records the Undo operation for a
 * safely stopped apply job and then performs one bounded chunk under the
 * same worker fence. Reads (history/eligibility/expiry) stay backend PHP
 * for #111; no GET mutation exists.
 */
final class Undo_Rest {
	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_rest_route(
			'writeleash/v1',
			'/undo/(?P<public_id>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/start',
			array(
				'methods' => 'POST',
				'callback' => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/** Capability gate. Job ownership/role authorization is checked in handle(). */
	public static function permission( \WP_REST_Request $request ) {
		return is_user_logged_in() && current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_products' );
	}

	public static function handle( \WP_REST_Request $request ) {
		global $wpdb;
		if ( ! Undo_Schema::ready( $wpdb ) || ! Job_Schema::ready( $wpdb ) ) {
			return new \WP_Error( 'writeleash_undo_schema_unavailable', Undo_Reason::message( 'SCHEMA_UNAVAILABLE' ), array( 'status' => 503 ) );
		}
		$job = Job_Repository::read_by_public_id( (string) $request['public_id'] );
		if ( ! $job ) {
			return new \WP_Error( 'writeleash_job_not_found', __( 'No WriteLeash job matches that identifier.', 'writeleash' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( ! Undo_Repository::authorized( $job, $user_id ) ) {
			return new \WP_Error( 'writeleash_undo_forbidden', Undo_Reason::message( 'PERMISSION_DENIED' ), array( 'status' => 403 ) );
		}
		try {
			$operation = Undo_Repository::initiate( (int) $job['id'], $user_id );
		} catch ( Undo_Error $error ) {
			return self::initiation_error( $error->reason() );
		}
		if ( Undo_State::is_terminal( $operation['status'] ) ) {
			return new \WP_Error( 'writeleash_undo_not_resumable', Undo_Reason::message( 'UNDO_TERMINAL' ), array( 'status' => 409, 'undo_status' => $operation['status'] ) );
		}
		// Bounded work only; the worker enqueues a follow-up wake-up when items remain.
		$result = Undo_Worker::run( (int) $operation['id'], array(), true );
		$current = Undo_Repository::read_operation( (int) $operation['id'] );
		return rest_ensure_response(
			array(
				'undo' => array(
					'id' => (int) $operation['id'],
					'public_id' => $operation['public_id'],
					'job_id' => (int) $job['id'],
					'status' => $current ? $current['status'] : $operation['status'],
					'status_reason' => $current ? $current['status_reason'] : $operation['status_reason'],
					'counts' => $result['counts'],
					'stop' => $result['stop'],
					'processed' => $result['processed'],
				),
			)
		);
	}

	private static function initiation_error( string $reason ) {
		if ( 'PERMISSION_DENIED' === $reason ) {
			return new \WP_Error( 'writeleash_undo_forbidden', Undo_Reason::message( 'PERMISSION_DENIED' ), array( 'status' => 403 ) );
		}
		if ( 'UNDO_EXPIRED' === $reason ) {
			return new \WP_Error( 'writeleash_undo_expired', Undo_Reason::message( 'UNDO_EXPIRED' ), array( 'status' => 410 ) );
		}
		if ( in_array( $reason, array( 'UNDO_JOB_RUNNING', 'UNDO_NOT_ELIGIBLE' ), true ) ) {
			return new \WP_Error( 'writeleash_undo_not_eligible', Undo_Reason::message( $reason ), array( 'status' => 409 ) );
		}
		return new \WP_Error( 'writeleash_undo_unavailable', Undo_Reason::message( $reason ), array( 'status' => 503 ) );
	}
}
