=== WriteLeash ===
Contributors: duyytrann
Tags: woocommerce, bulk edit, prices, variations, undo
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 0.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce bulk price editor with Preview, newer-price conflict checks, History and eligible Undo.

== Description ==

**Change WooCommerce prices. Protect newer edits.**

Bulk edit WooCommerce prices with a review before Apply. Preview exact
before-and-after prices for supported simple products and variations. If a
relevant current price or checked setting no longer matches your review,
WriteLeash preserves the conflicting newer value instead of overwriting it.

Select by name, SKU or category. Work runs in the background; Resume
interrupted jobs, review History and Undo eligible changes. Up to 1,000
selected products per new job in the tested configuration.

= Bulk edit Regular Price and Sale Price =

Set a price, add or subtract a fixed amount, or increase or decrease by a
percentage. Choose Regular Price or Sale Price for published simple products
and variations of published variable products, in your base store currency.
Select a variable product to include its variations, or choose individual
variations. Category selection includes direct members by default; an explicit
option also includes nested subcategories.

A Regular Price change preserves the existing Sale Price and sale schedule.
Use Set to add a first Sale Price, or calculate a sale discount from each
product's reviewed Regular Price. Clear Sale Price makes the sale price blank,
not zero, and keeps its dates. Sale dates, stock and orders are not changed.

Choose optional .99, .95, .90 or whole-number Price Endings where precision
allows. Final prices still pass eligibility and safety limits; Clear ignores
endings.

= Choose products by price range and exclude individual items =

Filter selected products by an inclusive stored Regular Price or Sale Price
range. Both endpoints count; variations use their own prices. Blank sales do
not match numeric ranges. Shopper and parent display prices are not the basis.

Exclude individual products or variations before approval, then review the
updated Preview. Confirmed excluded targets are left out of Apply and Undo.
Existing selection and count limits still apply.

= Preview exact changes before Apply =

Preview shows each product or variation with before-and-after prices,
warnings and explained skips. Unsupported or invalid items are skipped;
eligible products can continue where safe. Safety-limit blockers refuse the
whole Preview. Planned stored prices are not guaranteed shopper prices.

Set limits for product count, price increases, price decreases and zero-price
targets. Apply uses the products and target prices you reviewed. Changing the
selection or price settings requires a new Preview.

Download Preview CSV before approval. It reflects the saved Preview, not the
live catalog, and does not apply changes.

= Preserve newer edits =

If the stored price WriteLeash is changing or another checked setting no
longer matches the preview, WriteLeash leaves the newer value alone and marks
that product as a conflict. Other eligible products can continue.

A Regular Price change preserves a later Sale Price or schedule where valid.
A nonblank Sale Price target must stay below Regular Price. Clear and sale
discounts also check the reviewed Regular Price basis. Product name, SKU and
category are reference details only; changing them alone does not conflict.

Example: preview an eligible product's Regular Price at $100 with an $80
target. Another edit makes it $120 before Apply reaches it. WriteLeash leaves
$120 unchanged. Review the conflict through a fresh Preview and separate
approval. Checks compare relevant current values, not every intervening edit.

= Background processing and Resume =

Jobs run in the background with saved progress. Close the browser and return
to see results and remaining work. If work stalls, Resume continues remaining
products without starting again or repeating completed changes.

= History and eligible Undo =

History shows results, conflicts and Undo availability.

After Apply safely finishes, Undo restores eligible prices within the
available window. A failed price or setting check leaves that product alone
as a conflict. Regular Price Undo preserves later sales if restoration is
valid; Sale Price Undo also checks its recorded Regular Price. History
availability does not guarantee every product will restore.

Undo restores eligible prices only. It does not reverse orders, completed
sales, emails, webhooks, remote requests, external queues or other plugins'
effects.

== Supported scope ==

* Up to 1,000 selected products per new job in the tested configuration.
* Published core simple WooCommerce products.
* Published core variable WooCommerce products: selecting the parent targets all of its variations, or select individual variations.
* Stored regular and sale prices in the base store currency.
* Adding a first Sale Price with Set or a discount from reviewed Regular Price; changing an existing sale with fixed or percentage adjustments, Set, Clear or a relative discount.
* Regular-price edits on products with an existing sale price or schedule (the sale is preserved).
* WooCommerce 10.0 through 11.x (version 10.0.0 or newer, below 12.0.0).
* WordPress 7.0.1 and 7.1.2 were exercised. Requires WordPress 7.0 or newer.
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
5. Preview the prices and warnings, then approve and Apply the reviewed changes.

== Screenshots ==

1. Earlier selection-screen example: choose products by name, SKU or category. Current category selection also offers nested subcategories.
2. Sale Price Preview example: review before-and-after values, variations, warnings and skipped products before Apply.
3. Background-job example with 24 products: see changed and remaining products, and Resume interrupted work. This capture predates live progress updates.
4. Review completed changes and explained skips; one invalid price does not stop the useful result.
5. A later price or setting change becomes a conflict instead of a blind overwrite. Regular Price example: expected $100, planned $80, current $120 remains unchanged.
6. History example: review job outcomes and eligible Undo availability. Availability does not guarantee that every product will restore.

Earlier authentic captures; current labels and controls may differ.
Not all features are pictured.

== Frequently Asked Questions ==

= Can I bulk edit sale prices and variation prices? =

Yes, for supported core simple products and variations. Set or a discount
from Regular Price can add a first sale; Clear makes Sale Price blank.
Nonblank sale targets must be below Regular Price, and regular targets must
remain above an existing sale. Sale dates, stock and orders are not edited.

= What happens if a price changes after Preview? =

WriteLeash checks the target price and relevant product/parent eligibility,
currency and precision. A mismatch becomes a conflict; newer values are left
alone. Name, SKU and category edits alone do not conflict. Clear and discounts
from Regular Price also check the reviewed Regular Price basis. Use a fresh
Preview and separate approval to proceed. These checks compare current values;
changes away and back to matching values may not be detected.

= Can I review the Preview without changing prices? =

Yes. Preview and Download Preview CSV do not change prices. Review the final
product list, proposed prices, warnings and skips, then approve it separately.
A CSV is a saved Preview snapshot, not a live catalog export or approval.

= Can Undo reverse everything? =

No. Undo restores only confirmed eligible prices, after Apply safely finishes
while the window is open. Changed relevant values, missing evidence,
ineligible products or uncertain outcomes can prevent restoration. Sale Price
Undo also checks its recorded Regular Price basis. Orders, completed sales and
external plugin/service effects are not reversed.

= Should I back up my store before a large update? =

Yes. Back up and test on staging first. Preview and eligible Undo do not
replace backups or reverse all external effects. WriteLeash creates no backups.

= How many products can one job change? =

Up to 1,000 selected products per new job in the tested configuration.

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
