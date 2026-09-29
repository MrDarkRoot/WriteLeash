<?php
namespace WriteLeash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WriteLeash-owned disposable demo only; not a certified product operation. */
final class Disposable_Demo {
	public const SUFFIX = 'writeleash_demo_rows';
	public const LABEL = 'WriteLeash-owned disposable demo';
	public const LOGICAL_BUDGET = 5;
	// Six physical events must succeed so Guard can deny the sixth at pre-COMMIT.
	public const PHYSICAL_CEILING = 6;
	public const TABLE_COMMENT = 'WriteLeash-owned disposable demo rows v1';
	private const STATE_A = 'A';
	private const STATE_B = 'B';

	/** @var \wpdb|null Explicit restricted secondary connection (or operator config). */
	private $runtime;

	public function __construct( ?\wpdb $runtime = null ) {
		$this->runtime = $runtime;
	}

	public static function table( string $prefix ): string {
		return Update_Engine::table( $prefix . self::SUFFIX );
	}

	private static function result( string $status, string $reason, array $facts = array() ): array {
		return array_merge( array(
			'label' => self::LABEL,
			'status' => $status,
			'reason' => $reason,
			'logical_budget' => self::LOGICAL_BUDGET,
			'physical_ceiling' => self::PHYSICAL_CEILING,
		), $facts );
	}

	/** Live, credential-free runtime Doctor; never cache a previous PASS. */
	public function status(): array {
		$normal = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$db = $this->runtime ?: Certified_Operation_Status::runtime_connection();
		if ( ! $normal instanceof \wpdb || ! $normal->ready || ! $db instanceof \wpdb ||
			$db === $normal || ! $db->ready || ! $db->dbh instanceof \mysqli ) {
			return self::result( 'NOT_READY', 'runtime_unavailable' );
		}
		$this->runtime = $db;
		try {
			$table = self::table( (string) $normal->prefix );
			if ( (string) $db->prefix !== (string) $normal->prefix ||
				$db->get_var( 'SELECT DATABASE()' ) !== $normal->get_var( 'SELECT DATABASE()' ) ||
				$db->get_var( 'SELECT @@hostname' ) !== $normal->get_var( 'SELECT @@hostname' ) ||
				$db->get_var( 'SELECT @@port' ) !== $normal->get_var( 'SELECT @@port' ) ||
				$db->get_var( 'SELECT CURRENT_USER()' ) === $normal->get_var( 'SELECT CURRENT_USER()' ) ||
				'' !== (string) $db->last_error || '' !== (string) $normal->last_error ) {
				return self::result( 'NOT_READY', 'runtime_identity_mismatch' );
			}
			$schema = $db->get_var( 'SELECT DATABASE()' );
			$grants = is_string( $schema ) && '' !== $schema ? Compatibility_Grants::read( $db, $schema ) : null;
			$exact = $grants ? $grants->target_access_exact( $table, array( 'SELECT', 'UPDATE' ) ) : array( 'UNKNOWN' );
			if ( 'PASS' !== $exact[0] ) {
				return self::result( 'NOT_READY', 'target_privileges_mismatch', array( 'table' => $table, 'exact_grants' => $exact[0] ) );
			}
			if ( ! self::owned_table_shape( $db, $table ) ) {
				return self::result( 'NOT_READY', 'demo_table_not_owned', array( 'table' => $table, 'exact_grants' => 'PASS' ) );
			}
			$policies = array( $table => self::LOGICAL_BUDGET );
			// The shared runtime may also serve the one certified Redirection
			// policy. Account for its trigger graph, never hide it as "foreign".
			$sibling = Certified_Operation::redirection_5_5_2_bulk_disable();
			$sibling_table = Update_Engine::table( (string) $db->prefix . $sibling->table_suffix() );
			if ( 'PASS' === $grants->target_access( $sibling_table )[0] ) {
				if ( 'PASS' !== $grants->target_access_exact( $sibling_table, $sibling->target_privileges() )[0] ) {
					return self::result( 'NOT_READY', 'sibling_privileges_mismatch' );
				}
				$policies[ $sibling_table ] = null;
			}
			// Certify sibling reachability without a behavioral UPDATE of its
			// application data: this disposable demo touches only its own table.
			$doctor = Compatibility_Doctor::runtime( $policies, $db, array( $table ) );
			$integration = isset( $doctor['integrations'][ $table ] ) ? $doctor['integrations'][ $table ] : null;
			if ( 'PASS' !== $doctor['overall'] || ! is_array( $integration ) || 'PASS' !== $integration['status'] ) {
				return self::result( 'NOT_READY', 'doctor_not_ready', array( 'table' => $table, 'doctor' => $doctor, 'exact_grants' => 'PASS' ) );
			}
			if ( array_key_exists( $sibling_table, $policies ) ) {
				if ( $sibling->physical_ceiling() !== $doctor['integrations'][ $sibling_table ]['physical_ceiling'] ) {
					return self::result( 'NOT_READY', 'sibling_ceiling_mismatch' );
				}
			}
			if ( self::PHYSICAL_CEILING !== $integration['physical_ceiling'] ) {
				return self::result( 'NOT_READY', 'physical_ceiling_mismatch', array( 'table' => $table, 'doctor' => $doctor, 'exact_grants' => 'PASS', 'actual_physical_ceiling' => $integration['physical_ceiling'] ) );
			}
			$state = $this->canonical_state( $table );
			if ( '' !== (string) $db->last_error ) {
				return self::result( 'NOT_READY', 'demo_state_unavailable' );
			}
			if ( null === $state ) {
				return self::result( 'NOT_READY', 'trusted_reset_required', array( 'table' => $table ) );
			}
			return self::result( 'READY', 'ok', array( 'table' => $table, 'canonical_state' => $state, 'doctor' => $doctor, 'exact_grants' => 'PASS', 'actual_physical_ceiling' => $integration['physical_ceiling'] ) );
		} catch ( \Throwable $error ) {
			return self::result( 'NOT_READY', 'doctor_unavailable' );
		}
	}

