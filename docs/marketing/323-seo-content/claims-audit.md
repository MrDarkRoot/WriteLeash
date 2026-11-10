# Claims audit for the #323 articles

Supersedes the 27 October 2026 pass for product version and for WooCommerce doc wording that changed. The October rows remain in [keyword-evidence.md](keyword-evidence.md) where a page was not re-opened.

Auditor: content pass on 10 November 2026. No browser was available. Pages were fetched as text. No store was driven. `claim-matrix-audit.php` does not read this directory and was not modified.

## Release the articles may describe

| Surface | What was checked | What the articles may say |
| --- | --- | --- |
| WordPress.org WriteLeash 0.3.0 | Listing fetched 2026-11-10. Version 0.3.0. Last modified 2026-10-28. Active installations fewer than 10. Tested up to displayed as 7.1.4. | Preview, separate approval, conflict when the stored price being changed or another checked setting no longer matches, History of its own jobs, eligible price Undo within 30 days on the listing screenshot. Ordinary price jobs do not change stock. Stock Undo is not supported. Opt-in grants stay off until issued. Upgrade from 0.2.2 creates no grant. |
| Requirements | Listing and `wordpress/writeleash/readme.txt` on this branch. | Modern pair: WooCommerce 10.0.0 or newer, below 12.0.0, on WordPress 7.0 up to but not including 8.0. Legacy pair: WordPress 6.7 below 7.0 with WooCommerce 9.0.0 below 10.0.0. Exercised: 7.0.1 and 7.1.2 on the modern line, 6.7.2 with 9.0.4, 6.8.3 with 9.9.7. Other pairs refused before a job is created. Do not treat "Tested up to 7.1.4" as an exercised pair. |
| Grant boundary | Listing seatbelt paragraph and `FREE-GRANT-CONTRACT.md`. | Restricted WordPress users are denied on documented monitored WooCommerce write paths outside a matching Execution Frame. Not a firewall, not a sandbox, not a universal agent-safe guarantee. Direct SQL, hostile plugins, and unmonitored paths are not covered. No guarantee when the grant store is unavailable. No stock Undo. No grant setup walkthrough. |
| Scope | Listing "What is supported" and limitations. | Published core simple products and published variations of published core variable products, base currency, up to 1,000 selected products per new job in the tested configuration. Parent prices are not edited directly. Multisite unsupported. Real managed or shared hosting not tested. Planned stored prices are not guaranteed shopper prices. |

## Doc changes since 27 October 2026

| Earlier claim | 10 November 2026 result |
| --- | --- |
| Products-list bulk action is labeled Edit, and the guide says it cannot edit variable-product complexity | The [bulk editing guide](https://woocommerce.com/document/bulk-editing-products/) now says **Bulk edit**, then Apply, then Update. Bulk price changes apply to simple and external products, not to individual variations. A field on **No change** keeps its value. A price change clears scheduled sale dates. The blank-or-0 sale-removal sentence is gone. Not re-executed in a store. |
| Variation bulk actions save immediately and 0 clears sale prices | Still on the [variable product page](https://woocommerce.com/document/variable-product/) section opened 2026-11-10. |
| CSV blank sale price is not documented as "leave unchanged" | Still true on the [CSV guide](https://woocommerce.com/document/product-csv-importer-exporter/) opened 2026-11-10. Blank sale dates mean no date. Blank Stock disables stock management. **Do not import** remains the skip control. |
| Shop Manager has no separate price capability | Still true on the [roles guide](https://woocommerce.com/document/roles-capabilities/) opened 2026-11-10. |
| November 2025 variable sale was stored but hidden until Update | Thread re-opened 2026-11-10. Still unresolved in the replies. Support's no-change Update and WP-CLI line were not confirmed by the poster. |
| WriteLeash 0.2.2 is the installable plugin and has no staff lock | Removed. 0.3.0 is the listing. Grants are shipped and opt-in, not a lock on every user. |
| Pair rule is unpublished repository behavior | Removed. The pair rule is the 0.3.0 requirements text. |

## Article claim results

| Claim | Result | Note |
| --- | --- | --- |
| A 20% decrease is not undone by a 20% increase | PASS | Arithmetic, labeled as such. The 19.99 figure is not a measured WooCommerce result. |
| WriteLeash undoes a native bulk edit or CSV import | Not claimed | Articles say it does not. |
| Stock Undo | Not claimed as available | Every article says it is not supported. |
| Firewall, sandbox, agent-safe, universal protection, direct SQL covered, hostile plugins covered | Not claimed | Negations only. |
| Guarantee when the grant store is down | Not claimed | Articles say there is no such guarantee. |
| Undo reverses orders, emails, webhooks, or other plugins | Not claimed | Articles say it does not. |
| Search volume or rank | Not claimed | Volume is not measured. No rank tracker. |
| Invented customers or testimonials | Not claimed | The active-installation bucket is the directory's own figure. |
| JSON-LD rich results | NOT_TESTED | Article and FAQPage JSON-LD is emitted. It was not submitted to Google's rich-results test. FAQ answers match the visible FAQ. |
| Procedural steps executed on a store | NOT_TESTED | Steps cite docs and threads. No WooCommerce install was driven. |
| `wp wc product lookup regenerate --force` | Not claimed as verified | The support thread names it. The article says to confirm with `wp help wc product`. |

## Link check on 10 November 2026

Fetched as text, HTTP content returned, no browser:

- https://wordpress.org/plugins/writeleash/
- https://woocommerce.com/document/bulk-editing-products/
- https://woocommerce.com/document/product-csv-importer-exporter/
- https://woocommerce.com/document/variable-product/ (bulk-action section)
- https://woocommerce.com/document/roles-capabilities/
- https://woocommerce.com/document/woocommerce-rest-api/
- https://developer.wordpress.org/advanced-administration/security/application-passwords/
- https://wordpress.org/plugins/bulk-price-editor-for-woocommerce/ and the trunk readme
- https://wordpress.org/support/topic/variable-product-sale-prices-not-showing-after-csv-import/

Reddit URLs were not opened. The 27 October search retrieval is still labeled that way. GitHub issues #17885 and #37681 and the February 2026 decimal-places thread were not re-opened. Product Price History, MiraiLit, WPNest, and Inventory History were not re-opened.

## Editorial

| Check | Result |
| --- | --- |
| Fix is the first paragraph, before the product pitch | PASS |
| Affiliation in the first screen, without a second install pitch | PASS |
| Recovery usable without installing WriteLeash | PASS |
| One visible CTA, to the directory listing and the GitHub repository | PASS, enforced by `build_site.py` |
| Internal links among the five articles | PASS |
| No plugin telemetry and no site analytics | PASS |
| No readme, plugin PHP, demo, or launch-campaign edit | PASS |
