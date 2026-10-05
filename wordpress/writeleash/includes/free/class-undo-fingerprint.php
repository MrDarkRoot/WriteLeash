<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * Conservative post-apply fingerprint for conflict-aware Undo.
 *
 * Blocking fields (a mismatch refuses the restore with zero overwrite):
 * product identity, applied regular price, active price, core simple type,
 * publish status, sale price and sale dates, store currency, store price
 * decimals and lookup min/max with onsale=0. The WordPress/WooCommerce
 * versions pinned at apply time are durable provenance too, but routine
 * drift inside the supported ranges is not a product-state change: only
 * drift outside the supported range blocks the restore. Every blocking
 * value is a durable fact: journal COMMIT evidence, the frozen #107 plan
 * snapshot (proven MATCH by the apply precondition), or the frozen job
 * store context.
 *
 * ABA limitation (documented, never overstated): an external edit that moves
 * the price away from the applied value and back (80 -> 70 -> 80) through the
 * supported CRUD path reproduces every blocking value, so the fingerprint
 * matches and Undo proceeds. WriteLeash does not observe hostile or raw
 * writers; value equality plus context equality is the strongest maintainable
 * claim on the supported APIs. `post_modified_gmt` is recorded as advisory
 * provenance only: it is not a blocking safety claim (see contract research).
 */
final class Undo_Fingerprint {
	/** Legacy blocking fingerprint fields (provenance without a price field), in canonical hash order. */
	public static function fields(): array {
		return array(
			'product_id',
			'applied_price',
			'active_price',
			'product_type',
			'core_simple',
			'status',
			'sale_price',
			'sale_from',
			'sale_to',
			'currency',
			'price_decimals',
			'lookup_min',
			'lookup_max',
			'lookup_onsale',
			'wordpress_version',
			'woocommerce_version',
		);
	}

	/**
	 * Blocking fields for a field-scoped provenance. A regular-price restore
	 * only guards the applied regular price and identity/store/version facts;
	 * sale configuration, the active price and the lookup table depend on the
	 * other field and must not block Undo during a promotion. A sale-price
	 * restore additionally guards the regular price recorded at apply time.
	 */
	public static function fields_for( string $field ): array {
		Price_Operation::assert_field( $field );
		$fields = array( 'product_id', 'applied_price', 'product_type', 'core_simple', 'status', 'currency', 'price_decimals', 'wordpress_version', 'woocommerce_version' );
		if ( Price_Operation::FIELD_SALE === $field ) { $fields[] = 'regular_context'; }
		return $fields;
	}

