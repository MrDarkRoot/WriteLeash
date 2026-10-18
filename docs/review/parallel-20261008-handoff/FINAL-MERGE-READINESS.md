# Final merge-readiness report — PRs #219–#222 (Round 5, 2026-10-08)

Verification round: evidence integrity, #109 failure analysis, #170 acceptance, individual-vs-combined CI resolution, integration procedure and the #220 semantics decision package. **No merge, no issue closure, no deploy/publish, no CI/protection changes. PRs remain Draft. Founder retains GO/NO-GO.**

Source of truth at verification time: `origin/main` = `c48e307efe7750d0fb36015c39d50a34675f9579`; PR heads unchanged from Round 4 — #219 `eaad5dd0b1703997667474edb55f928fd2e75d0d`, #220 `029a08040da0fab06bfea2a8d174aeba3d72f2d4`, #221 `fd0dc73e8a1598da1c894a6be24be28f860c286d`, #222 `079f5dad148a8718586db05b4abc322047908710`; all Draft; zero maintainer comments/reviews; no commits added after Round 4.

## A. Evidence integrity

- **Manifest:** `SHA256SUMS` was regenerated to cover **every file** in `docs/review/parallel-20261008-handoff/` (previously 157 deliverable files; now the whole folder). `sha256sum -c` → all entries OK; `comm` against the on-disk file list → **0 missing / 0 extra**. Receipt: `final-merge-evidence/manifest-verify.log`.
- **Evidence-to-source mapping (independent, from the still-present staged plugin trees):** every distribution-manifest file (42/42) in each site's `wp-content/plugins/writeleash` was hashed against `git show <claimed HEAD>:wordpress/writeleash/<file>`:
  - `/tmp/wl107-{mysql,mariadb}` (#107 free suite) → **PR #219 head `eaad5dd`**, 0 mismatches;
  - `/tmp/wl108-{mysql,mariadb}` (#108 durable) and `/tmp/wl110-{mysql,mariadb}` (#110 undo) → **PR #220 head `029a080`**, 0 mismatches;
  - `/tmp/wlruntime/sites/wl111` (#111 admin) and `wl170` (#170 browser) → **candidate `330859f`**, 0 mismatches.
  Receipt: `final-merge-evidence/evidence-source-mapping.txt`.
- **jobs #109 mapping:** the B4 site overwrote B3 (same runner, same `agent-B` HEAD `029a080`); both runs are mapped by runner+clone identity, and B4's site is on-disk verifiable. Stated explicitly rather than inferred silently.
- **Tested results matrix:**

| Evidence | Source SHA | Environment | Actual outcome | Integrity | Verdict |
|---|---|---|---|---|---|
| `logs/free-A-PASS.log` | #219 `eaad5dd` | WP 7.1.2 / Woo 11.1.2 / MySQL 8.0.44 + MariaDB 10.11.15 | `#107 pinned two-engine Woo planning contract PASS` | manifest OK; site tree match | **PASS** |
| `logs/admin-integration-mysql-{default,redis}-PASS.log` | candidate `330859f` | MySQL, default + Redis 7.4.2 | `#111 Free Admin workflow E2E: PASS (838)` ×2 | manifest OK; site tree match | **PASS** |
| `logs/admin-progress-mysql-{default,redis}-PASS.log` | candidate `330859f` | MySQL, default + Redis | real persisted Apply/Undo, read-only SQL audit PASS ×2 | manifest OK | **PASS** |
| `logs/durable-B-PASS.log` | #220 `029a080` | both engines × both caches | `#108 … gate: PASS` (396 assertions final) | site tree match | **PASS** |
| `logs/jobs-B3-PASS.log` | #220 `029a080` | both engines × both caches | `#109 … gate: PASS` (932 assertions in first iteration) | mapped by runner+clone | **PASS** |
| `logs/jobs-B2…` / `race/jobs-B4-isolated-FAIL.log` | #220 `029a080` | MySQL/default | deadlock-stress assertion FAIL (`NEEDS_REVIEW` vs `COMPLETED`) | raw logs retained | **FAIL (flaky)** |
| `logs/undo-B-PASS.log` | #220 `029a080` | both engines × both caches | `#110 … gate: PASS` (561 assertions) | site tree match | **PASS** |
| `web170/170-{chromium,firefox}-result.json` + PNGs | candidate `330859f` | Chrome 148.0.7778.178 / Playwright Firefox 151.0 | `PASS (253 assertions)` each, `safety.apply` CONFLICT 100/120/80, `no_mutation` true | hashes match `proof.json`; dimensions 1440×3905/3906 | **PASS** |
| `live/live-progress-result.json` | candidate `330859f` | Chrome, real job | 0→10 changed / 22→12 remaining, URL unchanged, `noReload` intact | manifest OK | **PASS** |
| `final-merge-evidence/pr-fast-candidate.log` | candidate `330859f` | host | `#133 PR_FAST PASS` (after genuine #170 refresh) | — | **PASS (local)** |

- **Environment separation confirmed:** MySQL vs MariaDB and default vs persistent cache are separate runner loops and separate DB servers/cache daemons; version strings captured in `runtime-acceptance-evidence/environment.txt` and the suite headers. Failures and re-runs are retained side-by-side (B2/B4 FAIL + B3/diag PASS), never overwritten except the deliberately reused runner sites, which are documented.

## B. #109 failure analysis

- **Failing assertion:** `wordpress/tests/jobs/integration.php:1292` — `different jobs same product: one applies, one conflicts, no deadlock` (deadlock-stress scenario: two jobs on one product, targets 80/90, real concurrent `wp` worker processes, `lock_wait_timeout=20`).
- **Original failures:** B2 (under concurrent browser-setup load) and, decisively, **B4 (fully isolated, no other workload)** — identical result: `actual ["COMPLETED_WITH_ISSUES","NEEDS_REVIEW"]` vs `expected ["COMPLETED","COMPLETED_WITH_ISSUES"]`.
- **Isolated passes:** B3 (all iterations), two instrumented full-suite runs on the frozen PR head (`diag-full2/3/4`), and a focused 12-iteration reproducer (`race/focused-repro-12-PASS.log`). Aggregate: **2 FAIL / 6 full-suite PASS** at the same source.
- **Durable state at failure (captured from B4's DB before reset, `race/B4-failure-db-state.txt`):** the job that applied (target 90) has journal `APPLIED / WOO_CRUD_VERIFIED` — its write committed and passed commit-time verification — then its post-commit `observe()` threw `CACHE_VERIFICATION_FAILED`, leaving the item `NEEDS_REVIEW`; the other job recorded the expected `CONFLICT / ITEM_CONFLICT`; exactly one Woo save; final price is the winner's target. Outcome is conservative (no overwrite, no replay, no false success), but the expected deterministic classification is not stable.
- **Infrastructure checks:** InnoDB error log contains **zero** "LATEST DETECTED DEADLOCK" entries; no lock-wait messages in the worker stderr files; all worker stderr empty. Instrumented full runs (trace hooks in the mutator and in `observe()`'s catch) did not reproduce the failure, so no throw-site trace was captured.
- **Classification: `FLAKY / UNRESOLVED`.** Not attributable to load (an isolated run failed); not attributable to an InnoDB deadlock; not reproduced with tracing in three attempts. Most probable class is a timing-dependent post-commit observation/verification interaction between two concurrent workers on one product (the observed throw is inside `Price_Cache_Verifier::observe()` after a verified commit).
- **Acceptance impact:** the #109 gate is not deterministically green in the equivalent environment, so #220 cannot be called runtime-VERIFIED. The failure changes an outcome label (winner `NEEDS_REVIEW` instead of `COMPLETED`), not data integrity. Required next step: hosted Admin/jobs CI on the eventual integrated head (Docker runner) must be observed repeatedly; if it reproduces, capture the instrumented trace (the diagnostic plugin copy is preserved in `race/`) and fix under #220.

## C. #170 acceptance

- **Integrated source SHA:** candidate `330859ffb92477afe52266948fdf9ec216c7ce45` (tree `59a28872f22937d1466482a7d829f52244186d8e`). The candidate was **rebuilt from exact PR heads** (worktree at `c48e307`, replay B(4)→A(6)→C(6)→D(3) lane commits + the two combined-only commits `7f47309` test stub and `330859f` evidence refresh, with the documented union conflict resolutions) and the resulting tree is **byte-identical** (same tree hash; `git diff 330859f HEAD` → 0 files).
- **Chromium:** PASS, 253 assertions, Chrome 148.0.7778.178; **Firefox:** PASS, 253 assertions, Firefox 151.0. Result JSONs verified: `safety.apply.state=CONFLICT`, expected 100.00 / current 120.00 / planned 80.00, `safety.negatives.no_mutation=true`; screenshots genuine, 1440×3905/3906.
- **Fingerprint consistency:** `proof.json` source hashes match the candidate's three UI files; capture hashes match the PNGs; the only commits after the capture are `docs/review/170/**` (no UI source changed after capture).
- **Asset audit + local PR_FAST:** `asset-audit.php` PASS; `bash .github/ci/pr-fast.sh` → `#133 PR_FAST PASS` on the rebuilt/identical source (`final-merge-evidence/pr-fast-candidate.log`).
- **Hosted CI:** PR #219/#220/#221 show `PR_FAST fail` + `CI_COVERAGE fail` on their **individual** heads; #222 both pass. Individual PRs are **not** marked #170-pass; the passing pair is integrated-candidate-scoped and remains local-only (not pushed, not a fifth PR).

## D. Per-PR readiness

| PR | Code verdict | Runtime evidence | CI gate | Remaining blocker | Recommendation |
|---|---|---|---|---|---|
| **#219** | **VERIFIED** (round-3 dedupe + fail-closed completeness re-verified; no defect found in Round 5) | Free #107 both engines + Admin #111 (838×2); PASS | PR_FAST fail (#170 individual) / CI_COVERAGE downstream | Per-step #170 refresh (or merge-train) required; MariaDB admin axis not run | **GO FOR REVIEW** |
| **#220** | **VERIFIED** code (`fresh_product` typing + terminal mapping + recovery trace); | Durable/Undo PASS both engines × caches; Jobs **FLAKY** (#109) | PR_FAST fail (#170 individual) | **#109 flake unresolved** + transient-retry semantics decision | **HOLD** |
| **#221** | **VERIFIED** incl. browser-reproduced fix `fd0dc73` with regression | #111 (838×2), progress-integration (default+Redis), live polling, #170 both engines | PR_FAST fail (#170 individual) | Per-step #170 refresh (or merge-train) | **GO FOR REVIEW** |
| **#222** | **VERIFIED** preparation scope | n/a (no UI) | **PR_FAST + CI_COVERAGE PASS** | None (issue remains PARTIAL by design) | **GO FOR REVIEW (preparation)** |
| **Integrated** | **VERIFIED** byte-identical rebuild of all four heads | Candidate `330859f`: full local battery + real runtime/browser suites PASS | Local PR_FAST PASS; hosted CI not applicable (no push) | Integration procedure decision (E); #109 flake; #220 decision | **GO FOR REVIEW (subject to decisions)** |

## E. Integration procedure

**Branch protection (verified):** `main` requires `PR_FAST` + `CI_COVERAGE`, `strict: true` (head must be up to date with base), `enforce_admins: true`, no force pushes. **Consequence:** a single combined-evidence pair cannot make the three individual PRs mergeable; each PR's required checks must pass **on its own head at merge time**, and any update with a newer main changes the head tree (and its #170 fingerprint).

**Option 1 — sequential per-step refresh (no new PR):**
1. **#222 first** (its checks pass as-is): fetch, verify head `079f5da` unchanged, merge → main M1. (No UI/#170 change; no runtime regen needed.)
2. **#220**: update branch with M1 (non-force; `gh pr update-branch 220` or `git merge origin/main` + push). Its plugin bytes are unchanged by #222, so its runtime evidence stays valid; **regenerate the #170 pair against the updated head** (stage the exact head, run the Chromium+Firefox journeys, refresh `docs/review/170/{proof.json,170-*-result.json,170-*-apply-conflict.png}`, commit/push) until PR_FAST passes. Re-run owning suites (#108/#109/#110) once on the updated head (mandatory because the tree bytes changed). Merge → M2.
3. **#219**: update with M2; re-run owning runtime (#107 free + #111 admin selection fixtures); regenerate the #170 pair; PR_FAST green; merge → M3.
4. **#221**: update with M3; re-run owning runtime (#111 admin + progress-integration + live polling); regenerate the #170 pair; PR_FAST green; merge → M4.
5. Post-merge: verify main's tree equals the validated candidate tree (`git rev-parse main^{tree}` vs `59a28872…`) modulo merge-commit ordering; run the focused battery once on M4.
- **Rollback/stop conditions:** any required check red, any journey failure, any runtime regression (esp. #109 flake), or a conflict resolution that differs from the validated union → stop and return to review.
- **Cost:** 3 capture refreshes (each ~2 journeys) + 3 runtime re-runs. The environment for all of it is documented in `RUNTIME-ACCEPTANCE-REPORT.md` §A.

**Option 2 — single integration PR ("merge train", requires separate authorization for a fifth PR):** push the already-validated rebuild branch (`combined-rebuild` @ `105babd2886106ffbfddc6d7e8301b7794f28607`, tree `59a28872…`, byte-identical to `330859f`) as e.g. `release/free-v0.2-integration`, open **one** PR to main with the refreshed #170 evidence included. Its PR_FAST passes locally today; hosted CI would then run the ownership-selected Docker suites (including the real Admin browser job) on the exact integrated source — the strongest available validation. Merge once → main equals the validated candidate. Record/know: issue ownership requires the PR body to reference #206/#209/#204/#210 and not auto-close them (`Refs #…`), and this option needs the explicit fifth-PR authorization.

**Option 3 — temporarily relaxing protection:** **not recommended**; it requires repository-administrator action, would bypass a required safety gate, and is outside agent authority.

**Required founder/administrator decisions:** choose Option 1 or Option 2; confirm whether per-step evidence commits to PR branches are authorized (Option 1); if Option 2, authorize the fifth PR. No protection settings may be changed for the merge to occur under a bypass.

## F. Founder decision sheet

1. **#220 transient retry semantics — DECISION_REQUIRED.** Precise behavior of the code as it stands (**Option A, currently implemented**):
   - `Product_Price_Snapshot::fresh_product()` throws `unsupported_product_type` for stable non-core classes (terminal; never constructed) and `unreadable_product_data` for missing dependencies/suspended invalidation/non-string classname/constructor failure/ID mismatch (transient-classified).
   - Apply/Undo entry map `unsupported_product_type` → `UNSUPPORTED_PRODUCT_STATE` (**terminal** `FAILED`); transient `unreadable_product_data` propagates → catch maps to `FAILED` → durable journal `PENDING` (retryable, bounded by the worker attempt/backoff budget).
   - Every retry re-runs the journal `FOR UPDATE` + `PENDING` gate, product row locks, targeted cache invalidation, frozen-snapshot precondition, expected-price and coherence checks before any `set_field`/`save`; newer edits → `CONFLICT` (terminal); `$committed && FAILED` → `AMBIGUOUS_COMMIT` and rollback-failure → `TRANSACTION_LOST`, both `NEEDS_REVIEW` (never auto-retried).
   - Runtime evidence: `#108`/`#109`/`#110` real suites (kill-before/after COMMIT, duplicate Resume one-save, dirty catalog, conflict preservation); round-3 durable fixtures for extension-class terminal vs `stdClass` retryable.
   - **Option A** (accept): more recovery flexibility for genuine transient faults; behavior change vs pre-#209 baseline (which made any unreadable terminal); no data-safety cost observed.
   - **Option B** (strict baseline): make transient `unreadable_product_data` terminal too; narrower semantics change; loses automatic recovery of transient read faults; requires a mapped terminal code at both mutator entries and updated durable fixtures (the terminal branch already exists for `UNSUPPORTED_PRODUCT_STATE`, so the patch is small but the behavior is intentionally less forgiving).
   - **Recommendation:** **accept Option A** — the runtime evidence shows retryability does not weaken fences or overwrite newer edits, and the case for retrying transient read faults is product-positive. **Do not treat this as approved until the founder explicitly selects A or B.**
2. **Integration procedure — DECISION_REQUIRED:** approve Option 1 (per-step refresh) or Option 2 (authorized integration PR); Option 3 not recommended.
3. **Evidence sufficiency / blockers — DECISION_REQUIRED:** does the **#109 flake** block #220 merge (recommended: yes, until resolved or explicitly accepted with the conservative-outcome rationale), and is the **MariaDB Admin axis** (not run in Round 4/5) required before merge (recommended: run it once on the integrated head)?
4. **Proceed to merge stage — DECISION_REQUIRED:** per-PR GO/HOLD above; agent will not merge.

**Verdicts:** #219 GO FOR REVIEW · #220 HOLD · #221 GO FOR REVIEW · #222 GO FOR REVIEW (preparation) · integrated candidate GO FOR REVIEW subject to decisions 1–4. No MERGE action taken; stop and await founder authorization.
