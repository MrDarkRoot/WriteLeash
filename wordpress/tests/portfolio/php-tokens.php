<?php
// Build/test tooling only. Never distributed or loaded by a plugin.
$tokens = array();
foreach ( token_get_all( stream_get_contents( STDIN ), TOKEN_PARSE ) as $token ) {
    if ( is_array( $token ) ) {
        if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) { continue; }
        $tokens[] = array( token_name( $token[0] ), $token[1] );
    } else {
        $tokens[] = array( '', $token );
    }
}
echo json_encode( $tokens, JSON_THROW_ON_ERROR );
