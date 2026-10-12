<?php
// Actual independent WP processes race for the final slot. Disposable sites only.
use WriteLeash\Price_Preset_Repository as RacePresets;
use WriteLeash\Price_Preset_Configuration as RaceConfig;
$spec_path = getenv( 'WLPC_PRESET_SPEC' );
if ( $spec_path ) {
	$spec = json_decode( file_get_contents( $spec_path ), true );
	wp_set_current_user( $spec['actor'] );
	file_put_contents( $spec['ready'], 'ready' );
	$deadline = microtime( true ) + 30;
	while ( ! is_file( $spec['go'] ) ) { if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'save barrier timeout' ); } usleep( 10000 ); }
	try {
		$record = RacePresets::save( 'Concurrent actor ' . $spec['actor'], $spec['config'] );
		if ( RacePresets::load( $record['id'] ) !== $record ) { throw new RuntimeException( 'winning actor cache not coherent' ); }
		$result = array( 'status' => 'saved', 'record' => $record );
	} catch ( WriteLeash\Price_Validation_Error $e ) { $result = array( 'status' => $e->reason() ); }
	file_put_contents( $spec['result'], wp_json_encode( $result ) );
	return;
}
global $wpdb, $race_checks;
$race_checks = 0;
function race_eq( $actual, $expected, string $label ): void { global $race_checks; ++$race_checks; if ( $actual !== $expected ) { throw new RuntimeException( $label ); } }
wp_set_current_user( 1 );
foreach ( RacePresets::listing() as $record ) { RacePresets::delete( $record['id'] ); }
$config = RaceConfig::capture( array( 'selector' => 'manual_ids', 'ids' => '123', 'operation' => 'SET', 'price_field' => 'regular_price', 'amount' => '80', 'ending' => 'default', 'max_products' => '100', 'max_increase' => '100', 'max_decrease' => '100', 'warning_threshold' => '100', 'block_zero' => '1' ) );
// Warm negative caches; save/rename/delete in this process must invalidate them.
for ( $slot = 0; $slot < 20; ++$slot ) { race_eq( get_option( 'writeleash_price_preset_' . $slot, null ), null, 'fresh empty slot' ); }
$saved = RacePresets::save( 'Cache control', $config );
race_eq( RacePresets::load( $saved['id'] ), $saved, 'save clears negative cache' );
RacePresets::rename( $saved['id'], 'Renamed' ); race_eq( RacePresets::load( $saved['id'] )['name'], 'Renamed', 'rename clears record cache' );
$old = RacePresets::load( $saved['id'] ); RacePresets::delete( $saved['id'] );
race_eq( get_option( 'writeleash_price_preset_0', null ), null, 'delete clears record cache' );
$new = RacePresets::save( 'Reused', $config );
$cas = new ReflectionMethod( RacePresets::class, 'replace' ); $cas->setAccessible( true );
foreach ( array( null, $old ) as $replacement ) {
	try { $cas->invoke( null, 0, $old, $replacement ); throw new RuntimeException( 'stale CAS accepted' ); }
	catch ( WriteLeash\Price_Validation_Error $e ) { race_eq( $e->reason(), 'preset_changed', 'stale delete/rename refused' ); }
	race_eq( RacePresets::load( $new['id'] ), $new, 'reused slot retains signed owner/material' );
}
for ( $slot = 1; $slot < 19; ++$slot ) { RacePresets::save( 'Occupied ' . $slot, $config ); }
$work = sys_get_temp_dir() . '/wlpc-presets-' . bin2hex( random_bytes( 6 ) ); mkdir( $work );
$workers = array();
for ( $i = 0; $i < 2; ++$i ) {
	$actor = wp_insert_user( array( 'user_login' => 'race-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
	$spec = array( 'actor' => $actor, 'ready' => "$work/$i.ready", 'go' => "$work/go", 'result' => "$work/$i.result", 'config' => $config );
	file_put_contents( "$work/$i.json", wp_json_encode( $spec ) );
	$env = getenv(); $env['WLPC_PRESET_SPEC'] = "$work/$i.json";
	$proc = proc_open( array( 'wp', '--path=' . ABSPATH, 'eval-file', __FILE__ ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', "$work/$i.stdout", 'w' ), 2 => array( 'file', "$work/$i.stderr", 'w' ) ), $pipes, null, $env );
	if ( ! is_resource( $proc ) ) { throw new RuntimeException( 'save process spawn failed' ); } fclose( $pipes[0] );
	$workers[] = array( $proc, $spec );
}
$deadline = microtime( true ) + 30;
while ( ! is_file( "$work/0.ready" ) || ! is_file( "$work/1.ready" ) ) { if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'workers not ready' ); } usleep( 10000 ); }
file_put_contents( "$work/go", 'go' );
$results = array(); foreach ( $workers as $worker ) { race_eq( proc_close( $worker[0] ), 0, 'real save process exits successfully' ); $results[] = json_decode( file_get_contents( $worker[1]['result'] ), true ); }
$statuses = array_column( $results, 'status' ); sort( $statuses ); race_eq( $statuses, array( 'preset_limit', 'saved' ), 'exactly one final-slot reservation wins' );
race_eq( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'writeleash_price_preset_%'" ), 20, 'capacity not exceeded' );
// Default caches are request-local. Discard this parent's earlier view when
// observing child writes; the winning child already proved its own cache repair.
wp_cache_delete( 'writeleash_price_preset_19', 'options' ); wp_cache_delete( 'notoptions', 'options' );
$winner = 'saved' === $results[0]['status'] ? $results[0]['record'] : $results[1]['record'];
race_eq( get_option( 'writeleash_price_preset_19' ), $winner, 'durable final slot retains exact signed winner' );
foreach ( $workers as $worker ) {
	wp_set_current_user( $worker[1]['actor'] );
	if ( $winner['creator_id'] === $worker[1]['actor'] ) { race_eq( RacePresets::load( $winner['id'] ), $winner, 'winner ownership' ); }
	else { try { RacePresets::load( $winner['id'] ); throw new RuntimeException( 'other actor read winner' ); } catch ( WriteLeash\Price_Validation_Error $e ) { race_eq( $e->reason(), 'preset_unavailable', 'ownership boundary' ); } }
}
wp_set_current_user( 1 ); foreach ( RacePresets::listing() as $record ) { RacePresets::delete( $record['id'] ); }
echo 'Presets simultaneous final-slot save / reused-slot CAS / ownership / caches: PASS (' . $race_checks . " assertions)\n";
