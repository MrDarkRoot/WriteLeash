<?php
use WriteLeash\Free_Admin as A;
use WriteLeash\Job_Repository as R;
use WriteLeash\Job_Worker as W;
$file = getenv( 'WL210_FIXTURE' );
$mode = getenv( 'WL210_MODE' ) ?: 'seed';
if ( ! $file ) { throw new RuntimeException( 'Explicit disposable locale fixture required' ); }
global $wpdb;
if ( 'seed' === $mode ) {
	require __DIR__ . '/i18n-fixture-catalog.php';
	$username = 'i18n-' . wp_generate_uuid4(); $password = wp_generate_password( 24, false );
	$actor = wp_insert_user( array( 'user_login' => $username, 'user_pass' => $password, 'role' => 'shop_manager' ) ); wp_set_current_user( $actor );
	$parent = wp_insert_term( 'Locale parent ' . $actor, 'product_cat' )['term_id'];
	$child = wp_insert_term( 'Locale child ' . $actor, 'product_cat', array( 'parent' => $parent ) )['term_id'];
	$empty = wp_insert_term( 'Locale empty ' . $actor, 'product_cat' )['term_id'];
	$p = new WC_Product_Simple(); $p->set_name( 'Locale simple ' . $actor ); $p->set_status( 'publish' ); $p->set_regular_price( '100' ); $p->set_category_ids( array( $parent ) ); $p->save(); $id = $p->get_id();
	$v = new WC_Product_Variable(); $v->set_name( 'Locale variable ' . $actor ); $v->set_status( 'publish' ); $v->set_category_ids( array( $child ) ); $v->save();
	$variation = new WC_Product_Variation(); $variation->set_parent_id( $v->get_id() ); $variation->set_status( 'publish' ); $variation->set_regular_price( '100' ); $variation->save(); $variation_id = $variation->get_id(); WC_Product_Variable::sync( $v->get_id() );
	// These jobs are created in English before the merchant locale changes.
	$input = array( '_wpnonce' => wp_create_nonce( A::ACTION_PREVIEW ), 'selector' => 'ids', 'ids' => (string) $id, 'operation' => 'INCREASE_PERCENT', 'amount' => '10', 'max_products' => '1000', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '20' );
	$r = A::process_preview( $input, 'POST' );
	if ( 'OK' !== $r['status'] ) { throw new RuntimeException( wp_json_encode( $r ) ); }
	$job = R::read( $r['job_id'] ); $p->set_regular_price( '120' ); $p->save();
	$approved = A::process_approve( array( 'job' => $job['public_id'], '_wpnonce' => wp_create_nonce( A::ACTION_APPROVE . '_' . $job['plan_id'] ) ), 'POST' );
	if ( 'OK' !== $approved['status'] ) { throw new RuntimeException( 'Source approval failed' ); }
	W::run( (int) $job['id'], array( 'max_items' => 10, 'budget_seconds' => 20 ), true );
	if ( 'CONFLICT' !== R::items( (int) $job['id'] )[0]['state'] ) { throw new RuntimeException( 'Real source conflict required' ); }
	$input['ids'] = (string) $variation_id; $old = A::process_preview( $input, 'POST' );
	if ( 'OK' !== $old['status'] ) { throw new RuntimeException( 'Existing English plan unavailable' ); }
	$f = array( 'username' => $username, 'password' => $password, 'actor' => $actor, 'id' => $id, 'variation' => $variation_id, 'category' => $parent, 'child' => $child, 'empty' => $empty, 'source_job' => $job['public_id'], 'source_id' => (int) $job['id'], 'old_job' => $old['public_id'] );
	update_user_meta( $actor, 'locale', 'de_DE' );
	file_put_contents( $file, wp_json_encode( $f ) ); chmod( $file, 0600 );
	// Hold async execution so browser assertions observe saved READY progress.
	$mu = ABSPATH . 'wp-content/mu-plugins'; if ( ! is_dir( $mu ) ) { mkdir( $mu ); }
	file_put_contents( $mu . '/wl210-manual.php', '<?php add_filter("action_scheduler_allow_async_request_runner", "__return_false");' );
	echo "#210 localized browser fixture seeded\n"; return;
}
$f = json_decode( file_get_contents( $file ), true ); wp_set_current_user( $f['actor'] );
if ( 'run' === $mode ) {
	foreach ( WriteLeash\Undo_Repository::history_jobs( 0, 20, $f['actor'] )['jobs'] as $entry ) {
		$j = R::read( (int) $entry['job_id'] );
		if ( (int) $j['id'] !== $f['source_id'] && WriteLeash\Job_State::can_manual_run( $j['status'] ) ) { W::run( (int) $j['id'], array( 'max_items' => 10, 'budget_seconds' => 20 ), true ); }
	}
}
$source = R::read( $f['source_id'] );
$source_material = array( $source, R::items( $f['source_id'] ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE plan_id=%s ORDER BY product_id', WriteLeash\Price_Apply_Journal::table( $wpdb ), $source['plan_id'] ), ARRAY_A ) );
$jobs = array();
foreach ( WriteLeash\Undo_Repository::history_jobs( 0, 20, $f['actor'] )['jobs'] as $entry ) {
	$j = R::read( (int) $entry['job_id'] ); $plan = R::hydrate_plan( $j );
	$jobs[] = array( 'public_id' => $j['public_id'], 'status' => $j['status'], 'json' => $plan->json(), 'hash' => $plan->hash(), 'data' => $plan->data(), 'items' => R::items( (int) $j['id'] ) );
}
$csv = static function () use ( $source ) { $stream = fopen( 'php://temp', 'w+' ); A::write_job_csv( $stream, $source, R::hydrate_plan( $source ) ); rewind( $stream ); $rows = array(); while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) { $rows[] = $row; } fclose( $stream ); return $rows; };
$english_switched = switch_to_locale( 'en_US' );
$english = $csv();
if ( str_starts_with( A::reason_message( 'invalid_nonce' ), '[Ü] ' ) ) { throw new RuntimeException( 'English reference must use English gettext' ); }
switch_to_locale( 'de_DE' );
if ( ! str_starts_with( A::reason_message( 'invalid_nonce' ), '[Ü] ' ) ) { throw new RuntimeException( 'Effective PHP MO was not loaded' ); }
$localized = $csv();
if ( $english[1][1] === $localized[1][1] ) { throw new RuntimeException( 'Human CSV task was not translated' ); }
if ( $english[0] !== $localized[0] ) { throw new RuntimeException( 'CSV header translated' ); }
foreach ( array( 0, 5, 6, 7, 8, 9, 11, 12, 13, 15, 16, 17, 19, 21, 22, 23, 25, 26 ) as $index ) {
	if ( $english[1][$index] !== $localized[1][$index] ) { throw new RuntimeException( 'CSV machine value translated: ' . $index ); }
}
foreach ( $jobs as $j ) { if ( R::hydrate_plan( R::read_by_public_id( $j['public_id'] ) )->json() !== $j['json'] ) { throw new RuntimeException( 'Localized saved plan mutated' ); } }
restore_previous_locale();
if ( $english_switched ) { restore_previous_locale(); }
$prices = array();
foreach ( array( $f['id'], $f['variation'] ) as $id ) { $prices[$id] = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM %i WHERE post_id=%d AND meta_key='_regular_price'", $wpdb->postmeta, $id ) ); }
echo wp_json_encode( array( 'source' => $source_material, 'jobs' => $jobs, 'prices' => $prices, 'csv_header' => $localized[0], 'csv_machine_invariant' => true, 'locale_loaded' => true ) );
