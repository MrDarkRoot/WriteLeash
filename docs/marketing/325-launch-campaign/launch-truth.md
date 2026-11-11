# Launch truth

Fetched 10 November 2026. Figures below are from that fetch, not from the
28 October preparation note.

## Public plugin

| Check | Result |
| --- | --- |
| Plugin information API | `version` 0.3.0, `requires` 6.7, `tested` 7.1.4, `requires_php` 7.4, `last_updated` 2026-10-28 2:58pm GMT, `added` 2026-10-13. `requires_plugins`: woocommerce. Tags: prices, stock, undo, variations, woocommerce. |
| API `active_installs` | `0`. That field is the public "Fewer than 10" bucket. It is not a precise count of zero sites. |
| Ratings | `num_ratings` 0, `rating` 0. The reviews page contains "No reviews have been submitted yet." |
| Support | `support_threads` 0, `support_threads_resolved` 0. The support page contains "No topics found." |
| Directory zip | `https://downloads.wordpress.org/plugin/writeleash.0.3.0.zip` returned HTTP 200. `content-length` 1,618,335. `Last-Modified: Wed, 28 Oct 2026 14:59:05 GMT`. |
| Download events | [downloads.php](https://api.wordpress.org/stats/plugin/1.0/downloads.php?slug=writeleash&limit=400). 30 dates, 2026-10-11 through 2026-11-09. Sum **322**. 2026-11-10 was not in the response. These are download events, not active installs. |
| SVN tags index | [tags/](https://plugins.svn.wordpress.org/writeleash/tags/) HTTP 200. `Last-Modified: Wed, 28 Oct 2026 14:58:28 GMT`. |

The seed file is [metrics/2026-11-10-baseline.json](metrics/2026-11-10-baseline.json).

Issue #313 is closed. This pack does not reopen it and does not write SVN.
Issue #280 is still open. Nothing here is a compatibility pass.

## Git readme and the directory readme are not the same file

Compared on 10 November 2026: the plugin tree at git
`57c0de9d41a8c2d915394b7eb186e650f63db8f6` (`wordpress/writeleash/`) against
the files in `writeleash.0.3.0.zip`. Fifty-four payloads match. Only
`readme.txt` differs. The git copy is 9,645 bytes. The zip copy is 6,987
bytes. SVN trunk `readme.txt` matches the zip short description, not the git one.

| Copy | Short description |
| --- | --- |
| Git `main` | Protection against accidental WooCommerce price and stock overwrites, with Preview, conflicts, History and eligible Undo. |
| WordPress.org zip and SVN trunk | See WooCommerce price changes before they go live. Keep newer edits, undo eligible prices, and leash restricted agents. |

Do not describe the git readme as the distributed listing.

## What the directory readme may be called

The limits below are from the zip readme and the same SVN trunk readme, fetched
the same day. They are not a claim that the git file is what WordPress.org serves.

- Preview the regular price and the sale price, then approve and Apply. Walking away from Preview changes nothing. If the stored price or another checked setting no longer matches the preview, leave the newer value and mark a conflict.
- History shows what finished, what conflicted, and what can be undone. Eligible Undo puts a price back when the saved details and the product still allow it. The screenshot list says eligible Undo within 30 days. Availability does not guarantee restoration.
- Ordinary price jobs do not change stock. A regular-price change keeps the current sale price and its dates. Clear a sale price to blank, not zero.
- Stock moves only after a grant is issued, and that switch stays off until then. Stock Undo is not supported.
- A restricted WordPress principal is bounded to WriteLeash's controlled price and stock job path. Documented monitored WooCommerce write paths are denied outside a matching Execution Frame. Administrators who are not on the grant are outside it. A system cron on user 0 is outside it.
- It is a seatbelt, not a firewall. Direct SQL, hostile plugins, and unmonitored write paths are not covered. It is not a universal fail-closed guarantee, and it is not a sandbox or a universal agent-safe guarantee.
- Orders, completed sales, emails, webhooks, and other plugins stay as they are. Undo does not reverse a products-list bulk edit or a CSV import that already ran, because those were not an eligible WriteLeash price restore.
- Requirements in that readme: WooCommerce 10.0 through 11.x on WordPress 7.0 up to 8.0, and a legacy band of WordPress 6.7 below 7.0 with WooCommerce 9.0.0 below 10.0.0. Other pairs are refused before a job is created. The API "Requires at least" value is WordPress 6.7. This pack does not add pair-rule evidence. Up to 1,000 selected products per new job in the tested configuration.

Do not give grant setup steps in a public post. Do not say every automation account is covered. An administrator is not the restricted actor. A remote session that runs as an unrestricted administrator is outside the seatbelt described above.

## Repository recording, which is not the directory zip

These files are on `main` and returned HTTP 200 on 10 November 2026:

| File | URL | Bytes |
| --- | --- | --- |
| README | https://github.com/MrDarkRoot/WriteLeash/blob/main/demo/324-third-party/README.md | 11,914 |
| MP4 | https://raw.githubusercontent.com/MrDarkRoot/WriteLeash/main/demo/324-third-party/out/writeleash-addsynapse-deny.mp4 | 3,752,012 |
| GIF | https://raw.githubusercontent.com/MrDarkRoot/WriteLeash/main/demo/324-third-party/out/writeleash-addsynapse-deny.gif | 2,437,633 |
| Poster | https://raw.githubusercontent.com/MrDarkRoot/WriteLeash/main/demo/324-third-party/out/writeleash-addsynapse-deny-poster.png | 395,286 |

The same blob pages for the mp4, gif, and poster also returned HTTP 200.
`media.githubusercontent.com` returned HTTP 404 for the gif. Do not use it.

Describe the recording the way that README describes it. It is an unreleased
demonstration. AddSynapse Store Assistant 1.3.5 prepared a WooCommerce price
change as a restricted shop manager. No model was called, so it is not an
autonomous LLM write and not a hallucination. Provider cost was $0. Riley
pressed Approve, then Yes, apply the changes. WriteLeash denied
`WC_Product::save()` before the product rows changed. The cap stayed $40.00
and the tote stayed $100.00. An unrestricted administrator's lantern change,
from $30.00 to $55.00, was the positive control and did apply.

The README says the plugin bytes under test were header 0.2.0, not the
WordPress.org 0.2.2 package. The last caption says the same. Do not describe
the video as a capture of the distributed 0.3.0 zip. A fresh `run.sh` replaces
the grant id, expiry, and hashes. Do not copy those numbers into a post.

## Pages and what this pack still does not have

Fetched 10 November 2026. Each of these returned HTTP 200. This task did not
publish them. [PR #331](https://github.com/MrDarkRoot/WriteLeash/pull/331) is
still open. No Search Console property was read, and no search-volume number
is claimed.

| URL | HTTP |
| --- | --- |
| https://mrdarkroot.github.io/WriteLeash/ | 200 |
| https://mrdarkroot.github.io/WriteLeash/undo-woocommerce-bulk-price-edit/ | 200 |
| https://mrdarkroot.github.io/WriteLeash/restore-sale-prices-overwritten-by-import/ | 200 |
| https://mrdarkroot.github.io/WriteLeash/imported-sale-price-not-showing/ | 200 |
| https://mrdarkroot.github.io/WriteLeash/restrict-woocommerce-price-changes/ | 200 |
| https://mrdarkroot.github.io/WriteLeash/woocommerce-price-history/ | 200 |

| Item | State on 10 November 2026 |
| --- | --- |
| GitHub Releases | Empty list. |
| GitHub Discussions | `has_discussions` false. Not enabled. |
| Search Console and referral visits | `NOT_AVAILABLE`. The Pages responses above are not an analytics export. |
