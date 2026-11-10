# Four weekly themes

Subjects for a founder who is allowed to post. They are not posts. Each one
is useful with no plugin installed. WriteLeash, when named, is public 0.3.0
and stays inside [../launch-truth.md](../launch-truth.md).

Do not run the four weeks on the same day. Days 1–13 after 2026-10-28 already
passed with no external post.

## Week 1 — See the stored price before it is written

The products list writes when you click Update. The
[bulk-editing guide](https://woocommerce.com/document/bulk-editing-products/),
modified 2026-10-31, tells you to check the resulting prices. It also says a
bulk edit that changes a regular or sale price clears the scheduled sale dates.
The page does not document an undo.

The habit that does not need a plugin: export the ID, name, regular price, and
sale price first. Change a handful of rows. Open those products and compare
the stored values, including the sale dates, before the rest of the catalog.

A separate preview is the same idea for a tool that has one. WriteLeash does
that for its own jobs. It does not rewind a bulk edit you already clicked.

## Week 2 — A reverse percentage is not an undo

On [woocommerce/woocommerce#17885](https://github.com/woocommerce/woocommerce/issues/17885)
the reporter found that raising a sale by the same percentage they had used to
lower it did not restore the original price. Ten percent off 25.00 is 22.50.
Ten percent added to 22.50 is 24.75.

Recovery is a stored previous value, from the export taken before the edit.
It is not the opposite percentage.

## Week 3 — A mapped CSV column is a write

Two different failures keep showing up:

- An import that wrote a zero sale price, which the shop can sell.
- An import that stored variation sale prices while the variable product on the shop still showed the regular price.
- An import that overwrote stock, or could not add to stock, because the built-in importer replaces the number in the file.

The fix depends on which failure it is. A zero is a real price and needs the
previous value put back. A sale that is visible on the variation and missing
on the parent is a display sync, and importing the prices again can make it
worse. A stock file replaces the quantity. It does not add to it unless you
did the addition in the spreadsheet first.

WriteLeash does not undo a CSV import. Stock Undo is not supported.

## Week 4 — What a catalog change does not cover

Core WooCommerce checks `edit_products` for product edits. There is no separate
price-only capability in that check. A history log starts the day it is turned
on. It does not reconstruct last month.

An assistant that can edit products needs the same pause: read the preview,
then decide. Some tools save a draft with no review card. Some have no undo
at all. A later edit should not be blindly overwritten by a rollback.

WriteLeash 0.3.0 is a seatbelt for prices, and for stock only after an opt-in
grant that stays off until someone issues it. It is not a firewall. Direct SQL
and hostile plugins are not covered. It does not undo stock.
