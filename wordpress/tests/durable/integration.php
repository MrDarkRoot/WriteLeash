<?php
use WriteLeash\Price_Apply_Journal as J;
use WriteLeash\Price_Cache_Verifier as V;
use WriteLeash\Woo_Price_Mutator as M;
use WriteLeash\Woo_Price_Planner as Planner;
use WriteLeash\Price_Selection_Spec as Selection;
use WriteLeash\Price_Operation as Operation;
use WriteLeash\Safety_Policy as Policy;
use WriteLeash\Price_Decimal as Decimal;

global $wpdb, $work, $assertions;
$assertions = 0;
$work = sys_get_temp_dir() . '/wl108-' . bin2hex( random_bytes( 5 ) );
mkdir( $work );
function eq( $actual, $expected, string $label ): void {
	global $assertions;
	++$assertions;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function marker( string $label ): void { global $assertions; echo "#108 $label: PASS ($assertions assertions)\n"; }
function fixture( string $target = '80', ?int $actor = null ): array {
	global $work;
	wp_set_current_user( $actor ?? 1 );
	$p = new WC_Product_Simple();
	$p->set_name( 'WL108-' . wp_generate_uuid4() );
	$p->set_status( 'publish' );
	$p->set_regular_price( '100.00' );
	$p->save();
	// Two honest #107 plans; each freezes its own absolute target/hash.
	$plan = Planner::preview( Selection::ids( array( $p->get_id() ) ), new Operation( Operation::DECREASE_PERCENT, '80' === $target ? '20' : '10' ), new Policy( 2, '100', '100', true, '100' ) );
	eq( $plan->item( $p->get_id() )->data()['planned_regular_price'], $target . '.00', 'real #107 absolute target' );
	J::seed( $plan );
	$prefix = $work . '/' . $p->get_id();
	file_put_contents( $prefix . '.plan', serialize( $plan ) );
	return array( 'id' => $p->get_id(), 'plan' => $prefix . '.plan', 'object' => $plan, 'log' => $prefix . '.hooks' );
}
function start_worker( array $f, array $fault = array() ): array {
	global $work;
	$prefix = $work . '/worker-' . bin2hex( random_bytes( 5 ) );
	$job = array_merge( array_diff_key( $f, array( 'object' => 1 ) ), array( 'result' => $prefix . '.result', 'barrier' => $prefix . '.barrier', 'release' => $prefix . '.release', 'started' => $prefix . '.started' ), $fault );
	file_put_contents( $prefix . '.json', json_encode( $job ) );
	$proc = proc_open( array( 'wp', '--path=' . ABSPATH, 'eval-file', __DIR__ . '/worker.php', $prefix . '.json' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $prefix . '.stdout', 'w' ), 2 => array( 'file', $prefix . '.stderr', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) { throw new RuntimeException( 'worker spawn failed' ); }
	fclose( $pipes[0] );
	return array( 'proc' => $proc, 'job' => $job, 'prefix' => $prefix );
}
function await_file( string $file ): void {
	$deadline = microtime( true ) + 15;
	while ( ! is_file( $file ) ) {
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'missing deterministic barrier: ' . $file ); }
		usleep( 10000 );
	}
}
function finish( array $worker, bool $killed = false ): array {
	$exit = proc_close( $worker['proc'] );
	if ( $killed ) { eq( in_array( $exit, array( 9, 137 ), true ), true, 'actual SIGKILL exit' ); return array(); }
	if ( 0 !== $exit || ! is_file( $worker['job']['result'] ) ) {
		throw new RuntimeException( 'worker failure: ' . file_get_contents( $worker['prefix'] . '.stdout' ) . file_get_contents( $worker['prefix'] . '.stderr' ) );
	}
	return json_decode( file_get_contents( $worker['job']['result'] ), true );
}
function run_item( array $f, array $fault = array() ): array { return finish( start_worker( $f, $fault ) ); }
function truth( array $f, string $price, string $state, bool $fresh_woo = true ): array {
	$db = V::observer();
	$original = $GLOBALS['wpdb'];
	try {
		$row = J::read( $db, $f['object']->data()['plan_id'], $f['id'] );
		eq( $row['state'], $state, 'independent durable journal' );
		$storage = V::storage( $db, $f['id'] );
		V::matches( $storage, $price );
		if ( $fresh_woo ) {
			$GLOBALS['wpdb'] = $db;
			V::invalidate( $f['id'] );
			$p = wc_get_product( $f['id'] );
			eq( Decimal::parse( $p->get_regular_price( 'edit' ) ), Decimal::parse( $price ), 'independent fresh Woo regular' );
			eq( Decimal::parse( $p->get_price( 'edit' ) ), Decimal::parse( $price ), 'independent fresh Woo active' );
		}
		return $row;
	} finally { $GLOBALS['wpdb'] = $original; $db->close(); }
}
function hook_count( array $f, string $hook = 'woocommerce_before_product_object_save' ): int {
	if ( ! is_file( $f['log'] ) ) { return 0; }
	return count( array_filter( array_map( static fn( $line ) => json_decode( $line, true ), file( $f['log'], FILE_IGNORE_NEW_LINES ) ), static fn( $entry ) => $hook === $entry['hook'] && $f['id'] === $entry['id'] ) );
}
function edit( array $f, string $value ): void {
	V::invalidate( $f['id'] );
	$p = wc_get_product( $f['id'] ); $p->set_regular_price( $value ); $p->save();
}

