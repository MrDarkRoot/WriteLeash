<?php namespace BulkPriceEditor\Core;

use function \extract as allowedExtract;

class FileManager {
	
	/**
	 * Main file
	 *
	 * @var string
	 */
	private $mainFile;
	
	/**
	 * Directory of the plugin
	 *
	 * @var string
	 */
	private $pluginDirectory;
	
	/**
	 * Plugin name
	 *
	 * @var string
	 */
	private $pluginName;
	
	/**
	 * Plugin url
	 *
	 * @var string
	 */
	private $pluginUrl;
	
	/**
	 * Theme directory
	 *
	 * @var string
	 */
	private $themeDirectory;
	
	/**
	 * PluginManager constructor.
	 *
	 * @param  string  $mainFile
	 * @param  string|null  $themeDirectory
	 */
	public function __construct( $mainFile, $themeDirectory = null ) {
		$this->mainFile        = $mainFile;
		$this->pluginDirectory = plugin_dir_path( $this->mainFile );
		$this->pluginName      = basename( $this->pluginDirectory );
		$this->themeDirectory  = $themeDirectory ? $themeDirectory : $this->pluginName;
		$this->pluginUrl       = plugin_dir_url( $this->getMainFile() );
	}
	
	/**
	 * Get the plugin directory
	 *
	 * @return string
	 */
	public function getPluginDirectory() {
		return $this->pluginDirectory;
	}
	
	/**
	 * Return name of the plugin
	 *
	 * @return string
	 */
	public function getPluginName() {
		return $this->pluginName;
	}
	
	/**
	 * Get the main file
	 *
	 * @return string
	 */
	public function getMainFile() {
		return $this->mainFile;
	}
	
	/**
	 * Get the plugin url
	 *
	 * @return string
	 */
	public function getPluginUrl() {
		return $this->pluginUrl;
	}
	
	/**
	 * Include template
	 *
	 * @param  string  $__template
	 * @param  array  $__variables
	 */
	public function includeTemplate( $__template, array $__variables = array() ) {
		$__template = $this->locateTemplate( $__template );
		
		if ( $__template ) {
			allowedExtract( $__variables );
			
			do_action( 'bulk_price_editor/template/before_render', $__template, $__variables );
			// nosemgrep: audit.php.lang.security.file.inclusion-arg
			include( $__template );
			do_action( 'bulk_price_editor/template/after_render', $__template, $__variables );
		}
	}
	
	/**
	 * Render template
	 *
	 * @param  string  $template
	 * @param  array  $variables
	 *
	 * @return string
	 */
	public function renderTemplate( $template, array $variables = array() ) {
		ob_start();
		$this->includeTemplate( $template, $variables );
		$content = ob_get_contents();
		ob_end_clean();
		
		return $content;
	}
	
	/**
	 * Locate assets
	 *
	 * @param  string  $file
	 *
	 * @return string
	 */
	public function locateAsset( $file ) {
		return $this->pluginUrl . 'assets/' . $file;
	}
	
	/**
	 * Locate assets
	 *
	 * @param  string  $file
	 *
	 * @return string
	 */
	public function locateJSAsset( $file ) {
		
		$file = str_replace( '.js', '', $file );
		
		$js = '.js';
		
		if ( defined( 'BULK_PRICE_EDITOR_PRODUCTION' ) ) {
			$js = '.min.js';
		}
		
		return $this->pluginUrl . 'assets/' . $file . $js;
	}
	
	/**
	 * Locate template
	 *
	 * @param  string  $template
	 *
	 * @return string
	 */
	public function locateTemplate( $template ) {
		
		$file = $this->pluginDirectory . 'views/' . $template;
		
		if ( strpos( $template, 'frontend/' ) === 0 ) {
			
			$frontendTemplate = str_replace( 'frontend/', '', $template );
			$frontendFile     = locate_template( $this->themeDirectory . '/' . $frontendTemplate );
			
			if ( $frontendFile ) {
				$file = $frontendFile;
			}
		}
		
		return apply_filters( 'bulk_price_editor/template/location', $file, $template );
	}
	
}
