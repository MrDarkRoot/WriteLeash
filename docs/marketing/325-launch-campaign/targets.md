# High-fit targets

Fetched 10 November 2026. Eight places where a useful reply fits. Closed
threads are patterns for the next open question, not places to paste a draft.
No reply was posted. Direct requests to `www.reddit.com` returned no HTTP
status in this environment, so Reddit threads from search are omitted.

## Threads and docs

1. [Bulk updating products to a blank sale price set them to 0](https://github.com/woocommerce/woocommerce/issues/29617). Opened 2021-04-07, closed 2024-08-23. The reporter put the site in maintenance because products were free. A helpful reply separates a blank sale from a stored 0, and points at the export or backup taken before the bulk edit. Another percentage is not a restore.

2. [Decrease existing sale price, and the reverse percentage](https://github.com/woocommerce/woocommerce/issues/17885). Opened and closed 2017-11-23. The reporter found that increasing by the same percentage did not restore the original price. A helpful reply shows the arithmetic: 10 percent off 25.00 is 22.50, and 10 percent added to 22.50 is 24.75. The way back is the stored value from before the edit.

3. [Variation sale stored, parent still shows the regular price](https://wordpress.org/support/topic/variable-product-sale-prices-not-showing-after-csv-import/). Page fetched 2026-11-10. Closed to new replies. Status resolved. The topic description says the starter posted "11 months, 2 weeks ago," and last activity is the same age. About 1,000 products. The variation sale was visible. The shop still showed the regular price until a product Update. A helpful reply says not to import the prices again. The parent range is the stale part. A no-change Update was suggested on the thread and was not confirmed there.

4. [CSV price update created products instead of changing prices](https://wordpress.org/support/topic/woocommerce-product-import-from-csv-not-working-properly-2/). Closed, resolved. Starter "10 months, 3 weeks ago" on 2026-11-10. Last activity "10 months, 2 weeks ago." A helpful reply is the two-row test: ID or SKU, update existing products, map only the price columns, then open the products. The thread's own resolution was a Windows list separator, which is a reminder to look at the file before running it again.

5. [Updating stock via CSV](https://wordpress.org/support/topic/updating-stock-via-csv/). Closed, resolved. Starter "6 years, 2 months ago." Last activity "6 years, 1 month ago." WooCommerce support said the built-in importer overwrites stock and cannot add to the current quantity. A helpful reply is to export the current stock, add the new units in the spreadsheet, and import only SKU and stock after a two-row check. This is an inventory mistake, not a price undo.

6. [A custom field named Sale Price wrote a huge sale price](https://wordpress.org/support/topic/incorrect-sale-price-shows-up-after-editing-woocommerce-product/). Closed, resolved. Starter "9 months ago." Last activity "6 months, 1 week ago," with a reply link titled 5 May 2026. A helpful reply is to rename the colliding field before clearing the sale. Deleting the bad number and saving again puts it back while the field still matches WooCommerce price meta.

7. [A cart currency plugin left another currency in the stored price](https://wordpress.org/support/topic/price-mutation-issues/). Closed, resolved. Starter "9 months, 3 weeks ago." Last activity "9 months ago." A later `save()` persisted the converted amount as the catalog price. A helpful reply treats the stored price as untrusted until it is compared with a backup, and stops custom code that saves a product during cart totals. Copying the price off the cart row copies the converted one.

8. [Review and undo WooCommerce AI changes](https://woocommerce.com/document/woocommerce-ai/review-and-undo/). Official documentation. `dateModified` 2026-10-23. Not an accident report. No verified first-person thread of an assistant wrecking a catalog was opened in this session. A helpful reply, when someone is using an assistant, is the page's own limit: read the preview, some draft saves happen without a review card, undo cannot reverse every side effect, and a later edit is skipped unless someone chooses to overwrite it. The related engineering note [Running the Claude Commerce Agent](https://developer.woocommerce.com/2026/09/16/wc-claude-commerce-agent/) is dated 2026-09-16 and says, in its limitations, "There is no undo." Reversing a change there means staging a new one.

Also seen, and used only as a draft source: [Creating/Removing Sale Prices by Category](https://wordpress.org/support/topic/creating-removing-sale-prices-by-category/). Closed, resolved. Starter and last activity "2 years, 11 months ago." Decrease by 100 percent stored 0.00 and the product could be purchased for nothing.

The current [bulk-editing products](https://woocommerce.com/document/bulk-editing-products/) page was modified 2026-10-31. It tells the merchant to check the resulting prices after a percentage change. It says a bulk edit that changes a regular or sale price clears the scheduled sale dates. It does not document an undo. It does not say that a blank value removes the sale price. Do not repeat that older sentence.

## Submission pages that returned HTTP 200

These are forms, not posts. Nothing was submitted. Login or a real article URL is still required. The plugin URL is the wrong thing to send to the first one.

| Page | What the page says | Why it is not sent |
| --- | --- | --- |
| https://wpcontent.io/submit/ | Do not post links directly to a product, plugin, or theme page. A release post is fine. First submissions are held for review. Login is required. | The five guide URLs in [launch-truth.md](launch-truth.md) returned HTTP 200. Nothing was submitted. **BLOCKED: no account.** [PR #331](https://github.com/MrDarkRoot/WriteLeash/pull/331) is still open. |
| https://thewpweekly.com/submit/ | Form for WordPress articles, resources, guides, news, new products, and services. Publishing is at their discretion. | The guide URLs exist. Nothing was submitted. **BLOCKED: no account.** An earlier request to this host failed to connect. A later request on the same day returned HTTP 200. |
| https://wptavern.com/contact/ | A contact form. The page says they cannot reply to every note about a new release. | **BLOCKED: no account.** This task does not send email or a form. |

Checked and not used: https://poststatus.com/contact/ is a general contact page (`dateModified` 2025-05-23), not a directory. https://masterwp.com/write-for-us/ returned HTTP 403. https://www.wpmayor.com/write-for-us/ returned HTTP 404. https://www.therepository.email/submit returned HTTP 403.
