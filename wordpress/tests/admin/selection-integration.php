<?php
// #167 extends the real-Woo Admin suite, using its fixture/assertion helpers.
use WriteLeash\Product_Discovery as Discovery;
use WriteLeash\Free_Admin as Admin;
use WriteLeash\Job_Repository as Repo;

wp_set_current_user( 1 );
$tag167 = 'WL167-' . wp_generate_uuid4();
$duplicate167 = array( make_product( '100.00', 'publish', array( 'name' => $tag167 . ' Café <script>alert("167")</script>', 'sku' => $tag167 . '-SKU-ONE' ) ), make_product( '100.00', 'publish', array( 'name' => $tag167 . ' Café <script>alert("167")</script>' ) ) );
$long167 = make_product( '100.00', 'publish', array( 'name' => $tag167 . ' ' . str_repeat( 'Long product ', 25 ), 'sku' => $tag167 . '-SKU-TWO' ) );
$sale167 = make_product( '100.00', 'publish', array( 'name' => $tag167 . ' Sale', 'sale' => '90.00' ) );
$empty167 = make_product( '', 'publish', array( 'name' => $tag167 . ' Sale empty' ) );
$private167 = make_product( '100.00', 'private', array( 'name' => $tag167 . ' Private' ) );
$parent167 = wp_insert_term( $tag167 . ' Collection', 'product_cat' );
$child167 = wp_insert_term( $tag167 . ' Collection', 'product_cat', array( 'parent' => (int) $parent167['term_id'] ) );
$parent_product167 = make_product( '100.00', 'publish', array( 'category' => $parent167['term_id'], 'name' => $tag167 . ' Parent product' ) );
$child_product167 = make_product( '100.00', 'publish', array( 'category' => $child167['term_id'], 'name' => $tag167 . ' Child product' ) );
// #181 fixtures are created before the read-only save observer below.
$readable181 = make_product( '100.00', 'publish', array( 'name' => $tag167 . ' Readable 181' ) );
$unreadable181 = make_product( '100.00', 'publish', array( 'name' => $tag167 . ' Unreadable 181' ) );
$large167 = array();
for ( $i167 = 0; $i167 < 1001; ++$i167 ) { $large167[] = make_product( '100.00', 'publish', array( 'name' => $tag167 . ' Bounded ' . $i167 ) ); }
for ( $i167 = 0; $i167 < 121; ++$i167 ) { wp_insert_term( $tag167 . ' Taxonomy ' . $i167, 'product_cat' ); }

// Observe reads/planning only, after fixture writes. No simulated mutation provider.
$saves167 = 0;
$observer167 = static function () use ( &$saves167 ) { ++$saves167; };
add_action( 'woocommerce_before_product_object_save', $observer167 );
$started167 = microtime( true );
$found167 = Discovery::products( $tag167 . ' Café' );
eq( array_column( $found167['results'], 'id' ), array_map( 'strval', $duplicate167 ), 'name search finds duplicate names, no automatic selection' );
ok( str_contains( $found167['results'][0]['text'], 'SKU:' ) && str_contains( $found167['results'][1]['text'], 'No SKU' ), 'secondary identity distinguishes missing SKU' );
$partial167 = Discovery::products( $tag167 . '-SKU-' );
eq( array_column( $partial167['results'], 'id' ), array( (string) $duplicate167[0], (string) $long167 ), 'literal partial SKU discovery' );
eq( Discovery::products( $tag167 . ' does-not-exist' )['results'], array(), 'empty search' );
eq( Discovery::products( '' )['results'], array(), 'empty term does not load catalog' );
$sale_matches167 = Discovery::products( $tag167 . ' Sale' );
ok( ! str_contains( $sale_matches167['results'][0]['text'], 'Excluded:' ), 'sale-configured product is offered for regular/sale price choice' );
ok( str_contains( $sale_matches167['results'][1]['text'], 'Excluded:' ), 'search match explicitly does not mean eligible' );
eq( Discovery::products( $tag167 . ' Private' )['results'], array(), 'private products never exposed by published discovery' );
$query_start167 = $wpdb->num_queries;
$one167 = Discovery::products( $tag167 . ' Bounded' );
echo '#167 discovery load: catalog fixture 1001 matches; first-page results ' . count( $one167['results'] ) . '; SQL queries ' . ( $wpdb->num_queries - $query_start167 ) . "; two candidate windows capped at 11 rows each\n";
$two167 = Discovery::products( $tag167 . ' Bounded', 2 );
ok( count( $one167['results'] ) <= 20 && $one167['more'], 'bounded larger-catalog discovery offers pagination' );
eq( array_intersect( array_column( $one167['results'], 'id' ), array_column( $two167['results'], 'id' ) ), array(), 'candidate windows advance' );
$terms167 = Discovery::categories( $tag167 . ' Taxonomy' );
ok( count( $terms167['results'] ) <= 20 && $terms167['more'], 'bounded taxonomy page' );
$cat167 = Discovery::category( (int) $child167['term_id'] );
ok( str_contains( $cat167['text'], ' › ' ) && str_contains( $cat167['text'], 'Category ID:' ), 'same-name categories retain hierarchy and identity' );

