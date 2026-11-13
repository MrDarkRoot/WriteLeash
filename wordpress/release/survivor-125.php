<?php
// Spawned BEFORE uninstall from the byte-matching installed candidate.
$spec = json_decode( file_get_contents( $args[0] ), true );
$saves = 0;
add_action( 'woocommerce_before_product_object_save', static function () use ( &$saves ) { ++$saves; } );
file_put_contents( $spec['ready'], 'installed candidate loaded before uninstall' );
$deadline = microtime( true ) + 120;
while ( ! is_file( $spec['release'] ) ) { if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'survivor timeout' ); } usleep( 10000 ); }
do_action( WriteLeash\Job_Scheduler::HOOK, (int) $spec['job_id'] );
file_put_contents( $spec['result'], json_encode( array( 'runner_active' => WriteLeash\Runner_Authority::active( $GLOBALS['wpdb'] ), 'woo_saves' => $saves ) ) );
