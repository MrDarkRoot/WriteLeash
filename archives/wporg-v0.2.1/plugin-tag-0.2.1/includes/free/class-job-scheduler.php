<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler wake-up integration. Action Scheduler 4.0.0 is supplied by
 * the pinned WooCommerce fixture; it is a scheduler only. Enqueue IDs, logs and
 * uniqueness flags are diagnostics, never job truth or worker mutual exclusion.
 */
final class Job_Scheduler {
	public const HOOK = 'writeleash_process_job';
	public const GROUP = 'writeleash-jobs';

	/** Availability is verified after initialization, not from Woo being active. */
	public static function available(): bool {
		return function_exists( 'as_enqueue_async_action' ) && class_exists( '\ActionScheduler' ) && \ActionScheduler::is_initialized();
	}

	/** Returns the wake-up action ID, or 0 when the scheduler cannot accept it. */
	public static function enqueue( int $job_id ): int {
		if ( $job_id < 1 || ! self::available() ) { return 0; }
		$action_id = as_enqueue_async_action( self::HOOK, array( $job_id ), self::GROUP, false, 10 );
		return is_int( $action_id ) && $action_id > 0 ? $action_id : 0;
	}

	/** Deactivation/uninstall: cancel only the WriteLeash-owned action group. */
	public static function unschedule_all(): void {
		if ( ! self::available() ) { return; }
		try {
			\ActionScheduler::store()->cancel_actions_by_group( self::GROUP );
		} catch ( \Throwable $error ) {
			// Domain truth is in the job tables; scheduler cleanup is best effort.
		}
	}
}
