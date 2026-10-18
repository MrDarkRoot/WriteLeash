<?php namespace BulkPriceEditor\PriceModifiers;

use BulkPriceEditor\PriceModifiers\Modifiers\AddFlatValue;
use BulkPriceEditor\PriceModifiers\Modifiers\NewPrices;
use BulkPriceEditor\PriceModifiers\Modifiers\PriceModifier;
use BulkPriceEditor\PriceModifiers\Modifiers\RemovePrice;
use BulkPriceEditor\PriceModifiers\Modifiers\IncreaseByPercent;
use BulkPriceEditor\PriceModifiers\Modifiers\DecreaseByPercent;
use BulkPriceEditor\PriceModifiers\Modifiers\SubtractFlatValue;
use BulkPriceEditor\PriceModifiers\Modifiers\UpdateByFormula;

class Dispatcher {
	
	protected static ?self $instance = null;
	
	/**
	 * Array of registered price modifiers
	 *
	 * @var PriceModifier[]
	 */
	protected array $registeredPriceModifiers = array();
	
	protected function __construct() {
		add_action( 'init', function () {
			$this->registeredPriceModifiers = apply_filters( 'bulk_price_editor/price_modifiers', array(
				NewPrices::class,
				RemovePrice::class,
				IncreaseByPercent::class,
				DecreaseByPercent::class,
				AddFlatValue::class,
				SubtractFlatValue::class,
				UpdateByFormula::class,
			) );
		} );
	}
	
	public static function getInstance(): Dispatcher {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	/**
	 * Get all registered price modifiers
	 *
	 * @return PriceModifier[]
	 */
	public function getRegisteredPriceModifiers(): array {
		return $this->registeredPriceModifiers;
	}
	
	public function dispatchModifier( ?string $type, array $args = array() ): ?PriceModifier {
		
		foreach ( $this->getRegisteredPriceModifiers() as $modifier ) {
			
			$modifier = new $modifier();
			
			if ( $modifier->getType() === $type ) {
				$modifier->setRawArgs( $args );
				
				return $modifier;
			}
		}
		
		return null;
	}
	
}
