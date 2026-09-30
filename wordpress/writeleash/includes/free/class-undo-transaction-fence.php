<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #110 per-item Undo transactional fence guard.
 *
 * Acquires a `SELECT ... FOR UPDATE` row lock on the Undo operation row inside
 * the Undo item transaction, on the same writer connection, and verifies
 * `(lease_owner, lease_generation)`. It then takes a plain `FOR UPDATE` lock
 * on the parent apply job row: the #109 apply path always locks that same job
 * row first inside its own item transaction, so an apply worker and an Undo
 * worker can never hold overlapping item transactions on one job. Both locks
 * are held until the Undo transaction COMMIT/ROLLBACK, so every
 * generation-bumping UPDATE on the Undo operation row (lease takeover, cancel,
 * reaper) blocks behind the in-flight Undo item.
 *
 * Lock order across the Undo path is: Undo operation row -> apply job row ->
 * Undo item row -> #108 journal row -> Woo product/meta/lookup rows. The
 * apply path order (job row -> journal row -> Woo rows) is a subsequence of
 * this order and no path takes these locks in the opposite order, so no lock
 * cycle exists. Different jobs lock independent rows and remain parallel.
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
		$table = Undo_Schema::operations_table( $tx );
		$row = $tx->get_row( $tx->prepare( 'SELECT lease_owner,lease_generation FROM %i WHERE id=%d FOR UPDATE', $table, $this->undo_id ), ARRAY_A );
		$this->connection_id = (int) $tx->dbh->thread_id;
		if ( ! is_array( $row ) || (string) $row['lease_owner'] !== $this->owner || (int) $row['lease_generation'] !== $this->generation ) {
			throw new Price_Apply_Error( 'FENCE_LOST' );
		}
		// Serialization against the #109 apply fence on the same job row.
		$jobs = Job_Schema::jobs_table( $tx );
		$job = $tx->get_row( $tx->prepare( 'SELECT id FROM %i WHERE id=%d FOR UPDATE', $jobs, $this->job_id ), ARRAY_A );
		if ( ! is_array( $job ) ) { throw new Price_Apply_Error( 'FENCE_LOST' ); }
	}

	public function connection_id(): int { return $this->connection_id; }
}
