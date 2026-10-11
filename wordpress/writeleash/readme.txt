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

Bulk edit WooCommerce prices with exact Preview before Apply. Relevant
price/setting conflicts preserve newer values instead of overwriting them.

Select by name, SKU or category. Run background jobs, Resume remaining work
and review History.

= Bulk edit Regular Price and Sale Price =

Set a price, add/subtract a fixed amount, or increase/decrease by a percentage.
Choose Regular Price or Sale Price. Select a variable parent for its variations,
or choose variations individually. Category selection includes direct members
by default; an explicit option includes nested subcategories.

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

= Saved presets and Repeat previous jobs =

Save a price-operation configuration as a preset, then load and edit it.
Presets reuse settings, not old calculated prices, approval or individual
product exclusions. They never schedule or automatically apply updates.

With access to an eligible job in History, choose Repeat as new Preview to
prefill an editable operation. Current eligible prices are recalculated;
category membership may change. A previously refined job suggests only its
included products, without silently re-adding excluded targets. Repeat is not
Undo, Resume or an automatic retry; results and eligibility may differ.

Both workflows require a fresh Preview, review and separate approval before
Apply. Current products, permissions and limits are checked again.

= Preview exact changes before Apply =

Preview shows before-and-after prices, warnings and explained skips. Invalid
or unsupported items are skipped where safe; a safety-limit blocker refuses
the whole Preview. Planned stored prices are not guaranteed shopper prices.

Set product-count, increase/decrease and zero-price limits. Approve separately
to Apply the reviewed products and targets; changed settings need a new Preview.

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

Example: reviewed Regular Price $100, target $80; another edit sets it to $120
before Apply. WriteLeash leaves $120 unchanged. Proceed only through a fresh
Preview and separate approval.

= Background processing and Resume =

Follow live progress or return later. Resume stalled work without repeating
completed changes.

= History and eligible Undo =

History shows results, conflicts and Undo availability. After Apply safely
finishes, Undo can restore eligible prices within its window. Failed current
price/setting checks leave the product alone as a conflict. Regular Price Undo
preserves later sales where valid; Sale Price Undo checks its recorded Regular
Price. Availability does not guarantee restoration.

Undo restores eligible prices only. It does not reverse orders, completed
sales, emails, webhooks, remote requests, external queues or other plugins'
effects.

== Supported scope ==

* Up to 1,000 selected products per new job in the tested configuration. Variations count individually.
* Published core simple products and published variations of published core variable products, in the base store currency. Parent prices are not edited directly.
* Custom product types and universal extension compatibility are unsupported.
* WooCommerce 10.0 through 11.x (version 10.0.0 or newer, below 12.0.0).
* WordPress 7.0.1 and 7.1.2 were exercised. Requires WordPress 7.0 or newer.
* PHP 7.4.33, 8.0.30, 8.1.34 and 8.2.34 were exercised. Requires PHP 7.4 or newer.
* MySQL 8.0.44 and MariaDB 10.11.15 were exercised, with default and Redis Object Cache 2.7.0 (Redis 7.4.2) persistent cache modes.
* WooCommerce refreshes variable parent price ranges after each variation change.
* Multisite is unsupported.
* Real managed or shared hosting has not been tested.

== Installation ==

1. Install and activate WooCommerce 10.0 through 11.x.
2. Install and activate WriteLeash.
3. Open Products → Bulk Prices.
4. Select products, price field and operation.
5. Review Preview prices and warnings, then approve separately and Apply.

== Screenshots ==

1. Earlier selection-screen example: choose products by name, SKU or category. Current category selection also offers nested subcategories.
2. Review exact before-and-after Sale Price values, variations, warnings and skips in this earlier Preview example.
3. Background-job example with 24 products: see changed and remaining products, and Resume interrupted work. This capture predates live progress updates.
4. Review completed changes and explained skips; one invalid price does not stop the useful result.
5. A later price or setting change becomes a conflict instead of a blind overwrite. Regular Price example: expected $100, planned $80, current $120 remains unchanged.
6. Review History outcomes and eligible Undo availability in this earlier example. Availability does not guarantee that every product will restore.

Earlier authentic captures; current labels and controls may differ.
Not all features are pictured.

== Frequently Asked Questions ==

= Can I bulk edit sale prices and variation prices? =

Yes, within the supported scope above. Nonblank sale targets must be below
Regular Price; regular targets must remain above an existing sale.

= What happens if a price changes after Preview? =

Target-price, product/parent eligibility, currency and precision checks can
refuse a change. Review conflicts in a fresh Preview with separate approval.
Checks compare current values: changes away and back may not be detected.

= Can I review the Preview without changing prices? =

Yes. Preview and its CSV do not change prices or grant approval. Review the
final products, prices, warnings and skips before approving.

= Can Undo reverse everything? =

No. Only confirmed eligible prices can be restored. Missing evidence,
ineligible products, changed relevant values, uncertain outcomes or an expired
window can prevent Undo. Orders, sales and external effects are not reversed.

= Should I back up my store before a large update? =

Yes. Back up and test on staging first. Preview and eligible Undo do not
replace backups. WriteLeash creates no backups.

== Changelog ==

= 0.2.0 =

* Added live read-only Apply and Undo progress without page reloads.
* Added Conflict to fresh Preview for conflicted products at current prices.
* Added optional nested subcategories and clearer selection counts; Preview freezes reviewed product IDs.
* Added Clear Sale Price and sale discounts from reviewed Regular Price.
* Added optional Price Endings, subject to precision, eligibility and safety limits.
* Added save/load editable price-operation presets; fresh Preview and separate approval required.
* Added individual product exclusions before final Preview approval.
* Added Repeat from eligible History jobs into an editable fresh Preview with separate approval; previously excluded targets stay out of its suggestions.
* Added frozen saved-Preview CSV export before approval; no prices changed.
* Added inclusive stored Regular/Sale Price range selection.
* Updated public-cache compatibility for default and persistent object-cache modes.
* Updated merchant-facing PHP and JavaScript translation readiness.
* Requires WooCommerce 10.0 through 11.x.

= 0.1.0 =

* Bulk Regular Price and Sale Price changes for simple products and variations, with exact Preview before Apply and protection for relevant newer edits.
* Select by product name, SKU or category, with up to 1,000 selected products per new job in the tested configuration.
* Background processing, saved progress, Resume, History and eligible Undo.
* Requires WooCommerce 10.0 through 11.x.
