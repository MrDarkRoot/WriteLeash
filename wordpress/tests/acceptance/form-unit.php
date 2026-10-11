<?php
require __DIR__ . '/http.php';
$fields = wl112_form( '<form><input name="action" value="preview"><input type="checkbox" name="range_enabled" value="1"><input type="checkbox" name="block_zero" value="1" checked><input name="disabled_bound" value="99" disabled><input type="radio" name="basis" value="sale"><input type="radio" name="basis" value="regular" checked></form>', 'preview' );
wl112_assert( $fields === array( 'action' => 'preview', 'block_zero' => '1', 'basis' => 'regular' ), 'native successful-control semantics' );
echo "#231/#234 native form fixture: unchecked/disabled controls omitted; checked controls retained PASS\n";
