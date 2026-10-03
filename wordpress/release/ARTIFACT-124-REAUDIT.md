# B2 — full #124 exact-artifact compliance re-audit

Verdict: **PASS; #125 authorized only for ZIP SHA-256
`7932ed450fa4fdd43902cfd74fb264f3db14271a63030726066c17e677d4223e`**.
This is a checksum-specific #124 handoff, not #125 implementation, merchant
acceptance, publication or WordPress.org human approval.

Audit base main: `db32e07f3e0fc8bb1888d3dd8f34959247893d17`, verified live before
work. Candidate source: `5ccc75c1d895a2fb379866ce4cf0e9901d1c276c` (PR #140
C124-001 fix). Tooling/evidence base is not the candidate source identity.
Unique production runtime diff: NONE. Runtime, assets, listing and manifest
were not modified. Only repository release tooling/evidence are added here.

Old ZIP `2ecfd3edf667c15b36fc75fbb525551df07b075bdfc5696a07813a704eb6cf57`
remains **SUPERSEDED / COMPLIANCE-BLOCKED / NOT authorized for #125**.
PR #139 at `859e198be1ab5f21e2995b6a14c400d89f82ec31` is preserved unchanged.
C124-001 history and regeneration records are retained. Administrative closure
of issue #124 is not acceptance; no issue state was changed.

## Identity and installation

The merged builder was executed afresh against a clean detached checkout at the
candidate source above, with Git-blob/checkout verification and the unchanged
37-file manifest. Reproduction hash and size are exactly:

- ZIP SHA-256: `7932ed450fa4fdd43902cfd74fb264f3db14271a63030726066c17e677d4223e`
- Size: **396314 bytes**
- Manifest: `d30e8952a0364ac4a7e254d0b44a2ea98eea2df9fcc9f5ff6579ab5427cda891`
- Trunk/tag tree hash: `4d3fa1f34b2435a364bc62f92db75a7c417bedb23086b02eace2cf974b6cb5c4`

Normal WP-CLI `plugin install <exact ZIP> --activate` uses WordPress
Plugin_Upgrader. Fresh WordPress **7.1.2**, WooCommerce **11.1.2**, PHP **8.2.32**,
MariaDB **10.11.14-MariaDB-0ubuntu0.24.04.1** were used. The fixture uses an isolated
Unix socket and ordinary WordPress connection, with no custom grants, triggers,
routines, special user or privileged plugin setup. A fixture account with normal
installation rights does not prove shared/managed-host portability; that remains
NOT TESTED. No Redirection or Doctor was installed or required.

All 37 installed path/byte pairs were compared with ZIP entries, before security
checks and again after normal lifecycle uninstall/reinstallation. Both match.
No source-tree plugin staging was used. Basename/header/dependency observer and
an authenticated HTTP Products → Bulk Prices GET passed. All 35 PHP files were
also directly executed without WordPress: zero exit status and no output/stderr.

## Current official guidance

Reviewed **2026-10-03**, with current fetched-page hashes/lengths in JSON evidence:

