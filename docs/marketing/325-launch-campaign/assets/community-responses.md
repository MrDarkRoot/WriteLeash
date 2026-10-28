# Ten community-response drafts

Fetched 28 October 2026. These are drafts for a later, similar, open thread.
They were not posted.

Every source thread below is closed to new replies, except the GitHub issue,
which is also closed. WordPress.org forum guidelines prohibit promoting a
plugin and warn that unvetted AI replies can be removed. A founder who answers
a new thread has to rewrite the answer for that thread and check the current
screen. Do not paste these.

The plugin sentence is optional. The steps above it stand alone. Public
WriteLeash 0.2.2 does not undo a native bulk edit or a CSV import, does not
change stock, and is not a staff or AI lock.

## 1. Variation sale stored, parent still shows the regular price

Source: [Variable product sale prices not showing after CSV import](https://wordpress.org/support/topic/variable-product-sale-prices-not-showing-after-csv-import/). Closed. The poster imported about 1,000 products. Variation sale prices and dates were visible on each variation. The variable product on the shop still showed the regular price. A manual product Update made the sale appear. Lookup-table rebuild, cache clearing, and variation sync had already failed for them. Support suggested a no-change bulk Update and `wp wc product lookup regenerate --force`. The poster replied "will try this." The thread was closed without a confirmation of either suggestion.

Draft:

Do not import the prices again. You can already see the sale on the variation, so the file did land. The parent variable product is what the shop uses for the range, and that range is the stale part.

Open one variable product and click Update, which is the step that already refreshed the sale for the person who reported this. If that one product updates and the others do not, the remaining work is a resync of the parents, not another price write.

A no-change bulk Update was suggested on that thread and was not confirmed there. If you try it, do it on a small batch after a backup, and change no price field. The WP-CLI command was also only a suggestion. Run `wp help wc product` on that host before you run a regenerate. I have not executed it on WooCommerce 10 or 11.

I maintain WriteLeash. It does not refresh a variable product's price range and it does not undo an import. Leave it out of this repair.

## 2. Import that seemed to wipe the catalog

Source: [Changing Prices via Import/Export](https://wordpress.org/support/topic/changing-prices-via-import-export/). Closed, resolved. The poster said an earlier import overwrote or wiped data, and that a later attempt duplicated products. They then followed the advice to export only the columns they meant to change, with "update existing products" on, tested a couple of rows, and said it worked.

Draft:

Export a new file that contains only the ID or SKU, the name if you want a label, and the price column you intend to write. Leave every other column out of the file. On import, turn on the option to update existing products that match by ID or SKU. Map those columns and set anything else to Do not import. Run two rows, then open those two products and compare the regular price, the sale price, the sale dates, and the stock with the backup.

A full export with blank cells is a different file. Do not assume a blank cell means "leave this alone." The poster on this thread also duplicated products before the update-existing path was right, so the two-row test is the part that matters.

If the damage is already saved, the repair is the export from before the bad file, not a second full import.

I maintain WriteLeash. It will not undo this import. The backup will.

## 3. The same percentage does not restore the price

Source: [woocommerce/woocommerce#17885](https://github.com/woocommerce/woocommerce/issues/17885). Closed 23 November 2017. The reporter used "Decrease existing sale price" and saw no change until it was clear the products were not already on sale. Later they wrote that increasing by the same percentage did not restore the original price. They also tried 0 and an empty string.

Draft:

"Decrease existing sale price" does nothing useful on a product that has no sale price. The matching control for a new sale is the one based on the regular price. Read the dropdown before you click Update. The products list writes on Update, and the bulk-editing guide does not document an undo.

Once a percentage has been applied, the reverse percentage is a new price, not a restore. Ten percent off 25.00 is 22.50. Ten percent added to 22.50 is 24.75. The way back is the regular and sale values from the export you took first.

A comment on that issue links another plugin. I am not recommending it. I have not installed it.

I maintain WriteLeash. Its Undo restores an eligible price that WriteLeash applied. It does not reverse a products-list bulk edit you have already saved.

## 4. Decrease by 100 percent made the product free

Source: [Creating/Removing Sale Prices by Category](https://wordpress.org/support/topic/creating-removing-sale-prices-by-category/). Closed. The poster could add a 20 percent sale from the products list. Decrease by 100 percent of the sale price stored 0.00, and the product could be purchased for 0. They wanted the sale field empty. WooCommerce support suggested a CSV with only Product ID and Sale Price, the sale cells emptied, and update-existing turned on. The original poster did not reply to confirm that the import cleared the field.

Draft:

Stop. A sale price of 0.00 is a real price. Customers can check out at zero. Do not run "decrease by 100 percent" across the rest of the category.

The [bulk-editing guide](https://woocommerce.com/document/bulk-editing-products/), fetched 28 October 2026, says that "Set to regular price decreased by" with a blank value or 0 removes the Sales Price. That dropdown is not "Decrease existing sale price," which is the one that stored 0.00 in this thread. I have not run either control on a store. If you use the documented remove option, test one product and confirm the sale field is empty rather than 0.00.

Put the previous sale prices back from the export or database backup taken before this bulk edit. If you no longer have that file, you do not have an automatic undo. Write the correct sale prices, or a genuine empty sale, per product, and test one product on the shop before the next batch.

WooCommerce support suggested a CSV that contains only the product ID and the sale price, with the sale cells empty, as a way to clear the sale. That suggestion was not confirmed by the poster. If you try it, keep every other column out of the file, map only those two fields, and test one ID. A mapped column is a write. I am not treating a blank cell as a documented no-op.

I maintain WriteLeash. Public 0.2.2 can clear a sale price to blank, not zero, on a job it previews and you approve. It cannot undo the bulk edit that already wrote 0.00.

## 5. A plugin file edit made sale prices 0 and then -1

Source: [Remove sale price is not working](https://wordpress.org/support/topic/remove-sale-price-is-not-working/). Closed. The poster used a 100 percent decrease on variable products and the sale became 0, so the products were free. Later replies edited `products.php` inside a bulk-editor plugin and set the sale to -1. Other people then reported £0.00 still on sale, or a stored price of -1.

Draft:

Do not edit that plugin's PHP from a forum snippet, and do not write -1 into a price field. People following that snippet reported a sale that stayed at 0.00 and, in one case, a price of -1.

The damage to repair is the zero sale. Restore the variation sale prices from a backup taken before the decrease. A variation and its parent are separate rows. Fixing the parent and leaving the variation at 0 still sells the variation for nothing.

After the backup is restored, clear a sale by storing an empty sale price, then open the variation and the shop. A zero and an empty field are not the same value.

I maintain WriteLeash. I would not use it to unwind this thread. The backup is the repair. The public plugin also does not change stock.

## 6. Shop Manager can still edit prices

Source: [Restricting Shop Manager Role](https://wordpress.org/support/topic/restricting-shop-manager-role/). Closed. The poster wanted a shop manager who could not import, export, add, or delete products. The plugin author replied that `import` and `export` hide those buttons, that `delete_products` covers removal, and that the same `edit_products` capability is checked for adding and for editing. WooCommerce may recreate the `shop_manager` role on activation.

Draft:

Revoking `import` and `export` removes those tools. It does not stop a price edit. Revoking `delete_products` stops deletion. It does not stop a price edit either.

Product prices sit on the product. The capability WooCommerce checks for editing a product is `edit_products`, and that same capability is what the role editor's author said is used for adding a product. There is no separate core capability whose only job is the price box. If this person must not change a price, they need a role that cannot edit products, plus a check of any REST key or application password that still can.

WooCommerce can put the stock `shop_manager` role back when it is activated again. A copy of the role is safer than editing the original and assuming it will stay edited. Confirm the buttons on a test user after the next WooCommerce update.

I maintain WriteLeash. Version 0.2.2 does not document a staff lock, and I will not give grant setup steps. Remove the product-edit access instead.

## 7. Order line prices are a different lock

Source: [Restrict shop admin from editing orders prices](https://wordpress.org/support/topic/restrict-shop-admin-from-editing-orders-prices/). Closed. The poster asked to stop a shop manager editing order product prices, and to stop a completed order being moved back to pending. Support said WooCommerce has no built-in screen for changing the Shop Manager role and pointed at a role editor. The thread does not show a successful lock.

Draft:

This is the order screen, not the catalog. A role change that only affects orders will not stop the same person editing the product's regular or sale price. Those are different objects.

WooCommerce support's reply on this thread is that core does not ship a role editor for Shop Manager. A role plugin can remove capabilities. It cannot invent a core "edit order price only" switch that the reply did not name. Test with a real shop-manager login: open an order, open a product, and try both saves. Also decide what should happen to order status separately. Completed-to-pending is an order-status capability, not a price field.

I maintain WriteLeash. It works on catalog prices it is asked to change. It does not restrict the order editor, and the public version does not lock a role.

## 8. A cart currency plugin saved another currency into the stored price

Source: [Price Mutation Issues](https://wordpress.org/support/topic/price-mutation-issues/). Closed, resolved. The store's base currency was GBP. A product at £27 was found stored as £31, the euro figure. A product at £16 was found stored as £29, the dollar figure. The reporter traced a currency plugin that changed the product object during cart totals. The object was not saved by that plugin, but later custom code called `save()` and the regional price was written. The plugin author shipped a fix in 4.2.0. The reporter said they would check it.

Draft:

Treat the stored regular price as untrusted until you compare it with a backup from before the cart incident. A currency switcher that calls `set_price` on the catalog product, followed by any `save()` during the cart request, can persist the converted amount as if it were the base-currency price. The author of the plugin in this thread agreed that saving a product from the cart is a bad place to do it, and later shipped 4.2.0 for their case. Confirm the version on the site. Do not assume a different currency plugin has the same fix.

Restoration is the base-currency price from the backup or from the export taken before the bad saves. Do not copy the price currently sitting on the cart row. That row is the converted one.

While you repair it, stop any custom code that calls `save()` on a product inside cart or checkout hooks. A filter on the displayed price, removed when the cart calculation ends, does not write the product row.

I maintain WriteLeash. It would not have stopped this. The write did not go through a WriteLeash job. It also does not convert currencies.

## 9. An ACF field named Sale Price wrote a huge sale price

Source: [Incorrect Sale Price Shows up After Editing WooCommerce Product](https://wordpress.org/support/topic/incorrect-sale-price-shows-up-after-editing-woocommerce-product/). Closed, resolved. With ACF and WooCommerce active, saving a product stored a sale price of $6,972,416,978. Quick Edit could clear it. The poster then renamed the ACF field names to `tour_price` and `tour_sale_price` and said the bad value stopped. A later reply from ACF support agreed that a generic field can collide with WooCommerce price meta such as `_sale_price`.

Draft:

Rename the custom field before you clean the prices. A field whose name is Sale Price, or whose meta key collides with `_sale_price` or `_regular_price`, can write the product's real price on save. The person who hit a multi-billion sale price fixed it by giving the fields unique names (`tour_price`, `tour_sale_price`). Deleting the bad sale and saving again will put it back while the colliding field remains.

After the rename, clear the bogus sale price once, from Quick Edit if the product screen puts it back, and check `_sale_price` is empty. Then look for other products saved while the old field existed. The bad value is already stored on those rows. A unique field name does not rewrite history.

I maintain WriteLeash. Do not run a bulk price job on top of a colliding field. Fix the field, then restore the real prices from the export taken before the bad saves.

## 10. Migrated prices with extra decimals showed as on sale

Source: [Price format correction](https://wordpress.org/support/topic/price-format-correction/). Closed, resolved. The poster said many prices were stored as values like `x.00000` and appeared in an on-sale product widget even with no sale price. Removing the extra zeros took a product out of that list. The store had been migrated from PrestaShop with Cart2Cart. A backend price showed `34.950000` after they had entered `34.95`. Decimal places in WooCommerce settings had been empty and were later set to 2. An import of about 5,000 rows, across two languages, failed part-way in both the core importer and another importer. Support could not reproduce "100.00 with no sale price" as on sale, and recommended splitting the CSV, with the header row on each part.

Draft:

Do not start from a full-catalog import. The prices are already stored with extra decimal places after a migration. Support looked at a file of normal `100.00` values and could not reproduce an on-sale flag from those. The value they could see on the product screen was `34.950000`. Clean a copy of the export, one language at a time, and only the ID plus the regular-price and sale-price columns. Set every other column to Do not import.

Split that file into batches that finish. Keep the header row on each batch. Do not overlap IDs. Import one batch, open three products, and compare the stored price with the shop and with the on-sale widget before you import the next batch. WPML needs the same check on the other language. A timeout in the middle of one giant file is how a second, half-written price set gets created.

I do not know whether the on-sale widget was matching a hidden sale meta row or the long decimal itself. The thread did not settle that. The batch compare will tell you which products are still listed.

I maintain WriteLeash. It is the wrong tool for a migration cleanup. It does not round someone else's stored decimals, and it does not undo an import.
