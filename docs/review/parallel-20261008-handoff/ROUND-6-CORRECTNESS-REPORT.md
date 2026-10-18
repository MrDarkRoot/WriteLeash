# Round 6 — issue-based correctness closure (2026-10-08)

Priority: resolve the reproducible #109 post-COMMIT observation failure affecting PR #220; hold #219/#221; keep #222 preparation-only. **No merge, deploy, publish, issue closure or protection change. Founder retains GO/NO-GO.**

Preflight: `origin/main` = `c48e307efe7750d0fb36015c39d50a34675f9579`; live issue bodies byte-identical to the stored evidence (criteria unchanged). PR heads at round start: #219 `eaad5dd0…`, #220 `029a0804…`, #221 `fd0dc73e…`, #222 `079f5dad…` — all Draft.

## Result table

| Issue / PR | Acceptance criterion | Actual evidence | Result | Fix or remaining blocker |
|---|---|---|---|---|
| **#209 / #220** | No shipped Woo Internal dependency (incl. strings) | `grep ProductCache/Internal/wc_get_container` empty; source audit PASS | **PASS** | — |
| #209 / #220 | Concurrent edit → fresh Preview/Apply/observation/Undo, newer edits preserved | Real `#108`/`#109`/`#110` gates both engines × default+Redis; the intermittent post-commit observation defect found here was root-caused and fixed (`c2e3f61`) | **PASS** | — |
| #209 / #220 | Focused default + persistent tests (simple/variation/parent/rollback/recovery/current-price) | `#108`/`#110` gates PASS; `cache-public-integration.php` incl. new R4 regression PASS (64 assertions) | **PASS** | — |
| #209 / #220 | Versions before/after the former ProductCache boundary | Source-verified 9.9.7 / 11.0.1 / 11.1.2; runtime version-swap lives in the deferred acceptance matrix | **PARTIAL** | Version-matrix runtime axis not run this round |
| #209 / #220 | Missing support/freshness fails visibly and conservatively | Durable fixtures + real gates: refusals typed, zero writes, NEEDS_REVIEW retained | **PASS** | Transient-retry policy decision (below) |
| **#206 / #219** | Direct default + explicit descendants, nested, dedup, variations, limits, frozen Preview, no reselect, old-plan hydration | Unchanged code; re-verified: `selection-count` 63, `#107` two-engine planning gate PASS, `#111` Admin E2E 838×2 (default+Redis), no-replan audit PASS, candidate PR_FAST PASS | **PASS** | #170 per-PR gate needs the integration strategy |
| **#204 / #221** | Live Apply/Undo advance without reload; distinct states; read-only polling; auth; focus/a11y; no-JS | Unchanged code; re-verified: live polling PASS (0→10 changed, 22→12 remaining, no reload), `#170` both engines 253 assertions, `progress-integration` PASS default+Redis, `#111` 838×2, client/unit PASS | **PASS** | #170 per-PR gate needs the integration strategy |
| **#210 / #222** | Preparation-only inventory/tooling/docs | Unchanged; PR_FAST + CI_COVERAGE green on its head; #210 remains PARTIAL | **PASS (preparation)** | Full conversion is a separate future PR (plan below) |

## 1. Exact SHAs

- `origin/main`: `c48e307efe7750d0fb36015c39d50a34675f9579` (unchanged)
- #219 `work/206-selection`: `eaad5dd0b1703997667474edb55f928fd2e75d0d`
- #220 `work/209-public-cache`: **`c2e3f6167879bbc5ac7ba9c798cbb43ce672608d`** (new fix commit)
- #221 `work/204-live-progress`: `fd0dc73e8a1598da1c894a6be24be28f860c286d`
- #222 `work/210-i18n-preparation`: `079f5dad148a8718586db05b4abc322047908710`
- Integrated candidate `review/approved-four`: **`3b4ab5ec…`** (tree recorded in `round6-evidence/combined6-tree.txt`)

## 2. #109 original failure and reproduction

- **Failing assertion:** `wordpress/tests/jobs/integration.php:1292` — `different jobs same product: one applies, one conflicts, no deadlock`; actual `["COMPLETED_WITH_ISSUES","NEEDS_REVIEW"]` vs expected `["COMPLETED","COMPLETED_WITH_ISSUES"]`.
- **Historical:** B2 (under load) and B4 (isolated) failed; B3 and several instrumented runs passed → nondeterministic.
- **New reproduction:** with the exact runner (namespace + pinned scripts) and an instrumented disposable plugin, the failure reproduced **5/5 runner attempts**; host-side manual sites never reproduced it (13+ passes), which localized the trigger to the real runner flow.

## 3. Confirmed root cause

**Stale per-request `post_meta` blob cache served to the post-COMMIT public Woo read.** The winning job's write committed and the journal recorded `APPLIED / WOO_CRUD_VERIFIED`; the independent observer's raw DB read returned the committed target, but `Product_Price_Snapshot::fresh_product()`'s constructor read was served a blob primed with the pre-write value, so the price comparison threw `CACHE_VERIFICATION_FAILED` → item `NEEDS_REVIEW` (conservative: one save, no overwrite, price correct).

Value-level trace (`round6-evidence/rootcause-value-diagnostics.log`):
`raw_db=90.00` (observer DB) vs `cache_meta=100.00` (in-process blob) vs `fresh_regular=100.00` (Woo read) while `journal_state=APPLIED` with evidence `80/90`.
Step probes (`rootcause-freshproduct-step-probes.log`) show the blob primed by the pre-write `fresh_product` and by `wc_delete_product_transients` inside `invalidate()`, surviving the post-commit eviction in this interleaving.

## 4. Instrumentation findings (test-only, disposable)

