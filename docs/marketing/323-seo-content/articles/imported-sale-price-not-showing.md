---
title: "An imported WooCommerce sale is saved but the shop shows full price"
slug: imported-sale-price-not-showing
meta_description: "If the variation shows the sale and the shop shows the regular price, do not import again. Update one variable product, then recheck the shop."
h1: "An imported WooCommerce sale is saved but the shop shows full price"
card: "If the variation already shows the sale, do not import prices again. Update one variable product and check the shop."
intent: "Separate a stored sale price from a stale variable-product price range so a display bug is not repaired by another price import."
failure_family: "Stored sale hidden by a stale variable-product range"
affiliation: "WriteLeash publishes this guide. It is not an independent review."
canonical_status: INTENDED
date_revised: 2026-11-10
---

# An imported WooCommerce sale is saved but the shop shows full price

If the variation editor shows the sale and the shop still shows the regular price, do not import the price file again. Open that one variable product and press **Update** without changing the variation prices, then look at the shop in a logged-out browser. A second CSV can replace a sale that was already stored.

**WriteLeash publishes this guide.** It is not an independent review. The checks are WooCommerce admin steps. WriteLeash does not regenerate product lookup tables, and it is the wrong tool when the editor already shows the right sale.

## Confirm the sale is stored

Open the variation, not the variable parent. The [variable product guide](https://woocommerce.com/document/variable-product/), opened on 10 November 2026, says each variation has its own regular price and its own optional sale price. Variations without a price do not show in the store. The parent can show a range built from the variations.

On one affected variation, check:

1. Regular price is present.
2. Sale price is strictly below that regular price.
3. If sale dates are set, today in the store timezone falls inside them. The [CSV schema](https://woocommerce.com/document/product-csv-importer-exporter/) says a start date uses the start of that day and an end date uses the end of that day. A blank date means no date when that column was imported. It does not mean "leave the old date."
4. The variation is enabled, and the parent is published.

If the sale field is empty, you do not have this display bug. Go to [restore sale prices a CSV import overwrote](restore-sale-prices-overwritten-by-import.md). If the field is filled and the shop is wrong, keep going. Do not upload another price CSV.

The products-list [bulk editor](https://woocommerce.com/document/bulk-editing-products/) says bulk price changes apply to simple and external products, not to the individual variations of a variable product. Typing a sale into the parent's bulk-edit Price field is the wrong repair.

## What one store reported in November 2025

On 25 November 2025 a store imported about 1,000 products with variations through the WooCommerce CSV importer. The file included SKU, Sale price, sale dates, and Stock. Each variation showed the sale price and the dates. The variable product on the shop still showed the regular price. A manual Update on the product made the sale appear. The report was opened again on 10 November 2026: [Variable product sale prices not showing after CSV import](https://wordpress.org/support/topic/variable-product-sale-prices-not-showing-after-csv-import/). The thread is closed. The original poster had already rebuilt product lookup tables, cleared cache, and synced variations. The sale still did not appear until a manual Update.

WooCommerce support suggested **Products > All Products > Bulk actions > Edit**, then **Apply**, then **Update** with no field changes. The current bulk-editing guide labels that menu **Bulk edit** rather than **Edit**. Support also named `wp wc product lookup regenerate --force` if the host has WP-CLI. The poster replied that they would try it. The thread was closed with no later confirmation that either step was run. This article did not re-run the import on WooCommerce 10 or 11. Try one product before you apply a resync to the catalog.

The same report on [r/woocommerce](https://www.reddit.com/r/woocommerce/comments/1p67r2v/variable_product_sale_prices_not_showing_after/) was search-retrieved on 27 October 2026, including a follow-up that a bulk edit with no change worked for that poster. A direct fetch returned HTTP 403, and the Reddit URL was not opened as a page on 10 November 2026. Two days after the original report, [another r/woocommerce thread](https://www.reddit.com/r/woocommerce/comments/1p7pld9/imported_sale_prices_but_theyre_not_showing/) was search-retrieved the same way: the backend showed the imported sale, the frontend did not, and saving the products again made the sale register. If the WordPress.org thread and a search snippet ever disagree, trust the WordPress.org thread.

## Resync one product, then a small batch

The product Update is what the original report says worked. The other steps were offered on that thread and were not confirmed there. They are not a guarantee for every host.

1. Edit the variable product and press **Update** without changing the variation prices. Recheck the shop.
2. If you have many parents, the support reply suggested selecting them, choosing Edit, pressing Apply, changing nothing, and pressing Update. Use a small batch first. The bulk editing guide warns that a large page can strain the server. This no-change Update is a resync attempt. It is not permission to type a new Price or Sale value. Leave those fields on **No change**. A bulk edit that changes a regular or sale price clears scheduled sale dates.
3. The original report says rebuilding product lookup tables, clearing cache, and syncing variations had already failed for that store. Do not treat lookup regeneration as the fix that worked there. This research did not execute the tool.
4. If you use WP-CLI, support named `wp wc product lookup regenerate --force`. The thread does not confirm that command was run, and the poster had already rebuilt lookup tables in the admin. Run `wp help wc product` on that server and confirm the subcommand exists before you use it. Do not paste it onto a host where `wp wc` is a different package.

Afterward, check three variations: one that should be on sale, one that should not, and one with a future sale start date. Clear a full-page cache only after the editor and a logged-out browser agree. Also confirm you did not import a parent-row price and expect it to become the variation price.

## Why this is not an undo problem

Nothing in the November 2025 report says the sale price was deleted. The data was on the variation. The parent range and the lookup the shop used had not caught up until a later save. Overwriting the sale to "fix" the range can create the data loss in the [CSV restore guide](restore-sale-prices-overwritten-by-import.md). A resync cannot invent a price that is no longer stored. A [dated price export](woocommerce-price-history.md) is how you tell the two cases apart.

## What WriteLeash 0.3.0 does not refresh

The directory listing opened on 10 November 2026 is WriteLeash 0.3.0. It does not regenerate lookup tables and does not make a stored sale appear on the shop. The listing says planned stored prices are not guaranteed shopper prices. A correct stored sale that the theme or a cache still hides is outside the plugin.

The same listing says WooCommerce refreshes variable parent price ranges after each variation change. That sentence is about a variation change WriteLeash is making, not about an import WriteLeash did not run. If you are unsure the stored sale is right, do not run a WriteLeash job to "refresh" it. The step that made the sale appear in the November 2025 report was updating the product.

Eligible Undo restores prices WriteLeash applied, within 30 days on the listing screenshot, when the saved record and the product still allow it. It does not restore a CSV import, and it does not fix a stale range. Undo does not reverse orders, emails, webhooks, or other plugins. Stock Undo is not supported. Ordinary price jobs do not change stock. It is not a firewall, not a sandbox, and not a universal agent-safe guarantee. Direct SQL, hostile plugins, and unmonitored writes are not covered. There is no guarantee when the grant store is unavailable.

Requirements on the listing: WooCommerce 10.0.0 or newer and below 12.0.0 on WordPress 7.0 up to but not including 8.0, or WordPress 6.7 below 7.0 with WooCommerce 9.0.0 below 10.0.0. Exercised pairs named there include WordPress 7.0.1 and 7.1.2, WordPress 6.7.2 with WooCommerce 9.0.4, and WordPress 6.8.3 with WooCommerce 9.9.7. Other pairs are refused before a job is created. Published core simple products and published variations of published core variable products, base currency, up to 1,000 selected products per new job in the tested configuration. Parent prices are not edited directly.

## Related guides

- [Restore a sale the import actually cleared](restore-sale-prices-overwritten-by-import.md)
- [Undo a bulk price edit without a reverse percentage](undo-woocommerce-bulk-price-edit.md)
- [Keep a dated CSV so you can tell a hidden sale from a deleted one](woocommerce-price-history.md)

## FAQ

### The variation sale price is correct and the shop is wrong. Do I import the CSV again?

No. Importing again can overwrite the stored sale. In the November 2025 report, updating the product made the sale appear after a lookup-table rebuild had not. Update one variable product and recheck the shop.

### The variation sale price is empty. Is this the same bug?

No. The sale is not stored. Restore it from the pre-import export. A resync cannot invent a price that is gone.

### Does a no-change bulk Update edit variation prices?

The products-list bulk editor does not apply price changes to individual variations. A November 2025 support reply still suggested a no-change Update as a resync, and the thread never confirmed it was run. Do not type a new price into that form while you use it for this purpose.

### Can WriteLeash force the shop to show the sale?

No. WriteLeash 0.3.0 does not regenerate product lookup tables. Its listing says a planned stored price is not a guaranteed shopper price. Stock Undo is not supported. Undo does not reverse orders, emails, webhooks, or other plugins.

WriteLeash 0.3.0 previews a price you choose to change and can undo eligible prices it applied. It will not repair a stale shop range. The directory listing is [WriteLeash on WordPress.org](https://wordpress.org/plugins/writeleash/). The source is the [WriteLeash repository](https://github.com/MrDarkRoot/WriteLeash).
