<?php
// Static, disposable negative controls for #122. No WordPress/DB/browser runtime.
$repo = dirname( __DIR__, 3 );
$fixture = sys_get_temp_dir() . '/wl122-asset-audit-' . bin2hex( random_bytes( 6 ) );
$remove = static function ( string $dir ) use ( &$remove ): void {
    if ( ! is_dir( $dir ) ) { return; }
    foreach ( new FilesystemIterator( $dir ) as $file ) {
        if ( $file->isDir() && ! $file->isLink() ) { $remove( $file->getPathname() ); }
        else { unlink( $file->getPathname() ); }
    }
    rmdir( $dir );
};
$prepare = static function () use ( $repo, $fixture, $remove ): void {
    $remove( $fixture );
    foreach ( array( 'wordpress/assets', 'wordpress/release/assets-122', 'wordpress/writeleash' ) as $dir ) { mkdir( $fixture . '/' . $dir, 0700, true ); }
    $files = array_merge( array_values( array_filter( glob( $repo . '/wordpress/assets/*' ), 'is_file' ) ), array( $repo . '/wordpress/release/assets-122/proof.json', $repo . '/wordpress/release/writeleash-distribution-files.txt', $repo . '/wordpress/writeleash/readme.txt' ) );
    foreach ( $files as $file ) { copy( $file, $fixture . substr( $file, strlen( $repo ) ) ); }
};
$run = static function () use ( $repo, $fixture ): int {
    $paths = array_map( static fn( $file ) => substr( $file, strlen( $fixture ) + 1 ), glob( $fixture . '/wordpress/assets/*' ) );
    $pipes = array();
    $process = proc_open( array( PHP_BINARY, $repo . '/.github/ci/asset-audit.php', $fixture ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
    fwrite( $pipes[0], implode( "\0", $paths ) . "\0" ); fclose( $pipes[0] );
    stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
    stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
    return proc_close( $process );
};
$change_conflict_text = static function ( string $f, callable $change ): void {
    $p = $f . '/wordpress/release/assets-122/proof.json';
    $proof = json_decode( file_get_contents( $p ), true, 512, JSON_THROW_ON_ERROR );
    $proof['screenshots'][2]['visible_ui_text'] = $change( $proof['screenshots'][2]['visible_ui_text'] );
    file_put_contents( $p, json_encode( $proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
};
$cases = array(
    'missing Current header' => static function ( $f ) use ( $change_conflict_text ) { $change_conflict_text( $f, static fn( $s ) => str_replace( "\tCurrent\t", "\tOld\t", $s ) ); },
    'Current equals Expected despite other 21 text' => static function ( $f ) use ( $change_conflict_text ) { $change_conflict_text( $f, static fn( $s ) => str_replace( "10\t18\t21\t14.40", "10\t18\t18\t14.40", $s ) . "\n21\n" ); },
    'Current equals Planned' => static function ( $f ) use ( $change_conflict_text ) { $change_conflict_text( $f, static fn( $s ) => str_replace( "10\t18\t21\t14.40", "10\t18\t14.40\t14.40", $s ) ); },
    'conflict tokens exist only outside row' => static function ( $f ) use ( $change_conflict_text ) { $change_conflict_text( $f, static fn( $s ) => str_replace( 'CONFLICT (ITEM_CONFLICT)', 'APPLIED (WOO_CRUD_VERIFIED)', $s ) . "\nCONFLICT (ITEM_CONFLICT)\n" ); },
    'numbers are split across unrelated lines' => static function ( $f ) use ( $change_conflict_text ) { $change_conflict_text( $f, static fn( $s ) => "Product\tExpected\tCurrent\tPlanned\tApply\tUndo\n18\n21\n14.40\nCONFLICT (ITEM_CONFLICT)\n" ); },
    'entire asset directory missing' => static function ( $f ) use ( $remove ) { $remove( $f . '/wordpress/assets' ); },
    'missing required SVG icon' => static function ( $f ) { unlink( $f . '/wordpress/assets/icon.svg' ); },
    'missing required icon / SVG fallback' => static function ( $f ) { unlink( $f . '/wordpress/assets/icon-128x128.png' ); },
    'wrong exact icon dimensions' => static function ( $f ) { copy( $f . '/wordpress/assets/icon-128x128.png', $f . '/wordpress/assets/icon-256x256.png' ); },
    'screenshot numbering gap' => static function ( $f ) { rename( $f . '/wordpress/assets/screenshot-3.png', $f . '/wordpress/assets/screenshot-7.png' ); },
    'readme caption count mismatch' => static function ( $f ) { $p = $f . '/wordpress/writeleash/readme.txt'; file_put_contents( $p, preg_replace( '/^6\. History shows.*\n/m', '', file_get_contents( $p ) ) ); },
    'readme caption content mismatch' => static function ( $f ) { $p = $f . '/wordpress/writeleash/readme.txt'; file_put_contents( $p, str_replace( 'Review the exact before-and-after', 'Review different', file_get_contents( $p ) ) ); },
    'SVG outside supported icon role' => static function ( $f ) { copy( $f . '/wordpress/assets/icon.svg', $f . '/wordpress/assets/banner.svg' ); },
    'active SVG content' => static function ( $f ) { $p = $f . '/wordpress/assets/icon.svg'; file_put_contents( $p, str_replace( '</svg>', '<script>alert(1)</script></svg>', file_get_contents( $p ) ) ); },
    'unexpected large binary' => static function ( $f ) { file_put_contents( $f . '/wordpress/assets/banner-772x250.png', str_repeat( 'x', 2 * 1024 * 1024 + 1 ), FILE_APPEND ); },
    'directory asset in runtime manifest' => static function ( $f ) { file_put_contents( $f . '/wordpress/release/writeleash-distribution-files.txt', "assets/screenshot-1.png\n", FILE_APPEND ); },
    'format differs from extension' => static function ( $f ) { file_put_contents( $f . '/wordpress/assets/icon-256x256.png', '<svg/>'); },
    'capture bytes differ from proof' => static function ( $f ) { file_put_contents( $f . '/wordpress/assets/screenshot-1.png', 'different capture', FILE_APPEND ); },
);
try {
    $prepare();
    if ( 0 !== $run() ) { throw new RuntimeException( 'Valid asset control failed' ); }
    foreach ( $cases as $label => $mutate ) {
        $prepare(); $mutate( $fixture );
        if ( 0 === $run() ) { throw new RuntimeException( 'Invalid asset accepted: ' . $label ); }
    }
    echo '#122 asset audit valid control + ' . count( $cases ) . " rejection cases PASS\n";
} finally { $remove( $fixture ); }
