<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transaction-state probe and ownership operations for the guarded API.
 *
 * The active-state probe relies on documented SAVEPOINT semantics: outside a
 * transaction SAVEPOINT has no effect, so ROLLBACK TO SAVEPOINT fails with
 * MySQL error 1305; inside a transaction the savepoint exists and the
 * rollback-to succeeds. This was verified on the pinned fixtures MySQL 8.0.44
 * and MariaDB 10.11.15 and avoids INNODB_TRX (PROCESS privilege, blind to bare
 * transactions) and @@in_transaction (MySQL 8.0 does not define it).
 *
 * The probe statements run through the mysqli handle directly so that the
 * intentionally provoked 1305 does not pollute wpdb error state or logs.
 */
final class Guard_Transaction {
	private $db;

	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/** True only when the connection is inside a transaction. */
	public function active(): bool {
		$name = $this->probe_name();
		if ( false === $this->probe( 'SAVEPOINT `' . $name . '`' ) ) {
			throw new Unsupported_Transaction_State(
				'transaction_probe_failed',
				'CommitCap could not create its transaction-state probe savepoint.'
			);
		}
		if ( false !== $this->probe( 'ROLLBACK TO SAVEPOINT `' . $name . '`' ) ) {
			$this->probe( 'RELEASE SAVEPOINT `' . $name . '`' );
			return true;
		}
		$link  = $this->db->dbh;
		$errno = $link instanceof \mysqli ? $link->errno : 0;
		if ( 1305 !== $errno ) {
			throw new Unsupported_Transaction_State(
				'transaction_probe_ambiguous',
				'CommitCap transaction-state probe returned an unexpected database error.'
			);
		}
		return false;
	}

	/**
	 * A collision-resistant probe name. A fixed name could replace a caller's
	 * savepoint with the same name before the guard refuses to run, damaging
	 * caller transaction state. The name is generated only from PHP randomness
	 * and never from caller input.
	 */
	private function probe_name(): string {
		try {
			$suffix = bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $error ) {
			throw new Unsupported_Transaction_State(
				'transaction_probe_failed',
				'CommitCap could not generate a transaction-state probe name.',
				$error
			);
		}
		$name = 'commitcap_v01_tx_' . $suffix;
		if ( ! preg_match( '/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/', $name ) ) {
			throw new Unsupported_Transaction_State(
				'transaction_probe_failed',
				'CommitCap generated an invalid transaction-state probe name.'
			);
		}
		return $name;
	}

	private function probe( string $sql ) {
		$link = $this->db->dbh;
		if ( ! $link instanceof \mysqli ) {
			throw new Unsupported_Transaction_State(
				'unsupported_connection',
				'CommitCap requires a mysqli-based connection.'
			);
		}
		try {
			return $link->query( $sql );
		} catch ( \Throwable $error ) {
			throw new Unsupported_Transaction_State(
				'transaction_probe_failed',
				'CommitCap transaction-state probe failed.',
				$error
			);
		}
	}

	public function connection_id(): int {
		$value = $this->db->get_var( 'SELECT CONNECTION_ID()' );
		if ( null === $value || ! ctype_digit( (string) $value ) ) {
			throw new Guard_Error(
				'connection_identity_unreadable',
				'CommitCap could not read the database connection identity.'
			);
		}
		return (int) $value;
	}

	public function connection_unchanged( int $connection_id ): bool {
		try {
			return $this->connection_id() === $connection_id;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	public function commit(): bool {
		return false !== $this->db->query( 'COMMIT' );
	}

	/** Best-effort rollback; the caller reports the original failure. */
	public function rollback(): void {
		$this->db->query( 'ROLLBACK' );
	}
}
