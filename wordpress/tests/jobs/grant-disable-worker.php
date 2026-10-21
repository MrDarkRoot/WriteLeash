<?php
// Test-only emergency pause process. Runs Grant_Repository::disable() in a
// separate database session so an item transaction holding the kill-switch
// SHARE lock can prove disable serializes after the in-flight boundary. The
// barrier file records the DB thread id so the parent can wait for the real
// InnoDB lock wait before releasing the item.
$spec = json_decode( file_get_contents( $args[0] ), true );
if ( ! is_array( $spec ) || ! isset( $spec['operator'], $spec['result'], $spec['barrier'] ) ) {
	throw new RuntimeException( 'disable spec invalid' );
}
file_put_contents( $spec['started'], (string) getmypid() );
file_put_contents( $spec['barrier'], (string) (int) $GLOBALS['wpdb']->dbh->thread_id );
try {
	wp_set_current_user( (int) $spec['operator'] );
	WriteLeash\Grant_Repository::disable( (int) $spec['operator'] );
	$result = array( 'disabled' => true );
} catch ( Throwable $error ) {
	$result = array( 'disabled' => false, 'reason' => $error->getMessage() );
}
file_put_contents( $spec['result'], json_encode( $result ) );
echo "disable-complete\n";
