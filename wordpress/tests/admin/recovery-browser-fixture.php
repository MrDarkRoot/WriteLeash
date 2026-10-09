<?php
use WriteLeash\Free_Admin as A;
use WriteLeash\Job_Repository as R;
use WriteLeash\Job_Worker as W;
use WriteLeash\Price_Cache_Verifier as V;
$mode = getenv( 'WL205_MODE' ) ?: 'seed';
$file = getenv( 'WL205_FIXTURE' );
if ( ! $file ) { throw new RuntimeException( 'Explicit disposable recovery fixture required' ); }
global $wpdb;
if ( 'seed' === $mode ) {
 $username = 'recovery-browser-' . wp_generate_uuid4(); $password = wp_generate_password( 24, false );
 $actor = wp_insert_user( array( 'user_login' => $username, 'user_pass' => $password, 'role' => 'shop_manager' ) ); wp_set_current_user( $actor );
 $p = new WC_Product_Simple(); $p->set_name( 'Recovery browser product' ); $p->set_status( 'publish' ); $p->set_regular_price( '100' ); $p->save(); $id = $p->get_id();
 $r = A::process_preview( array( '_wpnonce' => wp_create_nonce( A::ACTION_PREVIEW ), 'selector' => 'ids', 'ids' => (string) $id, 'operation' => 'INCREASE_PERCENT', 'amount' => '10', 'max_products' => '1000', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '20', 'block_zero' => '1' ), 'POST' );
 if ( 'OK' !== $r['status'] ) { throw new RuntimeException( wp_json_encode( $r ) ); }
 $job = R::read( $r['job_id'] ); $p->set_regular_price( '120' ); $p->save();
 $a = A::process_approve( array( 'job' => $job['public_id'], '_wpnonce' => wp_create_nonce( A::ACTION_APPROVE . '_' . $job['plan_id'] ) ), 'POST' );
 if ( 'OK' !== $a['status'] ) { throw new RuntimeException( wp_json_encode( $a ) ); }
 W::run( (int) $job['id'], array( 'max_items' => 10, 'budget_seconds' => 20 ), true );
 if ( 'CONFLICT' !== R::items( (int) $job['id'] )[0]['state'] ) { throw new RuntimeException( 'Seed requires real conflict' ); }
 $f = array( 'username' => $username, 'password' => $password, 'actor' => $actor, 'id' => $id, 'job' => $job['public_id'], 'job_id' => $job['id'], 'plan_id' => $job['plan_id'] );
 file_put_contents( $file, wp_json_encode( $f ) ); chmod( $file, 0600 ); echo "#205 browser fixture seeded\n"; return;
}
$f = json_decode( file_get_contents( $file ), true ); wp_set_current_user( $f['actor'] );
if ( 'run' === $mode ) {
 foreach ( WriteLeash\Undo_Repository::history_jobs( 0, 20, $f['actor'] )['jobs'] as $entry ) {
  $j = R::read( (int) $entry['job_id'] ); if ( (int) $j['id'] !== (int) $f['job_id'] && WriteLeash\Job_State::can_manual_run( $j['status'] ) ) { W::run( (int) $j['id'], array( 'max_items' => 10, 'budget_seconds' => 20 ), true ); }
 }
}
V::invalidate( $f['id'] ); $product = wc_get_product( $f['id'] );
$jobs = $wpdb->get_results( $wpdb->prepare( 'SELECT id,public_id,plan_id,status,plan_json FROM %i WHERE creator_id=%d ORDER BY id', WriteLeash\Job_Schema::jobs_table( $wpdb ), $f['actor'] ), ARRAY_A );
echo wp_json_encode( array( 'price' => WriteLeash\Price_Decimal::parse( $product->get_regular_price( 'edit' ) ), 'source' => array( R::read( (int) $f['job_id'] ), R::items( (int) $f['job_id'] ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE plan_id=%s ORDER BY product_id', WriteLeash\Price_Apply_Journal::table( $wpdb ), $f['plan_id'] ), ARRAY_A ) ), 'jobs' => $jobs ) );
