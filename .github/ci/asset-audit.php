<?php
// Repository listing graphics only: no copying to the runtime manifest or ZIP.
$input = stream_get_contents( STDIN );
$count = 0;
foreach ( explode( "\0", $input ) as $path ) {
	if ( '' === $path || ! preg_match( '#^wordpress/(?:assets|icon)/#', $path ) ) { continue; }
	if ( preg_match( '/\.(?:md|txt)$/i', $path ) ) { continue; }
	if ( ! preg_match( '/\.(?:png|jpe?g)$/i', $path ) || is_link( $path ) || filesize( $path ) > 2 * 1024 * 1024 ) {
		throw new RuntimeException( 'Unreviewed directory asset type/size: ' . $path );
	}
	$image = getimagesize( $path );
	if ( false === $image || ! in_array( $image[2], array( IMAGETYPE_PNG, IMAGETYPE_JPEG ), true ) ) {
		throw new RuntimeException( 'Invalid directory image: ' . $path );
	}
	if ( preg_match( '/(\d+)x(\d+)/', basename( $path ), $match ) && ( (int) $match[1] !== $image[0] || (int) $match[2] !== $image[1] ) ) {
		throw new RuntimeException( 'Directory image dimensions differ from filename: ' . $path );
	}
	++$count;
}
echo "#133 tracked directory asset format/size/dimensions: $count files PASS\n";
