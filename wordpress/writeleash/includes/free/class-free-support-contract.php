<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** #112 shipping boundary, aligned with the #107 engineering selector maximum by #177. */
final class Free_Support_Contract {
	public const MAX_JOB_PRODUCTS = 1000;
	/**
	 * #299 delegated submission safety (#298 “Approver: grant authority alone
	 * does not imply human approval”). Stricter than any human Apply form
	 * because no human sees the job before execution: 50% increase/decrease
	 * caps, 20% warning threshold, and zero-target blocking.
	 */
	public const GRANT_MAX_INCREASE_PERCENT = '50';
	public const GRANT_MAX_DECREASE_PERCENT = '50';
	public const GRANT_BLOCK_ZERO = true;
	public const GRANT_WARNING_THRESHOLD_PERCENT = '20';
	/**
	 * #310 delegated stock bounds. No human reviews these jobs before
	 * execution, so the absolute delta ceiling and the floor stay fixed:
	 * planned quantity must stay within [0, expected ± 1000].
	 */
	public const MAX_STOCK_ABS_DELTA = '1000';
	public const MIN_STOCK_FLOOR = '0';
	/**
	 * Certified pairs, not a rectangle. A WordPress version and a WooCommerce
	 * version are supported only together, on one of these two bands:
	 *
	 * Modern: WordPress >= 7.0 and < 8.0, AND WooCommerce >= 10.0.0 and < 12.0.0.
	 * Legacy: WordPress >= 6.7 and < 7.0, AND WooCommerce >= 9.0.0 and < 10.0.0.
	 *
	 * Every other combination is refused before a job is created, including
	 * WooCommerce 9 on WordPress 7, WooCommerce 10 or 11 on WordPress 6.7/6.8,
	 * WordPress below 6.7, and WooCommerce below 9.0.0. The modern storage/API
	 * assumptions (CPT data-store price setters, wc_product_meta_lookup shape,
	 * currency/decimals helpers, the public product factory, and public cache
	 * invalidation) were certified on that line. The legacy line is only the
	 * tested WordPress 6.7/6.8 plus WooCommerce 9.x pairs. Fresh reads still
	 * construct the core product class directly and clean caches through
	 * public helpers, so no version-conditional cache class is required.
	 * Ceilings stay exclusive until the next major is re-certified.
	 * WOOCOMMERCE_MIN and WORDPRESS_MIN are the modern floors, not the
	 * directory headers (those name the legacy floors).
	 */
	public const WOOCOMMERCE_MIN = '10.0.0';
	public const WOOCOMMERCE_MAX_EXCLUSIVE = '12.0.0';
	public const WORDPRESS_MIN = '7.0';
	public const WORDPRESS_MAX_EXCLUSIVE = '8.0';
	public const LEGACY_WORDPRESS_MIN = '6.7';
	public const LEGACY_WORDPRESS_MAX_EXCLUSIVE = '7.0';
	public const LEGACY_WOOCOMMERCE_MIN = '9.0.0';
	public const LEGACY_WOOCOMMERCE_MAX_EXCLUSIVE = '10.0.0';

	/** Safe to call during bootstrap: activation does not depend on Woo init. */
	public static function woocommerce_reason(): ?string {
		if ( ! function_exists( 'wc_get_product' ) || ! defined( 'WC_VERSION' ) || ! did_action( 'woocommerce_init' ) ) {
			return 'woocommerce_unavailable';
		}
		global $wp_version;
		$wp = isset( $wp_version ) ? (string) $wp_version : '';
		return null !== self::support_band( $wp, (string) WC_VERSION ) ? null : 'woocommerce_version_unsupported';
	}

	public static function woocommerce_ok(): bool {
		return null === self::woocommerce_reason();
	}

