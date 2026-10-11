<?php
// Combined #230/#232/#205/#231/#234 workflows on real Woo/options/durable tables.
use WriteLeash\Free_Admin as CA241;
use WriteLeash\Price_Preset_Configuration as CC241;
use WriteLeash\Price_Preset_Repository as CP241;
use WriteLeash\Job_Repository as CJ241;
use WriteLeash\Job_Schema as CS241;
use WriteLeash\Price_Apply_Journal as CL241;
use WriteLeash\Change_Plan as Plan241;
global $wpdb, $combo_checks241;
$combo_checks241 = 0;
function ce241( $actual, $expected, string $label ): void { global $combo_checks241; ++$combo_checks241; if ( $actual !== $expected ) { throw new RuntimeException( $label . ': ' . wp_json_encode( array( $actual, $expected ) ) ); } }
function product241( string $price, string $type = 'WC_Product_Simple', int $parent = 0 ): int {
 $p = new $type(); $p->set_name( 'Combined ' . wp_generate_uuid4() ); $p->set_status( 'publish' ); if ( $parent ) { $p->set_parent_id( $parent ); } $p->set_regular_price( $price ); $p->save(); return $p->get_id();
}
function form241( array $ids ): array { return array( 'selector' => 'manual_ids', 'ids' => implode( ',', $ids ), 'operation' => 'INCREASE_PERCENT', 'price_field' => 'regular_price', 'amount' => '5', 'ending' => 'default', 'max_products' => '1000', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '20', 'block_zero' => '1', '_wpnonce' => wp_create_nonce( CA241::ACTION_PREVIEW ) ); }
function preview241( array $form ): array { $r = CA241::process_preview( $form, 'POST' ); ce241( $r['status'], 'OK', 'combined Preview ' . wp_json_encode( $r ) ); return CJ241::read_by_public_id( $r['public_id'] ); }
function repeat241( array $job ): array { return array_merge( CA241::repeat_form_values( CA241::repeat_source( $job['public_id'] )['plan']->data() ), array( 'repeat' => '1', 'source_job' => $job['public_id'], '_wpnonce' => wp_create_nonce( CA241::ACTION_PREVIEW ) ) ); }
function approve241( array $job ): array { return array( 'job' => $job['public_id'], '_wpnonce' => wp_create_nonce( CA241::ACTION_APPROVE . '_' . $job['plan_id'] ) ); }
function apply241( array $job ): void {
 ce241( CA241::process_approve( approve241( $job ), 'POST' )['status'], 'OK', 'independent approval' );
 for ( $i = 0; $i < 110; ++$i ) { if ( WriteLeash\Job_State::is_terminal( CJ241::read( (int) $job['id'] )['status'] ) ) { return; } WriteLeash\Job_Worker::run( (int) $job['id'], array( 'max_items' => 10, 'budget_seconds' => 20 ), true ); }
 throw new RuntimeException( 'Combined Apply not terminal' );
}
function price241( int $id ): string { return WriteLeash\Price_Decimal::parse( WriteLeash\Product_Price_Snapshot::fresh_product( $id )->get_regular_price( 'edit' ) ); }
function edit241( int $id, string $price ): void { $p = WriteLeash\Product_Price_Snapshot::fresh_product( $id ); $p->set_regular_price( $price ); $p->save(); }
function html241( string $view, array $job, array $form = array() ): string { ob_start(); CA241::render_view( $view, $job['public_id'], 0, $form ); return ob_get_clean(); }
function slots241(): int { global $wpdb; return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'writeleash_price_preset_%'" ); }
function saved241( array $job ): string {
 global $wpdb;
 $table = WriteLeash\Undo_Schema::operations_table( $wpdb );
 $exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
 $undo = $exists ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE job_id=%d ORDER BY id', $table, $job['id'] ), ARRAY_A ) : array(); $undo_items = array();
 foreach ( $undo as $op ) { $undo_items[] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE undo_id=%d ORDER BY id', WriteLeash\Undo_Schema::items_table( $wpdb ), $op['id'] ), ARRAY_A ); }
 return wp_json_encode( array( CJ241::read( (int) $job['id'] ), CJ241::items( (int) $job['id'] ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE plan_id=%s ORDER BY product_id', CL241::table( $wpdb ), $job['plan_id'] ), ARRAY_A ), $undo, $undo_items ) );
}
function preset241( string $verb, string $id = '', array $form = array() ): array { return CA241::process_preset( array_merge( $form, array( 'preset_action' => $verb, 'preset_id' => $id, 'preset_nonce' => wp_create_nonce( CA241::ACTION_PRESET . '_' . $verb . ( 'save' === $verb ? '' : '_' . $id ) ) ) ), 'POST' ); }
wp_set_current_user( 1 ); ce241( slots241(), 0, 'fresh combined fixture has no presets' );
$actor241 = wp_insert_user( array( 'user_login' => 'combo-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) ); wp_set_current_user( $actor241 );

// A: persisted configuration -> Apply -> actual History link -> independent Repeat.
$a241 = product241( '100' ); $input241 = form241( array( $a241 ) );
ce241( preset241( 'save', '', $input241 + array( 'preset_name' => 'Combined preset' ) )['status'], 'OK', 'native preset save' );
$preset241 = CP241::listing()[0]; $configuration241 = $preset241['configuration'];
$loaded241 = preset241( 'load', $preset241['id'] )['form']; ce241( isset( $loaded241['source_job'] ) || isset( $loaded241['repeat'] ), false, 'preset imports no historical routing authority' );
$loaded241['_wpnonce'] = wp_create_nonce( CA241::ACTION_PREVIEW ); $loaded241['amount'] = '5'; $source241 = preview241( $loaded241 ); apply241( $source241 ); ce241( price241( $a241 ), '105', 'first current price applied' );
$source_undo241 = WriteLeash\Undo_Repository::initiate( (int) $source241['id'], $actor241 );
ce241( (int) $source_undo241['id'] > 0, true, 'real historical Undo records exist for immutability checks' );
$original241 = saved241( $source241 );
ce241( str_contains( html241( 'history', $source241 ), 'wl_view=repeat' ), true, 'History offers Repeat navigation' );
$repeat_html241 = html241( 'repeat', $source241 );
ce241( str_contains( $repeat_html241, 'Save as preset' ), false, 'Repeat is not a preset Save form' );
ce241( str_contains( $repeat_html241, 'name="preset_nonce"' ), false, 'Repeat has no preset nonce' );
$repeat_form241 = repeat241( $source241 ); ce241( $repeat_form241['amount'], '5', 'operation prefilled' ); ce241( $repeat_form241['max_increase'], '50', 'safety prefilled' );
$next241 = preview241( $repeat_form241 ); $plan241 = CJ241::hydrate_plan( $next241 );
ce241( $plan241->item( $a241 )->data()['snapshot']['regular_price'], '105.00', 'Repeat reads current Woo prices' );
ce241( $plan241->item( $a241 )->data()['planned_regular_price'], '110.25', 'fresh exact computation' );
ce241( $next241['plan_id'] !== $source241['plan_id'] && $next241['plan_hash'] !== $source241['plan_hash'], true, 'distinct frozen identity and hash' );
ce241( $plan241->source_kind(), Plan241::SOURCE_REPEAT, 'frozen Repeat kind' );
$old_nonce241 = approve241( $source241 ); $old_nonce241['job'] = $next241['public_id']; ce241( CA241::process_approve( $old_nonce241, 'POST' )['reason'], 'invalid_nonce', 'historical approval not reusable' );
ce241( saved241( $source241 ), $original241, 'opening and Preview preserve source/Journal/Undo bytes' );
apply241( $next241 ); ce241( price241( $a241 ), '110.25', 'Repeat requires and uses new approval' ); ce241( saved241( $source241 ), $original241, 'new Apply preserves historical bytes' );
ce241( slots241(), 1, 'Repeat creates no additional preset' ); ce241( CP241::load( $preset241['id'] )['configuration'], $configuration241, 'preset unchanged by Repeat' );

// B: same installation with zero presets; Repeat remains independent.
CP241::delete( $preset241['id'] ); ce241( slots241(), 0, 'no presets' );
$b241 = product241( '100' ); $normal241 = preview241( form241( array( $b241 ) ) ); apply241( $normal241 );
$no_preset241 = preview241( repeat241( $normal241 ) ); apply241( $no_preset241 ); ce241( price241( $b241 ), '110.25', 'zero-preset Repeat works' ); ce241( slots241(), 0, 'no preset option created' );

// C: range preset -> refined frozen exclusions -> Apply -> included-only Repeat.
$ids241 = array( product241( '100' ), product241( '110' ), product241( '200' ) );
$range_form241 = form241( $ids241 ) + array( 'range_enabled' => '1', 'range_min' => '100', 'range_max' => '120', 'range_basis' => 'regular_price' );
$ranged241 = CP241::save( 'Ranged configuration', CC241::capture( $range_form241 ) );
$range_loaded241 = preset241( 'load', $ranged241['id'] )['form']; $range_loaded241['_wpnonce'] = wp_create_nonce( CA241::ACTION_PREVIEW );
$candidate241 = preview241( $range_loaded241 ); ce241( CJ241::hydrate_plan( $candidate241 )->data()['resolved_product_ids'], array_slice( $ids241, 0, 2 ), 'persisted inclusive range membership' );
$refinement241 = CA241::process_refine( array( 'job' => $candidate241['public_id'], '_wpnonce' => wp_create_nonce( CA241::ACTION_REFINE . '_' . $candidate241['public_id'] ), 'rows' => array( (string) $ids241[1] ), 'refinement_action' => 'exclude' ), 'POST' ); ce241( $refinement241['status'], 'OK', 'existing refinement' );
$refined241 = CJ241::read_by_public_id( $refinement241['public_id'] ); $refined_plan241 = CJ241::hydrate_plan( $refined241 );
ce241( $refined_plan241->data()['resolved_product_ids'], array( $ids241[0] ), 'only included ID frozen' ); ce241( $refined_plan241->data()['selection_refinement']['excluded_ids'], array( $ids241[1] ), 'individual exclusion frozen' );
$excluded_saves241 = 0; $watch241 = static function ( $p ) use ( &$excluded_saves241, $ids241 ) { if ( in_array( $p->get_id(), array_slice( $ids241, 1 ), true ) ) { ++$excluded_saves241; } };
add_action( 'woocommerce_before_product_object_save', $watch241 );
try {
 apply241( $refined241 ); $refined_bytes241 = saved241( $refined241 );
 $refined_repeat241 = repeat241( $refined241 ); ce241( $refined_repeat241['ids'], (string) $ids241[0], 'Repeat suggests only previously included IDs' );
 ce241( str_contains( html241( 'repeat', $refined241 ), 'excluded 1 product' ), true, 'Repeat explains previous exclusions' );
 $new_refined241 = preview241( $refined_repeat241 ); apply241( $new_refined241 );
 ce241( $excluded_saves241, 0, 'range and individually excluded products never saved' );
 foreach ( array( $refined241, $new_refined241 ) as $j241 ) { foreach ( array_slice( $ids241, 1 ) as $id241 ) { ce241( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE plan_id=%s AND product_id=%d', CL241::table( $wpdb ), $j241['plan_id'], $id241 ) ), 0, 'excluded product has no mutation journal' ); } }
 ce241( saved241( $refined241 ), $refined_bytes241, 'Repeat preserves refined history' ); ce241( CP241::load( $ranged241['id'] ), $ranged241, 'ordinary ranged preset unchanged' );
} finally { remove_action( 'woocommerce_before_product_object_save', $watch241 ); }

// D: same conflicted source, two immutable workflow classifications.
$d241 = product241( '100' ); $conflicted241 = preview241( form241( array( $d241 ) ) ); edit241( $d241, '120' ); apply241( $conflicted241 );
ce241( CJ241::items( (int) $conflicted241['id'] )[0]['state'], 'CONFLICT', 'actual source Apply conflict' );
$recovery_form241 = form241( array( $d241 ) ) + array( 'source_job' => $conflicted241['public_id'], 'product_ids' => array( (string) $d241 ) );
$recovery241 = preview241( $recovery_form241 ); $repeated241 = preview241( repeat241( $conflicted241 ) );
ce241( CJ241::hydrate_plan( $recovery241 )->source_kind(), 'recovery', 'Recovery classified at creation' ); ce241( CJ241::hydrate_plan( $repeated241 )->source_kind(), 'repeat', 'Repeat classified at creation' );
$wpdb->update( CS241::items_table( $wpdb ), array( 'state' => 'APPLIED', 'reason' => 'APPLIED' ), array( 'job_id' => $conflicted241['id'], 'product_id' => $d241 ) );
ce241( str_contains( html241( 'preview', $recovery241 ), 'Fresh Preview from chosen Apply conflicts' ), true, 'Recovery copy survives changing source conflicts' );
ce241( str_contains( html241( 'preview', $repeated241 ), 'repeated from a previous job' ), true, 'Repeat copy survives changing source conflicts' );
$tampered241 = CJ241::hydrate_plan( $repeated241 )->data(); $tampered241['source_kind'] = 'recovery';
try { Plan241::hydrate( $tampered241 ); throw new RuntimeException( 'kind tamper accepted' ); } catch ( WriteLeash\Price_Validation_Error $e241 ) { ce241( $e241->reason(), 'plan_hash_mismatch', 'valid-kind tamper changes hash' ); }
$tampered241['source_kind'] = 'unknown';
try { Plan241::hydrate( $tampered241 ); throw new RuntimeException( 'malformed kind accepted' ); } catch ( WriteLeash\Price_Validation_Error $e241 ) { ce241( $e241->reason(), 'invalid_source_job', 'malformed kind refused' ); }
$outside_recovery241 = $recovery_form241; $outside_recovery241['product_ids'] = array( (string) $b241 ); ce241( CA241::process_preview( $outside_recovery241, 'POST' )['status'], 'INVALID', 'Recovery cannot expand to unrelated products' );

// E: actor, capability, nonce and both storage trust boundaries.
$other241 = wp_insert_user( array( 'user_login' => 'combo-other-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $other241 ); ce241( preset241( 'load', $ranged241['id'] )['reason'], 'preset_unavailable', 'cross-actor preset denied' );
ce241( CA241::process_preview( array_merge( form241( array( $a241 ) ), array( 'source_job' => $source241['public_id'], 'repeat' => '1' ) ), 'POST' )['status'], 'FORBIDDEN', 'cross-actor historical source denied' );
ce241( str_contains( html241( 'repeat', $source241 ), 'Original operation:' ), false, 'unauthorized Repeat discloses no historical configuration' ); wp_set_current_user( $actor241 );
$bad_nonce241 = repeat241( $source241 ); $bad_nonce241['_wpnonce'] = wp_create_nonce( CA241::ACTION_PRESET . '_save' ); ce241( CA241::process_preview( $bad_nonce241, 'POST' )['reason'], 'invalid_nonce', 'preset nonce cannot authorize Repeat' );
ce241( CA241::process_preset( array( 'preset_action' => 'load', 'preset_id' => $ranged241['id'], 'preset_nonce' => wp_create_nonce( CA241::ACTION_PREVIEW ) ), 'POST' )['reason'], 'invalid_nonce', 'Preview nonce cannot authorize preset Load' );
ce241( preset241( 'save', '', repeat241( $source241 ) + array( 'preset_name' => 'Unwanted historical preset' ) )['reason'], 'invalid_preset', 'historical routing cannot create a preset' );
$u241 = wp_get_current_user(); $u241->add_cap( 'manage_woocommerce', false );
ce241( preset241( 'load', $ranged241['id'] )['status'], 'FORBIDDEN', 'revoked preset capability' );
ce241( CA241::process_preview( $repeat_form241, 'POST' )['status'], 'FORBIDDEN', 'revoked Repeat capability' ); $u241->remove_cap( 'manage_woocommerce' );
$deny241 = static function ( $caps, $cap, $actor, $args ) use ( $ids241 ) { return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $ids241[2] ? array( 'do_not_allow' ) : $caps; };
add_filter( 'map_meta_cap', $deny241, 10, 4 );
try { ce241( CA241::process_preview( array_merge( $range_form241, array( 'source_job' => $candidate241['public_id'], 'repeat' => '1' ) ), 'POST' )['status'], 'FORBIDDEN', 'Repeat authorizes entire source before range filtering' ); } finally { remove_filter( 'map_meta_cap', $deny241, 10 ); }
$slot241 = null; for ( $i241 = 0; $i241 < 20; ++$i241 ) { if ( ( get_option( 'writeleash_price_preset_' . $i241 )['id'] ?? null ) === $ranged241['id'] ) { $slot241 = $i241; break; } }
$raw241 = get_option( 'writeleash_price_preset_' . $slot241 ); $bad241 = $raw241; $bad241['configuration']['operation']['input'] = '7'; update_option( 'writeleash_price_preset_' . $slot241, $bad241, false );
ce241( preset241( 'load', $ranged241['id'] )['reason'], 'invalid_preset', 'tampered preset HMAC denied' ); update_option( 'writeleash_price_preset_' . $slot241, $raw241, false );
$wpdb->update( CS241::jobs_table( $wpdb ), array( 'plan_json' => str_replace( 'INCREASE_PERCENT', 'DECREASE_PERCENT', $source241['plan_json'] ) ), array( 'id' => $source241['id'] ) );
ce241( CA241::process_preview( $repeat_form241, 'POST' )['reason'], 'repeat_unavailable', 'tampered historical material denied' ); ce241( str_contains( html241( 'repeat', $source241 ), 'Original operation:' ), false, 'tampered historical details not leaked' );
$wpdb->update( CS241::jobs_table( $wpdb ), array( 'plan_json' => $source241['plan_json'] ), array( 'id' => $source241['id'] ) );

// F: Repeat boundary alongside presets; the existing suites retain real 1000/1001,
// concurrent reservation, reused-slot CAS, missing/catalog drift and variation tests.
$limit241 = repeat241( $source241 ); $limit241['ids'] = implode( ',', range( 9000001, 9001001 ) ); ce241( CA241::process_preview( $limit241, 'POST' )['reason'], 'supported_job_limit_exceeded', 'Repeat 1001 refused with preset present' );
$parent241 = product241( '100', 'WC_Product_Variable' ); $variation241 = product241( '100', 'WC_Product_Variation', $parent241 );
$variable_preset241 = CP241::save( 'Parent expansion', CC241::capture( form241( array( $parent241 ) ) ) ); $vf241 = CC241::form( $variable_preset241['configuration'] ); $vf241['_wpnonce'] = wp_create_nonce( CA241::ACTION_PREVIEW );
$vjob241 = preview241( $vf241 ); apply241( $vjob241 ); $vnext241 = preview241( repeat241( $vjob241 ) ); ce241( CJ241::hydrate_plan( $vnext241 )->data()['resolved_product_ids'], array( $variation241 ), 'parent preset followed by explicit included-variation Repeat' ); CP241::delete( $variable_preset241['id'] );
CP241::delete( $ranged241['id'] ); ce241( slots241(), 0, 'combined CRUD leaves no preset options' );
echo '#241+#242 combined real-Woo workflows: PASS (' . $combo_checks241 . ' assertions; PHP=' . PHP_VERSION . ', DB=' . DB_HOST . ', cache=' . ( wp_using_ext_object_cache() ? 'Redis' : 'default' ) . ")\n";