wp_set_current_user( 1 );
eq( WC_VERSION, '11.1.2', 'pinned Woo' );
eq( get_bloginfo( 'version' ), '7.1.2', 'pinned WordPress' );
eq( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, '8.2', 'pinned PHP' );
eq( (bool) wp_using_ext_object_cache(), 'persistent' === getenv( 'WL108_CACHE' ), 'real persistent drop-in loaded' );
if ( wp_using_ext_object_cache() ) {
	eq( get_file_data( WP_CONTENT_DIR . '/object-cache.php', array( 'version' => 'Version' ) )['version'], '2.7.0', 'pinned Redis drop-in' );
	// Prove cross-process persistence, rather than just a true feature flag.
	wp_cache_set( 'wl108_persistence', 'cross-process', 'wl108' );
	$out = shell_exec( 'wp --path=' . escapeshellarg( ABSPATH ) . ' eval ' . escapeshellarg( 'echo wp_cache_get("wl108_persistence","wl108");' ) );
	eq( trim( $out ), 'cross-process', 'actual cross-process Redis value' );
	marker( 'Redis 7.4.2 / Redis Object Cache 2.7.0 / Predis cross-process persistence' );
}
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_price_num_decimals', 2 );
J::install(); J::install();
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wl108_hook_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, product_id bigint unsigned NOT NULL, hook varchar(100) NOT NULL) ENGINE=InnoDB" );

require __DIR__ . '/identity.php';

