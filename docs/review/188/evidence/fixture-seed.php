<?php
$cats = [];
foreach (['Everyday accessories','Summer sale','Mixed catalog','Agency batch'] as $name) { $r=wp_insert_term($name,'product_cat'); $cats[$name]=is_wp_error($r) ? $r->get_error_data() : $r['term_id']; }
$ids=[];
$create=function($name,$sku,$regular,$sale,$category) use (&$ids,$cats) {
$p=new WC_Product_Simple(); $p->set_name($name); $p->set_status('publish'); $p->set_sku($sku); $p->set_regular_price((string)$regular); if ($sale!==null) $p->set_sale_price((string)$sale); $p->set_category_ids([$cats[$category]]); $p->save(); $ids[$sku]=$p->get_id(); return $p;
};
foreach (['Canvas tote','Travel pouch','Cotton cap','Water bottle','Key ring','Wool scarf','Leather belt','Canvas wallet','Phone sleeve','Notebook','Umbrella','Lunch bag'] as $i=>$name) $create($name,'ACC-'.sprintf('%03d',$i+1),20+$i,null,'Everyday accessories');
$create('Summer sandals','SALE-001',80,60,'Summer sale');
$create('Linen shorts','SALE-002',100,75,'Summer sale');
$create('Beach towel','SALE-003',40,30,'Summer sale');
$create('Clean ceramic mug','MIX-CLEAN',25,null,'Mixed catalog');
$create('External edit control','MIX-EDIT',50,40,'Mixed catalog');
$bad=$create('Imported damaged price','MIX-BAD',30,null,'Mixed catalog'); update_post_meta($bad->get_id(),'_regular_price','not-a-price');
$parent=new WC_Product_Variable(); $parent->set_name('Cotton tee'); $parent->set_status('publish'); $parent->set_sku('TEE'); $parent->set_category_ids([$cats['Mixed catalog']]);
$a=new WC_Product_Attribute(); $a->set_name('Size'); $a->set_options(['Small','Large']); $a->set_visible(true); $a->set_variation(true); $parent->set_attributes([$a]); $parent->save();
foreach (['Small','Large'] as $i=>$size) { $v=new WC_Product_Variation(); $v->set_parent_id($parent->get_id()); $v->set_status('publish'); $v->set_attributes(['size'=>$size]); $v->set_sku('TEE-'.strtoupper($size)); $v->set_regular_price((string)(30+$i*5)); $v->set_sale_price((string)(24+$i*4)); $v->save(); $ids[$v->get_sku()]=$v->get_id(); } WC_Product_Variable::sync($parent->get_id());
for ($i=1;$i<=1000;$i++) $create('Agency item '.sprintf('%04d',$i),'AGENCY-'.sprintf('%04d',$i),100+($i%10),null,'Agency batch');
file_put_contents('/tmp/writeleash-188-runtime/fixture-ids.json',wp_json_encode($ids,JSON_PRETTY_PRINT));
echo 'Seeded '.count($ids).' simple products and variations'.PHP_EOL;
