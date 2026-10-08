<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/** #112 shipping boundary, aligned with the #107 engineering selector maximum by #177. */
final class Free_Support_Contract {
	public const MAX_JOB_PRODUCTS = 1000;
	/**
	 * Supported WooCommerce range. Every storage/API assumption WriteLeash
	 * relies on is stable across this span: CPT data-store CRUD price
	 * setters, the wc_product_meta_lookup min/max/onsale shape, the
	 * currency/decimals helpers, the public product-factory type/classname
	 * lookup and the transient/cache-group invalidation calls. Fresh reads
	 * construct the exact core product class directly and clean caches
	 * through public WordPress/Woo helpers only, so no version-conditional
	 * cache class is required anywhere in this span. The ceiling excludes the
	 * next major until its storage contract is re-certified.
	 */
	public const WOOCOMMERCE_MIN = '10.0.0';
	public const WOOCOMMERCE_MAX_EXCLUSIVE = '12.0.0';
	/**
	 * Supported WordPress range. Matches the plugin header and WooCommerce
	 * 11's own minimum (WordPress 7.0); only long-stable WordPress APIs are
	 * used (options, WP_Query, roles/capabilities), so patch/minor drift
	 * inside this span is safe.
	 */
	public const WORDPRESS_MIN = '7.0';
	public const WORDPRESS_MAX_EXCLUSIVE = '8.0';

	/** Safe to call during bootstrap: activation does not depend on Woo init. */
	public static function woocommerce_reason(): ?string {
		if ( ! function_exists( 'wc_get_product' ) || ! defined( 'WC_VERSION' ) || ! did_action( 'woocommerce_init' ) ) {
			return 'woocommerce_unavailable';
		}
		return self::woo_supported( WC_VERSION ) ? null : 'woocommerce_version_unsupported';
	}

	public static function woocommerce_ok(): bool {
		return null === self::woocommerce_reason();
	}

	/** Whether an installed WooCommerce version is inside the supported range. */
	public static function woo_supported( string $version ): bool {
		return '' !== $version && version_compare( $version, self::WOOCOMMERCE_MIN, '>=' ) && version_compare( $version, self::WOOCOMMERCE_MAX_EXCLUSIVE, '<' );
	}

	/** Whether a WordPress version is inside the supported range. */
	public static function wp_supported( string $version ): bool {
		return '' !== $version && version_compare( $version, self::WORDPRESS_MIN, '>=' ) && version_compare( $version, self::WORDPRESS_MAX_EXCLUSIVE, '<' );
	}

	/**
	 * Routine patch/minor drift inside the supported ranges does not change
	 * any product/price fact WriteLeash guards, so a frozen plan stays valid
	 * across it. Drift that leaves the supported range (on either side, at
	 * either timestamp) is not routine and stays a conflict.
	 */
	public static function versions_compatible( string $frozen_wp, string $frozen_woo, string $current_wp, string $current_woo ): bool {
		return self::wp_supported( $frozen_wp ) && self::woo_supported( $frozen_woo ) && self::wp_supported( $current_wp ) && self::woo_supported( $current_woo );
	}

	/** Merchant-facing short form of the supported WooCommerce range. */
	public static function range_text(): string {
		return 'WooCommerce ' . self::WOOCOMMERCE_MIN . ' through 11.x';
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
