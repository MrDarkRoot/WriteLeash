# Launch announcement

Status: **not published.** Discussions are off. This is not a GitHub Release.
The link carries UTM parameters. WordPress.org will not report them back as
installs. See [../utm.md](../utm.md).

## Draft

**Check the stored WooCommerce price before the catalog write.**

I maintain WriteLeash, a free plugin on WordPress.org.

Before a large price change, export the ID, name, regular price, and sale
price. The products list writes when you click Update, and the current
bulk-editing guide does not document an undo. It does say to check the
resulting prices, and that a bulk price change clears scheduled sale dates.
A reverse percentage is a new price: 10 percent off 25.00 is 22.50, and 10
percent back is 24.75. A sale price of 0.00 is a real price. Customers can
check out at zero.

WriteLeash 0.3.0 previews its own price job and asks for a separate approval.
If the stored price changed after the preview, it leaves the newer value and
marks a conflict. Eligible Undo can put back a price it applied itself. Stock
changes only after an opt-in grant, which stays off until someone issues it.
Stock Undo is not supported. It is not a firewall, it does not stop direct SQL
or a hostile plugin, and it will not rewind a bulk edit or a CSV you already
ran.

There is a repository recording of a restricted shop manager using AddSynapse
with no model connected. Approve, then Yes, apply the changes, and the product
save was denied. An unrestricted administrator's save was not. The README calls
that an unreleased demonstration of an older repository build, not a capture of
the directory zip:

https://github.com/MrDarkRoot/WriteLeash/blob/main/demo/324-third-party/README.md

Listing: https://wordpress.org/plugins/writeleash/?utm_source=github&utm_medium=docs&utm_campaign=writeleash-v030-30d&utm_content=launch-note

## What this draft refuses to say

- That the video is the distributed 0.3.0 package.
- That an autonomous model edited the catalog in that recording. No model was called.
- That every staff user, every plugin, or every SQL write is covered.
- That Stock Undo exists.
- A testimonial, a user count, or a deadline.
