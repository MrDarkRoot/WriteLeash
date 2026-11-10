---
title: "Undo a WooCommerce bulk price edit from the pre-change CSV"
slug: undo-woocommerce-bulk-price-edit
meta_description: "WooCommerce bulk edit writes prices on Update and has no undo. Restore them from a dated CSV. A 20% cut is not fixed by raising 20%."
h1: "Undo a WooCommerce bulk price edit from the pre-change CSV"
card: "Restore the old prices from a CSV taken before the bulk edit. Do not reverse a percentage cut with the same percentage."
intent: "Recover regular or sale prices after the products-list bulk editor or the variation bulk actions wrote the wrong numbers."
failure_family: "Accidental bulk price edit"
affiliation: "WriteLeash publishes this guide. It is not an independent review."
canonical_status: INTENDED
date_revised: 2026-11-10
---

# Undo a WooCommerce bulk price edit from the pre-change CSV

Stop. Do not raise the prices by the same percentage you just cut, and do not upload another price file until you know the old numbers. WooCommerce writes the new prices when you press **Update**. The [bulk editing guide](https://woocommerce.com/document/bulk-editing-products/), opened on 10 November 2026, does not describe an undo control. It says to edit the affected products again if a value is wrong. The repair is a CSV exported before the mistake: keep ID or SKU and the price columns you mean to put back, and set every other column to **Do not import**.

**WriteLeash publishes this guide.** It is not an independent review. The steps use WooCommerce. You do not need WriteLeash to follow them.

## Restore the old prices first

Work on a staging copy if you have one. On a live store, change one SKU and read it back before you touch the rest.

1. Stop scheduled imports, sync tools, and a second bulk edit. A repair that runs twice is how the bad price returns.
2. Find a CSV from **Products > All Products > Export** that was saved before the edit. The [CSV guide](https://woocommerce.com/document/product-csv-importer-exporter/), opened the same day, matches updates by ID or SKU when **Update existing products** is on.
3. Build a restore file with the identifier and only the price columns you intend to write. Include **Date sale price starts** and **Date sale price ends** if a schedule was cleared. Delete every other column, or set those columns to **Do not import** on the mapping screen.
4. Import one row. Open the product and compare the regular price, the sale price, and both dates with the file. Then import the rest in batches you can check.
5. If you have no pre-change export, restore a database backup onto staging, export the price columns from staging, and import those columns into the live store with the same mapping. Do not restore the live database over a store that has taken orders since the backup. Those orders roll back with the prices.
6. An order line is the amount that customer was charged. It is not a catalog history. Products that did not sell are missing from it.

A products-list bulk edit that changes a regular or sale price also clears the scheduled sale start and end dates. The same guide says to set the schedule again on each affected product if you still need it. Putting the date columns back from the old export is the direct way to do that. This pass did not re-run that clear in a store.

Leave Price and Sale on **No change** unless you mean to write them. The guide says a field left on **No change** keeps its current value. The October 2026 text of that page said a blank or 0 on **Set to regular price decreased by** removes the sale. The page opened on 10 November 2026 no longer says that. Do not treat a blank box as either safe or as a documented clear. This research did not execute the blank field.

If the editor shows the old sale and the shop still shows the regular price, stop importing. That is [a saved sale the shop does not show](imported-sale-price-not-showing.md). If the sale field itself is empty, use [the CSV sale restore](restore-sale-prices-overwritten-by-import.md). Installing a history plugin after the edit does not recreate the lost values. [Keep a dated export next time](woocommerce-price-history.md).

## Do not reverse a percentage with itself

Store currency with two decimal places. Three published simple products, no sale prices:

| SKU | Regular price before | After a 20% decrease |
| --- | --- | --- |
| HOODIE | 80.00 | 64.00 |
| CAP | 24.00 | 19.20 |
| MUG | 12.50 | 10.00 |

Those three results are exact at two decimals: each old price times 0.8. Increasing the new prices by 20% does not return the old prices:

| SKU | After the 20% decrease | Increase that result by 20% | Original |
| --- | --- | --- | --- |
| HOODIE | 64.00 | 76.80 | 80.00 |
| CAP | 19.20 | 23.04 | 24.00 |
| MUG | 10.00 | 12.00 | 12.50 |

The inverse of multiplying by 0.8 is multiplying by 1.25, a 25% increase. That lands on 80.00, 24.00, and 12.50 only when every row in the batch was decreased by the same percentage, the stored results match the arithmetic, and nobody has edited those prices since. A fixed decrease of 5.00 is reversed by a fixed increase of 5.00 under the same conditions. Rounding can break the reverse. At two decimals, 19.99 times 0.85 is 16.9915 and rounds to 16.99. That figure is arithmetic, not a price this research measured inside WooCommerce. Check one product before you touch the rest.

A merchant reported the same surprise in [woocommerce/woocommerce#17885](https://github.com/woocommerce/woocommerce/issues/17885) on 23 November 2017, as read for the 27 October 2026 pass: after a percentage decrease, increasing by that same percentage did not put the old sale price back. The issue was closed the same day for a different reason. **Decrease existing sale price** does nothing unless a sale price already exists. The thread does not name a later release that changes the arithmetic.

A bulk edit can also stop halfway. [woocommerce/woocommerce#37681](https://github.com/woocommerce/woocommerce/issues/37681), opened in April 2023 and cited on 27 October 2026, describes a sale-price bulk edit that hit a blank regular price and left the list partly updated. That bug is not a claim about today's code. Export the current prices before a repair so you can see which rows moved.

A February 2026 support thread, [bulk edit and decimal places](https://wordpress.org/support/topic/bulk-edit-price-decimal-places-bug/), was read on 27 October 2026 and not re-opened for this pass. It was one store on WooCommerce 10.4.3. Support said bulk edit saved with the store's decimal setting. Do not assume a four-decimal price survived the bulk editor. Read the product after one row.

## Which bulk editor you used

Use the screen you actually used. They do not write the same rows.

**Products > All Products > Bulk actions > Bulk edit, then Apply.** The guide opened on 10 November 2026 says the Price field can change to a value, or increase or decrease the existing price by a fixed amount or a percent. The Sale field can change to a value, increase or decrease the existing sale, or set the sale to the regular price decreased by a fixed amount or a percent. It says to check the resulting sale price after that last option. By default, bulk price changes on this screen apply to simple and external products, not to the individual variations of a variable product. The edit applies to the products selected on the current page. A large page can strain the server.

**One variable product > Variations > Bulk actions.** This is a different menu, on the [variable product page](https://woocommerce.com/document/variable-product/) opened the same day. Those actions save immediately to the variations of that one product. For **Set sale prices to regular prices decreased by**, a regular price of 100 and an entry of 15 stores a sale price of 85. An entry of 20% stores 80. The action replaces any existing sale price. Entering 0 clears existing sale prices. Blank, non-numeric, or negative input leaves existing sale prices unchanged for that action. Cancel leaves them unchanged. Variations with no regular price are skipped. The sale price cannot go below zero.

Quick Edit is a third path. It shows that product's current values and writes when you update. It is not an undo of a previous bulk edit.

## What WriteLeash 0.3.0 will and will not undo

The WordPress.org directory page was opened on 10 November 2026. The listed version is WriteLeash 0.3.0. The active-installation bucket was fewer than 10. The listing was last modified on 28 October 2026. The "Tested up to" field read 7.1.4. That field is not the list of versions the requirements text says were exercised.

If the bad write was the products list, Quick Edit, or the variation bulk actions, WriteLeash Undo does not reverse it. Undo restores eligible prices that WriteLeash itself applied, when the saved details and the current product still allow it. The listing screenshot describes eligible Undo within 30 days. Availability does not mean every row will restore. Undo does not reverse orders, completed sales, emails, webhooks, remote requests, external queues, or other plugins' effects. WriteLeash does not create a backup. Stock Undo is not supported.

For a later change made in WriteLeash, Preview shows the before and after and writes nothing. Approval is a separate step. If the stored price WriteLeash is changing, or another checked setting, no longer matches the Preview, WriteLeash leaves the newer value and marks a conflict. Product name, SKU, and category alone do not cause that conflict. A value that moves away and comes back may not be detected. A regular-price change keeps the existing sale price and its dates. Ordinary price jobs do not change stock or orders. Clear makes a sale price blank, not zero.

Scope on that listing: published core simple products and published variations of published core variable products, in the base currency, up to 1,000 selected products per new job in the tested configuration. Parent prices are not edited directly. Custom product types are unsupported. Multisite is unsupported. Real managed or shared hosting has not been tested. Planned stored prices are not guaranteed shopper prices.

Requirements, from the listing and the plugin readme: WooCommerce 10.0 through 11.x on WordPress 7.0 up to 8.0 only (10.0.0 or newer, below 12.0.0), or the legacy band of WordPress 6.7 below 7.0 with WooCommerce 9.0.0 below 10.0.0. Exercised pairs named there: WordPress 7.0.1 and 7.1.2 on the modern line, WordPress 6.7.2 with WooCommerce 9.0.4, and WordPress 6.8.3 with WooCommerce 9.9.7. Other pairs are refused before a job is created.

Opt-in grants for bounded price and stock jobs shipped in 0.3.0. They stay off until an operator issues one. Upgrade from 0.2.2 does not create a grant. A restricted WordPress user is denied on documented monitored WooCommerce write paths outside a matching Execution Frame. It is not a firewall, not a sandbox, and not a universal agent-safe guarantee. Direct SQL, hostile plugins, and unmonitored writes are not covered. There is no guarantee when the grant store is unavailable. [Who can still change a price](restrict-woocommerce-price-changes.md) is a separate job from undoing one.

Other bulk editors may preview before they save, or keep a snapshot of their own runs. [Bulk Price Editor for WooCommerce](https://wordpress.org/plugins/bulk-price-editor-for-woocommerce/) 2.5.1 was opened on 10 November 2026. Its FAQ still says the plugin does not have an undo feature. Its 2.5.0 changelog, dated 1 September 2026, says each bulk update stores previous prices and marks one-click revert as premium. Read the version you install. This guide is not a ranking.

## Related guides

- [Restore sale prices a CSV import overwrote](restore-sale-prices-overwritten-by-import.md)
- [Why a saved sale can be missing on the shop](imported-sale-price-not-showing.md)
- [Keep a WooCommerce price history with a dated CSV](woocommerce-price-history.md)

## FAQ

### Does WooCommerce include an undo for bulk price edits?

No. The bulk editing guide opened on 10 November 2026 describes Update and says to edit the products again if a value is wrong. It does not describe an undo control. A backup, an older export, or a history tool that was already recording are the recovery paths.

### Will increasing by 20% undo a 20% decrease?

No. In the example above, a 20% decrease is reversed by a 25% increase, and only when the stored prices are exactly the decreased values and nothing else has changed them.

### Will product revisions put the old price back?

Do not plan on it. This guide did not find WooCommerce documenting revisions as a price undo. Confirm on a staging copy, on one product, before you count on a revision to restore the regular price and the sale price.

### Will WriteLeash undo a bulk edit that already ran?

No. WriteLeash Undo restores eligible prices WriteLeash applied. A native bulk edit is outside that history. Stock Undo is not supported. Undo does not reverse orders, emails, webhooks, or other plugins.

WriteLeash 0.3.0 previews a price change and can undo eligible prices it applied. It will not undo the bulk edit you already saved. The directory listing is [WriteLeash on WordPress.org](https://wordpress.org/plugins/writeleash/). The source is the [WriteLeash repository](https://github.com/MrDarkRoot/WriteLeash).
