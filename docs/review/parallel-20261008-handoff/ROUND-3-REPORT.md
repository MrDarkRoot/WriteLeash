# Round 3 report — remaining review blockers on PRs #219–#222 (2026-10-08)

Scope: verified the Round-2 findings and the newly raised Round-3 concerns, implemented only demonstrated corrections, added regressions, rebuilt and validated a disposable local integration candidate, and pushed fast-forward updates to the four existing Draft PRs. **No merge, no Ready-for-Review, no issue closure, no deploy/publish, no fifth PR, no gate/evidence rewrite. Founder retains GO / NO-GO.**

Preflight: `origin/main` unchanged at `c48e307efe7750d0fb36015c39d50a34675f9579`; no maintainer comments/reviews on any PR; no third-party commits; previous heads matched what we pushed. CI at preflight: #219/#220/#221 PR_FAST fail only at the #170 gate with CI_COVERAGE downstream; #222 green.

## Result table

| PR | Previous blocker | Finding after verification | Code change | Tests | Remaining blocker | Verdict |
|---|---|---|---|---|---|---|
| **#219** (#206) | PHP dedupe cannot restore rows already removed by SQL LIMIT; DISTINCT guard was unscoped | **Confirmed and fixed.** The `posts_distinct` guard now applies only to the exact discovery query (private token query var, 2-arg callback, `finally` cleanup), and CATEGORY discovery **fails closed** when completeness cannot be proven: rewritten `posts_per_page` window, or a full page carrying duplicate join rows → `selection_limit_exceeded` before any Woo read; short duplicate pages still dedupe exactly | `class-product-selector.php` (+63/−35): scoped guard + completeness checks | `selection-count.php` 46→**63 PASS** (full-window duplicates now refuse; short-window duplicates accept and read once; nested-probe token scoping; constructor-throw cleanup; window tamper; results-path injection full vs short; reads=0); negative control vs Round-2 bytes FAILs as expected; variations 73; durable `posts_results` full-window refusal added (Docker NOT_TESTED) | Real-WP `posts_distinct`/runtime admin+HTTP suites NOT_TESTED; #170 capture BLOCKED; a plugin that **removes** rows via `posts_results` is indistinguishable from a smaller result set (Preview independently re-resolves and freezes its own population) | **Code review: PASS · Acceptance: PARTIAL** |
| **#220** (#209) | End-to-end retryability of transient unreadable was asserted, not proven | **Verified; no defect found.** Full reason→durable-state→retryability→guard matrix with file:line citations; retries re-run journal `PENDING` gate, row locks, invalidation, frozen preconditions, expected-price/coherence checks before any save; uncertain (`AMBIGUOUS_COMMIT`, `TRANSACTION_LOST`) and `CONFLICT`/`NEEDS_REVIEW` never auto-retry; unsupported classes remain terminal; attempt fencing prevents duplicate completion | **No production change** (docs + durable tests only): `docs/review/209/IMPLEMENTATION.md`, durable `cache-public-integration.php` (+39), `jobs/integration.php` (+75), test-only `jobs/worker.php` knob | Local battery PASS (cache-public 73, unit 2,607, sale 105, variations 75, admin/no-replan/ownership/release audits); new durable fixtures (interrupted Apply between checkpoints, transient refusal→0 saves→PENDING→retry 1 save→concurrent edit CONFLICT) lint-checked **NOT_TESTED** (Docker) | Docker/Woo/Redis runtime NOT_TESTED; **the transient `unreadable_product_data`→`FAILED`→PENDING divergence remains and still requires founder approval** (proven safe, not removed) | **Code review: PASS · Acceptance: PARTIAL** |
| **#221** (#204) | Row-order/focus reconciliation claimed but not DOM-verified | **Verified; no defect found.** Exhaustive deterministic enumeration: 9,996 cases × 69,481 assertion checks × 1,198 focused-removed deferrals, 0 violations; invariants (no duplicates, server set, non-focused server order, focused rows never moved, exact order without focus). Endpoint proven read-only (SELECT-only call graph); Resume/Undo handlers independently enforce actor/POST/nonce/durable state and never read client availability fields | **No production change**: `progress-client.cjs` (+79), `progress-unit.php` (+90), docs | Client + unit suites PASS (strengthened sentinels incl. 16 job + 18 undo writer self-controls); pre-fix client `6a6a9ac` FAILs the updated suite; scratch enumeration 35,491 cases = 30,949 violations pre-fix vs 0 shipped | Real browser live Apply/Undo, keyboard, axe, expired-session journeys and Docker-bound `progress-integration.php` NOT_TESTED; focused-removed deferral shows previous view for one update cycle (documented, tested) | **Code review: PASS · Acceptance: PARTIAL** |
| **#222** (#210) | Oversized inventory (Round 2 fix) needed post-change regression check | **Verified.** Manifest-scoped tool + deterministic summary unchanged; changed-file accounting documented: `class-product-selector.php`, `class-free-admin.php`, `free-selection.js` are manifest entries scanned automatically; `free-progress.js` is auto-included once #221's manifest entry merges (fixture-verified); no gettext conversion/runtime edits | **Docs only**: `PREPARATION.md` §8.1, `IMPLEMENTATION.md` (round-3 verification) | `i18n-inventory-cases.php` PASS; determinism hashes byte-identical across runs (`--summary 9cf090ec…`, manifest-scoped `0e62bb58…`, `--all 06223803…` = pre-rework 9,436 candidates); ownership PASS; **PR_FAST + CI_COVERAGE green on this branch** | Locale runtime and post-merge conversion NOT_TESTED; **#210 remains PARTIAL — PREPARATION COMPLETE** | **Code review: PASS · Acceptance: PARTIAL (preparation scope verified)** |

