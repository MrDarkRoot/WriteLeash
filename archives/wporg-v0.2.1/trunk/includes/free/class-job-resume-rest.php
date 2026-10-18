<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Narrow #109 manual resume endpoint. This is deliberately not the #111 Admin
 * experience: one POST route that performs one bounded chunk under the same
 * worker fence and enqueues a follow-up when items remain.
 */
final class Job_Resume_Rest {
	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_rest_route(
			'writeleash/v1',
			'/jobs/(?P<public_id>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/resume',
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
		if ( ! Job_Schema::ready( $wpdb ) ) {
			return new \WP_Error( 'writeleash_job_schema_unavailable', Job_Reason::message( 'SCHEMA_UNAVAILABLE' ), array( 'status' => 503 ) );
		}
		$job = Job_Repository::read_by_public_id( (string) $request['public_id'] );
		if ( ! $job ) {
			return new \WP_Error( 'writeleash_job_not_found', __( 'No WriteLeash job matches that identifier.', 'writeleash' ), array( 'status' => 404 ) );
		}
		if ( ! self::authorized( $job ) ) {
			return new \WP_Error( 'writeleash_job_forbidden', Job_Reason::message( 'PERMISSION_DENIED' ), array( 'status' => 403 ) );
		}
		if ( Job_State::is_terminal( $job['status'] ) || ! Job_State::can_manual_run( $job['status'] ) ) {
			return new \WP_Error( 'writeleash_job_not_resumable', Job_Reason::message( 'JOB_TERMINAL' ), array( 'status' => 409, 'job_status' => $job['status'] ) );
		}
		// Bounded work only; the worker enqueues a follow-up wake-up when items remain.
		$result = Job_Worker::run( (int) $job['id'], array(), true );
		$current = Job_Repository::read( (int) $job['id'] );
		return rest_ensure_response(
			array(
				'job' => array(
					'id' => (int) $job['id'],
					'public_id' => $job['public_id'],
					'status' => $current ? $current['status'] : $job['status'],
					'status_reason' => $current ? $current['status_reason'] : $job['status_reason'],
					'counts' => $result['counts'],
					'stop' => $result['stop'],
					'processed' => $result['processed'],
				),
			)
		);
	}

	/** Creator, approver, or an administrator. Possession of the UUID grants nothing. */
	private static function authorized( array $job ): bool {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) { return false; }
		if ( user_can( $user_id, 'manage_options' ) ) { return true; }
		return $user_id === (int) $job['creator_id'] || ( (int) $job['approver_id'] > 0 && $user_id === (int) $job['approver_id'] );
	}
}
