# Partner email drafts

Status: **not sent. BLOCKED: no account.** No recipient and no list. The
founder sends one of these only to a person they already have a reason to
write to. A scraped list is out of scope. One note, no chase.

Each email is useful if the reader never installs anything. The directory
link will not be reported back by WordPress.org. See [../utm.md](../utm.md).

## 1. Agency that edits client catalogs

Subject: A catalog habit before the next sale price change

Hello,

I maintain WriteLeash. I am not asking for a partnership or a call.

The failure I keep seeing is mechanical. The products list writes on Update.
The bulk-editing guide tells you to check the resulting prices and does not
document an undo. A decrease of 100 percent can store a sale price of zero,
and the shop can then give the product away. Putting the same percentage back
on does not restore the earlier price.

The habit that survives without any plugin: export ID, name, regular price,
and sale price, edit a few rows, and compare the stored values, including the
sale dates, before the rest. If you already do that for clients, I would like
to hear where it still fails. If a one-page note of that sequence would help
your team, I can send the note. If not, ignore this.

WriteLeash only undoes an eligible price it applied itself. It does not undo
a native bulk edit or a CSV, and it does not undo stock.

https://wordpress.org/plugins/writeleash/?utm_source=partner-email&utm_medium=email&utm_campaign=writeleash-v030-30d&utm_content=email-agency

## 2. Host whose merchants ask about a bad import

Subject: When a WooCommerce import writes a zero sale price

Hello,

I maintain WriteLeash. This is one note, not a newsletter, and there is
nothing to buy.

A pattern in WooCommerce support is an import that leaves sale prices at 0.00,
so the shop gives the product away, or an import that stores the variation
sale while the parent product still displays the regular price. Stock files
have a third version: the importer replaces the quantity, and it does not add
to the number already in the shop. Those are different repairs. Re-importing
the same prices does not fix a stale parent range. A backup from before the
file is what puts a zero back.

I am not asking you to recommend a plugin. If your support team already has a
checklist for "the sale became zero" versus "the sale is on the variation but
not on the shop" versus "the stock file replaced the quantity," I would learn
from it.

The public plugin does not undo imports, and stock undo is not supported. I
would rather your team keep using the backup.

https://wordpress.org/plugins/writeleash/?utm_source=partner-email&utm_medium=email&utm_campaign=writeleash-v030-30d&utm_content=email-host

## 3. Freelancer who maintains one or two catalogs

Subject: Price history starts the day the log is turned on

Hello,

I maintain WriteLeash. Writing because catalog cleanup is the work you already
do. No list, and no ask to introduce me to your clients.

WooCommerce does not keep a price log of its own. A history plugin shows
changes made after it was installed. It cannot reconstruct last month. The
recoverable copy is the CSV or database backup taken before the edit.

The same pause applies when an assistant can edit products. Read the preview
before anything is stored. Some tools have no undo, and a rollback that
overwrites a later edit is another write.

WriteLeash can preview its own price job and undo an eligible price it wrote.
Stock changes only after an opt-in grant, which stays off until it is issued,
and those stock edits cannot be undone. It will not stop direct SQL or a
hostile plugin. If that boundary is still useful on a store you maintain, the
listing is here:

https://wordpress.org/plugins/writeleash/?utm_source=partner-email&utm_medium=email&utm_campaign=writeleash-v030-30d&utm_content=email-freelancer

If you do not want notes like this, tell me and I will not write again.
