=== WriteLeash ===
Contributors: duyytrann
Tags: woocommerce, prices, stock, variations, undo
Requires at least: 6.7
Tested up to: 7.1
Stable tag: 0.3.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Protection against accidental WooCommerce price and stock overwrites, with Preview, conflicts, History and eligible Undo.

== Description ==

WriteLeash offers protection against accidental WooCommerce price and stock
overwrites on paths you control. Preview the price change, approve it, and
Apply. If the stored price or another checked setting no longer matches the
Preview, WriteLeash leaves the newer value alone and marks a conflict.
History records the result. Eligible Undo restores prices WriteLeash applied
when the saved details and the current product state permit it.

Ordinary price jobs do not change stock. WriteLeash changes stock only on an
opt-in restricted grant, through its controlled price and stock job path,
which stays off until an operator issues the grant. Stock Undo is not
supported.

This is not protection for every WooCommerce write. It is not a firewall, it
does not isolate hostile plugins, and it does not stop direct SQL. Undo does
not reverse orders, completed sales, emails, webhooks, remote requests,
external queues or other plugins' effects.

= Regular Price and Sale Price =

Set a price, add/subtract a fixed amount, or change by a percentage. Choose
Regular or Sale Price. A variable parent means its variations, or choose
variations individually. Category selection includes direct members unless
nested subcategories are explicitly included.

A Regular Price change preserves the existing Sale Price and schedule. Set
adds a first Sale Price, or calculate a discount from each reviewed Regular
Price. Clear makes Sale Price blank, not zero, and keeps its dates. Price
changes do not change sale dates, stock or orders. Optional .99/.95/.90/whole
endings pass the same safety limits; Clear ignores them.

= Choose and exclude =

Filter by an inclusive stored Regular or Sale Price range; both endpoints
count, and blank sales never match. Variations use their own prices. Exclude products or variations before approval; confirmed exclusions
stay out of Apply and Undo.

= Preview exact changes before Apply =

Preview shows before-and-after prices, warnings and explained skips. Unsafe
items are skipped where possible; a safety-limit blocker refuses the whole
Preview. Planned stored prices are not guaranteed shopper prices. Set
product-count, increase/decrease and zero-price limits, then approve
separately to Apply the reviewed targets; changed settings need a new
Preview. The Preview CSV is the saved Preview, not live data.

= Preserve newer edits =

If the stored price WriteLeash is changing or another checked setting no
longer matches the preview, WriteLeash leaves the newer value alone and marks
that product as a conflict.

A Regular Price change preserves a later Sale Price or schedule where valid.
A nonblank Sale Price target must stay below Regular Price; Clear and sale
discounts check the reviewed Regular Price basis. The product name, SKU and
category are reference details only; changing them alone does not conflict.

= Background, History and Undo =

Follow live progress or return later; Resume stalled work without repeating
completed changes. History shows results, conflicts and Undo availability.
After Apply safely finishes, Undo can restore eligible prices within its
window. Failed current checks leave the product alone as a conflict. Regular
Price Undo preserves later sales where valid; Sale Price Undo checks its
recorded Regular Price. Availability does not guarantee restoration.

== Requirements ==

* WooCommerce 10.0 through 11.x on WordPress 7.0 up to 8.0 only (10.0.0 or newer, below 12.0.0).
* Exercised: WordPress 7.0.1 and 7.1.2 on that line; WordPress 6.7.2 with WooCommerce 9.0.4; WordPress 6.8.3 with WooCommerce 9.9.7. Legacy band is WordPress 6.7 below 7.0 with WooCommerce 9.0.0 below 10.0.0. Other pairs are refused before a job is created.
* PHP 7.4.33, 8.0.30, 8.1.34 and 8.2.34 were exercised. Requires PHP 7.4 or newer.
* MySQL 8.0.44 and MariaDB 10.11.15 were exercised. Redis 7.4.2 with Object Cache 2.7.0: modern line, and 6.7.2 with 9.0.4 at 100 products. Not on 6.8.3 with 9.9.7.

== What is supported ==

* Up to 1,000 selected products per new job in the tested configuration. Variations count individually.
* Published core simple products and published variations of published core variable products, in the base store currency. Parent prices are not edited directly.
* WooCommerce refreshes variable parent price ranges after each variation change.

== Current limitations ==

* Custom product types and universal extension compatibility are unsupported.
* Multisite is unsupported.
* Real managed or shared hosting has not been tested.

== Restricted agent access ==

A seatbelt, not a firewall: a restricted WordPress principal is bounded to
WriteLeash's controlled price and stock job path. Documented monitored
WooCommerce write paths are denied outside a matching Execution Frame. Direct
SQL, hostile plugins, and unmonitored write paths are not covered. This is
not a universal fail-closed guarantee when the grant store is unavailable.
It is not a sandbox or universal agent-safe guarantee. Opt-in: restricted
access is off until an operator issues a grant; only explicit 0 enables
grant-bound writes, and absent or malformed state denies.

Who: (1) an AI agent WordPress user (manage_woocommerce + edit_products,
human-granted) is the restricted actor; the issuer submits its price/stock
jobs only via POST /wp-json/writeleash/v1/grants/{uuid}/jobs; (2) cron,
Action Scheduler or WP-CLI --user=<actor> running as the grant actor;
user-0 system cron is outside the grant; (3) human Preview/Approve/Apply
and price Undo are unchanged; (4) operators issue, inspect, pause, revoke
or remove the restriction with an append-only audit.

Can:
* Submit price/stock jobs for the granted actor through the WriteLeash grant route, within frozen scope, budget and expiry.
* Apply price and stock through the controlled item transaction; variation parent price/stock-status sync inside the frame.
* Operator: issue/inspect/revoke a grant; pause/resume; revoke-all; remove restriction (administrator-only, never self).
* Read job/stock progress and the append-only grant/denial audit.

Cannot:
* Write products through WooCommerce REST, product save, stock helpers, direct price/stock meta, trash or delete, outside a matching Execution Frame.
* Exceed grant scope, budget, expiry, or the stock bounds (max absolute delta, floor 0). No stock Undo.
* Remove its own restriction, or operate pause/resume/revoke-all without site administration.
* Cover administrators, third-party hook side effects, orders, refunds, coupons, tax, options, arbitrary meta, or user-0 system cron.

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

== Frequently Asked Questions ==

= Can I change sale and variation prices? =

Yes, within What is supported above. Sale targets must stay below Regular Price; regular
targets must remain above an existing sale.

= What happens if a price changes after Preview? =

Checks can refuse a change. A later price or setting change becomes a
conflict instead of a blind overwrite. Review conflicts in a fresh Preview
with separate approval. Changes away and back may not be detected.

= Can I review without changing prices? =

Yes. Preview and its CSV change nothing and approve nothing.

= Can Undo reverse everything? =

No. Only confirmed eligible prices can be restored; missing evidence,
ineligible products, changed values, uncertainty or expiry can prevent it.
Orders, sales and external effects are not reversed.

= Should I back up before a large update? =

Yes. Back up and test on staging first; Preview and Undo do not replace
backups. WriteLeash creates no backups.

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
