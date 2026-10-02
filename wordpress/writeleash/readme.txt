=== WriteLeash ===
Tags: woocommerce, bulk actions, prices, safety, undo
Requires at least: 6.8
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce bulk regular-price changes with frozen previews, safety limits, durable execution and conflict-aware Undo.

== Description ==

WriteLeash Free changes stored regular prices for published core simple
products in the base store currency. Open Products → Bulk Prices to preview,
approve and follow durable progress, history and eligible Undo.

== How it works ==

1. Select products by category, exact SKU or explicit IDs.
2. Choose Set / +fixed / -fixed / +percent / -percent and safety limits.
3. Review the frozen, paginated preview, exclusions and warnings.
4. Approve the exact plan for durable execution. Resume stalled work from Admin.
5. Use history and conflict-aware Undo for eligible WriteLeash changes.

Later WooCommerce edits are not overwritten by Undo. Orders, sales, email,
webhook and other plugin side effects are outside Undo.

== Installation ==

1. Install and activate WooCommerce 11.1.2.
2. Install and activate WriteLeash.
3. Open Products → Bulk Prices.

Free uses the normal WordPress database connection. No additional database
account or manual database setup is required.

== Supported configuration ==

* WooCommerce: 11.1.2 exactly.
* WordPress tested fixtures: 7.0.1 and 7.1.2.
* PHP tested fixtures: 7.4.33, 8.0.30, 8.1.34 and 8.2.34.
* Database tested fixtures: MySQL 8.0.44 and MariaDB 10.11.15.
* New Free jobs: at most 100 selected products. The engineering selector limit
  is 1000; it does not increase the new-job limit.
* Already-approved larger historical jobs retain bounded recovery and Undo.
* Multisite is unsupported.

== Frequently Asked Questions ==

= What remains after uninstall? =

Uninstall shuts down the runner and cancels owned wake-ups. Durable journal,
job and Undo evidence is retained. Uninstall never changes WooCommerce prices.

= Does a closed browser lose the job? =

No. Progress is durable. Use the protected Resume action if work stalls.

== Changelog ==

= 0.1.0 development candidate (not a public release) =
* WooCommerce Free preview, execution, history and conflict-aware Undo.
* Isolated Woo-only public payload; historical research remains in the repository.
