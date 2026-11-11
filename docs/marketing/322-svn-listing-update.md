# SVN listing update proposal (not committed)

Prepared 2026-11-10. This file is the approval packet. It does not publish the directory.

## Why both paths

The Plugin Handbook says WordPress.org reads `Stable tag` from `trunk/readme.txt`, then reads the plugin page from `/tags/<stable tag>/`. With `Stable tag: 0.3.0`, and with `tags/0.3.0/` present, nothing else in trunk is parsed for the public listing. Changing only `trunk/readme.txt` does not change the page.

Source: https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/

Checked 2026-11-10. Both live files still use the 119-character line "See WooCommerce price changes before they go live. Keep newer edits, undo eligible prices, and leash restricted agents." and open with "One bulk edit can turn a $199 product into $19."

- https://plugins.svn.wordpress.org/writeleash/trunk/readme.txt
- https://plugins.svn.wordpress.org/writeleash/tags/0.3.0/readme.txt

## What to copy

After founder approval, copy one file and nothing else:

`wordpress/writeleash/readme.txt` from the approved git SHA of PR #336

to both:

- `trunk/readme.txt`
- `tags/0.3.0/readme.txt`

Leave every PHP file, asset, and version header untouched in both directories. `Stable tag` stays `0.3.0`. Plugin header `Version` stays `0.3.0`.

## What this does, and what it does not

The handbook also says a readme commit regenerates the directory zip. The 0.3.0 zip's `readme.txt` bytes will change. The PHP payload will not, if the commit is limited to those two readme paths. Existing installs are not notified, because the version number does not increase. The public plugin page updates from `tags/0.3.0/readme.txt`.

Do not copy `writeleash.php`. Its Description line in git is the same short sentence, for the Plugins screen inside WordPress. Shipping that header requires a version bump and is out of this proposal.

Screenshots stay the current six bulk-edit images. Captions in the new readme still match those images. A denial screenshot is a separate `assets/` upload.

## Approval gate

Do not run `svn commit` until the founder approves this packet and names the git SHA to copy. Suggested message:

`Listing copy only. Stable tag stays 0.3.0. No PHP or version change.`
