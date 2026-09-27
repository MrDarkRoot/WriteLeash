<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Observes every $wpdb::query() issued while the guarded callback runs.
 *
 * wpdb applies the 'query' filter before it flushes its error state, so a
 * failed statement that a callback ignored can be latched before the next
 * successful query clears $wpdb->last_error. That closes the swallowed-false
 * hole for ordinary $wpdb callers. Direct mysqli/$wpdb->dbh use is outside the
 * supported callback contract and cannot be observed.
 *
 * The monitor also latches SQL reserved for the Guard's transaction lifecycle
 * (transaction control, autocommit changes, direct #54 routine calls and
 * direct writes to @commitcap_v01_* variables). It detects cooperative
 * contract violations; it does not make a hostile caller impossible.
 */
final class Guard_Monitor {
	private $primary;
	private $connections = array();
	private $db_error    = false;
	private $violation   = null;
	private $active      = false;

	public function __construct( \wpdb $db ) {
		$this->primary = $db;
	}

	public function start(): void {
		if ( $this->active ) {
			return;
		}
		$candidates = array( $this->primary );
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \wpdb && $GLOBALS['wpdb'] !== $this->primary ) {
			$candidates[] = $GLOBALS['wpdb'];
		}
		foreach ( $candidates as $db ) {
			if ( ! $db->dbh instanceof \mysqli ) {
				continue;
			}
			$error = (string) $db->last_error;
			$this->connections[ spl_object_id( $db ) ] = array(
				'db'       => $db,
				'baseline' => $error,
				'last'     => $error,
			);
		}
		add_filter( 'query', array( $this, 'observe' ), PHP_INT_MAX );
		$this->active = true;
	}

	public function stop(): void {
		if ( ! $this->active ) {
			return;
		}
		remove_filter( 'query', array( $this, 'observe' ), PHP_INT_MAX );
		$this->active = false;
	}

	public function observe( $query ) {
		foreach ( $this->connections as $id => $entry ) {
			$error = (string) $entry['db']->last_error;
			if ( '' !== $error && $error !== $entry['last'] ) {
				$this->db_error = true;
			}
			$this->connections[ $id ]['last'] = $error;
		}
		if ( null === $this->violation && is_string( $query ) && self::reserved_sql( $query ) ) {
			$this->violation = 'callback_issued_reserved_sql';
		}
		return $query;
	}

	public function database_error(): bool {
		if ( $this->db_error ) {
			return true;
		}
		foreach ( $this->connections as $entry ) {
			$error = (string) $entry['db']->last_error;
			if ( '' === $error ) {
				continue;
			}
			if ( $entry['db'] === $this->primary || $error !== $entry['baseline'] ) {
				return true;
			}
		}
		return false;
	}

	public function contract_violation(): ?string {
		return $this->violation;
	}

	private static function reserved_sql( string $query ): bool {
		if ( preg_match( '/\A\s*(?:START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE\s+SAVEPOINT)\b/i', $query ) ) {
			return true;
		}
		if ( preg_match( '/\A\s*SET\s+autocommit\b/i', $query ) ) {
			return true;
		}
		if ( preg_match( '/\A\s*CALL\s+`?commitcap_v01_/i', $query ) ) {
			return true;
		}
		if ( preg_match( '/\A\s*SET\s+@commitcap_v01_/i', $query ) ) {
			return true;
		}
		return false;
	}
}
