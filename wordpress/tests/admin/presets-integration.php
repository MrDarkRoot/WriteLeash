<?php
// Real WordPress options + Woo + immutable jobs, on all four Admin DB/cache profiles.
use WriteLeash\Free_Admin as PresetAdmin;
use WriteLeash\Price_Preset_Repository as Presets;
use WriteLeash\Price_Preset_Configuration as PresetConfig;
use WriteLeash\Job_Repository as Repo;
use WriteLeash\Price_Operation as Operation;
function preset_post230( string $action, string $id = '', array $extra = array() ): array {
 return array_merge( array( 'preset_action' => $action, 'preset_id' => $id,
  'preset_nonce' => wp_create_nonce( PresetAdmin::ACTION_PRESET . '_' . $action . ( 'save' === $action ? '' : '_' . $id ) ) ), $extra );
}
function preset_call230( string $action, string $id = '', array $extra = array() ): array {
 return PresetAdmin::process_preset( preset_post230( $action, $id, $extra ), 'POST' );
}
wp_set_current_user( 1 );
foreach ( Presets::listing() as $record230 ) { Presets::delete( $record230['id'] ); }
$actor230 = wp_insert_user( array( 'user_login' => 'wl230-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
$other230 = wp_insert_user( array( 'user_login' => 'wl230-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $actor230 );
$id230 = make_product( '100.00', 'publish', array( 'sku' => 'Exact230-' . wp_generate_uuid4() ) );
$sku230 = wc_get_product( $id230 )->get_sku( 'edit' );
$category230 = wp_insert_term( 'Preset230-' . wp_generate_uuid4(), 'product_cat' );
$child230 = wp_insert_term( 'Child230-' . wp_generate_uuid4(), 'product_cat', array( 'parent' => $category230['term_id'] ) );
$child_product230 = make_product( '100.00', 'publish', array( 'category' => $child230['term_id'] ) );
$parent230 = new WC_Product_Variable(); $parent230->set_name( 'Preset parent' ); $parent230->set_status( 'publish' ); $parent230->save();
$variation230 = new WC_Product_Variation(); $variation230->set_parent_id( $parent230->get_id() ); $variation230->set_status( 'publish' ); $variation230->set_regular_price( '120' ); $variation230->save();
$base230 = preview_post( array( 'selector' => 'manual_ids', 'ids' => (string) $id230, 'max_products' => '1000', 'ending' => '99', 'block_zero' => '1' ) );
$writes230 = array(); $sql230 = array();
$watch230 = static function ( $id ) use ( &$writes230 ) { $writes230[] = $id; };
$query230 = static function ( $query ) use ( &$sql230 ) { $sql230[] = $query; return $query; };
add_action( 'woocommerce_before_product_object_save', $watch230 ); add_filter( 'query', $query230 );
try {
 $saved230 = preset_call230( 'save', '', array_merge( $base230, array( 'preset_name' => 'Recurring & safe' ) ) );
 eq( $saved230['status'], 'OK', 'save current form' );
 $record230 = Presets::listing()[0]; $preset_id230 = $record230['id'];
 eq( $record230['creator_id'], $actor230, 'creator stored, not client actor' );
 eq( array_keys( $record230['configuration'] ), array( 'schema', 'selection', 'operation', 'safety' ), 'no authority in storage' );
 $loaded230 = preset_call230( 'load', $preset_id230 ); eq( $loaded230['status'], 'OK', 'load' );
 eq( $loaded230['form']['ending'], '99', 'ending retained' ); eq( $loaded230['form']['block_zero'], '1', 'zero safety retained' );
 eq( preset_call230( 'rename', $preset_id230, array( 'preset_name' => 'Renamed & safe' ) )['status'], 'OK', 'rename' );
 eq( Presets::load( $preset_id230 )['name'], 'Renamed & safe', 'rename durable cache-coherent' );
 eq( preset_call230( 'rename', $preset_id230, array( 'preset_name' => 'Renamed & safe' ) )['status'], 'OK', 'same-name rename idempotent' );
 foreach ( array( 'save', 'load', 'rename', 'delete' ) as $verb230 ) {
  $request230 = preset_post230( $verb230, 'save' === $verb230 ? '' : $preset_id230, array_merge( $base230, array( 'preset_name' => 'Denied' ) ) );
  eq( PresetAdmin::process_preset( $request230, 'GET' )['reason'], 'post_required', 'GET never mutates/loads' );
  $request230['preset_nonce'] = 'bad'; eq( PresetAdmin::process_preset( $request230, 'POST' )['reason'], 'invalid_nonce', 'CSRF rejected ' . $verb230 );
 }
 $wrong_nonce230 = preset_post230( 'load', $preset_id230 ); $wrong_nonce230['preset_nonce'] = wp_create_nonce( PresetAdmin::ACTION_PREVIEW );
 eq( PresetAdmin::process_preset( $wrong_nonce230, 'POST' )['reason'], 'invalid_nonce', 'Preview nonce grants no preset access' );
 wp_set_current_user( $other230 ); eq( Presets::listing(), array(), 'other manager sees no names/configurations' );
 foreach ( array( 'load', 'rename', 'delete' ) as $verb230 ) { eq( preset_call230( $verb230, $preset_id230, array( 'preset_name' => 'Stolen' ) )['reason'], 'preset_unavailable', 'actor isolation ' . $verb230 ); }
 wp_set_current_user( 0 ); eq( preset_call230( 'load', $preset_id230 )['status'], 'FORBIDDEN', 'anonymous denied' );
 wp_set_current_user( $actor230 );
 // Capability fixture setup is independent of the measured preset request.
 remove_filter( 'query', $query230 ); wp_get_current_user()->add_cap( 'manage_woocommerce', false ); add_filter( 'query', $query230 );
 eq( preset_call230( 'load', $preset_id230 )['status'], 'FORBIDDEN', 'revoked capability denied' );
 remove_filter( 'query', $query230 ); wp_get_current_user()->remove_cap( 'manage_woocommerce' ); add_filter( 'query', $query230 );
 $raw230 = get_option( 'writeleash_price_preset_0' ); $bad230 = $raw230; $bad230['configuration']['operation']['input'] = '1';
 update_option( 'writeleash_price_preset_0', $bad230, false );
 eq( preset_call230( 'load', $preset_id230 )['reason'], 'invalid_preset', 'valid arithmetic tamper rejected by signature' );
 update_option( 'writeleash_price_preset_0', $raw230, false );
 foreach ( array(
  array( 'selector' => 'category', 'category' => (string) $category230['term_id'], 'include_subcategories' => '1' ),
  array( 'selector' => 'category', 'category' => (string) $category230['term_id'], 'include_subcategories' => null ),
  array( 'selector' => 'sku', 'sku' => $sku230 ),
  array( 'selector' => 'ids', 'picker_present' => '1', 'product_ids' => array( (string) $variation230->get_id(), (string) $id230 ) ),
 ) as $selection230 ) {
  $input230 = array_merge( $base230, $selection230 ); $capture230 = PresetConfig::capture( $input230 );
  $saved_record230 = Presets::save( 'Selection', $capture230 );
  eq( PresetConfig::capture( preset_call230( 'load', $saved_record230['id'] )['form'] ), $capture230, 'selection reopens exactly' );
  Presets::delete( $saved_record230['id'] );
 }
 // Reused-slot stale deletes cannot remove the new UUID/configuration.
 $old_cas230 = Presets::save( 'Old slot', PresetConfig::capture( $base230 ) );
 $slot230 = null;
 for ( $scan230 = 0; $scan230 < Presets::MAX_PRESETS; ++$scan230 ) { if ( ( get_option( 'writeleash_price_preset_' . $scan230 )['id'] ?? null ) === $old_cas230['id'] ) { $slot230 = $scan230; break; } }
 Presets::delete( $old_cas230['id'] ); $new_cas230 = Presets::save( 'Reused slot', PresetConfig::capture( $base230 ) );
 $cas230 = new ReflectionMethod( Presets::class, 'replace' ); $cas230->setAccessible( true );
 try { $cas230->invoke( null, $slot230, $old_cas230, null ); throw new RuntimeException( 'Stale delete accepted' ); }
 catch ( WriteLeash\Price_Validation_Error $error230 ) { eq( $error230->reason(), 'preset_changed', 'stale conditional delete rejected' ); }
 eq( Presets::load( $new_cas230['id'] )['name'], 'Reused slot', 'new record survives stale delete' ); Presets::delete( $new_cas230['id'] );
 // Inject a competing real save immediately before slot reservation.
 // The first INSERT must ignore that occupied slot, not upsert its creator.
 $raced230 = false; $competing230 = null;
 $race230 = static function ( $query ) use ( &$raced230, &$competing230, $other230, $actor230, $base230 ) {
  if ( ! $raced230 && preg_match( '/^INSERT IGNORE INTO /', $query ) && str_contains( $query, 'writeleash_price_preset_' ) ) {
   $raced230 = true; wp_set_current_user( $other230 );
   try { $competing230 = Presets::save( 'Competing creator', PresetConfig::capture( $base230 ) ); }
   finally { wp_set_current_user( $actor230 ); }
  }
  return $query;
 };
 add_filter( 'query', $race230 );
 try { $reserved230 = Presets::save( 'Original creator', PresetConfig::capture( $base230 ) ); }
 finally { remove_filter( 'query', $race230 ); }
 ok( $raced230, 'real slot race injected' );
 eq( Presets::load( $reserved230['id'] )['creator_id'], $actor230, 'original reservation survives race' );
 wp_set_current_user( $other230 ); eq( Presets::load( $competing230['id'] )['creator_id'], $other230, 'competing creator was not overwritten' );
 Presets::delete( $competing230['id'] ); wp_set_current_user( $actor230 ); Presets::delete( $reserved230['id'] );
 eq( $writes230, array(), 'CRUD performs zero Woo saves' );
 foreach ( $sql230 as $query ) {
  if ( preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $query ) ) {
   ok( str_contains( $query, $wpdb->options ), 'preset mutations touch options only, not job/history/price tables' );
  }
 }
} finally { remove_action( 'woocommerce_before_product_object_save', $watch230 ); remove_filter( 'query', $query230 ); }
// Fresh Preview uses current prices and a distinct independently approvable plan.
$old230 = PresetAdmin::process_preview( $base230, 'POST' ); eq( $old230['status'], 'OK', 'old ordinary Preview' );
$old_job230 = Repo::read_by_public_id( $old230['public_id'] );
$product230 = wc_get_product( $id230 ); $product230->set_regular_price( '110' ); $product230->save();
$form230 = preset_call230( 'load', $preset_id230 )['form']; $form230['amount'] = '70'; $form230['_wpnonce'] = wp_create_nonce( PresetAdmin::ACTION_PREVIEW );
$new230 = PresetAdmin::process_preview( $form230, 'POST' ); eq( $new230['status'], 'OK', 'edit loaded values and Preview fresh' );
$new_job230 = Repo::read_by_public_id( $new230['public_id'] );
ok( $new230['plan_id'] !== $old230['plan_id'] && $new230['plan_hash'] !== $old230['plan_hash'], 'distinct immutable plan identity' );
eq( Repo::read( (int) $old_job230['id'] ), $old_job230, 'old immutable job unchanged' );
$plan230 = Repo::hydrate_plan( $new_job230 ); eq( $plan230->data()['items'][0]['snapshot']['regular_price'], '110', 'fresh snapshot, not saved price target' );
eq( PresetAdmin::process_approve( array( 'job' => $new_job230['public_id'], '_wpnonce' => wp_create_nonce( PresetAdmin::ACTION_APPROVE . '_' . $old_job230['plan_id'] ) ), 'POST' )['reason'], 'invalid_nonce', 'old approval cannot approve fresh Preview' );
foreach ( array( Operation::SET, Operation::CLEAR_SALE, Operation::SALE_DISCOUNT_PERCENT ) as $operation230 ) {
 $sale230 = array_merge( $base230, array( 'price_field' => 'sale_price', 'operation' => $operation230, 'amount' => Operation::CLEAR_SALE === $operation230 ? '' : '20', 'ending' => '95' ) );
 $sale_record230 = Presets::save( 'Sale', PresetConfig::capture( $sale230 ) );
 $sale_form230 = preset_call230( 'load', $sale_record230['id'] )['form']; $sale_form230['_wpnonce'] = wp_create_nonce( PresetAdmin::ACTION_PREVIEW );
 eq( PresetAdmin::process_preview( $sale_form230, 'POST' )['status'], 'OK', 'Sale operation creates ordinary new immutable Preview' );
 Presets::delete( $sale_record230['id'] );
}
$variation_record230 = Presets::save( 'Exact variation', PresetConfig::capture( array_merge( $base230, array( 'ids' => (string) $variation230->get_id() ) ) ) );
$variation_form230 = preset_call230( 'load', $variation_record230['id'] )['form']; $variation_form230['_wpnonce'] = wp_create_nonce( PresetAdmin::ACTION_PREVIEW );
$variation_preview230 = PresetAdmin::process_preview( $variation_form230, 'POST' );
eq( $variation_preview230['status'], 'OK', 'exact variation reopens and previews' );
eq( Repo::hydrate_plan( Repo::read_by_public_id( $variation_preview230['public_id'] ) )->data()['resolved_product_ids'], array( $variation230->get_id() ), 'exact variation does not expand siblings' );
Presets::delete( $variation_record230['id'] );
// Existing 1001-product fixture supplies real boundary proof without duplicate catalog setup.
$limit_config230 = PresetConfig::capture( array_merge( $base230, array( 'ids' => implode( ',', array_slice( $large167, 0, 1000 ) ) ) ) );
$limit_record230 = Presets::save( '1000 exact IDs', $limit_config230 );
$limit_form230 = preset_call230( 'load', $limit_record230['id'] )['form']; $limit_form230['_wpnonce'] = wp_create_nonce( PresetAdmin::ACTION_PREVIEW );
$limit_preview230 = PresetAdmin::process_preview( $limit_form230, 'POST' );
eq( $limit_preview230['status'], 'OK', '1000 loaded exact IDs Preview' );
eq( Repo::hydrate_plan( Repo::read_by_public_id( $limit_preview230['public_id'] ) )->summary()['selected'], 1000, '1000 current exact targets frozen' );
$limit_form230['ids'] .= ',' . end( $large167 );
eq( PresetAdmin::process_preview( $limit_form230, 'POST' )['reason'], 'supported_job_limit_exceeded', 'editing loaded selection to 1001 refused' );
Presets::delete( $limit_record230['id'] );
$limit_record230 = Presets::save( 'Growing category', PresetConfig::capture( array_merge( $base230, array( 'selector' => 'category', 'category' => (string) $boundary206 ) ) ) );
$limit_form230 = preset_call230( 'load', $limit_record230['id'] )['form']; $limit_form230['_wpnonce'] = wp_create_nonce( PresetAdmin::ACTION_PREVIEW );
eq( PresetAdmin::process_preview( $limit_form230, 'POST' )['status'], 'OK', '1000 loaded category targets Preview' );
wp_set_object_terms( end( $large167 ), array( $boundary206 ), 'product_cat' );
eq( PresetAdmin::process_preview( $limit_form230, 'POST' )['reason'], 'supported_job_limit_exceeded', 'fresh category growth to 1001 refused' );
wp_set_object_terms( end( $large167 ), array(), 'product_cat' ); Presets::delete( $limit_record230['id'] );
$deny230 = static function ( $caps, $cap, $actor, $args ) use ( $id230 ) { return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $id230 ? array( 'do_not_allow' ) : $caps; };
add_filter( 'map_meta_cap', $deny230, 10, 4 );
$form230 = preset_call230( 'load', $preset_id230 )['form']; $form230['_wpnonce'] = wp_create_nonce( PresetAdmin::ACTION_PREVIEW );
eq( PresetAdmin::process_preview( $form230, 'POST' )['status'], 'FORBIDDEN', 'fresh Preview rechecks individual product permission' );
remove_filter( 'map_meta_cap', $deny230, 10 );
// A changed SKU does not silently select the old product; loading explains the missing reference.
$sku_record230 = Presets::save( 'Exact SKU', PresetConfig::capture( array_merge( $base230, array( 'selector' => 'sku', 'sku' => $sku230 ) ) ) );
$product230 = wc_get_product( $id230 ); $product230->set_sku( 'Changed230-' . wp_generate_uuid4() ); $product230->save();
eq( preset_call230( 'load', $sku_record230['id'] )['reason'], 'preset_references_changed', 'changed exact SKU explained' );
Presets::delete( $sku_record230['id'] );
// Category growth and missing references are rechecked, never silently importing old targets.
$category_form230 = array_merge( $base230, array( 'selector' => 'category', 'category' => (string) $category230['term_id'], 'include_subcategories' => '1' ) );
$category_record230 = Presets::save( 'Category', PresetConfig::capture( $category_form230 ) );
$category_loaded230 = preset_call230( 'load', $category_record230['id'] )['form']; $category_loaded230['_wpnonce'] = wp_create_nonce( PresetAdmin::ACTION_PREVIEW );
$category_preview230 = PresetAdmin::process_preview( $category_loaded230, 'POST' ); eq( $category_preview230['status'], 'OK', 'subcategory resolves fresh' );
eq( Repo::hydrate_plan( Repo::read_by_public_id( $category_preview230['public_id'] ) )->data()['resolved_product_ids'], array( $child_product230 ), 'subcategory inclusion preserved' );
wp_delete_term( $category230['term_id'], 'product_cat' );
eq( preset_call230( 'load', $category_record230['id'] )['reason'], 'preset_references_changed', 'deleted category explained' );
eq( PresetAdmin::process_preview( $category_loaded230, 'POST' )['reason'], 'invalid_category', 'deleted category Preview refused' );
Presets::delete( $category_record230['id'] );
wp_delete_post( $id230, true );
$missing230 = preset_call230( 'load', $preset_id230 ); eq( $missing230['reason'], 'preset_references_changed', 'deleted exact product explained' );
eq( $missing230['form']['ids'], (string) $id230, 'deleted ID retained for explicit review' );
$missing230['form']['_wpnonce'] = wp_create_nonce( PresetAdmin::ACTION_PREVIEW );
$missing_preview230 = PresetAdmin::process_preview( $missing230['form'], 'POST' );
eq( $missing_preview230['status'], 'OK', 'missing exact ID has truthful unsupported Preview row' );
eq( Repo::hydrate_plan( Repo::read_by_public_id( $missing_preview230['public_id'] ) )->data()['items'][0]['result'], 'UNSUPPORTED', 'missing ID cannot authorize a write' );
// Bounded store-wide capacity, including another creator's private slots.
for ( $n230 = count( Presets::listing() ); $n230 < Presets::MAX_PRESETS; ++$n230 ) { Presets::save( 'Bounded ' . $n230, PresetConfig::capture( $base230 ) ); }
eq( preset_call230( 'save', '', array_merge( $base230, array( 'preset_name' => 'Overflow' ) ) )['reason'], 'preset_limit', '21st preset refused' );
wp_set_current_user( $other230 ); eq( preset_call230( 'save', '', array_merge( $base230, array( 'preset_name' => 'Other actor' ) ) )['reason'], 'preset_limit', 'store cap shared across actors' );
wp_set_current_user( 1 );
foreach ( Presets::listing() as $record230 ) { eq( preset_call230( 'delete', $record230['id'] )['status'], 'OK', 'administrator delete respects ownership override' ); }
eq( Presets::listing(), array(), 'all slots deleted' );
eq( Repo::read( (int) $new_job230['id'] ), $new_job230, 'deletion leaves existing job and plan untouched' );
marker( '#230 preset CRUD, actor/nonce/integrity/no-write/current-Preview and DB/cache durability' );
