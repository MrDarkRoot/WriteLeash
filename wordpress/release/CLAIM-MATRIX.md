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

## 1. New-job product ceiling: 100

* **COPY CLAIM:** "Up to 100 selected products per new job in the tested configuration."
* **SUPPORTING ISSUE:** #112 (accepted by PR #119); enforcement in #111/#119.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/EVIDENCE.md` — complete 100-product workflow passes on every advertised row; 101/1,000/10,000 refused before journal.
  * `wordpress/tests/acceptance/SUPPORT-REPAIR.md` — production `Free_Support_Contract::MAX_JOB_PRODUCTS = 100`; explicit/category/approval/HTML/policy enforcement.
  * `wordpress/tests/acceptance/README.md` — `REFUSED_BEFORE_JOURNAL`, zero Woo saves, unchanged prices for oversized requests.
  * Final-head CI: `woo-admin-111`, `woo-acceptance` artifacts on `83b3b58`; #112 run 37002200860 (per PR #132).
* **ALLOWED WORDING:** "Up to 100 selected products per new job in the tested configuration."
* **REQUIRED LIMITATION:** Must be tied to "new job" and the tested configuration; must not say 100 is guaranteed for arbitrary shops, metadata sizes or hook loads.
* **STATUS:** PUBLISHABLE.

## 2. Engineering selector maximum 1,000

* **COPY CLAIM:** none. The word "1,000"/"1000" does not appear in the public listing.
* **SUPPORTING ISSUE:** #107 internal engineering selector; #112 decision; #119 shipping boundary.
* **SUPPORTING TEST/EVIDENCE:** `SUPPORT-REPAIR.md` ("Engineering selector maximum: 1000 internally"; "not a public support claim"); `readme-validate.php` rejects `1,000`/`1000` in the listing.
* **ALLOWED WORDING:** Internal/repository documentation only.
* **REQUIRED LIMITATION:** Never present as a supported size, throughput or host promise.
* **STATUS:** INTERNAL ONLY.

## 3. Five price operations

* **COPY CLAIM:** "choose Set, + fixed, - fixed, + percent or - percent".
* **SUPPORTING ISSUE:** #107 (accepted); #111 Admin workflow.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/writeleash/FREE-PRICE-CONTRACT.md` — operation contract and absolute target computation.
  * `wordpress/tests/acceptance/EVIDENCE.md` — completed rows applied exact frozen targets; percentage targets stayed absolute, never recomputed.
  * Unit suite `wordpress/tests/admin/` and the #107 suite (2,564 assertions) on the #120 final head.
* **ALLOWED WORDING:** "Set, + fixed, - fixed, + percent or - percent" for stored regular price.
* **REQUIRED LIMITATION:** Applies only to the stored regular price of eligible products; no sale-price or variation editing.
* **STATUS:** PUBLISHABLE.

## 4. Supported product scope

* **COPY CLAIM:** "Published core simple WooCommerce products", "Stored regular prices in the base store currency", "Products with no sale-price/date configuration".
* **SUPPORTING ISSUE:** #107 (contract), #108 (mutation proof), #111 (Admin), #112 (acceptance rows).
* **SUPPORTING TEST/EVIDENCE:**
  * `FREE-PRICE-CONTRACT.md` — eligibility rules for published simple products, stored regular price, base currency, no sale-price/date configuration (including inactive, future and expired sales).
  * `wordpress/writeleash/FREE-PRICE-APPLY-CONTRACT.md` — stock `WC_Product_Data_Store_CPT` and Woo CRUD path.
  * `wordpress/tests/acceptance/EVIDENCE.md` — price/meta/lookup/cache/journal parity on every completed row.
* **ALLOWED WORDING:** "Not changed: sale prices, sale dates, variations, stock and orders."
* **REQUIRED LIMITATION:** Variations, sale prices/dates, stock, orders and non-simple product types are unsupported.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 5. WooCommerce version

* **COPY CLAIM:** "WooCommerce 11.1.2 exactly."
* **SUPPORTING ISSUE:** #108/#111 execution gate; #112 compatibility row; #119 support boundary.
* **SUPPORTING TEST/EVIDENCE:**
  * `FREE-PRICE-APPLY-CONTRACT.md` — pinned to Woo 11.1.2 stock data store; official ZIP SHA-256 recorded.
  * `wordpress/tests/acceptance/EVIDENCE.md` — row `7.1.2 / 11.0.1 / 8.2.34` → `UNSUPPORTED: existing execution gate; zero applied`.
  * `wordpress/writeleash/includes/free/class-free-support-contract.php` — `WOOCOMMERCE_VERSION = '11.1.2'`.
  * Canonical immutable artifact: https://downloads.wordpress.org/plugin/woocommerce.11.1.2.zip → `Version: 11.1.2`, `Requires at least: 7.0`; SHA-256 `9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e`.
* **ALLOWED WORDING:** "WooCommerce 11.1.2 exactly."
* **REQUIRED LIMITATION:** No older, newer or range support; the Admin refuses unsupported versions before creating jobs.
* **STATUS:** PUBLISHABLE.

## 6. WordPress versions and minimum metadata