## 1. Exact current PR HEAD SHAs

| PR / branch | Previous HEAD | New HEAD | Commits added this round |
|---|---|---|---|
| #219 `work/206-selection` | `eadd0c9505e31ee3de7197936bc617ebf5eb2d2d` | **`eaad5dd0b1703997667474edb55f928fd2e75d0d`** | `5cf39c10…` (fix), `eaad5dd0…` (docs) |
| #220 `work/209-public-cache` | `7496874eb0b48989814234afb703d17a5563ace0` | **`029a08040da0fab06bfea2a8d174aeba3d72f2d4`** | `029a0804…` (docs + durable tests) |
| #221 `work/204-live-progress` | `1e218cb8cab63d796dd4440aa17e6eeff59b5abd` | **`19e2c6892c5887bb53a4a470979a44d3822e3480`** | `21ca6d4…`, `19e2c68…` (tests + docs) |
| #222 `work/210-i18n-preparation` | `4c7f6b2fd919b0c2a84922f15dd1b9bfdaca26fd` | **`079f5dad148a8718586db05b4abc322047908710`** | `079f5da…` (docs) |

All pushed fast-forward (no force/amend/rebase); all four verified **Draft** with matching `headRefOid`; PR descriptions updated with previous/new HEAD, findings, behavior, files, tests, blockers, support-claim impact and deviations.

## 2. Actual commands and results (lead re-ran)

- #219: `selection-count.php` **63 PASS**; `unit.php` 2,607; `sale-price.php` 105; `variations.php` 73; admin-audit/no-replan/ownership/release audits PASS; PR_FAST FAIL only at #170.
- #220: `cache-public.php` **73 PASS**; `variations.php` 75; unit 2,607; full battery PASS; PR_FAST only #170.
- #221: `progress-client.cjs` PASS (9,996 cases / 69,481 checks / 0 violations); `progress-unit.php` PASS (read-only + handler authority); battery PASS; PR_FAST only #170.
- #222: `i18n-inventory-cases.php` PASS; determinism byte-compare PASS; `pr-fast.sh` **PASS**; CI green.
- Combined candidate: full battery PASS (unit 2,607 · sale 105 · variations 75 · selection-count 63 · cache-public 73 · progress-client/unit · admin-audit · no-replan · package-preflight 43 · inventory · public-audit · readme · historical · claim-matrix · i18n tooling · ownership · dependency · test-artifact); lint + `git diff --check` clean; no conflict markers; PR_FAST fails only at #170 (21 PASS lines then `asset-audit.php:6`); GitNexus `detect-changes --scope compare` complete — **42 files / 393 symbols / 56 processes**, no partial/truncated.

## 3. Negative controls / reproductions

- #219: Round-2 bytes `eadd0c95` FAIL the new full-window duplicate refusal expectation (proof the regression discriminates); nested query probe receives no forced `DISTINCT` while the discovery query does; constructor throw still removes the guard; `posts_per_page` rewrite refuses with `reads = 0`; full-window duplicate injection refuses, short-window accepts.
- #221: pre-fix client `6a6a9ac` FAILs the updated enumeration suite; expanded scratch enumeration 35,491 cases = 30,949 violations pre-fix vs 0 with the shipped algorithm; mutation/worker sentinel self-controls fail when a mutation is injected.
- #222: `--all` reproduces the pre-rework raw population byte-for-byte (9,436 candidates / 60 files).
- #220: no defect reproduction — the verification is a positive proof (matrix + durable fixtures), explicitly not claimed as runtime PASS.

