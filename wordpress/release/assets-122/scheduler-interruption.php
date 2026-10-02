<?php
// Copy ONLY into the disposable screenshot site's mu-plugins directory.
// This controls the fixture's scheduler transport, not product job truth.
// DISABLE_WP_CRON must also be true. Normal protected Resume remains enabled.
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
