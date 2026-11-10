# Launch truth

Checked 28 October 2026. Each row is a fetch from that day, not a memory of an
earlier session.

## Public plugin

| Check | Result |
| --- | --- |
| Directory page | [wordpress.org/plugins/writeleash](https://wordpress.org/plugins/writeleash/). HTTP 200. Visible text: `Version 0.2.2`. Visible text: `Active installations Fewer than 10`. |
| Plugin information API | [slug query](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=writeleash). `version` 0.2.2, `requires` 7.0, `tested` 7.1.3, `requires_php` 7.4, `last_updated` 2026-10-18 12:00pm GMT, `added` 2026-10-13. Tags: bulk edit, prices, undo, variations, woocommerce. |
| API `active_installs` | `0`. That field is how this API represents the public "Fewer than 10" bucket. It is not a precise count of zero sites. |
| Latest zip | `https://downloads.wordpress.org/plugin/writeleash.latest-stable.zip` returned HTTP 302 to `https://downloads.wordpress.org/plugin/writeleash.0.2.2.zip`, then HTTP 200. 1,580,933 bytes. SHA-256 `09ff494b80646a5ec854bfb52f16dd3ad1a68d27da88a9e8564963b74b9f8b90`. Header `Version: 0.2.2`. Readme `Stable tag: 0.2.2`. `Last-Modified: Sun, 18 Oct 2026 12:00:24 GMT`. |
| SVN tags | [tags/](https://plugins.svn.wordpress.org/writeleash/tags/). `0.2.0`, `0.2.1`, `0.2.2`. No `0.3.0`. Index `Last-Modified: Sun, 18 Oct 2026 12:00:11 GMT`. |
| Trunk readme | [trunk/readme.txt](https://plugins.svn.wordpress.org/writeleash/trunk/readme.txt). `Stable tag: 0.2.2`. Short description is the bulk-edit listing, not the default-branch stock sentence. It says sale dates, stock, and orders are not changed. |
| Reviews | [reviews](https://wordpress.org/support/plugin/writeleash/reviews/). `No reviews have been submitted yet.` API `num_ratings` 0, `rating` 0. |
| Support | [support](https://wordpress.org/support/plugin/writeleash/). `No topics found. Select another view or start a new post.` API `support_threads` 0, `support_threads_resolved` 0. |
| Download events | [downloads.php](https://api.wordpress.org/stats/plugin/1.0/downloads.php?slug=writeleash&limit=90). 17 dates, 2026-10-11 through 2026-10-27. Sum **204**. 2026-10-28 was not in the response. These are download events, not active installs and not users. |

## What 0.2.2 contains, and what it does not

String search of the 0.2.2 zip on 28 October 2026:

| String | In the zip |
| --- | --- |
| `class-grant-repository.php` | yes |
| `Woo_Stock_Mutator` | no |
| `is_restricted_actor` | no |
| `Perimeter` | no |

Changelog entry for 0.2.2: "Added disabled-by-default foundations for bounded
delegated price authority." The zip also contains `class-grant-schema.php` and
`class-agent-jobs-rest.php`. That is not a merchant-facing staff lock or an AI
lock. This pack does not give grant setup steps.

Public behavior that drafts may describe, from the trunk readme fetched the
same day:

- Select by name, SKU, category, or ID. Preview exact before-and-after prices.
- Approve separately, then Apply. A newer stored price or another checked
  setting that no longer matches Preview is left alone and marked as a conflict.
- History, and eligible Undo inside the Undo window (30 days by default).
- Regular Price and Sale Price for published simple products and published
  variations. Up to 1,000 selected products per new job in the tested
  configuration.
- Requires WordPress 7.0 or newer and WooCommerce 10.0 through 11.x.

## Repository, which is not the directory download

| Surface | State on 28 October 2026 |
| --- | --- |
| `origin/main` | `f43d59397bba6094bf65aafeca4939143bce784b`, merge of [PR #333](https://github.com/MrDarkRoot/WriteLeash/pull/333). Plugin header on that commit: `Version: 0.2.0`. Readme `Stable tag: 0.2.0`. Requires WordPress 6.7 and WooCommerce 9.0. |
| [#330](https://github.com/MrDarkRoot/WriteLeash/pull/330) | Merged 27 October 2026. Listing copy on `main` talks about price and stock overwrites and an opt-in restricted grant. That copy is not the WordPress.org trunk readme. [Issue #322](https://github.com/MrDarkRoot/WriteLeash/issues/322) is still open. |
| [#329](https://github.com/MrDarkRoot/WriteLeash/pull/329) and [#328](https://github.com/MrDarkRoot/WriteLeash/pull/328) | Merged. Restricted-actor and product-create denial are in `main`. They are not in the 0.2.2 zip (no `Perimeter`, no `is_restricted_actor`). |
| [#313](https://github.com/MrDarkRoot/WriteLeash/issues/313) | Open. The 26 October 2026 comment classifies #323–#327 as post-release growth work. No founder GO for SVN is recorded on that issue. |
| GitHub Releases | [releases](https://github.com/MrDarkRoot/WriteLeash/releases) shows no releases. `GET /repos/MrDarkRoot/WriteLeash/releases` returned `[]`. |
| GitHub Discussions | Repository `has_discussions` is false. |
| GitHub Pages | `https://mrdarkroot.github.io/WriteLeash/` returned HTTP 404. |

## Related marketing work

| Item | Public status |
| --- | --- |
| [#323](https://github.com/MrDarkRoot/WriteLeash/issues/323) / [PR #331](https://github.com/MrDarkRoot/WriteLeash/pull/331) | Open. Five Markdown guides. `publication_status` is `BLOCKED`. `live_url` is null on every article. These are not published articles. |
| [#324](https://github.com/MrDarkRoot/WriteLeash/issues/324) / [PR #332](https://github.com/MrDarkRoot/WriteLeash/pull/332) | Open. Seatbelt recording is not on `main`. |
| [#333](https://github.com/MrDarkRoot/WriteLeash/pull/333) | Merged. `demo/324-third-party/` records an unreleased repository build, header 0.2.0, denying `WC_Product::save()` for a restricted shop manager using AddSynapse 1.3.5. `versions.json` sets `wordpress_org_distributed_writeleash` to `0.2.2` and `demonstration` to `unreleased repository build`. The README says WordPress.org 0.2.2 was not the recorded build. |

## Distribution verdict

v0.3.0 is **not DISTRIBUTED**. The 30-day launch calendar stays closed.
