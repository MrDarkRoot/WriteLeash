<?php
/** #205 focused real Woo/DB recovery, using the existing disposable Admin matrix. */
use WriteLeash\Free_Admin as A;
use WriteLeash\Job_Repository as R;
use WriteLeash\Job_Worker as W;
use WriteLeash\Price_Decimal as D;
use WriteLeash\Price_Cache_Verifier as V;
use WriteLeash\Job_Schema as S;

global $wpdb;
$n = 0;
$eq = static function ( $a, $b, $label ) use ( &$n ) { ++$n; if ( $a !== $b ) { throw new RuntimeException( "$label: " . wp_json_encode( array( $a, $b ) ) ); } };
$create = static function ( $class = 'WC_Product_Simple', $parent = 0 ) {
 $p = new $class(); $p->set_name( 'Recovery ' . wp_generate_uuid4() ); $p->set_status( 'publish' );
 if ( $parent ) { $p->set_parent_id( $parent ); }
 $p->set_regular_price( '100' ); $p->save(); return $p->get_id();
};
$edit = static function ( $id, $price, $field = 'regular_price' ) { V::invalidate( $id ); $p = wc_get_product( $id ); $p->{'set_' . $field}( $price ); $p->save(); V::invalidate( $id ); };
$price = static function ( $id ) { V::invalidate( $id ); return D::parse( wc_get_product( $id )->get_regular_price( 'edit' ) ); };
$post = static function ( $ids, $source = null, $extra = array() ) {
 return array_merge( array( '_wpnonce' => wp_create_nonce( A::ACTION_PREVIEW ), 'selector' => 'ids', 'ids' => implode( ',', $ids ), 'product_ids' => array_map( 'strval', $ids ), 'source_job' => $source, 'operation' => 'INCREASE_PERCENT', 'amount' => '10', 'max_products' => '1000', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '20', 'block_zero' => '1' ), $extra );
};
$preview = static function ( $input ) use ( $eq ) { $r = A::process_preview( $input, 'POST' ); $eq( $r['status'], 'OK', 'preview' ); return R::read( $r['job_id'] ); };
$apply = static function ( $job ) use ( $eq ) {
 $r = A::process_approve( array( 'job' => $job['public_id'], '_wpnonce' => wp_create_nonce( A::ACTION_APPROVE . '_' . $job['plan_id'] ) ), 'POST' ); $eq( $r['status'], 'OK', 'explicit approval' );
 for ( $i = 0; $i < 110; ++$i ) { if ( WriteLeash\Job_State::is_terminal( R::read( (int) $job['id'] )['status'] ) ) { return; } W::run( (int) $job['id'], array( 'max_items' => 10, 'budget_seconds' => 20 ), true ); }
 throw new RuntimeException( 'Apply did not finish' );
};
$render = static function ( $view, $job ) { ob_start(); A::render_view( $view, $job['public_id'], 0 ); return ob_get_clean(); };
$saved = static function ( $job ) use ( $wpdb ) {
 $id = (int) $job['id']; $plan_id = $job['plan_id'];
 return wp_json_encode( array( R::read( $id ), R::items( $id ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE plan_id=%s ORDER BY product_id', WriteLeash\Price_Apply_Journal::table( $wpdb ), $plan_id ), ARRAY_A ) ) );
};
$actor = wp_insert_user( array( 'user_login' => 'recovery-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $actor );
$good = $create(); $conflict = $create(); $uncertain = $create();
$source = $preview( $post( array( $good, $conflict, $uncertain ) ) );
$edit( $conflict, '120' ); $edit( $uncertain, '140' ); $apply( $source );
// Explicit fixture for an uncertain sibling: no recovery of this historic status.
$wpdb->update( S::items_table( $wpdb ), array( 'state' => 'NEEDS_REVIEW', 'reason' => 'COMMIT_UNCERTAIN' ), array( 'job_id' => $source['id'], 'product_id' => $uncertain ) );
$original = $saved( $source );
$eq( array_keys( A::recovery_source( $source['public_id'] )['rows'] ), array( $conflict ), 'only Apply conflict' );
$html = $render( 'recovery', $source );
$eq( str_contains( $html, 'Original operation:' ), true, 'original operation shown' );
$eq( str_contains( $html, 'checked' ), true, 'original block-zero policy retained' );
$eq( str_contains( $html, 'value="' . $good . '"' ), false, 'applied sibling absent' );
$eq( str_contains( $html, 'name="product_ids[]" value="' . $conflict . '" checked' ), false, 'safe empty default' );
$eq( $saved( $source ), $original, 'opening/cancelling preserves durable source' );
$eq( $price( $conflict ), '120', 'opening never writes' );
foreach ( array( $good, $uncertain, 2147483647 ) as $bad ) { $eq( A::process_preview( $post( array( $bad ), $source['public_id'] ), 'POST' )['reason'], 'invalid_conflict_selection', 'forged/non-conflict ID' ); }
$eq( A::process_preview( $post( array(), $source['public_id'] ), 'POST' )['status'], 'INVALID', 'empty selection' );
$eq( A::process_preview( $post( array( $conflict ), 'forged' ), 'POST' )['status'], 'INVALID', 'forged source' );
$eq( A::process_preview( $post( array( $conflict ), $source['public_id'], array( '_wpnonce' => 'invalid' ) ), 'POST' )['reason'], 'invalid_nonce', 'nonce' );
$eq( A::process_preview( $post( array( $conflict ), $source['public_id'] ), 'GET' )['reason'], 'post_required', 'POST only' );
$other = wp_insert_user( array( 'user_login' => 'recovery-other-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $other );
$eq( A::process_preview( $post( array( $conflict ), $source['public_id'] ), 'POST' )['status'], 'FORBIDDEN', 'cross actor' );
$eq( str_contains( $render( 'recovery', $source ), 'Original operation:' ), false, 'no disclosure' );
wp_set_current_user( 0 ); $eq( A::process_preview( $post( array( $conflict ), $source['public_id'] ), 'POST' )['status'], 'FORBIDDEN', 'anonymous' );
wp_set_current_user( $actor );
$deny = static function ( $caps, $cap, $uid, $args ) use ( $conflict ) { return 'edit_post' === $cap && ( $args[0] ?? 0 ) === $conflict ? array( 'do_not_allow' ) : $caps; };
add_filter( 'map_meta_cap', $deny, 10, 4 );
$eq( A::process_preview( $post( array( $conflict ), $source['public_id'] ), 'POST' )['status'], 'INVALID', 'current product rights' );
remove_filter( 'map_meta_cap', $deny, 10 );
$user = wp_get_current_user(); $user->add_cap( 'manage_woocommerce', false );
$eq( A::process_preview( $post( array( $conflict ), $source['public_id'] ), 'POST' )['reason'], 'capability_required', 'capability revoked' ); $user->remove_cap( 'manage_woocommerce' );
// Source changed after opening: revalidate durable membership on submission.
$row = R::items( (int) $source['id'], 'CONFLICT' )[0];
$wpdb->update( S::items_table( $wpdb ), array( 'state' => 'NEEDS_REVIEW' ), array( 'id' => $row['id'] ) );
$eq( A::process_preview( $post( array( $conflict ), $source['public_id'] ), 'POST' )['reason'], 'invalid_conflict_selection', 'source race rejected' );
$wpdb->update( S::items_table( $wpdb ), array( 'state' => 'CONFLICT' ), array( 'id' => $row['id'] ) );
$new = $preview( $post( array( $conflict, $conflict ), $source['public_id'] ) ); $plan = R::hydrate_plan( $new );
$eq( $plan->data()['resolved_product_ids'], array( $conflict ), 'duplicate IDs deduplicated' );
$eq( $plan->item( $conflict )->data()['planned_regular_price'], '132.00', '120 + 10 percent = 132' );
$eq( $plan->data()['source_job'], $source['public_id'], 'hash-bound provenance' );
$eq( $new['plan_id'] !== $source['plan_id'] && $new['public_id'] !== $source['public_id'], true, 'distinct plan and job' );
$eq( $price( $conflict ), '120', 'preview has no price side effect' );
$eq( $saved( $source ), $original, 'preview preserves original durable records' );
$apply( $new ); $eq( $price( $conflict ), '132', 'approved fresh plan applies' );
$eq( $price( $good ), '110', 'applied sibling never replayed' ); $eq( $price( $uncertain ), '140', 'uncertain untouched' );
$eq( $saved( $source ), $original, 'new Apply preserves original durable records' );
$stale = $preview( $post( array( $conflict ), $source['public_id'] ) ); $edit( $conflict, '160' ); $apply( $stale );
$eq( R::items( (int) $stale['id'] )[0]['state'], 'CONFLICT', 'stale new preview conflicts' ); $eq( $price( $conflict ), '160', 'later edit preserved' );
$eq( A::process_preview( $post( array( $conflict ), $new['public_id'] ), 'POST' )['reason'], 'invalid_conflict_selection', 'other job membership refused' );
// Deleted and unsupported rows stay explained and cannot write.
$deleted = $create(); $unsupported = $create(); $missing_source = $preview( $post( array( $deleted, $unsupported ) ) );
$edit( $deleted, '120' ); $edit( $unsupported, '120' ); $apply( $missing_source ); wp_delete_post( $deleted, true ); wp_update_post( array( 'ID' => $unsupported, 'post_status' => 'draft' ) );
$eq( str_contains( $render( 'recovery', $missing_source ), 'This product no longer exists.' ), true, 'missing product explained during selection' );
$missing = $preview( $post( array( $deleted, $unsupported ), $missing_source['public_id'] ) ); $eq( R::hydrate_plan( $missing )->summary()['unsupported'], 2, 'missing/unsupported frozen explanations' );
// Core variation identity, parent lookup and Sale arithmetic.
$parent = $create( 'WC_Product_Variable' ); $child = $create( 'WC_Product_Variation', $parent ); $sibling = $create( 'WC_Product_Variation', $parent );
$vs = $preview( $post( array( $child, $sibling ) ) ); $edit( $child, '120' ); $apply( $vs );
$vn = $preview( $post( array( $child ), $vs['public_id'] ) ); $vp = R::hydrate_plan( $vn ); $eq( $vp->data()['resolved_product_ids'], array( $child ), 'variation only' ); $eq( $vp->item( $child )->data()['snapshot']['parent_id'], $parent, 'parent identity' ); $apply( $vn ); $eq( $price( $child ), '132', 'variation applies' );
$eq( $wpdb->get_var( $wpdb->prepare( 'SELECT max_price FROM %i WHERE product_id=%d', $wpdb->prefix . 'wc_product_meta_lookup', $child ) ), '132.0000', 'variation lookup' );
$sale = $create(); $edit( $sale, '60', 'sale_price' ); $ss = $preview( $post( array( $sale ), null, array( 'price_field' => 'sale_price' ) ) ); $edit( $sale, '70', 'sale_price' ); $apply( $ss );
$sn = $preview( $post( array( $sale ), $ss['public_id'], array( 'price_field' => 'sale_price' ) ) ); $eq( R::hydrate_plan( $sn )->item( $sale )->data()['planned_regular_price'], '77.00', 'sale percent current basis' ); $apply( $sn );
V::invalidate( $sale ); $eq( D::parse( wc_get_product( $sale )->get_sale_price( 'edit' ) ), '77', 'sale applied normally' );
// Undo conflicts never manufacture Apply conflict membership.
$undo = A::process_undo( array( 'job' => $new['public_id'], '_wpnonce' => wp_create_nonce( A::ACTION_UNDO . '_' . $new['public_id'] ) ), 'POST' );
$eq( $undo['status'], 'OK', 'normal Undo action' );
$eq( WriteLeash\Undo_Repository::history_job( (int) $new['id'] )['undo']['conflict'], 1, 'real Undo conflict' );
$eq( A::recovery_source( $new['public_id'] )['rows'], array(), 'Undo not an Apply conflict' );
// Limits: actual source-conflict population, synthetic terminal statuses only for this bound fixture.
$ids = array(); for ( $i = 0; $i < 1000; ++$i ) { $ids[] = $create(); }
$large = $preview( $post( $ids ) );
$wpdb->update( S::jobs_table( $wpdb ), array( 'approver_id' => $actor, 'status' => 'COMPLETED_WITH_ISSUES' ), array( 'id' => $large['id'] ) );
$wpdb->update( S::items_table( $wpdb ), array( 'state' => 'CONFLICT', 'reason' => 'PRICE_CHANGED' ), array( 'job_id' => $large['id'] ) );
$limit = $preview( $post( $ids, $large['public_id'] ) ); $eq( R::hydrate_plan( $limit )->summary()['selected'], 1000, '1000 accepted exact targets' );
$eq( A::process_preview( $post( array_merge( $ids, array( $conflict ) ), $large['public_id'] ), 'POST' )['status'], 'INVALID', '1001 refused' );
echo '#205 recovery integration: PASS (' . $n . " assertions; " . ( getenv( 'WL111_CACHE' ) ?: 'default' ) . ")\n";