	/** Exact name, engine, comment, two columns and primary key; reject foreign collisions. */
	public static function owned_table_shape( \wpdb $db, string $table ): bool {
		$meta = $db->get_row( $db->prepare(
			'SELECT TABLE_TYPE, ENGINE, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table
		), ARRAY_A );
		$columns = $db->get_results( $db->prepare(
			'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION', $table
		), ARRAY_A );
		$keys = $db->get_results( $db->prepare(
			'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX', $table
		), ARRAY_A );
		return '' === (string) $db->last_error && is_array( $meta ) && 'BASE TABLE' === $meta['TABLE_TYPE'] &&
			'InnoDB' === $meta['ENGINE'] && self::TABLE_COMMENT === $meta['TABLE_COMMENT'] &&
			is_array( $columns ) && 2 === count( $columns ) &&
			array( 'id', 'value' ) === array_column( $columns, 'COLUMN_NAME' ) &&
			(bool) preg_match( '/\Aint(?:\(11\))?\z/i', $columns[0]['COLUMN_TYPE'] ) &&
			(bool) preg_match( '/\Aint(?:\(11\))?\z/i', $columns[1]['COLUMN_TYPE'] ) &&
			'NO' === $columns[0]['IS_NULLABLE'] && 'NO' === $columns[1]['IS_NULLABLE'] &&
			array( array( 'INDEX_NAME' => 'PRIMARY', 'COLUMN_NAME' => 'id' ) ) === $keys;
	}

	/**
	 * Run the fixed five-row commit followed by the six-row logical denial.
	 * A trusted initial seed establishes A. Every successful run toggles A/B
	 * using only the restricted runtime SELECT/UPDATE grants.
	 * Returns Guard facts, never claims independent durability verification.
	 */
	public function run(): array {
		$ready = $this->status();
		if ( 'READY' !== $ready['status'] ) {
			return self::result( 'NOT_READY', $ready['reason'], array( 'ready' => $ready ) );
		}
		$table = $ready['table'];
		$input = $ready['canonical_state'];
		$next = self::STATE_A === $input ? self::STATE_B : self::STATE_A;
		$safe_sql = self::STATE_A === $input
			? "UPDATE `$table` SET value = 1 WHERE id BETWEEN 1 AND 5 AND value = 0"
			: "UPDATE `$table` SET value = 0 WHERE id BETWEEN 1 AND 5 AND value = 1";
		$safe = $this->execute( $table, $safe_sql, 5 );
		if ( 'COMMITTED' !== $safe['outcome'] || 5 !== $safe['consumed'] || 5 !== $safe['affected_rows'] ) {
			return self::result( 'ERROR', 'safe_run_not_confirmed', array( 'safe' => $safe ) );
		}
		// A new Doctor check gates the second callback even after the first commit.
		$ready = $this->status();
		if ( 'READY' !== $ready['status'] || $next !== $ready['canonical_state'] ) {
			return self::result( 'NOT_READY', 'denied_run_not_ready', array( 'safe' => $safe, 'ready' => $ready ) );
		}
		$denied = $this->execute( $table, "UPDATE `$table` SET value = 2 WHERE id BETWEEN 1 AND 6", 6 );
		if ( 'DENIED' !== $denied['outcome'] || 'logical' !== $denied['denial_kind'] ||
			6 !== $denied['consumed'] || 6 !== $denied['attempted'] || ! $denied['transaction_rollback_attempted'] ) {
			return self::result( 'ERROR', 'logical_denial_not_confirmed', array( 'safe' => $safe, 'denied' => $denied ) );
		}
		return self::result( 'COMPLETE', 'guard_paths_exercised', array( 'input_state' => $input, 'safe_state' => $next, 'safe' => $safe, 'denied' => $denied ) );
	}

