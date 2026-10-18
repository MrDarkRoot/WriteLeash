<?php
global $wpdb;
$manifest='/opt/release/writeleash-distribution-files.txt';$source='/opt/writeleash';$installed=WP_PLUGIN_DIR.'/writeleash';
$files=[];$bad=[];
foreach(file($manifest,FILE_IGNORE_NEW_LINES) as $p){if(''===trim($p)||str_starts_with($p,'#'))continue;$expected=hash_file('sha256',$source.'/'.$p);$actual=is_file($installed.'/'.$p)?hash_file('sha256',$installed.'/'.$p):null;$files[$p]=['expected'=>$expected,'actual'=>$actual];if($actual!==$expected)$bad[]=$p;}
$env=['round'=>7,'wp'=>$GLOBALS['wp_version'],'woo'=>defined('WC_VERSION')?WC_VERSION:null,'php'=>PHP_VERSION,'database'=>$wpdb->get_var('SELECT VERSION()'),'database_host'=>DB_HOST,'database_name'=>DB_NAME,'ext_object_cache'=>wp_using_ext_object_cache(),'redis_host'=>defined('WP_REDIS_HOST')?WP_REDIS_HOST:null,'redis_prefix'=>defined('WP_REDIS_PREFIX')?WP_REDIS_PREFIX:null,'redis_plugin'=>get_plugin_data(WP_PLUGIN_DIR.'/redis-cache/redis-cache.php')['Version']??null,'dropin_sha256'=>is_file(WP_CONTENT_DIR.'/object-cache.php')?hash_file('sha256',WP_CONTENT_DIR.'/object-cache.php'):null,'instance_option'=>get_option('woocommerce_feature_product_instance_caching_enabled'),'manifest_sha256'=>hash_file('sha256',$manifest),'staged_files'=>count($files),'hash_mismatches'=>$bad,'files'=>$files];
echo 'ROUND7_ENVIRONMENT '.wp_json_encode($env)."\n";
if($bad)throw new RuntimeException('STAGED_SOURCE_MISMATCH');
