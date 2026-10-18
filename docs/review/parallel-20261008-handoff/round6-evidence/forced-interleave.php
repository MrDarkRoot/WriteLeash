<?php
use WriteLeash\Woo_Price_Planner as Planner;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Safety_Policy as Policy;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_State as JState;

global $wpdb, $work;
$work = sys_get_temp_dir() . '/wlforce-' . bin2hex( random_bytes( 5 ) );
mkdir( $work );
$GLOBALS['wl108_active'] = true;
$GLOBALS['wl108_log'] = $work . '/hooks.log';
touch( $GLOBALS['wl108_log'] );
@unlink( WP_CONTENT_DIR . '/debug.log' );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wl108_hook_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, product_id bigint unsigned NOT NULL, hook varchar(100) NOT NULL) ENGINE=InnoDB" );

function fcreate(): int {
	$p = new WC_Product_Simple(); $p->set_name( 'WLFORCE-' . wp_generate_uuid4() ); $p->set_status( 'publish' ); $p->set_regular_price( '100.00' ); $p->save(); return $p->get_id();
}
function fjob( int $product_id, string $target, int $actor = 1 ): array {
	wp_set_current_user( $actor );
	$plan = Planner::preview( Selection::ids( array( $product_id ) ), new Operation( Operation::SET, $target ), new Policy( 1000, '100', '100', true, '100' ) );
	$job = Repo::create_from_plan( $plan, $actor );
	Repo::approve( (int) $job['id'], 1 );
	return array( 'job' => Repo::read( (int) $job['id'] ) );
}
function fstart( array $spec ): array {
	global $work;
	$prefix = $work . '/p-' . bin2hex( random_bytes( 5 ) );
	$spec = array_merge( array( 'result' => $prefix . '.result', 'barrier' => $prefix . '.barrier', 'release' => $prefix . '.release', 'started' => $prefix . '.started', 'hook_log' => $GLOBALS['wl108_log'] ), $spec );
	file_put_contents( $prefix . '.json', json_encode( $spec ) );
	$proc = proc_open( array( '/tmp/wlruntime/bin/wp', '--path=' . ABSPATH, 'eval-file', '/tmp/writeleash-approved-20261008/agent-B/wordpress/tests/jobs/worker.php', $prefix . '.json' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $prefix . '.stdout', 'w' ), 2 => array( 'file', $prefix . '.stderr', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) { throw new RuntimeException( 'spawn failed' ); }
	fclose( $pipes[0] );
	return array( 'proc' => $proc, 'spec' => $spec, 'prefix' => $prefix );
}
function fawait( string $file, float $timeout = 25 ): void {
	$deadline = microtime( true ) + $timeout;
	while ( ! is_file( $file ) || '' === (string) file_get_contents( $file ) ) { if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'barrier timeout ' . $file ); } usleep( 10000 ); clearstatcache( true, $file ); }
}
function ffinish( array $w ): array {
	$exit = proc_close( $w['proc'] );
	if ( 0 !== $exit || ! is_file( $w['spec']['result'] ) ) { return array( 'exit' => $exit, 'error' => file_get_contents( $w['prefix'] . '.stdout' ) . file_get_contents( $w['prefix'] . '.stderr' ) ); }
	return json_decode( file_get_contents( $w['spec']['result'] ), true );
}

wp_set_current_user( 1 );
$bad = 0;
for ( $i = 1; $i <= 10; $i++ ) {
	$product = fcreate();
	$A = fjob( $product, '80.00' );
	$B = fjob( $product, '90.00' );
	$b1 = $work . '/b1-' . $i; $r1 = $work . '/r1-' . $i;
	$wA = fstart( array( 'job_id' => (int) $A['job']['id'], 'mode' => 'run', 'limits' => array( 'max_items' => 1, 'budget_seconds' => 30 ), 'lock_wait_timeout' => 20, 'mutator_checkpoint' => 'AFTER_COMMIT_BEFORE_RESPONSE', 'mutator_fault' => 'wait', 'mutator_barrier' => $b1, 'mutator_release' => $r1 ) );
	fawait( $b1 );
	$wB = fstart( array( 'job_id' => (int) $B['job']['id'], 'mode' => 'run', 'limits' => array( 'max_items' => 1, 'budget_seconds' => 30 ), 'lock_wait_timeout' => 20 ) );
	$rB = ffinish( $wB );
	touch( $r1 );
	$rA = ffinish( $wA );
	$sa = $rA['status'] ?? 'ERR'; $sb = $rB['status'] ?? 'ERR';
	$rows = array();
	foreach ( array( (int) $A['job']['id'], (int) $B['job']['id'] ) as $jid ) { $rows[] = $wpdb->get_row( $wpdb->prepare( "SELECT state,reason FROM {$wpdb->prefix}writeleash_job_items WHERE job_id=%d", $jid ), ARRAY_A ); }
	$ok = in_array( $sa, array( 'COMPLETED' ), true ) && in_array( $sb, array( 'COMPLETED_WITH_ISSUES' ), true );
	echo "#$i pausedA=$sa normalB=$sb A_item=" . json_encode( $rows[0] ) . ' B_item=' . json_encode( $rows[1] ) . ( $ok ? ' OK' : ' *** ABNORMAL ***' ) . "\n";
	if ( ! $ok ) { $bad++; break; }
}
echo "FORCED-INTERLEAVE DONE abnormal=$bad\n";