	/** Standalone six-row denial from either canonical state. */
	public function run_denied(): array {
		$ready = $this->status();
		if ( 'READY' !== $ready['status'] ) {
			return self::result( 'NOT_READY', $ready['reason'] );
		}
		$table = $ready['table'];
		$denied = $this->execute( $table, "UPDATE `$table` SET value = 2 WHERE id BETWEEN 1 AND 6", 6 );
		if ( 'DENIED' !== $denied['outcome'] || 'logical' !== $denied['denial_kind'] ||
			6 !== $denied['consumed'] || 6 !== $denied['attempted'] || ! $denied['transaction_rollback_attempted'] ) {
			return self::result( 'ERROR', 'logical_denial_not_confirmed', array( 'denied' => $denied ) );
		}
		return self::result( 'COMPLETE', 'logical_denial_exercised', array( 'input_state' => $ready['canonical_state'], 'denied' => $denied ) );
	}

	/** The only two runnable row layouts; all other layouts need trusted repair. */
	private function canonical_state( string $table ): ?string {
		$rows = $this->runtime_db()->get_results( "SELECT id, value FROM `$table` ORDER BY id", ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $this->runtime_db()->last_error || 6 !== count( $rows ) ) {
			return null;
		}
		$values = array();
		foreach ( $rows as $index => $row ) {
			if ( $index + 1 !== (int) $row['id'] ) {
				return null;
			}
			$values[] = (int) $row['value'];
		}
		if ( array( 0, 0, 0, 0, 0, 0 ) === $values ) {
			return self::STATE_A;
		}
		return array( 1, 1, 1, 1, 1, 0 ) === $values ? self::STATE_B : null;
	}

	private function runtime_db(): \wpdb {
		return $this->runtime ?: Certified_Operation_Status::runtime_connection();
	}

	private function execute( string $table, string $sql, int $count ): array {
		$db = $this->runtime_db();
		$facts = array(
			'label' => self::LABEL, 'outcome' => 'ERROR', 'reason' => 'guard_failure',
			'logical_budget' => self::LOGICAL_BUDGET, 'physical_ceiling' => self::PHYSICAL_CEILING,
			'attempted' => $count, 'consumed' => null, 'affected_rows' => null,
			'denial_kind' => null, 'transaction_rollback_attempted' => false,
			'guard_rollback_completed' => null, 'durability_verified_by_fresh_observer' => false,
		);
		try {
			$observed = Guard::update( $table, self::LOGICAL_BUDGET, function () use ( $db, $sql, $table ) {
				$affected = $db->query( $sql );
				return array( 'affected' => $affected, 'consumed' => ( new Update_Engine( $db ) )->state_consumed( $table ) );
			}, $db );
			$facts['outcome'] = 'COMMITTED';
			$facts['reason'] = 'ok';
			$facts['affected_rows'] = false === $observed['affected'] ? null : (int) $observed['affected'];
			$facts['consumed'] = $observed['consumed'];
		} catch ( Budget_Denied $error ) {
			$details = $error->details();
			$facts['outcome'] = 'DENIED';
			$facts['reason'] = $details['reason'];
			$facts['consumed'] = $details['consumed'];
			$facts['attempted'] = $details['attempted'];
			$facts['denial_kind'] = 'logical_budget_exceeded' === $details['reason'] ? 'logical' : 'physical';
			$facts['transaction_rollback_attempted'] = true;
		} catch ( Guard_Error $error ) {
			$facts['reason'] = $error->reason();
		} catch ( \Throwable $error ) {
			$facts['reason'] = 'demo_callback_failed';
		}
		return $facts;
	}
}
