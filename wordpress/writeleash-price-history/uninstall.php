<?php
namespace WriteLeash\PriceHistory;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) { exit; }

// The sole persistent record this scaffold creates. Never wildcard cleanup.
delete_option( 'writeleash_price_history_version' );
