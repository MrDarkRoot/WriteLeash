<?php
// Real immutable Plan/calculator with existing Woo fixtures; export never uses Woo.
require __DIR__ . '/../free/variations.php';
require __DIR__ . '/../../writeleash/includes/free/class-free-admin.php';
use WriteLeash\Free_Admin as CsvAdmin;
use WriteLeash\Change_Plan as CsvPlan;
use WriteLeash\Price_Operation as CsvOp;
use WriteLeash\Product_Price_Snapshot as CsvSnapshot;

function csv233_rows( CsvPlan $plan ): array {
	$stream = fopen( 'php://temp/maxmemory:1048576', 'w+' );
	try {
		CsvAdmin::write_preview_csv( $stream, $plan );
		rewind( $stream ); $header = fgetcsv( $stream, 0, ',', '"', '' ); $rows = array();
		while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) { $rows[] = array_combine( $header, $row ); }
		return $rows;
	} finally { fclose( $stream ); }
}
function csv233_matches( CsvPlan $plan ): void {
	$before = $plan->json();
	$rows = csv233_rows( $plan ); $index = 0; $offset = 0;
	do {
		$page = $plan->preview_page( $offset, 20 );
		foreach ( $page['items'] as $item ) {
			$row = $rows[$index++];
			foreach ( array( 'product_id' => 'product_id', 'expected_price' => 'expected_regular_price', 'planned_price' => 'planned_regular_price', 'delta' => 'absolute_delta', 'result' => 'result', 'stored_price_at_preview' => 'stored_price' ) as $column => $key ) {
				wl179_equal( $row[$column], (string) ( $item[$key] ?? '' ), 'CSV equals frozen Preview: ' . $column );
			}
			wl179_equal( $row['percent_delta'], $item['percentage_delta']['display'] ?? '', 'signed frozen percent display' );
			wl179_equal( $row['price_field'], $plan->price_field(), 'frozen field' );
			wl179_equal( $row['regular_price_at_preview'], $item['snapshot']['regular_price'], 'raw regular price' );
		}
		$offset = $page['next_offset'];
	} while ( null !== $offset );
	wl179_equal( count( $rows ), $plan->summary()['selected'], 'all reviewed rows, independent of pagination' );
	wl179_equal( $plan->json(), $before, 'export leaves immutable material/hash identical' );
}
$simple233 = wl179_simple( 2331, '100.00', '80.00' );
$zero233 = wl179_simple( 2332, '100.00', '0' );
$blank233 = wl179_simple( 2333, '100.00', '' );
$parent233 = wl179_variable( 2330, array( 2334 ) );
$variation233 = wl179_variation( 2334, 2330, '120.00', '90.00' );
foreach ( array(
	new CsvOp( CsvOp::DECREASE_PERCENT, '10' ),
	new CsvOp( CsvOp::SET, '80', CsvOp::FIELD_SALE ),
	new CsvOp( CsvOp::CLEAR_SALE, '', CsvOp::FIELD_SALE ),
	new CsvOp( CsvOp::SALE_DISCOUNT_PERCENT, '20', CsvOp::FIELD_SALE ),
	new CsvOp( CsvOp::DECREASE_PERCENT, '10', CsvOp::FIELD_REGULAR, '99' ),
	new CsvOp( CsvOp::SALE_DISCOUNT_PERCENT, '20', CsvOp::FIELD_SALE, '95' ),
) as $operation233 ) {
	$plan233 = wl179_plan( array( $simple233, $zero233, $blank233, $variation233 ), $operation233 );
	csv233_matches( CsvPlan::hydrate( json_decode( $plan233->json(), true ) ) );
}
$clear233 = csv233_rows( wl179_plan( array( $zero233, $blank233 ), new CsvOp( CsvOp::CLEAR_SALE, '', CsvOp::FIELD_SALE ) ) );
wl179_equal( array_column( $clear233, 'expected_price' ), array( '0', '' ), 'clear preserves zero versus absent sale' );
wl179_equal( array_column( $clear233, 'planned_price' ), array( '', '' ), 'clear targets canonical blank, never zero' );
wl179_equal( array_column( $clear233, 'result' ), array( 'CHANGING', 'UNCHANGED' ), 'clear unchanged represented honestly' );
wl179_equal( array_column( $clear233, 'delta' ), array( '', '' ), 'clear is not a numeric discount' );
$zero_target233 = csv233_rows( wl179_plan( array( $simple233 ), new CsvOp( CsvOp::SET, '0', CsvOp::FIELD_SALE ) ) );
wl179_equal( $zero_target233[0]['planned_price'], '0.00', 'numeric zero target keeps exact formatting' );

