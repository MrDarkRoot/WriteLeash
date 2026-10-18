<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #110 per-item Undo transactional fence guard.
 *
 * Acquires a `SELECT ... FOR UPDATE` row lock on the parent apply job row
 * inside the Undo item transaction, on the same writer connection, then locks
 * the Undo operation row and verifies `(lease_owner, lease_generation)`.
 *
 * Deterministic global lock order (repair for #110 blocker 3): every path
 * that needs both rows takes the apply job row first and the Undo operation
 * row second:
 *
 *   indexed lifecycle option row -> parent apply job row
 *   -> Undo operation row -> Undo item row
 *   -> apply job item / #108 journal row -> Woo product/context rows
 *
 * `Undo_Repository::initiate()` and the retention purge take the same job
 * row first, so a purge can never interleave with an in-flight item or with
 * initiation creation. The #109 apply path (job row -> journal row -> Woo
 * rows) is a subsequence of this order. No path takes these locks in the
 * opposite order, so no deadlock cycle exists. Both locks are held until the
 * Undo transaction COMMIT/ROLLBACK, so every generation-bumping UPDATE on
 * the Undo operation row (lease takeover, cancel, reaper) blocks behind the
 * in-flight Undo item exactly as before.
 */
final class Undo_Transaction_Fence implements Price_Apply_Transaction_Guard {
	private int $undo_id;
	private int $job_id;
	private string $owner;
	private int $generation;
	private int $connection_id = 0;

	public function __construct( int $undo_id, int $job_id, string $owner, int $generation ) {
		if ( $undo_id < 1 || $job_id < 1 || ! preg_match( '/\A[a-zA-Z0-9-]{1,64}\z/D', $owner ) || $generation < 1 ) { throw new Undo_Error( 'INVALID_FENCE' ); }
		$this->undo_id = $undo_id;
		$this->job_id = $job_id;
		$this->owner = $owner;
		$this->generation = $generation;
	}

	public function acquire( \wpdb $tx ): void {
		// Shutdown contends on this existing indexed option row before any
		// job/operation lock. The lock lives until COMMIT or ROLLBACK.
		if ( ! Runner_Authority::lock_active( $tx ) ) { throw new Price_Apply_Error( 'DEACTIVATED' ); }
		// 1. Parent apply job row: serializes against #109 apply workers, #110
		// initiation and #110 retention purge (all lock this row first).
		$jobs = Job_Schema::jobs_table( $tx );
		$job = $tx->get_row( $tx->prepare( 'SELECT id FROM %i WHERE id=%d FOR UPDATE', $jobs, $this->job_id ), ARRAY_A );
		if ( ! is_array( $job ) ) { throw new Price_Apply_Error( 'FENCE_LOST' ); }
		// 2. Undo operation row: verifies the live lease fence.
		$table = Undo_Schema::operations_table( $tx );
		$row = $tx->get_row( $tx->prepare( 'SELECT lease_owner,lease_generation FROM %i WHERE id=%d FOR UPDATE', $table, $this->undo_id ), ARRAY_A );
		if ( ! is_array( $row ) || (string) $row['lease_owner'] !== $this->owner || (int) $row['lease_generation'] !== $this->generation ) {
			throw new Price_Apply_Error( 'FENCE_LOST' );
		}
		$this->connection_id = (int) $tx->dbh->thread_id;
	}

	public function connection_id(): int { return $this->connection_id; }
}
