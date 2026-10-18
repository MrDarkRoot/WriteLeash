<?php
$ids=json_decode(file_get_contents('/tmp/writeleash-188-runtime/fixture-ids.json'),true);
$checks=[]; $mismatches=[];
$check=function($sku,$regular,$sale=null) use ($ids,&$checks,&$mismatches) {
$p=wc_get_product($ids[$sku]);
$actual=['regular'=>$p ? $p->get_regular_price('edit') : null,'sale'=>$p ? $p->get_sale_price('edit') : null];
$ok=$p && is_numeric($actual['regular']) && number_format((float)$actual['regular'],2,'.','')===number_format((float)$regular,2,'.','');
if ($sale!==null) $ok=$ok && is_numeric($actual['sale']) && number_format((float)$actual['sale'],2,'.','')===number_format((float)$sale,2,'.','');
$entry=['sku'=>$sku,'expected_regular'=>$regular,'expected_sale'=>$sale,'actual'=>$actual,'pass'=>$ok]; $checks[]=$entry; if (!$ok) $mismatches[]=$entry;
};
for ($i=1;$i<=1000;$i++) $check('AGENCY-'.sprintf('%04d',$i),$i===1 ? 150 : 100+($i%10));
for ($i=1;$i<=12;$i++) $check('ACC-'.sprintf('%03d',$i),19+$i);
$check('SALE-001',80,60); $check('SALE-002',100,75); $check('SALE-003',40,30);
$check('TEE-SMALL',30,24); $check('TEE-LARGE',35,28);
$check('MIX-CLEAN',44); $check('MIX-EDIT',52,40);
$bad=get_post_meta($ids['MIX-BAD'],'_regular_price',true);
$result=['phase'=>'After all first-pass customer UI flows finished; fresh WooCommerce API reads, no WriteLeash source/schema queries','checked_clean_targets'=>count($checks),'agency_targets'=>1000,'mismatches'=>$mismatches,'malformed_price_preserved'=>$bad==='not-a-price','malformed_price'=>$bad,'checks'=>$checks];
file_put_contents('/tmp/writeleash-188-runtime/evidence/final-price-verification.json',wp_json_encode($result,JSON_PRETTY_PRINT));
echo wp_json_encode(['checked_clean_targets'=>count($checks),'agency_targets'=>1000,'mismatch_count'=>count($mismatches),'malformed_price_preserved'=>$bad==='not-a-price']).PHP_EOL;
