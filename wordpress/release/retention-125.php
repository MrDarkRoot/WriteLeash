<?php
// Public-origin durable states; ONLY timestamps are aged for the retention clock fixture.
require __DIR__ . '/http-125.php';
use WriteLeash\Free_Admin as A;
use WriteLeash\Job_Repository as R;
use WriteLeash\Undo_Repository as U;
$wl112_cookies = array(); wp_set_current_user( 1 ); wl112_login();
function rp125_job( string $name, bool $approve, bool $down = false ): array {
    $p = new WC_Product_Simple(); $p->set_name( 'WL125-synthetic-retention-' . $name ); $p->set_status( 'publish' ); $p->set_regular_price( '18.00' ); $id = $p->save();
    $base = getenv( 'WL125_URL' ) . '/wp-admin/admin.php?page=writeleash-bulk-prices';
    $form = array_merge( wl112_form( wl112_get( $base )['body'], A::ACTION_PREVIEW ), array( 'selector'=>'ids','ids'=>(string)$id,'operation'=>'SET','amount'=>'14.40','max_products'=>'100','max_increase'=>'100','max_decrease'=>'100','warning_threshold'=>'10','block_zero'=>'1' ) );
    $r = wl112_post( $form ); wl112_assert( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $r['location'], $m ), 'retention preview' ); $page = wl112_get( $r['location'] );
    if ( $approve ) { if ( $down ) { update_option( 'wl112_scheduler_down', true, false ); } $r = wl112_post( wl112_form( $page['body'], A::ACTION_APPROVE ) ); delete_option( 'wl112_scheduler_down' ); $page = wl112_get( $r['location'] ); }
    return array( 'job'=>R::read_by_public_id($m[1]),'product_id'=>$id,'page'=>$page,'url'=>$r['location'] );
}
$protected = array( 'active'=>rp125_job('active',true), 'paused'=>rp125_job('paused',true,true), 'incomplete'=>rp125_job('incomplete',false) );
$review = rp125_job('needs-review',true); update_option( 'wl125_review_fault', $review['product_id'], false ); wl112_post( wl112_form( $review['page']['body'], A::ACTION_RESUME ) ); delete_option( 'wl125_review_fault' ); $review['job'] = R::read((int)$review['job']['id']);
wl112_assert( (int)$review['job']['needs_review'] === 1 && (int)$review['job']['applied'] === 0, 'KILL: hostile commit false APPLIED or review truth' );
$protected['needs_review'] = $review;
$eligible = rp125_job('eligible-apply',true); wl112_post( wl112_form( $eligible['page']['body'], A::ACTION_RESUME ) );
wl112_assert( U::history_job((int)$eligible['job']['id'])['undo_eligible'], 'fresh Apply Undo eligibility' );
$protected['eligible_apply'] = $eligible;
$eligible_undo = rp125_job('eligible-undo',true); wl112_post( wl112_form( $eligible_undo['page']['body'], A::ACTION_RESUME ) );
$page = wl112_get($eligible_undo['url']); wl112_post( wl112_form($page['body'],A::ACTION_UNDO)); $protected['undo_evidence']=$eligible_undo;
$expired = rp125_job('expired-terminal',true); wl112_post( wl112_form($expired['page']['body'],A::ACTION_RESUME));
$page = wl112_get($expired['url']); wl112_post(wl112_form($page['body'],A::ACTION_UNDO));
global $wpdb;
// Timestamp-only clock fixture. No status, actor, plan, target, lease, generation or price edits.
$aged = array($protected['active'],$protected['paused'],$protected['incomplete'],$protected['needs_review'],$expired);
foreach($aged as $f) {
    $wpdb->query($wpdb->prepare('UPDATE %i SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY),updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY),completed_at=IF(completed_at IS NULL,NULL,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY)) WHERE id=%d',$wpdb->prefix.'writeleash_jobs',(int)$f['job']['id']));
    $wpdb->query($wpdb->prepare('UPDATE %i SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY),updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY),completed_at=IF(completed_at IS NULL,NULL,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY)) WHERE job_id=%d',$wpdb->prefix.'writeleash_undo_operations',(int)$f['job']['id']));
}
$before = array(); foreach($protected as $name=>$f){$before[$name]=array('job'=>R::read((int)$f['job']['id']),'history'=>U::history_job((int)$f['job']['id']));}
$purged = U::purge_expired(100);
wl112_assert(1===$purged['jobs'] && 0===$purged['purge_failed'] && null===R::read((int)$expired['job']['id']),'retention eligible terminal purge');
foreach($protected as $name=>$f){wl112_assert($before[$name]['job']===R::read((int)$f['job']['id']),'retention destroyed protected '.$name);}
wl112_assert(U::history_job((int)$eligible['job']['id'])['undo_eligible'],'retention destroyed unexpired eligible Undo');
// Intercepted effects happen on Apply and Undo, and remain outside restoration promises.
$effects = rp125_job('external-effects',true); update_option('wl125_effect_product',$effects['product_id'],false); update_option('wl125_effect_attempts',0,false);
wl112_post(wl112_form($effects['page']['body'],A::ACTION_RESUME)); $page=wl112_get($effects['url']); wl112_post(wl112_form($page['body'],A::ACTION_UNDO));
$attempts=(int)$wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name=%s',$wpdb->options,'wl125_effect_attempts')); delete_option('wl125_effect_product'); wl112_assert($attempts===2,'intercepted Apply/Undo external attempt control');
$out=array('classification'=>'PASS','protected'=>array_map(static function($entry){return array('id'=>(int)$entry['job']['id'],'status'=>$entry['job']['status'],'applied'=>(int)$entry['job']['applied'],'pending'=>(int)$entry['job']['pending'],'needs_review'=>(int)$entry['job']['needs_review']);},$before),'purged'=>$purged,'clock_fixture'=>'40-day timestamp-only aging of synthetic plugin evidence; no authority/material/price fields edited','external_effects'=>'OUTSIDE_CONTRACT','intercepted_apply_undo_effect_attempts'=>$attempts);
file_put_contents('/evidence/'.getenv('WL125_LABEL').'-retention.json',json_encode($out,JSON_PRETTY_PRINT)."\n");
echo "Retention active/paused/incomplete/needs-review/unexpired Apply/Undo protected; only expired terminal purged; external effects OUTSIDE_CONTRACT PASS\n";