$discovery_input167 = array( 'nonce' => wp_create_nonce( Discovery::ACTION ), 'term' => $tag167, 'page' => '1', 'kind' => 'products' );
eq( Discovery::request( $discovery_input167, 'GET' )['status'], 'OK', 'authenticated nonce-bound read request' );
foreach ( array( array( 'nonce' => 'bad' ), array( 'term' => array() ), array( 'page' => '51' ), array( 'page' => '0' ), array( 'kind' => 'all' ), array( 'term' => str_repeat( 'a', 101 ) ) ) as $bad167 ) {
 eq( Discovery::request( array_merge( $discovery_input167, $bad167 ), 'GET' )['status'], 'INVALID', 'invalid discovery input denied' );
}
eq( Discovery::request( $discovery_input167, 'POST' )['status'], 'INVALID', 'incorrect discovery method denied' );
// Per-product denial even with valid global capability and nonce; no denied identity/label.
$deny167 = static function ( $caps, $cap, $actor, $args ) use ( $duplicate167 ) {
 if ( 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $duplicate167[0] ) { return array( 'do_not_allow' ); }
 return $caps;
};
add_filter( 'map_meta_cap', $deny167, 10, 4 );
eq( array_column( Discovery::products( $tag167 . ' Café' )['results'], 'id' ), array( (string) $duplicate167[1] ), 'denied product removed before labels leave discovery' );
try { Discovery::selected( array( $duplicate167[0] ) ); ok( false, 'cannot restore denied selection label' ); }
catch ( WriteLeash\Price_Validation_Error $error ) { eq( $error->reason(), 'permission_denied', 'selected identity also authorized' ); }
remove_filter( 'map_meta_cap', $deny167, 10 );

// #181 unreadable products stay listed and skippable instead of failing the
// whole search or silently disappearing from the selected population.
// Fixtures were created above, before the read-only save observer.
$unreadable_filter181 = static function ( $class, $type, $post_type, $id ) use ( $unreadable181 ) {
	if ( (int) $id === $unreadable181 ) { throw new RuntimeException( 'test-only unreadable product' ); }
	return $class;
};
// Evict Woo's optional product instance cache before installing the throwing
// filter: invalidate() itself instantiates the product through the factory.
\WriteLeash\Price_Cache_Verifier::invalidate( $unreadable181 );
add_filter( 'woocommerce_product_class', $unreadable_filter181, 10, 4 );
try {
	$found181 = Discovery::products( $tag167 . ' Unreadable 181' );
	eq( array_column( $found181['results'], 'id' ), array( (string) $unreadable181 ), 'unreadable product still listed' );
	ok( str_contains( $found181['results'][0]['text'], 'Needs attention:' ), 'unreadable product labeled for the merchant' );
	$selected181 = Discovery::selected( array( $readable181, $unreadable181 ) );
	$expected181 = array( $readable181, $unreadable181 );
	sort( $expected181, SORT_NUMERIC );
	$actual181 = array_keys( $selected181 );
	sort( $actual181, SORT_NUMERIC );
	eq( $actual181, $expected181, 'selected list stays complete with unreadable product' );
	ok( str_contains( $selected181[ $unreadable181 ]['text'], 'Needs attention:' ), 'selected unreadable row carries its reason' );
} finally { remove_filter( 'woocommerce_product_class', $unreadable_filter181, 10 ); }

