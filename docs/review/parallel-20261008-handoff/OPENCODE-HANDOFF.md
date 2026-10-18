# OpenCode handoff — continue the four approved WriteLeash coding lanes

You are the WriteLeash Lead Coding Agent. Resume the existing work, spawn four independent coding sub-agents, finish bounded implementation/local validation, review every diff, and produce the final evidence report. Do not restart these issues or discard prepared work. The founder retains final GO / NO-GO authority.

The previous session stopped at the user's request because their Codex quota ran out. A and C completed code preparation but could not commit. B and D were interrupted mid-implementation. No implementation commit, push, PR, merge, release dispatch, deployment, publication, or issue closure happened.

## Read these first

- Workspace: `/home/vanta/Projects/WriteLeash`.
- Repository: `https://github.com/MrDarkRoot/WriteLeash`.
- Handoff folder: `/home/vanta/Projects/WriteLeash/docs/review/parallel-20261008-handoff`.
- `STATE.json`: exact branch/HEAD, current staged/unstaged/untracked files, per-file hashes, and complete snapshot-patch hashes for all four lanes.
- `evidence/preflight.json`, `evidence/issue-{204,206,209,210}.json`: live GitHub preflight and full issue bodies captured before permissions/network changed.
- `A-IMPLEMENTATION.md` and `C-IMPLEMENTATION.md`: detailed criterion accounting and commands, including FAIL/BLOCKED/NOT_TESTED evidence.
- Read workspace `AGENTS.md` and `.github/ci/CI-POLICY.md`. Their graph and CI gates remain mandatory.

The exact baseline fetched from live `origin/main` was:

`c48e307efe7750d0fb36015c39d50a34675f9579`

All four independent clones and the empty combined-review clone still have this exact HEAD. **There is no implementation HEAD SHA yet.** The code lives in staged/unstaged/untracked changes. Never infer that branch HEAD includes it.

Live preflight found all four issues OPEN, no matching implementation PR/branch, and main source still missing the requested work. Open PRs were unrelated #165/#163/#139/#46/#22. Recent PR checks included success for the merged #203/#202 work; these historical checks do not certify these changed trees. A later live main recheck failed after network restrictions changed. Recheck live issues, main, branches, PRs and CI if your environment permits it; if main advanced, preserve the current baseline work and justify any subsequent rebase.

## Independent environments and current state

| Agent | Issue / branch | Existing clone | State |
|---|---|---|---|
| A | #206 / `work/206-selection` | `/tmp/writeleash-approved-20261008/agent-A` | 10 files staged; implementation prepared, review/runtime/commit gates blocked |
| B | #209 / `work/209-public-cache` | `/tmp/writeleash-approved-20261008/agent-B` | 13 changed/new files; unfinished freshness helper, fixtures, final validation and report |
| C | #204 / `work/204-live-progress` | `/tmp/writeleash-approved-20261008/agent-C` | 12 files staged; implementation prepared, review/runtime/commit gates blocked |
| D | #210 / `work/210-i18n-preparation` | `/tmp/writeleash-approved-20261008/agent-D` | Three untracked files; initial tokenizer/tooling/inventory, not finished or reviewed |

`/tmp/writeleash-approved-20261008/combined-review`, branch `review/approved-four`, is an empty local validation candidate at baseline. No branches or patches have been integrated there.

Use the existing independent clones. Do not share an uncommitted working tree. If `/tmp` was cleared, recreate independent clones at the exact baseline and apply the corresponding `A-snapshot.patch`, `B-snapshot.patch`, `C-snapshot.patch`, `D-snapshot.patch` from this handoff folder. These snapshots include the new untracked files, not just tracked diffs. Check `STATE.json` hashes before relying on them. Do not reapply patches to clones that already contain the changes.

The original main checkout contained unrelated untracked `.claude/`, `.playwright-mcp/`, `AGENTS.md`, `CLAUDE.md`, `docs/review/188/`, `wordpress/icon/`, and two full-audit reports. Preserve them. The handoff folder itself is newly untracked documentation/evidence. Do not clean/reset the main checkout or touch unrelated labs/worktrees.

There were only three concurrent sub-agent slots alongside the lead in Codex; D began after A finished. OpenCode may run all four simultaneously if supported. A/C should verify and finish their own gates rather than recode; B/D need substantive completion.

## Non-negotiable invariants / authorization

