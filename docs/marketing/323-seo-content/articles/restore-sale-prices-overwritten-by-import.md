---
title: "How to restore WooCommerce sale prices a CSV import overwrote"
slug: restore-sale-prices-overwritten-by-import
meta_description: "A mapped WooCommerce CSV column is a write. Put sale prices back from the pre-import export, and set every other column to Do not import."
h1: "How to restore WooCommerce sale prices a CSV import overwrote"
intent: "Diagnose a sale price, sale schedule, or active price that changed during a product CSV update, then restore the previous values."
failure_family: "Import or plugin overwrite of sale prices"
affiliation: "WriteLeash is our product. This guide is not an independent review."
canonical_status: UNPUBLISHED
date_revised: 2026-10-27
---

# How to restore WooCommerce sale prices a CSV import overwrote

**WriteLeash makes this guide.** We publish the [WriteLeash](https://wordpress.org/plugins/writeleash/) plugin. Everything in the recovery section uses WooCommerce's importer and an export you already have, or a backup. You do not need the plugin to do it.

The usual mistake is a stock or title update that also contained price columns. WooCommerce's [CSV importer](https://woocommerce.com/document/product-csv-importer-exporter/) updates rows that match by ID or SKU when **Update existing products** is on. A column WooCommerce recognizes is mapped for you. Unrecognized columns are not imported. In WooCommerce 11.2 and later, a GTIN, UPC, EAN, or ISBN column defaults to **Do not import** and is used only when both ID and SKU are blank. The mapping screen's control for "do not touch this" is **Do not import**.

## What "blank" means in the official schema

The schema does not say a blank Sale price means "keep the current sale price." It does say what blank means for neighboring columns:

- **Date sale price starts:** blank means no start date.
- **Date sale price ends:** blank means no end date.
- **Stock:** blank disables stock management.

So a spreadsheet that looks empty is not automatically a no-op. On the mapping screen, set Sale price, both date columns, and Regular price to **Do not import** unless that file is the source of truth for those fields.

There is a second, documented way to clear a sale without a CSV. On **Products > All Products > Bulk actions > Edit**, the [bulk editing guide](https://woocommerce.com/document/bulk-editing-products/) says **Set to regular price decreased by** with a blank value or 0 removes the sale price.

On a variable product, the variation bulk action is different again. The [variable product guide](https://woocommerce.com/document/variable-product/) says **Set sale prices to regular prices decreased by** replaces any existing sale price and saves immediately. Entering 0 clears sale prices. Blank, non-numeric, or negative input leaves existing sale prices unchanged for that action. A regular price of 100 and an entry of 20% stores a sale price of 80.

## A one-row example

Before the import, two simple products:

| SKU | Regular price | Sale price | Sale dates |
| --- | --- | --- | --- |
| CAP | 24.00 | 18.00 | none |
| HOODIE | 80.00 | 64.00 | none |

You export the catalog, edit stock quantities, and clear the Sale price cells because you are not running a sale in that sheet. The Sale price header is still in the file. You import with **Update existing products** and leave Sale price mapped.

Treat that as a price write until a one-row test proves otherwise. The safe file for a stock-only update has no Sale price column, or that column is set to **Do not import**. Keep the original export. It is the restore file.

A related clear can happen when the regular price moves. WooCommerce's current product data store, read from trunk on 27 October 2026, clears a sale price on a simple product or a variation when a save updates the regular or sale price and the sale price is greater than or equal to the regular price. Variable and grouped parents are outside that particular check because their customer price comes from children. Source: [`class-wc-product-data-store-cpt.php`](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php). If you raised a sale or lowered a regular price into that overlap, restoring only the regular price does not put the sale back. Restore both columns from the known-good export.

## Put the sale prices back

1. Pause any sync, feed, or scheduled import that might run the same file again. Restoring prices and then letting the job repeat is how the overwrite returns.
2. Take the export from before the bad import. If you do not have one, export prices from a database backup restored on staging. Do not restore that backup over the live database if the store has taken orders since. Orders would roll back with the catalog.
3. Make a CSV with ID or SKU, Sale price, Date sale price starts, and Date sale price ends. Include Regular price when the regular price also changed. Delete every other column, or map those columns to **Do not import**.
4. On staging, or on one live SKU, run the import with **Update existing products**.
5. Open the product. Confirm the sale price is strictly below the regular price, and that the dates match the file. A sale that is not below the regular price does not behave as an active discount.
6. Check the shop page, not only the editor. If the editor shows the right sale and the shop still shows the regular price, stop importing. That is a different failure, covered in [why an imported sale price can be saved and still not show](imported-sale-price-not-showing.md).
7. Import the remaining rows in batches you can spot-check.

The [CSV guide](https://woocommerce.com/document/product-csv-importer-exporter/) also says to back up and to test a small file before a large one, and to split files that are too big for the server. Read the importer's skipped-row report before you run the file again.

A 2021 [r/woocommerce thread](https://www.reddit.com/r/woocommerce/comments/noey3k/is_there_an_easy_way_to_raise_all_the_prices_in/) shows the habit that leads here: export the price, edit it in a spreadsheet, import it. That works when the sheet is the source of truth. It overwrites whatever the sheet no longer contains.

## Stop the next overwrite

- Keep a dated export of ID, SKU, Regular price, Sale price, and both date columns before every import.
- Map price and date columns to **Do not import** on stock-only or title-only files.
- Give a supplier file its own staging test. Supplier headers rarely match WooCommerce's schema. The CSV guide's section on supplier files says to normalize them and to test one parent plus a few variations first.
- If a plugin syncs products from another store, a sheet, or a marketplace, read which fields it sends. A plugin that sends a full product payload can write prices even when you only changed stock in the source. This guide did not audit a named sync plugin. Pause the sync and compare one SKU before and after a run.
- Do not use a second import as a way to "refresh" a variable product whose variations already show the correct sale. Refresh and overwrite are different jobs.

## What WriteLeash does here, and what it does not

**Directory plugin, checked 27 October 2026.** [WriteLeash 0.2.2](https://wordpress.org/plugins/writeleash/) does not watch the CSV importer and does not undo an import. If the sale price is already gone, WriteLeash has no record of the old value unless WriteLeash itself wrote that price and the job is still eligible for Undo.

What a later WriteLeash price change does, on the published listing:

- You choose Regular price or Sale price. A regular-price change is specified to preserve the existing sale price and sale schedule. Set can add a first sale price. A discount can be calculated from each product's reviewed regular price. Clear makes the sale price blank, not zero, and keeps its dates.
- A nonblank sale target has to stay below the regular price. Clear and sale discounts use the reviewed regular price as their basis.
- Preview is a separate step from approval. If the stored price WriteLeash is about to change no longer matches the Preview, the newer value stays and the row is a conflict. That check is not a guard around the CSV importer. Another tool can still write before or after the job.
- Undo restores eligible prices WriteLeash applied, within 30 days by default on the listing. It does not restore an import. It does not reverse orders or other plugins' effects. WriteLeash creates no backup.
- Scope: published core simple products and published variations of published core variable products, base currency, up to 1,000 selected products per new job in the tested configuration. Stock, sale dates, and orders are not changed by those price operations. WordPress 7.0 or newer and WooCommerce 10.0 through 11.x, with 7.0.1 and 7.1.2 exercised. Not every 7.x release is claimed.

**Not the download.** After merged [pull request #330](https://github.com/MrDarkRoot/WriteLeash/pull/330), the GitHub default branch certifies WordPress 7.0 up to but not including 8.0 with WooCommerce 10.0 up to but not including 12.0, or WordPress 6.7 up to but not including 7.0 with WooCommerce 9.0 up to but not including 10.0. Cross pairs are refused. The directory plugin does not advertise the legacy pair. Grant-scoped stock changes in [issue #312](https://github.com/MrDarkRoot/WriteLeash/issues/312) are not a 0.2.2 feature you can turn on from the listing. Version 0.2.2's changelog mentions disabled-by-default foundations for bounded delegated price authority and does not document a sale-price lock.

For a price log that covers imports, you need a tool that was recording before the import. [How to keep a price history](woocommerce-price-history.md) explains that limit.

## FAQ

### Does a blank Sale price cell leave the current sale price alone?

The [CSV schema](https://woocommerce.com/document/product-csv-importer-exporter/) does not say that. It does say a blank sale date means no date, and a blank Stock cell disables stock management. Set Sale price to **Do not import** unless you intend to write it, and test one SKU.

### The variation shows the right sale, but the shop shows the regular price. Should I import again?

No. Read [why an imported sale price can be saved and still not show](imported-sale-price-not-showing.md). A second price file can overwrite a sale that was stored correctly.

### Will WriteLeash detect that a plugin changed the sale price?

No. Conflict checks run on the price WriteLeash is applying, against the Preview. They do not monitor the importer or other plugins. WriteLeash does not undo those writes.

### Can I set the sale back with bulk edit instead of CSV?

Only if you know the exact sale price for every row. **Set to regular price decreased by** with a blank or 0 removes the sale price on the products-list screen. That is an easy second mistake. A CSV of the old sale prices is the cleaner restore.

```json
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Article",
      "headline": "How to restore WooCommerce sale prices a CSV import overwrote",
      "dateModified": "2026-10-27",
      "description": "A mapped WooCommerce CSV column is a write. Put sale prices back from the pre-import export, and set every other column to Do not import.",
      "author": { "@type": "Organization", "name": "WriteLeash", "url": "https://wordpress.org/plugins/writeleash/" },
      "publisher": { "@type": "Organization", "name": "WriteLeash", "url": "https://wordpress.org/plugins/writeleash/" }
    },
    {
      "@type": "FAQPage",
      "mainEntity": [
        {
          "@type": "Question",
          "name": "Does a blank Sale price cell leave the current sale price alone?",
          "acceptedAnswer": { "@type": "Answer", "text": "The WooCommerce CSV schema does not say that. Set Sale price to Do not import unless you intend to write it, and test one SKU." }
        },
        {
          "@type": "Question",
          "name": "The variation shows the right sale, but the shop shows the regular price. Should I import again?",
          "acceptedAnswer": { "@type": "Answer", "text": "No. That can be a display or lookup sync problem. A second price file can overwrite a sale that was stored correctly." }
        },
        {
          "@type": "Question",
          "name": "Will WriteLeash detect that a plugin changed the sale price?",
          "acceptedAnswer": { "@type": "Answer", "text": "No. WriteLeash checks the price it is about to apply against its Preview. It does not monitor the importer or other plugins, and it does not undo those writes." }
        },
        {
          "@type": "Question",
          "name": "Can I set the sale back with bulk edit instead of CSV?",
          "acceptedAnswer": { "@type": "Answer", "text": "Only if you know the exact sale price for every row. The products-list option Set to regular price decreased by, with a blank or 0, removes the sale price. A CSV of the old sale prices is the cleaner restore." }
        }
      ]
    }
  ]
}
```

Structured data template only. Do not emit it until this article has a public canonical URL. Add `datePublished` on that day. The affiliation above is required on the page.

If you want reviewed sale-price changes from here on, with a preview and eligible undo of the prices WriteLeash itself writes, the listing is [WriteLeash on WordPress.org](https://wordpress.org/plugins/writeleash/). It will not repair the import that already ran.
