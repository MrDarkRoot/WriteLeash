<?php
// #256 focused display/authorization regression; run with wp eval-file in a disposable store.
use WriteLeash\Free_Admin as LabelsAdmin;
use WriteLeash\Price_Preset_Repository as LabelsPresets;
use WriteLeash\Price_Preset_Configuration as LabelsConfig;
wp_set_current_user( 1 );
$checks256 = 0;
$assert256 = static function ( $actual, $expected, $label ) use ( &$checks256 ) {
 ++$checks256; if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . wp_json_encode( array( $actual, $expected ) ) ); }
};
$products256 = array();
foreach ( array( 'Visible & linen', 'Private name never shown', 'Deleted item', 'Draft product' ) as $n256 => $name256 ) {
 $p256 = new WC_Product_Simple(); $p256->set_name( $name256 ); $p256->set_sku( 'Labels-' . wp_generate_uuid4() );
 $p256->set_status( 3 === $n256 ? 'draft' : 'publish' ); $p256->set_regular_price( '100' ); $products256[] = $p256->save();
}
$input256 = array( 'selector' => 'manual_ids', 'ids' => implode( ',', $products256 ), 'operation' => 'INCREASE_FIXED', 'amount' => '5', 'price_field' => 'regular_price', 'max_products' => '1000', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '20' );
$record256 = LabelsPresets::save( 'Display regression', LabelsConfig::capture( $input256 ) );
wp_delete_post( $products256[2], true );
$deny256 = static function ( $caps, $cap, $actor, $args ) use ( $products256 ) {
 return in_array( $cap, array( 'edit_post', 'read_post' ), true ) && (int) ( $args[0] ?? 0 ) === $products256[1] ? array( 'do_not_allow' ) : $caps;
};
$writes256 = 0; $watch256 = static function () use ( &$writes256 ) { ++$writes256; };
add_filter( 'map_meta_cap', $deny256, 10, 4 ); add_action( 'woocommerce_before_product_object_save', $watch256 );
try {
 $result256 = LabelsAdmin::process_preset( array( 'preset_action' => 'load', 'preset_id' => $record256['id'], 'preset_nonce' => wp_create_nonce( LabelsAdmin::ACTION_PRESET . '_load_' . $record256['id'] ) ), 'POST' );
 $assert256( $result256['status'], 'OK', 'configuration can be reviewed despite changed references' );
 $form256 = $result256['form'];
 $assert256( $form256['selector'], 'ids', 'loaded IDs prefill existing named picker' );
 $assert256( LabelsAdmin::build_selection( $form256 )->data(), LabelsAdmin::build_selection( $input256 )->data(), 'all IDs and selection semantics retained' );
 ob_start(); LabelsAdmin::render_view( 'home', '', 0, $form256 ); $html256 = ob_get_clean();
 $assert256( str_contains( $html256, 'Visible &amp; linen' ), true, 'permitted name escaped before Preview' );
 $assert256( str_contains( $html256, 'Private name never shown' ), false, 'inaccessible name not disclosed' );
 foreach ( array_slice( $products256, 1 ) as $id256 ) {
  $assert256( str_contains( $html256, 'Product #' . $id256 . ' unavailable or inaccessible. ID retained;' ), true, 'generic unavailable explanation' );
  $assert256( str_contains( $html256, 'value="' . $id256 . '" selected' ), true, 'unavailable ID remains selected' );
 }
 $form256['_wpnonce'] = wp_create_nonce( LabelsAdmin::ACTION_PREVIEW );
 $assert256( LabelsAdmin::process_preview( $form256, 'POST' )['status'], 'FORBIDDEN', 'display fallback grants no planner authorization' );
 $assert256( $writes256, 0, 'load/render/refused Preview cause zero product saves' );
} finally {
 remove_filter( 'map_meta_cap', $deny256, 10 ); remove_action( 'woocommerce_before_product_object_save', $watch256 );
 LabelsPresets::delete( $record256['id'] ); foreach ( $products256 as $id256 ) { wp_delete_post( $id256, true ); }
}
echo '#256 labels, unavailable/deleted/draft retention, escaping, authorization, no writes: PASS (' . $checks256 . " assertions)\n";
