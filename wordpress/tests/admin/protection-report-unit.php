<?php
// #326 hermetic protection-report regression.
// Runs with plain PHP. No WordPress, no database, no network:
//   php wordpress/tests/admin/protection-report-unit.php
// Fixture rows cover the window, surface counts, grant budget, empty state,
// pagination bounds, and the denial-log parser. The parser must drop detail.

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 2 ) . '/writeleash/includes/free/class-protection-report-query.php';

use WriteLeash\Protection_Report_Query as Report;

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
	echo "#326 $label: PASS ($assertions assertions)\n";
}

function denial_at( int $unix, string $surface, int $actor, int $product, int $seq ): array {
	return array(
		'time' => gmdate( 'Y-m-d', $unix ) . 'T' . gmdate( 'H:i:s', $unix ) . '+00:00',
		'unix' => $unix,
		'actor_id' => $actor,
		'surface' => $surface,
		'product_id' => $product,
		'reason' => 'writeleash_perimeter_denied',
		'seq' => $seq,
	);
}
function grant_row( string $id, string $status, int $actor, int $max, int $used, int $reserved, string $expires, $removed = null ): array {
	return array(
		'public_id' => $id,
		'status' => $status,
		'actor_id' => $actor,
		'budget_max' => $max,
		'budget_consumed' => $used,
		'budget_reserved' => $reserved,
		'expires_at' => $expires,
		'restriction_removed_at' => $removed,
	);
}

$now = strtotime( '2026-11-10 12:00:00 UTC' );
ok( false !== $now, 'fixture clock parses' );
$now = (int) $now;

// ---------------------------------------------------------------------------
// Empty state.
// ---------------------------------------------------------------------------
$empty = Report::build( array(), array(), 7, 1, $now );
eq( $empty['empty'], true, 'no rows is the empty state' );
eq( $empty['blocked'], 0, 'empty blocked count' );
eq( $empty['rows'], array(), 'empty table' );
eq( $empty['active_grant_count'], 0, 'empty active grants' );
eq( $empty['expiring_grant_count'], 0, 'empty expiring grants' );
eq( $empty['window_days'], 7, 'default window when empty input still names 7' );
$surface_sum = 0;
foreach ( Report::SURFACES as $surface ) {
	eq( $empty['surfaces'][ $surface ], 0, 'known surface starts at zero: ' . $surface );
	$surface_sum += $empty['surfaces'][ $surface ];
}
eq( $surface_sum, 0, 'empty surface counts sum to zero' );
marker( 'empty state' );

// ---------------------------------------------------------------------------
// Window, surfaces the log stores, and pagination.
// ---------------------------------------------------------------------------
$denials = array(
	denial_at( $now - ( 6 * 86400 ), 'rest', 4, 4, 1 ),
	denial_at( $now - ( 7 * 86400 ), 'product_save', 4, 10, 2 ),
	denial_at( $now - ( 7 * 86400 ) - 1, 'pre_trash_post', 4, 11, 3 ),
	denial_at( $now - ( 40 * 86400 ), 'update_post_metadata', 5, 12, 4 ),
	denial_at( $now + 1, 'variation_save', 5, 13, 5 ),
	denial_at( $now - 60, 'product_set_stock', 6, 14, 6 ),
	denial_at( $now - 90, 'rest', 6, 15, 7 ),
);
$denials[0] = denial_at( $now - 10, 'rest', 4, 4, 1 );
$denials[6]['surface'] = 'not a surface';
$denials[6]['detail'] = 'secret-payload-body';
$week = Report::build( $denials, array(), 7, 1, $now );
eq( $week['blocked'], 4, '7-day window keeps the edge and drops older and future rows' );
eq( $week['surfaces']['rest'], 1, 'rest surface counted from the log where field' );
eq( $week['surfaces']['product_save'], 1, 'product_save counted' );
eq( $week['surfaces']['product_set_stock'], 1, 'product_set_stock counted' );
eq( $week['surfaces']['pre_trash_post'], 0, 'one second outside the 7-day edge is excluded' );
eq( $week['surfaces']['update_post_metadata'], 0, '40-day-old meta denial is outside 7 days' );
eq( $week['surfaces']['variation_save'], 0, 'future denial is excluded' );
eq( $week['surfaces']['unrecognized'], 1, 'unsafe surface text counts as unrecognized' );
eq( isset( $week['surfaces']['not a surface'] ), false, 'unsafe surface text is not a column' );
ok( false === strpos( (string) json_encode( $week ), 'secret-payload-body' ), 'build does not copy payload detail' );
$week_sum = 0;
foreach ( $week['surfaces'] as $count ) { $week_sum += $count; }
eq( $week_sum, $week['blocked'], 'surface counts reconcile with blocked attempts' );
eq( $week['empty'], false, 'rows are not the empty state' );
eq( $week['rows'][0]['surface'], 'rest', 'newest refusal is first' );
eq( $week['rows'][0]['actor_id'], 4, 'actor is the user id' );
eq( $week['rows'][0]['product_id'], 4, 'REST object id is shown as stored' );
eq( $week['rows'][0]['reason'], 'writeleash_perimeter_denied', 'reason is the denial code' );
ok( false === strpos( json_encode( $week['rows'] ), 'not a surface' ), 'unsafe surface text is not rendered from build' );

