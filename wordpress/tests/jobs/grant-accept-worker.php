<?php
// Test-only grant acceptance process. Reconstructs a frozen Change_Plan,
// impersonates the authenticated issuer, and calls Grant_Repository::accept_plan
// in a separate database session. An optional checkpoint barrier holds the
// acceptance transaction open (grant row lock held) so a concurrent same-key
// submission can prove serialization + replay with one job and one reservation.
$spec = json_decode( file_get_contents( $args[0] ), true );
if ( ! is_array( $spec ) || ! isset( $spec['plan_json'], $spec['grant'], $spec['issuer'], $spec['key'], $spec['result'] ) ) {
	throw new RuntimeException( 'accept spec invalid' );
}
file_put_contents( $spec['started'], (string) getmypid() );
if ( ! empty( $spec['checkpoint'] ) ) {
	add_action( 'writeleash_grant_accept_checkpoint', static function ( $point ) use ( $spec ) {
		if ( $point !== $spec['checkpoint'] ) { return; }
		file_put_contents( $spec['barrier'], $point . ':' . (int) $GLOBALS['wpdb']->dbh->thread_id );
		if ( 'wait' === ( $spec['fault'] ?? '' ) ) {
			$deadline = microtime( true ) + 25;
			while ( ! is_file( $spec['release'] ) ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'accept barrier timeout' ); }
				usleep( 10000 );
			}
		}
	} );
}
$plan = WriteLeash\Change_Plan::hydrate( json_decode( $spec['plan_json'], true ) );
wp_set_current_user( (int) $spec['issuer'] );
try {
	$result = WriteLeash\Grant_Repository::accept_plan( $plan, (string) $spec['grant'], (int) $spec['issuer'], (string) $spec['key'] );
	file_put_contents( $spec['result'], json_encode( array( 'ok' => true, 'replayed' => (bool) $result['replayed'], 'job_id' => (int) $result['job']['id'] ) ) );
} catch ( Throwable $error ) {
	file_put_contents( $spec['result'], json_encode( array( 'ok' => false, 'reason' => $error->getMessage() ) ) );
}
echo "accept-complete\n";
