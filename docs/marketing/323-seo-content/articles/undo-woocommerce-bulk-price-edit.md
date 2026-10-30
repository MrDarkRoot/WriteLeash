---
title: "How to undo an accidental WooCommerce bulk price edit"
slug: undo-woocommerce-bulk-price-edit
meta_description: "WooCommerce bulk edit writes prices on Update and documents no undo. Restore them from a CSV or backup, and do not reverse a percentage with itself."
h1: "How to undo an accidental WooCommerce bulk price edit"
intent: "Recover regular or sale prices after the products-list bulk editor or the variation bulk actions wrote the wrong numbers."
failure_family: "Accidental bulk price edit"
affiliation: "WriteLeash is our product. This guide is not an independent review."
canonical_status: UNPUBLISHED
date_revised: 2026-10-27
---

# How to undo an accidental WooCommerce bulk price edit

**WriteLeash makes this guide.** We publish the [WriteLeash](https://wordpress.org/plugins/writeleash/) plugin. The recovery steps below use WooCommerce itself. You do not need WriteLeash to follow them. The section near the end says what the plugin does and what it does not do.

WooCommerce's own [bulk editing guide](https://woocommerce.com/document/bulk-editing-products/) tells you to select products, choose **Edit**, change the Price or Sale field, and press **Update**. That page does not describe an undo button. Once Update finishes, the new prices are the stored prices.

There are still ways to get the old numbers back. They depend on an export, a backup, or arithmetic you can check. They are not a hidden undo command.

## Two different bulk editors

Use the screen you actually used. They do not write the same rows.

**Products > All Products > Bulk actions > Edit.** This is the screen in the [bulk editing guide](https://woocommerce.com/document/bulk-editing-products/). Its Price field offers:

- Change to
- Increase existing price by a fixed amount or a percent
- Decrease existing price by a fixed amount or a percent

Its Sale field offers Change to, Increase existing sale price, Decrease existing sale price, and **Set to regular price decreased by**. The same guide says this screen works best with simple products and cannot edit the complexities of variable, grouped, or other complicated products.

**A variable product > Variations > Bulk actions.** This is a different menu, documented on the [variable product page](https://woocommerce.com/document/variable-product/). Those actions apply to the variations of that one product. WooCommerce's current page says the change saves immediately. For **Set sale prices to regular prices decreased by**, a regular price of 100 and an entry of 15 stores a sale price of 85. An entry of 20% stores 80. Entering 0 clears existing sale prices. Blank, non-numeric, or negative input leaves existing sale prices unchanged for that action. Variations with no regular price are skipped. The sale price cannot go below zero.

Quick Edit is a third path. The bulk editing guide says it is the same kind of form for one product, and it shows that product's current values. It also writes when you update. It is not an undo of a previous bulk edit.

## A concrete simple-product example

Store currency with two decimal places. Three published simple products, no sale prices:

| SKU | Regular price before | After Decrease existing price by 20% |
| --- | --- | --- |
| HOODIE | 80.00 | 64.00 |
| CAP | 24.00 | 19.20 |
| MUG | 12.50 | 10.00 |

Those three results are exact at two decimals: each old price times 0.8. A February 2026 thread, [bulk edit and decimal places](https://wordpress.org/support/topic/bulk-edit-price-decimal-places-bug/), is from a store on WooCommerce 10.4.3 and WordPress 6.9.1. Support said bulk edit saves with the store's decimal setting and, in that reply, with the usual two-decimal precision. The store owner then reported that export, edit, and import kept four decimals, and support agreed that workflow did. If your store keeps four decimal places, do not assume the bulk editor preserved them.

## Why "increase by 20%" does not undo "decrease by 20%"

Increasing the new prices by 20% does not return the old prices:

| SKU | After the 20% decrease | Increase that result by 20% | Original |
| --- | --- | --- | --- |
| HOODIE | 64.00 | 76.80 | 80.00 |
| CAP | 19.20 | 23.04 | 24.00 |
| MUG | 10.00 | 12.00 | 12.50 |

The inverse of "multiply by 0.8" is "multiply by 1.25", which is a 25% increase:

| SKU | 25% increase of the decreased price | Original |
| --- | --- | --- |
| HOODIE | 80.00 | 80.00 |
| CAP | 24.00 | 24.00 |
| MUG | 12.50 | 12.50 |

Use that inverse only when every product in the batch was decreased by the same percentage, the stored results match the arithmetic, and nobody has edited those prices since. A fixed decrease of 5.00 is reversed by a fixed increase of 5.00 under the same conditions. Rounding can break the reverse. At two decimals, 19.99 times 0.85 is 16.9915 and rounds to 16.99. That figure is arithmetic, not a price this research measured inside WooCommerce. Check the reverse on one product before you touch the rest.

A merchant reported the same surprise in [woocommerce/woocommerce#17885](https://github.com/woocommerce/woocommerce/issues/17885) on 23 November 2017: after a percentage decrease, increasing by that same percentage did not put the old sale price back. The issue itself was closed the same day for a different reason. **Decrease existing sale price** does nothing unless the product already has a sale price. There is no percentage-calculation defect in that thread, and the thread names no later release that changes the arithmetic. Use the tables above. Do not treat a second bulk edit as undo unless you have checked the numbers.

A bulk edit can also stop halfway. [woocommerce/woocommerce#37681](https://github.com/woocommerce/woocommerce/issues/37681), opened in April 2023, describes a sale-price bulk edit that hit a blank regular price, threw an error, and left the list partly updated. That bug is not a claim about today's code. It is a reason to export the current prices before you run a repair, so you can see which rows moved.

## Restore the old prices without a plugin

Work on a staging copy if you have one. On production, change one SKU first and read it back in the product editor.

1. Stop. Do not run another bulk edit, import, or sync until you know the before-values.
2. Find a CSV exported before the mistake. WooCommerce's exporter is **Products > All Products > Export**. The [CSV guide](https://woocommerce.com/document/product-csv-importer-exporter/) says an update matches existing products by ID or SKU.
3. Build a restore file with only the identifier column and the price columns you intend to put back. On the column mapping screen, set every other column to **Do not import**. Turn on **Update existing products**.
4. Import one row. Open that product and confirm the regular price, the sale price, and the sale dates. Then import the rest in batches you can check.
5. If you have no pre-change export, restore a database backup onto staging, export the prices from staging, and import those prices into the live store with the same mapping. Do not restore the live database over a store that has taken orders since the backup. Those orders roll back with the prices.
6. An order line shows the amount that customer was charged. It is not a catalog history. Products that did not sell are missing from it.

Installing a price-history plugin after the edit does not recreate the lost values. Those tools record from the moment they are active. See [how to keep a price history](woocommerce-price-history.md) for the habit that helps next time.

If the bulk edit cleared sale prices, use [restore sale prices a CSV import overwrote](restore-sale-prices-overwritten-by-import.md). The products-list guide says **Set to regular price decreased by** with a blank value or 0 removes the sale price. That is a write, not a no-op.

## What WriteLeash does here, and what it does not

**Directory plugin, checked 27 October 2026.** [WriteLeash 0.2.2](https://wordpress.org/plugins/writeleash/) requires WordPress 7.0 or newer and WooCommerce 10.0 through 11.x. WordPress 7.0.1 and 7.1.2 were exercised. That is not a promise that every 7.x release was tested.

If the bad write was the products-list bulk editor, Quick Edit, or the variation bulk actions, WriteLeash Undo does not reverse it. Undo restores eligible prices that WriteLeash itself applied, inside its window, when the current product still matches the saved record. The listing's default window is 30 days. Availability does not mean every row will restore. Undo does not reverse orders, completed sales, emails, webhooks, remote requests, external queues, or other plugins' effects. WriteLeash does not create a backup.

If you make the next price change with WriteLeash, the useful part is earlier in the workflow:

- Preview shows the stored before and after for the regular or sale price you chose. A separate approval is required. Changing the selection or the operation requires a new Preview.
- If the stored price WriteLeash is changing no longer matches the Preview, WriteLeash leaves the newer value and marks a conflict. Product name, SKU, and category alone do not cause that conflict. A value that changes away and then back may not be detected.
- Eligible Undo applies only to prices WriteLeash applied. The public listing says a changed relevant value can leave a row unrestored. In the 0.2.2 package, the undo code goes further on one case: restoring a regular price stops as a conflict when that restored regular price would be less than or equal to a sale price now stored on the product, instead of clearing the newer sale. A sale-price undo writes the saved sale price back through WooCommerce's product save. Neither case is a promise that every later sale survives.
- Scope on the 0.2.2 listing: published core simple products and published variations of published core variable products, in the base store currency, up to 1,000 selected products per new job in the tested configuration. Parent prices are not edited directly. Custom product types are unsupported. Multisite is unsupported. Real managed or shared hosting has not been tested. Ordinary price changes do not change sale dates, stock, or orders. Planned stored prices are not guaranteed shopper prices.

**Not the download.** The GitHub default branch, after merged [pull request #330](https://github.com/MrDarkRoot/WriteLeash/pull/330), certifies pairs rather than every combination: WordPress 7.0 up to but not including 8.0 with WooCommerce 10.0 up to but not including 12.0, or WordPress 6.7 up to but not including 7.0 with WooCommerce 9.0 up to but not including 10.0. Other pairs are refused before a job is created. Do not install the directory plugin on WordPress 6.7 and expect that pair. Restricted-agent and stock-on-a-grant behavior in [issue #312](https://github.com/MrDarkRoot/WriteLeash/issues/312) is not part of the 0.2.2 listing.

Some other bulk editors preview before they save, or undo only before save. [PW WooCommerce Bulk Edit](https://wordpress.org/plugins/pw-bulk-edit/) says nothing is written until you click Save, and you can undo before that. That is a different moment from restoring a price that is already stored. Read the current listing of any tool you install. This guide is not a ranking.

## A short rule for the next sale

Export ID, SKU, Regular price, Sale price, and both sale-date columns. Name the file with the date. Run the bulk edit on a category or a handful of SKUs you can count. Open two products and compare them with the export. Then do the rest.

If you want that preview and the option to restore eligible prices from the tool that wrote them, [WriteLeash on WordPress.org](https://wordpress.org/plugins/writeleash/) is the plugin this guide is about. It will not undo the bulk edit you have already saved.

## FAQ

### Does WooCommerce include an undo for bulk price edits?

The [bulk editing guide](https://woocommerce.com/document/bulk-editing-products/) and the [variation bulk-action section](https://woocommerce.com/document/variable-product/) describe the write. They do not describe an undo control. A backup, an older export, or a history tool that was already recording are the recovery paths.

### Will product revisions put the old price back?

Do not plan on it. Confirm on a staging copy, on one product, whether restoring a revision restores `_regular_price` and `_sale_price`. This guide did not find WooCommerce documenting revisions as a price undo.

### Can I undo a 20% decrease by increasing 20%?

No. In the example above, a 20% decrease is reversed by a 25% increase, and only when the stored prices are exactly the decreased values and nothing else has changed them.

### Will WriteLeash undo a bulk edit I already ran in WooCommerce?

No. WriteLeash Undo restores eligible prices WriteLeash applied. A native bulk edit is outside that history.

```json
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Article",
      "headline": "How to undo an accidental WooCommerce bulk price edit",
      "dateModified": "2026-10-27",
      "description": "WooCommerce bulk edit writes prices on Update and documents no undo. Restore them from a CSV or backup, and do not reverse a percentage with itself.",
      "author": {
        "@type": "Organization",
        "name": "WriteLeash",
        "url": "https://wordpress.org/plugins/writeleash/"
      },
      "publisher": {
        "@type": "Organization",
        "name": "WriteLeash",
        "url": "https://wordpress.org/plugins/writeleash/"
      }
    },
    {
      "@type": "FAQPage",
      "mainEntity": [
        {
          "@type": "Question",
          "name": "Does WooCommerce include an undo for bulk price edits?",
          "acceptedAnswer": {
            "@type": "Answer",
            "text": "The official bulk editing guide and the variation bulk-action section describe the write. They do not describe an undo control. Recovery uses a backup, an older export, or a history tool that was already recording."
          }
        },
        {
          "@type": "Question",
          "name": "Will product revisions put the old price back?",
          "acceptedAnswer": {
            "@type": "Answer",
            "text": "Do not plan on it. Confirm on a staging copy whether restoring a revision restores the regular and sale prices. WooCommerce does not document revisions as a price undo."
          }
        },
        {
          "@type": "Question",
          "name": "Can I undo a 20% decrease by increasing 20%?",
          "acceptedAnswer": {
            "@type": "Answer",
            "text": "No. A 20% decrease is reversed by a 25% increase only when the stored prices are exactly the decreased values and nothing else has changed them."
          }
        },
        {
          "@type": "Question",
          "name": "Will WriteLeash undo a bulk edit I already ran in WooCommerce?",
          "acceptedAnswer": {
            "@type": "Answer",
            "text": "No. WriteLeash Undo restores eligible prices WriteLeash applied. A native WooCommerce bulk edit is outside that history."
          }
        }
      ]
    }
  ]
}
```

Structured data template only. Do not emit it until this article has a public canonical URL. Add `datePublished` on that day. The affiliation above is required on the page.