$plan233 = wl179_plan( array( $simple233 ), new CsvOp( CsvOp::DECREASE_PERCENT, '10', CsvOp::FIELD_REGULAR, '99' ) );
$frozen233 = csv233_rows( $plan233 );
$simple233->wl_set_value( 'regular_price', '999.99' ); $simple233->wl_set_value( 'name', 'Changed live' );
$later233 = csv233_rows( $plan233 );
unset( $frozen233[0]['exported_at_utc'], $later233[0]['exported_at_utc'] );
wl179_equal( $later233, $frozen233, 'live edits do not change any frozen value' );
wl179_equal( $later233[0]['planned_price'], '89.99', 'Price Endings final rounded target' );
wl179_equal( $later233[0]['delta'], '-10.01', 'negative delta remains parseable numeric string' );

// Unsupported rows exist only because they were included in this reviewed Plan.
$draft233 = wl179_register( new WC_Product_Simple( 2335, array( 'status' => 'draft', 'regular_price' => '10' ) ) );
$unsupported233 = csv233_rows( wl179_plan( array( $draft233 ), new CsvOp( CsvOp::SET, '8' ) ) );
wl179_equal( $unsupported233[0]['result'], 'UNSUPPORTED', 'refused row retained' );
wl179_equal( $unsupported233[0]['reason'], 'unsupported_status', 'refusal reason retained' );
wl179_equal( $unsupported233[0]['planned_price'], '', 'unsupported target never fabricated' );
$blocked233 = wl179_plan( array( wl179_simple( 2336, '100' ) ), new CsvOp( CsvOp::SET, '999' ) );
$blocked_rows233 = csv233_rows( $blocked233 );
wl179_equal( $blocked_rows233[0]['plan_status'], 'BLOCKED', 'plan-level refusal explicit, result retains domain meaning' );
wl179_equal( strpos( $blocked_rows233[0]['reason'], 'plan_policy_blocked' ) === 0, true, 'blocked changing row has no approval implication' );
wl179_equal( json_decode( $blocked_rows233[0]['plan_warnings'], true )['policy'], $blocked233->data()['policy_result']['warnings'], 'structured plan warnings preserved' );
wl179_equal( json_decode( $blocked_rows233[0]['plan_blockers'], true ), $blocked233->data()['policy_result']['blockers'], 'structured plan blockers preserved' );

// Spreadsheet disguises in text columns must never receive numeric exemptions.
foreach ( array( '=1+1', '+1', '-10.01', '@SUM(1)', "\tformula", "\rformula", "\nformula", '  =1', " \t@SUM(1)", "\0=1", "\x1f+1", "\xc2\xa0=1", "\xe2\x80\x8b=1", "\xef\xbb\xbf=1" ) as $payload233 ) {
	$p233 = wl179_simple( 2337, '100' ); $p233->wl_set_value( 'name', $payload233 ); $p233->wl_set_value( 'sku', $payload233 );
	$row233 = csv233_rows( wl179_plan( array( $p233 ), new CsvOp( CsvOp::SET, '90' ) ) )[0];
	wl179_equal( $row233['product_name_at_preview'], "'" . $payload233, 'formula/whitespace/control text neutralized and round trips' );
	wl179_equal( $row233['sku_at_preview'], "'" . $payload233, 'SKU formula neutralized even if numeric-looking' );
	wl179_equal( $row233['delta'], '-10', 'legitimate numeric negative delta exempt' );
}
foreach ( array( 'simple', 'variation' ) as $kind233 ) {
	$old233 = trim( file_get_contents( __DIR__ . '/i18n-baseline-' . $kind233 . '.json' ) );
	$legacy233 = CsvPlan::hydrate( json_decode( $old233, true ) );
	csv233_matches( $legacy233 );
	wl179_equal( $legacy233->json(), $old233, 'old Plan bytes/hash unchanged' );
}

// 1,000 targets, >1 MiB file forces temp spill. Read one row at a time, no CSV array.
$products233 = array();
for ( $i233 = 3000; $i233 < 4000; ++$i233 ) {
	$p233 = wl179_simple( $i233, '100.00' ); $p233->wl_set_value( 'name', str_repeat( 'N', 2048 ) ); $products233[] = $p233;
}
$large233 = wl179_plan( $products233, new CsvOp( CsvOp::SET, '90' ) );
$stream233 = fopen( 'php://temp/maxmemory:1048576', 'w+' );
$memory233 = memory_get_usage();
CsvAdmin::write_preview_csv( $stream233, $large233 );
$growth233 = memory_get_usage() - $memory233;
wl179_equal( $growth233 < 2097152, true, 'export retained memory bounded below 2 MiB' );
wl179_equal( ftell( $stream233 ) > 1048576, true, 'large export exceeds memory spool threshold' );
rewind( $stream233 ); fgetcsv( $stream233, 0, ',', '"', '' ); $count233 = 0;
while ( false !== ( $row233 = fgetcsv( $stream233, 0, ',', '"', '' ) ) ) {
	wl179_equal( $row233[1], (string) ( 3000 + $count233 ), 'all 1000 sorted frozen IDs present once' ); ++$count233;
}
fclose( $stream233 ); wl179_equal( $count233, 1000, '1000-target complete export' );
echo '#233 frozen CSV unit: PASS; 1000 rows, retained memory growth=' . $growth233 . " bytes\n";
