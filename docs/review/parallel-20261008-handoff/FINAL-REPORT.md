# Lead final report — four-lane preparation, gates, and disposable combined validation

Date: 2026-10-08 (OpenCode continuation of the Codex handoff). Base remains `c48e307efe7750d0fb36015c39d50a34675f9579`.
Authorized scope: preparation, independent local validation, a disposable combined candidate. **No merge, push, PR, deployment, publication, issue closure, or RELEASE_FULL was performed. The founder retains final GO / NO-GO.**

## 1. Environment recheck (what changed since the handoff)

- **GitHub recheck (live, read-only):** all four issues still **OPEN** with unchanged timestamps (#206, #209, #204, #210); `main` still `c48e307…`; no `work/*` or `review/*` branches on the remote; open PRs remain the unrelated #165/#163/#139/#46/#22; no matching implementation PR. Stored preflight evidence remains an accurate timestamped snapshot.
- **GitNexus EPERM blocker: RESOLVED in this runtime.** `detect-changes --scope all/staged/compare` completed for every lane and for the combined candidate (no `partial:true`, no `truncated:true`, no EPERM). All four lanes rebuilt/refreshed their own indexes and produced complete receipts.
- **Docker blocker: still BLOCKED, now differently.** `/var/run/docker.sock` **does not exist**, the daemon is inactive/disabled pre-boot, and `sudo` requires a password. No bypass was attempted. All Woo/DB/Redis/browser/runtime suites remain NOT_TESTED. The user is in the `docker` group, so if the daemon is started by the user, focused suites could run; this report does not start it.
- **Network/gh restored** (authenticated as MrDarkRoot). Used read-only only.
- All four clones still contain the exact frozen prepared bytes (SHA-256 verified against `STATE.json` before lane work); all four snapshot patches verify.

## 2. Lane results

| Issue / Branch | Implemented scope | Tests / evidence | Risks / deviations | Recommendation |
|---|---|---|---|---|
| **#206** / `work/206-selection` (clone `agent-A`) | Explicit include-subcategories in existing hashed plan context; shared resolver descendant selection + dedup + independent Preview freeze; informational authenticated Check-selection-count (no plan/job/worker); Admin toggle + form/review disclosure + `free-selection.js`; category raw window 2,000/2,001 distinct from the 1,000 final ceiling | Impl `c8cbf6c283545658add407745b3c2d605fcd8b14`; docs `fb2ecd4dd852cc4b394c25f3af316ced38092462`. `selection-count.php` 32 PASS; baseline negative control FAILs at the new opt-in as required; unit 2,607; sale 105; variations 73; admin-audit / no-replan / ownership / package / inventory / public / readme / historical / claim-matrix PASS; lint PASS; detect all+staged complete (10 files, 67 symbols, 25 flows). Impl bytes SHA-identical to frozen `A-snapshot.patch` (`31589065…`) | PR_FAST FAILs at the unchanged #170 UI-source evidence gate (expected; not weakened). Runtime Admin HTTP/integration/subcategory/browser/no-JS accessibility NOT_TESTED (Docker). Category raw-window distinction needs founder review, not silent support expansion | Keep prepared commits as-is. Do not merge before fresh UI evidence + runtime acceptance; approve the raw-window distinction explicitly |
| **#209** / `work/209-public-cache` (clone `agent-B`) | All shipped `Internal` cache dependencies removed (incl. string refs): verifier `invalidate` uses public cleanup only; `Product_Price_Snapshot::fresh_product` public read boundary (clean post/meta + targeted group invalidation before public type resolution); selector/snapshot/Admin/mutator read substitutions; durable + pure contract tests | Impl `56847070d90c84e180cf81cf60eb7a659fd5277a`; docs `7df7baf0f61f9226d99c622345de656381f207fe`; impl patch `3af490ae…`, lane patch `9c17d5f5…`. `cache-public.php` 72 PASS; unit 2,607; sale 105; variations 75; admin-audit / no-replan / package / inventory / public / readme / historical / claim-matrix / source-audit PASS; ownership + dependency PASS; `grep Internal/ProductCache` empty in shipped runtime; detect complete (15 files, 72 symbols, 31 flows) | **Deviation (documented, conservative):** extension/non-core resolved classes are refused as `unreadable_product_data`, and Apply/Undo classify that as `FAILED`/PENDING instead of `unsupported_product_type`/`UNSUPPORTED_PRODUCT_STATE` → retryable instead of terminal; no write and no eligibility path. **Doc-only extra files** beyond the frozen manifest: `FREE-PRICE-APPLY-CONTRACT.md` and `class-free-support-contract.php` docblocks (no constants, no support-limit change). Durable Woo/Redis/version-matrix/browser NOT_TESTED | Founder decides whether the refusal-classification deviation must be reclassified to `unsupported_product_type` before merge. Include the two doc-comment files in the merge. Runtime acceptance still required |
| **#204** / `work/204-live-progress` (clone `agent-C`) | Authenticated read-only `wp_ajax_writeleash_free_progress` (+ explicit nopriv refusal); job-bound nonce, capabilities + creator/approver/admin authorization, bounded offset/filter; endpoint reads only persisted plan/job/item/Undo state; isolated `free-progress.js` polling client (generation fence, single inflight, 15s timeout, hidden-page pause, 10/20/40 backoff, 4-failure stop, focus preservation); minimal asset allowlist/manifest/ownership entries; job/Undo view hooks | Impl `6a6a9ac91550c928101bebd2085874580a1c416a`; docs `a1b7f3a317abefe92cd1fce88775579e585df312`; lane patch `73ed2969…`. `progress-client.cjs` PASS; `progress-unit.php` PASS; admin-audit / package-preflight 43 / inventory / public / test-artifact 10 / ownership / dependency / readme / historical PASS; lint PASS (`sh -n run.sh` fails identically at baseline; `bash -n` PASS); detect complete (12 files, 136 symbols, 21 flows). Guard audit untouched except finite allowlist addition; negative controls reject injected `new $class` and unknown assets | PR_FAST FAIL #170 (expected). Real Woo poll audit, AJAX transport journey, browser keyboard/axe/session expiry, and focused-detail behavior NOT_TESTED. `render_undo_section` was HIGH pre-edit; only display hooks changed | Keep prepared commits. Do not claim live-progress acceptance until authorized browser/Woo runtime journeys run |
| **#210** / `work/210-i18n-preparation` (clone `agent-D`) | **PARTIAL — PREPARATION COMPLETE.** Raw-literal inventory tooling (PHP tokenizer + conservative JS), tooling tests, ~1.8 MB baseline inventory, `PREPARATION.md`, `IMPLEMENTATION.md`; official WP-CLI `i18n make-pot` evidence (phar 2.12.0, sha512 verified); no production Admin/JS edits; no catalog/manifest changes | Commit `b09fd5f226ea45e711352b8654e3ab3f09d0d1f3`; patch `328d8f6b…`. Tooling tests PASS; deterministic regeneration (same hash); baseline POT 989 B header-only (raw literals, as expected); `/tmp` fixture demonstrates 13 entries incl. plurals, `msgctxt`, JS format, translator comment, 5 placeholder warnings; ownership audit PASS; PR_FAST **PASS** on this lane (no Admin source change) | Inventory is not a translated catalog; JS scan includes comments/template limits; 9,436 candidates (2,623 distinct), machine strings dominate (noise documented, not deleted). Native catalog, pseudo/non-English flows, 0/1/many/long-label, browser, plan hydration NOT_TESTED | **Remains PARTIAL.** Final gettext conversion must follow accepted #204–#208 UI and use the documented make-pot workflow; no catalog change authorized yet |

## 3. Independent lead review findings (beyond agent self-reports)

- **Frozen-byte verification:** A's and C's implementation commits contain their 10/12 prepared files **byte-identical** to `STATE.json`; B's and D's trees match the frozen snapshot as their starting point, with B's completion delta inspected hunk-by-hunk.
- **B's `fresh_product` fix reviewed:** literal `new \WC_Product_Simple|\WC_Product_Variation|\WC_Product_Variable` only; other resolved classes refused **without instantiation** (asserted); non-product/mismatched-ID constructor result throws unreadable; `return false` only for genuinely absent type; cleanup order unchanged. The unchanged public-runtime audit (`new $var` ban) now passes for a legitimate reason.
- **B's mutator/verifier exception flow reviewed:** a refused `fresh_product` inside the mutator is caught by `\Throwable` → `FAILED`/PENDING, conservative; inside `Price_Cache_Verifier::verify()` it is converted to `CACHE_VERIFICATION_FAILED`. No half-write path.
- **C's guard change reviewed:** `public-runtime-audit.php` adds `free-progress.js` to the finite allowlist and checks an explicit three-asset reference list — a strict superset; the `new $var` / `$var::` / unknown-artifact rejections are untouched and negative-controlled.
- **Review catches:** (1) B modified two files outside the frozen manifest (doc-comment-only updates; constants unchanged — flagged, not blocking); (2) B's extension-refusal classification deviation (flagged for founder); (3) the predicted A/B test-prerequisite collision (below); (4) `sh -n run.sh` "failure" is baseline-identical (bash script) — not a regression.
- **No test, assertion, audit, or CI rule was weakened** in any lane or in the combined candidate. No GitNexus error was ignored or monkeypatched.

## 4. Combined disposable candidate (B → A → C → D)

- Clone `/tmp/writeleash-approved-20261008/combined-review`, branch `review/approved-four`, base `c48e307…`, **HEAD `7f47309740b4572fdf30dee8f333046b01b5a956`** (8 commits: 7 lane commits + 1 test-only integration commit).
- Cherry-pick order B → A → C → D. Exactly one textual conflict (`wordpress/tests/free/in-container.sh`, both lanes adding a test entry) resolved additively; `class-free-admin.php` and `class-product-selector.php` auto-merged and were verified as true unions by provenance hashing.
- **Integration fix (test-only, combined candidate only):** A's `selection-count.php` harness lacked the `WC_Cache_Helper` stub that B's `fresh_product` guard requires (the exact A/B fixture collision predicted in the handoff). Added `class WC_Cache_Helper { … }` only; all 32 assertions unchanged and passing. This fix must be carried into whichever branch merges second.
- Provenance: all lane-exclusive files hash-match their lane exactly; only the four expected union files differ (`class-free-admin.php` A+B+C, `class-product-selector.php` A+B, `free/in-container.sh` A+B, `selection-count.php` A + integration stub).
- Support ceilings unchanged: `MAX_JOB_PRODUCTS = 1000`, Woo `>=10.0.0 <12.0.0`, WP `>=7.0 <8.0`; A's raw category window is a query bound, not a support expansion.

**Combined validation (all receipts in `combined-evidence/`):**

| Check | Result |
|---|---|
| free contract/unit suites | unit 2,607; sale-price 105; variations 75; selection-count 32; cache-public 72 — **PASS** |
| Admin/JS | progress-client PASS; progress-unit PASS; admin-audit PASS; no-replan audit PASS |
| Release/package | package-preflight 43-file closure PASS; inventory 59 PHP PASS; public-audit PASS; readme PASS; historical-shim PASS; claim-matrix 15 topics PASS; i18n tooling PASS; test-artifact 10 tests OK |
| Ownership/dependency | `ownership.py --audit` PASS; selection → CODE_INTEGRATION `admin,jobs,journal,plan,undo` (release `acceptance` deferred by policy); dependency-audit PASS |
| Lint | all changed PHP/JS/CJS PASS; `git diff --check` clean; `sh -n run.sh` fails identically at baseline (`bash -n` PASS) |
| PR_FAST | **FAIL at the known unchanged #170 UI source/capture evidence gate only** (all preceding gates PASS). No evidence rehashed, no assertion edited, no gate removed |
| GitNexus | index rebuilt; `detect-changes --scope compare --base-ref c48e307…` complete — **39 files, 302 symbols, 55 affected flows, risk critical**; `--scope all` clean; no `partial`/`truncated` |
| Identity | patch `combined-candidate.patch` SHA-256 `a6a2f95726c82235349c3070943fd2bd1ab182d2387adad775b8f64d13c09717`; per-file hashes in `combined-file-hashes.txt`; candidate **never merged anywhere** |

## 5. Remaining blockers and incomplete criteria

1. **PR_FAST #170 UI evidence** on A/B/C/combined: requires fresh real runtime/browser capture through the repository-approved review process. Cannot be produced without a running WordPress/Woo environment; nothing was rehashed or weakened. Historical pre-change PR_FAST passing evidence does not cover these trees.
2. **Runtime acceptance NOT_TESTED for all four issues:** Woo/DB/Redis durable harnesses, real AJAX session journeys, browser keyboard/axe, no-JS flows, pseudo-locale flows. Docker daemon is not running; starting it is a user/founder action.
3. **#210 stays PARTIAL** — preparation only, by design.
4. GitNexus change receipts are complete but remain a static analysis; risk `critical` reflects shared Admin/worker/Undo flow traversal already surfaced pre-edit, not new authority.

## 6. Unresolved risks / additional tests required before acceptance

- Run the owning CODE_INTEGRATION suites (`admin`, `jobs`, `journal`, `plan`, `undo`) plus the new durable fixtures (`tests/durable/cache-public-integration.php`, `tests/admin/progress-integration.php`, `tests/admin/subcategory-integration.php`, `tests/admin/selection-http.php`) under the Docker Woo matrix once authorized, including default + Redis and both pinned Woo versions.
- Fresh real-browser evidence for #206 selection scope and #204 focus/live-update/accessibility, then regenerate the #170 reviewed capture through the approved process.
- Confirm the B refusal-classification decision (below) and, if kept, cover extension-product refusal messaging in Admin.
- Decide the disposition of the test-only private-cache prime at `wordpress/tests/admin/integration.php:729` (not shipped; pre-existing; outside #209's shipped-runtime scope).

## 7. Founder decisions requested

1. **GO / NO-GO** on any subsequent integration (merge) step. Nothing is merged; the combined candidate is disposable.
2. Accept or reject B's conservative refusal-classification deviation (`unreadable_product_data` / retryable FAILED instead of `unsupported_product_type` / terminal). If rejected, a typed unsupported refusal must replace it before merge **without** reintroducing dynamic instantiation.
3. Authorize the fresh #170 UI evidence capture process (and the runtime environment) needed to clear PR_FAST for A/B/C.
4. Authorize the bounded Docker/Woo runtime matrix for acceptance testing.
5. Confirm inclusion of B's two doc-comment-only files in the eventual #209 merge and of the combined candidate's one-line `WC_Cache_Helper` test stub with whichever branch merges second.
6. Approve A's category raw-window distinction (2,000/2,001 discovery bound vs 1,000 final ceiling) as within scope.

## 8. Compliance statement

Work stayed inside the authorized four scopes: bounded implementation, local tests, independent review, and one disposable combined candidate. No push, PR, merge, deployment, publication, WordPress.org/Woo submission, issue closure, or RELEASE_FULL dispatch occurred. The main checkout was not cleaned, reset, or modified; unrelated untracked files were preserved. No safety check, test, assertion, audit, or CI rule was weakened to obtain a PASS, and no sandbox access was bypassed.

**Artifacts:** per-lane final reports and implementation docs in `lane-reports/`; combined patch, hashes, detect receipts, validation logs, conflict-resolution notes (`INTEGRATION-NOTES.md`), integration-commit diff (`integration-commit-7f47309.patch`), and #209 deviation detail (`209-deviations.md`) in `combined-evidence/`. Machine-readable state: `FINAL-STATE.json`; artifact map: `EVIDENCE-INDEX.md`; integrity manifest: `SHA256SUMS`.
