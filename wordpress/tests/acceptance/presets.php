<?php
// Same public configuration pipeline on each supported PHP/WP/Woo/DB/cache profile.
use WriteLeash\Free_Admin as PresetAdmin112;
use WriteLeash\Price_Preset_Repository as Presets112;
use WriteLeash\Price_Preset_Configuration as Config112;
wp_set_current_user( 1 );
if ( ! PresetAdmin112::dependency_ok() ) {
 $refused230 = PresetAdmin112::process_preset( array( 'preset_action' => 'save', 'preset_nonce' => wp_create_nonce( PresetAdmin112::ACTION_PRESET . '_save' ) ), 'POST' );
 if ( 'woocommerce_version_unsupported' !== $refused230['reason'] ) { throw new RuntimeException( 'Unsupported Woo preset operation accepted' ); }
 echo "#230 acceptance presets: unsupported Woo refusal PASS\n";
 return;
}

$product230 = new WC_Product_Simple(); $product230->set_name( 'Acceptance preset' ); $product230->set_status( 'publish' ); $product230->set_regular_price( '100' ); $product230->save();
$maximum230 = new WC_Product_Simple(); $maximum230->set_name( 'Preset inclusive maximum' ); $maximum230->set_status( 'publish' ); $maximum230->set_regular_price( '150' ); $maximum230->save();
$outside230 = new WC_Product_Simple(); $outside230->set_name( 'Preset outside range' ); $outside230->set_status( 'publish' ); $outside230->set_regular_price( '150.01' ); $outside230->save();
$input230 = array( 'selector' => 'manual_ids', 'ids' => (string) $product230->get_id(), 'operation' => 'DECREASE_PERCENT', 'amount' => '10', 'ending' => '99', 'price_field' => 'regular_price', 'max_products' => '1000', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '20', 'block_zero' => '1' );
$saves230 = 0; $observer230 = static function () use ( &$saves230 ) { ++$saves230; };
add_action( 'woocommerce_before_product_object_save', $observer230 );
try {
 $config230 = Config112::capture( $input230 ); $preset230 = Presets112::save( 'Acceptance & preset', $config230 );
 $loaded230 = Config112::form( Presets112::load( $preset230['id'] )['configuration'] );
 if ( Config112::capture( $loaded230 ) !== $config230 ) { throw new RuntimeException( 'Preset configuration drift' ); }
 Presets112::rename( $preset230['id'], 'Renamed' );
 if ( 'Renamed' !== Presets112::load( $preset230['id'] )['name'] ) { throw new RuntimeException( 'Preset rename cache drift' ); }
 $loaded230['_wpnonce'] = wp_create_nonce( PresetAdmin112::ACTION_PREVIEW );
 $preview230 = PresetAdmin112::process_preview( $loaded230, 'POST' );
 if ( 'OK' !== $preview230['status'] ) { throw new RuntimeException( 'Preset fresh Preview: ' . json_encode( $preview230 ) ); }
 $job230 = WriteLeash\Job_Repository::read_by_public_id( $preview230['public_id'] );
 Presets112::delete( $preset230['id'] );
 if ( $saves230 || WriteLeash\Job_Repository::read( (int) $job230['id'] ) !== $job230 ) { throw new RuntimeException( 'Preset operation mutated prices or job' ); }
 $ranged230 = array_merge( $input230, array( 'ids' => implode( ',', array( $product230->get_id(), $maximum230->get_id(), $outside230->get_id() ) ), 'range_enabled' => '1', 'range_min' => '100', 'range_max' => '150', 'range_basis' => 'regular_price' ) );
 $preset230 = Presets112::save( 'Acceptance ranged preset', Config112::capture( $ranged230 ) );
 $loaded230 = Config112::form( Presets112::load( $preset230['id'] )['configuration'] ); $loaded230['_wpnonce'] = wp_create_nonce( PresetAdmin112::ACTION_PREVIEW );
 $preview230 = PresetAdmin112::process_preview( $loaded230, 'POST' );
 if ( 'OK' !== $preview230['status'] ) { throw new RuntimeException( 'Ranged preset Preview refused' ); }
 $root230 = WriteLeash\Job_Repository::read_by_public_id( $preview230['public_id'] );
 if ( WriteLeash\Job_Repository::hydrate_plan( $root230 )->data()['resolved_product_ids'] !== array( $product230->get_id(), $maximum230->get_id() ) ) { throw new RuntimeException( 'Persisted range broadened or lost inclusive boundary' ); }
 $refined230 = PresetAdmin112::process_refine( array( 'job' => $root230['public_id'], '_wpnonce' => wp_create_nonce( PresetAdmin112::ACTION_REFINE . '_' . $root230['public_id'] ), 'rows' => array( (string) $maximum230->get_id() ), 'refinement_action' => 'exclude' ), 'POST' );
 if ( 'OK' !== $refined230['status'] ) { throw new RuntimeException( 'Ranged preset refinement refused' ); }
 $job230 = WriteLeash\Job_Repository::read_by_public_id( $refined230['public_id'] ); $plan230 = WriteLeash\Job_Repository::hydrate_plan( $job230 );
 if ( $plan230->data()['resolved_product_ids'] !== array( $product230->get_id() ) || $plan230->data()['selection_refinement']['excluded_ids'] !== array( $maximum230->get_id() ) || (int) $job230['approver_id'] !== 0 ) { throw new RuntimeException( 'Refined preset membership/approval drift' ); }
 Presets112::load( $preset230['id'] ); Presets112::delete( $preset230['id'] );
 if ( $saves230 || WriteLeash\Job_Repository::read( (int) $job230['id'] ) !== $job230 ) { throw new RuntimeException( 'Preset reuse changed refined plan or prices' ); }
} finally { remove_action( 'woocommerce_before_product_object_save', $observer230 ); }
echo '#230 acceptance presets: PHP=' . PHP_VERSION . ' WP=' . get_bloginfo( 'version' ) . ' Woo=' . WC_VERSION . ' DB=' . DB_HOST . ' cache=' . ( wp_using_ext_object_cache() ? 'Redis' : 'default' ) . " CRUD/configuration/range/refinement/fresh-Preview/no-write PASS\n";
