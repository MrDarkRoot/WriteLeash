<?php
// Candidate: Fluent Forms 5.2.9 - Entries page bulk action "Mark as Read" for
// selected entries, executed through the plugin's SubmissionService, which is
// exactly what SubmissionController@handleBulkActions invokes from the REST
// route POST /fluentform/v1/submissions/bulk-actions.
require_once __DIR__ . '/helpers.php';

global $wpdb;
$forms_table = $wpdb->prefix . 'fluentform_forms';
$subs_table  = $wpdb->prefix . 'fluentform_submissions';

$form_id = wpFluent()->table( 'fluentform_forms' )->insert( array(
	'title'       => 'CC Research Form',
	'status'      => 'published',
	'form_fields' => wp_json_encode( array( array( 'element' => 'input_text', 'attributes' => array( 'name' => 'cc_name', 'type' => 'text' ) ) ) ),
	'has_payment' => 0,
	'created_by'  => 1,
	'created_at'  => current_time( 'mysql' ),
	'updated_at'  => current_time( 'mysql' ),
) );
if ( ! $form_id ) {
	echo "SEED FAILURE: form insert failed\n";
	return;
}
for ( $i = 0; $i < 6; ++$i ) {
	wpFluent()->table( 'fluentform_submissions' )->insert( array(
		'form_id'    => $form_id,
		'status'     => 'unread',
		'response'   => wp_json_encode( array( 'cc_name' => 'entry-' . $i ) ),
		'source_url' => 'http://research.test/',
		'user_id'    => 0,
		'created_at' => current_time( 'mysql' ),
		'updated_at' => current_time( 'mysql' ),
	) );
}
$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $subs_table . ' WHERE form_id=%d ORDER BY id', $form_id ) ) );
$unread = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $subs_table . " WHERE form_id=%d AND status='unread'", $form_id ) );
echo "SEED: form_id=$form_id entries=" . count( $ids ) . " unread=$unread\n";

$service = new \FluentForm\App\Services\Submission\SubmissionService();
ccr_trace_start( 'fluentform_bulk_mark_read' );
$message = $service->handleBulkActions( array(
	'form_id'     => $form_id,
	'entries'     => $ids,
	'action_type' => 'read',
) );
echo 'SERVICE: ' . $message . "\n";
$read = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $subs_table . " WHERE form_id=%d AND status='read'", $form_id ) );
ccr_trace_stop();
ccr_table_verbs( $GLOBALS['ccr_root'], 'fluentform_submissions' );
echo "RESULT: read=$read\n";
