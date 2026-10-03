<?php
require __DIR__ . '/http-125.php';
$wl112_cookies = array(); wp_set_current_user( 1 ); wl112_login();
$base = getenv( 'WL125_URL' ) . '/wp-admin/admin.php?page=writeleash-bulk-prices';
$p = new WC_Product_Simple(); $p->set_name( 'WL125-synthetic-lifecycle-paused' ); $p->set_status( 'publish' ); $p->set_regular_price( '18.00' ); $id = $p->save();
$form = array_merge( wl112_form( wl112_get( $base )['body'], WriteLeash\Free_Admin::ACTION_PREVIEW ), array( 'selector'=>'ids','ids'=>(string)$id,'operation'=>'SET','amount'=>'14.40','max_products'=>'100','max_increase'=>'100','max_decrease'=>'100','warning_threshold'=>'10' ) );
$r = wl112_post( $form ); wl112_assert( (bool) preg_match( '/wl_job=([0-9a-f-]{36})/', $r['location'], $m ), 'lifecycle paused preview' );
$page = wl112_get( $r['location'] ); update_option( 'wl112_scheduler_down', true, false ); wl112_post( wl112_form( $page['body'], WriteLeash\Free_Admin::ACTION_APPROVE ) ); delete_option( 'wl112_scheduler_down' );
$job = WriteLeash\Job_Repository::read_by_public_id( $m[1] ); wl112_assert( 'PAUSED' === $job['status'], 'real scheduler-refusal paused lifecycle job' );
$actions = array( 'unrelated'=>as_schedule_single_action( time()+3600, 'wl125_unrelated', array(), 'wl125-unrelated' ), 'owned'=>as_schedule_single_action( time()+3600, WriteLeash\Job_Scheduler::HOOK, array((int)$job['id']), WriteLeash\Job_Scheduler::GROUP ) );
wl112_assert( $actions['unrelated'] > 0 && $actions['owned'] > 0, 'lifecycle schedules' );
update_option( 'wl125_lifecycle', array('job_id'=>(int)$job['id'],'product_id'=>$id,'actions'=>$actions), false );
echo json_encode(array('job_id'=>(int)$job['id'],'product_id'=>$id,'status'=>$job['status']));