$month = Report::build( $denials, array(), 30, 1, $now );
eq( $month['blocked'], 5, '30-day window adds the refusal just outside 7 days' );
eq( $month['surfaces']['pre_trash_post'], 1, 'trash surface appears in the 30-day window' );
$quarter = Report::build( $denials, array(), 90, 1, $now );
eq( $quarter['blocked'], 6, '90-day window adds the 40-day-old meta refusal' );
eq( $quarter['surfaces']['update_post_metadata'], 1, 'meta surface is the stored where value' );
eq( Report::normalize_window( '14' ), 7, 'unlisted window falls back to 7' );
eq( Report::normalize_window( '30' ), 30, '30 is a real window' );
eq( Report::normalize_window( '90' ), 90, '90 is a real window' );
eq( Report::normalize_window( null ), 7, 'missing window falls back to 7' );

$paged = array();
for ( $i = 0; $i < 25; ++$i ) {
	$paged[] = denial_at( $now - $i, 'rest', 8, 100 + $i, $i );
}
$page1 = Report::build( $paged, array(), 7, 1, $now );
$page2 = Report::build( $paged, array(), 7, 2, $now );
eq( $page1['blocked'], 25, 'pagination does not change the blocked count' );
eq( count( $page1['rows'] ), Report::PAGE_SIZE, 'page size is bounded' );
eq( count( $page2['rows'] ), 5, 'second page is the remainder' );
eq( $page1['rows'][0]['product_id'], 100, 'page 1 starts at the newest product id' );
eq( $page2['rows'][4]['product_id'], 124, 'page 2 ends at the oldest fixture id' );
eq( $page1['pages'], 2, '25 rows are two pages' );
eq( Report::build( $paged, array(), 7, 100, $now )['page'], 2, 'page past the end clamps to the last page' );
eq( Report::normalize_page( '0' ), 1, 'page 0 clamps to 1' );
eq( Report::normalize_page( '-3' ), 1, 'negative page clamps to 1' );
eq( Report::normalize_page( '100' ), Report::MAX_PAGE, 'page above the cap clamps' );

$many = array();
for ( $i = 0; $i < 401; ++$i ) {
	$many[] = denial_at( $now - $i, 'add_post_metadata', 9, 500 + $i, $i );
}
$capped = Report::build( $many, array(), 7, 1, $now );
eq( $capped['blocked'], 401, 'blocked count includes every in-window fixture row' );
eq( $capped['listed'], Report::MAX_LISTED, 'the table pool is capped' );
eq( $capped['list_capped'], true, 'list cap is reported' );
eq( $capped['pages'], Report::MAX_PAGE, 'pages stop at the cap' );
eq( count( $capped['rows'] ), Report::PAGE_SIZE, 'first capped page is one page' );
eq( $capped['surfaces']['add_post_metadata'], 401, 'surface count keeps the row past the table cap' );
$last = Report::build( $many, array(), 7, Report::MAX_PAGE, $now );
eq( $last['rows'][0]['product_id'], 500 + 380, 'last page starts at the newest row still listed' );
foreach ( $last['rows'] as $listed_row ) {
	ok( 900 !== $listed_row['product_id'], 'the oldest overflow row is not listed' );
}
marker( 'window surfaces and pagination' );

