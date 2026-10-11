<?php
/** #232 real Woo/DB repeat-as-new-Preview, using the disposable Admin matrix. */
use WriteLeash\Free_Admin as A;
use WriteLeash\Job_Repository as R;
use WriteLeash\Job_Worker as W;
use WriteLeash\Price_Decimal as D;
use WriteLeash\Price_Cache_Verifier as V;
use WriteLeash\Job_Schema as S;

global $wpdb;
$n = 0;
$eq = static function ( $a, $b, $label ) use ( &$n ) { ++$n; if ( $a !== $b ) { throw new RuntimeException( "$label: " . wp_json_encode( array( $a, $b ) ) ); } };
$create = static function ( $price = '100', $class = 'WC_Product_Simple', $parent = 0 ) {
 $p = new $class(); $p->set_name( 'Repeat ' . wp_generate_uuid4() ); $p->set_status( 'publish' );
 if ( $parent ) { $p->set_parent_id( $parent ); }
 $p->set_regular_price( $price ); $p->save(); return $p->get_id();
};
$edit = static function ( $id, $price, $field = 'regular_price' ) { V::invalidate( $id ); $p = wc_get_product( $id ); $p->{'set_' . $field}( $price ); $p->save(); V::invalidate( $id ); };
$price = static function ( $id ) { V::invalidate( $id ); return D::parse( wc_get_product( $id )->get_regular_price( 'edit' ) ); };
$post = static function ( $source, array $extra = array() ) {
 return array_merge( array( '_wpnonce' => wp_create_nonce( A::ACTION_PREVIEW ), 'selector' => 'manual_ids', 'ids' => '', 'sku' => null, 'category' => null, 'include_subcategories' => null, 'range_enabled' => null, 'range_min' => null, 'range_max' => null, 'range_basis' => null, 'source_job' => $source, 'repeat' => '1', 'operation' => 'INCREASE_PERCENT', 'price_field' => 'regular_price', 'amount' => '5', 'ending' => 'default', 'max_products' => '1000', 'max_increase' => '50', 'max_decrease' => '50', 'warning_threshold' => '20', 'block_zero' => '1' ), $extra );
};
$preview = static function ( $input ) use ( $eq ) { $r = A::process_preview( $input, 'POST' ); $eq( $r['status'], 'OK', 'preview: ' . wp_json_encode( $r ) ); return R::read( $r['job_id'] ); };
$apply = static function ( $job ) use ( $eq ) {
 $r = A::process_approve( array( 'job' => $job['public_id'], '_wpnonce' => wp_create_nonce( A::ACTION_APPROVE . '_' . $job['plan_id'] ) ), 'POST' ); $eq( $r['status'], 'OK', 'explicit approval' );
 for ( $i = 0; $i < 110; ++$i ) { if ( WriteLeash\Job_State::is_terminal( R::read( (int) $job['id'] )['status'] ) ) { return; } W::run( (int) $job['id'], array( 'max_items' => 10, 'budget_seconds' => 20 ), true ); }
 throw new RuntimeException( 'Apply did not finish' );
};
$render = static function ( $view, $job, $form = array() ) { ob_start(); A::render_view( $view, $job['public_id'], 0, $form ); return ob_get_clean(); };
$saved = static function ( $job ) use ( $wpdb ) {
 $id = (int) $job['id']; $plan_id = $job['plan_id'];
 return wp_json_encode( array( R::read( $id ), R::items( $id ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE plan_id=%s ORDER BY product_id', WriteLeash\Price_Apply_Journal::table( $wpdb ), $plan_id ), ARRAY_A ) ) );
};
$actor = wp_insert_user( array( 'user_login' => 'repeat-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $actor );

// 1. Authorized history entry: editable prefill, no mutation on open.
$a = $create(); $b = $create();
$source = $preview( $post( null, array( 'selector' => 'ids', 'picker_present' => '1', 'product_ids' => array( (string) $a, (string) $b ) ) ) );
$apply( $source );
$eq( $price( $a ), '105', 'first +5% applied' );
$original = $saved( $source );
$html = $render( 'repeat', $source );
foreach ( array( 'Repeat as new Preview', 'Based on previous job', $source['public_id'], 'Original operation:', 'not Undo, Resume', 'name="source_job"', 'name="repeat" value="1"', 'name="operation"', 'INCREASE_PERCENT', 'name="amount"', 'value="5"', 'Preview price changes', 'Cancel and return to results', 'View original results' ) as $needle ) {
 $eq( str_contains( $html, $needle ), true, 'repeat view: ' . $needle );
}
$eq( str_contains( $html, 'writeleash-conflict-recovery-form' ), false, 'repeat uses the full selector form, not conflict checkboxes' );
$eq( str_contains( $html, 'Choose Apply-conflict products' ), false, 'no conflict row selection in repeat' );
$eq( $saved( $source ), $original, 'opening repeat preserves durable source' );
$eq( $price( $a ), '105', 'opening writes no prices' );
$eq( str_contains( $render( 'job', $source ), 'Repeat as new Preview' ), true, 'job detail entry point' );
$job_history = $render( 'history', $source );
$eq( str_contains( $job_history, 'Repeat as new Preview' ), true, 'history entry point' );

// 2. Fresh Preview computes from current prices with distinct identity.
$second = $preview( $post( $source['public_id'], array( 'ids' => $a . ',' . $b ) ) );
$plan = R::hydrate_plan( $second );
$eq( $plan->item( $a )->data()['planned_regular_price'], '110.25', '105 + 5 percent from the current price' );
$eq( $plan->data()['source_job'], $source['public_id'], 'bounded provenance, not authority' );
$eq( $second['plan_id'] !== $source['plan_id'] && $second['public_id'] !== $source['public_id'], true, 'distinct plan and job identity' );
$eq( $price( $a ), '105', 'new Preview has no price side effect' );
$eq( $saved( $source ), $original, 'new Preview preserves original records' );
$preview_html = $render( 'preview', $second );
$eq( str_contains( $preview_html, 'repeated from a previous job' ), true, 'repeat provenance copy on the new Preview' );
$eq( str_contains( $preview_html, 'Fresh Preview from chosen Apply conflicts' ), false, 'repeat is not mislabelled as conflict recovery' );
$apply( $second );
$eq( $price( $a ), '110.25', 'independently approved repeat applies' );
$eq( $saved( $source ), $original, 'new Apply preserves original records' );
$eq( WriteLeash\Undo_Repository::history_job( (int) $source['id'] )['undo_eligible'], true, 'source Undo eligibility unchanged' );

// 3. A later Repeat chains from the then-current price, never a stale target.
$third = $preview( $post( $second['public_id'], array( 'ids' => (string) $a ) ) );
$eq( R::hydrate_plan( $third )->item( $a )->data()['planned_regular_price'], '115.76', '110.25 + 5 percent' );
$eq( $third['plan_id'] !== $second['plan_id'], true, 'third distinct plan' );

// 4. Post-Preview drift yields ordinary CONFLICT, no blind overwrite.
$stale = $preview( $post( $second['public_id'], array( 'ids' => (string) $a ) ) );
$edit( $a, '200' );
$apply( $stale );
$eq( R::items( (int) $stale['id'] )[0]['state'], 'CONFLICT', 'stale repeat conflicts' );
$eq( $price( $a ), '200', 'later edit preserved' );

// 5. Cross-actor and anonymous access refused without disclosure.
$other = wp_insert_user( array( 'user_login' => 'repeat-other-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $other );
$eq( A::process_preview( $post( $source['public_id'], array( 'ids' => (string) $b ) ), 'POST' )['status'], 'FORBIDDEN', 'different actor refused' );
$other_html = $render( 'repeat', $source );
$eq( str_contains( $other_html, 'Original operation:' ), false, 'no job details disclosed' );
$eq( str_contains( $other_html, 'No repeatable configuration is visible to your account' ), true, 'typed refusal' );
wp_set_current_user( 0 );
$eq( A::process_preview( $post( $source['public_id'], array( 'ids' => (string) $b ) ), 'POST' )['status'], 'FORBIDDEN', 'anonymous refused' );
wp_set_current_user( $actor );

// 6. Transport and format negatives.
$eq( A::process_preview( $post( $source['public_id'], array( 'ids' => (string) $b ) ), 'GET' )['reason'], 'post_required', 'POST only' );
$eq( A::process_preview( $post( $source['public_id'], array( 'ids' => (string) $b, '_wpnonce' => 'invalid' ) ), 'POST' )['reason'], 'invalid_nonce', 'nonce' );
$eq( A::process_preview( $post( 'forged', array( 'ids' => (string) $b ) ), 'POST' )['reason'], 'repeat_unavailable', 'forged source' );
$eq( A::process_preview( $post( wp_generate_uuid4(), array( 'ids' => (string) $b ) ), 'POST' )['status'], 'FORBIDDEN', 'missing job indistinguishable' );
$user = wp_get_current_user(); $user->add_cap( 'manage_woocommerce', false );
$eq( A::process_preview( $post( $source['public_id'], array( 'ids' => (string) $b ) ), 'POST' )['reason'], 'capability_required', 'revoked capability grants no inherited authorization' );
$user->remove_cap( 'manage_woocommerce' );

// 7. Tampered historical plan refuses safely with no prefilled operation.
$tamper = $preview( $post( null, array( 'selector' => 'ids', 'picker_present' => '1', 'product_ids' => array( (string) $b ) ) ) );
$wpdb->update( S::jobs_table( $wpdb ), array( 'plan_json' => substr_replace( $tamper['plan_json'], 'X', 40, 1 ) ), array( 'id' => $tamper['id'] ) );
$eq( A::process_preview( $post( $tamper['public_id'], array( 'ids' => (string) $b ) ), 'POST' )['reason'], 'repeat_unavailable', 'tampered plan refused' );
$tamper_html = $render( 'repeat', R::read( (int) $tamper['id'] ) );
$eq( str_contains( $tamper_html, 'Original operation:' ), false, 'no prefilled unsafe operation' );
$tamper_history = $render( 'history', $tamper );
$eq( str_contains( $tamper_history, 'Saved task details unavailable' ), true, 'tampered rows stay plain history without a repeat entry' );

// 8. Current per-product authorization enforced on the fresh population.
$deny = static function ( $caps, $cap, $uid, $args ) use ( $b ) { return 'edit_post' === $cap && ( $args[0] ?? 0 ) === $b ? array( 'do_not_allow' ) : $caps; };
add_filter( 'map_meta_cap', $deny, 10, 4 );
$eq( A::process_preview( $post( $source['public_id'], array( 'ids' => $a . ',' . $b ) ), 'POST' )['status'], 'FORBIDDEN', 'product authorization drift refused' );
remove_filter( 'map_meta_cap', $deny, 10 );

// 9. Category membership drift resolves current members into a new frozen plan.
$term = wp_insert_term( 'Repeat ' . wp_generate_uuid4(), 'product_cat' )['term_id'];
$c1 = $create(); $c2 = $create();
wp_set_object_terms( $c1, array( $term ), 'product_cat' );
$cat_source = $preview( $post( null, array( 'selector' => 'category', 'category' => (string) $term ) ) );
$cat_html = $render( 'repeat', $cat_source );
$eq( str_contains( $cat_html, 'current membership' ), true, 'category refresh disclosed' );
$eq( str_contains( $cat_html, 'value="' . $term . '" selected' ), true, 'category prefilled' );
wp_set_object_terms( $c2, array( $term ), 'product_cat' );
wp_set_object_terms( $c1, array(), 'product_cat' );
$cat_new = $preview( $post( $cat_source['public_id'], array( 'selector' => 'category', 'category' => (string) $term ) ) );
$eq( R::hydrate_plan( $cat_new )->data()['resolved_product_ids'], array( $c2 ), 'current membership frozen, not the old population' );

// 10. Missing and deleted products stay explained and unwritten.
$gone = $create(); $draft = $create();
$gone_source = $preview( $post( null, array( 'selector' => 'ids', 'picker_present' => '1', 'product_ids' => array( (string) $gone, (string) $draft ) ) ) );
wp_delete_post( $gone, true ); wp_update_post( array( 'ID' => $draft, 'post_status' => 'draft' ) );
$gone_new = $preview( $post( $gone_source['public_id'], array( 'ids' => $gone . ',' . $draft ) ) );
$eq( R::hydrate_plan( $gone_new )->summary()['unsupported'], 2, 'missing/unsupported frozen explanations' );
$eq( R::hydrate_plan( $gone_new )->data()['source_job'], $gone_source['public_id'], 'provenance on explained previews too' );

// 11. Selection ceiling: exactly 1,000 freezes; 1,001 refuses before resolution.
$thousand = implode( ',', range( 9000001, 9001000 ) );
$ceiling = $preview( $post( $source['public_id'], array( 'ids' => $thousand ) ) );
$eq( R::hydrate_plan( $ceiling )->summary()['selected'], 1000, 'exactly 1000 final targets' );
$eq( A::process_preview( $post( $source['public_id'], array( 'ids' => $thousand . ',9001001' ) ), 'POST' )['reason'], 'supported_job_limit_exceeded', '1001 refused' );

// 12. Malformed merchant edits refuse and retain the edited repeat form.
$eq( A::process_preview( $post( $source['public_id'], array( 'ids' => (string) $b, 'amount' => 'invalid' ) ), 'POST' )['reason'], 'malformed_decimal', 'malformed amount' );
$retained_html = $render( 'repeat', $source, array( 'source_job' => $source['public_id'], 'repeat' => '1', 'amount' => 'invalid', 'operation' => 'DECREASE_FIXED' ) );
$eq( str_contains( $retained_html, 'value="invalid"' ), true, 'edited amount retained without JavaScript' );

// 13. SKU, sale field, ending and price-range prefill round-trips to a fresh plan.
$sku_product = $create(); $sku = 'REPEAT-' . wp_generate_uuid4();
V::invalidate( $sku_product ); $sp = wc_get_product( $sku_product ); $sp->set_sku( $sku ); $sp->set_sale_price( '60' ); $sp->save(); V::invalidate( $sku_product );
$sku_source = $preview( $post( null, array( 'selector' => 'sku', 'sku' => $sku, 'price_field' => 'sale_price', 'operation' => 'SALE_DISCOUNT_PERCENT', 'amount' => '10', 'ending' => '99', 'range_enabled' => '1', 'range_min' => '50', 'range_max' => '70', 'range_basis' => 'sale_price' ) ) );
$sku_html = $render( 'repeat', $sku_source );
foreach ( array( 'value="' . $sku . '"', 'SALE_DISCOUNT_PERCENT', 'value="10"', 'sale_price', 'value="99" selected', 'value="50"', 'value="70"' ) as $needle ) {
 $eq( str_contains( $sku_html, $needle ), true, 'sku/sale/range prefill: ' . $needle );
}
$sku_new = $preview( $post( $sku_source['public_id'], array( 'selector' => 'sku', 'sku' => $sku, 'price_field' => 'sale_price', 'operation' => 'SALE_DISCOUNT_PERCENT', 'amount' => '10', 'ending' => '99', 'range_enabled' => '1', 'range_min' => '50', 'range_max' => '70', 'range_basis' => 'sale_price' ) ) );
$sku_plan = R::hydrate_plan( $sku_new );
$eq( $sku_plan->data()['operation']['type'], 'SALE_DISCOUNT_PERCENT', 'sale operation preserved' );
$eq( $sku_plan->data()['selection']['price_range']['enabled'], true, 'range preserved' );

// 14. Variations keep explicit identity through repeat.
$parent = $create( '100', 'WC_Product_Variable' ); $child = $create( '100', 'WC_Product_Variation', $parent );
$var_source = $preview( $post( null, array( 'selector' => 'ids', 'picker_present' => '1', 'product_ids' => array( (string) $child ) ) ) );
$var_new = $preview( $post( $var_source['public_id'], array( 'ids' => (string) $child ) ) );
$var_plan = R::hydrate_plan( $var_new );
$eq( $var_plan->data()['resolved_product_ids'], array( $child ), 'explicit variation only' );
$eq( $var_plan->item( $child )->data()['snapshot']['parent_id'], $parent, 'variation parent identity' );

// 15. Presets independence: zero presets, none created by repeat.
$eq( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'writeleash_price_preset_%'" ), 0, 'repeat needs no presets and creates none' );

// 16. Refined (#231) source: excluded products stay out, explicitly noticed.
$e1 = $create(); $e2 = $create(); $e3 = $create();
$candidate = $preview( $post( null, array( 'selector' => 'ids', 'picker_present' => '1', 'product_ids' => array( (string) $e1, (string) $e2, (string) $e3 ) ) ) );
$refine_post = array( 'job' => $candidate['public_id'], '_wpnonce' => wp_create_nonce( A::ACTION_REFINE . '_' . $candidate['public_id'] ), 'rows' => array( (string) $e3 ), 'refinement_action' => 'exclude' );
$refined_result = A::process_refine( $refine_post, 'POST' );
$eq( $refined_result['status'], 'OK', 'exclusion refinement' );
$refined = R::read_by_public_id( $refined_result['public_id'] );
$eq( R::hydrate_plan( $refined )->data()['selection_refinement']['excluded_ids'], array( $e3 ), 'exclusion frozen on source' );
$refined_original = $saved( $refined );
$refined_html = $render( 'repeat', $refined );
$eq( str_contains( $refined_html, 'excluded 1 product' ), true, 'explicit exclusion notice' );
$eq( str_contains( $refined_html, 'value="' . $e1 . ',' . $e2 . '"' ), true, 'only included ids suggested' );
$eq( str_contains( $refined_html, 'value="' . $e1 . ',' . $e2 . ',' . $e3 . '"' ), false, 'excluded id absent from the suggestion' );
$refined_new = $preview( $post( $refined['public_id'], array( 'ids' => $e1 . ',' . $e2 ) ) );
$eq( R::hydrate_plan( $refined_new )->data()['resolved_product_ids'], array( $e1, $e2 ), 'excluded product never reintroduced' );
$eq( $saved( $refined ), $refined_original, 'repeat preserves the refined source' );
$apply( $refined_new );
$eq( $price( $e3 ), '100', 'excluded price untouched by the repeated operation' );
$eq( $saved( $refined ), $refined_original, 'apply preserves the refined source' );

echo "#232 repeat-as-new-Preview real-Woo integration: PASS ($n assertions)\n";
