# Issue #206 implementation handoff

Scope: explicit Include subcategories and bounded informational expanded target-count discovery. Local preparation only; founder retains GO / NO-GO. No push, PR, merge, deployment, issue update, release artifact regeneration, or support expansion.

| Field | Evidence |
|---|---|
| Issue | #206 — Include subcategories and show the expanded product selection |
| Base SHA | `c48e307efe7750d0fb36015c39d50a34675f9579` |
| Branch | `work/206-selection` in `/tmp/writeleash-approved-20261008/agent-A` |
| Head SHA | `c8cbf6c283545658add407745b3c2d605fcd8b14` (implementation commit, 10 files). Baseline `c48e307efe7750d0fb36015c39d50a34675f9579`. `git diff c48e307..HEAD` sha256 `31589065032ce35df2df7ed72b2263c9f6b331434986a0cbb117ab2e7ff4891d` — byte-identical to the frozen A snapshot patch. |
| PR status | Not created; local commit review-ready, no push/PR/merge |
| Scope deviation | None. Category raw candidate window is bounded separately from final selection; no selection/approval ceiling increased. |
| Support claims | Unchanged. Existing public package manifest and capture/proof evidence preserved. |
| Acceptance verdict | **PARTIAL**: commit and GitNexus graph gates now PASS; actual WooCommerce/Admin/browser runtime and fresh UI evidence remain NOT_TESTED (Docker unavailable), and PR_FAST still fails the unchanged #170 reviewed-capture fingerprint |

## Files changed

- `wordpress/writeleash/includes/free/class-product-selector.php`
- `wordpress/writeleash/includes/free/class-product-discovery.php`
- `wordpress/writeleash/includes/free/class-free-admin.php`
- `wordpress/writeleash/includes/free/free-selection.js`
- `wordpress/tests/free/selection-count.php`
- `wordpress/tests/free/in-container.sh`
- `wordpress/tests/admin/subcategory-integration.php`
- `wordpress/tests/admin/selection-integration.php`
- `wordpress/tests/admin/selection-http.php`
- `docs/review/206/IMPLEMENTATION.md`

## Behavior and bounds

`Price_Selection_Spec::category($id, $include_children = false)` retains the old false shape by default. The explicit boolean drives the public WP taxonomy query. CATEGORY selection remains in the existing canonical hashed plan material; resolved IDs are frozen separately. No hydration/schema/hash-version change is needed. Direct-members plans still hydrate without rewriting material or hashes.

The authenticated native **Check selection count** action delegates through the existing `Product_Discovery` boundary to the exact selector used by Preview. It does not validate/compute a price, create a plan/job, approve, or invoke a worker. Count includes distinct expanded targets, with explicit unreadable and missing counts; counts are not claims of eligibility. Denied targets refuse disclosure. Empty and over-limit selections are explained. The count is rendered only from a server-produced result, is never accepted as plan authority, and client edits mark it stale. Without JavaScript the last-check wording and native button remain available.

A category query admits at most 2,001 raw rows, with a refusal sentinel above 2,000. This allows 1,000 final variation targets plus their at-most-1,000 core parents: supported variations have one core parent. Deduplication and expansion still refuse 1,001 final targets. IDS/SKU input ceilings remain unchanged. A no-child variable parent stays one explained unsupported target. Oversized child expansion stops after the 1,001st distinct target read instead of reading an entire oversized variable catalog; Woo's public `get_children()` still owns child-ID retrieval.

Preview independently resolves the live selector again, freezes exact IDs, and includes the descendant scope in the saved review. Count/Preview divergence therefore cannot approve products without that final review. Execution continues to consume hydrated frozen items and never reselects a category.

## Acceptance criteria

`PASS` below is restricted to the executed contract/static checks. It does **not** establish actual WP query, Woo cache, HTTP, browser, or accessibility behavior.