// ---------------------------------------------------------------------------
// Active grants: budget, expiry, restriction.
// ---------------------------------------------------------------------------
$soon = gmdate( 'Y-m-d H:i:s', $now + ( 3 * 86400 ) );
$later = gmdate( 'Y-m-d H:i:s', $now + ( 20 * 86400 ) );
$past = gmdate( 'Y-m-d H:i:s', $now - 60 );
$exact = gmdate( 'Y-m-d H:i:s', $now );
$grants = array(
	grant_row( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'active', 4, 10, 3, 2, $soon, null ),
	grant_row( 'bbbbbbbb-bbbb-cccc-dddd-eeeeeeeeeeee', 'active', 5, 10, 8, 5, $later, null ),
	grant_row( 'cccccccc-bbbb-cccc-dddd-eeeeeeeeeeee', 'active', 6, 4, 0, 0, $past, null ),
	grant_row( 'dddddddd-bbbb-cccc-dddd-eeeeeeeeeeee', 'active', 7, 4, 0, 0, $exact, null ),
	grant_row( 'eeeeeeee-bbbb-cccc-dddd-eeeeeeeeeeee', 'revoked', 8, 4, 1, 0, $later, null ),
	grant_row( 'ffffffff-bbbb-cccc-dddd-eeeeeeeeeeee', 'active', 9, 4, 1, 0, $later, '2026-01-01 00:00:00' ),
);
$grant_report = Report::build( array(), $grants, 7, 1, $now );
eq( $grant_report['empty'], true, 'grants alone do not invent a refusal' );
eq( $grant_report['active_grant_count'], 2, 'expired, revoked, and removed grants are not active' );
eq( $grant_report['expiring_grant_count'], 1, 'only the grant inside the 7-day window is expiring' );
eq( count( $grant_report['grants'] ), 2, 'two grant rows are listed' );
eq( $grant_report['grants'][0]['public_id'], 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'soonest expiry is listed first' );
eq( $grant_report['grants'][0]['budget_used'], 3, 'used is consumed' );
eq( $grant_report['grants'][0]['budget_reserved'], 2, 'reserved is kept visible' );
eq( $grant_report['grants'][0]['budget_remaining'], 5, 'remaining is max minus used and reserved' );
eq( $grant_report['grants'][0]['expiring_soon'], true, 'three-day expiry is inside the window' );
eq( $grant_report['grants'][1]['budget_remaining'], 0, 'remaining never goes negative' );
eq( $grant_report['grants'][1]['expiring_soon'], false, 'twenty-day expiry is outside a 7-day window' );
$grant_month = Report::build( array(), $grants, 30, 1, $now );
eq( $grant_month['expiring_grant_count'], 2, '30-day window marks both active grants as expiring' );
marker( 'grant budget and expiry' );

// ---------------------------------------------------------------------------
// Denial log fixture. Detail and route must not survive the parse.
// ---------------------------------------------------------------------------
$log = tempnam( sys_get_temp_dir(), 'wl326' );
if ( ! is_string( $log ) ) { throw new RuntimeException( 'temp log was not created' ); }
$recent = gmdate( 'Y-m-d', $now - 120 ) . 'T' . gmdate( 'H:i:s', $now - 120 ) . '+00:00';
$old = gmdate( 'Y-m-d', $now - ( 10 * 86400 ) ) . 'T' . gmdate( 'H:i:s', $now - ( 10 * 86400 ) ) . '+00:00';
$lines = array(
	'PHP Notice: unrelated debug noise',
	json_encode( array( 'timestamp' => $old, 'code' => 'writeleash_perimeter_denied', 'where' => 'pre_delete_post', 'actor' => 4, 'object_id' => 77, 'kind' => 'delete', 'detail' => 'secret-payload-body' ) ),
	json_encode( array( 'timestamp' => $recent, 'code' => 'writeleash_perimeter_denied', 'where' => 'rest', 'actor' => 4, 'object_id' => 4, 'kind' => '0', 'detail' => '/wc/v3/products/77', 'route' => '/wc/v3/products/77' ) ),
	json_encode( array( 'timestamp' => $recent, 'code' => 'some_other_code', 'where' => 'rest', 'actor' => 1, 'object_id' => 1 ) ),
	'{not json',
);
file_put_contents( $log, implode( "\n", $lines ) . "\n" );
$parsed = Report::read_log( $log );
eq( count( $parsed['rows'] ), 2, 'noise and other codes are ignored' );
eq( $parsed['capped'], false, 'a small fixture is not capped' );
$encoded = json_encode( $parsed );
ok( false === strpos( (string) $encoded, 'secret-payload-body' ), 'payload detail is not retained' );
ok( false === strpos( (string) $encoded, '/wc/v3/products/77' ), 'route detail is not retained' );
ok( false === strpos( (string) $encoded, '"kind"' ), 'kind is not a report field' );
eq( $parsed['rows'][1]['surface'], 'rest', 'second fixture line is the REST denial' );
eq( $parsed['rows'][1]['reason'], 'writeleash_perimeter_denied', 'parsed reason is the denial code' );
eq( $parsed['rows'][1]['actor_id'], 4, 'parsed actor is the user id' );
$from_log = Report::build( $parsed['rows'], array(), 7, 1, $now );
eq( $from_log['blocked'], 1, 'the old log line is outside the 7-day window' );
eq( $from_log['surfaces']['rest'], 1, 'the kept log line counts as rest' );
eq( $from_log['rows'][0]['product_id'], 4, 'REST product column is the stored object id' );

