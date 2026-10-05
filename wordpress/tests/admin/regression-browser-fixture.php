<?php
// #170 disposable setup and independent SQL observer. Never used by the plugin.
$mode = (string) getenv( 'WL170_MODE' );
$file = (string) getenv( 'WL170_FIXTURE' );
if ( ! $file ) { throw new RuntimeException( 'Explicit disposable #170 fixture required' ); }
global $wpdb;
wp_set_current_user( 1 );
if ( 'seed' === $mode ) {
	if ( ! WriteLeash\Free_Admin::dependency_ok() ) { throw new RuntimeException( 'Seed needs supported Woo' ); }
	$tag = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 );
	$password = wp_generate_password( 24, false );
	$users = array();
	foreach ( array( 'manager' => 'shop_manager', 'other' => 'shop_manager', 'subscriber' => 'subscriber', 'editor' => 'subscriber' ) as $key => $role ) {
		$id = wp_insert_user( array( 'user_login' => 'wl170-' . $key . '-' . $tag, 'user_pass' => $password, 'role' => $role ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$users[$key] = array( 'id' => $id, 'username' => 'wl170-' . $key . '-' . $tag, 'password' => $password );
	}
	foreach ( array( 'edit_products', 'edit_published_products', 'manage_woocommerce' ) as $cap ) { ( new WP_User( $users['editor']['id'] ) )->add_cap( $cap ); }
	$ids = array(); $skus = array();
	for ( $i = 0; $i < 22; ++$i ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'Gate ' . $tag . ' product ' . $i . ( 0 === $i ? ' Café 日本 ' . str_repeat( 'long name ', 8 ) : '' ) );
		$sku = 'G170-' . $tag . '-' . sprintf( '%02d', $i );
		$p->set_sku( $sku ); $p->set_status( 'publish' ); $p->set_regular_price( '100.00' ); $p->save();
		$ids[] = $p->get_id(); $skus[] = $sku;
	}
	$fixture = array( 'users' => $users, 'ids' => $ids, 'skus' => $skus );
	file_put_contents( $file, wp_json_encode( $fixture ) ); chmod( $file, 0600 );
	update_option( 'wl170_ids', $ids, false ); update_option( 'wl170_saves', array(), false ); update_option( 'wl170_reads', array(), false );
	update_option( 'wl170_short_nonce', false, false );
	$mu = ABSPATH . 'wp-content/mu-plugins'; wp_mkdir_p( $mu );
	file_put_contents( $mu . '/wl170-observer.php', <<<'MU'
<?php
add_filter('action_scheduler_allow_async_request_runner', '__return_false');
add_filter('nonce_life', static function($life) { return get_option('wl170_short_nonce') ? 4 : $life; });
add_action('woocommerce_before_product_object_save', static function($p) {
 if (!in_array($p->get_id(), get_option('wl170_ids', array()), true)) return;
 $s=get_option('wl170_saves', array()); $id=$p->get_id(); $s[$id]=($s[$id]??0)+1; update_option('wl170_saves', $s, false);
});
add_filter('posts_results', static function($posts,$query) {
 $pt=(array)$query->get('post_type'); if (!in_array('product',$pt,true)) return $posts;
 $r=get_option('wl170_reads', array()); $kind=$query->get('post__in')?'selected':'search';
 $r[$kind]=max($r[$kind]??0,count($posts)); $pp=(int)$query->get('posts_per_page'); $limit=$kind==='selected'?max(\WriteLeash\Free_Support_Contract::MAX_JOB_PRODUCTS,\WriteLeash\Product_Price_Selector::MAX_SELECTED)+1:11; $r['unbounded']=($r['unbounded']??false)||$pp<1; $r['oversized']=($r['oversized']??false)||$pp>$limit; if(($pp<1||$pp>$limit)&&count($r['offenders']??array())<20){$in=$query->get('post__in'); $r['offenders'][]=array('kind'=>$kind,'pp'=>$pp,'returned'=>count($posts),'in'=>is_array($in)?count($in):(int)(bool)$in);}
 update_option('wl170_reads',$r,false); return $posts;
},10,2);
MU
	);
	echo "#170 real Woo browser fixture seeded\n"; return;
}
$f = json_decode( file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR );
if ( 'edit-apply' === $mode || 'edit-undo' === $mode ) {
	$id = $f['ids'][ 'edit-apply' === $mode ? 0 : 1 ];
	$p = wc_get_product( $id ); $p->set_regular_price( 'edit-apply' === $mode ? '120.00' : '93.00' ); $p->save();
} elseif ( 'revoke' === $mode || 'restore-rights' === $mode ) {
	( new WP_User( $f['users']['manager']['id'] ) )->add_cap( 'manage_woocommerce', 'restore-rights' === $mode );
} elseif ( 'short-nonce' === $mode || 'normal-nonce' === $mode ) {
	update_option( 'wl170_short_nonce', 'short-nonce' === $mode );
} elseif ( 'expire-session' === $mode ) {
	WP_Session_Tokens::get_instance( $f['users']['manager']['id'] )->destroy_all();
} elseif ( 'missing-woo' === $mode ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php'; deactivate_plugins( 'woocommerce/woocommerce.php' );
} elseif ( 'restore-woo' === $mode ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php'; activate_plugin( 'woocommerce/woocommerce.php' );
} elseif ( 'older-woo' === $mode ) {
	$zip = getenv( 'WL170_OLDER_ZIP' ) ?: '/opt/woo-zips/woocommerce.11.0.1.zip';
	$expected = 'da189b6616c610d15a2106f93151dab81b78f83e075bcefce221ac0d00b4fa21';
	if ( hash_file( 'sha256', $zip ) !== $expected ) { throw new RuntimeException( 'Wrong older Woo package' ); }
	$backup = ABSPATH . 'wp-content/wl170-supported-woo';
	if ( file_exists( $backup ) ) { throw new RuntimeException( 'Unrestored Woo fixture' ); }
	if ( ! rename( WP_PLUGIN_DIR . '/woocommerce', $backup ) ) { throw new RuntimeException( 'Woo fixture backup failed' ); }
	$archive = new ZipArchive(); $archive->open( $zip ); $archive->extractTo( WP_PLUGIN_DIR ); $archive->close();
} elseif ( 'restore-version' === $mode ) {
	if ( ! rename( WP_PLUGIN_DIR . '/woocommerce', ABSPATH . 'wp-content/wl170-older-woo-' . wp_generate_uuid4() ) || ! rename( ABSPATH . 'wp-content/wl170-supported-woo', WP_PLUGIN_DIR . '/woocommerce' ) ) { throw new RuntimeException( 'Woo fixture restoration failed' ); }
} elseif ( 'observe' !== $mode ) { throw new RuntimeException( 'Unknown #170 fixture mode' ); }
// Rejected actions are checked against prices, full durable rows and save counts.
$prices = array();
foreach ( $f['ids'] as $id ) { $prices[$id] = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM %i WHERE post_id=%d AND meta_key='_regular_price'", $wpdb->postmeta, $id ) ); }
function table170( string $table ): bool { global $wpdb; return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); }
$jobs = table170( WriteLeash\Job_Schema::jobs_table( $wpdb ) ) ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE creator_id IN (' . implode( ',', array_map( static fn( $u ) => (int) $u['id'], $f['users'] ) ) . ') ORDER BY id', WriteLeash\Job_Schema::jobs_table( $wpdb ) ), ARRAY_A ) : array();
$items = array(); $undo = array();
foreach ( $jobs as $job ) {
	$items[$job['public_id']] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE job_id=%d ORDER BY sequence', WriteLeash\Job_Schema::items_table( $wpdb ), $job['id'] ), ARRAY_A );
	if ( table170( WriteLeash\Undo_Schema::operations_table( $wpdb ) ) ) {
		$op = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE job_id=%d', WriteLeash\Undo_Schema::operations_table( $wpdb ), $job['id'] ), ARRAY_A );
		if ( $op ) { $undo[$job['public_id']] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE undo_id=%d ORDER BY id', WriteLeash\Undo_Schema::items_table( $wpdb ), $op['id'] ), ARRAY_A ); }
	}
}
echo wp_json_encode( array( 'prices' => $prices, 'jobs' => $jobs, 'items' => $items, 'undo' => $undo, 'saves' => get_option( 'wl170_saves', array() ), 'reads' => get_option( 'wl170_reads', array() ) ) );
