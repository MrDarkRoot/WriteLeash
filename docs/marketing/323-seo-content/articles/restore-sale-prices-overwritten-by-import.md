---
title: "Restore WooCommerce sale prices a CSV import overwrote"
slug: restore-sale-prices-overwritten-by-import
meta_description: "A mapped Sale price column overwrites the stored sale. Restore it from the pre-import export, and set every other column to Do not import."
h1: "Restore WooCommerce sale prices a CSV import overwrote"
card: "Put the old sale prices back from the pre-import export. On the mapping screen, set every other column to Do not import."
intent: "Put back a sale price or sale schedule that a product CSV update changed, without writing the rest of the catalog again."
failure_family: "Import or plugin overwrite of sale prices"
affiliation: "WriteLeash publishes this guide. It is not an independent review."
canonical_status: INTENDED
date_revised: 2026-11-10
---

# Restore WooCommerce sale prices a CSV import overwrote

Stop the sync or the scheduled import, then put the old sale back from the export you saved before the bad file. On the column mapping screen, set every column you are not restoring to **Do not import**. Test one SKU before the rest of the file. A second full import is how a correct sale gets overwritten again.

**WriteLeash publishes this guide.** It is not an independent review. The restore uses WooCommerce's importer and an export you already have, or a backup. You do not need WriteLeash to do it.

## Put the sale prices back

