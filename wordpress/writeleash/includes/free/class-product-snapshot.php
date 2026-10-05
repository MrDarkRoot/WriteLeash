<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

final class Price_Store_Context {
	use Immutable_Price_Value;
	private array $values;
	public function __construct( string $currency, int $decimals, string $wp_version, string $woo_version, bool $base_currency = true ) {
		Price_Decimal::decimals( $decimals );
		if ( ! preg_match( '/\A[A-Z]{3}\z/D', $currency ) || '' === $wp_version || '' === $woo_version ) { throw new Price_Validation_Error( 'invalid_store_context' ); }
		$this->values = array( 'currency' => $currency, 'price_decimals' => $decimals, 'wordpress_version' => $wp_version, 'woocommerce_version' => $woo_version, 'base_currency_context' => $base_currency );
	}
	public static function current(): self {
		if ( ! function_exists( 'wc_get_product' ) || ! defined( 'WC_VERSION' ) || ! did_action( 'woocommerce_init' ) ) { throw new Price_Validation_Error( 'woocommerce_unavailable' ); }
		global $wp_version;
		$currency = get_woocommerce_currency();
		return new self( $currency, wc_get_price_decimals(), $wp_version, WC_VERSION, $currency === get_option( 'woocommerce_currency' ) );
	}
	public function data(): array { return $this->values; }
}

final class Product_Price_Snapshot {
	use Immutable_Price_Value;
	private array $values;
	private function __construct( array $values ) { $this->values = $values; }
	/**
	 * A snapshot always carries the core simple/variation identity fields so
	 * plans can freeze and explain either kind. `$parent` is an optional,
	 * already-read variable parent for a variation; when omitted the parent
	 * is read once through the public Woo API.
	 */
	public static function read( int $id, $product, $parent = null ): self {
		if ( $id < 1 ) { throw new Price_Validation_Error( 'invalid_product_id' ); }
		if ( ! $product instanceof \WC_Product ) {
			return new self( self::missing_values( $id, false ) );
		}
		if ( $product->get_id() !== $id ) { throw new Price_Validation_Error( 'selection_changed_during_planning' ); }
		$from = $product->get_date_on_sale_from( 'edit' );
		$to = $product->get_date_on_sale_to( 'edit' );
		$categories = $product->get_category_ids( 'edit' );
		sort( $categories, SORT_NUMERIC );
		$variation = self::variation_facts( $product, $parent );
		return new self( array_merge( array(
			'product_id' => $id, 'exists' => true, 'unreadable' => false, 'name' => $product->get_name( 'edit' ), 'sku' => $product->get_sku( 'edit' ),
			'type' => $product->get_type(), 'status' => $product->get_status( 'edit' ),
			// Reject extension subclasses, even ones that advertise type=simple.
			'core_simple' => 'WC_Product_Simple' === get_class( $product ),
			'regular_price' => $product->get_regular_price( 'edit' ), 'sale_price' => $product->get_sale_price( 'edit' ),
			'sale_from' => $from ? (string) $from->getTimestamp() : null, 'sale_to' => $to ? (string) $to->getTimestamp() : null,
			'category_ids' => $categories,
		), $variation ) );
	}
	/** An ID whose WooCommerce read threw. Explicitly unknown, never guessed and never treated as absent. */
	public static function unreadable( int $id ): self {
		if ( $id < 1 ) { throw new Price_Validation_Error( 'invalid_product_id' ); }
		return new self( self::missing_values( $id, true ) );
	}
	/** The pre-#179 shape plus the always-present variation identity fields. */
	private static function missing_values( int $id, bool $unreadable ): array {
		return array(
			'product_id' => $id, 'exists' => false, 'unreadable' => $unreadable, 'name' => '', 'sku' => '', 'type' => '', 'status' => '', 'core_simple' => false,
			'core_variation' => false, 'parent_id' => 0, 'parent_type' => '', 'parent_status' => '', 'parent_core_variable' => false, 'variation_label' => '',
			'regular_price' => '', 'sale_price' => '', 'sale_from' => null, 'sale_to' => null, 'category_ids' => array(),
		);
	}
	/**
	 * Core variation identity and display label ("Product name — Blue / M").
	 * Only an exact core WC_Product_Variation is identified as a variation;
	 * extension subclasses keep core_variation=false and are refused later.
	 */
	private static function variation_facts( $product, $parent ): array {
		$facts = array( 'core_variation' => false, 'parent_id' => 0, 'parent_type' => '', 'parent_status' => '', 'parent_core_variable' => false, 'variation_label' => '' );
		if ( ! $product instanceof \WC_Product_Variation ) { return $facts; }
		$parent_id = (int) $product->get_parent_id( 'edit' );
		$facts['parent_id'] = $parent_id;
		if ( ! $parent instanceof \WC_Product && $parent_id > 0 && function_exists( 'wc_get_product' ) ) {
			try { $parent = wc_get_product( $parent_id ); }
			catch ( \Throwable $error ) { $parent = null; }
		}
		if ( $parent instanceof \WC_Product ) {
			$facts['parent_type'] = (string) $parent->get_type();
			$facts['parent_status'] = (string) $parent->get_status( 'edit' );
			$facts['parent_core_variable'] = 'WC_Product_Variable' === get_class( $parent );
		}
		$facts['core_variation'] = 'WC_Product_Variation' === get_class( $product );
		$facts['variation_label'] = self::variation_label( $product, $parent );
		return $facts;
	}
	/** Human-readable attributes; stable fallbacks, escaped only at output. */
	private static function variation_label( $product, $parent ): string {
		$name = $parent instanceof \WC_Product ? (string) $parent->get_name( 'edit' ) : '';
		if ( '' === $name ) { $name = (string) $product->get_name( 'edit' ); }
		$parts = array();
		if ( function_exists( 'wc_get_formatted_variation' ) ) {
			try {
				$flat = wc_get_formatted_variation( $product, true, false );
				if ( is_string( $flat ) && '' !== $flat ) {
					foreach ( explode( ',', $flat ) as $part ) { $part = trim( $part ); if ( '' !== $part ) { $parts[] = $part; } }
				}
			} catch ( \Throwable $error ) { $parts = array(); }
		}
		if ( ! $parts && method_exists( $product, 'get_attributes' ) ) {
			try {
				foreach ( (array) $product->get_attributes() as $value ) { if ( is_string( $value ) && '' !== $value ) { $parts[] = $value; } }
			} catch ( \Throwable $error ) { $parts = array(); }
		}
		if ( ! $parts ) { return $name; }
		return ( '' === $name ? '' : $name . ' — ' ) . implode( ' / ', $parts );
	}
	public function data(): array { return $this->values; }
}