Authorized: only the four scopes below, bounded implementation, local tests, independent code preparation, and a disposable combined validation candidate. No unrelated issues, Pro work, architecture rewrite, generic framework, extra mutation engine, increased supported ceilings, or unsupported compatibility expansion.

Preserve exact Preview/reviewed population; immutable frozen plans; absolute deterministic monetary values; approval bound to the exact plan; no execution-time selector rerun; no blind overwrite of supported newer edits; persistent job/item/journal truth; protected bounded Resume; no intentional reapplication of completed operations; truthful partial/conflict/uncertain states; History and eligible conflict-aware Undo; public Woo CRUD writes; existing support limits and release evidence. Do not weaken a safety check/assertion/CI rule to obtain PASS.

No automatic merging into main. No merge, deployment, publishing, WordPress.org/Woo Marketplace submission, RELEASE_FULL dispatch, or external messages. PR creation is conditional on actual working authorization; otherwise return branches/diffs and local PR-ready descriptions. Founder approval is required for the next repository-changing integration step after preparation. Local combined review does not authorize merging it into main.

## Agent A — #206 selection (prepared; verify and finish)

Implemented:

- `Price_Selection_Spec::category($id, $include_children = false)` preserves old direct-members default/shape and optional descendant boolean in existing hashed category plan context.
- Resolver uses public WP taxonomy descendant selection, deduplicates expanded variation targets, independently freezes actual Preview IDs.
- Native authenticated **Check selection count** action; informational only; no plan/job/approval/worker creation. Delegation goes through existing `Product_Discovery::selection_count`, preserving the unchanged Admin boundary audit.
- Empty/unreadable/missing/inaccessible/over-limit states; count edits become stale; actual HTTP `post_input` includes the toggle.
- Narrow Admin form/review disclosure and selection JS edits.
- Fixed category-only raw candidate sentinel of 2,001 rows, refusing above 2,000 before final expansion. This admits 1,000 final variations plus at-most-1,000 core parents, while final targets/approval remain at 1,000; IDS/SKU ceilings unchanged. No-child variable parent remains one unsupported target. This distinction needs review, not silent support expansion.

PASS reported: new stub contract 32 assertions, existing unit 2,607, sale 105, variation 73, unchanged Admin/no-replan/ownership/package checks. Negative control against baseline fails at the missing opt-in. Runtime fixtures cover nested taxonomy, overlaps, variations, count/Preview divergence, stale category additions after freeze, limits, unreadable/denied targets, old direct hydration, and actual HTTP/no-JS toggle transport. Those fixtures are **NOT_TESTED**, not runtime PASS.

Full file list/report: `agent-A/docs/review/206/IMPLEMENTATION.md`. A snapshot hash recorded in `STATE.json`; original `evidence/A-reviewed.patch` is identical to the frozen A snapshot.

Pre-edit `post_input` impact was **CRITICAL** (shared Preview/approve/Resume/Undo/export/render callers). Edit is a single additional field; preserve the old inputs. Other selection impacts were LOW, with explicit text confirmation where graph calls were UNKNOWN/lower-bound.

## Agent B — #209 cache correctness (unfinished; priority)

Goal: remove ALL shipped Woo `Internal` class dependencies, including string references, while preserving fresh Preview/Apply/Undo/current observations, targeted eviction, parent lookup/ranges, verification, transaction/journal/crash recovery correctness. No global cache flush, direct raw Woo price SQL writes, private wrapper, verification removal, or uncertain-as-success shortcut.

Prepared changes remove Internal ProductCache access from `Price_Cache_Verifier::invalidate` and `Free_Admin::product_observation`. Source inspection of cached Woo 9.9.7, 11.0.1, 11.1.2 established public `clean_post_cache` hooks, public `WC_Product_Factory::get_product_type/get_product_classname`, and core CRUD constructors. Hook eviction alone was insufficient for a guaranteed fresh primed instance, so a small `Product_Price_Snapshot::fresh_product` public read boundary was added. It cleans post/meta and invalidates the targeted `product_<id>` cache group **before** public type resolution. The public type lookup itself caches by that group; omitting its invalidation can read stale product type.

Read substitutions cover selector `resolve/parent_of/expand_variable_parents`, snapshot fallback parent read, Admin observation, verifier sync/independent observations, and one-line Apply/Undo read sites. Writes, locks, fences and verification must stay unchanged. Snapshot is the shared read boundary; do not move it into a generic new service or introduce a selector-to-execution dependency.

