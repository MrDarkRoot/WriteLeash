<?php
// #169 display-only formatting against real Woo locale/settings APIs.
use WriteLeash\Free_Admin as Polish;
use WriteLeash\Free_Admin as Admin;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Price_Operation as Operation;
$tiny_product169 = make_product( '99999999999.00' );
$tiny_result169 = Admin::process_preview( preview_post( array( 'ids' => (string) $tiny_product169, 'operation' => Operation::INCREASE_FIXED, 'amount' => '0.01' ) ), 'POST' );
eq( $tiny_result169['status'], 'OK', 'tiny exact-ratio preview created' );
$tiny_job169 = Repo::read_by_public_id( $tiny_result169['public_id'] );
$tiny_plan169 = Repo::hydrate_plan( $tiny_job169 );
$tiny_hash169 = $tiny_plan169->hash();
$tiny_html169 = render_view( 'preview', $tiny_job169['public_id'], 0 );
ok( str_contains( $tiny_html169, 'Increase &lt;0.01%' ), 'actual rendered tiny frozen ratio does not appear as zero' );
eq( Repo::hydrate_plan( Repo::read( (int) $tiny_job169['id'] ) )->hash(), $tiny_hash169, 'render never rewrites frozen percentage/hash' );
$display_options169 = array();
foreach ( array( 'woocommerce_currency', 'woocommerce_price_num_decimals', 'woocommerce_price_decimal_sep', 'woocommerce_price_thousand_sep', 'woocommerce_currency_pos', 'timezone_string', 'date_format', 'time_format' ) as $key169 ) { $display_options169[$key169] = get_option( $key169 ); }
try {
	update_option( 'woocommerce_currency', 'EUR' );
	update_option( 'woocommerce_price_num_decimals', 0 );
	update_option( 'woocommerce_price_decimal_sep', '.' );
	update_option( 'woocommerce_price_thousand_sep', ',' );
	update_option( 'woocommerce_currency_pos', 'left' );
	$saved169 = array( 'currency' => 'USD', 'price_decimals' => 2 );
	eq( Polish::money_display( '1234.50', $saved169 ), '$1,234.50 USD', 'saved currency/precision despite current EUR zero decimals' );
	eq( Polish::money_display( '1234', array( 'currency' => 'JPY', 'price_decimals' => 0 ) ), '¥1,234 JPY', 'saved zero-decimal currency' );
	eq( Polish::money_display( '-3.6', $saved169, true ), '-$3.60 USD', 'signed negative delta' );
	eq( Polish::money_display( '3.6', $saved169, true ), '+$3.60 USD', 'signed positive delta' );
	eq( Polish::money_display( '0', $saved169, true ), '$0.00 USD', 'known zero stays distinct' );
	eq( Polish::money_display( '18.001', $saved169 ), '$18.00 USD (exact: 18.001 USD)', 'fractional minor-unit truth visible' );
	eq( Polish::money_display( '-0.000001', $saved169, true ), '-$0.00 USD (exact: -0.000001 USD)', 'tiny negative delta remains visible' );
	eq( Polish::money_display( '999999999999.999999', $saved169 ), '$1,000,000,000,000.00 USD (exact: 999999999999.999999 USD)', 'large exact string not lost through floats' );
	foreach ( array( null, '', 'Unavailable', 'malformed', '1e2', '1.2345678' ) as $missing169 ) { eq( Polish::money_display( $missing169, $saved169 ), 'Unavailable', 'unknown money never becomes zero' ); }
	eq( Polish::money_display( '18', array() ), 'Unavailable', 'missing saved currency is not inferred' );
	eq( Polish::percentage_display( '20.000000' ), '20%', 'whole percent trim' );
	eq( Polish::percentage_display( '12.500000' ), '12.5%', 'fraction percent trim' );
	eq( Polish::percentage_display( '-12.345678', '-123' ), '≈ -12.35%', 'two-decimal display approximation labeled' );
	eq( Polish::percentage_display( '0.000001' ), '<0.01%', 'tiny input task label' );
	eq( Polish::percentage_display( '0.000001', '1' ), 'Increase <0.01%', 'tiny positive percent' );
	eq( Polish::percentage_display( '-0.000000', '-1' ), 'Decrease <0.01%', 'frozen rounded-zero ratio keeps exact nonzero direction' );
	eq( Polish::percentage_display( '0.000000', '0' ), '0%', 'actual zero percent' );
	eq( Polish::percentage_display( null ), 'Unavailable', 'undefined percentage never becomes zero' );
	update_option( 'woocommerce_price_decimal_sep', ',' );
	update_option( 'woocommerce_price_thousand_sep', '.' );
	update_option( 'woocommerce_currency_pos', 'right_space' );
	eq( Polish::money_display( '1234.50', $saved169 ), '1.234,50 $ USD', 'Woo locale separators and symbol placement' );
	eq( Polish::percentage_display( '12.500000' ), '12,5%', 'locale percentage separator' );
	update_option( 'timezone_string', 'Asia/Ho_Chi_Minh' );
	update_option( 'date_format', 'M j, Y' ); update_option( 'time_format', 'H:i' );
	eq( Polish::site_time( '2026-10-05 00:30:00' ), 'Oct 5, 2026 07:30 (Asia/Ho_Chi_Minh)', 'UTC saved timestamp rendered in site timezone/date format' );
	eq( Polish::site_time( null ), 'Unavailable', 'unknown time' );
	eq( Polish::site_time( 'invalid' ), 'Unavailable', 'invalid time' );
	// Display calls never mutate frozen evidence or hashes/targets/policy.
	$plan_before169 = $plan168->data(); $hash_before169 = $plan168->hash();
	Polish::task_description( $plan_before169 );
	foreach ( $plan_before169['items'] as $item169 ) {
		Polish::money_display( $item169['planned_regular_price'], $plan_before169['store'] );
		Polish::percentage_display( $item169['percentage_delta']['display'] ?? null, $item169['percentage_delta']['numerator'] ?? null );
	}
	eq( $plan168->data(), $plan_before169, 'display leaves frozen plan/target/policy untouched' );
	eq( $plan168->hash(), $hash_before169, 'display leaves fingerprint untouched' );
} finally {
	foreach ( $display_options169 as $key169 => $value169 ) { update_option( $key169, $value169 ); }
}
marker( '#169 money/percentage/locale/time presentation' );
