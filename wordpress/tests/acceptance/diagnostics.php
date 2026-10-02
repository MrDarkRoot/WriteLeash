<?php
// This audit itself runs on PHP 7.4 as well; no PHP 8-only string helpers.
$log = is_file( $argv[1] ) ? file_get_contents( $argv[1] ) : '';
$counts = array( 'writeleash' => 0, 'upstream' => 0 );
foreach ( explode( "\n", $log ) as $line ) {
    if ( ! preg_match( '/PHP (Warning|Notice|Deprecated|Fatal|Parse error)|_doing_it_wrong|headers already sent/i', $line ) ) { continue; }
    ++$counts[ false !== strpos( $line, '/wp-content/plugins/writeleash/' ) ? 'writeleash' : 'upstream' ];
}
file_put_contents( '/evidence/' . $argv[2] . '-diagnostics.json', json_encode( array( 'git_sha' => getenv( 'WL112_SHA' ), 'php' => PHP_VERSION, 'diagnostic_lines' => $counts ), JSON_PRETTY_PRINT ) . "\n" );
if ( $counts['writeleash'] ) { throw new RuntimeException( 'WriteLeash PHP diagnostics present' ); }