* **COPY CLAIM:** "WordPress 7.0.1 and 7.1.2 were exercised. Requires WordPress 7.0 or newer, the minimum of the supported WooCommerce package."
* **SUPPORTING ISSUE:** #112 compatibility rows; #121 metadata decision.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/EVIDENCE.md` — exact rows for WP `7.1.2` and `7.0.1`; `6.8.3` recorded as `UNSUPPORTED: Woo package requires WP 7.0`.
  * Exact Woo package header (`Requires at least: 7.0`) and `https://api.wordpress.org/plugins/info/1.0/woocommerce.json` (`requires: 7.0`), checked 2026-10-02.
  * `readme-validate.php` requires both exact fixtures and the 7.0 floor, and requires the plugin header `Requires at least: 7.0` to match.
  * `wordpress/tests/dependency-63.php`, foundation `in-container.sh` and current-core `in-container.sh`: exact public WP 6.8.3 expected Core minimum refusal before any historical shim; public WP 7.1.2 dependency proof with missing Woo and active Woo 11.1.2. See `wordpress/release/PHASE-B-121.md` for the separate repository-only historical mechanism.
* **ALLOWED WORDING:** "Requires WordPress 7.0 or newer" as directory metadata plus the exact tested fixtures.
* **REQUIRED LIMITATION:** Do not claim all WordPress 7.x releases are supported from two tested points; directory metadata is a minimum, not a coverage map.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 7. PHP versions and minimum metadata

* **COPY CLAIM:** "PHP 7.4.33, 8.0.30, 8.1.34 and 8.2.34 were exercised. Requires PHP 7.4 or newer."
* **SUPPORTING ISSUE:** #108/#109/#110 fixtures; #112 compatibility rows; #119.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/EVIDENCE.md` — rows for PHP 7.4.33, 8.0.30, 8.1.34, 8.2.34 at 100 products.
  * `wordpress/writeleash/writeleash.php` — runtime guard fails closed below PHP 7.4.
  * Exact Woo 11.1.2 package `Requires PHP: 7.4`.
* **ALLOWED WORDING:** "Requires PHP 7.4 or newer" with the four exact exercised patch versions.
* **REQUIRED LIMITATION:** No claim that 1,000 items passed on PHP 7.4/8.0/8.1 (only 8.2 evaluated 1,000), and no unlisted PHP version claim.
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

* **COPY CLAIM:** "with default and Redis Object Cache 2.7.0 (Redis 7.4.2) persistent cache modes."
* **SUPPORTING ISSUE:** #108 cache verifier; #109/#110 cache suites; #112 cache/hook torture.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/EVIDENCE.md` — Redis 7.4.2, Redis Object Cache/drop-in 2.7.0; rollback-before-commit, retry, SIGKILL recovery and cache-repair parity passed.
  * `FREE-PRICE-APPLY-CONTRACT.md` — Woo `ProductCache::remove()` plus targeted lookup/meta repair.
* **ALLOWED WORDING:** "default and Redis Object Cache 2.7.0 (Redis 7.4.2) persistent cache modes."
* **REQUIRED LIMITATION:** Exact fixture only; not a general "works with every caching plugin" promise.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 10. Frozen preview and approval binding

* **COPY CLAIM:** "build a frozen preview and approve the exact plan"; "Approval executes exactly the frozen product IDs and the absolute target prices that were previewed."
* **SUPPORTING ISSUE:** #107 immutable Change Plan; #109 durable import; #111 Admin.
* **SUPPORTING TEST/EVIDENCE:**
  * `FREE-PRICE-CONTRACT.md` — immutable plan, `Plan_Hasher`, approval hydration/fingerprint checks.
  * `wordpress/tests/acceptance/EVIDENCE.md` — percentage targets stayed absolute on apply and Undo; immutable bindings unchanged.
  * `SUPPORT-REPAIR.md` — approval hydrates/verifies frozen material; cannot be reused for different inputs.
* **ALLOWED WORDING:** "Changing the selection, operation or safety policy requires a new preview."
* **REQUIRED LIMITATION:** Planned prices are target store values, not guaranteed shopper prices.
* **STATUS:** PUBLISHABLE.

## 11. Conflict behavior

* **COPY CLAIM:** "If the stored regular price or another execution precondition no longer matches the approved plan, that item is reported as a conflict instead of being blindly overwritten."
* **SUPPORTING ISSUE:** #107 optimistic concurrency contract; #109/#110 conflict state; #111 item UI; #112 fresh-state tests.
* **SUPPORTING TEST/EVIDENCE:**
  * `wordpress/tests/acceptance/EVIDENCE.md` — "regular-price property changed to 88" → rollback to 100, journal/job `NEEDS_REVIEW`, no false `APPLIED`; external edit before Apply recorded.
  * `wordpress/tests/acceptance/SUPPORT-REPAIR.md` — one later Woo edit to 75 conflicts while other items restore.
  * `FREE-PRICE-CONTRACT.md` and `class-product-snapshot.php` — execution fingerprint covers stored regular price, product existence, core-simple/type state, publication status, sale configuration, currency/base context, price decimals and WordPress/WooCommerce versions. SKU, name/title and category membership are provenance-only.
