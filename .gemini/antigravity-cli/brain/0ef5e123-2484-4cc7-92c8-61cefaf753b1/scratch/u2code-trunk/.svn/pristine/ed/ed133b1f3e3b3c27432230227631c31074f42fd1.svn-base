<?php namespace BulkPriceEditor\PriceEditorPage;

/**
 * Keeps foreign admin notices off the price editor screen.
 *
 * The editor page owns its full layout and has its own notification UI (the
 * snackbar), so WordPress/plugin notices (update nags, other plugins' banners)
 * are only clutter pushing the widgets down the page.
 *
 * Two layers, weakest last:
 *
 *   1. The notice hooks are emptied right before they fire (`in_admin_header`
 *      runs immediately before them — by then every plugin has registered its
 *      notices and none has printed yet).
 *   2. CSS hides anything printed regardless (echoed from `admin_head`, or
 *      injected by another plugin's script). Direct children of the admin
 *      containers only, so the plugin's own markup can never match.
 *
 * Deliberately total — even WooCommerce update warnings are hidden here; they
 * remain visible on every other admin screen. Sites that disagree can return
 * false from the `bulk_price_editor/suppress_admin_notices` filter.
 */
class NoticeGuard {

	/**
	 * The notice hooks wp-admin/admin-header.php fires, in the order it fires them.
	 */
	const HOOKS = array(
		'network_admin_notices',
		'user_admin_notices',
		'admin_notices',
		'all_admin_notices',
	);

	public function __construct() {
		add_action( 'in_admin_header', array( $this, 'suppress' ), PHP_INT_MAX );

		// After PriceEditorPage::enqueueAssets, so the style handle exists.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueStyles' ), 20 );
	}

	/**
	 * Empty the notice hooks before they fire.
	 */
	public function suppress(): void {

		if ( ! $this->shouldSuppress() ) {
			return;
		}

		foreach ( self::HOOKS as $hook ) {
			remove_all_actions( $hook );
		}
	}

	/**
	 * Hide anything that is printed regardless.
	 */
	public function enqueueStyles(): void {

		if ( ! $this->shouldSuppress() ) {
			return;
		}

		$css = ':is( #wpcontent, #wpbody, #wpbody-content, .wrap )'
			   . ' > :is( .notice, .updated, .error, .update-nag ) { display: none !important; }';

		wp_add_inline_style( 'bulk-price-editor-page-css', $css );
	}

	protected function shouldSuppress(): bool {

		if ( ! PriceEditorPage::isCurrentPage() ) {
			return false;
		}

		/**
		 * Filter whether admin notices are hidden on the price editor screen.
		 *
		 * @param  bool  $suppress
		 */
		return (bool) apply_filters( 'bulk_price_editor/suppress_admin_notices', true );
	}
}