// Reproduce before fixing: post/meta cleanup alone is insufficient.
$f = fixture();
$wpdb->query( 'START TRANSACTION' );
$p = wc_get_product( $f['id'] ); $p->set_regular_price( '80.00' ); $p->save();
get_post_meta( $f['id'], '_regular_price', true );
$wpdb->query( 'ROLLBACK' );
eq( get_post_meta( $f['id'], '_regular_price', true ), '80.00', 'uncommitted cached metadata survives rollback' );
truth( $f, '100', 'PENDING', false );
clean_post_cache( $f['id'] );
wc_get_container()->get( Automattic\WooCommerce\Internal\Caches\ProductCache::class )->remove( $f['id'] );
$p = wc_get_product( $f['id'] ); $p->set_regular_price( '80.00' ); $p->save();
$db = V::observer();
$broken = V::storage( $db, $f['id'] );
eq( $broken['meta']['_regular_price'][0], '80.00', 'counterexample stored regular' );
eq( Decimal::parse( $broken['lookup']['min_price'] ), '100', 'counterexample lookup stayed 100' );
$db->close();
marker( 'known rollback-cache counterexample reproduced: regular=80 lookup=100' );
V::invalidate( $f['id'] ); edit( $f, '100.00' );
eq( run_item( $f )['code'], 'APPLIED', 'targeted repair then real executor' );
truth( $f, '80', 'APPLIED' );
marker( 'rollback cache fix: post/meta/product/lookup eviction; fresh Woo/read/lookup restored' );
$product_cache = wc_get_container()->get( Automattic\WooCommerce\Internal\Caches\ProductCache::class );
$product_cache->set( wc_get_product( $f['id'] ) );
eq( $product_cache->get( $f['id'] )->get_id(), $f['id'], 'actual Woo product cache entry primed' );
V::invalidate( $f['id'] );
eq( $product_cache->get( $f['id'] ), null, 'actual Woo product cache eviction' );
marker( 'Woo ProductCache public set/get/remove API eviction' );

