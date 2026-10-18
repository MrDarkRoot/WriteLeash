<?php namespace BulkPriceEditor\Request;

use BulkPriceEditor\PriceModifiers\Dispatcher;
use BulkPriceEditor\PriceModifiers\Modifiers\PriceModifier;
use BulkPriceEditor\ProductFilters\FilterManager;

class Request {
	
	public static function getFilterManager( array $data ): FilterManager {
		return new FilterManager( self::getFilters( $data ) );
	}
	
	public static function  getFilters( $data ): array {
		return isset( $data['filters'] ) ? (array) json_decode( stripslashes( $data['filters'] ), true ) : array();
	}
	
	public static function getPriceModifier( array $data ): ?PriceModifier {
		
		$priceModifierData = self::getPriceModifierData( $data );
		
		$type = isset( $priceModifierData['type'] ) ? (string) $priceModifierData['type'] : '';
		$args = isset( $priceModifierData['args'] ) ? (array) $priceModifierData['args'] : array();
		
		$priceModifiersDispatcher = Dispatcher::getInstance();
		
		return $priceModifiersDispatcher->dispatchModifier( $type, $args );
	}
	
	public static function getPriceModifierData( array $data ): array {
		return isset( $data['priceModifiers'] ) ? (array) json_decode( stripslashes( $data['priceModifiers'] ),
			true ) : array();
	}

	/**
	 * When to apply the update: now or at a scheduled time, with an optional
	 * automatic revert time. Datetimes are site-timezone 'Y-m-d\TH:i' strings.
	 */
	public static function getScheduleData( array $data ): array {

		$schedule = isset( $data['schedule'] ) ? (array) json_decode( stripslashes( $data['schedule'] ), true ) : array();

		$mode = isset( $schedule['mode'] ) && 'later' === $schedule['mode'] ? 'later' : 'now';

		return array(
			'mode'        => $mode,
			'schedule_at' => isset( $schedule['schedule_at'] ) ? sanitize_text_field( (string) $schedule['schedule_at'] ) : '',
			'revert_at'   => isset( $schedule['revert_at'] ) ? sanitize_text_field( (string) $schedule['revert_at'] ) : '',
		);
	}
}
