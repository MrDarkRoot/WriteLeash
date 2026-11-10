---
title: "Keep a WooCommerce price history with a dated CSV"
slug: woocommerce-price-history
meta_description: "WooCommerce has no catalog price log. Export ID, SKU, and prices before each bulk change. A history plugin only records what happens after you install it."
h1: "Keep a WooCommerce price history with a dated CSV"
card: "Save a dated CSV of ID, SKU, regular price, sale price, and both sale dates before the next bulk change."
intent: "Give a merchant a price audit trail they can open later, separate from order reports and from WriteLeash job history."
failure_family: "Price history and audit trail"
affiliation: "WriteLeash publishes this guide. It is not an independent review."
canonical_status: INTENDED
date_revised: 2026-11-10
---

# Keep a WooCommerce price history with a dated CSV

Export ID, SKU, Regular price, Sale price, and both sale-date columns, and name the file with the date, before the next bulk change. WooCommerce's product screens tell you how to set a price. They do not give you a page that lists the previous regular price, the previous sale price, who changed them, and when. If you need that after a bad edit and you never saved a copy, core has nothing to open.

**WriteLeash publishes this guide.** It is not an independent review. The export habit does not use the plugin. WriteLeash History, described later, is only the log of WriteLeash jobs.

A backup, an older export, or a plugin that was already recording can still hold the old number. [Undo a bulk price edit](undo-woocommerce-bulk-price-edit.md) is the restore. This page is how to make the next restore boring. Search volume for "WooCommerce price history" was not measured.

## What is not a price history

**Orders.** A line item stores the amount charged on that order. It helps when a product actually sold. It says nothing about products that did not sell, and it is not the catalog price at 09:00 if the order was placed at 16:00 after a second edit.

**Analytics.** WooCommerce reports are built from orders. They answer what you sold. They do not answer what the regular price field used to contain.

