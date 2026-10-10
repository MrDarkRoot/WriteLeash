<?php
// Native authenticated HTTP; independent final membership on every selected profile.
// Keep the existing main Apply/Undo journey and every original assertion intact.
$root231 = $job; $root_json231 = $plan->json();
$start231 = is_file( getenv( 'WL112_METRICS' ) ) ? count( file( getenv( 'WL112_METRICS' ) ) ) : 0;
$selection231 = wl112_get( $base . '&wl_view=refine&wl_job=' . $root231['public_id'] );
$request231 = wl112_form( $selection231['body'], WriteLeash\Free_Admin::ACTION_REFINE );
unset( $request231['rows[]'] );
$excluded231 = array( (int) $ids[0], (int) $ids[count( $ids ) - 1] );
$request231['rows'] = array_map( 'strval', $excluded231 ); $request231['refinement_action'] = 'exclude';
$response231 = wl112_post( $request231 );
wl112_assert( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $response231['location'], $id231 ), '#231 final Preview not created over native HTTP' );
wl112_assert( $id231[1] !== $root231['public_id'], '#231 did not create a new immutable review' );
$review231 = Repo::read_by_public_id( $id231[1] ); $final231 = Repo::hydrate_plan( $review231 );
$included231 = array_values( array_diff( $ids, $excluded231 ) );
wl112_assert( $final231->data()['resolved_product_ids'] === $included231, '#231 exact server-verified included IDs' );
wl112_assert( $final231->data()['selection_refinement']['excluded_ids'] === $excluded231 && count( $included231 ) === $size - 2, '#231 honest excluded/included counts' );
wl112_assert( 0 === (int) $review231['approver_id'] && 'PLANNED' === $review231['status'], '#231 exclusions did not approve or execute' );
wl112_assert( Repo::hydrate_plan( Repo::read( (int) $root231['id'] ) )->json() === $root_json231, '#231 old Preview bytes unchanged' );
$reload231 = wl112_get( $response231['location'] . '&wl_offset=20' );
wl112_assert( false !== strpos( $reload231['body'], 'Included targets ' . count( $included231 ) ) && false !== strpos( $reload231['body'], 'Excluded targets 2' ), '#231 membership survives pagination/reload' );
$last231 = wl112_get( $base . '&wl_view=preview&wl_job=' . $review231['public_id'] );
wl112_assert( wl112_rows( $last231['body'] ) === array_slice( $included231, 0, 20 ), '#231 final Preview rows exactly match Plan' );
wl112_assert( false !== strpos( $last231['body'], 'Approve and apply' ), '#231 final review requires explicit approval' );
wl112_assert( array() === wl112_save_counts( $start231 ), '#231 selection refinement issued Woo price saves' );
global $wpdb;
foreach ( $excluded231 as $excluded_id231 ) {
    wl112_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE plan_id=%s AND product_id=%d', WriteLeash\Price_Apply_Journal::table( $wpdb ), $review231['plan_id'], $excluded_id231 ) ), '#231 excluded target has no journal' );
}
$facts['exclusions'] = array( 'source_plan' => $root231['plan_id'], 'final_plan' => $review231['plan_id'], 'resolved' => $size, 'included' => count( $included231 ), 'excluded' => 2, 'woo_saves' => 0, 'approval' => 'SEPARATE_EXPLICIT_REVIEW_REQUIRED', 'membership' => 'PASS: exact immutable included IDs via native HTTP and paginated reload' );
echo '#231 ' . DB_HOST . ' ' . $size . ': native exclusion confirmation and final Preview PASS' . "\n";