| Live issue criterion | Contract evidence | Full acceptance |
|---|---|---|
| Direct/default and descendants across nested categories without duplicates | PASS: new 32-assertion harness models three taxonomy levels, overlapping membership, direct defaults and opt-in | NOT_TESTED: real taxonomy/Admin fixture prepared |
| Variable-parent expansion/count matches planner targets and overlapping membership | PASS: exact shared resolver, repeated children, explicit child+parent overlap, no-child parent, 1,001 raw / 1,000 final boundary | NOT_TESTED: actual Woo variable-parent behavior |
| Empty, unreadable and over-limit explained; 1,000/1,001 intact | PASS: empty/missing/unreadable/inaccessible and final boundaries; bounded sentinel reads | NOT_TESTED: actual rendered/native count and cache profiles |
| Category change between count and Preview cannot authorize unseen products; execution never reselects | PASS: count diverges from independent Preview; later category additions absent from hydrated IDs; unchanged no-replan audit | NOT_TESTED: real Apply execution after category change |
| Scope visible in review/retained context; old direct plans hydrate | PASS: true scope in hashed material, false default, byte-for-byte direct plan hydration/hash preservation | NOT_TESTED: actual HTTP whitelist, no-JS review, real browser accessibility |

## Executed validation

- `php wordpress/tests/free/selection-count.php` — **PASS**, 32 stub contract assertions (receipt `A2-selection-count.log`). Runtime label explicitly `NOT_TESTED`.
- The same harness against exact base production files using `WL206_SOURCE=/tmp/writeleash-approved-20261008/evidence/A-baseline-source` — **FAIL** as expected at explicit opt-in (`false != true`), proving the new regression distinguishes the baseline (receipt `A2-negative-baseline.log`; baseline source byte-identical to HEAD production files). An earlier negative-control attempt lacked an included dependency; it was repaired before taking this result.
- `php wordpress/tests/free/unit.php` — **PASS**, 2,607 assertions.
- `php wordpress/tests/free/sale-price.php` — **PASS**, 105 assertions.
- `php wordpress/tests/free/variations.php` — **PASS**, 73 assertions.
- `php wordpress/tests/admin/admin-audit.php wordpress/writeleash` — **PASS**, unchanged audit. Initial direct selector reference in Admin failed this check; delegation through Product_Discovery repaired the implementation without modifying the audit.
- `php wordpress/tests/jobs/no-replan-audit.php wordpress/writeleash` — **PASS**, Apply and Undo execution still lack selector/planner/catalog re-query calls.
- `python3 .github/ci/ownership.py --audit` — **PASS**. Selected CODE_INTEGRATION owners: `admin`, `jobs`, `journal`, `plan`, `undo`; acceptance is deferred release ownership, not dispatched here.
- `php wordpress/tests/release/package-preflight.php wordpress/writeleash wordpress/release/writeleash-distribution-files.txt`, inventory/public audits, readme validation, historical shim cases, and claim-matrix audit — **PASS**, unchanged manifest/public support claims.
- Changed PHP lint, `node --check wordpress/writeleash/includes/free/free-selection.js`, `sh -n wordpress/tests/free/in-container.sh`, `git diff --check` — **PASS**.
- Full battery rerun 2026-10-08 from the clone root: every command above **PASS** with receipts `A2-*.log` in `/tmp/writeleash-approved-20261008/evidence/`. `python3 .github/ci/ownership.py <10 changed paths>` returned `{"PR_FAST": true, "CODE_INTEGRATION": ["admin", "jobs", "journal", "plan", "undo"], "RELEASE_FULL_OWNERS": ["acceptance", "admin", "jobs", "journal", "plan", "undo"], "DEFERRED_RELEASE_OWNERS": ["acceptance"]}` (`A2-ownership-selection.log`); CODE_INTEGRATION is not dispatched without runtime.
- GitNexus commit gate (resolved runtime): `analyze --index-only` incremental refresh, then `status` — **up-to-date**, runner identity current, 356/356 covered files (`A2-status-fresh.log`). `detect-changes --scope all` and `--scope staged` both completed — **10 files, 67 changed symbols, 25 affected processes, risk `critical`**, no `partial`/`truncated` (`A2-detect-all.json`, `A2-detect-staged.json`, byte-identical). The critical risk matches the pre-edit `post_input` impact. Implementation commit created: `c8cbf6c283545658add407745b3c2d605fcd8b14`.
- `bash .github/ci/pr-fast.sh` — **FAIL** at the unchanged #170 UI source/capture fingerprint in `asset-audit.php` (`UI source differs from reviewed capture`); every preceding gate in the log PASSed, so this is the first and only failure (`A2-pr-fast.log`). Historical screenshot/proof evidence was not rehashed or altered. Fresh real-browser capture review is required before this gate can pass.

