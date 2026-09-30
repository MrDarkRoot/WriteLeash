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
	public static function read( int $id, $product ): self {
		if ( $id < 1 ) { throw new Price_Validation_Error( 'invalid_product_id' ); }
		if ( ! $product instanceof \WC_Product ) {
			return new self( array( 'product_id' => $id, 'exists' => false, 'name' => '', 'sku' => '', 'type' => '', 'status' => '', 'core_simple' => false, 'regular_price' => '', 'sale_price' => '', 'sale_from' => null, 'sale_to' => null, 'category_ids' => array() ) );
		}
		if ( $product->get_id() !== $id ) { throw new Price_Validation_Error( 'selection_changed_during_planning' ); }
		$from = $product->get_date_on_sale_from( 'edit' );
		$to = $product->get_date_on_sale_to( 'edit' );
		$categories = $product->get_category_ids( 'edit' );
		sort( $categories, SORT_NUMERIC );
		return new self( array(
			'product_id' => $id, 'exists' => true, 'name' => $product->get_name( 'edit' ), 'sku' => $product->get_sku( 'edit' ),
			'type' => $product->get_type(), 'status' => $product->get_status( 'edit' ),
			// Reject extension subclasses, even ones that advertise type=simple.
			'core_simple' => 'WC_Product_Simple' === get_class( $product ),
			'regular_price' => $product->get_regular_price( 'edit' ), 'sale_price' => $product->get_sale_price( 'edit' ),
			'sale_from' => $from ? (string) $from->getTimestamp() : null, 'sale_to' => $to ? (string) $to->getTimestamp() : null,
			'category_ids' => $categories,
		) );
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
	public static function evaluate( Product_Price_Snapshot $snapshot, Price_Store_Context $context ): Eligibility_Result {
		$s = $snapshot->data();
		$reason = null;
		if ( ! $s['exists'] ) { $reason = 'missing_product'; }
		elseif ( ! $context->data()['base_currency_context'] ) { $reason = 'unsupported_currency_context'; }
		elseif ( ! $s['core_simple'] || 'simple' !== $s['type'] ) { $reason = 'unsupported_product_type'; }
		elseif ( 'publish' !== $s['status'] ) { $reason = 'unsupported_status'; }
		elseif ( '' !== $s['sale_price'] || null !== $s['sale_from'] || null !== $s['sale_to'] ) { $reason = 'sale_configured'; }
		elseif ( '' === $s['regular_price'] ) { $reason = 'empty_regular_price'; }
		else {
			try { Price_Decimal::parse( $s['regular_price'] ); }
			catch ( Price_Validation_Error $e ) { $reason = 'invalid_price'; }
		}
		return new Eligibility_Result( $reason );
	}
}

/** Display copy is independent of stable domain codes; consumers must escape output. */
final class Price_Reason_Messages {
	public static function all(): array {
		return array(
			'missing_product' => 'The selected product no longer exists.',
			'unsupported_product_type' => 'Only core simple products are supported.',
			'unsupported_status' => 'Only published products are supported.',
			'sale_configured' => 'Remove sale configuration and create a new preview.',
			'empty_regular_price' => 'The stored regular price is empty, not zero.',
			'invalid_price' => 'The stored price is outside the supported decimal contract.',
			'unsupported_currency_context' => 'A base-store-currency context is required.',
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
