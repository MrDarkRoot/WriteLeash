# #322 listing conversion

Prepared 2026-11-10 from git `57c0de9d41a8c2d915394b7eb186e650f63db8f6` and a fresh WordPress.org API read. This file is the review. It does not publish the directory.

## Live check

`https://api.wordpress.org/plugins/info/1.2/` with `action=plugin_information` and `request[slug]=writeleash`, including `request[fields][short_description]=1`.

| Field | Observed |
| --- | --- |
| Version | 0.3.0 |
| Zip | https://downloads.wordpress.org/plugin/writeleash.0.3.0.zip |
| Requires / Tested / PHP | 6.7 / API `tested` 7.1.4 / 7.4 |
| Active installs | 0 |
| Rating / number of ratings | 0 / 0 |
| `downloaded` | 320 |
| Tags | woocommerce, prices, stock, variations, undo |
| Short description | 119 characters. See below. |

The default API payload omitted `short_description` until the field was requested. SVN trunk `readme.txt` (https://plugins.svn.wordpress.org/writeleash/trunk/readme.txt) matches that 119-character line and Stable tag 0.3.0. Git `readme.txt` on this base did not match trunk. Trunk is what the directory serves. This branch does not change trunk.

Six live screenshots, `?rev=3742817`, are the bulk-edit set: select, preview, progress, conflict, history/undo, safety limits. None shows a denied agent write.

## Before / after

### Title

| | Text |
| --- | --- |
| Before (git and live) | WriteLeash |
| After | WriteLeash |

The title stays the plugin name. It does not add "WordPress" or a competitor.

### Short description

| | Characters | Text |
| --- | --- | --- |
| Before, git `readme.txt` | 121 | Protection against accidental WooCommerce price and stock overwrites, with Preview, conflicts, History and eligible Undo. |
| Before, live directory and SVN trunk | 119 | See WooCommerce price changes before they go live. Keep newer edits, undo eligible prices, and leash restricted agents. |
| Earlier commit on this branch | 128 | Preview WooCommerce bulk price changes before they go live. Keep newer edits, undo eligible prices, and leash restricted agents. |
| After, this branch | 126 | Protect WooCommerce prices and stock from bad overwrites. Keep newer edits, undo eligible prices, and leash restricted agents. |

The 128-character line started with "Preview" and the word "bulk", so the snippet still read as a bulk editor. The 126-character line leads with protecting prices and stock, then leashing restricted agents. It does not start with "Preview" or "bulk".

The plugins-screen header in `writeleash.php` was the 113-character line "Preview WooCommerce bulk price changes, preserve newer edits, resume interrupted jobs, and undo eligible changes." It is now the same 126-character line as the short description. `Version` and `WRITELEASH_VERSION` stay `0.3.0`. Requires at least, Tested up to, and Requires PHP were not edited.

### Tags

| | Tags |
| --- | --- |
| Before | woocommerce, prices, stock, variations, undo |
| After | woocommerce, prices, stock, variations, undo |

Five tags. No competitor names. `stock` stays because a grant can change stock. The body says ordinary price jobs do not.

### Opening

**Git before.** The first lines sold a protection category: "WriteLeash offers protection against accidental WooCommerce price and stock overwrites on paths you control." Limits (not a firewall, stock only on a grant, Undo does not rewind outside effects) arrived before the merchant could see Preview, a kept newer price, or eligible Undo as the outcome.

**Live trunk before.** The directory already leads with an outcome: "One bulk edit can turn a $199 product into $19. WriteLeash shows you the new prices first. The catalog stays put until you approve." Headings then walk Preview, the newer edit, eligible Undo, and a grant. That page is not this git tree.

**After.** The expensive mistake comes first, then the outcome. The leash is the first heading, above the preview:

> A wrong percentage, a CSV overwrite, or an assistant account editing a product is the expensive mistake. With WriteLeash, the catalog does not change until a person approves.

First two headings: Put a restricted account on a leash. See the price before it goes live. Then: Keep the newer price. Undo an eligible price. Changes you can make. Requirements, supported scope, and the unchanged seatbelt paragraph stay below that pitch. The leash block still says grants are opt-in and off until issued, ordinary price jobs do not change stock, stock changes only on that grant, Stock Undo is not supported, and this is not protection for every WooCommerce write.

## Claim table

| Claim | Verdict |
| --- | --- |
| Protect prices and stock from bad overwrites | Allowed in the short description only with the body limits: ordinary price jobs do not change stock, stock changes only on an opt-in grant, Stock Undo is not supported, and this is not every WooCommerce write |
| The catalog does not change until a person approves | Allowed for a WriteLeash price change a person runs. Not a claim that a CSV tool, another plugin, or an already-issued grant waits for a second approval |
| Preview, then a separate Apply | Allowed |
| Walking away from Preview changes nothing | Allowed |
| The Preview CSV is the saved Preview, not live data | Allowed |
| If the stored price WriteLeash is changing, or another checked setting, no longer matches the preview, the newer value stays and that product is a conflict | Allowed. Exact sentence kept |
| A later price or setting change becomes a conflict instead of a blind overwrite | Allowed. Exact sentence kept |
| Product name, SKU, and category changes alone conflict | Not claimed. They are reference details only |
| Any later product edit conflicts | Not claimed |
| Eligible price Undo when the saved details and the current product state permit it | Allowed |
| Undo availability guarantees restoration | Not claimed |
| Stock Undo | Not claimed. Stated as not supported |
| Undo reverses orders, emails, webhooks, or other plugins | Not claimed |
| Ordinary price jobs change stock | Not claimed. Stock changes only on the opt-in grant |
| Grants are off until an operator issues one | Allowed |
| A restricted WordPress user is denied on documented monitored WooCommerce write paths outside a matching Execution Frame | Allowed |
| Pause, revoke, and remove-restriction fall open | Not claimed |
| The restricted account can remove its own restriction | Not claimed |
| Administrators who are not on the grant are inside it | Not claimed |
| Cron counts when it is not running as that restricted user | Not claimed |
| System cron user 0 is inside the grant | Not claimed |
| Firewall, sandbox, agent-safe, or universal protection | Not claimed. The seatbelt sentence says it is not those things |
| Stops direct SQL, hostile plugins, or every AI agent | Not claimed. Those paths are named as not covered |
| Universal fail-closed guarantee when the grant store is unavailable | Not claimed |
| Zero database writes in all cases | Not claimed |
| Primary line: WooCommerce 10.0 through 11.x on WordPress 7.0 up to 8.0 | Allowed. Not widened |
| Exercised legacy pairs: WordPress 6.7.2 with WooCommerce 9.0.4, and WordPress 6.8.3 with WooCommerce 9.9.7 | Allowed as exercised pairs, not as a 9.0-through-11.x rectangle |
| Other pairs | Refused before a job is created |
| Up to 1,000 selected products per new job in the tested configuration | Allowed |
| Multisite | Not supported |
| Custom product types and universal extension compatibility | Not supported |
| Managed or shared hosting | Not tested |
| Planned stored prices are the price a shopper is guaranteed to see | Not claimed |
| A denial screenshot is live | Not claimed |

## How merchants describe the miss

These are public pages a merchant would actually write or read. They are not search-volume evidence. No volume, rank, or conversion number is inferred from them.

1. [How do you safely update WooCommerce prices when suppliers send new files?](https://www.reddit.com/r/woocommerce/comments/1q6d2s5/how_do_you_safely_update_woocommerce_prices_when/) Merchants treat a supplier file as dangerous enough to back up first. The practical advice is to reimport only the price columns, and to use a percentage change in the admin when the vendor's update is a rate rather than a row-by-row sheet.
2. [How to Bulk Update WooCommerce Prices: Dashboard, CSV, API and CLI](https://macmyths.com/how-to-bulk-update-prices-in-woocommerce-safely/) The failure list is a mapped regular price landing in the sale column, the wrong products changing, a blank or `0` clearing a sale, and a second import wiping a later edit that was not in the file.
3. [Review and undo WooCommerce AI changes](https://woocommerce.com/document/woocommerce-ai/review-and-undo/) WooCommerce's own assistant docs tell the merchant to read the preview before Apply. Notifications, stock, and publication may not come back. A product edited elsewhere conflicts, and the default is to skip that row. Forcing Undo can replace the newer value.
4. [How to use AI abilities in Wholesale Suite](https://wholesalesuiteplugin.com/kb/ai-abilities/) Write access is a switch. Off, the assistant does not change prices. On, it can change live prices, discounts, and quotes. The merchant is told the assistant's own confirmation behavior is not a substitute for that switch.
5. [WooCommerce product import from CSV not working properly](https://wordpress.org/support/topic/woocommerce-product-import-from-csv-not-working-properly-2/) A store meant to update prices and instead created products, because the file did not match on ID or SKU. A non-numeric cell stopped the import. The fix was the file, not a promise that the catalog had been protected.

WriteLeash does not claim to intercept those CSV tools or every assistant. The opening names the same expensive mistakes, then says a WriteLeash change waits until a person approves. The leash section is only the restricted WordPress user an operator chooses, and it stays off until that grant exists.

## SEO notes

No search volume, keyword difficulty, ranking, or conversion rate is claimed. Active installs are 0 and ratings are 0. The API `downloaded` counter was 320 on 2026-11-10. That is a directory counter, not demand.

The short description is the search snippet. It leads with "Protect WooCommerce prices and stock" and "leash restricted agents". It does not start with "Preview" or "bulk", and it is not stuffed into the title. No query volume is claimed for those words.

Tags stay the five real behaviors. Adding `bulk edit` would mean dropping stock, variations, or undo. Competitor plugin names are not tags and are not in `readme.txt`.

Headings are the scan path a merchant uses: the restricted account first, then the price before it goes live, the newer price, eligible Undo, then requirements. The 10,240-byte readme guidance is the ceiling. This `readme.txt` is 10,041 bytes.

## Screenshots

Captions in `readme.txt` were not rewritten. `.github/ci/asset-audit.php` requires those six lines to match `wordpress/release/assets-122/proof.json`. Changing a caption without a new proof fails that gate. The lines below are the recommended rewrites for a later founder asset update. They are not live.

| # | Current caption, still in git and on the directory | Rewrite when the proof and SVN assets change | Why |
| --- | --- | --- | --- |
| 1 | Select products by name, SKU, category or ID – with nested subcategory option. | Choose products by name, SKU, category, or ID. Nested subcategories are included only if that option is on. This screen does not change prices. | The image is the selector, including "Include subcategories". It is not a price result. |
| 2 | Exact before-and-after Preview of Regular & Sale prices (including variations). | Read the sale price before and after, including variations. Preview has not applied it. | The capture is a Set Sale preview (for example $22.00 to $19.00). It does not show every price operation. |
| 3 | Live background job progress with Resume. | Leave and Resume the products still waiting. Finished changes are not repeated. | The image is "Waiting to continue" and "Resume remaining products", not a finished 1,000-product run. |
| 4 | Conflict protection: newer price is kept instead of overwritten. | A later price or setting change becomes a conflict instead of a blind overwrite. | The image is expected $100, now $120, planned $80, "Newer price kept". "Conflict protection" sounds wider than that check. The replacement is the claim-matrix caption. |
| 5 | History + eligible Undo within 30 days. | History shows that eligible price Undo is available for this job. It does not show a finished Undo. Stock Undo is not supported. | The image says Undo is available and shows a date. It is not a completed restore. |
| 6 | Safety limits: max products, max % increase/decrease, block zero prices. | Set the product cap, the maximum percent up or down, and block a zero price before you approve. | The image is those three limits. It is not a denied-agent screen and not a hosting test. |

A screenshot of a denied agent write does not exist in the six assets. Adding one needs founder SVN approval for a new `assets/` file on WordPress.org, and a matching proof update so asset audit still passes. This branch does not add an image and does not say one is on the directory.

## Validators

Both passed on this branch:

```
php wordpress/tests/release/readme-validate.php wordpress/writeleash
#63/#121 readme preflight: title, 126-char description, 5 tags, Stable tag 0.3.0, directory/header requirements coherent, GPLv2-or-later PASS

php wordpress/tests/release/claim-matrix-audit.php .
#121 claim matrix audit: 16 required topics, forbidden-claim guard, readme/matrix wording and packaging boundary PASS
```

The scripts require those path arguments. Exit code was 0 for both. Screenshot captions still match the asset audit. Validators were not edited.

## Publishing gate

`readme.txt` in git does not change the live listing. The founder must commit `readme.txt` to WordPress.org SVN trunk with the Stable tag left at 0.3.0, or ship a later release. This pull request does not perform that commit. It does not retag 0.3.0.
