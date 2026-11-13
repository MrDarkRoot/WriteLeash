<?php
// Fresh process: independent committed storage plus normal Woo edit-context readback.
$spec = json_decode( $args[0], true );
$facts = array();
$db = WriteLeash\Price_Cache_Verifier::observer();
try {
    foreach ( $spec as $id => $expected ) {
        $id = (int) $id;
        $storage = WriteLeash\Price_Cache_Verifier::storage( $db, $id );
        WriteLeash\Price_Cache_Verifier::matches( $storage, $expected );
        $product = wc_get_product( $id );
        if ( ! $product || WriteLeash\Price_Decimal::parse( $product->get_regular_price( 'edit' ) ) !== WriteLeash\Price_Decimal::parse( $expected ) ) { throw new RuntimeException( 'KILL: fresh Woo/storage divergence' ); }
        $facts[ $id ] = array( 'stored_regular' => $storage['meta']['_regular_price'][0], 'woo_regular' => $product->get_regular_price( 'edit' ), 'lookup_min' => $storage['lookup']['min_price'], 'lookup_max' => $storage['lookup']['max_price'] );
    }
} finally { $db->close(); }
echo json_encode( $facts );
