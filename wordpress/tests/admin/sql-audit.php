<?php
// #111 (Blocker 1) read-only history proof: pure Admin GET/render/history
// reads issue zero DDL/DCL and mutate no setup options or plugin tables.
// Runs both on a fresh schema (empty states must stay silent) and on a
// populated schema (reads must stay SELECT-only). Never calls a POST path.
use WriteLeash\Free_Admin as Admin;
use WriteLeash\Undo_Error as UndoError;
use WriteLeash\Undo_Repository as UndoRepo;

global $wpdb, $wl111_queries;
$wl111_queries = array();
add_filter(
	'query',
	static function ( $query ) {
		global $wl111_queries;
		$wl111_queries[] = $query;
		return $query;
	}
);
function wl111_eq( $actual, $expected, string $label ): void {
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function wl111_render( string $view, string $job, int $offset ): string {
	ob_start();
	Admin::render_view( $view, $job, $offset );
	return (string) ob_get_clean();
}
function wl111_audit_window( string $label ): void {
	global $wpdb, $wl111_queries;
	$ddl = array();
	$writes = array();
	foreach ( $wl111_queries as $query ) {
		if ( preg_match( '/\b(create|alter|drop|truncate|grant|revoke)\b/i', $query ) ) {
			$ddl[] = $query;
		}
		if ( preg_match( '/^\s*(insert|update|delete|replace)\b/i', $query ) && false !== stripos( $query, 'writeleash' ) ) {
			$writes[] = $query;
		}
	}
	wl111_eq( $ddl, array(), $label . ' zero DDL/DCL during pure reads' );
	wl111_eq( $writes, array(), $label . ' zero WriteLeash writes during pure reads' );
	$wl111_queries = array();
}

wp_set_current_user( 1 );
$setup_keys = array( 'writeleash_job_setup', 'writeleash_undo_setup', 'writeleash_job_schema', 'writeleash_undo_schema', 'writeleash_runner_state' );
$before = array();
foreach ( $setup_keys as $key ) { $before[ $key ] = get_option( $key, '__absent__' ); }

wl111_render( '', '', 0 );
wl111_render( 'history', '', 0 );
wl111_render( 'history', '', 40 );
wl111_render( 'job', 'not-a-uuid', 0 );
wl111_render( 'preview', 'not-a-uuid', 0 );
$jobs_page = UndoRepo::history_jobs( 0, 20, 1 );
wl111_eq( is_array( $jobs_page['jobs'] ), true, 'scoped history page shape' );
if ( $jobs_page['jobs'] ) {
	$first = (int) $jobs_page['jobs'][0]['job_id'];
	UndoRepo::history_job( $first );
	UndoRepo::history_items( $first, null, null, 0, 50 );
} else {
	// Empty schema: single-job reads refuse without touching missing tables.
	try {
		UndoRepo::history_job( 1 );
		throw new RuntimeException( 'history_job succeeded with no jobs' );
	} catch ( UndoError $error ) {
		wl111_eq( $error->reason(), 'UNDO_NOT_ELIGIBLE', 'empty history_job refusal' );
	}
	try {
		UndoRepo::history_items( 1, null, null, 0, 50 );
		throw new RuntimeException( 'history_items succeeded with no jobs' );
	} catch ( UndoError $error ) {
		wl111_eq( $error->reason(), 'UNDO_NOT_ELIGIBLE', 'empty history_items refusal' );
	}
}
wl111_audit_window( 'admin reads' );
foreach ( $setup_keys as $key ) {
	wl111_eq( get_option( $key, '__absent__' ), $before[ $key ], 'setup option untouched by reads: ' . $key );
}
echo "#111 read-only history SQL audit: zero DDL, zero WriteLeash writes, setup options untouched: PASS\n";
