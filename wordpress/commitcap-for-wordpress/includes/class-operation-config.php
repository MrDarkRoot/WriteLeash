<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal mutable product state for the certified operation (#78).
 *
 * Exactly two fields are mutable, and only these:
 *
 *   enabled        strict boolean
 *   logical_budget canonical integer within the descriptor's 0..P range
 *
 * One namespaced WordPress option, `commitcap_certified_operation_state`,
 * stores `array( 'operation_id', 'enabled', 'logical_budget' )`. Nothing else
 * is accepted: no table names, adapters, callbacks, SQL, routes, ceilings,
 * credentials or serialized objects. The stored `operation_id` must match the
 * descriptor, so a state row can never be replayed onto another operation.
 *
 * Strictness rules (no loose coercion anywhere):
 * - `enabled` must be exactly a PHP boolean; `1`, `'1'`, `'true'`, `null`,
 *   arrays and objects are rejected.
 * - `logical_budget` must be a PHP integer or a canonical decimal string
 *   (`0` or `[1-9][0-9]*`, no leading zeros, no sign, no exponent, no float),
 *   within the descriptor range. Canonical strings normalize to `int`.
 * - Unknown or missing keys make the stored state invalid; it is never
 *   silently repaired. `reset()` clears it and the default (disabled) applies.
 */
final class Operation_Config {
	public const STATE_OPTION = 'commitcap_certified_operation_state';

	private const FIELDS = array( 'operation_id', 'enabled', 'logical_budget' );

	/**
	 * Read and strictly validate the stored state for one operation.
	 *
	 * @return array{state: string, enabled: bool|null, logical_budget: int|null, reason: string, detail: string}
	 *         state: absent|ok|invalid. Absent means "not enabled" (safe default).
	 */
	public static function read( Certified_Operation $operation ): array {
		$raw = get_option( self::STATE_OPTION, null );
		if ( null === $raw ) {
			return array(
				'state'          => 'absent',
				'enabled'        => false,
				'logical_budget' => null,
				'reason'         => 'operation_disabled',
				'detail'         => 'No CommitCap operation state is stored; the certified operation is disabled.',
			);
		}
		if ( ! is_array( $raw ) ) {
			return self::invalid( 'Stored CommitCap operation state is not an array.' );
		}
		$keys   = array_keys( $raw );
		$fields = self::FIELDS;
		sort( $keys );
		sort( $fields );
		if ( $fields !== $keys ) {
			return self::invalid( 'Stored CommitCap operation state has unknown or missing fields.' );
		}
		if ( ! is_string( $raw['operation_id'] ) || $operation->id() !== $raw['operation_id'] ) {
			return self::invalid( 'Stored CommitCap operation state does not belong to this operation.' );
		}
		if ( ! is_bool( $raw['enabled'] ) ) {
			return self::invalid( 'Stored CommitCap `enabled` must be a boolean.' );
		}
		$budget = null;
		if ( null !== $raw['logical_budget'] ) {
			$budget = self::normalize_budget( $raw['logical_budget'], $operation );
			if ( null === $budget ) {
				return self::invalid( 'Stored CommitCap `logical_budget` is not a canonical in-range integer.' );
			}
		}
		return array(
			'state'          => 'ok',
			'enabled'        => $raw['enabled'],
			'logical_budget' => $budget,
			'reason'         => $raw['enabled'] ? 'ok' : 'operation_disabled',
			'detail'         => $raw['enabled']
				? 'Certified operation is enabled with a validated logical budget.'
				: 'Certified operation is disabled.',
		);
	}

	/**
	 * Set the enable flag. The value must be a real boolean.
	 *
	 * @return array Same shape as read().
	 * @throws \InvalidArgumentException On a non-boolean value.
	 * @throws \RuntimeException When stored state is invalid and must be reset first.
	 */
	public static function set_enabled( Certified_Operation $operation, $enabled ): array {
		if ( ! is_bool( $enabled ) ) {
			throw new \InvalidArgumentException( 'CommitCap `enabled` must be a boolean.' );
		}
		$current = self::require_writable( $operation );
		self::write( $operation, $enabled, $current['logical_budget'] );
		return self::read( $operation );
	}

	/**
	 * Set the logical budget. The value must be a canonical integer within the
	 * descriptor range; canonical decimal strings normalize to int.
	 *
	 * @return array Same shape as read().
	 * @throws \InvalidArgumentException On a malformed or out-of-range value.
	 * @throws \RuntimeException When stored state is invalid and must be reset first.
	 */
	public static function set_logical_budget( Certified_Operation $operation, $budget ): array {
		$normalized = self::normalize_budget( $budget, $operation );
		if ( null === $normalized ) {
			throw new \InvalidArgumentException(
				'CommitCap `logical_budget` must be a canonical integer between '
				. $operation->logical_budget_min() . ' and ' . $operation->logical_budget_max() . '.'
			);
		}
		$current = self::require_writable( $operation );
		self::write( $operation, $current['enabled'], $normalized );
		return self::read( $operation );
	}

	/** Delete the stored state; the safe default (disabled) applies again. */
	public static function reset( Certified_Operation $operation ): void {
		delete_option( self::STATE_OPTION );
	}

	/**
	 * @return array{state: string, enabled: bool|null, logical_budget: int|null, reason: string, detail: string}
	 */
	private static function require_writable( Certified_Operation $operation ): array {
		$current = self::read( $operation );
		if ( 'invalid' === $current['state'] ) {
			throw new \RuntimeException( 'Stored CommitCap operation state is invalid; reset it before writing.' );
		}
		return $current;
	}

	private static function write( Certified_Operation $operation, bool $enabled, ?int $budget ): void {
		update_option(
			self::STATE_OPTION,
			array(
				'operation_id'   => $operation->id(),
				'enabled'        => $enabled,
				'logical_budget' => $budget,
			),
			false
		);
	}

	/** Canonical normalization; returns null for any malformed/out-of-range value. */
	private static function normalize_budget( $value, Certified_Operation $operation ): ?int {
		if ( is_int( $value ) ) {
			$budget = $value;
		} elseif ( is_string( $value ) && preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) ) {
			$budget = (int) $value;
		} else {
			return null;
		}
		return $operation->supports_logical_budget( $budget ) ? $budget : null;
	}

	/**
	 * @return array{state: string, enabled: bool|null, logical_budget: int|null, reason: string, detail: string}
	 */
	private static function invalid( string $detail ): array {
		return array(
			'state'          => 'invalid',
			'enabled'        => null,
			'logical_budget' => null,
			'reason'         => 'config_invalid',
			'detail'         => $detail,
		);
	}
}
