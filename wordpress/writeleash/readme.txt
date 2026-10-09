=== WriteLeash ===
Contributors: duyytrann
Tags: woocommerce, bulk edit, prices, undo, safety
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 0.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk price changes without blindly overwriting newer edits.

== Description ==

**Bulk price changes without blindly overwriting newer edits.**

Review exact before-and-after prices before you Apply. If a relevant price or
checked product setting changes after Preview, WriteLeash preserves the newer
edit and shows a conflict instead of overwriting it.

Change Regular Price or Sale Price for simple products and variable-product
variations. Select by product name, SKU or category, with up to 1,000 products
in one job. Work runs in the background; Resume interrupted jobs, review
History and Undo eligible changes.

For store owners, Shop Managers, catalog operators, agencies and freelancers
managing WooCommerce stores, WriteLeash keeps price reviews, progress and
results together in Products → Bulk Prices.

= What you can change =

Set a price, add or subtract a fixed amount, or increase or decrease by a
percentage. Choose Regular Price or Sale Price for published simple products
and variations of published variable products, in your base store currency.
Select a variable product to include its variations, or choose individual
variations. Category selection includes direct members by default; an explicit
option also includes nested subcategories.

A Regular Price change preserves the existing Sale Price and sale schedule.
Use Set to add a first Sale Price; all operations work on an existing Sale
Price. Sale dates, stock and orders are not changed.

= Preview exact changes before Apply =

Preview shows the current and proposed price for each selected product, along
with how much it changes. Variations include their product and attributes so
you can tell them apart.

See which products will change, which are already at the target price and
which need attention. Invalid prices, unsupported products and items blocked
by your safety settings have a reason. Skipped items do not prevent valid
changes from continuing where it is safe to do so.

Set limits for product count, price increases, price decreases and zero-price
targets. Apply uses the products and target prices you reviewed. Changing the
selection or price settings requires a new Preview.

= Preserve newer edits =

If the stored price WriteLeash is changing or another checked setting no
longer matches the preview, WriteLeash leaves the newer value alone and marks
that product as a conflict. Other eligible products can continue.

A Regular Price change preserves a later Sale Price or schedule. A Sale Price
change must still stay below the Regular Price. Product name, SKU and category
are reference details only; changing them alone does not cause a conflict.

= Background processing and Resume =

Jobs run in the background with saved progress. You can close the browser and
return to see what changed, what remains and what needs attention. If work
stalls, Resume continues the remaining products in the same job without
starting again or repeating completed changes.

= History and eligible Undo =

History keeps your price-change jobs together with their results and Undo
availability. Open a job to review progress, conflicts and products needing
attention.

Undo restores eligible Regular Price or Sale Price values that WriteLeash
changed, within the available Undo window. If the price or a checked setting
has changed since Apply, Undo leaves that product alone and shows a conflict.
A Regular Price Undo preserves later sale changes; a Sale Price Undo checks
the Regular Price recorded when the sale change ran.

Undo restores eligible prices only. It does not reverse orders, completed
sales, emails, webhooks, remote requests, external queues or other plugins'
effects.

= Select → Preview → Apply =

1. **Select** products by name, SKU or category, then choose Regular Price or Sale Price and the operation.
2. **Preview** exact before-and-after values, skipped items and warnings.
3. **Apply** the reviewed changes, then check progress and History. Use Resume or eligible Undo when needed.

== Supported scope ==

* Up to 1,000 selected products per new job in the tested configuration.
* Published core simple WooCommerce products.
* Published core variable WooCommerce products: selecting the parent targets all of its variations, or select individual variations.
* Stored regular and sale prices in the base store currency.
* Adding a first sale price with Set; changing an existing sale with any operation.
* Regular-price edits on products with an existing sale price or schedule (the sale is preserved).
* WooCommerce 10.0 through 11.x (version 10.0.0 or newer, below 12.0.0).
* WordPress 7.0 through 7.x is supported. WordPress 7.0.1 and 7.1.2 were exercised. Requires WordPress 7.0 or newer.
* PHP 7.4.33, 8.0.30, 8.1.34 and 8.2.34 were exercised. Requires PHP 7.4 or newer.
* MySQL 8.0.44 and MariaDB 10.11.15 were exercised, with default and Redis Object Cache 2.7.0 (Redis 7.4.2) persistent cache modes.
* Not changed: sale dates, stock and orders. Variable parent price ranges are refreshed with WooCommerce's own sync after every variation change.
* Multisite is unsupported.
* Real managed or shared hosting has not been tested.

