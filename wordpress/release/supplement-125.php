<?php
// Fresh supplement for the two first baselines; immutable rehashed material and target equality.
require __DIR__ . '/http-125.php';
use WriteLeash\Job_Repository as R;
use WriteLeash\Free_Admin as A;
$report=json_decode(file_get_contents(getenv('WL125_RESULT')),true);
$job=R::read((int)$report['operations']['SET']['job_id']);$data=R::hydrate_plan($job)->data();
$cases=array();
foreach(array('selector','operation','amount','policy','population','target') as $change){
 $bad=$data;
 if($change==='selector'){$bad['selection']['type']='CATEGORY';}
 if($change==='operation'){$bad['operation']['type']='INCREASE_FIXED';}
 if($change==='amount'){$bad['operation']['input']='999';}
 if($change==='policy'){$bad['policy_snapshot']['max_products_changed']=0;}
 if($change==='population'){$bad['resolved_product_ids'][]=999999999;}
 if($change==='target'){$bad['items'][0]['planned_regular_price']='999';}
 $material=$bad;unset($material['plan_id'],$material['created_at'],$material['plan_hash'],$material['summary']);$bad['plan_hash']=WriteLeash\Plan_Hasher::hash($material);
 $rejected=false;try{R::assert_binding($job,WriteLeash\Change_Plan::hydrate($bad));}catch(Throwable $e){$rejected=true;}
 wl112_assert($rejected,'rehashed changed approval material accepted');$cases[$change]='REFUSED';
}
$wl112_cookies=array();wp_set_current_user(1);wl112_login();
$p=new WC_Product_Simple();$p->set_name('WL125-synthetic-target-equality-supplement');$p->set_status('publish');$p->set_regular_price('18.00');$id=$p->save();
$base=getenv('WL125_URL').'/wp-admin/admin.php?page=writeleash-bulk-prices';
$form=array_merge(wl112_form(wl112_get($base)['body'],A::ACTION_PREVIEW),array('selector'=>'ids','ids'=>(string)$id,'operation'=>'DECREASE_PERCENT','amount'=>'20','max_products'=>'100','max_increase'=>'100','max_decrease'=>'100','warning_threshold'=>'10'));
$r=wl112_post($form);wl112_assert((bool)preg_match('/wl_job=([0-9a-f-]{36})/',$r['location'],$m),'supplement preview');$f=R::read_by_public_id($m[1]);$preview=wl112_get($r['location']);$r=wl112_post(wl112_form($preview['body'],A::ACTION_APPROVE));$progress=wl112_get($r['location']);
WriteLeash\Price_Cache_Verifier::invalidate($id);$p=wc_get_product($id);$p->set_regular_price('14.40');$p->save();
$start=count(file(getenv('WL112_METRICS')));wl112_post(wl112_form($progress['body'],A::ACTION_RESUME));$f=R::read((int)$f['id']);$journal=WriteLeash\Price_Apply_Journal::read($GLOBALS['wpdb'],$f['plan_id'],$id);
$saves=array();foreach(array_slice(file(getenv('WL112_METRICS')),$start)as$line){$saves=array_merge($saves,json_decode($line,true)['woo_save_product_ids']);}
wl112_assert((int)$f['applied']===0 && (int)$f['conflict']===1 && $journal['state']==='CONFLICT' && $saves===array(),'KILL: target equality fabricated APPLIED');
$report['checks']['TARGET_EQUALITY_WITHOUT_JOURNAL_PROVEN_APPLY_IS_CONFLICT']='PASS';
$report['supplement']=array('rehashed_material'=>$cases,'target_equality'=>array('classification'=>'CONFLICT','validation'=>'PASS','job_id'=>(int)$f['id'],'applied'=>(int)$f['applied'],'conflict'=>(int)$f['conflict'],'journal_state'=>$journal['state'],'woo_save_count'=>count($saves)));
if($report['interruption']['before']['applied']===12){$report['interruption']['original_before_field_was_after_recovery']=$report['interruption']['before'];$report['interruption']['before']=array('applied'=>10,'pending'=>2);$report['interruption']['reporter_correction']='Original writer re-read counts after recovery. Before values above are the exact passed pre-kill 10 applied/2 pending assertions; original mistaken field retained separately.';}
$clean_id=(int)array_keys($report['partial']['woo_saves'])[0];global $wpdb;
$partial_job_id=(int)$wpdb->get_var($wpdb->prepare('SELECT job_id FROM %i WHERE product_id=%d LIMIT 1',$wpdb->prefix.'writeleash_job_items',$clean_id));
$partial_job=R::read($partial_job_id);
$conflicts=$wpdb->get_col($wpdb->prepare('SELECT product_id FROM %i WHERE job_id=%d AND state=%s ORDER BY product_id',$wpdb->prefix.'writeleash_job_items',$partial_job_id,'CONFLICT'));
$stale_id=0;foreach($conflicts as $candidate){$product=wc_get_product((int)$candidate);if(WriteLeash\Price_Decimal::parse($product->get_regular_price('edit'))==='21'){$stale_id=(int)$candidate;break;}}
wl112_assert($stale_id>0,'partial stale fixture identity');
$page=wl112_get($base.'&wl_view=job&wl_job='.$partial_job['public_id']);$dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML($page['body']);libxml_clear_errors();$xp=new DOMXPath($dom);$cells=$xp->query('//table/tbody/tr[td[1]="'.$stale_id.'"]/td');
wl112_assert($cells->length>=4 && trim($cells->item(1)->textContent)==='18' && trim($cells->item(2)->textContent)==='21' && trim($cells->item(3)->textContent)==='14.40','KILL: Expected/Current/Planned UI mismatch');
$report['supplement']['partial_ui']=array('classification'=>'PASS','expected'=>'18','current'=>'21','planned'=>'14.40');
file_put_contents(getenv('WL125_RESULT'),json_encode($report,JSON_PRETTY_PRINT)."\n");
echo "Rehashed changed material rejected; external target equality CONFLICT with zero APPLIED/save PASS\n";
