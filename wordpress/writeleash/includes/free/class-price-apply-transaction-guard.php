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

/**
 * #298 optional capability for guards that also own the apply job row lock.
 *
 * The grant authorization boundary must read the job's grant pin BEFORE the
 * journal lock (approved lock order), but a plain (non-locking) SELECT at the
 * top of a REPEATABLE READ transaction establishes the read view earlier than
 * the existing locked reads do. That shifts every later non-locking read to an
 * older snapshot and changed #108 authorization behavior (the role-revocation
 * regression). Exposing the already-locked job row avoids any extra read: the
 * guard's own `SELECT ... FOR UPDATE` is a current read and cannot move the
 * snapshot. Ordinary (non-grant) jobs and journal-only callers without a grant
 * guard perform no grant lookup at all, preserving their exact prior timing.
 */
interface Price_Apply_Grant_Guard {
	/** The apply job row locked by this guard for the item transaction, or null. */
	public function locked_job_row(): ?array;
}