**Immediate unresolved defect at interruption:** `fresh_product` still contains `new $class($id)`. The unchanged public-runtime/package audit rejects dynamic instantiation. Do not relax that guard. The last agreed direction was literal constructors for `WC_Product_Simple`, `WC_Product_Variation`, `WC_Product_Variable`; preserve conservative refusal of mapped extension classes rather than adding an unproven fallback. If diagnostic classification of unsupported extension objects becomes unreadable instead of unsupported, report the exact deviation and verify no extension can become eligible. A non-product/mismatched-ID constructor result must throw unreadable, not be mislabeled absent. Confirm source/current tests before applying this direction.

Prepared real fixture `wordpress/tests/durable/cache-public-integration.php` primes real optional instance caching via public APIs, has separate-request supported edits and negative controls, and checks Admin observation, stale Apply conflict, fresh Preview, verification, Undo preconditions/conflicts, variations/parent ranges, rollback repair and suspended invalidation. **Review whether standalone Preview happens without an earlier Admin/cache cleanup**: that was a lead finding and must not be masked. Also test missing hooks/read guarantees conservatively. Feature-on probe is wired into existing durable harness; do not treat replacing private fixture assertions with public counters alone as equivalent without real negative controls.

New pure contract `wordpress/tests/free/cache-public.php` and adaptations to old fake constructors/factory/cache stubs were in progress. Preserve existing assertions, including unreadable and extension refusals. Latest test state was not finalized; do not carry early PASS forward. `docs/review/209/IMPLEMENTATION.md` and exact final evidence/report still need completion. HIGH/CRITICAL impacts on cache/read/mutator boundaries were surfaced; verify receipts in the working clone/evidence.

Pinned cached plugin ZIPs are read-only at original workspace `wordpress/tests/admin/cache` (Woo11.1.2, Woo11.0.1, Redis2.7.0) and `wordpress/tests/research/.cache` (Woo9.9.7). Verify their hashes before source/runtime use. No actual default/Redis/Woo runtime acceptance was demonstrated.

## Agent C — #204 live progress (prepared; verify and finish)

Implemented authenticated `wp_ajax_writeleash_free_progress` and explicit unauthenticated denial via the same gated handler: user 0 immediately receives generic 403 before repository reads. Require capabilities, job-bound nonce, bounded offset/filter, and existing creator/approver/administrator authorization. Refuse unauthorized cross-actor reads without data. Check actual HTTP transport, not just doubles.

Endpoint reads ONLY persisted plan/job/item/Undo state. No catalog reads/cache eviction, worker/Resume invocation, product/job/Undo mutation, or schema install/migration. Existing protected mutation handlers remain the authority for separately clicked actions. Missing saved evidence disables actions and polling.

Isolated `free-progress.js` updates counters/outcomes/actions, preserves page-load current-price cells, labels new filtered rows as needing reload for current prices, handles stalled/partial/error/session states, records last successful refresh, prevents late/out-of-order updates using request generation, permits one inflight request, times out at 15 seconds, pauses hidden pages, backs off 10/20/40 seconds, stops after four failures and terminal/inactive/stalled jobs. Keep native refresh/no-JS behavior.

Lead-identified focus/staleness fixes were applied: initial volatile notices are updated/hidden; focused actions disabled but retained until blur; focused outcome/support details and disappearing filtered links preserved with stale-detail notice; disappearing focused Next link retains node, blocks activation and hides after blur; unchanged announcements suppressed. Verify these in a real browser, since deterministic DOM tests do not prove accessibility/focus behavior.

PASS reported: shipped-client deterministic JS tests, PHP endpoint boundary tests with repository doubles, unchanged Admin audit, package/inventory/negative public audits, 10 artifact-builder tests, ownership/dependency/source checks. Real running Apply/Undo/browser/HTTP/session/keyboard/axe checks are NOT_TESTED. New runtime fixture is wired into existing Admin suite. No full acceptance claim.

New asset has explicit manifest, public asset reference/allowlist and ownership entries. This is an extension of existing package closure, not permission to weaken unknown-asset/dynamic-instantiation guards. Preserve released ZIPs/hashes/evidence.

Full file list/report: `agent-C/docs/review/204/IMPLEMENTATION.md` (also copied as `C-IMPLEMENTATION.md`). Existing `render_undo_section` impact was HIGH; only display hooks changed. Complete patch: `evidence/C-204-live-progress.patch` (same frozen contents as C snapshot).

## Agent D — #210 PREPARATION ONLY (unfinished)

