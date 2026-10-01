<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler wake-up integration for Undo and retention.
 * Action Scheduler is a scheduler only: wake-up IDs, logs and uniqueness
 * flags are diagnostics, never Undo truth or worker mutual exclusion.
 *
 * Owned groups: `writeleash-undo` (Undo item chunks) and
 * `writeleash-maintenance` (bounded retention purge wake-ups). Deactivation
 * cancels exactly these plus the #109 `writeleash-jobs` group; uninstall
 * never touches Action Scheduler tables.
 */
final class Undo_Scheduler {
	public const HOOK = 'writeleash_process_undo';
	public const GROUP = 'writeleash-undo';
	public const PURGE_HOOK = 'writeleash_retention_purge';
	public const PURGE_GROUP = 'writeleash-maintenance';

	/** Availability is verified after initialization, not from Woo being active. */
	public static function available(): bool {
		return function_exists( 'as_enqueue_async_action' ) && class_exists( '\ActionScheduler' ) && \ActionScheduler::is_initialized();
	}

	/** Returns the wake-up action ID, or 0 when the scheduler cannot accept it. */
	public static function enqueue( int $undo_id ): int {
		if ( $undo_id < 1 || ! self::available() ) { return 0; }
		$action_id = as_enqueue_async_action( self::HOOK, array( $undo_id ), self::GROUP, false, 10 );
		return is_int( $action_id ) && $action_id > 0 ? $action_id : 0;
	}

	/** Bounded retention purge wake-up; eligibility still comes from durable timestamps. */
	public static function enqueue_purge(): int {
		if ( ! self::available() ) { return 0; }
		$action_id = as_enqueue_async_action( self::PURGE_HOOK, array(), self::PURGE_GROUP, false, 10 );
		return is_int( $action_id ) && $action_id > 0 ? $action_id : 0;
	}

	/** Deactivation: cancel only the WriteLeash-owned Undo/maintenance action groups. */
	public static function unschedule_all(): void {
		if ( ! self::available() ) { return; }
		try {
			\ActionScheduler::store()->cancel_actions_by_group( self::GROUP );
		} catch ( \Throwable $error ) {
			// Domain truth is in the Undo tables; scheduler cleanup is best effort.
		}
		try {
			\ActionScheduler::store()->cancel_actions_by_group( self::PURGE_GROUP );
		} catch ( \Throwable $error ) {
			// Domain truth is in the Undo tables; scheduler cleanup is best effort.
		}
	}

	/** Retention purge callback. Never throws: purge eligibility is durable, scheduler state is not. */
	public static function purge_callback(): void {
		try { Undo_Repository::purge_expired( Undo_Repository::PURGE_BATCH ); }
		catch ( \Throwable $error ) {
			// Purge is best effort; expired evidence simply remains until the next wake-up.
		}
	}
}
