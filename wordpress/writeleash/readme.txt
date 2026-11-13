=== WriteLeash ===
Contributors: duyytrann
Tags: woocommerce, prices, stock, variations, undo
Requires at least: 6.7
Tested up to: 7.1
Stable tag: 0.3.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Protect WooCommerce prices and stock from costly mistakes. Leash restricted AI agents and automation.

== Description ==

One wrong percentage or one CSV can rewrite a price before you notice.
WriteLeash shows the new price first. Nothing is saved until you approve it.

You can also limit one shop account, so an assistant or a scheduled job
cannot overwrite prices or stock outside the job you allow.

= Preview, then apply =

Pick simple products or variations. Check Regular Price and Sale Price.
Close the screen and nothing changes. Approve, then Apply.

= Keep the newer price =

If the stored price WriteLeash is changing or another checked setting no
longer matches the preview, WriteLeash leaves the newer value alone and
marks that product as a conflict.

A later price or setting change becomes a conflict instead of a blind
overwrite. The product name, SKU and category are reference details only.

= Undo a price =

History shows what finished and what can be undone. Eligible Undo puts a
regular or sale price back. Stock Undo is not supported. Undo does not
reverse orders, emails, or other plugins.

= Limit one account =

This stays off until you turn it on. Choose the user, the products, the
price or stock limits, and when it ends. A normal price job does not
change stock. That account cannot remove its own limit.

== Requirements ==

* WooCommerce 10.0 through 11.x on WordPress 7.0 up to 8.0 only (10.0.0 or newer, below 12.0.0).
* Exercised: WordPress 7.0.1 and 7.1.2 on that line; WordPress 6.7.2 with WooCommerce 9.0.4; WordPress 6.8.3 with WooCommerce 9.9.7. Legacy band is WordPress 6.7 below 7.0 with WooCommerce 9.0.0 below 10.0.0. Other pairs are refused before a job is created.
* PHP 7.4.33, 8.0.30, 8.1.34 and 8.2.34 were exercised. Requires PHP 7.4 or newer.
* MySQL 8.0.44 and MariaDB 10.11.15 were exercised. Redis 7.4.2 on the modern line, and on 6.7.2 with 9.0.4 at 100 products. Not on 6.8.3 with 9.9.7.

== What is supported ==

* Up to 1,000 selected products per new job in the tested configuration. Variations count individually.
* Published core simple products and published variations of published core variable products, in the base store currency. Parent prices are not edited directly.
* WooCommerce refreshes variable parent price ranges after each variation change.

== Current limitations ==

* Custom product types and universal extension compatibility are not covered yet.
* Multisite is not supported yet.
* Managed or shared hosting has not been tested. Run a small Preview.

== Restricted agent access ==

A seatbelt, not a firewall: a restricted WordPress principal is bounded to
WriteLeash's controlled price and stock job path. Documented monitored
WooCommerce write paths are denied outside a matching Execution Frame. Direct
SQL, hostile plugins, and unmonitored write paths are not covered. This is
not a universal fail-closed guarantee when the grant store is unavailable.
It is not a sandbox.

== Installation ==

1. Install and activate WooCommerce on a pair named under Requirements.
2. Install and activate WriteLeash.
3. Open Products → Bulk Prices.
4. Select products, price field and operation.
5. Review Preview prices and warnings, then approve separately and Apply.

== Screenshots ==

1. Select products by name, SKU, category or ID – with nested subcategory option.
2. Exact before-and-after Preview of Regular & Sale prices (including variations).
3. Live background job progress with Resume.
4. Conflict protection: newer price is kept instead of overwritten.
5. History + eligible Undo within 30 days.
6. Safety limits: max products, max % increase/decrease, block zero prices.
7. Restricted agent: $40.00 to $49.99 is blocked. The price stays $40.00.

== Frequently Asked Questions ==

= Will an assistant account overwrite my prices? =

Only inside a job you allow. Turn that limit on for one WordPress user, set the products and the price or stock bounds, and set when it ends. The account cannot remove its own limit. Direct database edits and other plugins are not covered.

= Can I look before anything is saved? =

Yes. Preview shows the price before and after. Close the screen and the catalog stays as it is.

= What if someone edits the price after I preview? =

A later price or setting change becomes a conflict instead of a blind overwrite. That product is left alone. Preview it again, then approve.

= Can I undo a bad bulk edit? =

Eligible Undo can put a regular or sale price back. Stock Undo is not supported. Orders, emails, and other plugins are not reversed.

= Does a price edit also change stock? =

No. A normal price job leaves stock alone. Stock changes only on a limit you issued for that account.

= Can I change sale prices and variations? =

Yes, for published simple products and published variations. A sale price has to stay below the regular price.

= Do I still need a backup? =

Yes. Preview and Undo are not a backup. WriteLeash does not create one. Test a large change on staging first.

== Changelog ==

= 0.3.0 =

* Added opt-in restricted grants for bounded price and stock jobs. Grants stay off until an operator issues one.
* Denied monitored product writes outside a matching Execution Frame. Pause, revoke, and remove-restriction do not fall open.
* Upgrade from 0.2.2 keeps existing jobs, journals, and presets. No grant is created on upgrade. Stock Undo is not supported.

= 0.2.2 =

* Hardened apply-journal transitions so a committed change is not demoted, and improved recovery after an interrupted request.
* Refreshed permission checks before Undo. Added disabled-by-default foundations for bounded delegated price authority.

= 0.2.1 =

* Refreshed the plugin listing. No price-change behavior changed.

= 0.2.0 =

* Added live progress, Conflict to fresh Preview, presets, exclusions, Repeat, CSV export, price range, and price endings.
* Requires WooCommerce 10.0 through 11.x.

= 0.1.0 =

* Added bulk Regular and Sale price changes with Preview, background jobs, Resume, History, and eligible Undo.
