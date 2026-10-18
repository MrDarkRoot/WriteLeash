# Runtime acceptance report — Round 4 (2026-10-08)

Mission: close the real-WooCommerce/Redis/browser and #170 evidence gaps through actual execution. This round provisioned a genuine user-space runtime at the pinned versions, executed the owning integration suites, reproduced and fixed one real browser defect, completed the #170 capture on the integrated candidate, and refreshed its reviewed evidence locally. **No merge, no Ready-for-Review, no issue closure, no deploy/publish; PRs remain Draft. Founder retains final GO/NO-GO.**

Statuses used below: PASS / FAIL / BLOCKED / NOT_TESTED / PARTIAL (PASS = actually executed and met expectations).

## A. Environment

- **Host:** Linux 6.18.7 x86_64 (Ubuntu 24.04 base, no Docker daemon). 97 GB free disk.
- **Provisioning method:** user-space tarball installs under `/tmp/wlruntime` at the repository-pinned versions, invoked through a user-namespace wrapper (`unshare -rm`) that bind-mounts an `/opt`-shaped tree and an `/etc/hosts` with `mysql`/`mariadb`/`redis` aliases, so the repository runner scripts (`wordpress/tests/*/in-container.sh`) ran **unmodified** except one clearly-labeled line: the MariaDB build-suffix assertion (`10.11.15-MariaDB-ubu2204` → `10.11.15-MariaDB`, bintar build). Fixtures themselves were not modified.
- **Permission boundary:** no `sudo`, no root, no privilege escalation, no system daemon or module changes, no repo/CI/secret changes. DBs/Redis bound to separate loopback addresses (`127.0.0.2/3/4`), disposable test data only.
- **Component versions (exact):** WordPress **7.1.2**; WooCommerce **11.1.2** (plus pinned 11.0.1 swap exercised in the browser journey); PHP **8.2.32**; WP-CLI **2.12.0**; MySQL **8.0.44**; MariaDB **10.11.15-MariaDB**; Redis **7.4.2**; object cache profiles **default** and **Redis persistent** (redis-cache 2.7.0 + bundled Predis); browser **Google Chrome 148.0.7778.178**, **Playwright Firefox 151.0** (Playwright 1.61.0, axe-core 4.11.0); Node 22.23.2.
- **Docker/runner availability:** `docker info` fails — `/var/run/docker.sock` absent, daemon inactive; `dockerd-rootless-setuptool.sh check` fails with the root-only prerequisites `apt-get install uidmap` + `modprobe nf_tables`. No safe unprivileged alternative existed; no repeated attempts, no bypass. GitHub Actions runtime jobs are reusable-workflow-only and hard-gated `needs: fast`, so they skip while PR_FAST fails (#170) — the exact CI deadlock this round resolves via the integrated capture.
- **Differences from the pinned matrix (labeled):** MariaDB build suffix (above); PHP is the host 8.2.32 CLI instead of the `wordpress:cli-php8.2` image (same minor); no container/network isolation; Firefox 151.0 instead of the previously recorded 155.0 (Playwright build). All results are real Woo/database/browser executions, never mocks.

## B. Per-PR acceptance

| PR | Test suite | Environment | Status | Evidence | Remaining risk |
|---|---|---|---|---|---|
| **#219** (#206) | `#107` free planning contract (`free/in-container.sh`, includes selection resolution/limits) | WP 7.1.2 + Woo 11.1.2, MySQL 8.0.44 + MariaDB 10.11.15, default + Redis | **PASS** (`#107 pinned two-engine Woo planning contract PASS`) | `logs/free-A-PASS.log` | Raw-window fail-closed path exercised in stub/unit only; real-WP `posts_distinct` override behavior remains a documented conservative refusal |
| **#219/#221** | `#111` Admin E2E incl. `selection-integration`, `selection-http`, subcategory, progress fixtures | MySQL 8.0.44, default and Redis | **PASS** 838 assertions each profile | `logs/admin-integration-mysql-default-PASS.log`, `…-redis-PASS.log` | MariaDB admin profile not run this round (PARTIAL for that axis) |
| **#220** (#209) | `#108` durable cache/journal gate: rollback, kill-before/after COMMIT, retry, already-APPLIED, current-price | MySQL + MariaDB, default + Redis | **PASS** (396 assertions in final matrix; `#108 … gate: PASS`) | `logs/durable-B-PASS.log` | — |
| **#220** | `#109` jobs gate: schema fail-closed, dirty catalog, interrupted workers, duplicate Resume fencing, deadlock stress, unicode | MySQL + MariaDB, default + Redis | **PASS** (931 assertions; `#109 … gate: PASS`, 160 PASS lines) | `logs/jobs-B3-PASS.log`; an earlier run failed one concurrency scenario while the browser setup ran concurrently — clean isolated re-run passed that scenario in all iterations (`logs/jobs-B2-load-induced-failure.log` preserved) | Scenario is timing-sensitive under heavy concurrent load; no re-run reproduced it |
| **#220** | `#110` conflict-aware Undo gate incl. kill-after-COMMIT, expiry, pagination | MySQL + MariaDB, default + Redis | **PASS** (561 assertions; `#110 … gate: PASS`) | `logs/undo-B-PASS.log` | — |
| **#221** (#204) | `progress-integration.php`: persisted Apply/Undo, actor/capability/session/nonce bounds, read-only SQL/product-read audit, conflict/terminal/no-JS | MySQL, default and Redis | **PASS** both profiles | `logs/admin-progress-mysql-default-PASS.log`, `…-redis-PASS.log` | — |
| **#221** | Live polling browser acceptance: queued job, page open, external Resume, counters advance without reload | Chrome 148, WP/Woo runtime | **PASS** (0→10 changed, 22→12 remaining, URL unchanged, `noReload` intact, automatic updates active) | `live/live-progress-result.json`, `live/live-progress-after.png` | Single engine/profile exercised for this specific check |
| **#222** (#210) | Deterministic manifest-scoped inventory + tooling tests; PR_FAST/CI green | Host tooling | **PASS (preparation scope)** | `REVIEW-FIX-REPORT.md`, CI on `079f5da` | Locale runtime NOT_TESTED by design; #210 remains PARTIAL |

## C. #170 UI evidence (integrated candidate)

- **Captured source SHA:** combined candidate `330859ffb92477afe52266948fdf9ec216c7ce45` (base `c48e307…`; order #220→#219→#221→#222).
- **Chromium:** `#170 chromium integrated keyboard/accessibility/safety: PASS (253 assertions)`, Chrome 148.0.7778.178 — `web170/170-chromium-result.json`, `web170/170-chromium-apply-conflict.png` (1440×3905).
- **Firefox:** `#170 firefox integrated keyboard/accessibility/safety: PASS (253 assertions)`, Firefox 151.0 — `web170/170-firefox-result.json`, `web170/170-firefox-apply-conflict.png` (1440×3906).
- **Same-row evidence:** both result JSONs carry `safety.apply {state: CONFLICT, expected: 100.00, current: 120.00, planned: 80.00}` and `safety.negatives.no_mutation: true`; axe scans ran inside the journeys (serious/critical = 0 asserted).
- **Fingerprint verification / PR_FAST:** `docs/review/170/proof.json` source hashes and capture hashes were **refreshed from the genuine new captures** in the disposable candidate; `asset-audit.php` → `#170 reviewed source, capture hashes/dimensions/captions and same-row independent conflict evidence PASS` (`asset-audit-PASS.log`); full `bash .github/ci/pr-fast.sh` → `#133 PR_FAST PASS` (`pr-fast-candidate.log`). The evidence refresh is committed in the disposable candidate only (`330859f`) and **not pushed**.
- **Outstanding capture limitations:** per-PR branches #219/#220/#221 still fail #170 in CI because each isolated source differs from the integrated capture — per the Round-4 instruction we did not contaminate PR scopes with combined-source hashes; the single reviewed pair is candidate-scoped, and a repo-process decision is needed on where the integrated refresh lands. Screen reader, Safari and WebKit remain NOT_TESTED (unchanged from the prior accepted evidence).

## D. Correctness and recovery assessment

- **Frozen Preview authority:** PASS — Admin E2E (#111) and planning suite (#107) executed the real preview/freeze paths; the browser journey asserts frozen IDs and no re-plan on duplicate approval; no execution-time reselection observed.
- **Cache freshness:** PASS — `#108` and `progress-integration` ran real Woo reads/writes with default and Redis object caches, including post-write verification and stale-observation refusal; no shipped Internal dependency reintroduced (grep empty).
- **Conflict safety:** PASS — `#108`/`#109`/`#110` exercised concurrent edits; conflicts stayed terminal (`CONFLICT`/`COMPLETED_WITH_ISSUES`), newer merchant values preserved, no blind overwrite observed.
- **Resume idempotency:** PASS — duplicate Resume requests produced exactly one Woo save per item; attempt/lease fencing and `ALREADY_APPLIED` observed in `#109`.
- **Undo conflict protection:** PASS — `#110` kill-after-COMMIT recovery adopted `UNDONE` without a second save; later edits preserved; expiry and pagination honest.
- **Durable journal correctness:** PASS — kill-before/after-COMMIT and rollback scenarios reconciled to `PENDING`/`APPLIED` correctly; failed migrations roll back fully.
- **Cross-actor authorization:** PASS — `progress-integration` and `#111` denied cross-actor job access with no data disclosure; nonce/GET/method negatives held.
- **Progress endpoint read-only:** PASS — dedicated SQL/product-read audit on every poll found no mutation/worker/schema/product write; live polling test changed state only via the Resume POST.
- **Stop-condition audit:** no instance found of unseen-product execution, blind overwrite, completed-work replay, uncertain-as-success, Undo destroying later edits, cross-actor disclosure, or polling mutation.

## E. Changes made

- **Defect (PR #221, found by the real-browser journey):** the polling endpoint's row text used short `reason_message` variants for `CONFLICT`/`UNDO_CONFLICT`, so after the first poll the client rewrote the server-rendered conflict guidance and the #170 journey failed at `readable Undo preservation` (Apply check passed only pre-poll). Original failure preserved in `defect/` (`live-undo-page-dump.txt`, journey log).
- **Failing regression first:** updated `progress-unit.php`/`progress-client.cjs` assertions failed against pre-fix bytes (discriminator evidence in `fix-221/`).
- **Minimal fix:** one shared `outcome_conflict_copy()` helper in `class-free-admin.php` used by both `render_item_outcome()` and `progress_snapshot()` rows; no other behavior changed.
- **Passing regression:** `progress-unit.php` 148 checks, `progress-client.cjs` 9,996 cases / 69,481 checks / 0 violations; full battery PASS.
- **New branch HEAD:** PR #221 `fd0dc73e8a1598da1c894a6be24be28f860c286d` (pushed fast-forward; Draft). PRs #219/#220/#222 unchanged this round (`eaad5dd0…`, `029a0804…`, `079f5dad…`).
- **Re-verification after the fix:** #170 journeys both engines PASS; live polling PASS; `progress-integration` and `#111` PASS on default + Redis; `pr-fast.sh` PASS on the integrated candidate.
- **Remaining risks:** the #109 deadlock-stress scenario is timing-sensitive under heavy concurrent load (one load-induced failure, clean isolated PASS; logged both); integrated #170 evidence is local-only pending a repo-process decision; MariaDB admin profile and Firefox live-polling were not separately repeated this round.

> **Round-5 correction (supersedes the Round-4 "load-induced" attribution):** the #109 failure was reproduced in a **fully isolated** run (Round 5, `final-merge-evidence/race/jobs-B4-isolated-FAIL.log`) while other isolated and instrumented runs passed. Correct classification: **FLAKY / UNRESOLVED**. See `FINAL-MERGE-READINESS.md` §B.

## F. Founder decisions (independent recommendations)

1. **PR #219 merge readiness:** **GO FOR REVIEW** — real two-engine planning plus Admin E2E runtime evidence; fail-closed discovery documented.
2. **PR #220 merge readiness:** **GO FOR REVIEW on code/runtime**, with the transient `unreadable_product_data`→retryable divergence explicitly **reserved for your decision** — runtime proves it is not unsafe replay, but the semantics change from the pre-#209 baseline. If you prefer strict baseline, require terminal treatment before merge.
3. **PR #221 merge readiness:** **GO FOR REVIEW** — browser-reproduced defect fixed with regression; live polling, read-only audit and both #170 engine journeys PASS.
4. **PR #222 preparation-only acceptance:** **GO FOR REVIEW (preparation scope)** — remains PARTIAL; no conversion.
5. **Combined integration readiness:** **GO FOR REVIEW** — candidate `330859f` passes the full local battery, the #170 gate and PR_FAST; decide where the integrated captured evidence is committed (it currently exists only in the local disposable candidate) and whether the per-PR CI #170 gate is expected to be refreshed at integration time.
6. **Missing infrastructure / further runs:** Docker remains BLOCKED (exact command and prerequisite in §A). Optional additional runs: MariaDB admin profile, Firefox live-polling, and a re-run of #109 under the exact CI runner to confirm the load-flake attribution.

## Compliance

No merge/deploy/publish/issue closure/Ready-for-Review occurred; no CI rule, assertion, expected result or protection was modified; no fabricated evidence — screenshots and result JSONs are genuine browser outputs from the rerun journeys; the disposable candidate and its refreshed evidence were not pushed.
