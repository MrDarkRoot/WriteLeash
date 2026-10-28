# Four weekly themes

These are the subjects for the thirty days after issue #313 is verified
DISTRIBUTED. They are not posts. Each one is useful with no plugin installed.
WriteLeash is optional and, when named, is the public 0.2.2 plugin only.

Do not start the weeks before distribution. Do not attach v0.3.0 agent or stock
claims to any week.

## Week 1 — See the stored price before it is written

WooCommerce's products-list bulk edit writes when the merchant clicks Update.
The [bulk-editing guide](https://woocommerce.com/document/bulk-editing-products/),
fetched 28 October 2026, describes that Update. The page does not describe an
undo. The same page says "Set to regular price decreased by" with a blank value
or 0 removes the Sales Price. That is a different control from decreasing the
existing sale price, which merchants have seen store 0.00. The practical habit
is: export the ID, name, regular price, and sale price first, change a handful
of rows, and check the stored values before the rest of the catalog.

A preview that is a separate step from Apply is the same idea. WriteLeash 0.2.2
does that for its own jobs. It does not rewind a bulk edit the merchant already
clicked.

## Week 2 — A reverse percentage is not an undo

On [woocommerce/woocommerce#17885](https://github.com/woocommerce/woocommerce/issues/17885)
the reporter found that raising a sale by the same percentage they had used to
lower it did not restore the original price. That is ordinary arithmetic. Ten
percent off 25.00 is 22.50. Ten percent added to 22.50 is 24.75.

Recovery is a stored previous value, from the export taken before the edit, or
from a tool that kept the value it itself wrote. It is not the opposite
percentage.

## Week 3 — A mapped CSV column is a write

Merchants keep meeting two different failures:

- An import that wrote a zero sale price, which the shop can sell.
- An import that stored variation sale prices while the variable product on the
  shop still showed the regular price.

The fix depends on which failure it is. A zero is a real price and needs the
previous value put back. A sale that is visible on the variation and missing on
the parent is a display sync, and importing the prices again can make it worse.

WriteLeash 0.2.2 does not undo a CSV import and does not refresh a variable
product's price range.

## Week 4 — What a catalog change does not cover

Agencies get asked to "lock prices" or "add price history." Core WooCommerce
checks `edit_products` for product edits. The roles guide does not list a
separate price capability. A history plugin records changes after it is active.
It does not reconstruct prices from before it was installed.

The public WriteLeash plugin does not lock staff, does not lock an AI agent,
and does not change stock. Those sentences stay in the week 4 note so a partner
conversation does not outrun the zip.
