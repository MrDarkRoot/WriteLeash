<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #109 per-item transactional fence guard.
 *
 * Acquires a `SELECT ... FOR UPDATE` row lock on the current job row inside the
 * #108 item transaction, on the same writer connection, and verifies
 * `(lease_owner, lease_generation)`. The lock is held until that transaction
 * COMMIT/ROLLBACK, so every generation-bumping UPDATE on the same job row
 * (`acquire_lease`, `cancel`, `pause_stalled`, `reap_stalled_leases`) blocks
 * behind the in-flight item. A newer generation therefore cannot become
 * authoritative while an older worker is committing an item.
 *
 * Lease expiry alone only makes takeover eligible; it never revokes an
 * in-flight transaction. Lock order across the item path is:
 * job fence row -> #108 journal row -> Woo product/meta/lookup rows.
 * No path takes these locks in the opposite order, so no lock cycle exists.
 */
final class Job_Transaction_Fence implements Price_Apply_Transaction_Guard {
	private int $job_id;
	private string $owner;
	private int $generation;
	private int $connection_id = 0;

	public function __construct( int $job_id, string $owner, int $generation ) {
		if ( $job_id < 1 || ! preg_match( '/\A[a-zA-Z0-9-]{1,64}\z/D', $owner ) || $generation < 1 ) { throw new Job_Error( 'INVALID_FENCE' ); }
		$this->job_id = $job_id;
		$this->owner = $owner;
		$this->generation = $generation;
	}

	public function acquire( \wpdb $tx ): void {
		$table = Job_Schema::jobs_table( $tx );
		$row = $tx->get_row( $tx->prepare( 'SELECT lease_owner,lease_generation FROM %i WHERE id=%d FOR UPDATE', $table, $this->job_id ), ARRAY_A );
		$this->connection_id = (int) $tx->dbh->thread_id;
		if ( ! is_array( $row ) || (string) $row['lease_owner'] !== $this->owner || (int) $row['lease_generation'] !== $this->generation ) {
			throw new Price_Apply_Error( 'FENCE_LOST' );
		}
	}

	public function connection_id(): int { return $this->connection_id; }
}
