<?php
// Test-only #299 submission process. Holds the grant row at the accept
// checkpoint so a concurrent same-key REST call can prove one job.
$spec = json_decode( file_get_contents( $args[0] ), true );
if ( ! is_array( $spec ) || ! isset( $spec['grant'], $spec['issuer'], $spec['body'], $spec['result'] ) ) {
	throw new RuntimeException( 'agent rest spec invalid' );
}
file_put_contents( $spec['started'], (string) getmypid() );
if ( ! empty( $spec['checkpoint'] ) ) {
	add_action( 'writeleash_grant_accept_checkpoint', static function ( $point ) use ( $spec ) {
		if ( $point !== $spec['checkpoint'] ) { return; }
		file_put_contents( $spec['barrier'], $point . ':' . (int) $GLOBALS['wpdb']->dbh->thread_id );
		if ( 'wait' === ( $spec['fault'] ?? '' ) ) {
			$deadline = microtime( true ) + 25;
			while ( ! is_file( $spec['release'] ) ) {
				if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'agent rest barrier timeout' ); }
				usleep( 10000 );
			}
		}
	} );
}
wp_set_current_user( (int) $spec['issuer'] );
$request = new WP_REST_Request( 'POST', '/writeleash/v1/grants/' . $spec['grant'] . '/jobs' );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_body( (string) $spec['body'] );
$response = rest_do_request( $request );
$data = $response->get_data();
$reason = '';
$public_id = '';
$replayed = null;
if ( is_array( $data ) ) {
	$reason = (string) ( $data['data']['reason'] ?? $data['reason'] ?? '' );
	if ( isset( $data['job'] ) && is_array( $data['job'] ) ) {
		$public_id = (string) ( $data['job']['public_id'] ?? '' );
		$replayed = $data['job']['replayed'] ?? null;
	}
}
file_put_contents( $spec['result'], json_encode( array( 'status' => $response->get_status(), 'reason' => $reason, 'public_id' => $public_id, 'replayed' => $replayed ) ) );
echo "agent-rest-complete\n";
