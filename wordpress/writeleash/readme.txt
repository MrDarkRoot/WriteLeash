=== WriteLeash ===
Tags: woocommerce, bulk edit, prices, undo, safety
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safety for high-impact WooCommerce changes: preview bulk regular- or sale-price edits, approve the exact plan and undo eligible changes.

== Description ==

Safety for high-impact WooCommerce changes.

WriteLeash Free changes the stored regular or sale price of published simple
WooCommerce products in the base store currency. Open Products → Bulk Prices,
choose the price to change (regular or sale), choose Set, + fixed, - fixed,
+ percent or - percent, build a frozen preview and approve the exact plan.

Before anything is saved you see the exact before-and-after value of the
selected price for every selected product, plus exclusions, blockers and
safety-limit outcomes. Approval is bound to that frozen plan. If the stored
value of the changed price field or another execution precondition no longer
matches the approved plan, that item is reported as a conflict instead of being
blindly overwritten. Progress is durable, so an
interrupted job can be resumed in bounded chunks, and eligible WriteLeash changes
can be undone later without overwriting
newer edits.

= What you see before execution =

* The selected products and the exact before-and-after value of the chosen price.
* Items that are unchanged, unsupported or blocked by your safety policy, with a reason.
* Counts for eligible, changing, unchanged, unsupported and blocked items.
* Safety limits for product count, maximum increase, maximum decrease and zero-price targets.

Approval executes exactly the frozen product IDs and the absolute target prices
that were previewed. Changing the selection, operation, price field or safety
policy requires a new preview; an approval cannot be reused for different inputs.

= What happens when the store changes after preview =

The preview freezes the intended product IDs and target prices. Execution
rechecks the stored value of the changed price field and the execution
preconditions before saving. If the stored regular price or another execution
precondition no longer matches
the approved plan, that item is reported as a conflict instead of being blindly
overwritten. A regular-price plan preserves an external sale price or schedule
change and never overwrites it; a sale-price plan requires its target to stay
below the regular price. SKU, name/title and category membership are provenance-only;
changes to those fields alone do not necessarily cause an execution conflict.

= What happens when execution stops =

Progress is durable, so a closed browser or an interrupted worker does not lose
the job. A stalled job pauses and shows truthful remaining work. The protected
Resume action continues the existing job in bounded chunks without re-planning
it.

= What Undo can restore =

Undo restores eligible stored regular- or sale-price values that WriteLeash
previously applied when the durable evidence and the current product state permit
it. If a
stored price value or another Undo execution precondition no longer matches
the durable Apply evidence, that item is reported as a conflict and is not
overwritten. A regular-price Undo is not blocked by a later sale change and
preserves it; a sale-price Undo checks the regular price recorded at apply time.
Undo does not reverse orders, completed
sales, emails, webhooks, remote HTTP requests, external queues or other plugin
side effects.

== Supported scope ==

* Up to 100 selected products per new job in the tested configuration.
* Published core simple WooCommerce products.
* Stored regular and sale prices in the base store currency.
* Adding a first sale price with Set; changing an existing sale with any operation.
* Regular-price edits on products with an existing sale price or schedule (the sale is preserved).
* WooCommerce 10.0 through 11.x (version 10.0.0 or newer, below 12.0.0).
* WordPress 7.0 through 7.x is supported. WordPress 7.0.1 and 7.1.2 were exercised. Requires WordPress 7.0 or newer.
* PHP 7.4.33, 8.0.30, 8.1.34 and 8.2.34 were exercised. Requires PHP 7.4 or newer.
* MySQL 8.0.44 and MariaDB 10.11.15 were exercised, with default and Redis Object Cache 2.7.0 (Redis 7.4.2) persistent cache modes.
* Not changed: sale dates, variations, stock and orders.
* Multisite is unsupported.
* Real managed or shared hosting has not been tested.

== Installation ==

1. Install and activate a supported WooCommerce version (10.0 through 11.x).
2. Install and activate WriteLeash.
3. Open Products → Bulk Prices.
4. Select products and an operation, then build a frozen preview.
5. Review the before-and-after prices and any warnings, then approve the exact plan.

WriteLeash uses the normal WordPress database connection and normal WooCommerce
product APIs. No SSH access, custom database account or manual SQL setup is
required.

== Screenshots ==

1. Review the exact before-and-after prices of the chosen field before approval.
2. A safety policy blocks the plan before any product is saved.
3. A later regular-price or execution-state change becomes a conflict instead of a blind overwrite.
4. An interrupted job shows truthful remaining work and a protected Resume action.
5. A partial result reports per-outcome counts instead of claiming global success.
6. History shows which WriteLeash changes are eligible for conflict-aware Undo.

== Frequently Asked Questions ==

= Does WriteLeash change sale prices, variations or stock? =

It changes the stored regular price or the stored sale price you choose for
published core simple products. A regular-price change preserves an existing
sale price and schedule. Sale dates themselves, variations, stock and orders
are outside the supported scope. Setting a sale price at or above the regular
price (or a regular price at or below the sale) is refused because WooCommerce
would clear the sale.

= What happens if the regular price or execution state changes after approval? =

If the stored regular price or another execution precondition no longer matches
the approved plan, that item is reported as a conflict instead of being blindly
overwritten. Execution preconditions include product existence, core-simple/type
state, publication status, the stored value of the changed price field,
currency/base context and price
decimals. Routine WordPress/WooCommerce updates inside the supported ranges do
not cause conflicts on their own; versions outside the supported ranges do.
SKU, name/title and category
membership are provenance-only; changes to those fields alone do not necessarily
cause an execution conflict.

= What happens if execution is interrupted? =

Progress is durable, so a closed browser does not lose the job. If work stalls,
the protected Resume action continues the existing job in bounded chunks.

= Can Undo reverse everything? =

No. Undo restores eligible stored regular- or sale-price values that WriteLeash
applied
when the durable evidence and the current product state permit it. A stored
price or Undo execution-precondition mismatch is reported as a conflict
instead of being overwritten. Orders, completed sales,
emails, webhooks, remote HTTP requests, external queues and other plugin side
effects are not reversed.

= How many products can one job change? =

Up to 100 selected products per new job in the tested configuration.

= Which versions are supported? =

WooCommerce 10.0 through 11.x. WordPress 7.0 through 7.x (7.0.1 and 7.1.2
exercised), PHP 7.4.33, 8.0.30,
8.1.34 and 8.2.34, MySQL 8.0.44 and MariaDB 10.11.15 were exercised. Real
managed or shared hosting has not been tested, and multisite is unsupported.

== Changelog ==

= 0.1.0 =

* Initial WooCommerce product: frozen bulk regular- or sale-price previews, approval-bound execution, durable progress, protected bounded Resume, bounded history and conflict-aware eligible Undo.
* Supports up to 100 selected products per new job in the tested configuration.
* Requires WooCommerce 10.0 through 11.x and uses the normal WordPress database connection.
