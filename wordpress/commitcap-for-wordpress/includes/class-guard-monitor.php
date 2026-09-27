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
 * hole for ordinary $wpdb callers, and the exact #54 denial identity
 * (CC54_DENIED + errno 1644 + SQLSTATE 45000) is latched separately so a
 * later successful query cannot downgrade a budget denial to a generic error.
 * Direct mysqli/$wpdb->dbh use is outside the supported callback contract and
 * cannot be observed.
 *
 * Guard-reserved SQL (transaction control, autocommit changes, all CALLs,
 * @commitcap_v01_* references) is classified by Guard_Sql, which understands
 * comments and formatting variation and fails closed on ambiguous statements.
 * The monitor detects
 * cooperative contract violations; it does not make a hostile caller
 * impossible.
 */
final class Guard_Monitor {
	private $primary;
	private $connections = array();
	private $db_error    = false;
	private $denial      = false;
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
			if ( ! $this->denial && $entry['db'] === $this->primary && self::is_denial_state( $entry['db'] ) ) {
				$this->denial = true;
			}
			$this->connections[ $id ]['last'] = $error;
		}
		if ( null === $this->violation && is_string( $query ) && Guard_Sql::CLEAR !== Guard_Sql::classify( $query ) ) {
			$this->violation = 'callback_issued_reserved_sql';
		}
		return $query;
	}

	/** True when the primary connection holds exact #54 denial evidence. */
	private static function is_denial_state( \wpdb $db ): bool {
		$link = $db->dbh;
		return $link instanceof \mysqli &&
			'CC54_DENIED' === (string) $db->last_error &&
			1644 === $link->errno &&
			'45000' === $link->sqlstate;
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

	/** Structured #54 budget-denial evidence latched before wpdb cleared it. */
	public function budget_denial(): bool {
		return $this->denial;
	}

	public function contract_violation(): ?string {
		return $this->violation;
	}
}
