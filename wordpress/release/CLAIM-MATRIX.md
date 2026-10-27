# #121 release claim matrix — WriteLeash Free 1.0 WordPress.org listing

Repository-only review artifact. **Not packaged** in the WordPress.org ZIP or
SVN runtime (WordPress.org does not require an internal claim matrix). Every
material public claim in `wordpress/writeleash/readme.txt`, the plugin header
and the #122 screenshot captions must appear below with accepted evidence.

Evidence baseline: post-#120 `origin/main` = `22a89ec0d12fb4860ec9ffb1347332af02e95788`
(merge of PR #132, closing #120; accepted public head `83b3b58588045f998ec990c5ee43017a7b43d344`).
Official WordPress.org guidance rechecked 2026-10-02; see the last section.

Phase B base after #133: `4bc45a9345a814e75a65f6d6293756f447603e91`.
Pre-Phase-B head: `56e90cc9c12057c81d1f9312aa51bec11039415f`.
Rebasing result: `14eaa1f580848964134b4f6b0f415ee791a20c36`.

Status values:

* `PUBLISHABLE` — public wording is supported as written.
* `PUBLISHABLE WITH LIMITATION` — supported only with the required limitation present.
* `INTERNAL ONLY` — behavior may exist but must not be advertised as support.
* `DO NOT PUBLISH` — no accepted evidence.

---

## 1. New-job product ceiling: 1,000

* **COPY CLAIM:** "Up to 1,000 selected products per new job in the tested configuration."
* **SUPPORTING ISSUE:** #112 shipping boundary, raised to the #107 engineering selector maximum by #177.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/support-boundaries.php` — 1,001 selected products refused before journal; 1,000 accepted; explicit/category/approval/HTML/policy enforcement.
  * `wordpress/tests/acceptance/journey.php` — complete 1,000-product workflow on the advertised rows; 10,000 refused before journal.
  * `wordpress/tests/admin/selection-integration.php` — 1,000 picker products accepted, 1,001 refused before import.
* **ALLOWED WORDING:** "Up to 1,000 selected products per new job in the tested configuration."
* **REQUIRED LIMITATION:** Must be tied to "new job" and the tested configuration; must not say 1,000 is guaranteed for arbitrary shops, metadata sizes or hook loads.
* **STATUS:** PUBLISHABLE.

## 2. Engineering selector maximum 1,000

* **COPY CLAIM:** "Up to 1,000 selected products per new job in the tested configuration." (the Free new-job ceiling now equals the #107 selector maximum; no larger size is advertised.)
* **SUPPORTING ISSUE:** #107 internal engineering selector; #112 decision; #177 raise.
* **SUPPORTING TEST/EVIDENCE:** `Product_Price_Selector::MAX_SELECTED = 1000`; `Free_Support_Contract::MAX_JOB_PRODUCTS = 1000`; selector overflow above 1,000 still maps to the support refusal.
* **ALLOWED WORDING:** The tested-configuration ceiling above. No larger supported size may be advertised.
* **REQUIRED LIMITATION:** Never present a size above 1,000 as supported, and never present either constant as a throughput or host promise.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 3. Five price operations

* **COPY CLAIM:** "choose Set, + fixed, - fixed, + percent or - percent".
* **SUPPORTING ISSUE:** #107 (accepted); #111 Admin workflow; #178 sale-price targeting.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/writeleash/FREE-PRICE-CONTRACT.md` — operation contract and absolute target computation.
  * `wordpress/tests/acceptance/EVIDENCE.md` — completed rows applied exact frozen targets; percentage targets stayed absolute, never recomputed.
  * Unit suite `wordpress/tests/admin/`, the pure-PHP `wordpress/tests/free/sale-price.php` harness and the #107 suite on the #178 final head.
* **ALLOWED WORDING:** "Set, + fixed, - fixed, + percent or - percent" for the stored regular or sale price.
* **REQUIRED LIMITATION:** Applies only to the stored regular or sale price of eligible core simple products and eligible variations; the variable parent is never targeted directly.
* **STATUS:** PUBLISHABLE.

## 4. Supported product scope

* **COPY CLAIM:** "Published core simple WooCommerce products", "Published core variable WooCommerce products: selecting the parent targets all of its variations, or select individual variations", "Stored regular and sale prices in the base store currency", "Regular-price edits on products with an existing sale price or schedule".
* **SUPPORTING ISSUE:** #107 (contract), #108 (mutation proof), #111 (Admin), #112 (acceptance rows), #178 (sale field), #179 (variations).
* **SUPPORTING TEST/EVIDENCE:**
  * `FREE-PRICE-CONTRACT.md` — eligibility rules for published simple products and variations of published core variable products, the stored regular or sale price, base currency and numeric target refusals (`regular_price_not_above_sale`, `sale_price_not_below_regular`, `empty_sale_price`); #207 explicitly adds Clear Sale and relative sale discount (see the #244 addendum).
  * `wordpress/writeleash/FREE-PRICE-APPLY-CONTRACT.md` — stock `WC_Product_Data_Store_CPT` / `WC_Product_Variation_Data_Store_CPT` and Woo CRUD path.
  * `wordpress/tests/acceptance/EVIDENCE.md` — price/meta/lookup/cache/journal parity on every completed row.
* **ALLOWED WORDING:** "Not changed: sale dates, stock and orders. Variable parent price ranges are refreshed with WooCommerce's own sync after every variation change."
* **REQUIRED LIMITATION:** Sale-date editing, stock, orders and non-simple/non-variation product types are unsupported; variation selections save their exact variation IDs in the preview; a numeric regular or sale target that WooCommerce would use to clear the sale is refused; explicit Clear Sale is supported. SKU, product name and category are reference details only and do not by themselves cause a conflict.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 5. WooCommerce version

* **COPY CLAIM:** "WooCommerce 10.0 through 11.x on WordPress 7.0 up to 8.0 only. Legacy band: WooCommerce 9.0.0 below 10.0.0 on WordPress 6.7 below 7.0. Exercised legacy points: WordPress 6.7.2 with WooCommerce 9.0.4, and WordPress 6.8.3 with WooCommerce 9.9.7."
* **SUPPORTING ISSUE:** #108/#111 execution gate; #112 compatibility row; #119 support boundary; #180 supported range; #280 pair rule.
* **SUPPORTING TEST/EVIDENCE:**
  * `FREE-PRICE-APPLY-CONTRACT.md` — Woo 10.0 through 11.x storage/API basis (CPT data store, lookup shape, CRUD price setters, cache helpers; ProductCache guarded for pre-10.5); official 11.1.2 ZIP SHA-256 recorded as the exercised point.
  * `wordpress/tests/acceptance/EVIDENCE.md` — modern rows remain the prior evidence. #280 (2026-10-27) adds the legacy pairs and the refusals; it does not re-execute the modern cells.
  * `wordpress/writeleash/includes/free/class-free-support-contract.php` — modern `WOOCOMMERCE_MIN = '10.0.0'`, `WOOCOMMERCE_MAX_EXCLUSIVE = '12.0.0'`; legacy `LEGACY_WOOCOMMERCE_MIN = '9.0.0'`, `LEGACY_WOOCOMMERCE_MAX_EXCLUSIVE = '10.0.0'`. A frozen plan must stay on the same band.
  * Canonical immutable artifact: https://downloads.wordpress.org/plugin/woocommerce.11.1.2.zip → `Version: 11.1.2`, `Requires at least: 7.0`; SHA-256 `9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e`.
  * Legacy floor artifact: https://downloads.wordpress.org/plugin/woocommerce.9.0.4.zip SHA-256 `230ba96dbd288c98ce76e8ce430a7d52ec0994596717bde978b5613839ff8333`. Legacy high pair reconfirmed: https://downloads.wordpress.org/plugin/woocommerce.9.9.7.zip SHA-256 `96facedd12e32b6e0b120dcaa4a62b9c6b1ae12159d32510ea416c8e9b44f4dd`.
* **ALLOWED WORDING:** "WooCommerce 10.0 through 11.x" for the modern line, plus the two named legacy pairs and the pair rule. Do not say WooCommerce 9.0 through 11.x.
* **REQUIRED LIMITATION:** Previous public floor was WooCommerce 10.0.0 with no legacy band (through 2026-10-27). No WooCommerce below 9.0.0 on the legacy band, no WordPress below 6.7, no WooCommerce 9 on WordPress 7, no WooCommerce 10 or newer on WordPress 6.7 or 6.8, and nothing at or above 12.0.0. Out-of-range pairs are refused before job creation. The two bands are not a rectangle. Untested versions inside a certified band are not a coverage map and are not called refused.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 6. WordPress versions and minimum metadata

* **COPY CLAIM:** "WordPress 7.0.1 and 7.1.2 were exercised on WooCommerce 10.0 through 11.x. Legacy band: WordPress 6.7 below 7.0 with WooCommerce 9.0.0 below 10.0.0. Exercised: WordPress 6.7.2 with WooCommerce 9.0.4, and WordPress 6.8.3 with WooCommerce 9.9.7. Requires WordPress 6.7 only on that legacy WooCommerce band; WordPress 7.0 up to 8.0 is required with WooCommerce 10.0 through 11.x."
* **SUPPORTING ISSUE:** #112 compatibility rows; #121 metadata decision; #280 pair rule.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/EVIDENCE.md` — exact rows for WP `7.1.2` and `7.0.1`. Before #280, `6.8.3` was recorded as `UNSUPPORTED: Woo package requires WP 7.0` because the modern Woo package refuses WordPress below 7.0. That refusal still holds for WooCommerce 10 and 11. #280 exercises 6.7.2 and 6.8.3 only with WooCommerce 9.x.
  * Modern Woo package header (`Requires at least: 7.0`) and `https://api.wordpress.org/plugins/info/1.0/woocommerce.json` (`requires: 7.0`), checked 2026-10-02. WooCommerce 9.9.7 header `Requires at least: 6.7`; WooCommerce 9.0.4 header `Requires at least: 6.4`.
  * `readme-validate.php` requires the exact fixtures `7.0.1` and `7.1.2`, the directory floor `Requires at least: 6.7`, and the modern line "WooCommerce 10.0 through 11.x".
  * `wordpress/tests/dependency-63.php` and foundation `in-container.sh`: WordPress 6.8.3 with WooCommerce 9.9.7 is a legacy-band dependency activation, not a core minimum refusal. The one below-floor WordPress refusal is 6.6.x (`plugin_wp_incompatible`, minimum 6.7). current-core `in-container.sh` keeps the public WordPress 7.1.2 plus WooCommerce 11.1.2 dependency proof. See `wordpress/release/PHASE-B-121.md` for the separate repository-only historical mechanism.
  * Image digests: `wordpress:6.7.2-php8.2-apache@sha256:85c7d25eaf9726eeff21adad930aa43d2894bd73e9253ab250737b6d994a1181`, `wordpress:6.8.3-php8.2-apache@sha256:1e6215749283955d5c9ffea6c297651ed23cdfdbb91677ad7abd705b2682f2cf`, `wordpress:6.6.2-php8.2-apache@sha256:c30c1376b4d2c9d1e2328c2ee6149d133104d65873a98cbc6adc743a2964c4ba`.
* **ALLOWED WORDING:** The tested fixtures and the pair rule. "Requires WordPress 6.7" is directory metadata for the legacy band only, not a promise that every WordPress 6.7+ site is supported.
* **REQUIRED LIMITATION:** Previous directory floor was WordPress 7.0 (through 2026-10-27). Do not claim all WordPress 6.7, 6.8, or 7.x releases from the tested points. Untested versions inside a certified band are not a coverage map and are not called refused. No WordPress below 6.7. No WooCommerce 9 on WordPress 7. No WooCommerce 10 or newer on WordPress 6.7 or 6.8. Out-of-range pairs are refused before job creation.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 7. PHP versions and minimum metadata

* **COPY CLAIM:** "PHP 7.4.33, 8.0.30, 8.1.34 and 8.2.34 were exercised. Requires PHP 7.4 or newer."
* **SUPPORTING ISSUE:** #108/#109/#110 fixtures; #112 compatibility rows; #119.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/EVIDENCE.md` — rows for PHP 7.4.33, 8.0.30, 8.1.34, 8.2.34 at 100 products.
  * `wordpress/writeleash/writeleash.php` — runtime guard fails closed below PHP 7.4.
  * Exact Woo 11.1.2 package `Requires PHP: 7.4`.
* **ALLOWED WORDING:** "Requires PHP 7.4 or newer" with the four exact exercised patch versions.
* **REQUIRED LIMITATION:** No claim that the full 1,000-product Apply/Undo journey passed on PHP 7.4/8.0/8.1 (the full journey runs on 8.2; the boundary preview/approval suite runs on all four), and no unlisted PHP version claim.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 8. Database engines

* **COPY CLAIM:** "MySQL 8.0.44 and MariaDB 10.11.15 were exercised."
* **SUPPORTING ISSUE:** #108/#109/#110 two-engine suites; #112 two-engine acceptance.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/EVIDENCE.md` — every compatibility row ran both engines.
  * `wordpress/tests/durable/LAB-ACTIVITY.md`, `wordpress/tests/jobs/LAB-ACTIVITY.md` — two-engine pass records.
* **ALLOWED WORDING:** "MySQL 8.0.44 and MariaDB 10.11.15 were exercised with the normal WordPress database connection."
* **REQUIRED LIMITATION:** Other engines/versions and arbitrary host SQL modes are not certified.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 9. Persistent object cache

* **COPY CLAIM:** "MySQL 8.0.44 and MariaDB 10.11.15 were exercised. Redis Object Cache 2.7.0 (Redis 7.4.2) on the modern line and on 6.7.2 with 9.0.4 at 100 products, not on 6.8.3 with 9.9.7."
* **SUPPORTING ISSUE:** #108 cache verifier; #109/#110 cache suites; #112 cache/hook torture; #280 pair rule.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/EVIDENCE.md` — Redis 7.4.2, Redis Object Cache/drop-in 2.7.0 on the modern line; rollback-before-commit, retry, SIGKILL recovery and cache-repair parity passed there. #280 adds Redis on WordPress 6.7.2 + WooCommerce 9.0.4 at size 100, both engines. WordPress 6.8.3 + WooCommerce 9.9.7 persistent cache is NOT_TESTED.
  * `FREE-PRICE-APPLY-CONTRACT.md` — Woo `ProductCache::remove()` plus targeted lookup/meta repair.
* **ALLOWED WORDING:** Name the cache fixture only for the modern line and for WordPress 6.7.2 with WooCommerce 9.0.4 at 100 products.
* **REQUIRED LIMITATION:** Exact fixture only; not a general "works with every caching plugin" promise. Do not imply persistent cache was exercised on WordPress 6.8.3 with WooCommerce 9.9.7.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 10. Preview and approval binding

* **COPY CLAIM:** "build a preview and approve the exact preview"; "Approving applies exactly the products and target prices shown in the preview."
* **SUPPORTING ISSUE:** #107 immutable Change Plan; #109 durable import; #111 Admin.
* **SUPPORTING TEST/EVIDENCE:**
  * `FREE-PRICE-CONTRACT.md` — immutable plan, `Plan_Hasher`, approval hydration/fingerprint checks.
  * `wordpress/tests/acceptance/EVIDENCE.md` — percentage targets stayed absolute on apply and Undo; immutable bindings unchanged.
  * `SUPPORT-REPAIR.md` — approval hydrates/verifies saved material; cannot be reused for different inputs.
* **ALLOWED WORDING:** "Changing the selection or the price settings requires a new preview."
* **REQUIRED LIMITATION:** Planned prices are target store values, not guaranteed shopper prices.
* **STATUS:** PUBLISHABLE.

## 11. Conflict behavior

* **COPY CLAIM:** "If the stored price WriteLeash is changing or another checked setting no longer matches the preview, WriteLeash leaves the newer value alone and marks that product as a conflict."
* **SUPPORTING ISSUE:** #107 optimistic concurrency contract; #109/#110 conflict state; #111 item UI; #112 fresh-state tests.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/EVIDENCE.md` — "regular-price property changed to 88" → rollback to 100, journal/job `NEEDS_REVIEW`, no false `APPLIED`; external edit before Apply recorded.
  * `wordpress/tests/acceptance/SUPPORT-REPAIR.md` — one later Woo edit to 75 conflicts while other items restore.
  * `FREE-PRICE-CONTRACT.md` and `class-product-snapshot.php` — execution fingerprint covers the stored value of the changed price field (regular or sale), product existence, core-simple/type state, publication status, currency/base context, price decimals and WordPress/WooCommerce versions. A regular-field plan preserves external sale changes; a sale-field plan requires the planned sale to stay strictly below the current regular price. SKU, product name and category are reference details only and do not by themselves cause a conflict.
* **ALLOWED WORDING:** The exact copy claim above. Screenshot caption: "A later price or setting change becomes a conflict instead of a blind overwrite."
* **REQUIRED LIMITATION:** SKU/product-name/category-only changes are not automatic conflicts. Never claim WriteLeash prevents all bad edits or blocks every concurrent Woo/plugin write; only the approved items are rechecked before they are changed.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 12. Saved progress, partial outcomes and Resume

* **COPY CLAIM:** "Progress is saved, so a closed browser or an interrupted background step does not lose the change"; "the Resume action continues the existing job in small steps"; "Results report per-outcome counts instead of claiming global success."
* **SUPPORTING ISSUE:** #109 durable job engine; #110 Undo lifecycle; #111 Resume/REST UI; #112 scheduler/kill tests.
* **SUPPORTING TEST/EVIDENCE:**
  * `FREE-JOB-ENGINE-CONTRACT.md` — durable repository is truth; Action Scheduler is wake-up only; one bounded chunk per worker run.
  * `wordpress/tests/acceptance/EVIDENCE.md` — real SIGKILL with 60-second lease expiry recovered by protected HTTP Resume; duplicate/stale wakes changed no counts; scheduler refusal preserves `PAUSED`.
  * `wordpress/tests/jobs/LAB-ACTIVITY.md` — crash/lease/generation evidence.
* **ALLOWED WORDING:** Saved progress, truthful remaining work and Resume.
* **REQUIRED LIMITATION:** Impaired scheduling may require the manual Resume action; no promise of unattended completion on every host.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 13. Undo eligibility

* **COPY CLAIM:** "Undo restores eligible stored regular- or sale-price values that WriteLeash previously applied when the saved details and the current product state permit it."
* **SUPPORTING ISSUE:** #110 conflict-aware Undo and history; #178 field-scoped saved record.
* **SUPPORTING TEST/EVIDENCE:**
  * `FREE-UNDO-HISTORY-CONTRACT.md` — eligible stored-price restoration with proven Apply evidence; `UNDO_CONFLICT` and `UNDO_NEEDS_REVIEW` never overwrite.
  * `wordpress/tests/acceptance/EVIDENCE.md` — eligible stored price restored; external edit before Undo recorded; partial Undo continues after reopen.
  * `wordpress/tests/acceptance/SUPPORT-REPAIR.md` — one later edit conflicts, other items restore; first Undo POST remains nonterminal until the operation finishes.
  * `wordpress/tests/free/sale-price.php` and `wordpress/tests/undo/integration.php` — a regular-field Undo is not blocked by a later sale change and preserves it.
* **ALLOWED WORDING:** The exact sentence above; the UI action "Restore eligible prices (Undo)".
* **REQUIRED LIMITATION:** Not universal rollback; a stored changed-field or checked-setting mismatch is not overwritten. Do not describe arbitrary product edits as automatic conflicts.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 14. External side effects are outside Undo

* **COPY CLAIM:** "Undo does not reverse orders, completed sales, emails, webhooks, remote HTTP requests, external queues or other plugin side effects."
* **SUPPORTING ISSUE:** #108/#109/#110 contracts; #112 explicit outside-contract boundary.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/EVIDENCE.md` — "OUTSIDE CONTRACT: emails, webhooks, remote HTTP, orders/completed sales, external queues and arbitrary plugin side effects."
  * Synthetic hook table — intercepted HTTP/mail attempts recorded outside the transaction; delivery/external reversal not claimed.
  * `class-free-admin.php` — same limitation shown in the Admin UI.
* **ALLOWED WORDING:** The exact sentence above.
* **REQUIRED LIMITATION:** Never imply external effects are reversible or prevented.
* **STATUS:** PUBLISHABLE.

## 15. Ordinary database privileges / no SSH or DBA requirement

* **COPY CLAIM:** "WriteLeash uses the normal WordPress database connection and normal WooCommerce product APIs. No SSH access, custom database account or manual SQL setup is required."
* **SUPPORTING ISSUE:** #108 ordinary-connection assertion; #109/#110 schemas; #112 permission-denial test.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/EVIDENCE.md` — schema-local SELECT/INSERT/UPDATE/DELETE/CREATE/ALTER/DROP/INDEX only; "The plugin does not require SSH, root DB, CREATE USER, TRIGGER, ROUTINE, GRANT, manual SQL or custom credentials."
  * `wordpress/tests/acceptance/README.md` — test DB identity carries no root privileges; installation requires neither root nor SSH.
  * `wordpress/release/PUBLIC-PAYLOAD.md` — Free first-use dbDelta uses the normal WP account; restricted legacy grants are repository-only.
* **ALLOWED WORDING:** The exact sentence above.
* **REQUIRED LIMITATION:** Normal plugin-owned table creation through the existing WordPress identity is required; not a claim about every locked-down host.
* **STATUS:** PUBLISHABLE.

## 16. Multisite

* **COPY CLAIM:** "Multisite is unsupported."
* **SUPPORTING ISSUE:** #112 host/status boundary; #120 payload boundary.
* **SUPPORTING TEST/EVIDENCE:** `wordpress/tests/acceptance/EVIDENCE.md` and `README.md` — "Multisite: UNSUPPORTED".
* **ALLOWED WORDING:** "Multisite is unsupported."
* **REQUIRED LIMITATION:** Must be present; no network-activation or per-site claim.
* **STATUS:** PUBLISHABLE.

## 17. Real managed/shared hosting

* **COPY CLAIM:** "Real managed or shared hosting has not been tested."
* **SUPPORTING ISSUE:** #112 host boundary; #120 payload boundary.
* **SUPPORTING TEST/EVIDENCE:** `wordpress/tests/acceptance/EVIDENCE.md` — "Real managed/shared-host pilot: NOT TESTED. Container evidence is not shared-host certification."
* **ALLOWED WORDING:** The exact sentence above.
* **REQUIRED LIMITATION:** A constrained container run is not managed-host certification; no "works on every shared host" promise.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 18. Normal-user installation has no Redirection/Doctor/Advanced path

* **COPY CLAIM:** none; the public listing contains no Redirection, Doctor, operator provisioning, restricted-DB or Advanced setup instructions.
* **SUPPORTING ISSUE:** #120 public-payload isolation (PR #132, accepted head `83b3b58`).
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/release/PUBLIC-PAYLOAD.md` — only 37 public files; historical classes registered only in the repository lab.
  * `wordpress/tests/release/public-runtime-audit.php` — rejects `Redirection|operator-setup|Doctor|WRITELEASH_DB_|GRANT|trusted … database/DB` in the packaged readme; #111/#120 exact-payload smoke proves no Redirection/Advanced/CLI callbacks in the public boot.
* **ALLOWED WORDING:** None in the normal listing; limitations that explain Woo behavior only.
* **REQUIRED LIMITATION:** Historical Redirection/Doctor facts remain repository research and must not enter the public listing.
* **STATUS:** PUBLISHABLE (absence verified).

## 19. Requirements metadata coherence

* **COPY CLAIM (headers):** `Requires at least: 6.7`, `Tested up to: 7.1`, `Requires PHP: 7.4`, `Requires Plugins: woocommerce`, `WC requires at least: 9.0`, `Stable tag: 0.2.0`.
* **SUPPORTING ISSUE:** #120 package identity; #121 metadata; #112 evidence; #280 pair rule.
* **SUPPORTING TEST/EVIDENCE:** `readme-validate.php` compares readme and plugin-header requirements and fails on contradiction; `package-preflight.php` locks the header values. `Requires at least: 6.7` is the directory floor so WordPress can activate the legacy band. It is not a promise that every WordPress 6.7+ site is supported. WooCommerce 10.0 through 11.x still requires WordPress 7.0 up to 8.0.
* **ALLOWED WORDING:** Directory metadata as above. 6.7 is the legacy-band floor. 7.4 is the WriteLeash/Woo PHP minimum. `woocommerce` is the required dependency. Stable tag stays 0.2.0.
* **REQUIRED LIMITATION:** "Tested up to: 7.1" reflects the tested 7.1.2 point (directory ignores the patch) and must not be read as all-7.x coverage. "Requires at least: 6.7" and "WC requires at least: 9.0" are minimums, not a rectangle.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 20. Restricted agent access (grant-bound seatbelt)

* **COPY CLAIM:** "A seatbelt, not a firewall: a restricted WordPress principal is bounded to WriteLeash's controlled price/stock job path and fails closed everywhere else. It is not a sandbox or universal 'agent-safe' guarantee."
* **SUPPORTING ISSUE:** #307, #308, #309, #310, #311, #312.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/jobs/matrix-integration.php` — 28 cells × 4 engine/cache combinations: 15 deny paths, 4 non-interference paths, 4 in-frame positives and 5 grant-state cells.
  * `wordpress/tests/jobs/perimeter-integration.php` — REST/save/stock/meta/trash/delete denials before persistence, controlled-frame positives, kill-switch and revocation races, denial log.
  * `wordpress/tests/jobs/stock-integration.php` — bounded SET/INCREASE_INT/DECREASE_INT, floor/delta refusals, variation parent stock-status sync, stock REST scope denial.
  * `wordpress/tests/jobs/grant-integration.php` — actor/issuer/approver, scope, expiry/revocation/disable, budget/recovery, idempotency, audit/journal, pause racing an open item.
  * `wordpress/tests/jobs/grant-removal-integration.php` — removal marker, self-un-leash refusal, revoke-all CAS/audit/no un-leash, administrator-only emergency controls.
  * `wordpress/tests/admin/grant-admin-integration.php` — operator matrix, create contract, revoke, remove-restriction warning/audit, pause/resume option posture.
  * `wordpress/tests/admin/stock-job-integration.php` — stock grant job view, history and read-only progress copy.
  * `wordpress/tests/admin/grant-cli.sh` — `wp writeleash grant list|create|revoke|disable|enable` with authenticated `--user`, emergency controls administrator-only.
* **ALLOWED WORDING:** The seatbelt sentence above; grant-scoped price/stock jobs through the controlled path; frozen scope, budget, expiry and stock bounds; operator issue/inspect/revoke/pause/resume/revoke-all/remove-restriction; append-only grant/denial audit.
* **REQUIRED LIMITATION:** Not a firewall or sandbox. Does not constrain administrators who are not grant actors, hostile plugins, direct SQL, third-party hook side effects, orders/refunds/coupons/tax/options or arbitrary meta. A user-0 system cron is outside the grant. Stock Undo is unsupported; Undo is price-only. No universal "agent-safe" claim.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 21. Accidental price and stock overwrite protection

* **COPY CLAIM:** "Protection against accidental WooCommerce price and stock overwrites, with Preview, conflicts, History and eligible Undo."
* **SUPPORTING ISSUE:** #322 listing position. Price conflicts and eligible Undo use the accepted #107/#110/#112 evidence. The stock path uses the #310/#312 grant boundary, not a new write path.
* **SUPPORTING TEST/EVIDENCE:**
  * Conflict behavior: `wordpress/tests/acceptance/EVIDENCE.md` and `wordpress/writeleash/includes/free/class-product-snapshot.php` (row 11). The check is the stored price WriteLeash is changing, or another checked setting, not every catalog edit.
  * Eligible Undo: `wordpress/writeleash/FREE-UNDO-HISTORY-CONTRACT.md` and `wordpress/tests/acceptance/EVIDENCE.md` (rows 13 and 14). Restoration is price-only, when the saved details and current product state permit it.
  * Stock controlled path: `wordpress/tests/jobs/stock-integration.php` grant path only (bounded SET/INCREASE_INT/DECREASE_INT inside a frame). Not a claim that every stock write is protected. Ordinary price operations still do not change stock (row 4).
  * Restricted agent: `wordpress/tests/jobs/matrix-integration.php`, `wordpress/tests/jobs/perimeter-integration.php`, and `wordpress/tests/jobs/grant-integration.php`, as already listed in row 20.
* **ALLOWED WORDING:** The short description above. Say that merchant price jobs use Preview, conflict checks, History and eligible price Undo, and that WriteLeash stock changes run only on the opt-in controlled grant path.
* **REQUIRED LIMITATION:** This is not protection for every WooCommerce write, not direct SQL, not hostile plugins, and not a firewall. Stock Undo is unsupported. Eligible Undo does not reverse orders, completed sales, emails, webhooks, remote requests, external queues or other plugins' effects.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

---

## Forbidden public claims (must never appear)

* 10,000 or more supported products; any size above the tested 1,000-product new-job ceiling as a support promise.
* WooCommerce outside the two certified bands, "all WooCommerce versions", or the rectangle "WooCommerce 9.0 through 11.x".
* WordPress below 6.7. WooCommerce 9 on WordPress 7. WooCommerce 10 or newer on WordPress 6.7 or 6.8. The WooCommerce 11.1.2 package still refuses WordPress below 7.0.
* Real managed/shared-host certification.
* Universal rollback; "rolls everything back"; "prevents all bad edits"; any protection claim for every Woo write.
* Reading the #322 listing as a firewall, hostile-plugin isolation, direct SQL protection, universal agent safety, or protection for every WooCommerce write. Stock wording covers only the opt-in controlled grant path.
* Reversal of orders, sales, emails, webhooks, remote HTTP or external queues.
* Guaranteed shopper prices; arbitrary save-hook throughput; arbitrary metadata size.
* Package-upgrade certification from a released Free package (none exists).
* Multisite support.

The `#121` readme guard in `readme-validate.php` fails the listing if
`10,000`/`10000`, "universal rollback", "all bad edits", "every shared host",
"all WooCommerce versions" or "any WooCommerce version" appears.

## Source references

* Accepted product evidence: #107 (PR #114), #108 (PR #115), #109 (PR #116),
  #110 (PR #117), #111 (PR #118), #112 (PR #119).
* Public payload isolation: #120 (PR #132, merge `22a89ec`, accepted head `83b3b58`).
* `wordpress/tests/acceptance/EVIDENCE.md`, `SUPPORT-REPAIR.md`, `README.md`.
* `wordpress/writeleash/FREE-PRICE-CONTRACT.md`,
  `FREE-PRICE-APPLY-CONTRACT.md`, `FREE-JOB-ENGINE-CONTRACT.md`,
  `FREE-UNDO-HISTORY-CONTRACT.md`.
* `wordpress/release/PUBLIC-PAYLOAD.md`,
  `wordpress/release/writeleash-distribution-files.txt`.
* Primary WooCommerce 11.1.2 package evidence:
  https://downloads.wordpress.org/plugin/woocommerce.11.1.2.zip,
  SHA-256 `9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e`.
  Mutable secondary corroboration only:
  `https://api.wordpress.org/plugins/info/1.0/woocommerce.json`, reviewed 2026-10-02.

## Current WordPress.org guidance reviewed (2026-10-02)

* https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
  (last modified 2026-03-11) — GPL compatibility (1), code readability/source
  availability (4), no trialware (5), no tracking (7), no external executable
  code (8), no fake reviews/keyword stuffing/legal-compliance guarantees (9),
  limited tags and no readme spam (12), default libraries (13), version
  increments (15), complete plugin at submission (16), trademark/project-name
  respect (17).
* https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
  (last updated 2026-03-11) — readme header fields, 150-character short
  description, 1–5 tags, stable tag semantics, screenshots/custom sections,
  ~10k readme guidance.
* https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
  (last updated 2026-09-01) — "Tested Up To" must be an actually tested,
  released version (not a range); maximum 5 tags; minified code needs readable
  source; no zips in SVN; stable tag must not be `trunk`.
* https://developer.wordpress.org/plugins/plugin-basics/header-requirements/
  (last modified 2026-03-11) — `Requires at least` and `Requires PHP` header
  semantics; since WordPress 5.8 requirements are parsed from the main plugin
  file.
* Official readme validator: https://wordpress.org/plugins/developers/readme-validator/
  — run 2026-10-02; no errors; only non-blocking notes (Contributors omitted, no
  Upgrade Notice section, no donate link).

## #122/#182/#195 screenshot caption mapping

The current readme captions and filenames follow #195 curation: (1) product
selection; (2) exact Sale Price Preview; (3) background progress and Resume;
(4) completed Regular Price changes and explained invalid-price skip; (5) a $100 expected / $120 current / $80
planned conflict with the newer edit preserved; (6) History and eligible Undo.
See `docs/visual-assets-v0.1.md` for exact captions and capture provenance.

## #244 current-source listing addendum (2026-10-11)

Source: `c291cdb596b7d5b6f2a0345d7f1ac91070619794` (#247 merged).
The historical baseline above remains evidence, not a final v0.2 artifact receipt.
This addendum supersedes older operation/screenshot wording where noted.
All rows are **PUBLISHABLE WITH LIMITATION on reviewed source**; #240 must
verify the selected final source and ZIP before publishing them.

| Listing claim | Accepted source / tests | Required limitation |
| --- | --- | --- |
| Clear Sale and relative Sale Discount | `class-price-operation.php`, `class-change-plan.php`, `class-woo-price-mutator.php`; `tests/free/sale-operations.php`, `tests/undo/sale-operations.php`, `tests/acceptance/sale-operations.php`; merged #235 | Clear means blank, not zero; dates remain. Relative discount uses each reviewed Regular Price. Both operations conflict if that basis changes. |
| Optional Price Endings | Same calculator/plan and sale-operation tests; merged #237, `docs/review/208/IMPLEMENTATION.md` | Precision-dependent .99/.95/.90/whole options; final eligibility, direction and safety checks; Clear ignores endings. |
| Inclusive Price Range | `class-product-selector.php`; `tests/free/price-range.php`; accepted #234 commit `778af88` (included in merged PR #239) | Stored Regular/Sale Price, both endpoints inclusive, each variation's own price; blank sales excluded from numeric sale ranges. Not shopper or parent display prices. |
| Exclude individual products | `class-free-admin.php`, `class-product-selector.php`; `tests/free/exclusions.php`, `tests/admin/exclusions-integration.php`, `tests/acceptance/exclusions.php`; issue #231 via merged PR #239 | Rebuild/review Preview before approval; confirmed excluded targets have no Apply/Undo items. Existing limits remain. |
| Frozen Preview CSV | `class-free-admin.php`; `tests/admin/preview-csv-unit.php`, `tests/admin/preview-csv-integration.php`, `tests/acceptance/preview-csv.php`; issue #233 via merged PR #238 | Saved Preview only, read-only; not current catalog data or approval. |
| $100 reviewed / $80 target / $120 current conflict | Plan precondition and locked target-field comparison; `tests/durable/integration.php`, `tests/admin/regression-browser.cjs`, `tests/admin/recovery-integration.php`; #245 E3/E4 | Otherwise eligible target, USD/two decimals and permissive limits; current-value checks, not intervening-edit detection; fresh Preview requires separate approval. |

Paths beginning `class-` are under `wordpress/writeleash/includes/free/`;
`tests/` paths are under `wordpress/`. Additional core/Undo/compatibility
traceability is in `docs/marketing/245-safety-messaging.md` E1–E10 and the
#244 SEO brief. Only exact exercised WordPress fixtures are claimed, not all
7.x releases. Existing metadata is unchanged; #240 owns final versions.

The six revised captions match `wordpress/release/assets-122/proof.json`.
No PNG, capture hash or timestamp changed. Screenshots 1/3 are explicitly
historical; 2 does not prove new CSV/exclusion controls, 5 shows the preserved
$120 Regular Price, and 6 proves availability rather than completed Undo.
See `docs/marketing/244-wporg-seo-brief.md` for recapture guidance.
