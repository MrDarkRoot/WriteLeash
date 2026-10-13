=== WriteLeash ===
Contributors: duyytrann
Tags: woocommerce, bulk edit, prices, variations, undo
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 0.2.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk edit WooCommerce prices safely: exact Preview, newer-edit conflict protection, background jobs, History and eligible Undo. No ads or tracking.

== Description ==

**Bulk edit WooCommerce prices. Never overwrite a price someone changed after you reviewed it.**

You prepare a 20% sale for 300 products. While you review, a colleague fixes one price by hand. Many bulk editors apply your number anyway, and the fix is silently lost.

WriteLeash checks every product again at the moment of Apply. If a price changed after your Preview, it keeps the newer value, marks the product as a conflict, and carries on with the rest.

= How it works =

1. Select products by name, SKU, category or ID.
2. Preview every change in exact before-and-after prices.
3. Approve separately, then Apply in the background.
4. Review History and restore eligible prices with Undo.

= Built for stores where a wrong price costs money =

* **Nothing changes until you approve it.** Preview first, then a separate Approve step. Change any setting and you get a new Preview.
* **See what shoppers would pay after Apply.** Before-and-after prices, delta and percent change, and a plain-language reason for every skipped product.
* **Safety limits you control.** Cap how many products can change, set maximum increase and decrease percentages, get warnings on big swings, and block zero prices.
* **Runs in the background.** Follow live progress, or close the tab and come back later. Resume skips work that is already done.
* **History and eligible Undo.** See who changed what and when, and restore eligible prices within the Undo window (30 days by default).
* **Presets and Repeat.** Save a price-operation setup as a reusable preset, or repeat an eligible job from History as a fresh, editable Preview.

= Choose products your way =

Search by product name or SKU, pick a category (optionally including nested subcategories), enter exact IDs, or choose a variable parent to target all of its variations. Narrow the list with an inclusive Regular Price or Sale Price range, and exclude individual products or variations before approval; confirmed exclusions are left out of Apply and Undo.

= Bulk edit Regular Price and Sale Price =

Set a price, add or subtract a fixed amount, or increase or decrease by a percentage. Choose Regular Price or Sale Price. A Regular Price change preserves the existing Sale Price and sale schedule. Use Set to add a first Sale Price, or calculate a sale discount from each product's reviewed Regular Price. Clear Sale Price makes the sale price blank, not zero, and keeps its dates.

Use optional .99, .95, .90 or whole-number Price Endings where store precision allows. Sale dates, stock and orders are not changed.

= Preserve newer edits =

If the stored price WriteLeash is changing or another checked setting no longer matches the preview, WriteLeash leaves the newer value alone and marks that product as a conflict. Other eligible products can continue.

Product name, SKU and category are reference details only; changing them alone does not conflict.

Example: reviewed Regular Price $100, target $80; another edit sets it to $120 before Apply. WriteLeash leaves $120 unchanged. Proceed only through a fresh Preview and separate approval.

= Good to know =

* Preview shows planned stored prices, not guaranteed shopper prices. Invalid or unsupported items are skipped where safe, and a safety-limit blocker refuses the whole Preview.
* Download Preview CSV before approval; it reflects the saved Preview, not the live catalog, and does not apply changes.
* Undo availability does not guarantee restoration. Failed current price/setting checks leave the product alone as a conflict, and Undo does not reverse orders, completed sales, emails, webhooks, remote requests, external queues or other plugins' effects.

= Respects your store and your data =

No tracking, no ads, no upgrade nags, no remote code and no auto-updater. Declares WooCommerce HPOS (custom order tables) compatibility. Uninstalling never changes your products or prices.

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

Earlier authentic captures; current labels and controls may differ. Not all features are pictured.

== Frequently Asked Questions ==

= Is WriteLeash free? =

Yes. Every feature is free: no paid tier, no account and no credit card. Preview, conflict protection, Presets, Repeat, background jobs, History and eligible Undo are all included.

= Can I bulk edit sale prices and variation prices? =

Yes, within the supported scope above. Nonblank sale targets must be below Regular Price; regular targets must remain above an existing sale.

= Does WriteLeash change prices automatically? =

No. Every change needs a Preview and a separate approval. Presets and Repeat only prefill settings; they never schedule or apply anything.

= What happens if a price changes after Preview? =

Target-price, product/parent eligibility, currency and precision checks can refuse a change. Review conflicts in a fresh Preview with separate approval. Checks compare current values: changes away and back may not be detected.

= Can I review the Preview without changing prices? =

Yes. Preview and its CSV do not change prices or approve changes. Review the final products, prices, warnings and skips before approving.

= Can Undo reverse everything? =

No. Only confirmed eligible prices can be restored. Missing evidence, ineligible products, changed relevant values, uncertain outcomes or an expired window can prevent Undo. Orders, sales and external effects are not reversed.

= Should I back up my store before a large update? =

Yes. Back up and test on staging first. Preview and eligible Undo do not replace backups. WriteLeash creates no backups.

== Changelog ==

= 0.2.1 =

* Refreshed the plugin listing with clearer benefits, a How it works summary and an FAQ entry confirming every feature is free.
* No price-change behavior changed in this release.

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