* **ALLOWED WORDING:** The exact copy claim above. Screenshot caption: "A later regular-price or execution-state change becomes a conflict instead of a blind overwrite."
* **REQUIRED LIMITATION:** SKU/name/category-only changes are not automatic execution conflicts. Never claim WriteLeash prevents all bad edits or blocks every concurrent Woo/plugin write; only the approved items are rechecked and fenced.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 12. Durable progress, partial outcomes and Resume

* **COPY CLAIM:** "Progress is durable, so a closed browser or an interrupted worker does not lose the job"; "the protected Resume action continues the existing job in bounded chunks"; "A partial result reports per-outcome counts instead of claiming global success."
* **SUPPORTING ISSUE:** #109 durable job engine; #110 Undo lifecycle; #111 Resume/REST UI; #112 scheduler/kill tests.
* **SUPPORTING TEST/EVIDENCE:**
  * `FREE-JOB-ENGINE-CONTRACT.md` — durable repository is truth; Action Scheduler is wake-up only; one bounded chunk per worker run.
  * `wordpress/tests/acceptance/EVIDENCE.md` — real SIGKILL with 60-second lease expiry recovered by protected HTTP Resume; duplicate/stale wakes changed no counts; scheduler refusal preserves `PAUSED`.
  * `wordpress/tests/jobs/LAB-ACTIVITY.md` — crash/lease/generation evidence.
* **ALLOWED WORDING:** Durable progress, truthful remaining work and protected bounded Resume.
* **REQUIRED LIMITATION:** Impaired scheduling may require the manual Resume action; no promise of unattended completion on every host.
* **STATUS:** PUBLISHABLE WITH LIMITATION.

## 13. Undo eligibility

* **COPY CLAIM:** "Undo restores eligible stored regular-price values that WriteLeash previously applied when the durable evidence and the current product state permit it."
* **SUPPORTING ISSUE:** #110 conflict-aware Undo and history.
* **SUPPORTING TEST/EVIDENCE:**
  * `FREE-UNDO-HISTORY-CONTRACT.md` — eligible stored-price restoration with proven Apply evidence; `UNDO_CONFLICT` and `UNDO_NEEDS_REVIEW` never overwrite.
  * `wordpress/tests/acceptance/EVIDENCE.md` — eligible stored price restored; external edit before Undo recorded; partial Undo continues after reopen.
  * `wordpress/tests/acceptance/SUPPORT-REPAIR.md` — one later edit conflicts, other items restore; first Undo POST remains nonterminal until the operation finishes.
* **ALLOWED WORDING:** The exact sentence above; the UI action "Restore eligible prices (Undo)".
* **REQUIRED LIMITATION:** Not universal rollback; a stored regular-price or Undo execution-precondition mismatch is not overwritten. Do not describe arbitrary product edits as automatic conflicts.
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

* **COPY CLAIM (headers):** `Requires at least: 7.0`, `Tested up to: 7.1`, `Requires PHP: 7.4`, `Requires Plugins: woocommerce`, `Stable tag: 0.1.0`.
* **SUPPORTING ISSUE:** #120 package identity; #121 metadata; #112 evidence.
* **SUPPORTING TEST/EVIDENCE:** `readme-validate.php` compares readme and plugin-header requirements and fails on contradiction; `package-preflight.php` locks the header values; official readme validator result 2026-10-02 (notes only, no errors).
* **ALLOWED WORDING:** Directory metadata as above with rationale: 7.0 is the WooCommerce 11.1.2 package minimum; 7.4 is the WriteLeash/Woo minimum; `woocommerce` is the required dependency.
* **REQUIRED LIMITATION:** "Tested up to: 7.1" reflects the tested 7.1.2 point (directory ignores the patch) and must not be read as all-7.x coverage.
* **STATUS:** PUBLISHABLE.

---

## Forbidden public claims (must never appear)

* 1,000 or 10,000 supported products; the internal selector maximum as a support promise.
* Support for WooCommerce versions other than 11.1.2, or "all WooCommerce versions".
* "Old WordPress support" below 7.0 (the Woo 11.1.2 package refuses WP 6.8.3).
* Real managed/shared-host certification.
* Universal rollback; "rolls everything back"; "prevents all bad edits"; any protection claim for every Woo write.
* Reversal of orders, sales, emails, webhooks, remote HTTP or external queues.
* Guaranteed shopper prices; arbitrary save-hook throughput; arbitrary metadata size.
* Package-upgrade certification from a released Free package (none exists).
* Multisite support.

The `#121` readme guard in `readme-validate.php` fails the listing if `1,000`/`1000`,
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

## #122 screenshot caption mapping

The readme captions are written to match the #122 asset plan and #111 UI
states, in order: (1) frozen before/after preview; (2) safety-policy blocked
plan; (3) "A later regular-price or execution-state change becomes a conflict instead of a blind overwrite."; (4) interrupted/paused job
with truthful remaining work and Resume; (5) partial result with per-outcome
counts; (6) history with conflict-aware Undo eligibility. No #122 asset files
were created or modified by #121.
