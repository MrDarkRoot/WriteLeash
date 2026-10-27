# Claims audit for the #323 articles

Auditor: content pass on 27 October 2026, against the WordPress.org listing, the 0.2.2 zip in `archives/wporg-v0.2.2/`, and `origin/main` at `3be6805` (merge of #330). Articles were not browser-published, so structured data was not tested in Google's rich-results tool. Status for that check is `NOT_TESTED`.

## Release split

| Surface | What it is | What the articles may say |
| --- | --- | --- |
| WordPress.org WriteLeash 0.2.2 | The installable plugin. Listing fetched 2026-10-27. Stable tag 0.2.2. | Preview, separate approval, conflict retention, History of its own jobs, eligible price Undo, 30-day default window. Does not change stock, sale dates, or orders. Requires WordPress 7.0 or newer and WooCommerce 10.0 through 11.x. Exercised WordPress versions named on the listing: 7.0.1 and 7.1.2. |
| 0.2.2 zip | Contains `class-grant-repository.php` and `class-grant-schema.php`. Does not contain a stock mutator, perimeter class, or grant-admin class (zip listing filtered 2026-10-27). Changelog: disabled-by-default foundations for bounded delegated price authority. | Foundations exist in the zip and are not a documented staff or agent lock. Articles must not give grant setup steps. |
| 0.2.2 undo code | `class-woo-undo-mutator.php` throws `UNDO_CONFLICT` when a regular-price restore would be less than or equal to the current sale price, with a comment that this avoids clearing a newer sale. | Allowed in the undo article, as a conflict, not as "the sale is always kept and the regular price always restores." |
| `origin/main` `3be6805` | Merged #330. Not published to WordPress.org by that PR. Pair rule in `Free_Support_Contract::support_band()`. Readme describes restricted-agent access and stock only on an opt-in grant. Plugin header version on that commit is 0.2.0. | Describe as repository behavior that is not the directory download. State both pairs and that cross pairs are refused. Do not tell a merchant to install 0.2.2 on WordPress 6.7. |

## Article claim results

| Claim | Result | Note |
| --- | --- | --- |
| Products-list bulk edit writes on Update and the official guide has no undo | PASS | [Bulk Editing Products](https://woocommerce.com/document/bulk-editing-products/), fetched 2026-10-27. |
| Variation bulk actions save immediately, and 0 clears sale prices | PASS | [Variable products](https://woocommerce.com/document/variable-product/), fetched 2026-10-27. |
| A 20% decrease is not undone by a 20% increase | PASS | Arithmetic in the undo article uses exact two-decimal values. The 19.99 example is labeled as arithmetic, not a measured WooCommerce result. |
| Issue #17885 was a percentage bug fixed in WooCommerce 3.3.1 | Not claimed | The thread was re-read on 2026-10-27. It was closed the same day because Decrease existing sale price requires an existing sale. A later comment reports that the same percentage increase did not restore the sale. The undo article cites that comment and does not name WooCommerce 3.3.1. |
| Lookup-table rebuild fixed the November 2025 hidden sale | Not claimed | The WordPress.org poster had already rebuilt lookup tables. The sale appeared after a product Update. Support's no-change bulk Update and WP-CLI suggestion are labeled unconfirmed on that thread. |
| Blank sale-price CSV cells are documented as "leave unchanged" | Not claimed | Articles tell the reader the schema does not say that, and to use Do not import. |
| Blank sale dates mean no date; blank Stock disables stock management | PASS | CSV schema, fetched 2026-10-27. |
| Products-list "Set to regular price decreased by" with blank or 0 removes the sale | PASS | Bulk editing guide. |
| Sale price greater than or equal to regular price is cleared on simple/variation save | PASS with scope | Trunk data store, retrieved 2026-10-27. Variable and grouped parents are excluded in that check. Not re-run as a live import. |
| November 2025 variable sale was stored but not shown until update | PASS | WordPress.org thread, re-read 2026-10-27. Lookup rebuild, cache clear, and variation sync had already failed for that poster. Reddit follow-up marked as search-retrieved; direct fetch 403. |
| `wp wc product lookup regenerate --force` always exists | Not claimed | Articles say confirm with `wp help wc product`. This task did not run WP-CLI. `NOT_TESTED` as an executed command. |
| Shop Manager product editing includes prices; no core price-only capability | PASS | Roles guide, fetched 2026-10-27. |
| WriteLeash 0.2.2 locks staff or AI agents | Not claimed | Articles say it does not. |
| WriteLeash 0.2.2 undoes native bulk edits or CSV imports | Not claimed | Articles say it does not. |
| WriteLeash changes stock in the directory plugin | Not claimed | 0.2.2 listing says stock is not changed. Main-branch grant stock is labeled unpublished. |
| Pair rule applies to the 0.2.2 download | Not claimed | Pair rule is attributed to the default branch after #330. |
| Every WordPress 7.x and WooCommerce combination works | Not claimed | Listing names two exercised WordPress versions. Branch text refuses cross pairs. |
| Search volume, ranking difficulty, or expected traffic | Not claimed | Evidence table uses `UNKNOWN`. |
| Competitor is unsafe or inferior | Not claimed | Other plugins are described from their own listings. |
| JSON-LD is live and valid for rich results | NOT_TESTED | Templates only. `datePublished` omitted until a real URL exists. |
| Procedural steps executed on a store | NOT_TESTED | Steps cite official docs and threads. No WooCommerce install was driven for this content pass. |

## Link check

HTTP 200 on 27 October 2026 for the WooCommerce docs, WordPress.org plugin pages, GitHub issues, and the application-password developer page used in the articles. Reddit thread URLs returned 403 to an unauthenticated client. Their text was retrieved through search the same day and is labeled that way in [keyword-evidence.md](keyword-evidence.md).

## Editorial

| Check | Result |
| --- | --- |
| Affiliation in the first screen of each article | PASS |
| Recovery section usable without installing WriteLeash | PASS |
| One primary CTA to the WordPress.org listing | PASS |
| Internal links between the five articles | PASS, relative Markdown links |
| No plugin telemetry | PASS |
| No readme or runtime plugin edit in this change | PASS |

## Open limits

- The trunk data-store sale-clear behavior was read from source, not executed.
- The no-change bulk Update resync was a 2025 support suggestion. The WordPress.org thread does not confirm it was run. It is not a lab result on WooCommerce 10.x or 11.x.
- Bulk Price Editor 2.5.0 claims were taken from the trunk readme on 2026-10-27. The plugin was not installed. The FAQ on that same readme still says there is no undo feature. Revert is marked premium.
- Grant foundations inside the 0.2.2 zip were not exercised. Articles do not enable them.
