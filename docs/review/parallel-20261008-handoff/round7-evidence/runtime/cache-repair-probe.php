<?php
use WriteLeash\Price_Cache_Verifier as C;
use WriteLeash\Price_Apply_Journal as J;
use WriteLeash\Woo_Price_Mutator as M;
use WriteLeash\Woo_Price_Planner as P;
use WriteLeash\Price_Selection_Spec as S;
use WriteLeash\Price_Operation as O;
use WriteLeash\Safety_Policy as Policy;
wp_set_current_user(1);J::install();$mode=getenv('ROUND7_MODE')?:'transient';
$p=new WC_Product_Simple();$p->set_name('ROUND7-'.wp_generate_uuid4());$p->set_status('publish');$p->set_regular_price('100.00');$p->save();$id=$p->get_id();
$plan=P::preview(S::ids([$id]),new O(O::SET,'80.00'),new Policy(2,'100','100',true,'100'));J::seed($plan);$applied=M::apply($plan,$id);
if($applied['code']!=='APPLIED')throw new RuntimeException('APPLY_FIXTURE_FAILED');
$saves=0;$served=0;$reads=0;$class_calls=0;$fresh_resolutions=0;$active=true;$prices=[];
$save=function($product)use($id,&$saves){if($product instanceof WC_Product&&$product->get_id()===$id)++$saves;};
$filter=function($value,$object_id,$meta_key,$single)use($id,&$served,&$active){if($active&&(int)$object_id===$id&&$meta_key===''&&$single===false){++$served;return['_regular_price'=>['100.00'],'_price'=>['100.00'],'_sale_price'=>['']];}return$value;};
$read=function($pid,$product=null)use($id,&$reads,&$active,&$prices,$mode){if((int)$pid===$id){++$reads;$prices[]=$product instanceof WC_Product?$product->get_regular_price('edit'):null;if($mode==='transient'&&$reads>=2)$active=false;}};
$class=function($class,$type,$context,$pid)use($id,&$class_calls,&$fresh_resolutions){if((int)$pid===$id){++$class_calls;$trace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);foreach($trace as $i=>$frame){if(($frame['class']??'')==='WriteLeash\\Product_Price_Snapshot'&&($frame['function']??'')==='fresh_product'&&($trace[$i-1]['function']??'')==='get_product_classname')++$fresh_resolutions;}}return$class;};
add_action('woocommerce_before_product_object_save',$save,10,1);add_filter('get_post_metadata',$filter,10,4);add_action('woocommerce_product_read',$read,10,2);add_filter('woocommerce_product_class',$class,10,4);
$outcome=null;$exception=null;
try{$observed=C::observe($plan,$id);$outcome=$observed['journal']['state'];}catch(Throwable$e){$exception=$e->getMessage();}
finally{remove_action('woocommerce_before_product_object_save',$save,10);remove_filter('get_post_metadata',$filter,10);remove_action('woocommerce_product_read',$read,10);remove_filter('woocommerce_product_class',$class,10);}
$db=C::observer();try{$storage=C::storage($db,$id);$journal=J::read($db,$plan->data()['plan_id'],$id);}finally{$db->close();}
$receipt=['mode'=>$mode,'source_label'=>getenv('ROUND7_SOURCE_LABEL'),'wp'=>$GLOBALS['wp_version'],'woo'=>WC_VERSION,'database_host'=>DB_HOST,'database'=>$GLOBALS['wpdb']->get_var('SELECT VERSION()'),'ext_cache'=>wp_using_ext_object_cache(),'apply'=>$applied['code'],'observe'=>$outcome,'exception'=>$exception,'stale_blobs_served'=>$served,'public_class_resolutions'=>$class_calls,'direct_fresh_class_resolutions'=>$fresh_resolutions,'product_read_events'=>$reads,'observed_read_prices'=>$prices,'additional_woo_saves'=>$saves,'raw_regular'=>$storage['meta']['_regular_price'][0],'raw_active'=>$storage['meta']['_price'][0],'journal_state'=>$journal['state']];
$receipt['assertions']=['stale_path_entered'=>$served>0,'exactly_one_reconstruction'=>$fresh_resolutions===2,'zero_additional_saves'=>$saves===0,'durable_commit_preserved'=>$journal['state']==='APPLIED'&&WriteLeash\Price_Decimal::parse($storage['meta']['_regular_price'][0])==='80','expected_observation'=>$mode==='persistent'?$exception==='CACHE_VERIFICATION_FAILED':$outcome==='APPLIED'];
echo 'ROUND7_CACHE_REPAIR '.wp_json_encode($receipt)."\n";
wp_delete_post($id,true);
if(in_array(false,$receipt['assertions'],true))throw new RuntimeException('ROUND7_DISCRIMINATING_REGRESSION_FAILED');