**Stock logs.** A stock-history plugin is a different field. A stock log does not restore a sale price. The [Inventory History](https://wordpress.org/plugins/inventory-history/) listing was read on 27 October 2026 as saying WooCommerce does not save a product stock-change log by default. That sentence is about stock. It was not re-opened for this pass, and it is not a price-log claim.

**The EU 30-day lowest price.** Some plugins show a shopper-facing notice on discounted products. That is a disclosure on the product page. It is not an admin undo, and this article is not legal advice. If you sell into the EU, read the rule that applies to you. Do not assume an admin CSV satisfies it.

**Revisions in the product editor.** This guide did not find WooCommerce documenting post revisions as a price undo. Prove a restore on staging, on one product, before you count on it.

## A history you can keep without a developer

### Dated exports

Go to **Products > All Products > Export**. The [CSV guide](https://woocommerce.com/document/product-csv-importer-exporter/), opened on 10 November 2026, explains the columns. For a price snapshot, keep ID, SKU, Type, Name, Regular price, Sale price, Date sale price starts, and Date sale price ends.

Leave the file named with the date, in a place that is not the same filename every week. A weekly export that overwrites `products.csv` is not a history. You can export a category, or use **Export selected** from the product list, when you are about to change one slice of the catalog.

### Export again immediately before a write

Do this before a products-list bulk edit, before variation bulk actions, before a CSV update, and before you connect a sync tool or an assistant that can write prices. The [variable product guide](https://woocommerce.com/document/variable-product/) says variation bulk actions save immediately. The [bulk editing guide](https://woocommerce.com/document/bulk-editing-products/) says a products-list edit that changes a regular or sale price clears the scheduled sale dates, so the date columns belong in the snapshot. The file from the weekly export is the older copy. The file from this step is the before-image of this change.

### A plugin log, installed before you need it

[Bulk Price Editor for WooCommerce](https://wordpress.org/plugins/bulk-price-editor-for-woocommerce/) 2.5.1 was opened on 10 November 2026, including its trunk readme. Changelog 2.5.0, dated 1 September 2026, says each bulk update stores a snapshot of the previous prices, and that a separate price-change record can cover manual edits, imports, the API, and bulk updates. Revert from that history is marked premium. The FAQ on the same readme still says the plugin does not have an undo feature and points at the preview plus a database backup. Read the version you install. Recording starts when the plugin is active.

This pass did not re-test other history plugins in a store. On 27 October 2026 their listings said recording starts when the plugin is active: [Product Price History](https://wordpress.org/plugins/product-price-history/), [MiraiLit Product Change Tracker](https://wordpress.org/plugins/mirailit-product-change-tracker-for-woocommerce/), and [WPNest Price History](https://wordpress.org/plugins/wpnest-price-history-for-woocommerce/). Those pages were not re-opened on 10 November 2026. Install on staging, change one simple product and one variation by Quick Edit, by bulk edit, and by a one-row CSV, and see whether the log shows the old price. Installing any of them after the bad edit does not reconstruct the old price.

### Know what the host backup contains

Ask the host how far back backups go and whether a restore replaces the whole database. A full restore brings back old prices and also rolls back orders, coupons, and customer changes made after that backup. The safer pattern is to restore the backup on staging, export the price columns, and import those columns into the live store with every other column set to **Do not import**. That import is the same repair as [sale prices a CSV overwrote](restore-sale-prices-overwritten-by-import.md).

## How to read a snapshot when something looks wrong

Open the product and write down the regular price, the sale price, and the dates you see now. Find the newest export from before the incident. Compare those columns on the SKU.

| What you see | What to do |
| --- | --- |
| The editor matches the old export, and the shop does not | Do not import prices again. See [why a saved sale might not show](imported-sale-price-not-showing.md). |
| The editor shows a new sale or a blank sale, and the export has the old one | Restore from that export. |
| You have no export and no backup | You do not have the old catalog price. Orders may hint at it for products that sold. Guessing with a reverse percentage is the risky path in the bulk-edit guide. |

## What WriteLeash History is

The directory listing opened on 10 November 2026 is WriteLeash 0.3.0. History shows what finished, what conflicted, and whether Undo is available. Read that as the history of WriteLeash jobs. It is not a catalog price log.

It does not record Quick Edit, the products-list bulk editor, variation bulk actions, the CSV importer, another plugin's save, or a REST write that did not go through WriteLeash. An append-only grant audit records grant and denial events for a restricted user. It is not a history of every price in the catalog.

Eligible Undo restores prices WriteLeash applied, within 30 days on the listing screenshot, when the saved details and the product still allow it. Availability does not guarantee restoration. Undo does not reverse orders, emails, webhooks, or other plugins. Stock Undo is not supported. Ordinary price jobs do not change stock. The Preview CSV is the saved Preview, not a live catalog export, and it does not apply changes. WriteLeash creates no backup.

It is not a firewall, not a sandbox, and not a universal agent-safe guarantee. Direct SQL, hostile plugins, and unmonitored writes are not covered. There is no guarantee when the grant store is unavailable. Opt-in grants stay off until an operator issues one. Upgrade from 0.2.2 does not create a grant.

Scope on the listing: published core simple products and published variations of published core variable products, base currency, up to 1,000 selected products per new job in the tested configuration. Pairs: WooCommerce 10.0.0 or newer and below 12.0.0 on WordPress 7.0 up to but not including 8.0, or WordPress 6.7 below 7.0 with WooCommerce 9.0.0 below 10.0.0. Exercised examples named on the listing: WordPress 7.0.1 and 7.1.2, WordPress 6.7.2 with WooCommerce 9.0.4, and WordPress 6.8.3 with WooCommerce 9.9.7. Other pairs are refused before a job is created. Multisite is unsupported. Real managed or shared hosting has not been tested.

The dated CSV is the log of the catalog. WriteLeash History is the log of one tool's price writes. Use both if you use WriteLeash. The CSV still matters, because most overwrites never pass through the plugin. [Who is allowed to make the next change](restrict-woocommerce-price-changes.md) is a separate decision.

## Related guides

- [Undo a bulk price edit from the pre-change CSV](undo-woocommerce-bulk-price-edit.md)
- [Restore sale prices a CSV import overwrote](restore-sale-prices-overwritten-by-import.md)
- [A sale that is saved but missing on the shop](imported-sale-price-not-showing.md)

## FAQ

### Where do I click to see every past product price?

Core does not provide that screen. Use a dated export, a backup, or a history plugin that was recording at the time of the change.

### Does WriteLeash History show CSV imports and bulk edits?

It shows WriteLeash jobs: what finished, what conflicted, and whether Undo is available. It does not show the products-list bulk editor, variation bulk actions, or the CSV importer.

### Will a 30-day price chart on the product page let me undo a price?

No. A shopper-facing lowest-price notice is not an admin restore. Eligible Undo inside WriteLeash only restores eligible prices WriteLeash applied. Stock Undo is not supported.

### How many exports do I need?

One immediately before each bulk change, plus an older copy you do not overwrite. There is no measured search volume behind a weekly habit. Undo does not reverse orders, emails, webhooks, or other plugins.

WriteLeash 0.3.0 keeps a history of its own price jobs and can undo eligible prices it applied. Keep the CSV anyway. The directory listing is [WriteLeash on WordPress.org](https://wordpress.org/plugins/writeleash/). The source is the [WriteLeash repository](https://github.com/MrDarkRoot/WriteLeash).
