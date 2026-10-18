<?php namespace BulkPriceEditor;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use BulkPriceEditor\Actions\ActionsManager;
use BulkPriceEditor\CLI\Commands;
use BulkPriceEditor\Core\AdminNotifier;
use BulkPriceEditor\Core\FileManager;
use BulkPriceEditor\Core\ServiceContainerTrait;
use BulkPriceEditor\History\HistoryManager;
use BulkPriceEditor\History\Schema;
use BulkPriceEditor\Integrations\Integrations;
use BulkPriceEditor\PriceEditorPage\PriceEditorPage;
use BulkPriceEditor\PriceModifiers\Dispatcher;
use BulkPriceEditor\ProductFilters\Templates\TemplateManager;
use BulkPriceEditor\ProductsTable\Services\AjaxHandler;

/**
 * Class BulkPriceEditorPlugin
 *
 * @package BulkPriceEditor
 */
class BulkPriceEditorPlugin {
	
	use ServiceContainerTrait;
	
	const VERSION = '2.5.0';
	
	public function __construct( string $mainFile ) {
		
		Freemius::getInstance();
		
		// Core
		$this->getContainer()->add( 'fileManager', new FileManager( $mainFile ) );
		$this->getContainer()->add( 'adminNotifier', new AdminNotifier() );
		
		$this->saveActivationTime();
		$this->declareCompatibilities();
	}
	
	public function declareCompatibilities() {
		add_action( 'before_woocommerce_init', function () {
			if ( class_exists( FeaturesUtil::class ) ) {
				
				$mainFile = $this->getContainer()->getFileManager()->getMainFile();
				
				FeaturesUtil::declare_compatibility( 'custom_order_tables', $mainFile );
				FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $mainFile );
				FeaturesUtil::declare_compatibility( 'product_block_editor', $mainFile );
			}
		} );
	}
	
	public function initializationHooks() {
		add_action( 'init', array( $this, 'loadTextDomain' ), - 999 );
		add_filter( 'plugin_row_meta', array( $this, 'addPluginsMeta' ), 10, 2 );
	}
	
	public function checkRequirements(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		
		// Check if WooCommerce is active
		if ( ! ( is_plugin_active( 'woocommerce/woocommerce.php' ) || is_plugin_active_for_network( 'woocommerce/woocommerce.php' ) ) ) {
			/* translators: %s: required plugin */
			$message = sprintf( __( '<b>Bulk Price Editor</b> plugin requires %s to be installed and activated.',
				'bulk-price-editor' ),
				'<a target="_blank" href="https://wordpress.org/plugins/woocommerce/">WooCommerce</a>' );
			
			$this->getContainer()->getAdminNotifier()->push( $message, AdminNotifier::ERROR );
			
			return false;
		}
		
		return true;
	}
	
	/**
	 * Entry point when every requirement is passed
	 */
	public function run() {

		$this->initializationHooks();

		new PriceEditorPage();
		new ActionsManager();
		new Integrations();

		TemplateManager::getInstance();
		HistoryManager::init();

		// Per-product price change tracking (all sources, not only this plugin).
		\BulkPriceEditor\History\PriceLogger::init();
		\BulkPriceEditor\History\PriceLogProductScreen::init();
		\BulkPriceEditor\PriceEditorPage\SettingsTab::init();

		$this->getContainer()->add( Dispatcher::class, Dispatcher::getInstance() );

		// Init Services
		add_action( 'init', function () {
			Schema::maybeUpgrade();

			$this->getContainer()->initService( AjaxHandler::class );
		} );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register();
		}
	}

	/**
	 * Capability required to use the editor and all its actions. Defaults to
	 * administrators; can be relaxed (e.g. to `manage_woocommerce` for shop
	 * managers) with the filter.
	 */
	public static function capability(): string {
		return (string) apply_filters( 'bulk_price_editor/capability', 'manage_options' );
	}
	
	public function addPluginsMeta( $links, $file ) {
		
		if ( strpos( $file, 'bulk-price-editor' ) === false ) {
			return $links;
		}
		
		$links['docs'] = '<a target="_blank" href="' . self::getDocumentationURL() . '">' . __( 'Documentation',
				'bulk-price-editor' ) . '</a>';
		
		return $links;
	}
	
	/**
	 * Load plugin translations
	 */
	public function loadTextDomain() {
		$name = $this->getContainer()->getFileManager()->getPluginName();
		load_plugin_textdomain( 'bulk-price-editor', false, $name . '/languages/' );
	}
	
	/**
	 * Intentionally no cleanup on uninstall: plugin options (templates, settings)
	 * must survive so users can switch between free and premium versions
	 * without losing their data.
	 */
	public static function uninstall() {}
	
	/**
	 * Plugin activation
	 */
	public function activate() {
		Schema::install();
	}
	
	public static function getDocumentationURL(): string {
		return 'https://price-editor.com/documentation/';
	}
	
	public static function getSupportedProductTypes(): array {
		return apply_filters( 'bulk_price_editor/supported_product_type', array(
			'simple'    => __( 'Simple product', 'bulk-price-editor' ),
			'variable'  => __( 'Variable product', 'bulk-price-editor' ),
			'variation' => __( 'Product variation', 'bulk-price-editor' ),
			'external'  => __( 'External product', 'bulk-price-editor' ),
		) );
	}
	
	public static function getDefaultProductTypes(): array {
		// Not the `supported_product_type` filter: that one carries a keyed
		// map, this one a plain list — sharing a name broke third-party callbacks.
		return apply_filters( 'bulk_price_editor/default_product_types', array(
			'simple',
			'variation',
			'external',
		) );
	}
	
	public function saveActivationTime() {
		if ( ! get_option( 'bpe_plugin_activation_timestamp', false ) ) {
			update_option( 'bpe_plugin_activation_timestamp', time() );
		}
	}
	
	public static function getPluginActivationDate(): ?int {
		return intval( get_option( 'bpe_plugin_activation_timestamp', 0 ) );
	}
}
