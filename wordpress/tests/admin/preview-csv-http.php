<?php
// Actual native form submission over WordPress HTTP, with no JavaScript runtime.
$forms233 = forms_for_action( $preview_page['body'], 'writeleash_free_export_preview' );
beq( count( $forms233 ), 1, 'Preview offers one native CSV download form before approval' );
bok( str_contains( $preview_page['body'], 'not the current live catalog and not an offline approval' ), 'snapshot notice in Preview' );
$job_http233 = WriteLeash\Job_Repository::read_by_public_id( $public_id );
$csv_http233 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), $forms233[0] );
beq( $csv_http233['code'], 200, 'no-JS form downloads CSV before Apply' );
beq( $csv_http233['content_type'], 'text/csv; charset=UTF-8', 'safe CSV content type' );
beq( $csv_http233['content_disposition'], 'attachment; filename="writeleash-preview-' . $public_id . '.csv"', 'safe job-ID-only filename' );
$stream_http233 = fopen( 'php://temp', 'w+' ); fwrite( $stream_http233, $csv_http233['body'] ); rewind( $stream_http233 );
$header_http233 = fgetcsv( $stream_http233, 0, ',', '"', '' ); $ids_http233 = array();
while ( false !== ( $line_http233 = fgetcsv( $stream_http233, 0, ',', '"', '' ) ) ) {
	$row_http233 = array_combine( $header_http233, $line_http233 ); $ids_http233[] = (int) $row_http233['product_id'];
	beq( $row_http233['planned_price'], '80.00', 'actual downloaded target equals Preview' );
	beq( $row_http233['plan_id'], $job_http233['plan_id'], 'download belongs to exact reviewed Plan' );
}
fclose( $stream_http233 ); beq( $ids_http233, $ids, 'download contains exact frozen IDs' );
beq( WriteLeash\Job_Repository::read_by_public_id( $public_id ), $job_http233, 'HTTP download creates no approval or job write' );
$bad_http233 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), array_merge( $forms233[0], array( '_wpnonce' => 'invalid' ) ) );
beq( $bad_http233['code'], 400, 'HTTP invalid nonce refused' );
bok( ! str_contains( $bad_http233['body'], 'product_name_at_preview' ), 'refusal emits no CSV prefix or private rows' );
$other_http233 = http_post( admin_url_abs( '/wp-admin/admin-post.php' ), array_merge( $forms233[0], array( 'job' => wp_generate_uuid4() ) ) );
beq( $other_http233['code'], 403, 'HTTP guessed ID denied' );
echo "#233 native no-JS HTTP Preview CSV: PASS\n";