$a = fixture(); $b = fixture( '90' );
eq( run_item( $a )['code'], 'APPLIED', 'clean A apply' );
eq( run_item( $b )['code'], 'APPLIED', 'clean B apply' );
$row = truth( $a, '80', 'APPLIED' ); truth( $b, '90', 'APPLIED' );
eq( hook_count( $a ), 1, 'normal before hook' );
eq( hook_count( $a, 'woocommerce_after_product_object_save' ), 1, 'normal after hook' );
eq( hook_count( $a, 'woocommerce_update_product' ), 1, 'normal update hook' );
eq( hook_count( $a, 'woocommerce_product_object_updated_props' ), 1, 'normal updated-props hook' );
foreach ( file( $a['log'], FILE_IGNORE_NEW_LINES ) as $entry ) {
	eq( json_decode( $entry, true )['connection'], json_decode( $row['evidence'], true )['connection_id'], 'Woo/hook writes share journal transaction connection' );
}
$db = V::observer();
$writer_connection = json_decode( $row['evidence'], true )['connection_id'];
eq( (int) $db->dbh->thread_id !== $writer_connection, true, 'durable observer has independent connection ID' );
echo '#108 transaction ownership: writer/Woo/journal=' . $writer_connection . '; independent observer=' . $db->dbh->thread_id . "\n";
eq( (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM {$db->prefix}wl108_hook_events WHERE product_id=%d", $a['id'] ) ), 4, 'normal same-connection hook DB rows committed' );
eq( $db->get_var( $db->prepare( "SELECT option_value FROM {$db->options} WHERE option_name=%s", 'wl108_hook_option_' . $a['id'] ) ), 'woocommerce_after_product_object_save', 'normal hook option durable' );
$db->close();
eq( run_item( $a )['code'], 'ALREADY_APPLIED', 'already APPLIED retry' );
eq( hook_count( $a ), 1, 'APPLIED retry mutator hook replay=0' );
truth( $a, '80', 'APPLIED' );
marker( 'clean A/B apply and already-APPLIED retry: hooks replay=0; absolute retry remains 80, never 64' );

$f = fixture();
eq( run_item( $f, array( 'point' => 'AFTER_WOO_SAVE_BEFORE_JOURNAL', 'mode' => 'throw' ) )['code'], 'FAILED', 'catchable failure rollback' );
truth( $f, '100', 'PENDING' );
eq( hook_count( $f ), 1, 'rollback external filesystem hook happened' );
eq( hook_count( $f, 'woocommerce_after_product_object_save' ), 1, 'after-save hook happened before rollback' );
$db = V::observer();
eq( (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM {$db->prefix}wl108_hook_events WHERE product_id=%d", $f['id'] ) ), 0, 'same-connection hook DB writes rolled back' );
eq( $db->get_var( $db->prepare( "SELECT option_value FROM {$db->options} WHERE option_name=%s", 'wl108_hook_option_' . $f['id'] ) ), null, 'hook WP option rolled back' );
$db->close();
eq( run_item( $f )['code'], 'APPLIED', 'retry after rollback' );
truth( $f, '80', 'APPLIED' );
eq( hook_count( $f ), 2, 'rolled-back hooks may replay on a fresh attempt' );
marker( 'rollback/failure/retry; hook DB option/log rollback; filesystem surrogate survives' );

// Real SIGKILL; ordinary PHP exception handling does not run.
foreach ( array( 'BEFORE_WOO_SAVE', 'AFTER_WOO_SAVE_BEFORE_JOURNAL', 'AFTER_JOURNAL_BEFORE_COMMIT', 'AFTER_COMMIT_BEFORE_RESPONSE' ) as $point ) {
	$f = fixture();
	$worker = start_worker( $f, array( 'point' => $point, 'mode' => 'wait' ) );
	await_file( $worker['job']['barrier'] );
	$postcommit = 'AFTER_COMMIT_BEFORE_RESPONSE' === $point;
	truth( $f, $postcommit ? '80' : '100', $postcommit ? 'APPLIED' : 'PENDING', false );
	proc_terminate( $worker['proc'], 9 ); finish( $worker, true );
	truth( $f, $postcommit ? '80' : '100', $postcommit ? 'APPLIED' : 'PENDING' );
	$count = hook_count( $f );
	eq( run_item( $f )['code'], $postcommit ? 'ALREADY_APPLIED' : 'APPLIED', 'crash recovery ' . $point );
	eq( hook_count( $f ), $count + ( $postcommit ? 0 : 1 ), 'crash save replay contract' );
	truth( $f, '80', 'APPLIED' );
	marker( ( $postcommit ? 'kill-after-COMMIT durable APPLIED recovered; Woo save replay=0 ' : 'kill-before-COMMIT original price/journal not APPLIED ' ) . $point );
}

$a = fixture(); $b = fixture( '90' );
$b_input = array_diff_key( $b, array( 'object' => 1 ) );
$worker = start_worker( $a, array( 'next' => $b_input, 'point' => 'AFTER_COMMIT_BEFORE_RESPONSE', 'mode' => 'wait' ) );
await_file( $worker['job']['barrier'] );
proc_terminate( $worker['proc'], 9 ); finish( $worker, true );
truth( $a, '80', 'APPLIED' ); truth( $b, '100', 'PENDING' );
$resume = run_item( $a, array( 'next' => $b_input ) );
eq( $resume['A']['code'], 'ALREADY_APPLIED', 'interruption recover A in restarted two-item process' );
eq( $resume['B']['code'], 'APPLIED', 'interruption resume B in same restarted process' );
truth( $a, '80', 'APPLIED' ); truth( $b, '90', 'APPLIED' );
eq( hook_count( $a ), 1, 'A no hook replay' ); eq( hook_count( $b ), 1, 'B once' );
marker( 'interruption: A APPLIED / B PENDING / restart recognizes A / resume B once / save replay=0' );

$f = fixture();
$first = start_worker( $f, array( 'point' => 'ITEM_LOCKED', 'mode' => 'wait' ) );
await_file( $first['job']['barrier'] );
$second = start_worker( $f ); await_file( $second['job']['started'] );
$deadline = microtime( true ) + 10;
$overlap = false;
$db = V::observer();
while ( microtime( true ) < $deadline ) {
	foreach ( $db->get_results( 'SHOW FULL PROCESSLIST', ARRAY_A ) as $process ) {
		if ( str_contains( $process['Info'] ?? '', 'writeleash_price_items' ) && str_contains( $process['Info'] ?? '', 'FOR UPDATE' ) ) { $overlap = true; break 2; }
	}
	usleep( 10000 );
}
$db->close(); eq( $overlap, true, 'second real worker overlaps on journal row lock' );
eq( hook_count( $f ), 0, 'no mutation before first released' );
file_put_contents( $first['job']['release'], 'go' );
eq( finish( $first )['code'], 'APPLIED', 'first worker applied' );
eq( finish( $second )['code'], 'ALREADY_APPLIED', 'second worker no save' );
eq( hook_count( $f ), 1, 'one mutator save' ); truth( $f, '80', 'APPLIED' );
marker( 'concurrent same item: real overlap / one mutator save / one APPLIED / second ALREADY_APPLIED' );

$f = fixture(); edit( $f, '90.00' );
eq( run_item( $f )['code'], 'CONFLICT', 'external edit result' );
truth( $f, '90', 'CONFLICT' ); eq( hook_count( $f ), 0, 'external edit zero WriteLeash save' );
marker( 'external edit: expected 100 / current 90 / CONFLICT / zero WriteLeash save' );
$f = fixture(); edit( $f, '80.00' );
eq( run_item( $f )['code'], 'CONFLICT', 'target equality without journal is not APPLIED' );
truth( $f, '80', 'CONFLICT' ); eq( hook_count( $f ), 0, 'equal target external edit no save' );
marker( 'current==target without APPLIED journal: CONFLICT / no invented application evidence' );

foreach ( array( 'status', 'sale', 'currency', 'decimals', 'software' ) as $drift ) {
 $f = fixture();
 if ( 'status' === $drift || 'sale' === $drift ) {
  $p = wc_get_product( $f['id'] );
  if ( 'status' === $drift ) { $p->set_status( 'draft' ); }
  else { $p->set_sale_price( '90' ); }
  $p->save();
 } elseif ( 'currency' === $drift ) { update_option( 'woocommerce_currency', 'EUR' ); }
 elseif ( 'decimals' === $drift ) { update_option( 'woocommerce_price_num_decimals', 3 ); }
 $result = run_item( $f, 'software' === $drift ? array( 'wp_version_fault' => true ) : array() );
 eq( $result['code'], 'CONFLICT', 'fresh contract drift ' . $drift );
 eq( hook_count( $f ), 0, 'contract drift zero mutation' );
 update_option( 'woocommerce_currency', 'USD' ); update_option( 'woocommerce_price_num_decimals', 2 );
 marker( 'fresh ' . $drift . ' contract changed: CONFLICT / zero WriteLeash save' );
}


$user = wp_insert_user( array( 'user_login' => 'wl108-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$f = fixture( '80', $user );
( new WP_User( $user ) )->set_role( 'subscriber' );
eq( run_item( $f )['code'], 'PERMISSION_DENIED', 'revoked authority' );
truth( $f, '100', 'FAILED' ); eq( hook_count( $f ), 0, 'revoked zero mutation' );
wp_set_current_user( 1 ); marker( 'permission revoked after plan: PERMISSION_DENIED / zero mutation' );
$role_user = wp_insert_user( array( 'user_login' => 'wl108-role-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$f = fixture( '80', $role_user ); wp_set_current_user( 1 );
$worker = start_worker( $f, array( 'point' => 'ITEM_LOCKED', 'mode' => 'wait' ) );
await_file( $worker['job']['barrier'] );
get_role( 'shop_manager' )->remove_cap( 'edit_products' );
file_put_contents( $worker['job']['release'], 'go' );
eq( finish( $worker )['code'], 'PERMISSION_DENIED', 'role definition revoked after worker bootstrap' );
truth( $f, '100', 'FAILED' ); eq( hook_count( $f ), 0, 'role revocation zero mutation' );
get_role( 'shop_manager' )->add_cap( 'edit_products' );
marker( 'concurrent role-capability revocation while worker paused: fresh authority / zero mutation' );

foreach ( array( 'lost', 'lost-then-throw', 'changed', 'reconnected', 'transaction-ended' ) as $mode ) {
	$f = fixture();
	$result = run_item( $f, array( 'point' => 'AFTER_WOO_SAVE_BEFORE_JOURNAL', 'mode' => $mode ) );
	eq( $result['code'], 'NEEDS_REVIEW', 'connection loss fails closed' );
	eq( $result['reason'], 'TRANSACTION_LOST', 'typed lost ownership' );
	truth( $f, '100', 'NEEDS_REVIEW' );
	eq( run_item( $f )['code'], 'NEEDS_REVIEW', 'ownership loss not blindly retried' );
	eq( hook_count( $f ), 1, 'no ownership-loss replay' );
	marker( 'transaction ownership ' . $mode . ': TRANSACTION_LOST / NEEDS_REVIEW / no replay' );
}

$f = fixture();
$result = run_item( $f, array( 'point' => 'BEFORE_WOO_SAVE', 'mode' => 'kill-during-query' ) );
eq( $result['code'], 'NEEDS_REVIEW', 'connection lost between fence and SQL' );
eq( $result['reason'], 'TRANSACTION_LOST', 'core wpdb reconnect replay refused' );
truth( $f, '100', 'NEEDS_REVIEW' );
eq( run_item( $f )['code'], 'NEEDS_REVIEW', 'replay-fence review cannot retry' );
eq( hook_count( $f ), 1, 'replay fence one before-save hook only' );
eq( hook_count( $f, 'woocommerce_after_product_object_save' ), 0, 'replay fence no completed save' );
marker( 'actual KILL between query fence and SQL: wpdb check_connection replay refused / price original' );

// Deterministic lost acknowledgement: test BOTH possible durability outcomes.
foreach ( array( 'AFTER_JOURNAL_BEFORE_COMMIT', 'AFTER_COMMIT_BEFORE_RESPONSE' ) as $point ) {
	$f = fixture();
	$result = run_item( $f, array( 'point' => $point, 'mode' => 'ambiguous' ) );
	eq( $result['code'], 'NEEDS_REVIEW', 'ambiguous outcome' );
	eq( $result['reason'], 'AMBIGUOUS_COMMIT', 'typed ambiguous commit' );
	$applied = 'AFTER_COMMIT_BEFORE_RESPONSE' === $point;
	truth( $f, $applied ? '80' : '100', $applied ? 'APPLIED' : 'NEEDS_REVIEW' );
	eq( run_item( $f )['code'], $applied ? 'ALREADY_APPLIED' : 'NEEDS_REVIEW', 'durable evidence before possible retry' );
	eq( hook_count( $f ), 1, 'ambiguous no blind save replay' );
	marker( 'ambiguous COMMIT ' . $point . ': NEEDS_REVIEW / independent durable observation / no blind retry' );
}

$f = fixture();
$result = run_item( $f, array( 'point' => 'AFTER_COMMIT_BEFORE_RESPONSE', 'mode' => 'cache-read-fault' ) );
eq( $result['code'], 'NEEDS_REVIEW', 'fresh Woo read disagreement does not certify APPLIED' );
eq( $result['reason'], 'CACHE_VERIFICATION_FAILED', 'typed fresh Woo read disagreement' );
truth( $f, '80', 'APPLIED' );
eq( run_item( $f )['code'], 'ALREADY_APPLIED', 'read hook fault recovered by independent process' );
eq( hook_count( $f ), 1, 'read disagreement never saves twice' );
marker( 'fresh observer Woo read hook disagrees with durable truth: CACHE_VERIFICATION_FAILED / no replay' );

$f = fixture();
eq( run_item( $f )['code'], 'APPLIED', 'journal tamper fixture' );
$wpdb->update( J::table( $wpdb ), array( 'evidence' => '' ), array( 'plan_id' => $f['object']->data()['plan_id'], 'product_id' => $f['id'] ) );
$result = run_item( $f );
eq( $result['code'], 'NEEDS_REVIEW', 'journal evidence mismatch cannot claim APPLIED' );
eq( $result['reason'], 'JOURNAL_MISMATCH', 'typed journal mismatch' );
eq( hook_count( $f ), 1, 'journal mismatch never reruns save' );
marker( 'journal evidence mismatch: NEEDS_REVIEW / save replay=0' );

$f = fixture();
$wpdb->query( 'START TRANSACTION' ); $wpdb->query( 'SAVEPOINT wl108_caller' );
$result = M::apply( $f['object'], $f['id'] );
eq( $result['code'], 'TRANSACTION_UNAVAILABLE', 'caller transaction not owned' );
eq( false !== $wpdb->query( 'ROLLBACK TO SAVEPOINT wl108_caller' ), true, 'caller transaction preserved' );
$wpdb->query( 'ROLLBACK' ); truth( $f, '100', 'PENDING' );
marker( 'caller-owned transaction: refused without COMMIT/ROLLBACK of caller work' );

$f = fixture();
$result = run_item( $f, array( 'lookup_fault' => true ) );
eq( $result['code'], 'NEEDS_REVIEW', 'lookup fault result' ); eq( $result['reason'], 'LOOKUP_MISMATCH', 'lookup fault reason' );
truth( $f, '100', 'NEEDS_REVIEW' ); marker( 'lookup mismatch: rollback / NEEDS_REVIEW / no false APPLIED' );

foreach ( array( 'duplicate', 'missing', 'malformed' ) as $anomaly ) {
	$f = fixture();
	if ( 'duplicate' === $anomaly ) { add_post_meta( $f['id'], '_regular_price', '90' ); }
	elseif ( 'missing' === $anomaly ) { delete_post_meta( $f['id'], '_regular_price' ); }
	else { update_post_meta( $f['id'], '_regular_price', 'not-a-price' ); }
	V::invalidate( $f['id'] );
	echo '#108 abnormal storage ' . $anomaly . ' actual Woo regular=' . json_encode( wc_get_product( $f['id'] )->get_regular_price( 'edit' ) ) . "\n";
	$result = run_item( $f );
	eq( $result['code'], 'UNSUPPORTED_PRODUCT_STATE' , 'metadata anomaly refusal' );
	eq( hook_count( $f ), 0, 'metadata anomaly zero mutation' );
	marker( $anomaly . ' regular metadata: typed refusal / zero mutation' );
}
$f = fixture();
$table = J::table( $wpdb ); $wpdb->query( "ALTER TABLE $table ENGINE=MyISAM" );
eq( run_item( $f )['code'], 'TRANSACTION_UNAVAILABLE', 'nontransactional journal refused' );
eq( hook_count( $f ), 0, 'nontransactional zero mutation' );
$wpdb->query( "ALTER TABLE $table ENGINE=InnoDB" );
truth( $f, '100', 'PENDING' ); marker( 'non-InnoDB journal: TRANSACTION_UNAVAILABLE / zero mutation' );

$a = fixture(); $b = fixture( '90' );
eq( run_item( $a )['code'], 'APPLIED', 'undo A fixture' ); eq( run_item( $b )['code'], 'APPLIED', 'undo B fixture' );
edit( $a, '70.00' );
eq( M::undo_precondition( $a['object'], $a['id'] ), 'UNDO_CONFLICT', 'newer edit fails undo prototype' );
eq( M::undo_precondition( $b['object'], $b['id'] ), 'UNDO_ELIGIBLE', 'untouched eligible' );
edit( $a, '80.00' );
eq( M::undo_precondition( $a['object'], $a['id'] ), 'UNDO_ELIGIBLE', 'ABA equality limitation explicitly observed' );
marker( 'Undo precondition only: current!=applied CONFLICT / current==applied ELIGIBLE / ABA limitation observed' );
if ( wp_using_ext_object_cache() ) { marker( 'persistent cache COMMIT/ROLLBACK/retry/already-APPLIED/external-edit consistent' ); }
marker( 'complete executable correctness matrix' );
