<?php
// Existing measured Admin journey: download from first AND last Preview pages.
$csv_before233 = wl112_evidence_rows();
$csv_job233 = WriteLeash\Job_Repository::read_by_public_id( $public );
$csv_digests233 = array();
foreach ( array( $preview, $page ) as $csv_page233 ) {
	$csv_fields233 = wl112_form( $csv_page233['body'], WriteLeash\Free_Admin::ACTION_EXPORT_PREVIEW );
	$csv_response233 = wl112_http( 'POST', 'http://127.0.0.1:8080/wp-admin/admin-post.php', $csv_fields233 );
	wl112_assert( 200 === $csv_response233['code'], 'Preview CSV native POST before Apply' );
	$csv_stream233 = fopen( 'php://temp/maxmemory:1048576', 'w+' );
	fwrite( $csv_stream233, $csv_response233['body'] ); rewind( $csv_stream233 );
	$csv_header233 = fgetcsv( $csv_stream233, 0, ',', '"', '' ); $csv_count233 = 0;
	$csv_hash233 = hash_init( 'sha256' );
	while ( false !== ( $csv_line233 = fgetcsv( $csv_stream233, 0, ',', '"', '' ) ) ) {
		$csv_row233 = array_combine( $csv_header233, $csv_line233 );
		wl112_assert( $csv_row233['product_id'] === (string) $ids[$csv_count233], 'CSV covers all frozen targets exactly once' );
		$csv_item233 = $plan->item( (int) $csv_row233['product_id'] )->data();
		foreach ( array( 'expected_price' => 'expected_regular_price', 'planned_price' => 'planned_regular_price', 'delta' => 'absolute_delta', 'result' => 'result' ) as $csv_column233 => $csv_key233 ) {
			wl112_assert( $csv_row233[$csv_column233] === (string) ( $csv_item233[$csv_key233] ?? '' ), 'CSV matches persisted reviewed value: ' . $csv_column233 );
		}
		wl112_assert( $csv_row233['plan_id'] === $job['plan_id'] && $csv_row233['plan_hash'] === $plan->hash(), 'CSV identity/fingerprint matches reviewed Plan' );
		unset( $csv_row233['exported_at_utc'] ); hash_update( $csv_hash233, json_encode( $csv_row233 ) ); ++$csv_count233;
	}
	fclose( $csv_stream233 );
	wl112_assert( $csv_count233 === $size, 'complete bounded CSV including unchanged rows' );
	$csv_digests233[] = hash_final( $csv_hash233 );
}
wl112_assert( $csv_digests233[0] === $csv_digests233[1], 'CSV independent of Preview pagination' );
wl112_assert( $csv_before233 === wl112_evidence_rows(), 'CSV creates no Plan, journal, approval or Undo evidence' );
wl112_assert( $csv_job233 === WriteLeash\Job_Repository::read_by_public_id( $public ), 'CSV leaves complete job record unchanged' );
wl112_assert( array() === wl112_save_counts( $facts['request_start_line'] ), 'CSV performs zero Woo product saves before Apply' );
$facts['preview_csv'] = array( 'outcome' => 'PASS', 'rows' => $csv_count233, 'first_and_last_page_sha256' => $csv_digests233, 'frozen_match' => true, 'woo_saves' => 0, 'evidence_unchanged' => true );
echo '#233 ' . DB_HOST . ' ' . $size . ': native Preview CSV frozen match, pagination parity and zero saves PASS' . "\n";
