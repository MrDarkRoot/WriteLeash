<?php
// Fresh process with actual Woo package inactive or replaced; no WriteLeash source substitution.
require __DIR__ . '/http-125.php';
$wl112_cookies=array(); wp_set_current_user(1); wl112_login();
$before=wl112_evidence_rows();
$base=getenv('WL125_URL').'/wp-admin/admin.php?page=writeleash-bulk-prices';
$page=wl112_get($base);
wl112_assert(false===strpos($page['body'],'name="action" value="writeleash_free_preview"'),'dependency loss exposes preview');
$reason=WriteLeash\Free_Support_Contract::woocommerce_reason();
wl112_assert(in_array($reason,array('woocommerce_unavailable','woocommerce_version_unsupported'),true),'dependency refusal reason missing');
$response=wl112_post(array('action'=>WriteLeash\Free_Admin::ACTION_PREVIEW,'_wpnonce'=>wl112_nonce(WriteLeash\Free_Admin::ACTION_PREVIEW),'selector'=>'ids','ids'=>'1','operation'=>'SET','amount'=>'1','max_products'=>'100','max_increase'=>'100','max_decrease'=>'100','warning_threshold'=>'10'));
wl112_assert(false===strpos($response['location'],'wl_job=') && $before===wl112_evidence_rows(),'dependency refusal creates durable work');
echo json_encode(array('classification'=>'REFUSED','validation'=>'PASS','reason'=>$reason,'durable_rows_before'=>$before,'durable_rows_after'=>wl112_evidence_rows()));
