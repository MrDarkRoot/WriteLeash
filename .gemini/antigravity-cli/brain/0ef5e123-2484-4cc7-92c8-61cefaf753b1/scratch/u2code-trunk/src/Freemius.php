<?php namespace BulkPriceEditor;

use Freemius as FSLicense;

/**
 * Class Freemius
 *
 * @package BulkPriceEditor
 */
class Freemius {
	
	private ?FSLicense $license;
	
	private static ?Freemius $instance = null;
	
	/**
	 * Freemius constructor.
	 */
	protected function __construct() {
		$this->init();
		
		if ( $this->isValid() ) {
			$this->hooks();
		}
	}
	
	public static function getInstance(): ?Freemius {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	public function hooks() {
		add_action( 'admin_menu', [ $this, 'initPages' ] );
		
		$this->license->add_filter( 'templates/pricing.php', function ( $template ) {
			ob_start();
			?>

			<style>
				#fs_pricing_app {
					/*			--fs-ds-blue-300: #faf6f9;
								--fs-ds-blue-500: #814c77;
								--fs-ds-blue-600: #814c77;
								--fs-ds-blue-700: #814c77;
								--fs-ds-blue-800: #814c77;
								--fs-ds-blue-900: #814c77;
			
								--fs-ds-theme-background-color: #faf6f9;*/
				}
			</style>
			<?php
			
			$style = ob_get_clean();
			
			return $style . $template;
		} );
		
		$this->license->add_filter( 'pricing/show_annual_in_monthly', '__return_false' );
	}
	
	public function isValid(): bool {
		return $this->license instanceof FSLicense;
	}
	
	public function init() {
		if ( function_exists( 'bpefw_fs' ) ) {
			$this->license = bpefw_fs();
		}
	}
	
	public function initPages() {
		
		// Account
		add_submenu_page( '__freemius', __( 'Freemius Account', 'tier-price-table' ),
			__( 'Freemius Account', 'bulk-price-editor-for-woocommerce' ), 'manage_options',
			'bulk-price-editor-account', [ $this, 'renderAccountPage' ] );
		
		// Contact us
		add_submenu_page( '__freemius', __( 'Contact Us', 'tiered-price-table' ),
			__( 'Contact Us', 'bulk-price-editor-for-woocommerce' ), 'manage_options', 'bulk-price-editor-contact-us',
			[ $this, 'renderContactUsPage' ] );
	}
	
	public function renderAccountPage() {
		
		if ( ! $this->license->get_user() || $this->license->is_activation_mode() || $this->license->is_anonymous() ) {
			wp_safe_redirect( $this->license->get_activation_url() );
			exit;
		} else {
			$this->license->_account_page_load();
			$this->license->_account_page_render();
		}
	}
	
	public function getUpgradeURL(): string {
		return bpefw_fs_upgrade_url();
	}

	/**
	 * The SDK's own contact/account submenus are disabled, so its
	 * contact_url()/get_account_url() point at pages that do not exist —
	 * these are the plugin's registered ones (see initPages()).
	 */
	public static function getContactURL(): string {
		return admin_url( 'admin.php?page=bulk-price-editor-contact-us' );
	}

	public static function getAccountURL(): string {
		return admin_url( 'admin.php?page=bulk-price-editor-account' );
	}
	
	public function renderContactUsPage() {
		$this->license->_contact_page_render();
	}
}