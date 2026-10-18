<?php
// Hermetic schema checks for the #299 submission parser. No WordPress, no Woo writes.
namespace WriteLeash {
	class Grant_Repository {
		public const IDEMPOTENCY_REGEX = '/\A[a-zA-Z0-9_-]{1,64}\z/';
	}
	class Product_Price_Selector {
		public const MAX_SELECTED = 1000;
	}
}
namespace {
define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-price-decimal.php';
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-price-operation.php';
require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-agent-jobs-rest.php';

$assertions = 0;
function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) {
		throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) );
	}
}
function reason( array $body ): string {
	$parsed = \WriteLeash\Agent_Jobs_Rest::parse_submission( $body );
	return (string) ( $parsed['error'] ?? 'OK' );
}
function body( array $over = array() ): array {
	return array_merge(
		array(
			'idempotency_key' => 'key-1',
			'actor_id' => 22,
			'grant_version' => 1,
			'price_field' => 'regular_price',
			'operation' => 'SET',
			'target' => '19.00',
			'product_ids' => array( 10, 11 ),
		),
		$over
	);
}

eq( reason( body() ), 'OK', 'absolute set parses' );
$parsed = \WriteLeash\Agent_Jobs_Rest::parse_submission( body( array( 'product_ids' => array( 11, 10 ) ) ) );
eq( $parsed['product_ids'], array( 10, 11 ), 'ids freeze in numeric order' );
eq( reason( body( array( 'operation' => 'INCREASE_PERCENT' ) ) ), 'UNSUPPORTED_OPERATION', 'percent rejected' );
eq( reason( body( array( 'operation' => 'DECREASE_FIXED' ) ) ), 'UNSUPPORTED_OPERATION', 'delta rejected' );
eq( reason( body( array( 'callback' => 'system' ) ) ), 'MALFORMED', 'unknown field rejected' );
eq( reason( body( array( 'selector' => 'sku' ) ) ), 'MALFORMED', 'selector rejected' );
eq( reason( array() ), 'MALFORMED', 'empty object rejected' );
eq( reason( array( 1, 2 ) ), 'MALFORMED', 'list rejected' );
eq( reason( body( array( 'actor_id' => '22' ) ) ), 'MALFORMED', 'string actor rejected' );
eq( reason( body( array( 'grant_version' => 0 ) ) ), 'MALFORMED', 'zero version rejected' );
eq( reason( body( array( 'idempotency_key' => 'bad key' ) ) ), 'GRANT_IDEMPOTENCY_INVALID', 'spaced key rejected' );
eq( reason( body( array( 'product_ids' => array( 10, 10 ) ) ) ), 'MALFORMED', 'duplicate ids rejected' );
eq( reason( body( array( 'target' => '-1' ) ) ), 'MALFORMED', 'negative target rejected' );
eq( reason( body( array( 'price_field' => '_price' ) ) ), 'MALFORMED', 'meta key rejected' );
$source = file_get_contents( dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-agent-jobs-rest.php' );
foreach ( array( 'set_regular_price', 'set_sale_price', 'update_post_meta(', 'update_metadata(', 'WC_Product_Variable::sync' ) as $token ) {
	eq( false === strpos( $source, $token ), true, 'submission route has no price writer token ' . $token );
}
eq( \WriteLeash\Agent_Jobs_Rest::plan_id( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'key-1' ), \WriteLeash\Agent_Jobs_Rest::plan_id( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'key-1' ), 'plan id is stable' );
eq( \WriteLeash\Agent_Jobs_Rest::plan_id( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'key-1' ) === \WriteLeash\Agent_Jobs_Rest::plan_id( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'key-2' ), false, 'plan id follows the key' );
echo '#299 agent jobs schema: PASS (' . $assertions . " assertions)\n";
}
