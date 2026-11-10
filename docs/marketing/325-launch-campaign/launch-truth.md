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

## What 0.3.0 may be called

From the public readme on `main`, which matches the distributed listing copy:

- Preview a price change, approve it separately, and Apply. If the stored price or another checked setting no longer matches the preview, leave the newer value and mark a conflict.
- History records the result. Eligible Undo restores prices WriteLeash applied when the saved details and the current product still permit it. The default window on the listing screenshots is 30 days. Availability does not guarantee restoration.
- Ordinary price jobs do not change stock, sale dates, or orders. Clear makes a sale price blank, not zero.
- Stock changes only on an opt-in restricted grant, through the controlled price and stock job path. That path stays off until an operator issues the grant. Stock Undo is not supported.
- A restricted WordPress principal is bounded to that controlled path. Documented monitored WooCommerce writes are denied outside a matching Execution Frame.
- It is not a firewall. It does not isolate hostile plugins. It does not stop direct SQL. It is not a universal fail-closed guarantee, and it is not a sandbox.
- Undo does not reverse orders, completed sales, emails, webhooks, remote requests, external queues, or other plugins' effects. It does not undo a products-list bulk edit or a CSV import that already ran.
- The readme limits jobs to tested WordPress and WooCommerce pairs: WooCommerce 10.0 through 11.x on WordPress 7.0 up to 8.0, and a legacy band of WordPress 6.7 below 7.0 with WooCommerce 9.0.0 below 10.0.0. Other pairs are refused before a job is created. The API "Requires at least" value is WordPress 6.7. This pack does not add pair-rule evidence. Up to 1,000 selected products per new job in the tested configuration.

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

## Still not public

| Item | State on 10 November 2026 |
| --- | --- |
| GitHub Releases | Empty list. |
| GitHub Discussions | `has_discussions` false. Not enabled. |
| GitHub Pages | `https://mrdarkroot.github.io/WriteLeash/` returned HTTP 404. |
| [#323](https://github.com/MrDarkRoot/WriteLeash/issues/323) / [PR #331](https://github.com/MrDarkRoot/WriteLeash/pull/331) | Open. No live article URL. |
| Search Console, referral visits | `NOT_AVAILABLE`. |