$form167 = preview_post( array( 'picker_present' => '1', 'product_ids' => array_map( 'strval', $duplicate167 ), 'selection_action' => 'update-products', 'discovery_nonce' => wp_create_nonce( Discovery::ACTION ), 'product_search' => $tag167 . ' Café', 'amount' => 'bad-price' ) );
$selected167 = Admin::process_selection( $form167, 'POST' );
eq( $selected167['status'], 'OK', 'native update selection is read-only' );
eq( $selected167['form']['amount'], 'bad-price', 'native search retains configuration even while invalid' );
$removed167 = Admin::process_selection( array_merge( $form167, array( 'selection_action' => 'remove:' . $duplicate167[0] ) ), 'POST' );
eq( $removed167['form']['product_ids'], array( (string) $duplicate167[1] ), 'native individual removal' );
$cleared167 = Admin::process_selection( array_merge( $form167, array( 'selection_action' => 'clear-products' ) ), 'POST' );
eq( $cleared167['form']['product_ids'], array(), 'native clear' );
eq( Admin::process_selection( array_merge( $form167, array( 'discovery_nonce' => 'bad' ) ), 'POST' )['reason'], 'invalid_nonce', 'native read session nonce checked' );
$html167 = ( static function ( $form ) { ob_start(); Admin::render_view( '', '', 0, $form ); return ob_get_clean(); } )( $selected167['form'] );
ok( str_contains( $html167, '&lt;script&gt;' ) && ! str_contains( $html167, '<script>alert' ), 'picker labels escaped' );
ok( str_contains( $html167, 'value="bad-price"' ) && str_contains( $html167, 'subcategories are not included' ), 'retained input and direct-category explanation visible' );
$duplicate_post167 = array_merge( $form167, array( 'amount' => '80.00', 'product_ids' => array( (string) $duplicate167[0], (string) $duplicate167[0], (string) $duplicate167[1] ) ) );
$preview167 = Admin::process_preview( $duplicate_post167, 'POST' );
eq( $preview167['status'], 'OK', 'picker creates regular IDS preview' );
$job167 = Repo::read_by_public_id( $preview167['public_id'] );
$material167 = $job167['plan_json'];
$hash167 = $job167['plan_hash'];
eq( Repo::hydrate_plan( $job167 )->summary()['selected'], 2, 'repeated selection cannot duplicate plan population' );
eq( Repo::hydrate_plan( $job167 )->data()['selection']['type'], 'IDS', 'discovery text is not the saved execution selector' );
foreach ( array( '', 'history', 'job' ) as $view167 ) {
 $html167 = render_view( $view167, 'job' === $view167 ? $job167['public_id'] : '', 0 );
 ok( str_contains( $html167, 'Continue review' ) && str_contains( $html167, 'wl_view=preview' ), 'saved review action on ' . $view167 );
}
$html167 = render_view( 'preview', $job167['public_id'], 0 );
ok( str_contains( $html167, 'Approve and apply' ) && str_contains( $html167, 'Create a new preview' ), 'saved preview approval plus separate new-preview action' );
$direct167 = Admin::process_preview( preview_post( array( 'selector' => 'category', 'category' => (string) $parent167['term_id'] ) ), 'POST' );
$parent_plan167 = Repo::hydrate_plan( Repo::read_by_public_id( $direct167['public_id'] ) );
eq( array_column( $parent_plan167->data()['items'], 'product_id' ), array( $parent_product167 ), 'parent category excludes child-only member' );
$thousand167 = Admin::process_preview( preview_post( array( 'picker_present' => '1', 'product_ids' => array_map( 'strval', array_slice( $large167, 0, 1000 ) ) ) ), 'POST' );
eq( $thousand167['status'], 'OK', '1000 picker products permitted at the supported ceiling' );
eq( Repo::hydrate_plan( Repo::read_by_public_id( $thousand167['public_id'] ) )->summary()['selected'], 1000, '1000 picker products frozen exactly' );
$before_jobs167 = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WriteLeash\Job_Schema::jobs_table( $wpdb ) );
$thousandone167 = Admin::process_preview( preview_post( array( 'picker_present' => '1', 'product_ids' => array_map( 'strval', array_slice( $large167, 0, 1001 ) ) ) ), 'POST' );
eq( $thousandone167['reason'], 'supported_job_limit_exceeded', '1001 picker products refused before import' );
eq( Admin::process_preview( preview_post( array( 'picker_present' => '0', 'ids' => implode( ',', range( 1, 1001 ) ) ) ), 'POST' )['reason'], 'supported_job_limit_exceeded', '1001 explicit IDs refused before resolution' );
eq( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WriteLeash\Job_Schema::jobs_table( $wpdb ) ), $before_jobs167, '1001 refusal creates no job' );
$blocked_thousand167 = Admin::process_preview( preview_post( array( 'picker_present' => '1', 'product_ids' => array_map( 'strval', array_slice( $large167, 0, 1000 ) ), 'max_decrease' => '1' ) ), 'POST' );
$blocked_html167 = render_view( 'preview', $blocked_thousand167['public_id'], 0 );
ok( str_contains( $blocked_html167, 'Showing the first 20 blocked products in this summary' ) && str_contains( $blocked_html167, '980 more blocked products are not listed here' ), 'blocked 1000-item preview bounds its reason summary' );
$blocked167 = Admin::process_preview( preview_post( array( 'ids' => (string) $duplicate167[0], 'max_decrease' => '1' ) ), 'POST' );
ok( str_contains( render_view( 'preview', $blocked167['public_id'], 0 ), 'This plan cannot be executed.' ), 'saved blocked preview explanation' );
ok( str_contains( render_view( 'job', $blocked167['public_id'], 0 ), 'Review blocked plan' ), 'direct blocked status has review action' );
$new167 = Admin::process_preview( array_merge( $duplicate_post167, array( 'amount' => '70.00' ) ), 'POST' );
ok( $new167['plan_id'] !== $preview167['plan_id'], 'changed configuration creates a new plan' );
eq( Repo::read_by_public_id( $preview167['public_id'] )['plan_json'], $material167, 'new preview does not mutate saved material' );
eq( $saves167, 0, 'discovery, fallback and all planning performed zero Woo product saves before approval' );
echo '#167 bounded discovery and taxonomy elapsed ' . round( microtime( true ) - $started167, 3 ) . "s (includes preview/import assertions; not a scale claim)\n";
remove_action( 'woocommerce_before_product_object_save', $observer167 );

