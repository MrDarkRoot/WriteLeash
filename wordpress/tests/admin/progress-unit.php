<?php
// #204 boundary unit checks. Repository doubles do not establish Woo runtime correctness.
namespace {
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['wl204_actor'] = 7; $GLOBALS['wl204_capable'] = true; $GLOBALS['wl204_http_error'] = null; $GLOBALS['wl204_workers'] = array();
	function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['wl204_hooks'][$hook] = $callback; }
	function nocache_headers() {}
	function wp_send_json_error( $data, $status ) { $GLOBALS['wl204_http_error'] = array( $data, $status ); throw new RuntimeException( 'Unit transport exit' ); }
	function get_current_user_id() { return $GLOBALS['wl204_actor']; }
	function current_user_can( $cap ) { return $GLOBALS['wl204_capable']; }
	function sanitize_text_field( $value ) { return $value; }
	function wp_unslash( $value ) { return $value; }
	function wp_verify_nonce( $nonce, $action ) { return $nonce === 'nonce:' . $action; }
	function wp_create_nonce( $action ) { return 'nonce:' . $action; }
	function admin_url( $path ) { return '/wp-admin/' . $path; }
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
	function wc_get_price_thousand_separator() { return ','; }
	function wc_get_price_decimal_separator() { return '.'; }
	function get_woocommerce_currency_symbol( $currency ) { return '$'; }
	function get_woocommerce_price_format() { return '%1$s%2$s'; }
	function user_can( $id, $cap ) { return false; }
}
namespace WriteLeash {
	class Job_Schema { public static function ready( $db ) { return true; } }
	class Fixture204Plan {
		public function summary() { return array( 'selected' => 2 ); }
		public function price_field() { return 'regular_price'; }
		public function data() { return array( 'store' => array( 'currency' => 'USD', 'price_decimals' => 2 ), 'items' => array_map( static fn( $id ) => array( 'product_id' => $id, 'snapshot' => array( 'name' => '<script>literal</script>', 'regular_price' => '10.00' ) ), array( 11, 12 ) ) ); }
	}
	class Job_Worker {
		public static function run() { $GLOBALS['wl204_workers'][] = 'Job_Worker::run'; return array(); }
		public static function queue_job() { $GLOBALS['wl204_workers'][] = 'Job_Worker::queue_job'; return false; }
	}
	class Undo_Worker {
		public static function run() { $GLOBALS['wl204_workers'][] = 'Undo_Worker::run'; return array(); }
	}
	class Job_Repository {
		public const PUBLIC_ID_REGEX = '/\Ajob20[45]\z/D';
		public static $counts = array( 'planned' => 2, 'pending' => 1, 'applying' => 0, 'applied' => 1, 'unchanged' => 0, 'conflict' => 0, 'failed' => 0, 'needs_review' => 0, 'unsupported' => 0 );
		public static $state = 'RUNNING', $stalled = false, $reads = 0, $observes = 0, $plans = 0, $throws = false, $plan_throws = false, $read_throws = false, $mutations = array();
		public static function __callStatic( $name, $args ) { self::$mutations[] = $name; throw new \RuntimeException( 'Mutation attempted: ' . $name ); }
		public static function read_by_public_id( $id ) {
			++self::$reads;
			if ( self::$read_throws ) { throw new \RuntimeException( 'offline' ); }
			$creator = 'job204' === $id ? 7 : 8;
			return array( 'id' => 'job204' === $id ? 4 : 5, 'public_id' => $id, 'creator_id' => $creator, 'approver_id' => $creator, 'status' => self::$state, 'applied' => 1 );
		}
		public static function observe( $id ) { ++self::$observes; if ( self::$throws ) { throw new \RuntimeException( 'offline' ); } return array( 'job' => self::read_by_public_id( 'job204' ), 'counts' => self::$counts, 'effective_status' => self::$stalled ? 'PAUSED' : self::$state, 'effective_reason' => 'LEASE_RECOVERY', 'stalled' => self::$stalled ); }
		public static function hydrate_plan( $job ) { ++self::$plans; if ( self::$plan_throws ) { throw new \RuntimeException( 'mismatch' ); } return new Fixture204Plan(); }
	}
	class Undo_Repository {
		public const MAX_EVIDENCE_ROWS = 1000;
		public static $state = null, $stalled = false, $history = 0, $items = 0, $history_throws = false, $items_throws = false, $mutations = array();
		public static function __callStatic( $name, $args ) { self::$mutations[] = $name; throw new \RuntimeException( 'Mutation attempted: ' . $name ); }
		public static function authorized( $job, $id ) { return in_array( $id, array( (int) $job['creator_id'], (int) $job['approver_id'] ), true ); }
		public static function history_job( $id ) { ++self::$history; if ( self::$history_throws ) { throw new \RuntimeException( 'offline' ); } return array( 'undo' => array( 'operation_id' => self::$state ? 3 : null, 'operation_status' => self::$state, 'operation_reason' => null, 'undone' => 0, 'pending' => 0, 'conflict' => 0, 'failed' => 0, 'applying' => 0, 'needs_review' => 0 ), 'undo_eligible' => 'COMPLETED' === Job_Repository::$state, 'undo_expires_at' => null ); }
		public static function observe( $id ) { return array( 'counts' => array( 'undone' => 1, 'pending' => 1, 'conflict' => 0, 'failed' => 0, 'applying' => 0, 'needs_review' => 0 ), 'effective_status' => self::$stalled ? 'UNDO_PAUSED' : self::$state, 'stalled' => self::$stalled ); }
		public static function history_items( $id, $apply, $undo, $offset, $limit ) {
			++self::$items; if ( self::$items_throws ) { throw new \RuntimeException( 'offline' ); }
			if ( 50 !== $limit ) { throw new \RuntimeException( 'Unbounded page' ); }
			return array( 'items' => array( array( 'product_id' => 11, 'expected_price' => '10.00', 'planned_price' => '15.00', 'apply_state' => 'CONFLICT', 'apply_reason' => null, 'undo_state' => null, 'undo_reason' => null ) ), 'next_offset' => null );
		}
	}
}
namespace {
	foreach ( array( 'free-support-contract', 'price-decimal', 'price-operation', 'job-state', 'undo-state', 'free-admin' ) as $file ) { require __DIR__ . '/../../writeleash/includes/free/class-' . $file . '.php'; }
	use WriteLeash\Free_Admin as Admin;
	use WriteLeash\Job_Repository as Jobs;
	use WriteLeash\Undo_Repository as Undo;
	function check204( $actual, $expected, $label ) { if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( $actual ) ); } }
	function refused204() { return array( 'status' => 'FORBIDDEN', 'reason' => 'progress_unavailable' ); }
	$undo_source = file_get_contents( __DIR__ . '/../../writeleash/includes/free/class-undo-repository.php' );
	preg_match( '/const MAX_EVIDENCE_ROWS = ([0-9]+)/', $undo_source, $bound );
	check204( (int) ( $bound[1] ?? 0 ), Undo::MAX_EVIDENCE_ROWS, 'unit bound mirrors production MAX_EVIDENCE_ROWS' );
	Admin::boot(); check204( $GLOBALS['wl204_hooks']['wp_ajax_writeleash_free_progress'], array( Admin::class, 'handle_progress' ), 'authenticated registration' );
	check204( $GLOBALS['wl204_hooks']['wp_ajax_nopriv_writeleash_free_progress'], array( Admin::class, 'handle_progress' ), 'expired-session denial registration' );
	$GLOBALS['wl204_actor'] = 0; try { Admin::handle_progress(); } catch ( RuntimeException $error ) {}
	check204( $GLOBALS['wl204_http_error'], array( array( 'reason' => 'progress_unavailable' ), 403 ), 'expired session denial exposes no job facts' ); check204( Jobs::$reads, 0, 'denial reads no repository'); $GLOBALS['wl204_actor'] = 7;
	$post = array( 'job' => 'job204', '_wpnonce' => wp_create_nonce( Admin::ACTION_PROGRESS . '_job204' ), 'offset' => '0', 'filter' => '' );
	foreach ( array( array( '_wpnonce' => 'wrong' ), array( '_wpnonce' => null ), array( '_wpnonce' => array() ), array( '_wpnonce' => wp_create_nonce( Admin::ACTION_PROGRESS . '_job205' ) ), array( 'offset' => '-1' ), array( 'offset' => '1001' ), array( 'offset' => '10001' ), array( 'offset' => '1000000' ), array( 'offset' => array() ), array( 'filter' => 'bad' ), array( 'filter' => array() ), array( 'job' => 'bad' ) ) as $bad ) {
		check204( Admin::progress_snapshot( array_merge( $post, $bad ), 'POST' ), refused204(), 'typed boundary' );
	}
	check204( Jobs::$reads, 0, 'invalid inputs read no repository' );
	check204( Admin::progress_snapshot( $post, 'GET' ), refused204(), 'POST read only' );
	check204( Admin::progress_snapshot( $post, 'post' ), refused204(), 'method case is exact' );
	$GLOBALS['wl204_actor'] = 0; check204( Admin::progress_snapshot( $post, 'POST' ), refused204(), 'anonymous' );
	$GLOBALS['wl204_actor'] = 8; check204( Admin::progress_snapshot( $post, 'POST' ), refused204(), 'cross actor' );
	check204( Jobs::$observes, 0, 'cross actor observes nothing' ); check204( Undo::$history, 0, 'cross actor reads no history' );
	$guess_reads = Jobs::$reads; $GLOBALS['wl204_actor'] = 7;
	check204( Admin::progress_snapshot( array( 'job' => 'job205', '_wpnonce' => wp_create_nonce( Admin::ACTION_PROGRESS . '_job205' ), 'offset' => '0', 'filter' => '' ), 'POST' ), refused204(), 'guessed public ID with valid job-bound nonce' );
	check204( Jobs::$reads - $guess_reads, 1, 'guess performs only the visibility lookup' );
	check204( Jobs::$observes, 0, 'guess observes no saved plan' ); check204( Jobs::$plans, 0, 'guess hydrates no plan' ); check204( Undo::$history, 0, 'guess reads no history' ); check204( Undo::$items, 0, 'guess reads no items' );
	$GLOBALS['wl204_actor'] = 7; $GLOBALS['wl204_capable'] = false; check204( Admin::progress_snapshot( $post, 'POST' ), refused204(), 'revoked capability' ); $GLOBALS['wl204_capable'] = true;
	$result = Admin::progress_snapshot( $post, 'POST' );
	check204( $result['status'], 'OK', 'saved running fixture' ); check204( $result['poll'], true, 'running Apply polls' ); check204( $result['rows'][0]['apply_attention'], true, 'conflict not success' );
	check204( $result['counts'], Jobs::$counts, 'durable counters authority' );
	check204( Admin::progress_snapshot( array_merge( $post, array( 'offset' => '1000' ) ), 'POST' )['status'], 'OK', 'offset at the evidence bound accepted' );
	Jobs::$stalled = true; $stalled = Admin::progress_snapshot( $post, 'POST' ); check204( $stalled['poll'], false, 'stalled stops' ); check204( str_contains( $stalled['notice'], 'stopped' ), true, 'stalled honest' );
	Jobs::$stalled = false; Jobs::$state = 'COMPLETED'; Jobs::$counts['pending'] = 0; Jobs::$counts['applied'] = 2;
	$terminal = Admin::progress_snapshot( $post, 'POST' ); check204( $terminal['poll'], false, 'terminal Apply stops' ); check204( $terminal['resume_available'], false, 'terminal cannot resume' ); check204( $terminal['resume_nonce'], null, 'terminal cannot obtain Resume nonce' ); check204( $terminal['undo_available'], true, 'eligible Undo becomes available' );
	Undo::$state = 'UNDO_RUNNING'; $running = Admin::progress_snapshot( $post, 'POST' ); check204( $running['poll'], true, 'running Undo after Apply polls' ); check204( $running['undo_counts']['undone'], 1, 'Undo durable counts' );
	Undo::$stalled = true; check204( Admin::progress_snapshot( $post, 'POST' )['poll'], false, 'stalled Undo stops' ); Undo::$stalled = false;
	Jobs::$counts['planned'] = 1; $missing = Admin::progress_snapshot( $post, 'POST' ); check204( $missing['poll'], false, 'missing Apply evidence blocks Undo polling too' ); check204( $missing['undo_available'], false, 'missing blocks Undo action' ); check204( $missing['resume_nonce'], null, 'missing blocks Resume nonce' ); check204( $missing['undo_nonce'], null, 'missing blocks Undo nonce' ); check204( str_contains( $missing['summary'], 'unavailable' ), true, 'missing evidence visibly incomplete' );
	Jobs::$throws = true; check204( Admin::progress_snapshot( $post, 'POST' )['status'], 'UNAVAILABLE', 'repository error honest' ); Jobs::$throws = false;
	Jobs::$plan_throws = true; check204( Admin::progress_snapshot( $post, 'POST' )['status'], 'UNAVAILABLE', 'plan hydration error honest' ); Jobs::$plan_throws = false;
	Jobs::$read_throws = true; check204( Admin::progress_snapshot( $post, 'POST' )['status'], 'UNAVAILABLE', 'read error honest' ); Jobs::$read_throws = false;
	Undo::$history_throws = true; check204( Admin::progress_snapshot( $post, 'POST' )['status'], 'UNAVAILABLE', 'history error honest' ); Undo::$history_throws = false;
	Undo::$items_throws = true; check204( Admin::progress_snapshot( $post, 'POST' )['status'], 'UNAVAILABLE', 'item page error honest' ); Undo::$items_throws = false;
	check204( Jobs::$mutations, array(), 'progress never calls a mutating Job_Repository method' );
	check204( Undo::$mutations, array(), 'progress never calls a mutating Undo_Repository method' );
	check204( $GLOBALS['wl204_workers'], array(), 'progress never starts a worker' );
	echo "#204 PHP boundary unit: capabilities/actor/nonce/types, job-bound nonce, guessed ID, persisted counters, stalled/terminal Apply/Undo, incomplete/error fail closed, read-only mutation/worker sentinels PASS (repository doubles; no Woo runtime claim)\n";
}
