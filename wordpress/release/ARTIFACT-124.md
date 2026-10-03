# #124 exact-artifact compliance gate — GO

All conclusions below attach only to ZIP SHA-256
`2ecfd3edf667c15b36fc75fbb525551df07b075bdfc5696a07813a704eb6cf57`
(size **395959**), version **0.1.0**, source Git SHA
`27c3241be4eb5ce9772a128c3af1a381cd3c8843`. Audit branch starts at reviewed
current main `a19ac0a729fe9c98c5a3c2ad257f47e157591bda`.

Merged #123 tooling independently reproduced the frozen source in a clean
checkout, not current main. Its public manifest hash is
`d30e8952a0364ac4a7e254d0b44a2ea98eea2df9fcc9f5ff6579ab5427cda891`;
trunk/tag tree hash is
`ab855d166300890e10b93a84edce42f655ad09dc856f37efb6394cac0b743824`.
The reproduction matches the exact original #123 ZIP hash and byte size.
No candidate runtime, metadata or assets were edited. No replacement artifact
is authorized by this report.

## Environment and official guidance

Execution/review date: **2026-10-03**. WordPress **7.1.2**, WooCommerce
**11.1.2**, PHP **8.2.32**, MariaDB **10.11.14**, WP-CLI **2.12.0**;
Python **3.12.3**, Git **2.43.0** for local artifact tooling.
The database is a disposable local Unix-socket fixture using the ordinary
WordPress connection. No product SQL, custom grants, triggers, routines,
secondary DB user or external hosting setup was required.

First-party guidance reread:

