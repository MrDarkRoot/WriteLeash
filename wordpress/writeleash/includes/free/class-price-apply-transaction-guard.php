<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Narrow #108 extension point used by #109 per-item transaction fencing.
 *
 * The guard runs inside an item transaction, after BEGIN and before the journal
 * row lock, through the same Price_Apply_Connection that performs the Woo
 * mutation. An implementation must acquire and hold its own database row lock
 * until that transaction COMMIT/ROLLBACK. It must not open another connection,
 * use object cache/transients/Action Scheduler state, start a nested or
 * separate transaction, or release the row lock early.
 *
 * This is an internal, trusted-code API only. It is never populated from REST
 * or client input, and it adds no public mutation endpoint.
 */
interface Price_Apply_Transaction_Guard {
	/**
	 * Runs inside the item transaction on the writer connection. Throw
	 * Price_Apply_Error('FENCE_LOST') to refuse the mutation before any Woo
	 * write; the caller rolls back and reports the typed result.
	 */
	public function acquire( \wpdb $tx ): void;

	/** Writer connection ID observed while the guard lock was acquired; 0 when not acquired. */
	public function connection_id(): int;
}
