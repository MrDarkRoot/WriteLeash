<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** #112 shipping boundary, separate from the #107 engineering selector limit. */
final class Free_Support_Contract {
	public const MAX_JOB_PRODUCTS = 100;
	public const WOOCOMMERCE_VERSION = '11.1.2';

	/** Safe to call during bootstrap: activation does not depend on Woo init. */
	public static function woocommerce_reason(): ?string {
		if ( ! function_exists( 'wc_get_product' ) || ! defined( 'WC_VERSION' ) || ! did_action( 'woocommerce_init' ) ) {
			return 'woocommerce_unavailable';
		}
		return self::WOOCOMMERCE_VERSION === WC_VERSION ? null : 'woocommerce_version_unsupported';
	}

	public static function woocommerce_ok(): bool {
		return null === self::woocommerce_reason();
	}

	/** Selected products, not just changing products or the caller's policy. */
	public static function assert_job_size( int $selected ): void {
		if ( $selected > self::MAX_JOB_PRODUCTS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Typed integer data, not an exception/output message; Admin renders the derived notice through esc_html().
			throw new Free_Job_Limit_Error( $selected );
		}
	}
}

/** Carries a bounded scalar count through the authenticated Admin PRG notice. */
final class Free_Job_Limit_Error extends \RuntimeException {
	private int $selected;
	public function __construct( int $selected ) {
		parent::__construct( 'supported_job_limit_exceeded' );
		$this->selected = $selected;
	}
	public function selected(): int { return $this->selected; }
}