- Throw-site logging identified the exact throw (`class-price-cache-verifier.php:267`, the field-set price comparison).
- Raw-vs-cache probes distinguished DB truth from cache staleness; connection/session probes confirmed the observer was a distinct session and the DB was correct.
- WP-core cache tracers (disposable site copy) plus step probes localized priming to the meta blob; no InnoDB deadlock (error log count 0), no lock-wait messages.
- Instrumentation lived only in a disposable plugin/site copy; the repository gained only the production fix, the regression, and documentation.

## 5. Regression before/after

- **Deterministic emulation:** a `get_post_metadata` filter serves the pre-write blob until the second public product read completes (then normal), reproducing the exact observed condition.
- **Standalone:** UNFIXED → `OBSERVE FAIL Price_Apply_Error CACHE_VERIFICATION_FAILED`; FIXED → `OBSERVE PASS journal=APPLIED` with the retry reading the real value (`regression-before-after.txt`).
- **Fixture-level (`tests/durable/cache-public-integration.php` R4):** UNFIXED bytes fail at `class-price-cache-verifier.php:268`; FIXED bytes PASS (64 assertions, zero extra saves, price unchanged).
- **Fix:** `Price_Cache_Verifier::observe()` keeps independent storage-vs-journal comparison as authority; on a public-read mismatch it evicts targeted caches (`wp_cache_delete` + `wp_cache_delete_multiple` for `post_meta`/`posts` + `product_<id>` group) and reconstructs the product **exactly once**; a persistent mismatch still throws. No observation removal, no success reclassification, no write retry, no global flush.

## 6–7. Default/Redis and MySQL/MariaDB results (post-fix)

- Jobs gate: **PASS** both engines × default + Redis, including the formerly-flaky scenario; validated twice on the fixed branch (`fix-validated-runner-attempt{1,2}-PASS.log`).
- Durable gate: **PASS** both engines × default + Redis (`#108 … gate: PASS`).
- Integrated candidate `3b4ab5e`: combined jobs gate **PASS**; combined durable gate **PASS** (`combined-jobs.log`, `combined-durable.log`).

## 8. Apply/Resume/Undo correctness

Real gates confirm: one Woo save per item under duplicate Resume, `ALREADY_APPLIED` recovery, conflict preservation with newer edits intact, kill-before/after-COMMIT recovery, Undo conflict-aware protection, zero blind overwrites. The fix is read-only observation recovery and does not touch write/fence/journal paths.

## 9. #170 source/capture validity after the fix

The fix touches `class-price-cache-verifier.php`, the durable fixture and docs — **not** the three fingerprinted UI files. Candidate `proof.json` source hashes still MATCH; `asset-audit.php` PASS; full `pr-fast.sh` **PASS** on the candidate. Individual PRs #219/#220/#221 still fail #170 on their isolated heads (integration strategy below); #222 remains green.

## 10. Combined candidate and integration results

- Rebuilt from exact PR heads with the new fix cherry-picked: `review/approved-four` @ `3b4ab5e`; fast battery PASS (unit 2,607 · sale 105 · variations 75 · selection-count 63 · cache-public 73 · progress-client 9,996 cases · progress-unit 148 · admin-audit · no-replan); `#170` PASS; `PR_FAST` PASS.
- Integrated runtime: **jobs PASS**, **durable PASS** (both engines × default + Redis).
- The candidate remains local/disposable; not pushed; no fifth PR.

## 11. Transient retry policy decision (unchanged; DECISION_REQUIRED)

- **Option A (implemented):** stable unsupported class → terminal; transient `unreadable_product_data` → bounded retry from durable `PENDING`; every retry re-runs journal/locks/preconditions; conflicts/uncertain never auto-retry. Runtime evidence shows no unsafe re-execution.
- **Option B:** make transient unreadable terminal too (narrower divergence from pre-#209 baseline; loses transient recovery).
- **Recommendation:** accept Option A; **founder selection still required**; the #109 fix does not alter this policy.

## 12. Full #210 work remaining (outside preparation)

Separate future PR after the accepted Admin UI settles: gettext conversion under `writeleash`; PHP/JS extraction incl. plural/context; translated accessibility text; pseudo/non-English flows; canonical money/plan-material stability; public distribution catalog/asset requirements. #222 stays `PARTIAL — PREPARATION COMPLETE`.

## Verdicts

| Issue / PR | Code | Acceptance |
|---|---|---|
| #209 / #220 | **PASS** (defect root-caused, fixed, regression-backed) | **PARTIAL** (runtime gates now green incl. integrated; version-matrix axis + founder policy decision remain) |
| #206 / #219 | **PASS** (unchanged; no regression) | **PARTIAL** (#170 per-PR gate; MariaDB admin axis) |
| #204 / #221 | **PASS** (unchanged; fix holds) | **PARTIAL** (#170 per-PR gate) |
| #210 / #222 | **PASS** (preparation scope) | **PARTIAL** (by design) |

**Integration strategy (unchanged from Round 5, updated heads):** Option 1 sequential per-step (merge #222 first; then update + regenerate #170 + re-run owning runtime for #220 → #219 → #221), or Option 2 a single authorized integration PR from the validated rebuild — required-check rules (`PR_FAST`+`CI_COVERAGE`, strict, enforce_admins) make a per-PR #170 refresh unavoidable under Option 1. No protection changes.

**Stop conditions:** none triggered — no overwrite, no replay, no false success, no blind retry, no Undo regression, journal authority preserved, no Internal API reintroduced, no unauthorized privileges.

**Recommendation:** #220 **GO FOR REVIEW** (fix validated; policy decision reserved) · #219/#221 **GO FOR REVIEW** subject to integration strategy · #222 **GO FOR REVIEW (preparation)** · integrated candidate **GO FOR REVIEW**. No merge action taken; await founder authorization.
