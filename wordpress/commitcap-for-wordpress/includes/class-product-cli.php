<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WP-CLI diagnostic surface for the one certified operation and #58 demo. */
final class Product_CLI {
	private static function format( array $args, array $assoc_args ): string {
		if ( $args || array_diff( array_keys( $assoc_args ), array( 'format' ) ) ||
			( isset( $assoc_args['format'] ) && 'json' !== $assoc_args['format'] ) ) {
			\WP_CLI::error( 'Only the optional --format=json is supported; no table, SQL or callback arguments are accepted.' );
		}
		return isset( $assoc_args['format'] ) ? 'json' : 'human';
	}

	private static function output( array $row, string $format ): void {
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $row ) );
			return;
		}
		foreach ( $row as $key => $value ) {
			if ( 'last_outcome' === $key ) {
				\WP_CLI::line( 'last_outcome: ' . ( is_array( $value ) ? $value['outcome'] . ' (' . $value['reason'] . ', L=' . $value['logical_budget'] . ', consumed=' . ( null === $value['consumed'] ? 'unknown' : $value['consumed'] ) . ', attempted=' . ( null === $value['attempted'] ? 'unknown' : $value['attempted'] ) . ', rollback attempted=' . ( $value['transaction_rollback_attempted'] ? 'yes' : 'no' ) . ', independently verified=no)' : 'none' ) );
			} elseif ( is_scalar( $value ) || null === $value ) {
				\WP_CLI::line( $key . ': ' . ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : ( null === $value ? 'none' : (string) $value ) ) );
			}
		}
	}

	/** Human-readable by default; stable secret-free snapshot with --format=json. */
	public function status( array $args, array $assoc_args ): void {
		self::output( Product_Status::snapshot(), self::format( $args, $assoc_args ) );
	}

	/** Live Doctor; disabled/unsupported/unknown/DEGRADED never exit zero. */
	public function doctor( array $args, array $assoc_args ): void {
		$format = self::format( $args, $assoc_args );
		$snapshot = Product_Status::snapshot();
		self::output( $snapshot, $format );
		if ( Certified_Operation_Status::READY !== $snapshot['operation_status'] || 'PASS' !== $snapshot['doctor_state'] ) {
			\WP_CLI::halt( 1 );
		}
	}

	/** Repeatable restricted-runtime demo; never provisions or resets objects. */
	public function demo( array $args, array $assoc_args ): void {
		$format = self::format( $args, $assoc_args );
		$result = ( new Disposable_Demo() )->run();
		$view = array(
			'label' => Disposable_Demo::LABEL,
			'status' => $result['status'], 'reason' => $result['reason'],
			'logical_budget' => Disposable_Demo::LOGICAL_BUDGET,
			'physical_ceiling' => Disposable_Demo::PHYSICAL_CEILING,
			'input_state' => isset( $result['input_state'] ) ? $result['input_state'] : null,
			'safe_state' => isset( $result['safe_state'] ) ? $result['safe_state'] : null,
		);
		foreach ( array( 'safe', 'denied' ) as $leg ) {
			if ( isset( $result[ $leg ] ) && is_array( $result[ $leg ] ) ) {
				$view[ $leg ] = array_intersect_key( $result[ $leg ], array_flip( array(
					'outcome', 'reason', 'attempted', 'consumed', 'affected_rows',
					'denial_kind', 'transaction_rollback_attempted',
					'guard_rollback_completed', 'durability_verified_by_fresh_observer',
				) ) );
			}
		}
		if ( 'json' === $format ) {
			self::output( $view, $format );
		} else {
			\WP_CLI::line( $view['label'] . ': ' . $view['status'] . ' (' . $view['reason'] . ')' );
			if ( 'COMPLETE' === $view['status'] ) {
				\WP_CLI::line( 'Safe: 5 UPDATE row events COMMITTED. Denied: 6 UPDATE row events, logical L=5 P=6, DENIED before CommitCap COMMIT; rollback attempted, not independently verified by this request.' );
			} else {
				\WP_CLI::line( Product_Status::explanation( $view['reason'] ) );
			}
		}
		if ( 'COMPLETE' !== $view['status'] ) {
			\WP_CLI::halt( 1 );
		}
	}
}
