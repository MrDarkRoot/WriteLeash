<?php
use WriteLeash\Woo_Price_Planner as Planner;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Safety_Policy as Policy;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Job_State as JState;
use WriteLeash\Price_Cache_Verifier as Verifier;

global $wpdb, $work;
$work = sys_get_temp_dir() . '/wlrace-' . bin2hex( random_bytes( 5 ) );
mkdir( $work );
$hook_log = $work . '/hooks.log';
touch( $hook_log );
require '/tmp/writeleash-approved-20261008/agent-B/wordpress/tests/durable/hook-torture.php';
$GLOBALS['wl108_active'] = true;
$GLOBALS['wl108_log'] = $hook_log;
@unlink( WP_CONTENT_DIR . '/debug.log' );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wl108_hook_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, product_id bigint unsigned NOT NULL, hook varchar(100) NOT NULL) ENGINE=InnoDB" );
@unlink( '/tmp/wlruntime/logs/diag-trace.log' );

function create_product( string $price ): int {
	$p = new WC_Product_Simple();
	$p->set_name( 'WLRACE-' . wp_generate_uuid4() );
	$p->set_status( 'publish' );
	$p->set_regular_price( $price );
	$p->save();
	return $p->get_id();
}
function single_job( int $product_id, string $target, int $actor = 1 ): array {
	wp_set_current_user( $actor );
	$plan = Planner::preview( Selection::ids( array( $product_id ) ), new Operation( Operation::SET, $target ), new Policy( 1000, '100', '100', true, '100' ) );
	$job = Repo::create_from_plan( $plan, $actor );
	Repo::approve( (int) $job['id'], 1 );
	return array( 'plan' => $plan, 'job' => Repo::read( (int) $job['id'] ) );
}
function start_worker( array $spec ): array {
	global $work;
	$prefix = $work . '/proc-' . bin2hex( random_bytes( 5 ) );
	$spec = array_merge( array( 'result' => $prefix . '.result', 'barrier' => $prefix . '.barrier', 'release' => $prefix . '.release', 'started' => $prefix . '.started', 'hook_log' => $GLOBALS['wl108_log'] ), $spec );
	file_put_contents( $prefix . '.json', json_encode( $spec ) );
	$proc = proc_open( array( '/tmp/wlruntime/bin/wp', '--path=' . ABSPATH, 'eval-file', '/tmp/writeleash-approved-20261008/agent-B/wordpress/tests/jobs/worker.php', $prefix . '.json' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $prefix . '.stdout', 'w' ), 2 => array( 'file', $prefix . '.stderr', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) { throw new RuntimeException( 'worker spawn failed' ); }
	fclose( $pipes[0] );
	return array( 'proc' => $proc, 'spec' => $spec, 'prefix' => $prefix );
}
function finish_worker( array $worker ): array {
	$exit = proc_close( $worker['proc'] );
	if ( 0 !== $exit || ! is_file( $worker['spec']['result'] ) ) { return array( 'exit' => $exit, 'error' => file_get_contents( $worker['prefix'] . '.stdout' ) . file_get_contents( $worker['prefix'] . '.stderr' ) ); }
	return json_decode( file_get_contents( $worker['spec']['result'] ), true );
}
function saves( int $id ): int {
	global $hook_log;
	if ( ! is_file( $hook_log ) ) { return 0; }
	$count = 0;
	foreach ( file( $hook_log, FILE_IGNORE_NEW_LINES ) as $line ) {
		$entry = json_decode( $line, true );
		if ( is_array( $entry ) && 'woocommerce_before_product_object_save' === ( $entry['hook'] ?? '' ) && $id === (int) ( $entry['id'] ?? 0 ) ) { ++$count; }
	}
	return $count;
}
function fresh_price( int $id ): string {
	Verifier::invalidate( $id );
	$p = wc_get_product( $id );
	return (string) $p->get_regular_price( 'edit' );
}

wp_set_current_user( 1 );
$fail = 0;
for ( $i = 1; $i <= 80; $i++ ) {
	$product = create_product( '100.00' );
	$a = single_job( $product, '80.00' );
	$b = single_job( $product, '90.00' );
	$x = start_worker( array( 'job_id' => (int) $a['job']['id'], 'mode' => 'run', 'limits' => array( 'max_items' => 1, 'budget_seconds' => 30 ), 'lock_wait_timeout' => 20 ) );
	$y = start_worker( array( 'job_id' => (int) $b['job']['id'], 'mode' => 'run', 'limits' => array( 'max_items' => 1, 'budget_seconds' => 30 ), 'lock_wait_timeout' => 20 ) );
	$rx = finish_worker( $x );
	$ry = finish_worker( $y );
	$sx = $rx['status'] ?? ( 'ERR:' . ( $rx['error'] ?? '?' ) );
	$sy = $ry['status'] ?? ( 'ERR:' . ( $ry['error'] ?? '?' ) );
	$statuses = array( $sx, $sy );
	sort( $statuses );
	$expect = array( JState::COMPLETED, JState::COMPLETED_WITH_ISSUES );
	$ok = $statuses === $expect;
	echo "#$i product=$product A=" . $sx . " B=" . $sy . ' saves=' . saves( $product ) . ' price=' . fresh_price( $product ) . ( $ok ? ' OK' : ' *** MISMATCH ***' ) . "\n";
	if ( ! $ok ) {
		$fail++;
		global $wpdb;
		foreach ( array( (int) $a['job']['id'], (int) $b['job']['id'] ) as $jid ) {
			$item = $wpdb->get_row( $wpdb->prepare( "SELECT job_id,state,reason,attempt_count FROM {$wpdb->prefix}writeleash_job_items WHERE job_id=%d", $jid ), ARRAY_A );
			echo '  item ' . json_encode( $item ) . "\n";
		}
		echo '  A-result ' . json_encode( $rx ) . "\n  B-result " . json_encode( $ry ) . "\n";
		if ( is_file( '/tmp/wlruntime/logs/diag-trace.log' ) ) { echo "  trace:\n" . file_get_contents( '/tmp/wlruntime/logs/diag-trace.log' ) . "\n"; }
		break;
	}
}
echo "REPRO DONE failures=$fail\n";