final class Eligibility_Result {
	use Immutable_Price_Value;
	public const ELIGIBLE = 'ELIGIBLE';
	public const UNSUPPORTED = 'UNSUPPORTED';
	private ?string $reason;
	public function __construct( ?string $reason ) { $this->reason = $reason; }
	public function data(): array { return array( 'state' => null === $this->reason ? self::ELIGIBLE : self::UNSUPPORTED, 'reason' => $this->reason ); }
}

final class Product_Price_Eligibility {
	/** An exact core simple product; extension subclasses never qualify. */
	private static function is_core_simple( array $s ): bool {
		return ! empty( $s['core_simple'] ) && 'simple' === $s['type'];
	}
	/** An exact core variation; extension subclasses never qualify. */
	private static function is_core_variation( array $s ): bool {
		return ! empty( $s['core_variation'] ) && 'variation' === $s['type'];
	}
	/** A variation needs a published core variable parent captured at preview. */
	private static function parent_supported( array $s ): bool {
		return (int) ( $s['parent_id'] ?? 0 ) > 0
			&& ! empty( $s['parent_core_variable'] )
			&& 'variable' === ( $s['parent_type'] ?? '' )
			&& 'publish' === ( $s['parent_status'] ?? '' );
	}
	/**
	 * Field-aware eligibility. The third argument defaults to the regular
	 * price so discovery's existing two-argument call keeps its meaning; the
	 * optional operation/planned arguments add the target-relative safety
	 * checks when a concrete target exists.
	 */
	public static function evaluate( Product_Price_Snapshot $snapshot, Price_Store_Context $context, string $field = Price_Operation::FIELD_REGULAR, ?string $operation_type = null, ?string $planned_target = null ): Eligibility_Result {
		$s = $snapshot->data();
		if ( ! in_array( $field, Price_Operation::FIELDS, true ) ) { throw new Price_Validation_Error( 'unsupported_price_field' ); }
		$reason = null;
		if ( ! $s['exists'] ) { $reason = ! empty( $s['unreadable'] ) ? 'unreadable_product_data' : 'missing_product'; }
		elseif ( ! $context->data()['base_currency_context'] ) { $reason = 'unsupported_currency_context'; }
		elseif ( self::is_core_variation( $s ) && ! self::parent_supported( $s ) ) { $reason = 'unsupported_parent_product'; }
		elseif ( ! self::is_core_simple( $s ) && ! self::is_core_variation( $s ) ) { $reason = 'unsupported_product_type'; }
		elseif ( 'publish' !== $s['status'] ) { $reason = 'unsupported_status'; }
		// Both fields need a parseable non-empty regular price: WooCommerce
		// validates every sale against it and would clear the sale without one.
		elseif ( '' === $s['regular_price'] ) { $reason = 'empty_regular_price'; }
		else {
			try { Price_Decimal::parse( $s['regular_price'] ); }
			catch ( Price_Validation_Error $e ) { $reason = 'invalid_price'; }
			if ( null === $reason && '' !== $s['sale_price'] ) {
				try { Price_Decimal::parse( $s['sale_price'] ); }
				catch ( Price_Validation_Error $e ) { $reason = 'invalid_price'; }
			}
		}
		if ( null === $reason && Price_Operation::FIELD_SALE === $field ) {
			// A first sale price has no percentage/fixed baseline to adjust.
			if ( '' === $s['sale_price'] && null !== $operation_type && Price_Operation::SET !== $operation_type ) { $reason = 'empty_sale_price'; }
			// WooCommerce silently clears a sale that is not below the regular price.
			if ( null === $reason && null !== $planned_target && '' !== $s['regular_price'] ) {
				try {
					if ( Price_Decimal::compare( Price_Decimal::units( Price_Decimal::parse( $planned_target ) ), Price_Decimal::units( Price_Decimal::parse( $s['regular_price'] ) ) ) >= 0 ) { $reason = 'sale_price_not_below_regular'; }
				} catch ( Price_Validation_Error $e ) { $reason = 'invalid_price'; }
			}
		}
		if ( null === $reason && Price_Operation::FIELD_REGULAR === $field && null !== $planned_target && '' !== $s['sale_price'] ) {
			// WooCommerce clears the sale when the new regular price is not
			// above it; refuse instead of losing sale configuration silently.
			try {
				if ( Price_Decimal::compare( Price_Decimal::units( Price_Decimal::parse( $planned_target ) ), Price_Decimal::units( Price_Decimal::parse( $s['sale_price'] ) ) ) <= 0 ) { $reason = 'regular_price_not_above_sale'; }
			} catch ( Price_Validation_Error $e ) { $reason = 'invalid_price'; }
		}
		return new Eligibility_Result( $reason );
	}
}

