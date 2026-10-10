<?php
use WriteLeash\Free_Admin as Admin;
use WriteLeash\Job_Repository as Repo;
// Loaded by the existing real Woo MySQL/MariaDB default/Redis Admin fixture.
function preview_export233( array $job ): array {
	return array( 'job' => $job['public_id'], '_wpnonce' => wp_create_nonce( Admin::ACTION_EXPORT_PREVIEW . '_' . $job['public_id'] . '_' . $job['plan_hash'] ) );
}
function preview_csv233( WriteLeash\Change_Plan $plan ): array {
	$stream = fopen( 'php://temp/maxmemory:1048576', 'w+' );
	try {
		Admin::write_preview_csv( $stream, $plan ); rewind( $stream );
		$header = fgetcsv( $stream, 0, ',', '"', '' ); $rows = array();
		while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) { $rows[] = array_combine( $header, $row ); }
		return $rows;
	} finally { fclose( $stream ); }
}
wp_set_current_user( 1 );
$actor233 = wp_insert_user( array( 'user_login' => 'wl233-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $actor233 );
$id233 = make_product( '100.00', 'publish', array( 'name' => '=SUM(1,2) Café', 'sku' => '+WL233-' . wp_generate_uuid4() ) );
$same233 = make_product( '89.99' ); $refused233 = make_product( '100', 'draft' );
$preview233 = Admin::process_preview( preview_post( array( 'ids' => implode( ',', array( $id233, $same233, $refused233 ) ), 'amount' => '89.99', 'ending' => '99' ) ), 'POST' );
eq( $preview233['status'], 'OK', 'CSV fixture Preview created' );
$job233 = Repo::read_by_public_id( $preview233['public_id'] );
$request233 = preview_export233( $job233 );
eq( Admin::export_preview( $request233, 'POST' )['status'], 'OK', 'creator can export before approval' );
eq( Admin::export_preview( $request233, 'GET' )['reason'], 'post_required', 'GET export denied' );
eq( Admin::export_preview( array_merge( $request233, array( '_wpnonce' => 'bad' ) ), 'POST' )['reason'], 'invalid_nonce', 'invalid nonce denied' );
$nonce_action233 = Admin::ACTION_EXPORT_PREVIEW . '_' . $job233['public_id'] . '_' . $job233['plan_hash'];
$expired_nonce233 = substr( wp_hash( ( wp_nonce_tick( $nonce_action233 ) - 3 ) . '|' . $nonce_action233 . '|' . get_current_user_id() . '|' . wp_get_session_token(), 'nonce' ), -12, 10 );
eq( Admin::export_preview( array_merge( $request233, array( '_wpnonce' => $expired_nonce233 ) ), 'POST' )['reason'], 'invalid_nonce', 'expired review nonce rejected' );
eq( Admin::export_preview( array_merge( $request233, array( '_wpnonce' => wp_create_nonce( Admin::ACTION_EXPORT . '_' . $job233['public_id'] ) ) ), 'POST' )['reason'], 'invalid_nonce', 'Job CSV nonce cannot authorize Preview CSV' );
eq( Admin::export_preview( array_merge( $request233, array( '_wpnonce' => wp_create_nonce( Admin::ACTION_EXPORT_PREVIEW . '_' . $job233['public_id'] . '_' . str_repeat( '0', 64 ) ) ) ), 'POST' )['reason'], 'invalid_nonce', 'nonce binds exact reviewed fingerprint' );
$other233 = wp_insert_user( array( 'user_login' => 'wl233-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager' ) );
wp_set_current_user( $other233 );
$denied233 = Admin::export_preview( preview_export233( $job233 ), 'POST' );
eq( $denied233['status'], 'FORBIDDEN', 'other manager with own valid nonce denied' );
ok( ! isset( $denied233['job'], $denied233['plan'] ), 'denial discloses no private content' );
eq( Admin::export_preview( array( 'job' => wp_generate_uuid4(), '_wpnonce' => 'bad' ), 'POST' )['status'], 'FORBIDDEN', 'guessed missing job denied' );
eq( Admin::export_preview( array( 'job' => $job233['plan_id'], '_wpnonce' => 'bad' ), 'POST' )['status'], 'FORBIDDEN', 'Plan ID cannot select job' );
wp_set_current_user( 0 ); eq( Admin::export_preview( $request233, 'POST' )['status'], 'FORBIDDEN', 'anonymous denied' );
wp_set_current_user( $actor233 ); wp_get_current_user()->add_cap( 'edit_products', false );
eq( Admin::export_preview( $request233, 'POST' )['status'], 'FORBIDDEN', 'revoked capability denied' );
wp_get_current_user()->remove_cap( 'edit_products' );
wp_set_current_user( 1 );
$request233 = preview_export233( $job233 );
eq( Admin::export_preview( $request233, 'POST' )['status'], 'OK', 'administrator can export another creator Preview' );
$plan233 = Repo::hydrate_plan( $job233 );
$p233 = wc_get_product( $id233 ); $p233->set_regular_price( '999.00' ); $p233->set_name( 'Live renamed' ); $p233->set_sku( 'LIVE233-' . wp_generate_uuid4() ); $p233->save();
$before233 = Repo::read( (int) $job233['id'] );
$sql233 = array(); $reads233 = array(); $writes233 = array();
$trace233 = static function ( $query ) use ( &$sql233 ) { $sql233[] = $query; return $query; };
$read233 = static function ( $id ) use ( &$reads233 ) { $reads233[] = $id; };
$write233 = static function ( $id ) use ( &$writes233 ) { $writes233[] = $id; };
add_filter( 'query', $trace233 ); add_action( 'woocommerce_product_read', $read233 ); add_action( 'woocommerce_before_product_object_save', $write233 );
try {
	$export233 = Admin::export_preview( array_merge( $request233, array( 'plan_id' => 'different', 'ids' => (string) $same233, 'amount' => '0', 'offset' => 20 ) ), 'POST' );
	$csv233 = preview_csv233( $export233['plan'] );
} finally { remove_filter( 'query', $trace233 ); remove_action( 'woocommerce_product_read', $read233 ); remove_action( 'woocommerce_before_product_object_save', $write233 ); }
eq( $reads233, array(), 'export performs zero live Woo reads' ); eq( $writes233, array(), 'export performs zero Woo mutations' );
foreach ( $sql233 as $query233 ) { ok( ! preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|START\s+TRANSACTION|BEGIN|LOCK)\b|\bFOR\s+UPDATE\b/i', $query233 ), 'read-only export SQL; no Plan creation or approvals' ); }
eq( Repo::read( (int) $job233['id'] ), $before233, 'entire persisted job unchanged by export' );
eq( count( $csv233 ), 3, 'all frozen outcomes exported despite client row selector' );
eq( array_column( $csv233, 'result' ), array( 'CHANGING', 'UNCHANGED', 'UNSUPPORTED' ), 'honest reviewed outcomes' );
eq( $csv233[0]['product_name_at_preview'], "'=SUM(1,2) Café", 'frozen escaped name, not live rename' );
eq( $csv233[0]['expected_price'], '100', 'canonical expected frozen amount' );
eq( $csv233[0]['stored_price_at_preview'], '100.00', 'raw reviewed before string preserved' );
eq( $csv233[0]['planned_price'], '89.99', 'rounded final target frozen' );
eq( $csv233[0]['delta'], '-10.01', 'negative delta not damaged by formula protection' );
eq( $csv233[2]['reason'], 'unsupported_status', 'refused row reason' );

// Corrupt/missing material and identity mismatch fail before any CSV writer is invoked.
$jobs_table233 = WriteLeash\Job_Schema::jobs_table( $wpdb );
foreach ( array( array( 'plan_json' => '{}' ), array( 'plan_json' => '' ), array( 'plan_hash' => str_repeat( '0', 64 ) ), array( 'plan_id' => wp_generate_uuid4() ), array( 'status' => 'CANCELLED' ) ) as $damage233 ) {
	$wpdb->update( $jobs_table233, $damage233, array( 'id' => (int) $job233['id'] ) );
	$damaged233 = Repo::read( (int) $job233['id'] );
	$failure233 = Admin::export_preview( preview_export233( $damaged233 ), 'POST' );
	eq( $failure233['status'], 'INVALID', 'unverifiable/no-longer-reviewable Plan safely rejected' );
	ok( ! isset( $failure233['plan'], $failure233['job'] ), 'invalid material does not disclose content' );
	$restore233 = array(); foreach ( $damage233 as $key233 => $value233 ) { $restore233[$key233] = $job233[$key233]; }
	$wpdb->update( $jobs_table233, $restore233, array( 'id' => (int) $job233['id'] ) );
}
$page_html233 = render_view( 'preview', $job233['public_id'], 0 );
ok( str_contains( $page_html233, 'Download Preview CSV' ) && str_contains( $page_html233, 'not an offline approval' ), 'snapshot notice and native download button' );
ok( str_contains( $page_html233, 'name="action" value="' . Admin::ACTION_APPROVE . '"' ), 'approval flow remains available after export' );
marker( '#233 frozen Preview CSV authorization, nonce, integrity, no-write and live-drift proof' );
