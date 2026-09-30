<?php
function wl107_equal( $actual, $expected, string $label ): void {
	global $wl107_assertions;
	++$wl107_assertions;
	if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . json_encode( array( 'actual' => $actual, 'expected' => $expected ) ) ); }
}
function wl107_error( callable $call, string $reason ): void {
	try { $call(); }
	catch ( \WriteLeash\Price_Validation_Error $e ) { wl107_equal( $e->reason(), $reason, 'typed error' ); return; }
	throw new RuntimeException( 'Missing typed error: ' . $reason );
}
function wl107_marker( string $label ): void {
	global $wl107_assertions;
	echo "#107 $label: PASS ($wl107_assertions cumulative assertions)\n";
}
