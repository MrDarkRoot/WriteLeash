<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version-pinned cooperative adapter for exactly one reviewed Redirection
 * operation (#87):
 *
 *   Redirects -> select all matching -> Bulk Actions -> Disable
 *
 * Researched call path (Redirection 5.5.2):
 *   Admin REST POST /wp-json/redirection/v1/bulk/redirect/disable with
 *   `global=true` -> Red_Item::set_status_all( 'disable', $params )
 *   -> UPDATE wp_redirection_items SET status='disabled' (no WHERE when the
 *   Admin selected all matching with no filter).
 *
 * The adapter substitutes the restricted runtime wpdb for the duration of that
 * single reviewed static call, inside a Guard-owned transaction on the same
 * restricted connection. It never routes the surrounding WordPress request or
 * the item-scoped Redirection path through the restricted identity:
 * `$GLOBALS['wpdb']` is restored in a finally block on every outcome.
 *
 * Version pinning is exact: a different or missing Redirection build is
 * refused before any plugin code runs. Enable and Reset are NOT certified by
 * this class, generic Redirection support is not implemented, and no Admin
 * input selects table, method, status or filter.
 *
 * Cooperative boundary (see GUARD.md): this is application-level protection
 * for the reviewed call, not hostile-writer containment. The restricted
 * runtime holds only the reviewed CommitCap evidence grants plus
 * SELECT/UPDATE on wp_redirection_items.
 */
class Redirection_Bulk_Disable {
	public const OPERATION         = 'redirection-5.5.2-bulk-disable-global';
	public const SUPPORTED_VERSION = '5.5.2';
	public const PLUGIN_CLASS      = 'Red_Item';
	public const PLUGIN_METHOD     = 'set_status_all';
	public const PLUGIN_SLUG       = 'redirection';
	public const TABLE_SUFFIX      = 'redirection_items';
	public const DISABLE_ARG       = 'disable';

	/** @var \wpdb */
	private $db;

	/** @var int */
	private $budget;

	/** @var string */
	private $table;

	/**
	 * @param \wpdb $runtime_db Restricted shared runtime connection.
	 * @param int|string $budget Canonical nonnegative logical UPDATE budget L.
	 * @throws \InvalidArgumentException On an invalid runtime table or budget.
	 */
	public function __construct( \wpdb $runtime_db, $budget ) {
		$this->db     = $runtime_db;
		$this->budget = Update_Engine::budget( $budget );
		$this->table  = Update_Engine::table( (string) $runtime_db->prefix . self::TABLE_SUFFIX );
	}

	/** Detected Redirection version, or null when the plugin is not loaded. */
	public static function detected_version(): ?string {
		if ( ! defined( 'REDIRECTION_VERSION' ) ) {
			return null;
		}
		$version = constant( 'REDIRECTION_VERSION' );
		return is_string( $version ) && '' !== $version ? $version : null;
	}

	/** The reviewed model class and reviewed static method must both exist. */
	public static function plugin_class_available(): bool {
		return class_exists( self::PLUGIN_CLASS ) && method_exists( self::PLUGIN_CLASS, self::PLUGIN_METHOD );
	}

	/**
	 * Pure compatibility classification. Unknown and incompatible builds are
	 * never treated as supported.
	 *
	 * @return array{0: string, 1: string} Status (SUPPORTED/UNSUPPORTED/UNKNOWN)
	 *                                     and a machine reason.
	 */
	public static function compatibility_status( ?string $version, bool $class_available ): array {
		if ( null === $version ) {
			return array( 'UNKNOWN', 'redirection_missing' );
		}
		if ( self::SUPPORTED_VERSION !== $version ) {
			return array( 'UNSUPPORTED', 'redirection_version_unsupported' );
		}
		if ( ! $class_available ) {
			return array( 'UNKNOWN', 'redirection_class_missing' );
		}
		return array( 'SUPPORTED', 'ok' );
	}

	/**
	 * Adapter compatibility evidence.
	 *
	 * Protected so the test suite can simulate absent or wrong-version plugin
	 * builds without changing the production check; production always resolves
	 * the loaded plugin itself. A caller with PHP execution can bypass this
	 * class entirely, so this seam does not widen the cooperative threat model.
	 *
	 * @return array{0: string, 1: string}
	 */
	protected function compatibility(): array {
		return self::compatibility_status( self::detected_version(), self::plugin_class_available() );
	}

	/**
	 * The single reviewed plugin call. Protected only for adversarial test
	 * subclasses (exception, DB error, unexpected SQL); production always calls
	 * the pinned Redirection 5.5.2 method with the Disable status and no filter.
	 *
	 * @return int|false wpdb::query() result.
	 */
	protected function invoke_plugin() {
		return \Red_Item::set_status_all( self::DISABLE_ARG, array() );
	}

	/**
	 * Execute the certified operation and return factual, structured evidence.
	 *
	 * Outcomes: COMMITTED, DENIED, ERROR, UNKNOWN. Only a DENIED result whose
	 * post-rollback fingerprint matches the pre-run fingerprint on a separate
	 * connection reports rollback_verified = true.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$compat = $this->compatibility();
		if ( 'SUPPORTED' !== $compat[0] ) {
			return $this->result( 'UNKNOWN', $compat[1] );
		}

		$normal = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \wpdb ? $GLOBALS['wpdb'] : null;
		if ( null !== $normal && $normal !== $this->db && (string) $normal->prefix !== (string) $this->db->prefix ) {
			// A prefix mismatch would guard a different table than the plugin writes.
			return $this->result( 'UNKNOWN', 'runtime_prefix_mismatch' );
		}

		try {
			$doctor = Compatibility_Doctor::runtime( array( $this->table => $this->budget ), $this->db );
		} catch ( \Throwable $error ) {
			return $this->result( 'UNKNOWN', 'doctor_unavailable' );
		}
		$integration = isset( $doctor['integrations'][ $this->table ] ) && is_array( $doctor['integrations'][ $this->table ] )
			? $doctor['integrations'][ $this->table ]
			: null;
		if ( 'PASS' !== ( isset( $doctor['overall'] ) ? $doctor['overall'] : null ) || null === $integration || 'PASS' !== $integration['status'] ) {
			return $this->result( 'UNKNOWN', 'doctor_not_ready', array(
				'physical_ceiling' => null !== $integration && isset( $integration['physical_ceiling'] ) ? $integration['physical_ceiling'] : null,
			) );
		}
		$ceiling = isset( $integration['physical_ceiling'] ) ? $integration['physical_ceiling'] : null;
		$before  = $this->fingerprint( $normal );

		try {
			$captured = Guard::update(
				$this->table,
				$this->budget,
				function () {
					return $this->run_operation();
				},
				$this->db
			);
		} catch ( Budget_Denied $error ) {
			$details  = $error->details();
			$evidence = array(
				'physical_ceiling' => isset( $details['physical_ceiling'] ) ? $details['physical_ceiling'] : $ceiling,
				'consumed'         => isset( $details['consumed'] ) ? $details['consumed'] : null,
				'attempted'        => isset( $details['attempted'] ) ? $details['attempted'] : null,
			);
			$evidence['rollback_verified'] = $this->rollback_verified( $normal, $before );
			return $this->result(
				'DENIED',
				isset( $details['reason'] ) ? (string) $details['reason'] : 'budget_denied',
				$evidence,
				self::denial_kind( $details )
			);
		} catch ( Guard_Error $error ) {
			return $this->result( 'ERROR', $error->reason(), array( 'physical_ceiling' => $ceiling ) );
		} catch ( \Throwable $error ) {
			return $this->result( 'ERROR', 'adapter_failure', array( 'physical_ceiling' => $ceiling ) );
		}

		return $this->result( 'COMMITTED', 'ok', array(
			'physical_ceiling' => $ceiling,
			'consumed'         => null !== $captured && isset( $captured['consumed'] ) ? $captured['consumed'] : null,
			'affected'         => null !== $captured && array_key_exists( 'affected', $captured ) ? $captured['affected'] : null,
		) );
	}

	/**
	 * Swap the restricted connection into global $wpdb for the reviewed call
	 * only, always restoring the original in finally. Reads the direct helper
	 * accounting before returning so Guard/evidence do not depend on the
	 * plugin's query return value.
	 */
	private function run_operation(): array {
		$had_global = array_key_exists( 'wpdb', $GLOBALS );
		$original   = $had_global ? $GLOBALS['wpdb'] : null;

		$GLOBALS['wpdb'] = $this->db;
		try {
			$affected = $this->invoke_plugin();
		} finally {
			if ( $had_global ) {
				$GLOBALS['wpdb'] = $original;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}

		$consumed = ( new Update_Engine( $this->db ) )->state_consumed( $this->table );
		return array(
			'affected' => false === $affected ? null : (int) $affected,
			'consumed' => $consumed,
		);
	}

	/**
	 * Durable-state fingerprint on a separate observer connection.
	 *
	 * @return array{0: string, 1: string}|null Total rows and disabled rows.
	 */
	private function fingerprint( ?\wpdb $observer ): ?array {
		if ( ! $observer instanceof \wpdb || ! $observer->ready || ! $observer->dbh instanceof \mysqli || $observer === $this->db ) {
			return null;
		}
		if ( '' !== (string) $observer->last_error ) {
			return null;
		}
		$row = $observer->get_row(
			"SELECT COUNT(*) AS total, COALESCE(SUM(status = 'disabled'), 0) AS disabled FROM `" . $this->table . '`',
			ARRAY_A
		);
		if ( ! is_array( $row ) || ! isset( $row['total'], $row['disabled'] ) || '' !== (string) $observer->last_error ) {
			return null;
		}
		return array( (string) $row['total'], (string) $row['disabled'] );
	}

	private function rollback_verified( ?\wpdb $observer, ?array $before ): bool {
		$after = $this->fingerprint( $observer );
		return null !== $before && null !== $after && $before === $after;
	}

	/** @param array<string, mixed> $details Budget_Denied::details(). */
	private static function denial_kind( array $details ): ?string {
		$reason = isset( $details['reason'] ) ? (string) $details['reason'] : '';
		if ( 'logical_budget_exceeded' === $reason ) {
			return 'logical';
		}
		if ( in_array( $reason, array( 'budget_exceeded', 'denial_signal' ), true )
			&& isset( $details['sqlstate'] ) && '45000' === $details['sqlstate'] ) {
			return 'physical';
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $evidence
	 * @return array<string, mixed>
	 */
	private function result( string $outcome, string $reason, array $evidence = array(), ?string $denial_kind = null ): array {
		return array(
			'operation_id'      => self::OPERATION,
			'plugin'            => self::PLUGIN_SLUG,
			'plugin_version'    => self::detected_version(),
			'supported_version' => self::SUPPORTED_VERSION,
			'table'             => $this->table,
			'logical_budget'    => $this->budget,
			'physical_ceiling'  => isset( $evidence['physical_ceiling'] ) ? $evidence['physical_ceiling'] : null,
			'consumed'          => isset( $evidence['consumed'] ) ? $evidence['consumed'] : null,
			'attempted'         => isset( $evidence['attempted'] ) ? $evidence['attempted'] : null,
			'affected_rows'     => isset( $evidence['affected'] ) ? $evidence['affected'] : null,
			'outcome'           => $outcome,
			'denial_kind'       => $denial_kind,
			'rollback_verified' => ! empty( $evidence['rollback_verified'] ),
			'reason'            => $reason,
		);
	}
}
