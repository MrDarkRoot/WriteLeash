# Launch announcement

Status: **DO NOT PUBLISH as a v0.3.0 launch.** Issue #313 is not DISTRIBUTED.
This text describes only the plugin people can install today. It is a draft for
a founder-controlled GitHub note if the founder wants one. It is not a GitHub
Release, and this repository has Discussions turned off.

v0.3.0 claims: OMIT until issue #313 is verified DISTRIBUTED.

## Draft

**WriteLeash 0.2.2 previews a WooCommerce price change before it is applied.**

I maintain WriteLeash, a free plugin on WordPress.org.

You choose published simple products or variations, see the stored price and
the price you intend to write, and approve that preview as a separate step.
If a price you are changing was edited again after the preview, WriteLeash
leaves the newer value in place and marks a conflict. History shows the job.
Eligible Undo can put back a price WriteLeash itself applied, inside the undo
window, when the current product still matches what was saved.

It does not change sale dates, stock, or orders. It does not undo a normal
WooCommerce bulk edit or a CSV import. It does not lock staff or an AI agent
in this public version. The tested job size on the listing is up to 1,000
selected products. It needs WordPress 7.0 or newer and WooCommerce 10.0
through 11.x.

Listing and supported scope: https://wordpress.org/plugins/writeleash/

## What this draft refuses to say

- That a restricted shop manager's product save is denied. That behavior was
  recorded on an unreleased repository build in `demo/324-third-party/`, not
  on the 0.2.2 zip.
- That stock is protected. The public readme says stock is not changed.
- That WordPress 6.7 or WooCommerce 9 is a supported install. That pair rule
  is on `main` and is not the directory package.