Only inventory/tooling/docs/tests, never mass-edit active Admin PHP/JS. At interruption the clone had:

- `wordpress/release/i18n-inventory.php` — initial PHP-tokenizer-backed literal inventory plus conservative JS inventory; **not a translated catalog**.
- `wordpress/tests/release/i18n-inventory-cases.php` — initial tooling tests, not verified by the lead.
- `docs/i18n/baseline-literals.json` — generated broad baseline inventory (~1.8 MB), not yet reviewed for completeness/noise/accuracy.

Planned but not finished: `docs/i18n/PREPARATION.md`, `docs/review/210/IMPLEMENTATION.md`, full tooling validation and handoff evidence. Review existing partial files before continuing. Keep tooling minimal; use official WP-CLI `i18n make-pot` extraction workflow instead of inventing a gettext parser. Raw English literal inventory is not proof that gettext/plural/context/JS extraction captures merchant strings.

Cover merchant PHP/JS/accessibility strings across setup/errors, selection, Preview, Apply, progress, conflicts, History, export/CSV and Undo. Strategy is text domain `writeleash`; document gettext/plural/context/complete sentences/placeholder translator comments and supported JS `wp-i18n` plus `wp_set_script_translations` requirements. Never translate machine codes, operation IDs, plan hashes, canonical money or CSV machine keys. No shipped catalog/manifest changes were authorized by this lane yet. Inventory finalized accepted A/C UI strings in follow-up; the baseline inventory does not certify those unmerged changes.

Report **PARTIAL — PREPARATION COMPLETE**, not full #210 implementation or closure. Pseudo/non-English full-flow 0/1/many/long-label, native extraction/catalog and browser criteria remain NOT_TESTED unless separately demonstrated.

## Real environment blockers from Codex (recheck; do not bypass)

The user changed permissions mid-session: original `.git` became read-only; only workspace and `/tmp` writes allowed, network restricted. Independent clones were created under `/tmp` so they retain writable private Git metadata.

1. Docker socket: `permission denied while trying to connect to the docker API at unix:///var/run/docker.sock`. No Woo/DB/Redis/browser runtime matrices ran. Do not bypass denied sandbox access. If OpenCode legitimately has access, run the existing focused owning suites; otherwise retain NOT_TESTED/BLOCKED and prepared fixtures.
2. Mandatory GitNexus `detect-changes --scope all` and `--scope staged` returned `Git diff failed: spawnSync git EPERM`. Direct tool-invoked Git diff works; Node child-process probes can return status 0/full stdout **and an EPERM error**, so GitNexus's unchanged `execFileSync` throws. Sometimes CLI exit is 0 despite the error body. The lead also reproduced the issue with normal Node20/22. Do not ignore/patch the error, monkeypatch the tool, or emulate a clean result. This gate **blocked all commits**. If OpenCode's normal authorized runtime resolves it, rerun genuine complete graph gates before committing. Otherwise return patch identities with HEAD still baseline, no fabricated implementation SHA.
3. `PR_FAST` on A/B/C fails the unchanged reviewed #170 source/capture hash in `.github/ci/asset-audit.php`. Baseline PR_FAST passed. Runtime Admin changes necessarily invalidate historical UI-source evidence. **Do not rehash old screenshots/proof, edit assertions, or remove the gate.** Legitimate fresh runtime/browser evidence and the repository-approved review process are required. Keep the failure logs and report FAIL/BLOCKED until resolved. Independent package/source checks are not a full PR_FAST PASS. D alone should preserve runtime source and can run baseline-like PR_FAST.
4. Live GitHub recheck failed after network restrictions; use stored live preflight evidence as a timestamped snapshot, not current certainty.

No heavy historical/release matrix or RELEASE_FULL was authorized. Do not dispatch them to manufacture progress.

## GitNexus use

Installed cached CLI:

`/home/vanta/.npm/_npx/e46929201c1128dd/node_modules/gitnexus/dist/cli/index.js`

Normal invocation:

```sh
cd /tmp/writeleash-approved-20261008/agent-A
export GITNEXUS_HOME="$PWD/.gitnexus-home"
node /home/vanta/.npm/_npx/e46929201c1128dd/node_modules/gitnexus/dist/cli/index.js status
node /home/vanta/.npm/_npx/e46929201c1128dd/node_modules/gitnexus/dist/cli/index.js context symbolName --repo WriteLeash
node /home/vanta/.npm/_npx/e46929201c1128dd/node_modules/gitnexus/dist/cli/index.js impact symbolName --direction upstream --repo WriteLeash
```