1. Pause any sync, feed, or scheduled import that might run the same file again.
2. Take the export from before the bad import. The [CSV guide](https://woocommerce.com/document/product-csv-importer-exporter/), opened on 10 November 2026, says an update matches by ID or SKU when **Update existing products** is on. In WooCommerce 11.2 and later, a mapped GTIN, UPC, EAN, or ISBN is used only when both ID and SKU are blank.
3. If you do not have that export, restore a database backup on staging and export the price columns there. Do not restore that backup over the live database if the store has taken orders since. Orders would roll back with the catalog.
4. Make a CSV with ID or SKU, Sale price, Date sale price starts, and Date sale price ends. Include Regular price when the regular price also changed. Delete every other column, or map those columns to **Do not import**.
5. Run that file on one SKU. Open the product. The sale price has to be strictly below the regular price, and the dates have to match the file.
6. Check the shop page, not only the editor. If the editor is right and the shop still shows the regular price, stop. That is [a saved sale the shop does not show](imported-sale-price-not-showing.md). Do not upload another price file to "refresh" it.
7. Import the remaining rows in batches. Read the importer's skipped-row report before you run the file again. The guide says not to refresh or leave the page while the import is running, and to split a file the server cannot finish.

The usual mistake is a stock or title update that still contains a Sale price column. A column WooCommerce recognizes is mapped for you. Unrecognized columns are not imported. The control for "do not touch this" is **Do not import**.

## What a blank cell means

The schema on that same page does not say a blank Sale price means "keep the current sale price." It does say what blank means for neighboring columns:

- **Date sale price starts:** blank means no start date.
- **Date sale price ends:** blank means no end date.
- **Stock:** blank disables stock management.

A spreadsheet that looks empty is not automatically a no-op. Set Sale price, both date columns, and Regular price to **Do not import** unless that file is the source of truth for those fields. Test one row even then.

Before the import, two simple products:

| SKU | Regular price | Sale price | Sale dates |
| --- | --- | --- | --- |
| CAP | 24.00 | 18.00 | none |
| HOODIE | 80.00 | 64.00 | none |

You export the catalog to edit stock, and you clear the Sale price cells because that sheet is not the sale. The Sale price header is still in the file. You import with **Update existing products** and leave Sale price mapped. Treat that as a price write until a one-row test proves otherwise. The safe stock-only file has no Sale price column, or that column is set to **Do not import**. Keep the original export. It is the restore file.

There is a second way to clear a sale that is not a CSV. On a variable product, the [variation bulk action](https://woocommerce.com/document/variable-product/) opened on 10 November 2026 saves immediately. **Set sale prices to regular prices decreased by** replaces any existing sale price. Entering 0 clears sale prices. Blank, non-numeric, or negative input leaves existing sale prices unchanged for that action. A regular price of 100 and an entry of 20% stores a sale price of 80.

On the products list, the [bulk editing guide](https://woocommerce.com/document/bulk-editing-products/) opened the same day says **Set to regular price decreased by** overrides the sale with the regular price decreased by a fixed amount or a percent, and that you should check the result. It also says a bulk edit that changes a regular or sale price clears the scheduled sale start and end dates. The October 2026 text of that page said a blank or 0 on that option removes the sale. The November page does not. This pass did not execute a blank sale field. Leave the field on **No change** unless you mean to write it. Products-list price edits apply to simple and external products, not to the variations of a variable product.

A sale can also disappear when the regular price moves under it. WooCommerce's product data store, read from trunk on 27 October 2026 and not re-run for this pass, clears a sale price on a simple product or a variation when a save updates the regular or sale price and the sale price is greater than or equal to the regular price. Variable and grouped parents are outside that particular check. Source: [`class-wc-product-data-store-cpt.php`](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php). If you only restore the regular price, the sale may stay gone. Restore both columns from the known-good export.

A 2021 [r/woocommerce thread](https://www.reddit.com/r/woocommerce/comments/noey3k/is_there_an_easy_way_to_raise_all_the_prices_in/) shows the habit that leads here: export, edit the price in a spreadsheet, import. The thread text was retrieved through search on 27 October 2026. A direct fetch from this environment returned HTTP 403 then, and the URL was not opened as a page on 10 November 2026. The export, edit, and import habit is also what the CSV guide is for. It overwrites whatever the sheet no longer contains.

## Stop the next overwrite

- Keep a dated export of ID, SKU, Regular price, Sale price, and both date columns before every import. [That file is the price history](woocommerce-price-history.md).
- Map price and date columns to **Do not import** on stock-only or title-only files.
- Normalize a supplier file and test one parent plus a few variations before the full catalog. The CSV guide's supplier section says to do that, and to read the skipped-row report instead of importing the full file again.
- If a plugin sends a full product payload, it can write prices when you only changed stock in the source. This guide did not audit a named sync plugin. Pause the sync and compare one SKU before and after a run.
- Do not use [a reverse percentage](undo-woocommerce-bulk-price-edit.md) as a substitute for the old sale prices. You need the old numbers.

## What WriteLeash 0.3.0 does not restore

The directory listing opened on 10 November 2026 is WriteLeash 0.3.0. It does not watch the CSV importer and does not undo an import. If the sale is already gone, WriteLeash has no record of the old value unless WriteLeash itself wrote that price and the job is still eligible for Undo.

On a later price change made in WriteLeash, you choose Regular price or Sale price. A regular-price change keeps the existing sale price and its dates. Clear makes the sale price blank, not zero, and keeps its dates. A nonblank sale target has to stay below the regular price. Preview writes nothing. Approval is separate. If the stored price WriteLeash is about to change no longer matches the Preview, the newer value stays and the row is a conflict. That check is not a guard around the importer.

Undo restores eligible prices WriteLeash applied. The listing screenshot describes a 30-day window. Undo does not reverse orders, emails, webhooks, or other plugins. WriteLeash creates no backup. Stock Undo is not supported. Ordinary price jobs do not change stock. It is not a firewall, not a sandbox, and not a universal agent-safe guarantee. Direct SQL, hostile plugins, and unmonitored writes are not covered. There is no guarantee when the grant store is unavailable.

Scope on the listing: published core simple products and published variations of published core variable products, base currency, up to 1,000 selected products per new job in the tested configuration. WordPress and WooCommerce are accepted as pairs: WooCommerce 10.0.0 or newer and below 12.0.0 on WordPress 7.0 up to but not including 8.0, or WordPress 6.7 below 7.0 with WooCommerce 9.0.0 below 10.0.0. Exercised examples named on the listing: WordPress 7.0.1 and 7.1.2 on the modern line, WordPress 6.7.2 with WooCommerce 9.0.4, and WordPress 6.8.3 with WooCommerce 9.9.7. Other pairs are refused before a job is created. The directory "Tested up to" field read 7.1.4. That is not a claim that 7.1.4 was one of the exercised pairs.

Opt-in grants for bounded price and stock jobs stay off until an operator issues one. Upgrade from 0.2.2 does not create a grant. They do not roll back a CSV. [Limiting who can write prices](restrict-woocommerce-price-changes.md) is still a role, a key, and a password problem first.

## Related guides

- [Undo a bulk price edit from the pre-change CSV](undo-woocommerce-bulk-price-edit.md)
- [A sale that is saved but missing on the shop](imported-sale-price-not-showing.md)
- [Keep the dated export that makes this restore possible](woocommerce-price-history.md)

## FAQ

### Does a blank Sale price cell leave the current sale price alone?

The CSV schema opened on 10 November 2026 does not say that. It does say a blank sale date means no date, and a blank Stock cell disables stock management. Set Sale price to Do not import unless you intend to write it, and test one SKU.

### The variation shows the right sale, but the shop shows the regular price. Should I import again?

No. Read the guide on a saved sale that the shop does not show. A second price file can overwrite a sale that was stored correctly.

### Will WriteLeash detect that an import changed the sale price?

No. WriteLeash checks the price it is about to apply against its Preview. It does not monitor the importer, and it does not undo that write. Stock Undo is not supported.

### Can I set the sale back with bulk edit instead of CSV?

Only if you know the exact sale price for every row. On a variable product, variation bulk actions save immediately, and entering 0 clears sale prices. A CSV of the old sale prices is the cleaner restore. Undo does not reverse orders, emails, webhooks, or other plugins.

WriteLeash 0.3.0 previews a later price change and can undo eligible prices it applied. It will not repair the import that already ran. The directory listing is [WriteLeash on WordPress.org](https://wordpress.org/plugins/writeleash/). The source is the [WriteLeash repository](https://github.com/MrDarkRoot/WriteLeash).
