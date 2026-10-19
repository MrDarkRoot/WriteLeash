<?php
namespace WriteLeash;

defined( 'ABSPATH' ) || exit;

/**
 * #307 follow-up: admin notice for the standing-grant kill-switch.
 *
 * When Grant_Repository::is_disabled() reports restricted (grant-bound)
 * price writes as disabled — operator kill-switch on, or the option missing
 * or malformed and therefore failing closed — operators managing products
 * see a passive notice. Read-only: the notice never flips state, never
 * grants capabilities, and never injects its own request handling.
 *
 * @package WriteLeash
 */
final class Grant_Notice {

	public static function boot(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the kill-switch notice for product-management users only.
	 * Fail silent: any store read failure renders no notice (a manager who
	 * cannot be proven restricted sees nothing; the perimeter itself stays
	 * fail-closed independently).
	 */
	public static function render(): void {
		try {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			if ( ! class_exists( Grant_Repository::class, false ) || ! Grant_Repository::is_disabled() ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WriteLeash: restricted price writes are currently disabled by the kill-switch.', 'writeleash' ) . '</p></div>';
		} catch ( \Throwable $ignored ) {
			// A broken notice must never break the admin screen.
		}
	}
}
