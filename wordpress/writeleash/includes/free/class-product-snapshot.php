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
	 * Read a new public Woo CRUD object without reusing a factory instance.
	 * Type/class resolution retains Woo's public extension filters; callers
	 * still apply the existing exact-core eligibility rules. No save, transient
	 * deletion, SQL write or guessed object-cache key belongs to this read.
	 *
	 * Only Woo's three exact core classes are constructed, each through its
	 * own literal `new` expression; a construction failure is rethrown as a
	 * typed, conservatively retryable `unreadable_product_data` failure.
	 *
	 * A classname that resolves to a WC_Product subclass outside the three
	 * exact core classes (Woo external/grouped product types, extension
	 * subclasses, filtered classnames) is a stable unsupported product class
	 * and is refused terminally as `unsupported_product_type` without ever
	 * being instantiated. A resolved string that is not a WC_Product at all
	 * (for example `stdClass`) is a broken mapping, not a product type, and is
	 * kept conservatively unreadable. Neither path may substitute a core class:
	 * that would be an unproven fallback that could make an unsupported
	 * product eligible for a write.
	 */
	public static function fresh_product( int $id ) {
		if ( $id < 1 ) { throw new Price_Validation_Error( 'invalid_product_id' ); }
		if ( ! function_exists( 'wc_get_product' ) || ! class_exists( '\WC_Product_Factory' ) || ! class_exists( '\WC_Cache_Helper' ) || ! empty( $GLOBALS['_wp_suspend_cache_invalidation'] ) ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
		clean_post_cache( $id );
		wp_cache_delete( $id, 'post_meta' );
		// Woo's public type lookup uses this product group too. Evict it before
		// resolving the class, so a concurrent type edit cannot reuse old type.
		\WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
		$type = \WC_Product_Factory::get_product_type( $id );
		if ( ! $type ) { return false; }
		$class = \WC_Product_Factory::get_product_classname( $id, $type );
		if ( ! is_string( $class ) ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
		if ( 'WC_Product_Simple' !== $class && 'WC_Product_Variation' !== $class && 'WC_Product_Variable' !== $class ) {
			// Stable unsupported product class, never an automatically
			// retryable read failure; a non-product classname stays unreadable.
			if ( is_a( $class, '\WC_Product', true ) ) { throw new Price_Validation_Error( 'unsupported_product_type' ); }
			throw new Price_Validation_Error( 'unreadable_product_data' );
		}
		try {
			if ( 'WC_Product_Simple' === $class ) { $product = new \WC_Product_Simple( $id ); }
			elseif ( 'WC_Product_Variation' === $class ) { $product = new \WC_Product_Variation( $id ); }
			else { $product = new \WC_Product_Variable( $id ); }
		} catch ( \Throwable $error ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
		if ( ! $product instanceof \WC_Product || $product->get_id() !== $id ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
		return $product;
	}
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
	/**
	 * A present, stable unsupported product class: exists, not unreadable, no
	 * core identity and no prices, so eligibility yields the terminal
	 * `unsupported_product_type` refusal instead of a retryable read failure.
	 */
	public static function unsupported_type( int $id, string $name = '' ): self {
		if ( $id < 1 ) { throw new Price_Validation_Error( 'invalid_product_id' ); }
		$values = self::missing_values( $id, false );
		$values['exists'] = true;
		$values['name'] = $name;
		return new self( $values );
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
			try { $parent = self::fresh_product( $parent_id ); }
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
			if ( '' === $s['sale_price'] && null !== $operation_type && ! in_array( $operation_type, array( Price_Operation::SET, Price_Operation::CLEAR_SALE, Price_Operation::SALE_DISCOUNT_PERCENT ), true ) ) { $reason = 'empty_sale_price'; }
			// WooCommerce silently clears a sale that is not below the regular price.
			if ( null === $reason && null !== $planned_target && '' !== $planned_target && '' !== $s['regular_price'] ) {
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
			'missing_product' => __( 'The selected product no longer exists.', 'writeleash' ),
			'unreadable_product_data' => __( 'WriteLeash could not read this product’s saved data, so it was skipped before any change. Open the product in WooCommerce and save it again to regenerate its data; if many products are affected, run WooCommerce’s product lookup table update. Then create a new preview.', 'writeleash' ),
			'unsupported_product_type' => __( 'Only core simple products and variations of published core variable products are supported.', 'writeleash' ),
			'unsupported_parent_product' => __( 'This variation’s parent product is missing, is not a core variable product, or is not published.', 'writeleash' ),
			'unsupported_status' => __( 'Only published products are supported.', 'writeleash' ),
			'sale_configured' => __( 'Remove sale configuration and create a new preview.', 'writeleash' ),
			'empty_regular_price' => __( 'The stored regular price is empty, not zero.', 'writeleash' ),
			'invalid_price' => __( 'The stored price is outside the supported decimal contract.', 'writeleash' ),
			'unsupported_currency_context' => __( 'A base-store-currency context is required.', 'writeleash' ),
			'unsupported_price_field' => __( 'Choose either the regular price or the sale price as the target.', 'writeleash' ),
			'regular_price_not_above_sale' => __( 'The planned regular price is not above the current sale price; WooCommerce would clear the sale. Remove or reprice the sale first.', 'writeleash' ),
			'sale_price_not_below_regular' => __( 'The planned sale price must be below the current regular price; WooCommerce would clear the sale. Create a new preview.', 'writeleash' ),
			'empty_sale_price' => __( 'This product has no stored sale price to adjust; use Set to add the first sale price.', 'writeleash' ),
			'duplicate_selection' => __( 'Repeated IDs were included once.', 'writeleash' ),
			'ambiguous_sku' => __( 'More than one product has this exact SKU.', 'writeleash' ),
			'percent_from_zero_undefined' => __( 'A percentage operation on zero is unsupported.', 'writeleash' ),
			'negative_target' => __( 'The planned price would be negative.', 'writeleash' ),
			'price_overflow' => __( 'The price exceeds twelve integer digits.', 'writeleash' ),
			'malformed_decimal' => __( 'Use an unsigned decimal string with a dot separator.', 'writeleash' ),
			'input_precision_exceeded' => __( 'Use at most six fractional digits.', 'writeleash' ),
			'unsupported_price_decimals' => __( 'Store precision must be between zero and six.', 'writeleash' ),
			'zero_target_blocked' => __( 'Your safety settings block a planned zero price.', 'writeleash' ),
			'percentage_limit_from_zero_undefined' => __( 'A percentage safety cap cannot authorize an increase from zero.', 'writeleash' ),
			'max_products_exceeded' => __( 'The number of changing products exceeds the limit.', 'writeleash' ),
			'max_increase_exceeded' => __( 'A planned increase exceeds the percentage limit.', 'writeleash' ),
			'max_decrease_exceeded' => __( 'A planned decrease exceeds the percentage limit.', 'writeleash' ),
			'large_price_increase' => __( 'The planned increase exceeds the warning threshold.', 'writeleash' ),
			'large_price_decrease' => __( 'The planned decrease exceeds the warning threshold.', 'writeleash' ),
			'regular_price_changed' => __( 'The stored regular price changed; create a new preview.', 'writeleash' ),
			'sale_price_changed' => __( 'The stored sale price changed; create a new preview.', 'writeleash' ),
			'product_type_changed' => __( 'The product type changed; create a new preview.', 'writeleash' ),
			'product_status_changed' => __( 'The publication status changed; create a new preview.', 'writeleash' ),
			'sale_configuration_changed' => __( 'Sale configuration changed; create a new preview.', 'writeleash' ),
			'currency_context_changed' => __( 'Currency context changed; create a new preview.', 'writeleash' ),
			'price_decimals_changed' => __( 'Store price precision changed; create a new preview.', 'writeleash' ),
			'software_version_changed' => __( 'The WordPress or WooCommerce version changed; create a new preview.', 'writeleash' ),
			'invalid_product_id' => __( 'Product IDs must be positive integers.', 'writeleash' ),
			'invalid_selection_size' => __( 'Select between one and one thousand explicit IDs.', 'writeleash' ),
			'selection_limit_exceeded' => __( 'Narrow the selection to at most one thousand products.', 'writeleash' ),
			'invalid_sku' => __( 'Use a non-empty exact SKU without whitespace or markup.', 'writeleash' ),
			'invalid_category' => __( 'Select an existing product category.', 'writeleash' ),
			'unsupported_operation' => __( 'Select a supported price operation.', 'writeleash' ),
			'sale_operation_requires_sale_field' => __( 'Choose Sale price for this operation.', 'writeleash' ),
			'clear_sale_requires_empty_input' => __( 'Clear Sale Price takes no amount. Leave the amount blank.', 'writeleash' ),
			'sale_discount_out_of_range' => __( 'Use a discount between 0 and 100 percent. The resulting sale must be below Regular Price and pass your safety settings.', 'writeleash' ),
			'invalid_product_limit' => __( 'The changed-product limit must be between zero and one thousand.', 'writeleash' ),
			'invalid_store_context' => __( 'The store context is incomplete or invalid.', 'writeleash' ),
			'woocommerce_unavailable' => __( 'WooCommerce must be active before previewing price changes.', 'writeleash' ),
			'permission_denied' => __( 'You do not have permission to plan these product edits.', 'writeleash' ),
			'plan_policy_blocked' => __( 'The safety settings block this preview.', 'writeleash' ),
			'unchanged' => __( 'The stored price already equals the planned price.', 'writeleash' ),
			'immutable_plan' => __( 'Build a new preview to change these settings.', 'writeleash' ),
			'invalid_preview_page' => __( 'Use a nonnegative offset and a page size between one and one hundred.', 'writeleash' ),
			'unpreviewed_product' => __( 'This product was not part of the preview.', 'writeleash' ),
			'selection_changed_during_planning' => __( 'A product changed while the preview was being built; create a new preview.', 'writeleash' ),
			'store_context_changed_during_planning' => __( 'Store settings changed while the preview was being built; create a new preview.', 'writeleash' ),
			'selection_snapshot_mismatch' => __( 'The saved product list does not match the selection.', 'writeleash' ),
			'invalid_plan_identity' => __( 'The saved preview identity is invalid.', 'writeleash' ),
			'invalid_snapshot' => __( 'A product snapshot is required.', 'writeleash' ),
			'noncanonical_plan_value' => __( 'The saved preview contains an unsupported value type.', 'writeleash' ),
			'invalid_plan_encoding' => __( 'The saved preview text is not valid UTF-8.', 'writeleash' ),
		);
	}
}