## 4. Coverage of recovery semantics for #220

Matrix (reason → durable journal/item → retryable? → guard), all cited in `docs/review/209/IMPLEMENTATION.md` round-3 section: stable `unsupported_product_type` and `UNSUPPORTED_PRODUCT_STATE` → `FAILED` terminal (no write); transient `unreadable_product_data` → `FAILED`/`PENDING` bounded retry, but every retry re-runs the journal `FOR UPDATE` + `PENDING` gate, row locks, targeted invalidation, frozen-snapshot precondition, expected-value and coherence checks before `set_field`/`save`; `CACHE_VERIFICATION_FAILED` and `AMBIGUOUS_COMMIT`/`TRANSACTION_LOST` → `NEEDS_REVIEW` terminal, never auto-retried; `CONFLICT` terminal with the newer edit preserved; Undo `UNDO_CONFLICT`/`UNDO_NEEDS_REVIEW` terminal, `UNDO_PENDING` bounded. Attempt fencing (`owns_attempt`, unique journal transitions) prevents duplicate completion under duplicate Resume. No stop condition violated.

## 5. Browser and UI-capture evidence status

**BLOCKED — UI_CAPTURE_ENV_UNAVAILABLE.** The established #170 procedure (`docs/review/170/README.md`) is to re-execute the Admin Chromium/Firefox journey against the updated source and refresh `source_hashes` + `170-{engine}-result.json` (PNG captures remain historical); the gate compares hashes of `class-free-admin.php`, `free-selection.js`, `free-selection.css` against `docs/review/170/proof.json`. It requires the real Woo/MySQL Admin runner. Environment: `/var/run/docker.sock` absent, daemon inactive; `dockerd-rootless-setuptool.sh check` fails with the root-only requirement `apt-get install uidmap` + `modprobe nf_tables` (no passwordless sudo, no bypass); `browser-tools` declares Playwright/axe but has no installed modules. No capture, synthesis, rehash, gate edit or assertion change was performed.

## 6. Real Woo / default-cache / Redis evidence status

**NOT_TESTED.** Exact failed command: `docker info` → cannot connect (socket absent); `dockerd-rootless-setuptool.sh check` → missing `uidmap`/`nf_tables` (root-only). Safe alternatives investigated: rootless Docker (binaries present) is blocked by missing `newuidmap/newgidmap` + `slirp4netns`/`fuse-overlayfs` and the unloaded `nf_tables` module — not available without privilege escalation. All durable `cache-public-integration.php`, `jobs/`, `undo/`, `tests/free/integration.php`, `progress-integration.php`, `selection-http.php`/`selection-integration.php`/`subcategory-integration.php` assertions are lint-checked only and remain NOT_TESTED. No mocked PASS substitution.

## 7. Combined candidate SHA and integration order

- Order: **#220 → #219 → #221 → #222** (B → A → C → D), assembled by cherry-pick on top of the Round-2 candidate.
- Clone `/tmp/writeleash-approved-20261008/combined-review`, branch `review/approved-four`, HEAD **`8c1545502c57dd2492ef0cd8df5dbab11f34c9ac`**; patch `combined3-candidate.patch` SHA-256 `abec3dc6bc94f5b0e280bd6a3d074817b0c21960e895bbc1f64fddb79a0a8863`; 42 changed files vs baseline.
- This round all four fixes applied without manual conflict (Round-2's two selector union resolutions and the `WC_Cache_Helper` test stub remain in history). The candidate is local and disposable; **not pushed, not a PR**.
- Any further source change invalidates this evidence and requires a rerun.

## 8. Outstanding founder decisions

1. **GO/NO-GO** on any merge; all four PRs remain Draft.
2. **#220 transient retry divergence remains** (`unreadable_product_data` → retryable `FAILED`/PENDING; proven safe, bounded, journal-gated; baseline was terminal) — explicit founder approval still required, or instruct strict-baseline terminal treatment.
3. Authorize a runtime environment (Docker or equivalent) for Woo/default-cache/Redis acceptance and the #170 UI-capture refresh; until then those acceptance verdicts stay PARTIAL/NOT_TESTED.
4. Confirm #210 stays PARTIAL — PREPARATION COMPLETE (no conversion until the accepted #204–#208 UI settles).

Artifacts: `round3-evidence/` (per-PR fix logs/reports, PR body snapshots, combined3 patch/detect/battery). Machine-readable round-2 state remains `FINAL-STATE.json`; round-2 report `REVIEW-FIX-REPORT.md`; integrity manifest `SHA256SUMS`.
