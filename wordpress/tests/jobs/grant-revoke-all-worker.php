<?php
// Test-only emergency revoke-all process. Runs Grant_Repository::revoke_all()
// in a separate database session so acceptance holding a grant row lock can
// prove serialization: the batch's SELECT ... FOR UPDATE waits for the
// acceptance COMMIT, and the accepted job gains no write authority. The
// barrier file records the DB thread id so the parent can wait for the real
// InnoDB lock wait before releasing the acceptance transaction.
$spec = json_decode( file_get_contents( $args[0] ), true );
if ( ! is_array( $spec ) || ! isset( $spec['operator'], $spec['result'], $spec['barrier'] ) ) {
	throw new RuntimeException( 'revoke-all spec invalid' );
}
file_put_contents( $spec['started'], (string) getmypid() );
file_put_contents( $spec['barrier'], (string) (int) $GLOBALS['wpdb']->dbh->thread_id );
try {
	wp_set_current_user( (int) $spec['operator'] );
	$revoked = WriteLeash\Grant_Repository::revoke_all( (int) $spec['operator'] );
	$result = array( 'revoked' => $revoked );
} catch ( Throwable $error ) {
	$result = array( 'revoked' => -1, 'reason' => $error->getMessage() );
}
file_put_contents( $spec['result'], json_encode( $result ) );
echo "revoke-all-complete\n";