	/**
	 * Assemble durable provenance from apply-time records only. Fresh Woo
	 * state is never an input here; it is compared later by `verify()`.
	 *
	 * @param array $facts apply-time facts (see repository assembler).
	 * @return array{provenance: string, fingerprint: string}
	 */
	public static function capture( array $facts ): array {
		$required = array( 'product_id', 'expected_price', 'applied_price', 'apply_attempt_id', 'applied_at', 'actor_id', 'initiator_id', 'job_id', 'plan_id', 'currency', 'price_decimals', 'wordpress_version', 'woocommerce_version', 'product_type', 'core_simple', 'status' );
		$field = $facts['price_field'] ?? null;
		if ( null === $field ) {
			$block_fields = self::fields();
			$required = array_merge( $required, array( 'sale_price', 'sale_from', 'sale_to', 'active_price', 'lookup_min', 'lookup_max', 'lookup_onsale' ) );
		} else {
			$block_fields = self::fields_for( $field );
			if ( Price_Operation::FIELD_SALE === $field ) { $required[] = 'regular_context'; }
		}
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $facts ) ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		}
		$blocking = array();
		foreach ( $block_fields as $block_field ) { $blocking[$block_field] = $facts[$block_field]; }
		$provenance = array(
			'version' => 1,
			'job_id' => (int) $facts['job_id'],
			'plan_id' => (string) $facts['plan_id'],
			'product_id' => (int) $facts['product_id'],
			'expected_price' => (string) $facts['expected_price'],
			'applied_price' => (string) $facts['applied_price'],
			'apply_attempt_id' => (string) $facts['apply_attempt_id'],
			'applied_at' => $facts['applied_at'],
			'actor_id' => (int) $facts['actor_id'],
			'initiator_id' => (int) $facts['initiator_id'],
			'blocking' => $blocking,
			// Advisory only: recorded for history display, never a blocking claim.
			'initiated_post_modified_gmt' => $facts['initiated_post_modified_gmt'] ?? null,
		);
		if ( null !== $field ) { $provenance['price_field'] = $field; }
		$json = Plan_Hasher::canonical_json( $provenance );
		return array( 'provenance' => $json, 'fingerprint' => hash( 'sha256', Plan_Hasher::canonical_json( $blocking ) ) );
	}

	/**
	 * Compare fresh mutation-boundary facts against stored provenance.
	 *
	 * @param array $provenance decoded stored provenance.
	 * @param array $fresh fresh facts keyed like `fields()` plus product_id.
	 * @return array{match: bool, reasons: string[]}
	 */
	public static function verify( array $provenance, array $fresh ): array {
		$reasons = array();
		if ( ! is_array( $provenance ) || 1 !== ( $provenance['version'] ?? 0 ) || ! is_array( $provenance['blocking'] ?? null ) ) {
			return array( 'match' => false, 'reasons' => array( 'provenance_tampered' ) );
		}
		$field = $provenance['price_field'] ?? null;
		if ( null !== $field && ! in_array( $field, Price_Operation::FIELDS, true ) ) {
			return array( 'match' => false, 'reasons' => array( 'provenance_tampered' ) );
		}
		$blocking = $provenance['blocking'];
		if ( (int) ( $fresh['product_id'] ?? 0 ) !== (int) ( $provenance['product_id'] ?? 0 ) ) {
			return array( 'match' => false, 'reasons' => array( 'product_identity_changed' ) );
		}
		if ( null === $field ) {
			foreach ( array( 'applied_price', 'active_price', 'lookup_min', 'lookup_max' ) as $compare ) {
				if ( ! self::decimal_equal( $blocking[$compare] ?? null, $fresh[$compare] ?? null ) ) {
					$reasons[] = 'applied_price_changed';
					break;
				}
			}
			foreach ( array( 'sale_price', 'sale_from', 'sale_to' ) as $compare ) {
				if ( ( $blocking[$compare] ?? null ) !== ( $fresh[$compare] ?? null ) ) {
					$reasons[] = 'sale_configuration_changed';
					break;
				}
			}
			if ( ( $blocking['currency'] ?? null ) !== ( $fresh['currency'] ?? null ) ) {
				$reasons[] = 'currency_context_changed';
			}
			if ( (int) ( $blocking['price_decimals'] ?? -1 ) !== (int) ( $fresh['price_decimals'] ?? -2 ) ) {
				$reasons[] = 'price_decimals_changed';
			}
			if ( '0' !== (string) ( $fresh['lookup_onsale'] ?? '' ) || '0' !== (string) ( $blocking['lookup_onsale'] ?? '' ) ) {
				$reasons[] = 'lookup_changed';
			}
		} else {
			if ( ! self::decimal_equal( $blocking['applied_price'] ?? null, $fresh['applied_price'] ?? null ) ) {
				$reasons[] = 'applied_price_changed';
			}
			if ( Price_Operation::FIELD_SALE === $field && ! self::decimal_equal( $blocking['regular_context'] ?? null, $fresh['regular_context'] ?? null ) ) {
				$reasons[] = 'regular_context_changed';
			}
			if ( ( $blocking['currency'] ?? null ) !== ( $fresh['currency'] ?? null ) ) {
				$reasons[] = 'currency_context_changed';
			}
			if ( (int) ( $blocking['price_decimals'] ?? -1 ) !== (int) ( $fresh['price_decimals'] ?? -2 ) ) {
				$reasons[] = 'price_decimals_changed';
			}
		}
		if ( ( $blocking['product_type'] ?? null ) !== ( $fresh['product_type'] ?? null ) || (bool) ( $blocking['core_simple'] ?? false ) !== (bool) ( $fresh['core_simple'] ?? false ) ) {
			$reasons[] = 'product_type_changed';
		}
		if ( ( $blocking['status'] ?? null ) !== ( $fresh['status'] ?? null ) ) {
			$reasons[] = 'product_status_changed';
		}
		// Same rule as the apply precondition: routine supported version
		// drift is not a product-state change. Only drift outside the
		// supported ranges refuses the restore.
		if ( ! Free_Support_Contract::versions_compatible( (string) ( $blocking['wordpress_version'] ?? '' ), (string) ( $blocking['woocommerce_version'] ?? '' ), (string) ( $fresh['wordpress_version'] ?? '' ), (string) ( $fresh['woocommerce_version'] ?? '' ) ) ) {
			$reasons[] = 'software_version_changed';
		}
		return array( 'match' => array() === $reasons, 'reasons' => $reasons );
	}

	/** Recompute the stored fingerprint over stored blocking values (tamper check). */
	public static function fingerprint_of( array $provenance ): string {
		if ( ! is_array( $provenance ) || ! is_array( $provenance['blocking'] ?? null ) ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		$field = $provenance['price_field'] ?? null;
		try { $expected = null === $field ? self::fields() : self::fields_for( $field ); }
		catch ( Price_Validation_Error $error ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		foreach ( $expected as $block_field ) {
			if ( ! array_key_exists( $block_field, $provenance['blocking'] ) ) { throw new Undo_Error( 'UNDO_PROVENANCE_MISMATCH' ); }
		}
		return hash( 'sha256', Plan_Hasher::canonical_json( $provenance['blocking'] ) );
	}

	private static function decimal_equal( $stored, $fresh ): bool {
		try {
			if ( ! is_string( $stored ) || ! is_string( $fresh ) ) { return false; }
			return Price_Decimal::parse( $stored ) === Price_Decimal::parse( $fresh );
		} catch ( \Throwable $error ) { return false; }
	}
}
