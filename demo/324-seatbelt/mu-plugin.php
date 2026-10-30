<?php
/**
 * Disposable timing control for the #324 recording.
 *
 * Approve queues Apply on Action Scheduler. The async runner can finish that
 * job before the recording reaches Resume. This filter keeps the queued job
 * waiting until the merchant clicks Resume remaining products. The worker,
 * the price comparison, and the conflict result are still WriteLeash.
 * The wizard filters only stop WooCommerce from replacing the first admin
 * screen with its setup redirect. Delete this file on a normal site.
 */
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'woocommerce_prevent_automatic_wizard_redirect', '__return_true' );
add_filter( 'woocommerce_enable_setup_wizard', '__return_false' );
