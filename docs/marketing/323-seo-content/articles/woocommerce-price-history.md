---
title: "How to keep a WooCommerce price history without writing code"
slug: woocommerce-price-history
meta_description: "WooCommerce does not ship a catalog price log. Keep dated CSV exports, and use a history plugin only for changes made after you install it."
h1: "How to keep a WooCommerce price history without writing code"
intent: "Give a merchant a price audit trail they can open later, and separate that from order reports and from WriteLeash job history."
failure_family: "Price history and audit trail"
affiliation: "WriteLeash is our product. This guide is not an independent review."
canonical_status: UNPUBLISHED
date_revised: 2026-10-27
---

# How to keep a WooCommerce price history without writing code

**WriteLeash makes this guide.** We publish the [WriteLeash](https://wordpress.org/plugins/writeleash/) plugin. The export habit below does not use it. WriteLeash History, described later, is only the log of WriteLeash jobs.

WooCommerce's [product management guide](https://woocommerce.com/document/managing-products/) and [bulk editing guide](https://woocommerce.com/document/bulk-editing-products/) tell you how to set a price and how to change many prices. They do not describe a screen that lists the previous regular price, the previous sale price, who changed them, and when. If you need that after a bad bulk edit, and you never saved a copy, core has nothing to open.

That is not the same sentence as "there is no way to recover." A backup, an older export, or a plugin that was already recording can still hold the old number. [How to undo an accidental bulk price edit](undo-woocommerce-bulk-price-edit.md) walks through the restore. This page is how to make the next restore boring.

## What is not a price history

**Orders.** A line item stores the amount charged on that order. It helps when a product actually sold. It says nothing about products that did not sell, and it is not the catalog price at 9:00 if the order was placed at 16:00 after a second edit.

**Analytics.** WooCommerce reports are built from orders. They answer what you sold. They do not answer what the regular price field used to contain.

**Stock logs.** The [Inventory History](https://wordpress.org/plugins/inventory-history/) listing says WooCommerce does not save a log of product stock changes by default. Stock quantity and price are different fields. A stock log does not restore a sale price.

**The EU 30-day lowest price.** Several plugins, including [Product Price History](https://wordpress.org/plugins/product-price-history/) and [WPNest Price History](https://wordpress.org/plugins/wpnest-price-history-for-woocommerce/), advertise a consumer-facing notice for discounted products. That is a disclosure on the product page. It is not an admin undo, and this article is not legal advice. If you sell to shoppers in the EU, read the rule that applies to you. Do not assume an admin CSV export satisfies it.

**Revisions in the product editor.** This guide did not find WooCommerce documenting post revisions as a price undo. Prices live in product data. Prove a restore on staging, on one product, before you count on it.

## A history you can keep without a developer

### 1. Dated exports

**Products > All Products > Export.** The [CSV guide](https://woocommerce.com/document/product-csv-importer-exporter/) explains the columns. For a price snapshot, keep:

- ID
- SKU
- Type
- Name
- Regular price
- Sale price
- Date sale price starts
- Date sale price ends

Leave the file named with the date, in a place that is not the same filename every week. A weekly export that overwrites `products.csv` is not a history.

You can export a category or a selection when you are about to change that slice of the catalog. The guide shows **Export selected** from the product list.

### 2. Export again immediately before a write

Do this before:

- Products-list bulk edit
- Variation bulk actions, which the [variable product guide](https://woocommerce.com/document/variable-product/) says save immediately
- A CSV update
- Connecting a sync tool or an assistant that can write prices

The file from step 1 is the backup of the backup. The file from step 2 is the exact before-image of this change.

### 3. A plugin log, installed before you need it

These listings, read on 27 October 2026, describe recording that starts when the plugin is active:

- [Product Price History](https://wordpress.org/plugins/product-price-history/) says it saves a record when a product's price changes, including through the REST API and importers, and that recording starts as soon as the plugin is active.
- [MiraiLit Product Change Tracker](https://wordpress.org/plugins/mirailit-product-change-tracker-for-woocommerce/) says it records price, stock, title, and weight from the product editor, quick edit, bulk edit, REST API, WP-CLI, importers, and stock changes from orders and refunds, with the old value, the new value, who did it, and when.
- [Bulk Price Editor](https://wordpress.org/plugins/bulk-price-editor-for-woocommerce/) 2.5.0, dated 1 September 2026 in the trunk readme checked on 27 October 2026, says each bulk update stores a snapshot of the previous prices. The same entry says a separate price-change record covers manual edits, imports, the API, and bulk updates, and can be turned off. Revert from that history is marked premium. The FAQ on the same readme still says the plugin does not have an undo feature and points at the preview plus a database backup. Read the version you install. Recording starts when the plugin is active.

None of these sentences were re-tested in a store for this article. Install on staging, change one simple product and one variation by Quick Edit, by bulk edit, and by a one-row CSV, and see whether the log shows the old price. Then decide.

Installing any of them after the bad edit does not reconstruct the old price.

### 4. Know what your host backup contains

Ask the host how far back backups go and whether a restore replaces the whole database. A full restore brings back old prices and also rolls back orders, coupons, and customer changes made after that backup. The safer pattern is: restore the backup on staging, export the price columns, import those columns into the live store with every other column set to **Do not import**.

## How to read a snapshot when something looks wrong

Open the product and write down the regular price, the sale price, and the dates you see now. Find the newest export from before the incident. Diff those columns on the SKU. Three different outcomes:

| What you see | What to do |
| --- | --- |
| The editor matches the old export, and the shop does not | Do not import prices again. See [why a saved sale price might not show](imported-sale-price-not-showing.md). |
| The editor shows a new sale or a blank sale, and the export has the old one | Restore from that export. See [sale prices a CSV import overwrote](restore-sale-prices-overwritten-by-import.md). |
| You have no export and no backup | You do not have the old catalog price. Orders may hint at it for products that sold. Guessing with a reverse percentage is the risky path in [undo a bulk price edit](undo-woocommerce-bulk-price-edit.md). |

## What WriteLeash History is

**Directory plugin, checked 27 October 2026.** [WriteLeash 0.2.2](https://wordpress.org/plugins/writeleash/) says History shows who changed what and when, and that eligible prices can be restored within the Undo window, 30 days by default. Read that as the history of WriteLeash jobs: results, conflicts, and whether Undo is available.

It does not record:

- Quick Edit
- The products-list bulk editor
- Variation bulk actions
- The CSV importer
- Another plugin's save
- A REST write that did not go through WriteLeash

Undo availability does not guarantee restoration. A later price change, a product that is no longer eligible, a missing record, or an expired window can leave the row unrestored. Undo does not reverse orders, completed sales, emails, webhooks, remote requests, external queues, or other plugins' effects. The Preview CSV is the saved Preview, not a live catalog export, and it does not apply changes.

Scope on that listing: published core simple products and published variations of published core variable products, base store currency, up to 1,000 selected products per new job in the tested configuration. WordPress 7.0 or newer, with 7.0.1 and 7.1.2 exercised. WooCommerce 10.0 through 11.x. Multisite is unsupported. Real managed or shared hosting has not been tested. Price operations do not change stock, sale dates, or orders.

**Not the download.** The default branch after [pull request #330](https://github.com/MrDarkRoot/WriteLeash/pull/330) uses compatibility pairs: WordPress 7.0 up to but not including 8.0 with WooCommerce 10.0 up to but not including 12.0, or WordPress 6.7 up to but not including 7.0 with WooCommerce 9.0 up to but not including 10.0. The 0.2.2 listing does not advertise the legacy pair. An append-only grant audit in [issue #312](https://github.com/MrDarkRoot/WriteLeash/issues/312) is not a catalog price history and is not in the published listing.

WriteLeash is one log of one tool's price writes. The dated CSV is the log of the catalog. Use both if you use WriteLeash. The CSV still matters, because most overwrites never pass through the plugin.

## FAQ

### Where do I click to see every past product price?

Core does not provide that screen. Use a dated export, a backup, or a history plugin that was recording at the time of the change.

### Does WriteLeash History show CSV imports and bulk edits?

It shows WriteLeash jobs. It does not show the products-list bulk editor, variation bulk actions, or the CSV importer.

### Will a 30-day price chart on the product page let me undo a price?

No. A shopper-facing lowest-price notice is not an admin restore. Eligible Undo inside WriteLeash is also not that chart. It only restores eligible prices WriteLeash applied, for 30 days by default on the 0.2.2 listing.

### How many exports do I need?

One immediately before each bulk change, plus an older copy you do not overwrite. Weekly is a reasonable extra if prices change often. There is no directory statistic behind a specific frequency.

```json
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Article",
      "headline": "How to keep a WooCommerce price history without writing code",
      "dateModified": "2026-10-27",
      "description": "WooCommerce does not ship a catalog price log. Keep dated CSV exports, and use a history plugin only for changes made after you install it.",
      "author": { "@type": "Organization", "name": "WriteLeash", "url": "https://wordpress.org/plugins/writeleash/" },
      "publisher": { "@type": "Organization", "name": "WriteLeash", "url": "https://wordpress.org/plugins/writeleash/" }
    },
    {
      "@type": "FAQPage",
      "mainEntity": [
        {
          "@type": "Question",
          "name": "Where do I click to see every past product price?",
          "acceptedAnswer": { "@type": "Answer", "text": "WooCommerce core does not provide that screen. Use a dated export, a backup, or a history plugin that was recording at the time of the change." }
        },
        {
          "@type": "Question",
          "name": "Does WriteLeash History show CSV imports and bulk edits?",
          "acceptedAnswer": { "@type": "Answer", "text": "It shows WriteLeash jobs. It does not show the products-list bulk editor, variation bulk actions, or the CSV importer." }
        },
        {
          "@type": "Question",
          "name": "Will a 30-day price chart on the product page let me undo a price?",
          "acceptedAnswer": { "@type": "Answer", "text": "No. A shopper-facing lowest-price notice is not an admin restore. WriteLeash Undo only restores eligible prices WriteLeash applied, for 30 days by default on the 0.2.2 listing." }
        },
        {
          "@type": "Question",
          "name": "How many exports do I need?",
          "acceptedAnswer": { "@type": "Answer", "text": "One immediately before each bulk change, plus an older copy you do not overwrite. There is no statistic behind a specific weekly frequency." }
        }
      ]
    }
  ]
}
```

Structured data template only. Do not emit it until this article has a public canonical URL. Add `datePublished` on that day. The affiliation above is required on the page.

When the price change itself should carry a preview and an eligible restore, [WriteLeash on WordPress.org](https://wordpress.org/plugins/writeleash/) is the tool this guide is attached to. Keep the CSV export anyway.
