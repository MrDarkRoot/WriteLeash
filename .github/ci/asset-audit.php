<?php
// Network-free WordPress.org directory audit. Release tooling only.
// Policy reviewed 2026-10-02: developer.wordpress.org/plugins/wordpress-org/plugin-assets/
$root = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__, 2 );
$paths = array_values( array_filter( explode( "\0", stream_get_contents( STDIN ) ) ) );
$fail = static function ( string $message ): void { throw new RuntimeException( '#122 assets: ' . $message ); };
$canonical = array(
    'icon-128x128.png' => array( 128, 128 ),
    'icon-256x256.png' => array( 256, 256 ),
    'banner-772x250.png' => array( 772, 250 ),
    'banner-1544x500.png' => array( 1544, 500 ),
);
$count = 0;
$total = 0;
foreach ( $paths as $path ) {
    if ( ! preg_match( '#^wordpress/(?:assets|icon)/#', $path ) ) { continue; }
    if ( preg_match( '/\.(?:md|txt)$/', $path ) ) { continue; }
    $file = $root . '/' . $path;
    if ( is_link( $file ) || ! is_file( $file ) || strtolower( $path ) !== $path ) { $fail( 'missing, linked or non-lowercase asset: ' . $path ); }
    $size = filesize( $file );
    $total += $size;
    // Deliberately tighter than official banner 4MB / screenshot 10MB ceilings.
    $cap = str_starts_with( basename( $path ), 'icon' ) ? 1024 * 1024 : 2 * 1024 * 1024;
    if ( $size < 1 || $size > $cap ) { $fail( 'unexpected large/empty asset: ' . $path ); }
    if ( 'wordpress/assets/icon.svg' === $path ) {
        // Current policy permits icon.svg ONLY with PNG fallbacks. No SVG banners/screenshots.
        $xml = file_get_contents( $file );
        if ( preg_match( '/<!|<\?|(?:href|url\s*\(|on[a-z]+\s*=)/i', $xml ) ) { $fail( 'active or external SVG content' ); }
        $dom = new DOMDocument();
        if ( ! $dom->loadXML( $xml, LIBXML_NONET ) || 'svg' !== $dom->documentElement->tagName || 'http://www.w3.org/2000/svg' !== $dom->documentElement->namespaceURI ) { $fail( 'invalid icon SVG' ); }
        foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
            if ( ! in_array( $node->tagName, array( 'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'title', 'desc' ), true ) ) { $fail( 'unexpected SVG element: ' . $node->tagName ); }
        }
        ++$count;
        continue;
    }
    if ( ! preg_match( '/\.(?:png|jpg)$/', $path ) ) { $fail( 'unsupported image format: ' . $path ); }
    $image = getimagesize( $file );
    $type = str_ends_with( $path, '.png' ) ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
    if ( false === $image || $type !== $image[2] ) { $fail( 'format differs from extension: ' . $path ); }
    if ( preg_match( '/(\d+)x(\d+)/', basename( $path ), $match ) && array( (int) $match[1], (int) $match[2] ) !== array( $image[0], $image[1] ) ) { $fail( 'filename dimensions differ: ' . $path ); }
    ++$count;
}
if ( $total > 8 * 1024 * 1024 ) { $fail( 'unexpected large total asset set' ); }

// #122 introduces a required canonical set; deleting it must also fail.
{
    $proof_path = $root . '/wordpress/release/assets-122/proof.json';
    if ( ! is_file( $proof_path ) ) { $fail( 'missing screenshot proof inventory' ); }
    $proof = json_decode( file_get_contents( $proof_path ), true, 512, JSON_THROW_ON_ERROR );
    if ( ! is_string( $proof['product_base'] ?? null ) || '' === $proof['product_base'] || count( $proof['screenshots'] ?? array() ) !== 6 ) { $fail( 'invalid product baseline/screenshot inventory' ); }
    $readme = file_get_contents( $root . '/wordpress/writeleash/readme.txt' );
    if ( ! preg_match( '/== Screenshots ==\s*\n(.*?)(?=\n== |\z)/s', $readme, $section ) ) { $fail( 'missing readme screenshot section' ); }
    preg_match_all( '/^(\d+)\. (.+)$/m', $section[1], $captions, PREG_SET_ORDER );
    if ( count( $captions ) !== 6 ) { $fail( 'readme caption count differs from screenshot count' ); }
    foreach ( $proof['screenshots'] as $i => $shot ) {
        $n = $i + 1;
        $name = 'screenshot-' . $n . '.png';
        if ( $name !== ( $shot['filename'] ?? '' ) || (string) $n !== $captions[$i][1] || $shot['caption'] !== $captions[$i][2] ) { $fail( 'screenshot numbering/caption mismatch' ); }
        if ( ! is_int( $shot['width'] ?? null ) || ! is_int( $shot['height'] ?? null ) || $shot['width'] < 772 || $shot['width'] > 1280 || $shot['height'] < 200 || $shot['height'] > 1600 ) { $fail( 'unexpected screenshot canvas' ); }
        if ( 5 === $n ) {
            $visible = $shot['visible_ui_text'] ?? '';
            if ( ! is_string( $visible ) || ! str_contains( $visible, 'left the newer value unchanged' ) ) {
                $fail( 'screenshot-5 missing conflict explanation proof' );
            }
        }
        $canonical[$name] = array( $shot['width'], $shot['height'] );
    }
    if ( ! in_array( 'wordpress/assets/icon.svg', $paths, true ) ) { $fail( 'required icon.svg not tracked' ); }
    $allowed = array_keys( $canonical );
    $allowed[] = 'icon.svg';
    foreach ( glob( $root . '/wordpress/assets/*' ) as $file ) {
        if ( ! is_file( $file ) || ! in_array( basename( $file ), $allowed, true ) ) { $fail( 'unexpected canonical asset: ' . basename( $file ) ); }
    }
    foreach ( $canonical as $name => $dimensions ) {
        $path = 'wordpress/assets/' . $name;
        if ( ! in_array( $path, $paths, true ) ) { $fail( 'required asset not tracked: ' . $name ); }
        $image = getimagesize( $root . '/' . $path );
        if ( false === $image || array( $image[0], $image[1] ) !== $dimensions || IMAGETYPE_PNG !== $image[2] ) { $fail( 'canonical dimensions/format differ: ' . $name ); }
    }
    foreach ( $proof['screenshots'] as $shot ) {
        if ( hash_file( 'sha256', $root . '/wordpress/assets/' . $shot['filename'] ) !== $shot['sha256'] ) { $fail( 'capture hash differs from proof inventory' ); }
    }
    $manifest = file_get_contents( $root . '/wordpress/release/writeleash-distribution-files.txt' );
    foreach ( preg_split( '/\R/', $manifest ) as $entry ) {
        $entry = trim( $entry );
        if ( '' === $entry || '#' === $entry[0] ) { continue; }
        if ( preg_match( '#(?:^|/)(?:assets|icon)(?:/|$)|\.(?:png|jpg|jpeg|gif|svg)$#i', $entry ) ) { $fail( 'directory asset in runtime manifest' ); }
    }
    echo '#122 required files, exact dimensions, contiguous six screenshots, readme captions, capture hashes, SVG policy, size caps and runtime manifest: PASS' . "\n";
}

// #170 current review evidence has its own identity; #122 listing proof stays historical.
$review_dir = $root . '/docs/review/170';
{
    if ( ! is_file( $review_dir . '/proof.json' ) ) { $fail( 'missing #170 reviewed evidence' ); }
    $review = json_decode( file_get_contents( $review_dir . '/proof.json' ), true, 512, JSON_THROW_ON_ERROR );
    if ( 1 !== ( $review['schema'] ?? null ) || 'bd66a79' !== ( $review['base_main'] ?? null ) || count( $review['screenshots'] ?? array() ) !== 2 ) { $fail( 'invalid #170 reviewed baseline' ); }
    foreach ( array( 'class-free-admin.php', 'free-selection.js', 'free-selection.css' ) as $name ) {
        if ( hash_file( 'sha256', $root . '/wordpress/writeleash/includes/free/' . $name ) !== ( $review['source_hashes'][$name] ?? null ) ) { $fail( '#170 UI source differs from reviewed capture' ); }
    }
    foreach ( array( 'chromium', 'firefox' ) as $i => $engine ) {
        $shot = $review['screenshots'][$i];
        $name = '170-' . $engine . '-apply-conflict.png';
        $caption = ucfirst( $engine ) . ': newer Woo price preserved; expected/current/planned values remain in the same product row.';
        if ( $name !== ( $shot['filename'] ?? null ) || $caption !== ( $shot['caption'] ?? null ) ) { $fail( '#170 capture/caption mismatch' ); }
        $image = getimagesize( $review_dir . '/' . $name );
        if ( ! $image || $image[2] !== IMAGETYPE_PNG || array( $image[0], $image[1] ) !== array( $shot['width'], $shot['height'] ) || $image[0] !== 1440 || $image[1] < 200 || hash_file( 'sha256', $review_dir . '/' . $name ) !== $shot['sha256'] ) { $fail( '#170 capture hash/dimensions mismatch' ); }
        $result = json_decode( file_get_contents( $review_dir . '/170-' . $engine . '-result.json' ), true, 512, JSON_THROW_ON_ERROR );
        $a = $result['safety']['apply'];
        $dom = new DOMDocument(); libxml_use_internal_errors( true );
        $dom->loadHTML( '<table><tbody>' . $a['html'] . '</tbody></table>' ); libxml_clear_errors();
        $xpath = new DOMXPath( $dom );
        $rows = $xpath->query( '//tr[@data-product-id="' . (int) $a['product_id'] . '"]' );
        if ( $rows->length !== 1 || 'CONFLICT' !== $a['state'] || '100.00' !== $a['expected'] || '120.00' !== $a['current'] || '80.00' !== $a['planned'] ) { $fail( '#170 missing independent conflict observer' ); }
        $cells = array(); foreach ( $xpath->query( './td', $rows->item( 0 ) ) as $cell ) { $cells[] = trim( $cell->textContent ); }
        if ( count( $cells ) !== 6 || array_slice( $cells, 1, 3 ) !== array( '$100.00 USD', '$120.00 USD', '$80.00 USD' ) || ! str_contains( $cells[0], 'Gate ' ) || ! str_contains( $cells[4], 'left the newer value unchanged' ) ) { $fail( '#170 missing same-row identity/expected/current/planned/preservation proof' ); }
        if ( ! ( $result['safety']['negatives']['no_mutation'] ?? false ) ) { $fail( '#170 safety negatives did not pass' ); }
    }
    echo "#170 reviewed source, capture hashes/dimensions/captions and same-row independent conflict evidence PASS\n";
}

echo "#133 tracked directory asset format/size/dimensions: $count files PASS\n";
