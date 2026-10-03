<?php
// Network-free test doubles, NOT real WordPress/Woo co-installation evidence.
define( 'ABSPATH', __DIR__ . '/' );
$options = array( 'writeleash_version' => '0.1.0', 'unrelated_option' => 'keep' );
$activation = array();
$deactivation = array();
$actions = array();
class WooCommerce {}
class wpdb {}
function register_activation_hook( $file, $callback ) { global $activation; $activation[$file] = $callback; }
function register_deactivation_hook( $file, $callback ) { global $deactivation; $deactivation[$file] = $callback; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { global $actions; $actions[$hook][] = $callback; }
function get_option( $name ) { global $options; return $options[$name] ?? false; }
function update_option( $name, $value, $autoload = false ) { global $options; $options[$name] = $value; return true; }
function delete_option( $name ) { global $options; unset( $options[$name] ); }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function isolation_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$wordpress = $argv[1];
require $wordpress . '/writeleash/writeleash.php';
foreach ( array( 'price-history', 'price-campaigns' ) as $target ) {
    require $wordpress . '/writeleash-' . $target . '/writeleash-' . $target . '.php';
}
isolation_assert( 3 === count( $activation ) && 3 === count( $deactivation ), 'Independent lifecycle hooks missing' );
foreach ( array( 'price-history', 'price-campaigns' ) as $target ) {
    $file = $wordpress . '/writeleash-' . $target . '/writeleash-' . $target . '.php';
    try { $activation[$file]( true ); throw new LogicException( 'Network activation accepted' ); }
    catch ( RuntimeException $expected ) {}
    $activation[$file]( false );
    $activation[$file]( false ); // Independent/idempotent metadata activation.
}
$before = $options;
foreach ( array( 'price-history', 'price-campaigns' ) as $target ) {
    $deactivation[$wordpress . '/writeleash-' . $target . '/writeleash-' . $target . '.php']();
    isolation_assert( $options === $before, 'Deactivation changed state' );
}
define( 'WP_UNINSTALL_PLUGIN', true );
foreach ( array( 'price-history', 'price-campaigns' ) as $target ) {
    $key = 'writeleash_' . str_replace( '-', '_', $target ) . '_version';
    $expected = $options;
    unset( $expected[$key] );
    require $wordpress . '/writeleash-' . $target . '/uninstall.php';
    isolation_assert( $options === $expected, 'Uninstall affected another owner' );
}
echo '#144 test-double bootstrap/lifecycle: no redeclaration, independent hooks/options, network refusal, exact uninstall PASS (not real WP/Woo)' . "\n";
