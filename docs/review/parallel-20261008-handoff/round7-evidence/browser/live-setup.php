<?php
wp_set_current_user(1);
$ids=[];
for($i=0;$i<22;$i++) { $p=new WC_Product_Simple(); $p->set_name('Round7 live progress '. $i); $p->set_status('publish'); $p->set_regular_price('100.00'); $p->save(); $ids[]=$p->get_id(); }
update_option('wl170_ids',$ids,false); update_option('wl170_saves',[],false);
$plan=WriteLeash\Woo_Price_Planner::preview(WriteLeash\Price_Selection_Spec::ids($ids), new WriteLeash\Price_Operation(WriteLeash\Price_Operation::SET,'80'), new WriteLeash\Safety_Policy(1000,'100','100',true,'100'));
$job=WriteLeash\Job_Repository::create_from_plan($plan,1);
WriteLeash\Job_Repository::approve((int)$job['id'],1);
echo wp_json_encode(['job_id'=>(int)$job['id'],'public_id'=>$job['public_id'],'ids'=>$ids]),"\n";