== Installation ==

1. Install and activate a supported WooCommerce version (10.0 through 11.x).
2. Install and activate WriteLeash.
3. Open Products → Bulk Prices.
4. Select products and choose Regular Price or Sale Price and the operation.
5. Preview the exact changes and any warnings, then Apply the reviewed changes.

No SSH access, custom database account or manual SQL setup is required.

== Screenshots ==

1. Select products by name, SKU or category before reviewing your price changes.
2. Review exact before-and-after Sale Price changes, including variations and skipped products, before Apply.
3. See changed and remaining products in a background job, and Resume interrupted work.
4. Review completed changes and explained skips; one invalid price does not stop the useful result.
5. A later price or setting change becomes a conflict instead of a blind overwrite. A planned $100 to $80 change leaves a newer $120 edit alone.
6. Review History, job outcomes and the availability of eligible Undo.

== Frequently Asked Questions ==

= Does WriteLeash change sale prices, variations or stock? =

Choose Regular Price or Sale Price for published simple products and variations
of published variable products. Select a variable product to include all of
its variations, or choose individual variations. Regular Price changes
preserve the existing Sale Price and schedule. Sale dates, stock and orders
are not changed. A Sale Price at or above the Regular Price, or a Regular
Price at or below an existing sale, is refused because WooCommerce would
clear the sale.

= Which newer edits are protected? =

WriteLeash checks the price being changed and relevant settings: whether the
product exists, its type and publication status, its variation's published
variable parent, currency and price precision. A mismatch becomes a conflict
and the newer value is left alone. Product name, SKU and category changes
alone do not cause a conflict. Routine WordPress or WooCommerce updates within
the supported ranges do not cause conflicts on their own; versions outside
those ranges do.

= What happens to invalid or unsupported products? =

Preview explains why an item cannot change. Those items are skipped while
eligible products continue where safe. Review the results for conflicts and
anything needing attention.

= What happens if the job is interrupted? =

Progress is saved. Return to the job to see its results and remaining work.
If background work stalls, use Resume to continue the existing job.

= Can Undo reverse everything? =

No. Undo restores eligible Regular Price or Sale Price values WriteLeash
changed, while the Undo window is open and the checked price and settings
still allow restoration. It preserves relevant newer edits as conflicts.
Orders, completed sales, emails, webhooks, remote requests, external queues
and other plugins' effects are not reversed.

= How many products can one job change? =

Up to 1,000 selected products per new job in the tested configuration.

= Which versions are supported? =

WooCommerce 10.0 through 11.x. WordPress 7.0 through 7.x (7.0.1 and 7.1.2
exercised), PHP 7.4.33, 8.0.30, 8.1.34 and 8.2.34, MySQL 8.0.44 and MariaDB
10.11.15 were exercised. Real managed or shared hosting has not been tested,
and multisite is unsupported.

== Changelog ==

= 0.2.0 =

* Added live read-only Apply and Undo progress without reloading the page.
* Added an explicit option to include nested subcategories in category selection; Preview freezes the reviewed product IDs.
* Added Conflict to fresh Preview: re-preview conflicted products at their current prices.
* Update public-cache compatibility for default and persistent object-cache modes.
* Update merchant-facing PHP and JavaScript translation readiness.
* Requires WooCommerce 10.0 through 11.x.

= 0.1.0 =

* Bulk Regular Price and Sale Price changes for simple products and variations, with exact Preview before Apply and protection for relevant newer edits.
* Select by product name, SKU or category, with up to 1,000 selected products per new job in the tested configuration.
* Background processing, saved progress, Resume, History and eligible Undo.
* Requires WooCommerce 10.0 through 11.x.