- [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [Common issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/)
- [Official Plugin Check](https://wordpress.org/plugins/plugin-check/)

No material guidance change was identified against the recorded #139 review
interpretation on the same date. Prior page bytes were not archived, so a complete
historical textual diff is UNKNOWN, not claimed. Plugin Check remains 2.1.0.
Nonce normalization, late escaping, prepared SQL, direct access, package and
Free/commercial boundaries were reread. No additional source publication rule is
invented: the candidate is readable PHP, GPL license and readme, without custom
compiled/minified production assets requiring separate source publication.

## Official Plugin Check

Current official plugin was freshly installed from WordPress.org: **2.1.0**.
Run against the actual installed `writeleash` directory:

```sh
wp plugin check writeleash --include-low-severity-errors --include-low-severity-warnings --include-experimental --format=json
wp plugin check writeleash --require=wp-content/plugins/plugin-check/cli.php --include-low-severity-errors --include-low-severity-warnings --include-experimental --format=json
```

Static and runtime-enabled runs passed; the strict runtime command was repeated
on the reinstalled exact ZIP after lifecycle checks. No file/category/rule/check
exclusion, ignore list or AI classification was used. The complete available-check
inventory is retained. The loader enables the runtime environment; low-severity
and experimental flags are explicit.

| Level | Count | Disposition |
|---|---:|---|
| ERROR | 0 | No emitted finding |
| WARNING | 0 | No emitted finding |
| NOTICE | 0 | No emitted finding |
| INFO | 0 | No emitted finding |

With zero results Plugin Check prints `Success: Checks complete. No errors found.`
even with `--format=json`; JSON evidence preserves that exact stdout and empty
stderr for each run. It does not fabricate an empty finding response or mark
unknown tool output as PASS. No unresolved error/security/compliance blocker.

## C124-001 closure in installed artifact

`nonce-artifact-audit.py` inspects exact extracted/installed class-free-admin.php.
The helper at lines 106–109 first rejects nonstrings, then calls
`sanitize_text_field( wp_unslash( $value ) )`. Preview's shared gate and the three
other public methods pass only this normalized string to wp_verify_nonce.
Verification sites: lines 201, 377, 440, 500. Raw request nonce reaching verification:
**NONE**. Preview, Approve, Resume, Undo: **PASS**.

`nonce-smoke-124-reaudit.php`, derived from the existing installed nonce observer,
ran with the ZIP-installed class and delegates to real WordPress normalization
and verification functions. **111 focused assertions PASS**: exact normalization
order/arguments; valid, invalid, slashed, HTML and mixed strings; absent/null/int/
bool/array/object input; GET refusal; independent subscriber capability refusal;
valid-nonce cross-actor Approve/Resume/Undo refusal; all durable rows, runner and
full product post/meta unchanged after rejected requests.

One repository observer input was corrected: prefixing a nonce beginning `0`
with a single slash makes PHP stripslashes interpret `\0` as NUL, so that input
is not an encoding of the valid nonce. The slashed case now uses a slash before
an HTML wrapper, whose removal follows the tested unslash→sanitize boundary.
The original failed fixture observation is disclosed here; no production code or
candidate byte changed, and the 111-assertion contract/order controls remain.
This is a fixture correction, not concealment of a production failure.

C124-001: **CLOSED for this installed checksum**, independent of issue state.

## Fresh manual security review and executable controls

Every area below was retraced in this ZIP. Old audit PASS was not inherited.
Exact file hashes and line-context inventory are included in JSON evidence.

| Area | Exact artifact paths / reasoning and controls | Result |
|---|---|---|
| Admin authentication/capabilities | class-free-admin.php boot registers authenticated admin_post actions only. can_mutate requires manage_woocommerce AND edit_products. Each process method independently checks method/capabilities; mutation actor is current user. Nonce grants neither capability nor job scope. Core controls activation/deactivation/uninstall access. | PASS |
| Admin actor scope | authorized_for_job delegates to Undo_Repository::authorized (creator, approver or manage_options override). Preview planner checks actor/product rights; Approve binds immutable plan and per-product rights; Resume/Undo authorize before worker/repository mutation. UUID possession alone grants nothing. Valid-nonce cross-actor negatives reject. | PASS |
| REST authorization | class-job-resume-rest.php and class-undo-rest.php register POST-only UUID routes and permission_callback requiring logged-in user plus both Woo/product capabilities. Handles check schema, anchored repository UUID and actor before state/detail output. Anonymous 401, subscriber 403, cross-actor shop manager 403 with explicit ownership-refusal error codes. GET and malformed paths 404. Denied requests reveal no job/Undo detail and leave all durable/product snapshots unchanged. | PASS |
| REST session semantics | Internal dispatch tests exercise permissions/ownership with real WordPress users and routes. Separately, real HTTP anonymous and cookie-authenticated admin/subscriber/other actor POSTs without REST session nonce return 401 rest_forbidden. This validates Core's cookie-session CSRF boundary, not only in-process dispatch. | PASS |
| IDs/selection | Admin requires string positive decimal ID/category grammar, exact selector enum, bounded UTF-8 SKU without controls/markup/wildcard. SKU equality is byte-checked through Woo; category excludes descendants. Job IDs must be anchored lowercase UUIDs. REST route grammar and repository anchored UUID validation reject malformed identifiers; casts cannot turn a non-UUID scalar into a valid job. | PASS |
| Operations/policy | class-price-operation.php allows exactly SET/fixed increase/decrease/percent increase/decrease. class-price-decimal.php accepts only nonnegative bounded decimal strings (12 integer digits/6 fraction digits), not exponent/coercion. Admin policy fields require strings and strict decimal parse; max_products digit grammar and <=100. Zero flag is absent or literal '1', not broad truthiness. Executed invalid enum/type/amount/limits/SKU/category/job controls. | PASS |
| Pagination | Admin GET offset grammar is bounded nonnegative digits. Plan/repository pages check offset>=0, limit 1..100; state filters use explicit enums. Prepared limit/offset and fixed sort clauses. history_jobs actor predicate applies before pagination; reads never install schema. | PASS |
| Escaping | class-free-admin.php late-escapes product names/SKU, Expected/Planned/Current, status/reasons, history/Undo and PRG values with esc_html; attributes with esc_attr; page/form/pager links with esc_url. Current price is fresh/strictly parsed, failure becomes fixed unavailable text. Stored values are not trusted. Real product title containing script markup is harmless in rendered frozen preview; Plugin Check late-escaping scan passes. | PASS |
| Admin behavior | Page-scoped transient notices are bounded, actor-keyed and consumed; safe PRG redirect targets local plugin view only. Products submenu and contextual dependency/error messages; no forced activation redirect, persistent global nag, dashboard takeover, rating ask, affiliate or unrelated marketing. | PASS |
| Job/Undo SQL | class-job-schema.php, class-undo-schema.php validate prefix-derived identifiers; %i/%s/%d bind dynamic identifiers/values. Static schema DDL via Core dbDelta uses ordinary connection on explicit setup paths, not pure reads/deactivation/uninstall. Fixed WHERE/status/placeholder-count fragments only, no request SQL. Lease/recovery/generation CAS queries re-reviewed. | PASS |
| Journal/transaction | class-price-apply-journal.php binds plan-instance/product material and checks duplicates; pre-release v1 upgrade validates material before plugin-only ALTER/UPDATE. class-price-apply-connection.php pins original mysqli handle, rejects reconnect replay, verifies savepoint ownership and query verbs, fail-closes transaction loss/ambiguous commit. Job/Undo fences lock lifecycle/parent rows on same transaction. This is source review, not new crash/concurrency acceptance. | PASS |
| Woo mutation | class-woo-price-mutator.php and class-woo-undo-mutator.php verify fresh eligibility/actor capabilities/fence/provenance and call Woo set_regular_price→save. No direct Woo regular-price SQL; target is stored absolute plan/provenance value. Independent observers verify regular/active/lookup truth. | PASS |
| Lifecycle/uninstall | Runner becomes active/deactivated/missing at defined boundaries. Owned groups alone canceled, unrelated action remains pending. Durable evidence retained, local options deleted by exact name. Normal deactivate/reactivate/uninstall all preserve every Woo product post/meta/lookup/terms and all durable rows. Separate query observer records zero DDL_DCL and PRODUCT_DML for all four lifecycle operations. | PASS |
| Remote code/tracking/updater | Literal main includes form exactly the manifest closure; schema includes point only to Core upgrade.php. No eval/assert-as-code/create_function/preg_replace-e, user-controlled callback/file/include, wp_remote/download-execute/custom updater, telemetry/licensing/tracking. GNU license URLs and local routing strings are harmless metadata. | PASS |
| Free/trialware | class-free-support-contract.php defines tested Woo version and 100-product NEW-job ceiling; neither is a paid unlock. No license key/remote activation/trial clock or paid correctness/recovery/Resume/history/eligible Undo branch. Historical verified recovery is not blocked by new-work ceiling. | PASS |
| Package/direct access | 37 files, 35 PHP, one header; ABSPATH guards and dual uninstall guard. All direct executions exit safely. Literal public closure, inventory candidate mode, credential/path scan and source/readme audits rerun on installed/extracted bytes. No assets/tests/CI/reports/research/fixtures/proof/shim/Guard/Doctor/Redirection/operator/Docker/logs/archives/secrets/private paths. Finite historical option deletion literals are cleanup, not historical runtime. | PASS |
| Readability/metadata/claims | Readable PHP/GPL/readme. Version/Stable tag 0.1.0; WriteLeash/writeleash; Woo dependency; minimum WP 7.0, Tested 7.1, PHP 7.4, GPL v2 or later. Exact installed readme vs unchanged claim matrix passes all 15 topics and forbidden-claim controls. | PASS |

Claim boundaries remain published core simple products; stored regular price only;
base currency; no configured sale price/date; the five operations; 100-product
new-job ceiling; supported precondition conflicts instead of blind overwrite;
conflict-aware eligible Undo; multisite unsupported; managed/shared hosts NOT
TESTED; Woo **11.1.2 exact**. SKU/name/category-only changes are provenance facts,
not silently widened apply conflicts. Shopper price and irreversible side effects
are not promised. No listing/claim broadening.

The lifecycle fixture also creates a real one-product public Admin apply and
eligible Undo before taking the retention baseline. Nonempty APPLIED journal,
completed job and UNDONE/UNDO_COMPLETED operation/item evidence survive all
lifecycle phases unchanged. This is a bounded local uninstall-retention control,
not #125 merchant acceptance. Its initial observer compared the raw restored
string `31.00`; Undo correctly restores canonical decimal `31`. Independent
state read showed UNDONE/UNDO_RESTORED, and the observer was corrected to compare
through Price_Decimal::parse, then rerun successfully. This second fixture
assertion correction is disclosed; no runtime bytes were changed.

## PHPCS suppression inventory

**44 sites measured in the exact installed artifact**, not assumed from #139.
Each `PHPCS_SUPPRESSIONS` JSON row contains file, line, rules, artifact-file SHA-256,
line-numbered adjacent code, fresh individual disposition and reason. All are
`NON_BLOCKING_NOTE` for inline code annotations; `PLUGIN_CHECK_EMITTED_FINDING`
is false. None is described as an actual warning suppressed by Plugin Check.

The 41 durable-query sites require current DB/CAS/transaction truth and use
validated/prepared identifiers and values; the two WP_Query selection sites are
bounded supported meta/tax queries; the integer exception site carries typed count
data into a fixed reason and escaped notice. Each query, lease/status fragment,
actor predicate, join, backoff, shutdown and duplicate-insert site was separately
reviewed again. No inline suppression was added to product runtime, and no
command-level blanket exclusion exists.

## Evidence, reproducible commands and verifier

```sh
python3 wordpress/release/build-wordpress-org.py --source /tmp/writeleash-124-regen-source-1 --sha 5ccc75c1d895a2fb379866ce4cf0e9901d1c276c --output /tmp/writeleash-124-reaudit-build
wp plugin install /tmp/writeleash-124-reaudit-build/writeleash-0.1.0.zip --activate
wp eval-file wordpress/release/zip-smoke.php
wp eval-file wordpress/release/nonce-smoke-124-reaudit.php
wp eval-file wordpress/release/compliance-smoke-124-reaudit.php
wp eval-file wordpress/tests/admin/sql-audit.php
wp eval-file wordpress/release/durable-lifecycle-fixture-124.php
wp eval-file wordpress/release/lifecycle-smoke-124-reaudit.php before
wp plugin deactivate writeleash --require=wordpress/release/lifecycle-query-observer-124.php
wp eval-file wordpress/release/lifecycle-smoke-124-reaudit.php deactivated
wp plugin activate writeleash --require=wordpress/release/lifecycle-query-observer-124.php
wp eval-file wordpress/release/lifecycle-smoke-124-reaudit.php reactivated
wp plugin deactivate writeleash --require=wordpress/release/lifecycle-query-observer-124.php
wp plugin uninstall writeleash --require=wordpress/release/lifecycle-query-observer-124.php
wp eval-file wordpress/release/lifecycle-smoke-124-reaudit.php uninstalled
```

Observers run outside the plugin payload with WP-CLI `--path` pointing to the
fresh site. Lifecycle phases use fresh WP-CLI processes/DB reads. The enhanced
security observer explicitly requires ownership error codes for cross-actor
capable roles, covers malformed REST/Admin IDs and compares all durable/product
state. HTTP evidence uses ephemeral in-memory credentials/cookie handling;
no cookies, nonce values, passwords, tokens or raw HTTP logs are committed.

`artifact-124-reaudit-evidence.json` contains checksum/file inventory, environment,
guidance retrieval identity, strict Plugin Check stdout/stderr, available checks,
44 reviews, nonce/lifecycle/authorization/HTTP outcomes and checksum-specific
verdict. Package and read-only SQL observers are retained. Historical reports
are untouched. `compliance-reaudit-124.py` rejects checksum/installed drift,
unknown Plugin Check output and incomplete/blocked evidence. PR_FAST artifact
tests exercise checksum drift, installed helper injection and all four finding
levels without granting authorization from identity-only mode.

## Handoff and limits

Unresolved security/compliance findings: **NONE**. Source change required: **NO**.
Exact artifact still valid: **YES**. #125 authorization: **YES, only the new
checksum above**. #125 has not been started. If source/artifact bytes change,
this handoff does not apply; regenerate and re-audit.

No new operational support claim for old migration scale, persistent-cache,
crash or concurrency matrices. Official Plugin Check is not a replacement for
WordPress.org human review. Exact-final-head PR_FAST/CI_COVERAGE must pass with
selected runtime owners NONE. RELEASE_FULL NOT RUN. No issue state edits,
publication, SVN commit/import, GitHub Release, release tag or merge.
