<?php
// Real registered callback, paused at a production checkpoint for parent SIGKILL.
$spec = json_decode( file_get_contents( $args[0] ), true );
file_put_contents( $spec['started'], (string) getmypid() );
add_action( 'writeleash_price_apply_checkpoint', static function ( $point ) use ( $spec ) {
    if ( 'AFTER_WOO_SAVE_BEFORE_JOURNAL' !== $point ) { return; }
    file_put_contents( $spec['barrier'], 'actual Woo save before durable journal commit' );
    while ( true ) { usleep( 10000 ); }
}, 10, 1 );
do_action( WriteLeash\Job_Scheduler::HOOK, (int) $spec['job_id'] );
