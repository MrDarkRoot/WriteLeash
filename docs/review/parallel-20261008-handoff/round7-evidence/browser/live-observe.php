<?php
global $wpdb;
$jobid=(int)getenv('ROUND7_JOB_ID');
$job=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d',WriteLeash\Job_Schema::jobs_table($wpdb),$jobid),ARRAY_A);
$undo=$wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE job_id=%d',WriteLeash\Undo_Schema::operations_table($wpdb),$jobid),ARRAY_A);
$prices=[]; foreach((array)get_option('wl170_ids') as $id) { $prices[$id]=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM %i WHERE post_id=%d AND meta_key='_regular_price'",$wpdb->postmeta,$id)); }
echo wp_json_encode(['job'=>$job,'undo'=>$undo,'prices'=>$prices,'saves'=>get_option('wl170_saves',[])]);