// External Woo edit, then reopen: exact material stays frozen and execution conflicts.
$edited167 = wc_get_product( $duplicate167[0] ); $edited167->set_regular_price( '120.00' ); $edited167->save();
$html167 = render_view( 'preview', $job167['public_id'], 0 );
ok( str_contains( $html167, '100.00' ) && str_contains( $html167, '80.00' ), 'reopen still shows reviewed before and absolute target' );
eq( Repo::read_by_public_id( $job167['public_id'] )['plan_json'], $material167, 'reopen never replans material' );
eq( Repo::read_by_public_id( $job167['public_id'] )['plan_hash'], $hash167, 'reopen preserves approval binding hash' );
eq( Admin::process_approve( approve_post( $job167 ), 'POST' )['status'], 'OK', 'saved original plan approval' );
run_job_terminal( (int) $job167['id'] );
price_eq( fresh_price( $duplicate167[0] ), '120.00', 'fresh conflict preserves newer Woo price' );
ok( ! str_contains( render_view( 'preview', $job167['public_id'], 0 ), 'Approve and apply' ), 'old preview link after approval routes to progress' );
eq( Admin::process_approve( approve_post( $job167 ), 'POST' )['reason'], 'already_approved', 'cannot approve existing job twice' );

$other167 = wp_insert_user( array( 'user_login' => 'wl167-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $other167 );
$other_nonce167 = wp_create_nonce( Discovery::ACTION );
eq( Discovery::request( array_merge( $discovery_input167, array( 'nonce' => $other_nonce167 ) ), 'GET' )['status'], 'OK', 'Shop Manager has authenticated discovery' );
ok( ! str_contains( render_view( 'history', '', 0 ), $job167['public_id'] ), 'history remains actor-scoped' );
ok( str_contains( render_view( 'preview', $job167['public_id'], 0 ), 'No preview is visible' ), 'wrong actor cannot reopen original preview' );
eq( Discovery::request( $discovery_input167, 'GET' )['reason'], 'invalid_nonce', 'other actor cannot use creator discovery nonce' );
$user167 = wp_get_current_user(); $user167->add_cap( 'manage_woocommerce', false );
eq( Admin::can_mutate(), false, 'revocation fixture updates the active WordPress actor' );
eq( Discovery::request( array_merge( $discovery_input167, array( 'nonce' => $other_nonce167 ) ), 'GET' )['reason'], 'permission_denied', 'revoked global permission denies even valid nonce' );
wp_set_current_user( 0 );
eq( Discovery::request( $discovery_input167, 'GET' )['reason'], 'permission_denied', 'anonymous discovery refused' );
wp_set_current_user( 1 );
marker( '#167 merchant discovery, native selection, frozen reopen and security' );

require __DIR__ . '/subcategory-integration.php';
