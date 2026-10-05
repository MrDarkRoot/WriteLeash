=== WriteLeash ===
Tags: woocommerce, bulk edit, prices, undo, safety
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Preview WooCommerce bulk price changes, run them safely, and undo changes that are still safe to restore.

== Description ==

Preview bulk price changes before they happen. Run them safely. If the price or
a checked setting changed after review, WriteLeash leaves the newer value alone.
Undo changes that are still safe to restore.

WriteLeash Free changes the stored regular price or sale price of published
simple WooCommerce products and variations of published variable products in
the base store currency. Open Products → Bulk Prices, choose the products,
choose whether to change regular prices or sale prices, pick an operation
(Set, + fixed, - fixed, + percent or - percent), then preview the exact
before-and-after values before anything is saved.

= What you see before anything runs =

* The selected products and the exact before-and-after value of the chosen price.
* Items that would not change, are unsupported or are blocked by your safety settings, with a plain-language reason.
* Counts for planned changes, products already at the target price and skips.
* Safety limits for product count, maximum increase, maximum decrease and zero-price targets.
* For variable products, every selected variation named by its product and attributes.

Approving applies exactly the products and target prices shown in the preview.
Changing the selection or the price settings requires a new preview.

= What happens when a product changes after review =

The preview saves the intended products and target prices. When the change
runs, WriteLeash rechecks the price being changed and the checked product
settings. If the stored price WriteLeash is changing or another checked setting
no longer matches the preview, WriteLeash leaves the newer value alone and
marks that product as a conflict. A regular-price change preserves an external
sale price or schedule and never overwrites it; a sale-price change requires
the sale to stay below the regular price. SKU, product name and category are
reference details only; changing them does not by itself cause a conflict.

= What happens when the change stops =

Progress is saved, so a closed browser or an interrupted background step does
not lose the change. A stalled job pauses and shows truthful remaining work.
The Resume action continues the existing job in small steps without rebuilding
the preview.

= What Undo can restore =

Undo restores eligible stored regular-price or sale-price values that WriteLeash
previously applied, when the saved details and the current product state permit
it. If a stored price value or another checked setting no longer matches what
WriteLeash recorded when it applied, that product is left alone and shown as a
conflict instead of being overwritten. A regular-price Undo is not blocked by a
later sale change and preserves it; a sale-price Undo checks the regular price
recorded at apply time. Undo does not reverse orders, completed sales, emails,
webhooks, remote HTTP requests, external queues or other plugin side effects.

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
4. Select products, choose the regular price or sale price and the operation.
5. Preview the before-and-after prices and any warnings, then approve the preview.

WriteLeash uses the normal WordPress database connection and normal WooCommerce
product APIs. No SSH access, custom database account or manual SQL setup is
required.

== Screenshots ==

1. Select products and preview every regular-price or sale-price change before it runs.
2. Review the exact before-and-after values for the price you chose, with counts for changes, skips and blocks.
3. A later price or setting change becomes a conflict instead of a blind overwrite.
4. Results list each product with its expected, current and planned value, plus anything needing attention.
5. A large job shows what is done, what remains and what needs attention, page by page.
6. History shows each price change with its Undo availability and how long the restoration window stays open.

== Frequently Asked Questions ==

= Does WriteLeash change sale prices, variations or stock? =

It changes the stored regular price or the stored sale price you choose for
published core simple products and for variations of published core variable
products. Selecting a variable parent targets all of its variations; you can
also select individual variations. A regular-price change preserves an existing
sale price and schedule. Sale dates themselves, stock and orders are outside
the supported scope. Setting a sale price at or above the regular price (or a
regular price at or below the sale) is refused because WooCommerce would clear
the sale.

= What happens if the price or a checked setting changes after approval? =

If the stored price WriteLeash is changing or another checked setting no longer
matches the preview, WriteLeash leaves the newer value alone and marks that
product as a conflict. The checked settings include product existence, product type
(including a variation's published variable parent), publication status, the
stored value of the price being changed, currency and price precision. Routine
WordPress or WooCommerce updates inside the supported ranges do not cause
conflicts on their own; versions outside the supported ranges do. SKU, product
name and category are reference details only; changing them does not by itself
cause a conflict.

= What happens if the change is interrupted? =

Progress is saved, so a closed browser does not lose the job. If work stalls,
the Resume action continues the existing job in small steps.

= Can Undo reverse everything? =

No. Undo restores eligible stored regular-price or sale-price values that
WriteLeash applied when the saved details and the current product state permit
it. A stored price or checked-setting mismatch is left alone and shown as a
conflict instead of being overwritten. Orders, completed sales, emails,
webhooks, remote HTTP requests, external queues and other plugin side effects
are not reversed.

= How many products can one job change? =

Up to 1,000 selected products per new job in the tested configuration.

= Which versions are supported? =

WooCommerce 10.0 through 11.x. WordPress 7.0 through 7.x (7.0.1 and 7.1.2
exercised), PHP 7.4.33, 8.0.30, 8.1.34 and 8.2.34, MySQL 8.0.44 and MariaDB
10.11.15 were exercised. Real managed or shared hosting has not been tested,
and multisite is unsupported.

== Changelog ==

= 0.1.0 =

* Initial WooCommerce product: preview bulk regular- or sale-price changes, approve the exact preview, keep saved progress, resume interrupted work in small steps, and undo changes that are still safe to restore.
* Supports up to 1,000 selected products per new job in the tested configuration.
* Requires WooCommerce 10.0 through 11.x and uses the normal WordPress database connection.
