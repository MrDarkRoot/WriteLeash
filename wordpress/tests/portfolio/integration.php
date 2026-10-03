<?php
// Run by wp eval-file on the pinned #111 WP/Woo/two-engine fixture.
// Sentinel tables/actions belong ONLY to this fixture, not to product schemas.
function wl144_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( '#144 ' . $message ); } }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$mode = getenv( 'ISOLATION_MODE' );
$subject = getenv( 'ISOLATION_PLUGIN' );
$owners = array(
    'writeleash' => array( 'prefix' => 'writeleash_', 'version' => '0.1.0', 'group' => 'writeleash-jobs', 'hook' => 'writeleash_process_job', 'namespace' => 'WriteLeash', 'rest' => 'writeleash/v1' ),
    'writeleash-price-history' => array( 'prefix' => 'writeleash_price_history_', 'version' => '0.0.0', 'group' => 'writeleash-price-history', 'hook' => 'writeleash_price_history_wakeup', 'namespace' => 'WriteLeash\PriceHistory', 'rest' => 'writeleash-price-history/v1' ),
    'writeleash-price-campaigns' => array( 'prefix' => 'writeleash_price_campaigns_', 'version' => '0.0.0', 'group' => 'writeleash-price-campaigns', 'hook' => 'writeleash_price_campaigns_wakeup', 'namespace' => 'WriteLeash\PriceCampaigns', 'rest' => 'writeleash-price-campaigns/v1' ),
);
wl144_assert( class_exists( 'WooCommerce' ), 'Woo dependency missing' );
wl144_assert( ActionScheduler::is_initialized(), 'Woo scheduler unavailable' );
if ( 'seed' === $mode ) {
    $active = get_option( 'active_plugins' );
    sort( $active );
    $expected = array( 'woocommerce/woocommerce.php', 'writeleash/writeleash.php', 'writeleash-price-history/writeleash-price-history.php', 'writeleash-price-campaigns/writeleash-price-campaigns.php' );
    sort( $expected );
    wl144_assert( $active === $expected, 'Unexpected shared required plugin' );
    $tables = array();
    $groups = array();
    $routes = array();
    foreach ( $owners as $slug => $owner ) {
        wl144_assert( class_exists( $owner['namespace'] . '\Plugin' ), 'Independent namespace missing' );
        $main = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
        $headers = get_plugin_data( $main, false, false );
        wl144_assert( 'woocommerce' === $headers['RequiresPlugins'], 'Sibling dependency declared' );
        wl144_assert( $owner['version'] === get_option( $owner['prefix'] . 'version' ), 'Version option collision' );
        $table = $wpdb->prefix . $owner['prefix'] . 'isolation_fixture';
        wl144_assert( ! in_array( $table, $tables, true ) && ! in_array( $owner['group'], $groups, true ) && ! in_array( $owner['rest'], $routes, true ), 'Ownership collision' );
        $tables[] = $table;
        $groups[] = $owner['group'];
        $routes[] = $owner['rest'];
        if ( 'writeleash' !== $slug ) {
            $class = $owner['namespace'] . '\Plugin';
            wl144_assert( $owner['prefix'] === $class::TABLE_PREFIX && $owner['prefix'] === $class::OPTION_PREFIX && $owner['group'] === $class::SCHEDULER_GROUP && $owner['hook'] === $class::ACTION_HOOK && $owner['rest'] === $class::REST_NAMESPACE, 'Reserved runtime identity drift' );
        }
        update_option( $owner['prefix'] . 'isolation_sentinel', $slug, false );
        $wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i (id bigint PRIMARY KEY, marker varchar(100) NOT NULL) ENGINE=InnoDB', $table ) );
        $wpdb->replace( $table, array( 'id' => 1, 'marker' => $slug ), array( '%d', '%s' ) );
        $id = get_option( 'wl144_fixture_action_' . $slug );
        if ( ! $id || 'pending' !== ActionScheduler::store()->get_status( $id ) ) {
            $id = as_schedule_single_action( time() + DAY_IN_SECONDS, $owner['hook'], array( 'isolation_fixture' => $slug ), $owner['group'], true );
        }
        wl144_assert( $id > 0, 'Cannot seed scheduler sentinel' );
        update_option( 'wl144_fixture_action_' . $slug, $id, false );
    }
    // Compare live flagship tables with reserved satellite prefixes.
    foreach ( array( WriteLeash\Job_Schema::jobs_table( $wpdb ), WriteLeash\Job_Schema::items_table( $wpdb ), WriteLeash\Undo_Schema::operations_table( $wpdb ), WriteLeash\Undo_Schema::items_table( $wpdb ) ) as $table ) {
        wl144_assert( false === strpos( $table, 'writeleash_price_history_' ) && false === strpos( $table, 'writeleash_price_campaigns_' ), 'Shared writable table' );
    }
    $registered = rest_get_server()->get_routes();
    foreach ( array_keys( $registered ) as $route ) {
        wl144_assert( 0 !== strpos( $route, '/writeleash-price-history/' ) && 0 !== strpos( $route, '/writeleash-price-campaigns/' ), 'Scaffold unexpectedly registers REST routes' );
    }
    wl144_assert( (bool) preg_grep( '#^/writeleash/v1/#', array_keys( $registered ) ), 'Flagship REST bootstrap missing' );
    // The scaffold has no menus/nonces/actions to collide; only flagship hooks exist.
    wl144_assert( 'writeleash_price_history_wakeup' !== WriteLeash\Job_Scheduler::HOOK && 'writeleash_price_campaigns_wakeup' !== WriteLeash\Undo_Scheduler::HOOK, 'Wakeup hook collision' );
} else {
    wl144_assert( in_array( $mode, array( 'deactivated', 'uninstalled' ), true ) && isset( $owners[$subject] ), 'Ambiguous lifecycle test' );
    foreach ( $owners as $slug => $owner ) {
        $table = $wpdb->prefix . $owner['prefix'] . 'isolation_fixture';
        wl144_assert( $slug === get_option( $owner['prefix'] . 'isolation_sentinel' ), 'Broad option cleanup' );
        wl144_assert( $slug === $wpdb->get_var( $wpdb->prepare( 'SELECT marker FROM %i WHERE id=1', $table ) ), 'Cross-plugin table cleanup' );
        if ( 'uninstalled' === $mode && $slug === $subject ) {
            wl144_assert( false === get_option( $owner['prefix'] . 'version' ), 'Exact version cleanup missing' );
        } else {
            wl144_assert( $owner['version'] === get_option( $owner['prefix'] . 'version' ), 'Another plugin version changed' );
        }
        if ( $slug !== $subject ) {
            $id = get_option( 'wl144_fixture_action_' . $slug );
            wl144_assert( 'pending' === ActionScheduler::store()->get_status( $id ), 'Cross-plugin scheduler cancellation' );
        }
    }
}
// Every included family runtime belongs to a staged plugin root. Static token
// audits also prove no sibling reference, including dormant include paths.
foreach ( get_included_files() as $included ) {
    if ( false !== strpos( $included, '/writeleash' ) ) {
        wl144_assert( 1 === preg_match( '#/wp-content/plugins/(?:writeleash|writeleash-price-history|writeleash-price-campaigns)/#', $included ), 'Runtime escaped staged roots' );
    }
}
wl144_assert( '' === $wpdb->last_error, 'Database fixture error' );
echo '#144 real WP/Woo ' . $mode . ' ' . $subject . " PASS\n";
