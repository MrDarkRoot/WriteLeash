# Community drafts

Checked 10 November 2026. Not posted. **BLOCKED: no account** for Reddit,
Facebook, X, LinkedIn, email, and WordPress.org.

These are shapes for a later open thread that matches the source. Every source
below is closed. Do not paste them into the closed thread. Rewrite the answer
for the screen in front of you. The steps stand alone if the last sentence is
deleted.

The directory links use [../utm.md](../utm.md). WordPress.org will not report
those parameters. One mention of WriteLeash per draft. Public 0.3.0 does not
undo a bulk edit or a CSV that already ran, does not undo stock, and is not a
firewall.

## 1. Blank sale price became zero

Source: [woocommerce/woocommerce#29617](https://github.com/woocommerce/woocommerce/issues/29617), opened 2021-04-07, closed 2024-08-23. Bulk "change sale to blank" stored 0 and the products were free.

Draft:

Stop the next batch. A sale price of 0.00 is a price. People can check out for nothing. An empty field and a zero are not the same value, and a bulk form has treated them as the same before.

Put the previous sale prices back from the export or database backup taken before this edit. If that file is gone, there is no automatic undo. Fix one product, look at the shop, then do the next handful. Do not "reverse" it with another percentage.

I maintain WriteLeash. On a job it previews, clearing a sale means blank, not zero. It cannot undo the bulk edit that already wrote 0.00.

https://wordpress.org/plugins/writeleash/?utm_source=wporg-support&utm_medium=community&utm_campaign=writeleash-v030-30d&utm_content=reply-blank-sale

## 2. The same percentage does not restore the price

Source: [woocommerce/woocommerce#17885](https://github.com/woocommerce/woocommerce/issues/17885), opened and closed 2017-11-23.

Draft:

"Decrease existing sale price" does nothing useful on a product that has no sale price. The control that sets a new sale from the regular price is a different row in the dropdown. Read it before you click Update. The products list writes on Update. The current bulk-editing guide, modified 31 October 2026, tells you to check the resulting prices. It does not document an undo. A price change on that screen also clears scheduled sale dates, so check the dates on the products you open.

Once a percentage has been applied, the reverse percentage is a new price. Ten percent off 25.00 is 22.50. Ten percent added to 22.50 is 24.75. The way back is the regular and sale values from the export you took first.

I maintain WriteLeash. Its Undo restores an eligible price that WriteLeash applied. It does not reverse a products-list bulk edit you have already saved.

https://wordpress.org/plugins/writeleash/?utm_source=wporg-support&utm_medium=community&utm_campaign=writeleash-v030-30d&utm_content=reply-reverse-percent

## 3. Decrease by 100 percent made the product free

Source: [Creating/Removing Sale Prices by Category](https://wordpress.org/support/topic/creating-removing-sale-prices-by-category/). Closed. Starter and last activity "2 years, 11 months ago" on 10 November 2026. Decrease by 100 percent stored 0.00.

Draft:

Stop. Do not run "decrease by 100 percent" across the rest of the category. A sale price of 0.00 can be purchased.

I have not run the bulk controls on a store today. The bulk-editing guide does not say that a blank box removes the sale, so do not treat a blank cell as a documented clear. Test one product and confirm the sale field is empty rather than 0.00 before you touch another row.

Put the previous sale prices back from the export or backup taken before this edit. If you no longer have that file, write the correct sale, or a genuine empty sale, per product.

I maintain WriteLeash. It cannot undo the bulk edit that already wrote 0.00.

https://wordpress.org/plugins/writeleash/?utm_source=wporg-support&utm_medium=community&utm_campaign=writeleash-v030-30d&utm_content=reply-sale-zero

## 4. Variation sale stored, parent still shows the regular price

Source: [Variable product sale prices not showing after CSV import](https://wordpress.org/support/topic/variable-product-sale-prices-not-showing-after-csv-import/). Closed, resolved. Starter "11 months, 2 weeks ago" on 10 November 2026.

Draft:

Do not import the prices again. You can already see the sale on the variation, so the file did land. The parent variable product is what the shop uses for the range, and that range is the stale part.

Open one variable product and click Update. That is the step that refreshes the range for a lot of people in this situation. If that one product updates and the others do not, resync the parents. Do not write a new price while you do it.

A no-change bulk Update was suggested on that thread and was not confirmed there. If you try it, take a backup, use a small batch, and change no price field.

I maintain WriteLeash. It does not refresh a variable product's price range and it does not undo an import. Leave it out of this repair.

https://wordpress.org/plugins/writeleash/?utm_source=wporg-support&utm_medium=community&utm_campaign=writeleash-v030-30d&utm_content=reply-csv-parent

## 5. The import created products, or the prices did not move

Source: [Woocommerce product import from CSV not working properly](https://wordpress.org/support/topic/woocommerce-product-import-from-csv-not-working-properly-2/). Closed, resolved. Starter "10 months, 3 weeks ago" on 10 November 2026. The thread's own fix was the Windows list separator.

Draft:

Export a new file that contains only the ID or SKU and the price column you intend to write. On import, turn on update existing products. Map those columns and set anything else to Do not import. Run two rows, then open those two products and compare the regular price, the sale price, the sale dates, and the stock with the backup.

If new products appeared with empty titles, the importer did not match an existing ID or SKU. Do not run the full file again until two known IDs update in place. Also open the CSV in a plain editor and check the delimiter. A list separator that does not match the importer will shift every column, which is what resolved the thread this draft comes from.

If the damage is already saved, the repair is the export from before the bad file.

I maintain WriteLeash. It will not undo this import.

https://wordpress.org/plugins/writeleash/?utm_source=wporg-support&utm_medium=community&utm_campaign=writeleash-v030-30d&utm_content=reply-csv-match

## 6. The stock file replaced the quantity

Source: [Updating stock via CSV](https://wordpress.org/support/topic/updating-stock-via-csv/). Closed, resolved. Starter "6 years, 2 months ago" on 10 November 2026. WooCommerce support said the built-in importer overwrites stock and cannot add to it.

Draft:

The number in the stock column replaces the number in the shop. It does not add to it. A plus sign in the cell is not an "add 12 units" instruction in the built-in importer.

Export the current SKU and stock first. Add the new units in the spreadsheet, so the file contains the total you want stored. Import only those two columns, with update existing turned on. Check two SKUs before the rest. If a product is sold between the export and the import, your total is already stale. That is why the two-row check matters on a busy shop.

I maintain WriteLeash. Stock changes in that plugin are a separate opt-in path, and stock undo is not supported. It will not reverse this file. Use the export you just took.

https://wordpress.org/plugins/writeleash/?utm_source=wporg-support&utm_medium=community&utm_campaign=writeleash-v030-30d&utm_content=reply-stock-csv

## 7. A custom field named Sale Price wrote a huge number

Source: [Incorrect Sale Price Shows up After Editing WooCommerce Product](https://wordpress.org/support/topic/incorrect-sale-price-shows-up-after-editing-woocommerce-product/). Closed, resolved. Starter "9 months ago" on 10 November 2026. Last activity "6 months, 1 week ago."

Draft:

Rename the custom field before you clean the prices. A field whose name is Sale Price, or whose meta key collides with `_sale_price` or `_regular_price`, can write the product's real price on save. The person who hit a multi-billion sale price fixed it by giving the fields unique names. Deleting the bad sale and saving again puts it back while the colliding field remains.

After the rename, clear the bogus sale once. Quick Edit worked for them when the product screen put the number back. Then look for other products saved while the old field existed. A new field name does not rewrite those rows.

I maintain WriteLeash. Do not run a bulk price job on top of a colliding field. Fix the field, then restore the real prices from the export taken before the bad saves.

https://wordpress.org/plugins/writeleash/?utm_source=wporg-support&utm_medium=community&utm_campaign=writeleash-v030-30d&utm_content=reply-acf-sale

## 8. An assistant, or a cart save, wrote the wrong price

Sources: [Review and undo WooCommerce AI changes](https://woocommerce.com/document/woocommerce-ai/review-and-undo/), modified 2026-10-23, and [Price Mutation Issues](https://wordpress.org/support/topic/price-mutation-issues/), closed, starter "9 months, 3 weeks ago" on 10 November 2026. The AI page is documentation, not a wrecked-catalog report. The currency thread is a stored-price overwrite.

Draft:

Treat the stored regular price as untrusted until you compare it with a backup from before the bad save. Two different things land in the same column.

A currency switcher that changes the product object during cart totals, followed by any `save()` in that request, can persist the converted amount as the catalog price. Do not copy the price off the cart row. Stop custom code that saves a product inside cart or checkout hooks. A filter on the displayed price, removed when the calculation ends, does not write the product row.

An assistant is the same class of write if it can call product save. Read the preview before you approve it. WooCommerce's own AI document says some draft saves happen with no review card, and that undo cannot reverse every side effect. A later edit should stay unless you have decided to replace it. The WooCommerce engineering note of 16 September 2026 on the Claude commerce agent says there is no undo in that reference project. Reversing it means asking for a new change, which can be wrong.

I maintain WriteLeash. Grants for a restricted automation account stay off until someone issues one. It is not a firewall. Direct SQL and hostile plugins are not covered. It would not have stopped a cart `save()` that never went through its job, and it cannot undo stock.

https://wordpress.org/plugins/writeleash/?utm_source=wporg-support&utm_medium=community&utm_campaign=writeleash-v030-30d&utm_content=reply-assistant-or-cart
