<?php
namespace WriteLeash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Informational last certified production outcome; never a readiness authority. */
final class Last_Outcome {
	public const OPTION = 'writeleash_last_certified_outcome';
	private const VERSION = 1;
	private const FIELDS = array(
		'schema_version', 'timestamp', 'operation_id', 'outcome', 'reason',
		'logical_budget', 'physical_ceiling', 'attempted', 'consumed',
		'affected_rows', 'denial_kind', 'transaction_rollback_attempted',
		'guard_rollback_completed', 'durability_verified_by_fresh_observer',
	);

	/** Adapter facts only. Never store SQL, a request body, an exception or a connection. */
	public static function record( array $result ): void {
		if ( ! in_array( $result['outcome'] ?? null, array( 'COMMITTED', 'DENIED' ), true ) ||
			Certified_Operation::REDIRECTION_BULK_DISABLE_ID !== ( $result['operation_id'] ?? null ) ) {
			return;
		}
		$record = array( 'schema_version' => self::VERSION, 'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ) );
		foreach ( self::FIELDS as $field ) {
			if ( 'schema_version' !== $field && 'timestamp' !== $field ) {
				$record[ $field ] = array_key_exists( $field, $result ) ? $result[ $field ] : null;
			}
		}
		if ( null !== self::valid( $record ) ) {
			update_option( self::OPTION, $record, false );
		}
	}

	/** Malformed stored data is ignored; it cannot influence the operation. */
	public static function read(): ?array {
		return self::valid( get_option( self::OPTION, null ) );
	}

	private static function valid( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$keys = array_keys( $row );
		$expected = self::FIELDS;
		sort( $keys );
		sort( $expected );
		if ( $keys !== $expected || self::VERSION !== $row['schema_version'] ||
			! is_string( $row['timestamp'] ) || ! preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $row['timestamp'] ) ||
			Certified_Operation::REDIRECTION_BULK_DISABLE_ID !== $row['operation_id'] ||
			! in_array( $row['outcome'], array( 'COMMITTED', 'DENIED' ), true ) ||
			! is_string( $row['reason'] ) || ! preg_match( '/\A[a-z_]+\z/D', $row['reason'] ) ||
			! Certified_Operation::redirection_5_5_2_bulk_disable()->supports_logical_budget( $row['logical_budget'] ) ||
			Certified_Operation::redirection_5_5_2_bulk_disable()->physical_ceiling() !== $row['physical_ceiling'] ||
			! self::optional_count( $row['attempted'] ) || ! self::optional_count( $row['consumed'] ) ||
			! self::optional_count( $row['affected_rows'] ) ||
			! in_array( $row['denial_kind'], array( null, 'logical', 'physical' ), true ) ||
			! is_bool( $row['transaction_rollback_attempted'] ) ||
			null !== $row['guard_rollback_completed'] || false !== $row['durability_verified_by_fresh_observer'] ) {
			return null;
		}
		if ( 'COMMITTED' === $row['outcome'] &&
			( 'ok' !== $row['reason'] || null !== $row['denial_kind'] || $row['transaction_rollback_attempted'] ||
				! is_int( $row['consumed'] ) || ! is_int( $row['affected_rows'] ) ) ) {
			return null;
		}
		if ( 'DENIED' === $row['outcome'] &&
			( null === $row['denial_kind'] || ! $row['transaction_rollback_attempted'] || null !== $row['affected_rows'] ) ) {
			return null;
		}
		return $row;
	}

	private static function optional_count( $value ): bool {
		return null === $value || ( is_int( $value ) && $value >= 0 );
	}
}
