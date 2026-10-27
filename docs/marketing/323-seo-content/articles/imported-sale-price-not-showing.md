---
title: "Why an imported WooCommerce sale price can be saved and still not show"
slug: imported-sale-price-not-showing
meta_description: "If the variation editor shows the sale and the shop shows the regular price, do not import prices again. Resync one product before you touch the file."
h1: "Why an imported WooCommerce sale price can be saved and still not show"
intent: "Separate a stored sale price from a stale variable-product price range, so a display bug is not repaired by another price import."
failure_family: "Import overwrite, misread as a missing sale"
affiliation: "WriteLeash is our product. This guide is not an independent review."
canonical_status: UNPUBLISHED
date_revised: 2026-10-27
---

# Why an imported WooCommerce sale price can be saved and still not show

**WriteLeash makes this guide.** We publish the [WriteLeash](https://wordpress.org/plugins/writeleash/) plugin. The checks below are WooCommerce admin steps. WriteLeash does not refresh a variable product's price range, and it should not be the tool you reach for when the editor already shows the right sale.

This failure looks like an overwrite. The shop, or the variable product's price range, still shows the regular price after a CSV import. The expensive mistake is to import a second price file. If the sale is already stored on the variation, a second file can replace it. [Restoring sale prices](restore-sale-prices-overwritten-by-import.md) is the other article. Use this one first to see which failure you have.

## A report from November 2025

On 25 November 2025, a store imported about 1,000 products with variations through the WooCommerce CSV importer. The file included SKU, Sale price, sale dates, and Stock. Each variation showed the sale price and the dates. The variable product on the shop still showed the regular price. A manual Update on the product made the sale appear. The report is [Variable product sale prices not showing after CSV import](https://wordpress.org/support/topic/variable-product-sale-prices-not-showing-after-csv-import/).

The original poster had already rebuilt product lookup tables, cleared cache, and synced variations. The sale still did not appear until a manual Update. WooCommerce support then suggested **Products > All Products > Bulk actions > Edit**, then **Apply**, then **Update** with no field changes. They also named `wp wc product lookup regenerate --force` if the host has WP-CLI. The WordPress.org thread was closed without a later confirmation that those steps were run. The same report on [r/woocommerce](https://www.reddit.com/r/woocommerce/comments/1p67r2v/variable_product_sale_prices_not_showing_after/) includes a search-retrieved follow-up that a bulk edit with no change worked for them.

Two days later, [another r/woocommerce thread](https://www.reddit.com/r/woocommerce/comments/1p7pld9/imported_sale_prices_but_theyre_not_showing/) describes the same split: the backend showed the imported sale prices, the frontend did not, and saving the products again made the sale register. A comment suggested **WooCommerce > Status > Tools** and regenerating product lookup tables.

Direct fetches of those Reddit URLs from this research environment returned HTTP 403 on 27 October 2026. The thread text was retrieved through search on the same day. The WordPress.org thread is the one to trust if the two ever disagree.

This article did not re-run the import on WooCommerce 10 or 11. Try one product before you apply a resync to the catalog.

## Confirm the sale is stored

Open the variation, not the variable parent.

The [variable product guide](https://woocommerce.com/document/variable-product/) says each variation has its own regular price and its own optional sale price. Variations without a price do not show in the store. The parent can show a price range built from those variations. The products-list [bulk editor](https://woocommerce.com/document/bulk-editing-products/) says it cannot edit the complexities of variable products. Typing a sale into the parent's bulk-edit Price field is the wrong repair.

On one affected variation, check:

1. Regular price is present.
2. Sale price is strictly below that regular price.
3. If sale dates are set, today's date in the store timezone falls inside them. The [CSV schema](https://woocommerce.com/document/product-csv-importer-exporter/) says a start date uses the start of that day and an end date uses the end of that day. A blank date means no date, not "leave the old date," when that column was imported.
4. The variation is enabled and published with the parent.

If the sale field is empty, you do not have this display bug. The import cleared or never wrote the sale. Go to [restore sale prices a CSV import overwrote](restore-sale-prices-overwritten-by-import.md).

If the sale field is filled and the shop is wrong, keep going. Do not upload another price CSV.

## Resync one product, then the batch

The product Update is what the original report says worked. The other steps were offered on that thread, or in a search-retrieved Reddit comment, and were not confirmed there. They are not a ranked guarantee for every host.

1. Edit the variable product and press **Update** without changing the variation prices. That is what made the sale appear for the original report.
2. If you have many parents, the support reply suggested selecting them on **Products > All Products**, choosing **Edit**, pressing **Apply**, changing nothing, and pressing **Update**. Do a small batch first. The bulk editing guide warns that a large page of products can strain the server. This no-change Update is a resync attempt. It is not permission to set a new Price or Sale value while you are there. A blank **Set to regular price decreased by** removes sale prices. The WordPress.org thread does not confirm that this batch step was run.
3. The original report says **Tools → Product lookup tables → Rebuild** had already been tried, together with a cache clear and a variation sync, and the sale still did not appear. A Reddit comment suggested **WooCommerce > Status > Tools** again. Do not treat lookup regeneration as the fix for that store. This research did not execute the tool.
4. If you use WP-CLI, the support reply named `wp wc product lookup regenerate --force`. The WordPress.org thread does not confirm that command was run, and the poster had already rebuilt lookup tables in the admin without the sale appearing. Run `wp help wc product` on that server and confirm the subcommand exists before you use it. Do not paste it from this article into a host where `wp wc` is a different package.

Afterward, check three variations: one that should be on sale, one that should not, and one with a future sale start date. Clear any full-page cache only after the editor and a logged-out browser agree. A cache can keep serving the old range after WooCommerce has already updated.

Also confirm you did not import the parent row's price and expect it to become the variation price. The customer-facing range comes from the variations.

## Why this is not an undo problem

Nothing in the November 2025 report says the sale price was deleted. The data was on the variation. The parent range and the lookup used by the shop had not caught up until a later save. Overwriting the sale to "fix" the range can create the real data loss the other articles are about.

If a later import did clear the sale, you need the pre-import export. A resync cannot invent a price that is no longer stored. A [dated price export](woocommerce-price-history.md) is how you tell those two cases apart.

## What WriteLeash does here, and what it does not

**Directory plugin, checked 27 October 2026.** [WriteLeash 0.2.2](https://wordpress.org/plugins/writeleash/) does not regenerate lookup tables and does not make a stored sale appear on the shop. Its listing also says previewed prices are planned stored prices, not guaranteed shopper prices. A correct stored sale that the theme or a cache still hides is outside the plugin.

WriteLeash is relevant only if you decide to change prices on purpose:

- You can set a sale, calculate one from the reviewed regular price, or clear a sale to blank rather than zero. Sale dates stay as they are. Stock and orders stay as they are.
- Preview and approval are separate. A nonblank sale target has to stay below the regular price.
- If you are still unsure whether the current stored sale is right, do not run a WriteLeash job to "refresh" it. The step that made the sale appear in the November 2025 report was updating the product. A lookup-table rebuild had already failed for that store.
- Eligible Undo restores prices WriteLeash applied, for 30 days by default on the listing. It does not restore a CSV import, and it does not fix a stale range.
- Requirements on the listing: WordPress 7.0 or newer (7.0.1 and 7.1.2 exercised) and WooCommerce 10.0 through 11.x. Published core simple products and published variations of published core variable products, base currency, up to 1,000 selected products per new job in the tested configuration. Parent prices are not edited directly.

**Not the download.** [Pull request #330](https://github.com/MrDarkRoot/WriteLeash/pull/330) on the default branch certifies pairs: WordPress 7.0 up to but not including 8.0 with WooCommerce 10.0 up to but not including 12.0, or WordPress 6.7 up to but not including 7.0 with WooCommerce 9.0 up to but not including 10.0. Do not install 0.2.2 onto the legacy pair. Nothing in that pair rule makes WriteLeash a cache tool.

## FAQ

### The variation sale price is correct and the shop is wrong. Do I import the CSV again?

No. Importing again can write over the sale you just confirmed. In the November 2025 report, updating the product made the sale appear after a lookup-table rebuild had not. Update one variable product and recheck the shop.

### The variation sale price is empty. Is this the same bug?

No. The sale is not stored. Use the pre-import export and [restore the sale prices](restore-sale-prices-overwritten-by-import.md).

### Does a no-change bulk Update edit variation prices?

The [bulk editing guide](https://woocommerce.com/document/bulk-editing-products/) says the products-list bulk editor does not handle variable-product complexity. The November 2025 support reply still suggested a no-change Update as a resync. Do not type a new price into that form while you use it for this purpose.

### Can WriteLeash force the shop to show the sale?

No. The 0.2.2 listing does not regenerate product lookup tables. It also warns that a planned stored price is not a guaranteed shopper price.

```json
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Article",
      "headline": "Why an imported WooCommerce sale price can be saved and still not show",
      "dateModified": "2026-10-27",
      "description": "If the variation editor shows the sale and the shop shows the regular price, do not import prices again. Resync one product before you touch the file.",
      "author": { "@type": "Organization", "name": "WriteLeash", "url": "https://wordpress.org/plugins/writeleash/" },
      "publisher": { "@type": "Organization", "name": "WriteLeash", "url": "https://wordpress.org/plugins/writeleash/" }
    },
    {
      "@type": "FAQPage",
      "mainEntity": [
        {
          "@type": "Question",
          "name": "The variation sale price is correct and the shop is wrong. Do I import the CSV again?",
          "acceptedAnswer": { "@type": "Answer", "text": "No. Importing again can overwrite the stored sale. In the November 2025 report, updating the product made the sale appear after a lookup-table rebuild had not. Update one variable product and recheck the shop." }
        },
        {
          "@type": "Question",
          "name": "The variation sale price is empty. Is this the same bug?",
          "acceptedAnswer": { "@type": "Answer", "text": "No. The sale is not stored. Restore it from the pre-import export." }
        },
        {
          "@type": "Question",
          "name": "Does a no-change bulk Update edit variation prices?",
          "acceptedAnswer": { "@type": "Answer", "text": "The products-list bulk editor is not the variation editor. A November 2025 support reply suggested a no-change Update as a resync. Do not type a new price into that form while using it for this purpose." }
        },
        {
          "@type": "Question",
          "name": "Can WriteLeash force the shop to show the sale?",
          "acceptedAnswer": { "@type": "Answer", "text": "No. WriteLeash 0.2.2 does not regenerate product lookup tables. Its listing says a planned stored price is not a guaranteed shopper price." }
        }
      ]
    }
  ]
}
```

Structured data template only. Do not emit it until this article has a public canonical URL. Add `datePublished` on that day. The affiliation above is required on the page.

When you are ready to change stored prices on purpose, with a preview of the before and after, [WriteLeash on WordPress.org](https://wordpress.org/plugins/writeleash/) is the plugin maintained with this guide. It will not repair a stale shop range.
