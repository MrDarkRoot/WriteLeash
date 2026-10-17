<?php
// Test-only grant revoke process. Revokes the named grant in a separate
// database session while another worker holds the grant row lock, proving
// serialization: the UPDATE blocks until the item COMMIT/ROLLBACK.
$spec = json_decode( file_get_contents( $args[0] ), true );
if ( ! is_array( $spec ) || ! isset( $spec['grant'], $spec['issuer'], $spec['result'] ) ) {
	throw new RuntimeException( 'revoke spec invalid' );
}
file_put_contents( $spec['started'], (string) getmypid() );
try {
	// Grant management is bound to the authenticated principal: impersonate
	// the issuer before revoking (a real request would carry this session).
	wp_set_current_user( (int) $spec['issuer'] );
	$grant = WriteLeash\Grant_Repository::revoke( (string) $spec['grant'], (int) $spec['issuer'] );
	$result = array( 'revoked' => true, 'version' => (int) $grant['version'] );
} catch ( Throwable $error ) {
	$result = array( 'revoked' => false, 'reason' => $error->getMessage() );
}
file_put_contents( $spec['result'], json_encode( $result ) );
echo "revoke-complete\n";