	/**
	 * Modern, legacy, or null. Null is refused. The halves are not
	 * interchangeable: WooCommerce 9 is legacy-only and WooCommerce 10/11
	 * are modern-only.
	 */
	public static function support_band( string $wp, string $woo ): ?string {
		if ( self::in_span( $wp, self::WORDPRESS_MIN, self::WORDPRESS_MAX_EXCLUSIVE ) && self::in_span( $woo, self::WOOCOMMERCE_MIN, self::WOOCOMMERCE_MAX_EXCLUSIVE ) ) {
			return 'modern';
		}
		if ( self::in_span( $wp, self::LEGACY_WORDPRESS_MIN, self::LEGACY_WORDPRESS_MAX_EXCLUSIVE ) && self::in_span( $woo, self::LEGACY_WOOCOMMERCE_MIN, self::LEGACY_WOOCOMMERCE_MAX_EXCLUSIVE ) ) {
			return 'legacy';
		}
		return null;
	}

	/**
	 * Woo half of some certified band. Not a support decision: WooCommerce
	 * 9.9.7 is true here and still refused on WordPress 7.
	 */
	public static function woo_supported( string $version ): bool {
		return self::in_span( $version, self::LEGACY_WOOCOMMERCE_MIN, self::LEGACY_WOOCOMMERCE_MAX_EXCLUSIVE )
			|| self::in_span( $version, self::WOOCOMMERCE_MIN, self::WOOCOMMERCE_MAX_EXCLUSIVE );
	}

	/**
	 * WordPress half of some certified band. Not a support decision:
	 * WordPress 6.8 is true here and still refused with WooCommerce 10 or newer.
	 */
	public static function wp_supported( string $version ): bool {
		return self::in_span( $version, self::LEGACY_WORDPRESS_MIN, self::LEGACY_WORDPRESS_MAX_EXCLUSIVE )
			|| self::in_span( $version, self::WORDPRESS_MIN, self::WORDPRESS_MAX_EXCLUSIVE );
	}

	/**
	 * Patch/minor drift stays valid only inside one certified band. A frozen
	 * plan recorded on one band must not apply on the other, and a timestamp
	 * outside both bands is a conflict on either side.
	 */
	public static function versions_compatible( string $frozen_wp, string $frozen_woo, string $current_wp, string $current_woo ): bool {
		$frozen = self::support_band( $frozen_wp, $frozen_woo );
		$current = self::support_band( $current_wp, $current_woo );
		return null !== $frozen && $frozen === $current;
	}

	/** Merchant-facing pair rule. Not a single WooCommerce 9.0 through 11.x rectangle. */
	public static function range_text(): string {
		return __( 'WooCommerce 10.0 through 11.x on WordPress 7.0 up to 8.0, or WooCommerce 9.0 below 10.0 on WordPress 6.7 below 7.0', 'writeleash' );
	}

	private static function in_span( string $version, string $min, string $max_exclusive ): bool {
		return '' !== $version && version_compare( $version, $min, '>=' ) && version_compare( $version, $max_exclusive, '<' );
	}

	/**
	 * Item-row form of an execution refusal. Item reasons must match the
	 * durable `[A-Z0-9_]` vocabulary; a transiently unavailable WooCommerce
	 * (for example mid-update) stays retryable under TRANSACTION_UNAVAILABLE.
	 */
	public static function item_reason( string $reason ): string {
		if ( 'woocommerce_version_unsupported' === $reason ) { return 'WOOCOMMERCE_VERSION_UNSUPPORTED'; }
		if ( 'multisite_unsupported' === $reason ) { return 'MULTISITE_UNSUPPORTED'; }
		if ( 'db_transactions_unsupported' === $reason ) { return 'DB_TRANSACTIONS_UNSUPPORTED'; }
		return 'TRANSACTION_UNAVAILABLE';
	}

	/**
	 * Pre-mutation environment gate for the Apply/Undo item transactions.
	 * Returns null when execution may proceed, otherwise a typed,
	 * actionable reason. Ordinary WordPress database setups (including
	 * standard wpdb subclasses/drop-ins on a mysqli handle) pass; only
	 * conditions that genuinely prevent a safe transaction fail.
	 */
	public static function execution_reason( $db ): ?string {
		$woo = self::woocommerce_reason();
		if ( null !== $woo ) {
			return $woo;
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return 'multisite_unsupported';
		}
		if ( ! $db instanceof \wpdb || ! $db->dbh instanceof \mysqli ) {
			return 'db_transactions_unsupported';
		}
		return null;
	}

	/** NEW selected work, not execution validity or historical recovery authority. */
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