$capped_lines = array();
for ( $i = 0; $i < 3; ++$i ) {
	$stamp = gmdate( 'Y-m-d', $now - $i ) . 'T' . gmdate( 'H:i:s', $now - $i ) . '+00:00';
	$capped_lines[] = json_encode( array( 'timestamp' => $stamp, 'code' => 'writeleash_perimeter_denied', 'where' => 'rest', 'actor' => 4, 'object_id' => 20 + $i, 'kind' => '0', 'detail' => 'drop-me' ) );
}
file_put_contents( $log, implode( "\n", $capped_lines ) . "\n" );
$capped_log = Report::read_log( $log, 0, 2 );
eq( $capped_log['capped'], true, 'row cap is reported' );
eq( count( $capped_log['rows'] ), 2, 'row cap keeps two lines' );
eq( $capped_log['rows'][0]['product_id'], 21, 'row cap keeps the newer lines' );
eq( $capped_log['rows'][1]['product_id'], 22, 'row cap ends at the newest line' );
ok( false === strpos( (string) json_encode( $capped_log ), 'drop-me' ), 'capped lines still drop detail' );
unlink( $log );
eq( Report::read_log( '' )['rows'], array(), 'missing log is an empty read' );
marker( 'denial log fixture' );

// ---------------------------------------------------------------------------
// Source assertion: the new classes do not call the network.
// ---------------------------------------------------------------------------
$root = dirname( __DIR__, 2 ) . '/writeleash';
$files = array(
	$root . '/includes/free/class-protection-report-query.php',
	$root . '/includes/free/class-protection-report-admin.php',
);
foreach ( $files as $file ) {
	ok( is_file( $file ), 'class file exists: ' . basename( $file ) );
	$source = (string) file_get_contents( $file );
	$code = preg_replace( '/\/\*.*?\*\//s', '', $source );
	$code = preg_replace( '/\/\/.*$/m', '', (string) $code );
	foreach ( array( 'wp_remote_', 'curl_', 'file_get_contents', 'http://', 'https://' ) as $token ) {
		ok( false === strpos( (string) $code, $token ), basename( $file ) . ' has no ' . $token );
	}
}
$query = (string) file_get_contents( $files[0] );
$admin = (string) file_get_contents( $files[1] );
ok( false !== strpos( $query, '$db->prepare(' ), 'grant reads are prepared' );
ok( false !== strpos( $query, '%i' ), 'table names use the identifier placeholder' );
foreach ( array( 'INSERT', 'UPDATE', 'DELETE' ) as $sql ) {
	ok( false === strpos( $query, $sql ), 'query class has no ' . $sql );
}
ok( false === strpos( $admin, '$_GET' ), 'admin does not read the superglobal' );
ok( false === strpos( $admin, '$_POST' ), 'admin does not read POST' );
ok( false === strpos( $admin, '$_REQUEST' ), 'admin does not read REQUEST' );
ok( false !== strpos( $admin, 'filter_input' ), 'window and page come from filter_input' );
ok( false !== strpos( $admin, 'Grant_Admin::can_mutate()' ), 'capability gate is the grant-management pair' );
ok( false !== strpos( $admin, "edit.php?post_type=product" ), 'screen is registered under Products' );
ok( false !== strpos( $admin, 'esc_html' ), 'output is escaped' );
ok( false === strpos( $admin, 'user_email' ), 'actor text has no email field' );
ok( false !== strpos( $admin, 'display_name' ), 'actor text uses the display name' );
$plugin = (string) file_get_contents( $root . '/includes/class-plugin.php' );
$main = (string) file_get_contents( $root . '/writeleash.php' );
ok( false !== strpos( $plugin, 'Protection_Report_Admin::boot()' ), 'report screen is booted' );
ok( false !== strpos( $main, 'class-protection-report-query.php' ), 'query class is required from the plugin' );
ok( false !== strpos( $main, 'class-protection-report-admin.php' ), 'admin class is required from the plugin' );
foreach ( array( 'writeleash-distribution-files.txt', 'writeleash-woo-distribution-files.txt' ) as $manifest ) {
	$list = (string) file_get_contents( dirname( __DIR__, 2 ) . '/release/' . $manifest );
	ok( false !== strpos( $list, 'includes/free/class-protection-report-query.php' ), $manifest . ' lists the query' );
	ok( false !== strpos( $list, 'includes/free/class-protection-report-admin.php' ), $manifest . ' lists the admin' );
}
marker( 'source has no network call' );

echo "#326 protection report unit: PASS ($assertions assertions)\n";