- [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) (page Last Updated: March 15, 2024).
- [Common issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/) (page Last Updated: March 20, 2026).
- [Official Plugin Check guidance](https://wordpress.org/plugins/plugin-check/), including the CLI runtime-loader requirement.

No material change relative to the recorded #121/#123 interpretations was
identified: readable deployed source satisfies the source-access rule;
capabilities are separate from CSRF checks; trialware, tracking, remote code
and Admin hijacking remain disallowed. There is no archived byte-level
historical webpage snapshot here, so this does not claim a complete historical
text diff. Plugin Check 2.1.0 is current per the official plugin information
API and is the same pinned version already referenced by repository audits.
No public claim was rewritten.

## Exact install and Plugin Check

`wp plugin install <exact ZIP> --activate` used the ordinary WordPress
Plugin_Upgrader path. Installed basename: `writeleash/writeleash.php`.
All installed paths, byte sizes and hashes match the ZIP's 37-file mapping.
The installed candidate, not a source checkout, was inspected by Plugin Check
and the source/readme compliance verifier.

Official Plugin Check **2.1.0** ZIP SHA-256:
`6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4`.

```sh
wp plugin check writeleash --require=wp-content/plugins/plugin-check/cli.php --include-low-severity-errors --include-low-severity-warnings --include-experimental --format=json
```

Separate default-static and runtime-enabled runs also completed successfully.
No rule/category/file was excluded; no ignore-code or ignore-warning option,
AI review, SVN checker or remote submission was used.

| Level | Findings | Disposition |
|---|---:|---|
| ERROR | 0 | No unresolved directory error |
| WARNING | 0 | None to suppress |
| NOTICE | 0 | None emitted |
| INFO | 0 | None emitted |

Exact stdout: `Success: Checks complete. No errors found.` Exit status 0;
stderr empty. `artifact-124-evidence.json` preserves this result, environment,
command and all **44 inherited inline PHPCS suppression sites**. Each site
has its exact rules, path/line, artifact file SHA-256, adjacent source and
individual disposition/rationale. No new suppression was added.

The inline direct-query/no-cache notes acknowledge actual prepared durable
queries/CAS/history reads; they do not pretend direct SQL is absent. SKU/tax
query notes acknowledge query cost with fixed keys, validated values and a
bounded result set. The one exception escaping suppression carries an integer
in `Free_Job_Limit_Error`; its parent message is constant and the derived
Admin count is escaped. These are source-annotation reviews, not claims that
Plugin Check emitted 44 findings. No blanket false-positive list is applied.

## Manual security review of exact artifact

Paths/lines here refer to files in the exact installed/extracted candidate.

| Area | Exact paths and evidence | Verdict |
|---|---|---|
| Admin mutations | `includes/free/class-free-admin.php`: boot lines 41–47 registers four `admin_post_` actions; `gate` 187–199 checks both manage_woocommerce and edit_products before POST and nonce; process_approve 357+, process_resume 420+, process_undo 480+ repeat method/capability and job authorization before persistence. Action-specific nonces bind plan/job identities. | PASS |
| Actor scope | `Free_Admin::authorized_for_job` and `load_job_for_view` require creator/approver or administrator; history SQL is actor-scoped. UUID possession is not authority. Worker restores the frozen actor and Woo mutators recheck global and product capabilities fresh. | PASS |
| REST | `class-job-resume-rest.php` 17–30 and `class-undo-rest.php` 19–32 register only POST with permission_callback requiring logged-in manage_woocommerce/edit_products. Handlers check creator/approver/admin before worker/initiation. IDs use fixed UUID route regex plus repository PUBLIC_ID_REGEX. Core REST cookie authentication owns X-WP-Nonce validation; application passwords are a separate Core authentication path. | PASS |
| Input | Free_Admin build_selection 217+, build_operation 252+, build_policy 264+: strings/types checked before integer conversion, anchored digit regex, positive IDs, fixed five-operation enum, maximum new selection/policy 100. Price_Decimal::parse rejects floats, exponent notation, malformed/sign/coercion inputs. Job IDs are strict UUIDs; Undo numeric IDs come from trusted loaded operations, not request SQL. Display offsets use bounded digit regex and do not authorize mutations. | PASS |
| Escaping | Free_Admin rendering uses esc_html for product-derived/current regular-price/status/reason/history/conflict text, esc_attr for inputs/hidden values, esc_url for links/forms. Current column at 1036 escapes current_regular_price. HTML structures are fixed local markup; database origin is not treated as an escaping exemption. PRG notices map reasons to fixed messages. | PASS |
| SQL/schema | Job_Schema/Undo_Schema validate identifier names; dynamic values use prepare or typed wpdb insert/update APIs, identifiers use %i or validated/Core-owned names. Query order/state fragments are internal enums/booleans, not HTTP SQL. Job/Undo/price journals are plugin-owned InnoDB tables; dbDelta uses fixed schemas. | PASS |
| Transaction vs Woo | Price_Apply_Connection and job/Undo fences control pinned transactions, prepared lease/generation CAS and row locking. Woo_Price_Mutator/Woo_Undo_Mutator write regular prices through Woo CRUD set_regular_price/save; lock/probe SQL is distinct from price mutation. No direct Woo price UPDATE SQL. | PASS |
| Direct access | Accepted source audit checks all 35 runtime PHP files; includes guard ABSPATH, main guards ABSPATH, uninstall requires both WP_UNINSTALL_PLUGIN and ABSPATH. No release/test helper is in ZIP. | PASS |
| Network/executable code | Exact closure/source audit and manual include scan: includes are literal local files or fixed ABSPATH wp-admin/includes/upgrade.php. No eval, code assert, create_function, /e execution, user-selected include, download/execute, wp_remote_* call, custom updater or telemetry. PHP URLs are GNU license notices, not network calls. | PASS |
| Free usefulness | Five advertised operations are shipped locally, without paid prerequisite, license service, expiration or cumulative usage quota. The declared 100-product new-job safety limit is not a paid unlock; no included security feature is paywalled. | PASS |
| Admin behavior | Products submenu, contextual workflow/dependency notices and same-site safe PRG redirects only; no forced activation redirect, external Admin takeover, affiliate insertion, rating request or unrelated marketing/nag surface. | PASS |

Negative controls used real installed classes and WordPress REST dispatch:
anonymous 401; subscriber 403; unrelated shop_manager with both route
capabilities 403 for both routes. Responses exposed no job/Undo payload.
POST without valid nonce and GET Admin mutations were rejected; REST GETs
returned 404. Job row and product regular price were unchanged across these
requests. Invalid product ID strings/arrays, unknown operation, numeric amount,
exponent amount and over-limit/coercion policy values were refused. A valid
frozen preview was the positive control; no price apply/Undo journey was run.
These dispatcher tests prove route/actor authorization, not a bypass of Core
cookie authentication. Repository-only observer: `compliance-smoke-124.php`.

Initial Undo negative reached SCHEMA_UNAVAILABLE (503) before job lookup;
plugin-owned Undo_Schema::install was then used to exercise the intended
ownership check in the ready-schema fixture. The 503 contains no job data and
performs no mutation. An initial lifecycle observer incorrectly expected the
literal `inactive`; the artifact's documented state is `deactivated`. Corrected
observer then passed the full lifecycle sequence. Neither observation required
any production change.

## Lifecycle and data policy

Install/activate/deactivate/reactivate/uninstall succeeded using the exact ZIP.
`lifecycle-smoke-124.php` independently observed the product's regular price,
all product metadata, a planned durable job row, runner option and two scheduler
actions before/after. Product and metadata remained byte/value-equivalent;
job evidence remained unchanged after uninstall. Runner states were active →
deactivated → active → missing. Only the WriteLeash-group action was canceled;
the unrelated scheduler-group action stayed pending. Normal deactivation has
no DDL; uninstall deletes exact owned metadata options, preserves durable
tables and never calls product mutation/deletion. No custom DB setup or
Redirection/Doctor was installed.

Schema creation on a new public install is finite plugin-owned DDL. The
pre-release historical journal v1 migration scans old journal rows and is not
a merchant-facing bounded chunk; this audit does not authorize historical
migration scale or claim a public v1 upgrade path. No previously released v1
schema exists in this fresh fixture. Concurrent crash/cache/retention behavior
remains owned by prior integration gates and #125; this smoke does not repeat
or extend those claims.

## Listing, package and source

One plugin header; Plugin Name WriteLeash; Version/Stable tag 0.1.0; Text Domain
writeleash; Requires Plugins woocommerce; minimum WP 7.0; Tested up to 7.1;
minimum PHP 7.4; GPL v2 or later. Woo claim and execution contract stay exactly
11.1.2. Accepted readme validation passes against installed bytes.

Merged #121 claim matrix audit passes using a temporary review root containing
the **installed candidate readme** and the merged matrix/manifest. Review also
traced the following public copy to exact runtime contracts:

| Claim | Exact artifact evidence/boundary |
|---|---|
| 100 selected products per new job | Free_Support_Contract::MAX_JOB_PRODUCTS=100 and preview selection/frozen-plan enforcement; not the internal selector's 1000 engineering bound. |
| Published simple products | Product_Price_Snapshot/Product_Price_Selector eligibility rejects other status/type/configurations. |
| Stored regular price / base currency | Snapshot/store context and Woo mutator; not shopper-price guarantees. |
| No sale-price/date editing | Eligibility rejects sale configurations; mutators change regular price only. |
| SET, + fixed, - fixed, + %, - % | Exact Price_Operation enum and decimal calculator; unsupported operations rejected. |
| Conflicts | Current guarded fields/fingerprint compared before execution; title/SKU/category/provenance-only edits are not blanket conflict claims. |
| Undo | Eligible stored regular-price restoration within retention/fingerprint constraints, not external side effects/orders or arbitrary plugin effects. |
| Multisite | Listing says unsupported; network activation is refused, not promised. |
| Managed/shared hosting | Explicit NOT TESTED; local fixture is not hosting evidence. |
| Woo support | Exact 11.1.2 only; no range or widening. |

Exactly 37 files; assets outside runtime ZIP; no tests/CI/helper/fixture/proof,
research docs, historical shim/overlay, legacy public Redirection/Doctor setup,
Docker/log/dump/archive/credential/private-path payload. Historical uninstall
option-name cleanup is finite local cleanup, not a legacy product prerequisite.
All PHP is readable deployed source; no custom compiled/minified production
asset or Composer dependency needs separate source publication. No invented
source repository requirement is added.

## Regression gate and boundaries

`compliance-audit-124.py` checks the frozen ZIP hash/size, every accepted runtime
path/size/hash, ZIP metadata, package scan, source/readme audits and optional
installed-tree byte equivalence. Any unknown/nonzero Plugin Check output fails
closed for review. PR_FAST's existing independent artifact builds invoke this
verifier; no network, install or deep runtime integration is added to PR_FAST.

**GO for #124 only.** No unresolved security finding, error-level directory
blocker or production change. Any source/runtime/asset change invalidates this
audit and requires new #123-equivalent artifact production and downstream
reaudit. WordPress.org's human review remains separate. No #125 implementation,
RELEASE_FULL, merge, tag, GitHub Release, SVN operation or publication occurred.