Use each clone's own registry; inspect `.gitnexus-home/registry.json` for its actual storage path (A uses `.gitnexus-local`; others may differ). Initial copied indexes were rejected as foreign. A/B/C rebuilt actual indexes; D began rebuilding. Confirm byte/index/runner freshness before trusting a graph. Do not query the main checkout's registry for a clone diff. Auto `run.cjs` tried network/package cache writes and failed after restrictions; direct cached CLI avoids that install path without bypassing permissions.

Graph first for named symbols, flows, dependencies/callers; text confirmation only for UNKNOWN/empty/literals/unresolved boundaries. MUST impact before editing every function/class/method, report callers/processes/risk, warn HIGH/CRITICAL. UNKNOWN is unresolved. Refresh after relationship changes; MUST run genuine complete `detect_changes` all/staged before a commit and exact baseline compare for regression review. `partial:true`/`truncated:true` are not clean. No commit when graph gates are blocked.

## Ownership, collisions and integration review

- A: category/selection logic, bounded count, selection tests; Admin `post_input` toggle, build/retain/process selection, selector form, narrow Preview category disclosure; `free-selection.js`.
- B: cache verifier and fresh snapshot read boundary, narrow current observation, narrow selector/public discovery fresh reads, one-line mutator read substitutions, cache regression tests.
- C: Admin boot/assets/progress endpoint/job/Undo display hooks, isolated polling JS and focused tests; minimal new asset package/ownership registration.
- D: translation inventory/tooling/docs/tests only; no production Admin edits.

Actual shared production files: `class-free-admin.php` (three agents, mostly separate methods), `class-product-selector.php` (A/B within resolve/parent/variation expansion). Check complete STATE manifests for any additional B public-discovery or harness changes. Shared test prerequisites may collide when B's new freshness APIs meet A's fake Woo constructors.

Planned local combined-validation order: **B → A → C → D**, after independent review. Preserve B fresh-read calls and A query scope/limits/dedup; never take a whole-file side to resolve overlap. A/C are independent baseline patches, not branches already rebased on B. Re-run owning selection/cache/progress checks after semantic combination. If commits remain blocked, combine exact reviewed patches only in the separate disposable candidate and record its full diff/file hashes, not a nonexistent implementation SHA. Do not touch main.

Lead must challenge every unsupported PASS and inspect complete diffs, including new untracked files. Remaining review work includes B package-safe freshness implementation, all B tests, D tooling accuracy/size, A+B constructor/test compatibility, combined source safety, and actual runtime/browser coverage.

## Validation and deliverables

Use established PR_FAST and ownership-selected CODE_INTEGRATION. Run `python3 .github/ci/ownership.py --audit` and classify the complete changed-file set. A/B source owners select plan/journal/jobs/undo/admin; C selects admin; ordinary acceptance is deferred release ownership under existing cost policy. Do not weaken ownership or expand to heavyweight historical/release suites just to demonstrate progress. Check actual classifier results, not this description alone.

Relevant existing commands include `bash .github/ci/pr-fast.sh`, `php wordpress/tests/free/{unit,sale-price,variations}.php`, A `selection-count.php`, B `cache-public.php`, C `php wordpress/tests/admin/progress-unit.php` and `node wordpress/tests/admin/progress-client.cjs`, unchanged Admin/no-replan audits, package/inventory/public negative checks, and actual selected suite entrypoints when authorized runtime is available. Native extraction/accessibility/Woo behavior requires actual native/runtime evidence. A passing stub/mock proves only its bounded contract.

Each agent returns: issue/scope, exact base, exact implementation head **or clearly no commit/current HEAD plus patch hash**, independent branch/clone, complete files, summary, PASS/FAIL/NOT_TESTED per acceptance criterion, commands and actual results, unrun reasons, safety effects on Plan/Conflict/Resume/History/Undo, support claims, exact deviations, concrete blockers, PR status. Retain failure evidence.

Final lead report table:

`Issue / Branch | Implemented scope | Tests / evidence | Risks / deviations | Recommendation`

Then summarize independent reviewability; incomplete criteria; actual overlaps/integration order; unresolved correctness/security risks; additional tests; founder decisions. #210 must remain PARTIAL. Stop after code preparation, local validation and review report. Founder owns final GO/NO-GO and subsequent repository-changing integration.
