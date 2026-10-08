<?php
// #204 boundary unit checks. Repository doubles do not establish Woo runtime correctness.
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'WC_VERSION', '11.1.2' );
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
	// The mutation-handler battery declares the supported Woo boundary so the
	// shared dependency gate is exercised; repository doubles stay read-only.
	function wc_get_product( $product = null ) { return null; }
	function did_action( $hook ) { return 'woocommerce_init' === $hook; }
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
		public static function callback( $job_id ): void { $GLOBALS['wl204_workers'][] = 'Job_Worker::callback'; }
	}
	class Undo_Worker {
		public static function run() { $GLOBALS['wl204_workers'][] = 'Undo_Worker::run'; return array(); }
		public static function queue_undo( $undo_id ): string { $GLOBALS['wl204_workers'][] = 'Undo_Worker::queue_undo'; return 'skipped'; }
		public static function callback( $undo_id ): void { $GLOBALS['wl204_workers'][] = 'Undo_Worker::callback'; }
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
	// Resume/Undo POST handlers are independent authority: each re-derives
	// capability, POST, job regex, job-bound nonce, actor, terminal/resumable
	// state and plan binding before any worker runs. The availability flags
	// and nonces handed to the browser are never inputs to these handlers.
	$resume_nonce = wp_create_nonce( Admin::ACTION_RESUME . '_job204' );
	$undo_nonce = wp_create_nonce( Admin::ACTION_UNDO . '_job204' );
	$workers_before = $GLOBALS['wl204_workers']; $jobs_mutations_before = Jobs::$mutations; $undo_mutations_before = Undo::$mutations;
	check204( Admin::process_resume( array( 'job' => 'job204', '_wpnonce' => $resume_nonce ), 'GET' ), array( 'status' => 'INVALID', 'reason' => 'post_required' ), 'Resume requires POST' );
	check204( Admin::process_undo( array( 'job' => 'job204', '_wpnonce' => $undo_nonce ), 'GET' ), array( 'status' => 'INVALID', 'reason' => 'post_required' ), 'Undo requires POST' );
	$GLOBALS['wl204_capable'] = false;
	check204( Admin::process_resume( array( 'job' => 'job204', '_wpnonce' => $resume_nonce ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'Resume capability re-checked' );
	check204( Admin::process_undo( array( 'job' => 'job204', '_wpnonce' => $undo_nonce ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'capability_required' ), 'Undo capability re-checked' );
	$GLOBALS['wl204_capable'] = true;
	check204( Admin::process_resume( array( 'job' => 'bad', '_wpnonce' => $resume_nonce ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_job' ), 'Resume job id is regex-checked' );
	check204( Admin::process_undo( array( 'job' => 'bad', '_wpnonce' => $undo_nonce ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_job' ), 'Undo job id is regex-checked' );
	check204( Admin::process_resume( array( 'job' => 'job204', '_wpnonce' => wp_create_nonce( Admin::ACTION_RESUME . '_job205' ) ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ), 'Resume nonce is job-bound' );
	check204( Admin::process_undo( array( 'job' => 'job204', '_wpnonce' => wp_create_nonce( Admin::ACTION_UNDO . '_job205' ) ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ), 'Undo nonce is job-bound' );
	check204( Admin::process_resume( array( 'job' => 'job204', '_wpnonce' => $undo_nonce ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ), 'Resume rejects an Undo action nonce' );
	check204( Admin::process_undo( array( 'job' => 'job204', '_wpnonce' => $resume_nonce ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'invalid_nonce' ), 'Undo rejects a Resume action nonce' );
	$GLOBALS['wl204_actor'] = 8;
	check204( Admin::process_resume( array( 'job' => 'job204', '_wpnonce' => $resume_nonce ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'not_authorized' ), 'Resume actor authorization re-checked' );
	check204( Admin::process_undo( array( 'job' => 'job204', '_wpnonce' => $undo_nonce ), 'POST' ), array( 'status' => 'FORBIDDEN', 'reason' => 'not_authorized' ), 'Undo actor authorization re-checked' );
	$GLOBALS['wl204_actor'] = 7;
	Jobs::$state = 'COMPLETED';
	check204( Admin::process_resume( array( 'job' => 'job204', '_wpnonce' => $resume_nonce, 'resume_available' => 'true' ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'job_terminal', 'public_id' => 'job204', 'job_status' => 'COMPLETED' ), 'terminal job refuses Resume despite a valid nonce and client availability' );
	Jobs::$state = 'PLANNED';
	check204( Admin::process_resume( array( 'job' => 'job204', '_wpnonce' => $resume_nonce ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'job_not_resumable', 'public_id' => 'job204', 'job_status' => 'PLANNED' ), 'non-resumable state refuses Resume' );
	Jobs::$state = 'RUNNING';
	check204( Admin::process_undo( array( 'job' => 'job204', '_wpnonce' => $undo_nonce, 'undo_available' => 'true' ), 'POST' ), array( 'status' => 'INVALID', 'reason' => 'undo_unavailable', 'public_id' => 'job204' ), 'Undo passes every pre-writer gate, then the mutation sentinel intercepts initiate' );
	check204( Undo::$mutations, array_merge( $undo_mutations_before, array( 'initiate' ) ), 'Undo initiate is the only repository writer reached' );
	Undo::$mutations = $undo_mutations_before;
	check204( Jobs::$mutations, $jobs_mutations_before, 'Resume/Undo refusals never call a Job_Repository writer' );
	check204( $GLOBALS['wl204_workers'], $workers_before, 'Resume/Undo refusals start no worker' );
	// Source structure: the handlers re-check authority and never read the
	// availability/nonce fields the progress snapshot hands to the browser.
	$admin_source = (string) file_get_contents( __DIR__ . '/../../writeleash/includes/free/class-free-admin.php' );
	$resume_start = strpos( $admin_source, 'function process_resume' );
	$undo_start = strpos( $admin_source, 'function process_undo' );
	$views_start = strpos( $admin_source, 'Read-only views', $undo_start );
	check204( false !== $resume_start && false !== $undo_start && false !== $views_start && $resume_start < $undo_start && $undo_start < $views_start, true, 'handler section markers present' );
	$resume_body = substr( $admin_source, $resume_start, $undo_start - $resume_start );
	$undo_body = substr( $admin_source, $undo_start, $views_start - $undo_start );
	foreach ( array( 'resume_available', 'undo_available', 'undo_button', 'resume_nonce', 'undo_nonce' ) as $hint ) {
		check204( str_contains( $resume_body . $undo_body, $hint ), false, 'handlers never trust client hint ' . $hint );
	}
	foreach ( array( 'can_mutate', "'POST' !== \$method", 'wp_verify_nonce', 'authorized_for_job', 'Job_State::is_terminal', 'Job_State::can_manual_run', 'hydrate_plan', 'precheck_product_rights', 'Job_Worker::run' ) as $check ) {
		check204( str_contains( $resume_body, $check ), true, 'Resume handler contains ' . $check );
	}
	foreach ( array( 'can_mutate', "'POST' !== \$method", 'wp_verify_nonce', 'authorized_for_job', 'hydrate_plan', 'Undo_Repository::initiate', 'Undo_State::is_terminal', 'Undo_Worker::run' ) as $check ) {
		check204( str_contains( $undo_body, $check ), true, 'Undo handler contains ' . $check );
	}
	// Manual/no-JS fallback stays server-rendered: a GET refresh link plus the
	// no-JavaScript notice, both independent of the shipped client.
	check204( str_contains( $admin_source, 'Refresh saved progress' ) && str_contains( $admin_source, 'Automatic updates require JavaScript.' ), true, 'manual/no-JS refresh is server-rendered' );
	// The read-only argument depends on the doubles defining reads only: any
	// other explicit method would bypass the __callStatic mutation sentinel.
	$jobs_reads = array_values( array_diff( get_class_methods( Jobs::class ), array( '__callStatic' ) ) ); sort( $jobs_reads );
	check204( $jobs_reads, array( 'hydrate_plan', 'observe', 'read_by_public_id' ), 'Job_Repository double defines only the observed reads' );
	$undo_reads = array_values( array_diff( get_class_methods( Undo::class ), array( '__callStatic' ) ) ); sort( $undo_reads );
	check204( $undo_reads, array( 'authorized', 'history_items', 'history_job', 'observe' ), 'Undo_Repository double defines only the observed reads' );
	// Self-control: the mutation sentinel intercepts every production writer
	// name used by Resume/Undo, and the worker sentinels record every worker
	// entrypoint (run/queue/callback). State is restored before the checks.
	foreach ( array( 'create_from_plan', 'approve', 'refresh_counters', 'acquire_lease', 'fence', 'renew_lease', 'finish_chunk', 'transition_unlocked', 'mark_paused', 'reap_stalled_leases', 'pause_stalled', 'claim_next_item', 'release_lifecycle_claim', 'record_item', 'reconcile_item', 'cancel' ) as $writer ) {
		$before_mutations = Jobs::$mutations;
		try { Jobs::$writer(); } catch ( \Throwable $error ) {}
		check204( count( Jobs::$mutations ) > count( $before_mutations ), true, 'Job_Repository mutation sentinel intercepts ' . $writer );
		Jobs::$mutations = $before_mutations;
	}
	foreach ( array( 'initiate', 'refresh_counters', 'transition_item', 'claim_next_item', 'release_lifecycle_claim', 'record_item', 'reconcile_item', 'clear_claim', 'defer_item', 'acquire_lease', 'fence', 'renew_lease', 'finish_chunk', 'transition_unlocked', 'mark_paused', 'reap_stalled_leases', 'pause_stalled', 'cancel' ) as $writer ) {
		$before_mutations = Undo::$mutations;
		try { Undo::$writer(); } catch ( \Throwable $error ) {}
		check204( count( Undo::$mutations ) > count( $before_mutations ), true, 'Undo_Repository mutation sentinel intercepts ' . $writer );
		Undo::$mutations = $before_mutations;
	}
	$before_workers = $GLOBALS['wl204_workers'];
	\WriteLeash\Job_Worker::queue_job( 4 ); \WriteLeash\Job_Worker::callback( 4 ); \WriteLeash\Job_Worker::run( 4 );
	\WriteLeash\Undo_Worker::queue_undo( 3 ); \WriteLeash\Undo_Worker::callback( 3 ); \WriteLeash\Undo_Worker::run( 3 );
	check204( count( $GLOBALS['wl204_workers'] ), count( $before_workers ) + 6, 'worker sentinels record run/queue/callback for both workers' );
	$GLOBALS['wl204_workers'] = $before_workers;
	check204( Jobs::$mutations, array(), 'progress never calls a mutating Job_Repository method' );
	check204( Undo::$mutations, array(), 'progress never calls a mutating Undo_Repository method' );
	check204( $GLOBALS['wl204_workers'], array(), 'progress never starts a worker' );
	echo "#204 PHP boundary unit: capabilities/actor/nonce/types, job-bound nonce, guessed ID, persisted counters, stalled/terminal Apply/Undo, incomplete/error fail closed, Resume/Undo handler authority, reads-only repository doubles + mutation/worker sentinel self-controls PASS (repository doubles; no Woo runtime claim)\n";
}
