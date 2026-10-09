# Issue #212 — Stage 2 senior review

Review date: 2026-10-09. This work was executed immediately against the unmerged #228 candidate in an isolated worktree, `/tmp/opencode/writeleash-212-stage2`.

## A. Executive assessment

- **Independent technical result: PARTIAL.** Packaging reproduction, local PR_FAST, domain and authorization-boundary tests passed. Two concrete variation-parent contract gaps were found in the original candidate and narrowly patched. The new database-backed regression has not executed locally; the patched payload must not inherit the original candidate's green CI.
- **Overall #212 readiness: BLOCKED.** Official QIT access/CLI, permitted commercial-extension packages and a local database/container runtime are unavailable. Required live extension, HPOS/storage-mode and platform-window acceptance remain incomplete.
- **Original #211 payload: technical FAIL for the parent-state findings below.** Its checksum is verified, but it is not a correctness-ready package. The replacement review payload is reproducible; its live mutation/Undo regression remains unverified. Both genuine product findings and external blockers are recorded, rather than conflated.
- **Delivery:** Draft [PR #229](https://github.com/MrDarkRoot/WriteLeash/pull/229), stacked on #228. Its base is `work/211-woo-exact-review-package`, reviewed at `abb95109c4261057d141f3f2e0662a4295044d98`. It is not independently mergeable against `main` while #228 remains unmerged. Neither #227 nor #228 was merged, and neither issue was closed.

## B. Source and candidate identity

Current `main` at intake: `f02db253cfb08dc236f19ad4a62c567afa7be1b0`. #228 live/reviewed HEAD: `abb95109c4261057d141f3f2e0662a4295044d98`. Stage 1 [#227](https://github.com/MrDarkRoot/WriteLeash/pull/227) HEAD: `a39b1aac135ee5f1eeee34b21837675c96a7a01d`. Its existing evidence/checklist remain intact on that PR; this report supersedes its earlier artifact-unavailable observation, without rewriting historical evidence.

| Identity | Original #211 candidate | Patched Stage 2 provisional candidate |
|---|---|---|
| Build source SHA | `10493f320cf0af65641933da9f8599c7a6e8ea3b` (PR body claim independently verified) | `d8359a18c20930dabfc7342815b8ca43f6f3dc2e` (Stage 2 implementation/test commit) |
| Version | Proposed `0.2.0`, not founder-authorized release | Proposed `0.2.0`, no silent version change or release authorization |
| Filename | `writeleash-0.2.0.zip` | `writeleash-0.2.0.zip` in a separate output directory |
| ZIP SHA-256 | `08f2128e2f8df2869e4810b9e8df5762ac845d088c9776fa2f4aa5ed40afad79` | `ab7e7f2b2069d66f2e277c6ebdf4c7be626583ef7c281473fc981026f15ad3cb` |
| ZIP bytes / files | 2,029,422 / 45 | 2,029,682 / 45 |
| Runtime tree hash | `0e6e07fd9d72ea24aaaba2830caca7b8eb1668e9a215eadbb6b40089dc8b4668` | `78bf3a12b7bde8ef8f96261144b41ec78d30d9e5231e0924d89496ccc117bb10` |
| Public manifest SHA-256 (both) | `f83c628b732ba9688a5fa73063d94cfc6ebed865b7221274cf6689c78864a5e9` | Same manifest; only three existing runtime files changed |
| Local output path | `/tmp/opencode/wl212-build-a/` and `wl212-build-b/` | `/tmp/opencode/wl212-fixed-build-a/` and `wl212-fixed-build-b/` |

**Source vs HEAD:** `git diff --exit-code 10493f3 abb9510 -- wordpress/writeleash wordpress/release/build-woo-candidate.py wordpress/release/writeleash-woo-distribution-files.txt` passed: the build source and final #228 HEAD have identical candidate bytes/tooling. The #211 implementation report instead names older source `5be1ba3…` and contains a truncated manifest digest; use the full independently generated receipt above, not that abbreviated documentation. This is an evidence-document inconsistency, not a ZIP mismatch.

**Verification:** two independent clean detached worktrees per source; `PYTHONDONTWRITEBYTECODE=1` builds using the unchanged #211 builder; `cmp` byte equality; independent `sha256sum`; exact allowlist, metadata, source audit and forbidden-payload gates. Each build emits `evidence.json` with all 45 per-file hashes. `candidate-install-verify.php` passed for both distinct artifacts: 45 installed files byte-equal, parsed headers and lint clean.

**Installation limitation:** that script extracts into a fresh `wp-content/plugins` directory and models header parsing. It does not run WordPress Plugin_Upgrader, authenticate an Upload Plugin browser session, or execute DB-backed activation. Baseline hosted CI activated the staged source tree, which uses 44 runtime files rather than the exact 45-file ZIP (the extra file is the changelog). Do not label that as full exact-ZIP installation/activation acceptance.

### Reproduction commands

Use fresh paths outside the clean source checkouts; never build from a dirty review worktree. Substitute the chosen source SHA from the table explicitly.

```sh
git worktree add --detach /tmp/opencode/wl212-repro-a <source-sha>
git worktree add --detach /tmp/opencode/wl212-repro-b <source-sha>
PYTHONDONTWRITEBYTECODE=1 python3 wordpress/release/build-woo-candidate.py --source /tmp/opencode/wl212-repro-a --sha <source-sha> --output /tmp/opencode/wl212-repro-build-a
PYTHONDONTWRITEBYTECODE=1 python3 wordpress/release/build-woo-candidate.py --source /tmp/opencode/wl212-repro-b --sha <source-sha> --output /tmp/opencode/wl212-repro-build-b
cmp /tmp/opencode/wl212-repro-build-a/writeleash-0.2.0.zip /tmp/opencode/wl212-repro-build-b/writeleash-0.2.0.zip
sha256sum /tmp/opencode/wl212-repro-build-a/writeleash-0.2.0.zip
php wordpress/tests/release/candidate-install-verify.php /tmp/opencode/wl212-repro-build-a/writeleash-0.2.0.zip /tmp/opencode/wl212-repro-build-a/evidence.json /tmp/opencode/wl212-repro-install
```

## C. Security and journal assessment

Paths/line anchors below refer to the reviewed #228 HEAD unless identified as patched.

| Surface | Source-backed assessment | Evidence / residual gap |
|---|---|---|
| Admin actions / CSRF | Authenticated admin-post actions, Woo capabilities, POST-only mutation, action/job-bound normalized nonce, then ownership | `class-free-admin.php:90–92,326–337,645–804`; progress/recovery tests passed |
| Object authorization / polling | Creator/approver or administrator; job UUID and nonce do not grant an actor override; authorization before saved history reads | `class-free-admin.php:221–299,1639–1647`; 153 progress-boundary checks passed; raw catalog facts are read, not live Woo objects |
| Discovery | Capability/nonce checks, strict bounded terms/pages and allowlisted kind, per-product edit/read permissions before labels leave | `class-product-discovery.php:17–38,94–135,171–185`; supported selection frozen before execution |
| REST | POST-only UUID routes; logged-in Woo capabilities plus job authorization | `class-job-resume-rest.php:16–49`, `class-undo-rest.php:18–57`; cookie CSRF depends on Core REST authentication. Existing internal dispatch tests do not establish full HTTP missing/invalid/valid REST nonce coverage |
| Output / disclosure | Escaped server row HTML; client ordinary labels use `textContent`; outcome `innerHTML` receives server-escaped markup; generic failure responses; fixed safe redirects; CSV formula-prefix protection | `class-free-admin.php:1294–1329,1382–1455,1514–1562`; `free-progress.js:11–23`; no reproducible XSS/disclosure established |
| SQL / persistence | Fixed/validated identifiers and prepared values; strict plan hydration and persisted binding. No request-controlled SQL identifier found | `class-job-repository.php:200–249`, `class-undo-repository.php:350–368,715–831`; no QIT SQL finding exists to classify or suppress |
| Transaction ownership | Pinned mysqli handle/thread, sentinel savepoint, reconnect/replay refusal and independent observer for ambiguous outcomes | `class-price-apply-connection.php:41–89`; hosted journal crash/connection-loss tests passed on baseline |
| Fencing / recovery | Lifecycle row → job → operation/item/journal locks; generation verified inside transaction; product mutation and durable evidence share commit; retries adopt committed truth instead of replaying Woo saves | `class-job-transaction-fence.php:38–45`, `class-undo-transaction-fence.php:45–60`, mutator commit paths and worker reconciliation |
| Schema / history | Journal unique plan/product identity; duplicate seed preserves terminal state; Undo binds to durable Apply and count-checked retention purge | `class-price-apply-journal.php:152–187`, `class-undo-repository.php:154–171,1054–1109`; no schema or transaction weakening introduced |

No reproduced authorization, injection, deserialization or filesystem vulnerability was established. No blanket scanner exclusion or QIT warning suppression was added. Database-wide compromise and value-equality ABA edits remain outside the documented guarantees; hashes do not make hostile database material trustworthy.

**Unresolved security test gap:** Undo does not explicitly refresh cached user-role objects the same way Apply does. Existing revocation fixtures revoke before worker startup; revocation from another request after caches are populated needs a concurrent runtime test. This is an unverified suspicion, not a confirmed bypass or a reason for speculative security rewrites.

**Graph provenance:** GitNexus 1.6.12 was reindexed with `--pdg --index-only` on the exact review worktree. Pre-edit upstream impact: `precondition` → Apply/job worker (1 direct, LOW); `restore` → Undo worker (1 direct, LOW); `sync_variable_parent` → Apply and Undo (2 direct, LOW). Detect-changes after runtime edits reported six affected flows/HIGH review risk. Graph process extraction reported truncation and PHP name-fallback/test-double resolution; source and tests were used to corroborate. Taint `explain` returned no findings for Free_Admin and Undo mutator; callback/property/implicit flows are not fully modeled, so this is not proof of safety.

## D. Official QIT matrix

The official [submission requirements](https://developer.woocommerce.com/docs/woo-marketplace/submitting-your-product/) were fetched during Stage 2 and still name all seven categories. QIT docs root/Validation fetches are blocked by HTTP 403 here. `qit` is not on PATH, so CLI version and per-suite invocation could not be observed. No private authenticated vendor execution context is available; no official run was launched.

Artifact for pending reruns: **`ab7e7f2b2069d66f2e277c6ebdf4c7be626583ef7c281473fc981026f15ad3cb`**. The original `08f2128e…` is retained only for baseline/defect provenance.

| Official suite | Status | Run ID | Artifact SHA-256 | Evidence / blocker |
|---|---|---|---|---|
| Validation | BLOCKED | — | `ab7e7f2b…15ad3cb` | Supported QIT CLI + private authorized vendor authentication unavailable |
| Activation | BLOCKED | — | `ab7e7f2b…15ad3cb` | Same; local extraction and hosted source activation are not official QIT |
| Security | BLOCKED | — | `ab7e7f2b…15ad3cb` | Same; source review is not official QIT |
| PHPCompatibility | BLOCKED | — | `ab7e7f2b…15ad3cb` | Same; local PHP 8.2 syntax/domain tests are not official QIT |
| Malware | BLOCKED | — | `ab7e7f2b…15ad3cb` | Same; no official scan executed |
| API | BLOCKED | — | `ab7e7f2b…15ad3cb` | Same; no official API run executed |
| E2E | BLOCKED | — | `ab7e7f2b…15ad3cb` | Same; no official E2E run executed |

## E. Six-extension coexistence matrix

The static review examines WriteLeash's boundaries, not the unavailable proprietary extension internals. `Product_Price_Snapshot::fresh_product` only constructs literal core simple/variation/variable classes; other resolved Woo subclasses are refused without construction. Writable targets are published exact-core simple products or variations of a published exact-core variable parent. Core CPT data stores are checked for child writes. The parent-state gaps are addressed by the patch described below.

| Extension | Static interoperability review | Actual licensed test | Version | Evidence / blocker |
|---|---|---|---|---|
| WooCommerce Subscriptions | Subscription subclasses/custom variable types excluded; no subscription-specific metadata setter | BLOCKED | — | Permitted official package/license unavailable; recurring-price metadata/hook behavior unverified |
| WooCommerce Bookings | Booking custom product types excluded; no booking cost/resource/availability mutation path | BLOCKED | — | Permitted package/license unavailable; live activation/invariants unverified |
| WooCommerce Product Add-Ons | Eligible core products can be repriced; add-on configuration not explicitly edited; Woo save hooks still run | BLOCKED | — | Permitted package/license unavailable; metadata snapshots required |
| WooCommerce Product Bundles | Bundle custom class excluded; an eligible core component can still be repriced, affecting derived bundle prices | BLOCKED | — | Permitted package/license unavailable; membership/pricing-mode/parent-child behavior unverified |
| WooCommerce Min/Max Quantities | No explicit quantity-restriction setter; eligible core-product price writes remain possible | BLOCKED | — | Permitted package/license unavailable; live quantity invariants unverified |
| WooCommerce Shipment Tracking | Reviewed paths mutate products, not orders/tracking records | BLOCKED | — | Permitted package/license unavailable; order/shipment snapshots and HPOS/legacy runs unverified |

Explicit Apply/Undo mutation is the selected core Regular or Sale Price setter plus Woo `save()`, with active price/lookup verification, other price field and sale dates preserved. **Variable-parent synchronization is broader:** Woo's full public `WC_Product_Variable::sync()` can update parent price metadata, lookup, stock status and hooks. Do not equate absence of extension-specific setters with proof that extension metadata or hooks remain unaffected. No mock is reported as a live extension PASS.

## F. Platform evidence

| Configuration | Observation / limit |
|---|---|
| Local PHP / Node | PHP CLI 8.2.32, Node 22.23.2; DB-free tests and package extraction executed |
| Local WordPress/Woo/DB | No live installation or Docker daemon; no local MySQL/MariaDB mutation run |
| Baseline hosted journal | Exact #228 HEAD run `37945262944`: Woo 11.1.2; PHP 8.2.34; MySQL 8.0.44 and MariaDB 10.11.15; default and Redis 7.4.2 / Redis Object Cache 2.7.0 profiles (job log inspected) |
| WordPress hosted pin | Existing digest-pinned fixture uses WordPress 7.1.2; previous platform rows are historical, not acceptance of the patched ZIP |
| HPOS | Declaration `custom_order_tables` remains intact. Explicit HPOS-enabled and legacy-order-storage runtime checks for this ZIP: NOT_TESTED |
| Official support window | Two latest major releases of WordPress and WooCommerce; PHP 7.4+ minimum, 8.3+ recommended. Official WordPress version-check API returns 7.1.3 and 7.0.7; Woo official releases list latest stable 11.2.0 (2026-10-07) and previous major's 10.9.4. These current/previous major configurations were identified, not executed against this ZIP |
| Other gaps | PHP 8.3+, real-host pilot, commercial coexistence and full exact-ZIP activation not tested; multisite remains unsupported |

Release-window sources checked: <https://api.wordpress.org/core/version-check/1.7/>, <https://github.com/woocommerce/woocommerce/releases/tag/11.2.0>, <https://github.com/woocommerce/woocommerce/releases/tag/10.9.4>. Baseline Woo 11.1.2/WordPress 7.1.2 results do not establish coverage of the newer published patch/minor or previous major configuration.

## G. Executed tests and CI

### Local execution

Baseline at `abb95109c4261057d141f3f2e0662a4295044d98`: PR_FAST passed; original two-build/hash/extraction checks passed. After patch, at implementation source `d8359a18c20930dabfc7342815b8ca43f6f3dc2e` (same tested working-tree contents):

| Command | Result |
|---|---|
| `bash .github/ci/pr-fast.sh` | PASS; 10 historical artifact tests, metadata/closure/asset/ownership checks and PHP lint |
| `php wordpress/tests/free/unit.php` | PASS, 2,607 assertions including 1,000/1,001 limit and frozen plan hydration |
| `php wordpress/tests/free/sale-price.php` | PASS, 105 assertions including field-scoped legacy journal/fingerprint behavior |
| `php wordpress/tests/free/variations.php` | Before patch FAIL (`MATCH`, expected `CONFLICT`); after patch PASS, 77 assertions |
| `php wordpress/tests/free/cache-public.php` | PASS, 73 assertions; model, not real Woo runtime |
| `php wordpress/tests/free/selection-count.php` | PASS, 63 assertions; stub contract |
| `php wordpress/tests/admin/progress-unit.php` | PASS, 153 boundary checks using repository doubles |
| `node wordpress/tests/admin/progress-client.cjs` | PASS, 69,481 assertions / 9,996 enumerated cases / zero violations |
| `php wordpress/tests/admin/recovery-unit.php` | PASS, 2,618 cumulative assertions; provenance/legacy compatibility |
| `php wordpress/tests/admin/admin-audit.php wordpress/writeleash` | PASS, static transport/source audit |
| `php wordpress/tests/jobs/no-replan-audit.php wordpress/writeleash` | PASS for Apply and Undo |
| `php -l wordpress/tests/undo/parent-state.php` and `integration.php` | PASS syntax only; new DB-backed fixture NOT_TESTED locally |
| Two patched clean builds, `cmp`, `sha256sum`, extraction verification | PASS, new artifact `ab7e7f2b…15ad3cb` |

### Hosted CI provenance and invalidation

Inspected completed [run 37945262944](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37945262944), exact `headSha=abb95109c4261057d141f3f2e0662a4295044d98`, conclusion **success**:

- PASS: PR_FAST, foundation MySQL/MariaDB, engine, research, adapter, historical, plan, journal, jobs, Undo, Admin and CI_COVERAGE.
- SKIPPED: acceptance, feasibility, native. The acceptance job did **not** execute; a green aggregate does not change that.
- Journal log confirms actual crash/recovery, same-item concurrency, permission revocation, ambiguous COMMIT, lookup failures and default/Redis profiles. Hosted source activation and Admin/browser checks are baseline evidence only.
- **Invalidation:** runtime fixes change three manifest files and the checksum. None of the original #228 runtime PASS results is a post-fix PASS. Draft #229 schedules repository CI; record its own exact SHA/results when available without polling or delaying this handoff. In particular, `bash wordpress/tests/undo/run.sh` must execute the new six-case fixture on both engines/cache modes. No result is assumed while it is pending.

One post-fix snapshot was inspected while completing the report: [run 37951967922](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37951967922), exact HEAD `d8359a18c20930dabfc7342815b8ca43f6f3dc2e`. **PR_FAST and plan PASS**; journal/jobs/Undo/Admin **IN_PROGRESS** at that snapshot; unrelated foundation/engine/research/adapter/historical/feasibility/native and acceptance **SKIPPED**. No completed aggregate or new Undo-regression PASS is claimed. No CI polling/waiting was used.

## H. Defect ledger and regression scope

### S212-01 — MEDIUM: Apply admits a now-unpublished variation parent

- **Invariant:** only a published exact-core variable parent is supported at the write boundary, not just at Preview.
- **Reproducer (baseline):** preview variation #12 with regular 100 under published parent #10; supply a fresh snapshot with that same parent now `draft`, variation identity/price unchanged. `Change_Plan::precondition` returned `MATCH`. New regression at `wordpress/tests/free/variations.php:312–315` failed on the original implementation. The publication change did not affect the old child's own status/type/parent-ID checks.
- **Runtime reproduction to execute:** Preview → normal Woo parent editor/CRUD sets draft → Apply; assert CONFLICT, zero Woo saves, regular/sale unchanged and no APPLIED journal. This is in `wordpress/tests/undo/parent-state.php` for both fields; not locally executed.
- **Fix:** add parent eligibility to precondition and require published parent again under the existing parent row lock before public sync. If a concurrent parent edit arrives after the earlier check, sync refuses and the item transaction rolls back rather than commits unsupported state. No transaction/schema rewrite.
- **Observed after fix:** domain regression PASS. Database-backed zero-mutation assertion still pending.

### S212-02 — MEDIUM: Undo's parent comparison ignores the frozen parent

- **Invariant:** Undo must not restore a variation moved to a different variable parent since Apply.
- **Reproducer (source + in-memory production snapshot):** frozen plan variation #502 parent #501; current exact-core variation price 90, parent #503 published; existing guard compared current snapshot parent ID with that same current Woo object's parent ID. Probe output: `{"frozen_parent":501,"current_parent":503,"existing_restore_parent_guard_refuses":false}`. No full database restore was executed in this environment.
- **Evidence:** original `class-woo-undo-mutator.php:146`; fingerprint does not contain parent ID, so it cannot compensate for the tautological current/current comparison. The plan already stores the original parent, and binding validation precedes restore.
- **Fix:** compare against `$plan->item($product_id)->data()['snapshot']['parent_id']`, using the existing immutable plan; keep legacy fingerprint/schema/hash format unchanged. Original persisted plans can use their existing frozen parent fact, without a migration or new provenance version.
- **Regression:** both fields: approved Apply control; move variation to a new published core parent; Undo must conflict, preserve applied price/parent and emit zero restore saves. An unchanged-parent control verifies normal Undo and parent lookup range. Wired into the existing two-engine/default/Redis Undo runner; syntax checked, real execution pending.

### Other classifications

- **Confirmed evidence-document inconsistency:** #211 report's truncated manifest hash and older source label versus PR body/generated receipt; independently corrected in this report. Not a runtime defect and not edited on the other agent's branch.
- **Unverified:** concurrent cached Undo capability revocation; full HTTP REST-cookie nonce matrix; late parent-edit race under live database; ancillary variable-parent sync/hook metadata impacts. No speculative fix applied.
- **Supported false positives:** none newly adjudicated; no actual QIT scan exists. Historical Plugin Check/journal SQL handling is not QIT clearance.
- **Package impact:** original `08f2128e…` findings remain attached to that artifact. Patched `ab7e7f2b…` must receive fresh relevant runtime/official/live-coexistence evidence. No version, pricing arithmetic, authorization, schema, transaction or feature expansion was introduced.

## I. Final handoff

**Independent: PARTIAL. Overall #212: BLOCKED.** The original candidate has the two confirmed parent-boundary defects above; a narrow patch and reproducible replacement payload are available, but its complete runtime validation is still open. Static review/local tests cannot establish Marketplace certification.

Minimal remaining actions:

1. Execute the stacked Stage 2 plan/journal/jobs/Undo/Admin CI on its own exact HEAD, especially `parent-state.php`; retain failures rather than treating baseline green runs as replacement acceptance.
2. Run genuine exact-ZIP installation/activation and applicable current/previous WordPress/Woo/PHP configurations, with explicit HPOS-enabled and legacy storage observations.
3. Provide supported QIT CLI and a **private authorized vendor execution context**, then run all seven required suites against the final chosen full checksum. No credential request was posted publicly.
4. Provide legitimate permitted packages/licenses for the six extensions, record their actual versions, and run the existing [Stage 1 checklist on #227](https://github.com/MrDarkRoot/WriteLeash/blob/a39b1aac135ee5f1eeee34b21837675c96a7a01d/docs/review/212/EXECUTION-CHECKLIST.md) against that checksum. Static boundary checks are not live coexistence evidence.
5. Founder/release owner must authorize proposed version `0.2.0` and decide inclusion of the parent-state fixes. Any further payload change requires a new checksum and affected reruns. No merge, submission, Marketplace publication or release was performed.

Evidence identity summary: [STAGE2-ARTIFACTS.json](STAGE2-ARTIFACTS.json). Final PR HEAD may include report-only commits after implementation source `d8359a1…`; retrieve it from #229 and verify the manifest bytes are unchanged before reusing the replacement checksum.
