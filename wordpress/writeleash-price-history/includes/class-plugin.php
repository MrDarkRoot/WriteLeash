<?php
namespace WriteLeash\PriceHistory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Independent scaffold: no tables, routes, scheduler work or price behavior. */
final class Plugin {
	public const VERSION = '0.0.0';
	// Internal ownership remains independent of any later public branding.
	public const TABLE_PREFIX = 'writeleash_price_history_';
	public const OPTION_PREFIX = 'writeleash_price_history_';
	public const VERSION_OPTION = 'writeleash_price_history_version';
	public const SCHEDULER_GROUP = 'writeleash-price-history';
	public const ACTION_HOOK = 'writeleash_price_history_wakeup';
	public const REST_NAMESPACE = 'writeleash-price-history/v1';

	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) { wp_die( 'Network activation is not implemented by this scaffold.' ); }
		if ( ! class_exists( '\WooCommerce' ) ) { wp_die( 'WooCommerce is required.' ); }
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			if ( ! update_option( self::VERSION_OPTION, self::VERSION, false ) ) {
				wp_die( 'Could not save plugin version metadata.' );
			}
		}
	}

	public static function deactivate(): void {
		// Retain this plugin's metadata. No shared state or scheduler cleanup.
	}
}