## Unrun validation and blockers

Docker is unavailable: earlier `docker info` failed with `permission denied while trying to connect to the docker API at unix:///var/run/docker.sock`; in the current runtime `/var/run/docker.sock` is absent and the daemon is inactive (`A2-docker-probe.log`). Actual CODE_INTEGRATION plan/journal/jobs/undo/admin suites, new real-Woo/HTTP fixtures, no-JS/browser accessibility, default and Redis behavior are **NOT_TESTED / BLOCKED**, not PASS. The three Admin fixtures were attempted standalone and abort on missing WP runtime helpers (`admin_get`, `wp_set_current_user`) — they require `wordpress/tests/admin/run.sh` (Docker compose: pinned Woo 11.1.2/Redis 2.7.0, MySQL 8.0.44 + MariaDB 10.11.15, `wp eval-file` integration/browser e2e); receipts `A2-admin-selection-*.log`. No privileged/Docker/network workaround was attempted. RELEASE_FULL is neither authorized nor dispatched.

Prepared fixtures run through the existing plan/Admin CI entrypoints. The real Admin fixture covers nested taxonomy, count/Preview divergence, exact frozen execution excluding later category additions, authorization, unreadable targets, limits and old-plan hydration. The HTTP fixture exercises real `filter_input` transport for the toggle and native no-JS count/review with existing accessibility checks. These files are preparation, not runtime evidence.

## Safety, graph and integration

Plan/calculation/approval semantics remain owned by the existing planner and frozen material. Conflicts, bounded Resume, journal outcomes, History and eligible conflict-aware Undo are unchanged. The no-replan audit passes; actual behavior remains untested here.

Pre-edit graph impacts were LOW for category, resolve, expansion, selection form/process/context methods and the selection client. `post_input` was **CRITICAL** with six direct callers (Preview, approval, Resume, Undo, export and rendering); the warning was surfaced before adding the single checkbox field. No existing input contracts changed. Product_Discovery class and test harness class walks returned UNKNOWN; targeted text searches confirmed their real uses before the narrow addition/constructor adaptation. The previously blocked graph gate was rerun in the resolved runtime: the index was refreshed incrementally and `status` reported up-to-date with a current runner identity and 356/356 covered files; `detect-changes --scope all` and `--scope staged` both completed with no `partial` or `truncated` result (10 files, 67 changed symbols, 25 affected processes, risk `critical`). Commit `c8cbf6c283545658add407745b3c2d605fcd8b14` was created; `git diff c48e307..HEAD` sha256 equals the frozen `A-snapshot.patch` hash `31589065032ce35df2df7ed72b2263c9f6b331434986a0cbb117ab2e7ff4891d`. Compare analysis is NOT_TESTED. Process enumeration retains the analyzer's documented bounded flow inventory; absence of an enumerated flow is not treated as absence of a caller.

Actual collisions: Agent B's cache freshness substitutions touch selector Woo read calls within resolve/parent/expansion and Product_Discovery readable-product observations; Agent A preserves those call sites. Integrate B before A with semantic hunk review. Agent C edits other methods in Free_Admin, while A owns only post_input's toggle entry, build_selection, retained_inputs, process_selection, selection form and narrow Preview scope disclosure. D avoids these production lines. Do not overwrite overlapping hunks. Re-run focused contracts and all selected owners on any combined candidate.

Founder attention: review bounded raw-category discovery distinction; approve any future repository-changing integration step (push/PR/merge); arrange authorized Docker runtime execution and fresh browser capture evidence before acceptance. Code and local contract preparation are complete and committed at `c8cbf6c283545658add407745b3c2d605fcd8b14`. Remaining blockers: PR_FAST reviewed-capture fingerprint and all runtime/UI criteria; issue #206 is not claimed closed or release-ready. No tool monkeypatch, ignored error, or sandbox workaround was used.
