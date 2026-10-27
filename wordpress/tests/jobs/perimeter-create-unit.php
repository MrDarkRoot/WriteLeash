<?php
// #306 hermetic regression: an unframed product create is denied while the
// WooCommerce id is still 0, which is before wp_insert_post. No WordPress,
// no database, no network:
//   php wordpress/tests/jobs/perimeter-create-unit.php

namespace WriteLeash {
	class Grant_Repository {
		public static bool $restricted = false;
		public static function is_restricted_actor( int $user_id = 0 ): bool {
			return self::$restricted;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function get_current_user_id() {
		return 22;
	}

	function do_action( ...$args ) {
		$GLOBALS['wl306_actions'][] = $args;
	}

	class WC_Product {
		public function __construct( private int $id ) {}
		public function get_id(): int { return $this->id; }
	}

	class WC_Data_Exception extends RuntimeException {
		private string $error_code;
		public function __construct( string $code, string $message ) {
			parent::__construct( $message );
			$this->error_code = $code;
		}
		public function getErrorCode(): string { return $this->error_code; }
	}

	require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-perimeter-guard.php';

	$assertions = 0;
	function eq( $actual, $expected, string $label ): void {
		global $assertions;
		++$assertions;
		if ( $actual !== $expected ) {
			throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) );
		}
	}
	function ok( bool $condition, string $label ): void { eq( $condition, true, $label ); }
	function marker( string $label ): void {
		global $assertions;
		echo "#306 $label: PASS ($assertions assertions)\n";
	}
	function denied( callable $call ): string {
		try {
			$call();
		} catch ( WC_Data_Exception $error ) {
			return $error->getErrorCode();
		}
		throw new RuntimeException( 'expected WC_Data_Exception' );
	}

	$GLOBALS['wl306_actions'] = array();
	\WriteLeash\Grant_Repository::$restricted = false;
	\WriteLeash\Perimeter_Guard::before_product_save( new WC_Product( 0 ) );
	\WriteLeash\Perimeter_Guard::before_variation_save( new WC_Product( 0 ) );
	eq( $GLOBALS['wl306_actions'], array(), 'ordinary principal creates are not perimeter events' );
	marker( 'ordinary create is outside the leash' );

	\WriteLeash\Grant_Repository::$restricted = true;
	eq( denied( static function () { \WriteLeash\Perimeter_Guard::before_product_save( new WC_Product( 0 ) ); } ), 'writeleash_perimeter_denied', 'simple create denied' );
	eq( denied( static function () { \WriteLeash\Perimeter_Guard::before_variation_save( new WC_Product( 0 ) ); } ), 'writeleash_perimeter_denied', 'variation create denied' );
	// A frame for a real product must not authorize an id-0 insert.
	\WriteLeash\Perimeter_Guard::open_frame( 22, 7, 'plan-create', array( 55 ), 'price', 'regular_price' );
	eq( denied( static function () { \WriteLeash\Perimeter_Guard::before_product_save( new WC_Product( 0 ) ); } ), 'writeleash_perimeter_denied', 'framed sibling does not authorize create' );
	\WriteLeash\Perimeter_Guard::before_product_save( new WC_Product( 55 ) );
	\WriteLeash\Perimeter_Guard::close_frame();
	eq( denied( static function () { \WriteLeash\Perimeter_Guard::before_product_save( new WC_Product( 55 ) ); } ), 'writeleash_perimeter_denied', 'closed frame denies the same product' );
	marker( 'unframed create denied before insert' );

	echo "#306 perimeter-create-unit PASS ($assertions assertions)\n";
}
