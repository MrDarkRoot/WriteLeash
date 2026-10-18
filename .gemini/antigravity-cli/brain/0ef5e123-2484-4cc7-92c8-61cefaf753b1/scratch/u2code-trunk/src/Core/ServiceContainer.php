<?php namespace BulkPriceEditor\Core;

use Exception;

class ServiceContainer {
	
	private array $services = array();
	
	private static ?self $instance = null;
	
	private function __construct() {}
	
	public static function getInstance(): self {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	public function add( $name, $instance ) {
		$this->services[ $name ] = $instance;
	}
	
	public function initService( $className, $dependencies = [] ) {
		
		$className = apply_filters( 'bulk_price_editor/container/service_instance', $className );
		
		$this->add( $className, new $className( ...$dependencies ) );
	}
	
	/**
	 * Get service
	 *
	 * @param $name
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function get( $name ) {
		if ( ! empty( $this->services[ $name ] ) ) {
			return $this->services[ $name ];
		}
		
		throw new Exception( 'Undefined service' );
	}
	
	/**
	 * Get fileManager
	 *
	 * @return FileManager
	 */
	public function getFileManager(): ?FileManager {
		try {
			return $this->get( 'fileManager' );
		} catch ( Exception $e ) {
			return null;
		}
	}
	
	/**
	 * Get AdminNotifier
	 *
	 * @return AdminNotifier
	 */
	public function getAdminNotifier(): ?AdminNotifier {
		try {
			return $this->get( 'adminNotifier' );
		} catch ( Exception $e ) {
			return null;
		}
	}
}