/** Display copy is independent of stable domain codes; consumers must escape output. */
final class Price_Reason_Messages {
	public static function all(): array {
		return array(
			'missing_product' => 'The selected product no longer exists.',
			'unreadable_product_data' => 'WriteLeash could not read this product’s saved data, so it was skipped before any change. Open the product in WooCommerce and save it again to regenerate its data; if many products are affected, run WooCommerce’s product lookup table update. Then create a new preview.',
			'unsupported_product_type' => 'Only core simple products and variations of published core variable products are supported.',
			'unsupported_parent_product' => 'This variation’s parent product is missing, is not a core variable product, or is not published.',
			'unsupported_status' => 'Only published products are supported.',
			'sale_configured' => 'Remove sale configuration and create a new preview.',
			'empty_regular_price' => 'The stored regular price is empty, not zero.',
			'invalid_price' => 'The stored price is outside the supported decimal contract.',
			'unsupported_currency_context' => 'A base-store-currency context is required.',
			'unsupported_price_field' => 'Choose either the regular price or the sale price as the target.',
			'regular_price_not_above_sale' => 'The planned regular price is not above the current sale price; WooCommerce would clear the sale. Remove or reprice the sale first.',
			'sale_price_not_below_regular' => 'The planned sale price must be below the current regular price; WooCommerce would clear the sale. Create a new preview.',
			'empty_sale_price' => 'This product has no stored sale price to adjust; use Set to add the first sale price.',
			'duplicate_selection' => 'Repeated IDs were included once.',
			'ambiguous_sku' => 'More than one product has this exact SKU.',
			'percent_from_zero_undefined' => 'A percentage operation on zero is unsupported.',
			'negative_target' => 'The planned price would be negative.',
			'price_overflow' => 'The price exceeds twelve integer digits.',
			'malformed_decimal' => 'Use an unsigned decimal string with a dot separator.',
			'input_precision_exceeded' => 'Use at most six fractional digits.',
			'unsupported_price_decimals' => 'Store precision must be between zero and six.',
			'zero_target_blocked' => 'The policy blocks a planned zero price.',
			'percentage_limit_from_zero_undefined' => 'A percentage safety cap cannot authorize an increase from zero.',
			'max_products_exceeded' => 'The number of changing products exceeds the limit.',
			'max_increase_exceeded' => 'A planned increase exceeds the percentage limit.',
			'max_decrease_exceeded' => 'A planned decrease exceeds the percentage limit.',
			'large_price_increase' => 'The planned increase exceeds the warning threshold.',
			'large_price_decrease' => 'The planned decrease exceeds the warning threshold.',
			'regular_price_changed' => 'The stored regular price changed; create a new preview.',
			'sale_price_changed' => 'The stored sale price changed; create a new preview.',
			'product_type_changed' => 'The product type changed; create a new preview.',
			'product_status_changed' => 'The publication status changed; create a new preview.',
			'sale_configuration_changed' => 'Sale configuration changed; create a new preview.',
			'currency_context_changed' => 'Currency context changed; create a new preview.',
			'price_decimals_changed' => 'Store price precision changed; create a new preview.',
			'software_version_changed' => 'The WordPress or WooCommerce version changed; create a new preview.',
			'invalid_product_id' => 'Product IDs must be positive integers.',
			'invalid_selection_size' => 'Select between one and one thousand explicit IDs.',
			'selection_limit_exceeded' => 'Narrow the selection to at most one thousand products.',
			'invalid_sku' => 'Use a non-empty exact SKU without whitespace or markup.',
			'invalid_category' => 'Select an existing product category.',
			'unsupported_operation' => 'Select one of the five supported price operations.',
			'invalid_product_limit' => 'The changed-product limit must be between zero and one thousand.',
			'invalid_store_context' => 'The store context is incomplete or invalid.',
			'woocommerce_unavailable' => 'WooCommerce must be initialized before planning.',
			'permission_denied' => 'You do not have permission to plan these product edits.',
			'plan_policy_blocked' => 'The plan is blocked by its safety policy.',
			'unchanged' => 'The planned regular price equals the expected regular price.',
			'immutable_plan' => 'Create a new preview to change plan inputs.',
			'invalid_preview_page' => 'Use a nonnegative offset and a page size between one and one hundred.',
			'unpreviewed_product' => 'This product is not part of the frozen preview.',
			'selection_changed_during_planning' => 'Product identity changed during planning; create a new preview.',
			'store_context_changed_during_planning' => 'Store settings changed during planning; create a new preview.',
			'selection_snapshot_mismatch' => 'The snapshot IDs do not match the explicit selection.',
			'invalid_plan_identity' => 'The plan identity, timestamp or actor is invalid.',
			'invalid_snapshot' => 'A product snapshot is required.',
			'noncanonical_plan_value' => 'Plan serialization accepts scalar decimal strings, not floats or objects.',
			'invalid_plan_encoding' => 'Plan text must use valid UTF-8.',
		);
	}
}
