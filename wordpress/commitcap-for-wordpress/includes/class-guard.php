<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cooperative guarded UPDATE transaction API (#56).
 *
 * Guard::update() owns one explicit DB transaction, one #54 UPDATE accounting
 * policy and the final COMMIT/ROLLBACK. Failures observed before the Guard's
 * COMMIT call while the owned transaction is still intact cause a full
 * ROLLBACK before control returns to the caller. A callback can instead end
 * the transaction (including through an opaque DB path); then durability may
 * already exist. Unknown COMMIT outcomes and post-commit anomalies cannot
 * promise rollback either. See GUARD.md, "Failure timing and durability".
 *
 * This is cooperative application-level enforcement. It does not turn
 * MySQL/MariaDB into a hostile-writer security boundary. SQL outside this API,
 * direct transaction control, CALLs and side-effecting stored functions are
 * outside the supported contract; direct mysqli/$wpdb->dbh SQL cannot be
 * monitored.
 */
final class Guard {
	private static $active = false;

	/**
	 * Runs one bounded UPDATE job inside a Guard-owned transaction.
	 *
	 * @param string   $table    Simple unqualified table name with a verified policy.
	 * @param int|string $budget Canonical nonnegative UPDATE row-event budget.
	 * @param callable $callback Receives no arguments. Must use $db for its SQL.
	 * @param \wpdb|null $db     Connection to guard; defaults to $GLOBALS['wpdb'].
	 * @return mixed The callback return value, after COMMIT.
	 * @throws Budget_Denied                  On a #54 budget denial (rollback while
	 *                                         the owned transaction remains intact).
	 * @throws Unsupported_Transaction_State  When the guard refuses before running.
	 * @throws Guard_Error                    On other guarded failures. Rollback applies
	 *                                         only while the owned transaction remains
	 *                                         intact before Guard COMMIT; transaction
	 *                                         loss may already be durable, COMMIT may
	 *                                         be unknown, and post-COMMIT changes may
	 *                                         be durable. See GUARD.md, "Failure timing
	 *                                         and durability".
	 * @throws \InvalidArgumentException      On an invalid table name or budget.
	 */
	public static function update( $table, $budget, $callback, ?\wpdb $db = null ) {
		if ( self::$active ) {
			throw new Unsupported_Transaction_State(
				'nested_guard',
				'CommitCap does not support nested guards; complete the outer guard first.'
			);
		}
		$name  = Update_Engine::table( $table );
		$limit = Update_Engine::budget( $budget );
		if ( ! is_callable( $callback ) ) {
			throw new \InvalidArgumentException( 'CommitCap requires a callable callback.' );
		}
		if ( null === $db ) {
			$db = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		}
		if ( ! $db instanceof \wpdb ) {
			throw new Unsupported_Transaction_State(
				'no_connection',
				'CommitCap requires a WordPress database connection.'
			);
		}
		if ( ! $db->ready || ! $db->dbh instanceof \mysqli ) {
			throw new Unsupported_Transaction_State(
				'unsupported_connection',
				'CommitCap requires a ready mysqli-based wpdb connection.'
			);
		}
		if ( '' !== (string) $db->last_error ) {
			throw new Unsupported_Transaction_State(
				'dirty_error_state',
				'CommitCap requires a connection with no prior database error state.'
			);
		}

		$transaction = new Guard_Transaction( $db );
		$autocommit  = $db->get_var( 'SELECT @@autocommit' );
		if ( null === $autocommit ) {
			throw new Unsupported_Transaction_State(
				'connection_unavailable',
				'CommitCap could not read the connection autocommit state.'
			);
		}
		if ( '1' !== (string) $autocommit ) {
			throw new Unsupported_Transaction_State(
				'autocommit_off',
				'CommitCap requires an autocommit=1 connection.'
			);
		}
		if ( $transaction->active() ) {
			throw new Unsupported_Transaction_State(
				'existing_transaction',
				'CommitCap refuses to start inside an existing transaction.'
			);
		}

		$engine = new Update_Engine( $db );
		try {
			$ceiling = $engine->verify_runtime_budget( $name, $limit );
		} catch ( \Throwable $error ) {
			throw new Guard_Error(
				'policy_unverified',
				'CommitCap could not verify the runtime policy for ' . $name . '.',
				$error
			);
		}

		// A committed accounting row means a previous run ended outside the
		// guard (for example a direct callback COMMIT). Refuse before mutating.
		// The unmediated helper read is used so no routine body is load-bearing
		// for the safety invariant.
		try {
			$stale = $engine->state_consumed( $name );
		} catch ( \Throwable $error ) {
			throw new Guard_Error(
				'accounting_precheck_failed',
				'CommitCap could not read accounting state for ' . $name . '.',
				$error
			);
		}
		if ( null !== $stale ) {
			throw new Unsupported_Transaction_State(
				'stale_accounting_state',
				'Committed CommitCap accounting state already exists for this connection and policy; trusted maintenance cleanup is required.'
			);
		}

		self::$active = true;
		$monitor      = null;
		try {
			if ( false === $db->query( 'START TRANSACTION' ) ) {
				throw new Guard_Error( 'transaction_start_failed', 'CommitCap could not start the guarded transaction.' );
			}
			$engine->begin_guard_state();
			$engine->begin_accounting( $name, $limit );

			$connection_id = $transaction->connection_id();
			$monitor       = new Guard_Monitor( $db );
			$monitor->start();

			try {
				$result = call_user_func( $callback );
			} catch ( \Throwable $error ) {
				$monitor->stop();
				$transaction->rollback();
				if ( $error instanceof Guard_Error ) {
					throw $error;
				}
				throw new Guard_Error(
					'callback_failed',
					'The guarded callback failed: ' . $error->getMessage(),
					$error
				);
			}
			$monitor->stop();

			// Capture every failure signal before any further query clears it.
			$denial        = $engine->is_budget_denial() ? $engine->denial_details( $name, $limit, $ceiling ) : null;
			$generic_error = $monitor->database_error();
			$violation     = $monitor->contract_violation();
			$swallowed     = $monitor->budget_denial();
			if ( null !== $denial ) {
				$transaction->rollback();
				throw new Budget_Denied( $denial );
			}
			if ( null !== $violation ) {
				$transaction->rollback();
				throw new Guard_Error(
					$violation,
					'The guarded callback issued SQL outside the supported Guard transaction contract.'
				);
			}
			// A #54 denial whose raw mysqli state was cleared by a later query is
			// still a budget denial: keep the typed classification ahead of the
			// generic database error it also produced.
			if ( $swallowed ) {
				$denial = self::denial_details( $engine, $name, $limit, $ceiling );
				$transaction->rollback();
				throw new Budget_Denied( $denial );
			}
			if ( ! $transaction->connection_unchanged( $connection_id ) ) {
				$transaction->rollback();
				throw new Guard_Error(
					'connection_changed',
					'The database connection changed during the guarded callback.'
				);
			}
			// The session denial signal lives outside the transaction and belongs
			// to this connection, so it is read only after the identity check.
			if ( $engine->denial_seen() ) {
				$denial = self::denial_details( $engine, $name, $limit, $ceiling );
				$transaction->rollback();
				throw new Budget_Denied( $denial );
			}
			if ( $generic_error ) {
				$transaction->rollback();
				throw new Guard_Error(
					'database_error',
					'A database operation failed during the guarded callback.'
				);
			}
			if ( ! $transaction->active() ) {
				throw new Guard_Error(
					'transaction_lost',
					'The guarded transaction is no longer active; guarded changes may already be durable.'
				);
			}
			try {
				$consumed = $engine->state_consumed( $name );
			} catch ( \Throwable $error ) {
				$transaction->rollback();
				throw new Guard_Error(
					'accounting_invalid',
					'CommitCap could not read valid accounting state before commit.',
					$error
				);
			}
			if ( null === $consumed ) {
				$transaction->rollback();
				throw new Guard_Error(
					'accounting_invalid',
					'CommitCap could not read valid accounting state before commit.'
				);
			}
			if ( $consumed > $limit ) {
				$transaction->rollback();
				throw new Budget_Denied( array(
					'table'            => $name,
					'budget'           => $limit,
					'consumed'         => $consumed,
					'attempted'        => $consumed,
					'returned_count'   => $consumed,
					'reason'           => 'logical_budget_exceeded',
					'sqlstate'         => null,
					'errno'            => null,
					'physical_ceiling' => $ceiling,
				) );
			}
			$engine->end_accounting( $name );
			if ( ! $transaction->active() ) {
				throw new Guard_Error(
					'transaction_lost_after_close',
					'The guarded transaction ended before the Guard commit; guarded changes may already be durable.'
				);
			}
			if ( ! $transaction->commit() ) {
				// After COMMIT is sent the outcome is unknown: the transaction may
				// already be durable. The rollback attempt is best-effort only and
				// is not claimed to undo a successful commit.
				$transaction->rollback();
				throw new Guard_Error(
					'commit_failed_or_unknown',
					'CommitCap could not confirm the guarded COMMIT; durability is unknown.'
				);
			}
			$post_state = null;
			$post_error = false;
			try {
				$post_state = $engine->state_consumed( $name );
			} catch ( \Throwable $error ) {
				$post_error = true;
			}
			if ( $transaction->active() || $post_error || null !== $post_state ) {
				// The COMMIT call appeared to succeed, so changes may already be
				// durable even though the connection state is not as expected.
				throw new Guard_Error(
					'post_commit_state',
					'The connection is not in the expected post-commit state; the commit may already be durable.'
				);
			}
			return $result;
		} catch ( Guard_Error $error ) {
			$transaction->rollback();
			throw $error;
		} catch ( \Throwable $error ) {
			$transaction->rollback();
			throw new Guard_Error(
				'guard_failure',
				'CommitCap guard failed: ' . $error->getMessage(),
				$error
			);
		} finally {
			if ( $monitor instanceof Guard_Monitor ) {
				$monitor->stop();
			}
			self::$active = false;
		}
	}

	/**
	 * Structured denial facts for a denial proven by the monitor latch or the
	 * session signal after the raw mysqli state was cleared.
	 */
	private static function denial_details( Update_Engine $engine, string $table, int $budget, ?int $ceiling = null ): array {
		$count = null;
		try {
			$count = $engine->state_consumed( $table );
		} catch ( \Throwable $error ) {
			$count = null;
		}
		$details = array(
			'table'          => $table,
			'budget'         => $budget,
			'consumed'       => $count,
			'attempted'      => null === $count ? null : $count + 1,
			'returned_count' => $count,
			'reason'         => 'denial_signal',
			'sqlstate'       => '45000',
			'errno'          => 1644,
		);
		if ( null !== $ceiling ) {
			$details['physical_ceiling'] = $ceiling;
		}
		return $details;
	}
}
