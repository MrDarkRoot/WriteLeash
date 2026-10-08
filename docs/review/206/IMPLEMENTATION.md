# Issue #206 implementation handoff

Scope: explicit Include subcategories and bounded informational expanded target-count discovery. Local preparation only; founder retains GO / NO-GO. No push, PR, merge, deployment, issue update, release artifact regeneration, or support expansion.

| Field | Evidence |
|---|---|
| Issue | #206 — Include subcategories and show the expanded product selection |
| Base SHA | `c48e307efe7750d0fb36015c39d50a34675f9579` |
| Branch | `work/206-selection` in `/tmp/writeleash-approved-20261008/agent-A` |
| Head SHA | `899a115e5a3b8432dd92d77eddde89062ab31b7c` (review-fix commit `fix(selection): deduplicate raw category rows before the discovery bound (#206)`, 4 files) on top of `fb2ecd4dd852cc4b394c25f3af316ced38092462`. Feature commit `c8cbf6c283545658add407745b3c2d605fcd8b14` (10 files) and its frozen A snapshot patch hash `31589065032ce35df2df7ed72b2263c9f6b331434986a0cbb117ab2e7ff4891d` remain historical. Baseline `c48e307efe7750d0fb36015c39d50a34675f9579`. |
| PR status | Draft PR #219 on `work/206-selection`; review-fix commit pushed without force |
| Scope deviation | None beyond the reviewed P1 fix. The deduplication and CATEGORY-only SQL DISTINCT guard narrow a false refusal inside the existing 2,001-row candidate sentinel; no selection/approval ceiling increased and no generic query engine added. |
| Support claims | Unchanged. Existing public package manifest and capture/proof evidence preserved. |
| Acceptance verdict | **PARTIAL**: review-fix commit passes the selection/unit/sale/variation/audit/package battery and the GitNexus commit gate; actual WooCommerce/Admin/browser runtime and fresh UI evidence remain NOT_TESTED (Docker unavailable), and PR_FAST still fails the unchanged #170 reviewed-capture fingerprint |

## Files changed

- `wordpress/writeleash/includes/free/class-product-selector.php`
- `wordpress/writeleash/includes/free/class-product-discovery.php`
- `wordpress/writeleash/includes/free/class-free-admin.php`
- `wordpress/writeleash/includes/free/free-selection.js`
- `wordpress/tests/free/selection-count.php`
- `wordpress/tests/free/integration.php`
- `wordpress/tests/free/in-container.sh`
- `wordpress/tests/admin/subcategory-integration.php`
- `wordpress/tests/admin/selection-integration.php`
- `wordpress/tests/admin/selection-http.php`
- `docs/review/206/IMPLEMENTATION.md`

## Behavior and bounds

`Price_Selection_Spec::category($id, $include_children = false)` retains the old false shape by default. The explicit boolean drives the public WP taxonomy query. CATEGORY selection remains in the existing canonical hashed plan material; resolved IDs are frozen separately. No hydration/schema/hash-version change is needed. Direct-members plans still hydrate without rewriting material or hashes.

The authenticated native **Check selection count** action delegates through the existing `Product_Discovery` boundary to the exact selector used by Preview. It does not validate/compute a price, create a plan/job, approve, or invoke a worker. Count includes distinct expanded targets, with explicit unreadable and missing counts; counts are not claims of eligibility. Denied targets refuse disclosure. Empty and over-limit selections are explained. The count is rendered only from a server-produced result, is never accepted as plan authority, and client edits mark it stale. Without JavaScript the last-check wording and native button remain available.

A category query admits at most 2,001 returned rows, with a refusal sentinel above 2,000 distinct posts. `resolve()` keys `WP_Query` rows by post ID before the sentinel and before any Woo read, and the CATEGORY query asks WordPress for SQL `DISTINCT` (`posts_distinct`) because tax_query joins return one row per matching descendant membership. Duplicates therefore neither inflate the count, falsely refuse a valid selection, nor resolve a product twice. Sufficiency: every distinct raw post is either a final target or the core parent of at least one expanded variation; distinct core parents own disjoint child sets, so parents ≤ final targets and the distinct raw population ≤ 2 × final targets ≤ 2,000 for ≤1,000 final targets. Any distinct raw population above 2,000 therefore implies more than 1,000 final targets, so the bound never refuses an otherwise-valid selection; with `DISTINCT` collapsing the joins before `LIMIT`, a population at or below the bound is complete rather than truncated. Deduplication and expansion still refuse 1,001 final targets. IDS/SKU input ceilings remain unchanged. A no-child variable parent stays one explained unsupported target. Oversized child expansion stops after the 1,001st distinct target read instead of reading an entire oversized variable catalog; Woo's public `get_children()` still owns child-ID retrieval.

### Review-bullet audit (P1 raw candidate bound and expansion)

| Review bullet | Finding | Disposition |
|---|---|---|
| Reject a valid final selection because intermediate raw rows exceed the bound | WP_Query does not add SQL DISTINCT for tax_query joins, so overlapping descendant membership returned one row per join and `count( $query->posts ) > 2,000` counted duplicates. A valid ≤1,000-target selection could be falsely refused. | Fixed: rows keyed by ID before the sentinel and before any Woo read; CATEGORY query requests SQL DISTINCT. Regression: 2,501 duplicate rows for one product are accepted (was refused at bump `fb2ecd4`). |
| Truncate the queried population without identifying incompleteness | Without DISTINCT, duplicate rows could crowd the 2,001-row page and hide distinct posts; the sentinel counted rows, not distinct posts. | Fixed: `posts_distinct` collapses joins before `LIMIT`, so ≤2,000 distinct posts are complete and the 2,001st distinct post refuses; PHP keying keeps the invariant if a cache/plugin ignores DISTINCT. Also: any distinct raw >2,000 implies >1,000 final targets, so refusal is never a false refusal. |
| Count one product/variation multiple times | Products and unreadable IDs are keyed by ID; variation children are `array_unique` and skipped when already present; final IDs are `array_unique`. | Already sound; 1,001-raw parent+directly-categorized-child overlap deduplicates to 1,000 targets; repeated `get_children()` IDs deduplicate. |
| Misleading results after category membership changes | Count is informational; Preview independently re-resolves the live category and freezes exact IDs; execution consumes the frozen plan and never reselects. | Already sound; count/Preview divergence and post-Preview category additions are covered by stub and real-WP fixtures. |
| Misrepresent unsupported variable parents | A core variable parent with no readable children stays itself as one explained unsupported target (count includes it; Preview reports it as skipped/unsupported). Extension subclasses are not expanded. | Already sound; no-child parent and unsupported-parent count representation covered. |

Counts versus Preview/eligibility: `discover_count()` keeps its `{selected, unreadable, missing}` shape, and the rendered copy already says counts are informational, that Preview resolves again and freezes the exact products, and that Preview explains skipped or unsupported products. No new engine, return shape change, or copy change was needed.

Preview independently resolves the live selector again, freezes exact IDs, and includes the descendant scope in the saved review. Count/Preview divergence therefore cannot approve products without that final review. Execution continues to consume hydrated frozen items and never reselects a category.

## Acceptance criteria

`PASS` below is restricted to the executed contract/static checks. It does **not** establish actual WP query, Woo cache, HTTP, browser, or accessibility behavior.

| Live issue criterion | Contract evidence | Full acceptance |
|---|---|---|
| Direct/default and descendants across nested categories without duplicates | PASS: new 46-assertion harness models three taxonomy levels, overlapping membership, direct defaults and opt-in, plus duplicate tax-query join rows that must not refuse a valid selection | NOT_TESTED: real taxonomy/Admin fixture prepared |
| Variable-parent expansion/count matches planner targets and overlapping membership | PASS: exact shared resolver, repeated children, explicit child+parent overlap, no-child parent, 1,001 raw / 1,000 final boundary | NOT_TESTED: actual Woo variable-parent behavior |
| Empty, unreadable and over-limit explained; 1,000/1,001 intact | PASS: empty/missing/unreadable/inaccessible and final boundaries; bounded sentinel reads | NOT_TESTED: actual rendered/native count and cache profiles |
| Category change between count and Preview cannot authorize unseen products; execution never reselects | PASS: count diverges from independent Preview; later category additions absent from hydrated IDs; unchanged no-replan audit | NOT_TESTED: real Apply execution after category change |
| Scope visible in review/retained context; old direct plans hydrate | PASS: true scope in hashed material, false default, byte-for-byte direct plan hydration/hash preservation | NOT_TESTED: actual HTTP whitelist, no-JS review, real browser accessibility |

## Executed validation

- `php wordpress/tests/free/selection-count.php` — **PASS**, 46 stub contract assertions (receipt `fix-selection-count.log`). Runtime label explicitly `NOT_TESTED`.
- The same harness against exact base production files using `WL206_SOURCE=/tmp/writeleash-approved-20261008/evidence/A-baseline-source` — **FAIL** as expected at explicit opt-in (`false != true`), proving the original regression distinguishes the baseline (receipt `A2-negative-baseline.log`; baseline source byte-identical to HEAD production files). An earlier negative-control attempt lacked an included dependency; it was repaired before taking this result.
- The 46-assertion harness against the pre-fix selector bytes (`fb2ecd4`) — **FAIL** as expected: the duplicate-row acceptance test throws `selection_limit_exceeded` at the old row-count check (receipt `fix-negative-prev.log`), proving the new regression distinguishes the bug rather than restating the implementation.
- `wordpress/tests/free/integration.php` overflow fixture updated for real WP: a 2-product IDS selection inflated with 1,001 duplicate join rows must still freeze both IDs, and a synthetic 1,001-distinct-row overflow must still refuse. Docker/Woo runtime suite; **NOT_TESTED** locally.
- `php wordpress/tests/free/unit.php` — **PASS**, 2,607 assertions.
- `php wordpress/tests/free/sale-price.php` — **PASS**, 105 assertions.
- `php wordpress/tests/free/variations.php` — **PASS**, 73 assertions.
- `php wordpress/tests/admin/admin-audit.php wordpress/writeleash` — **PASS**, unchanged audit. Initial direct selector reference in Admin failed this check; delegation through Product_Discovery repaired the implementation without modifying the audit.
- `php wordpress/tests/jobs/no-replan-audit.php wordpress/writeleash` — **PASS**, Apply and Undo execution still lack selector/planner/catalog re-query calls.
- `python3 .github/ci/ownership.py --audit` — **PASS**. Selected CODE_INTEGRATION owners: `admin`, `jobs`, `journal`, `plan`, `undo`; acceptance is deferred release ownership, not dispatched here.
- `php wordpress/tests/release/package-preflight.php wordpress/writeleash wordpress/release/writeleash-distribution-files.txt`, inventory/public audits, readme validation, historical shim cases, and claim-matrix audit — **PASS**, unchanged manifest/public support claims.
- Changed PHP lint, `node --check wordpress/writeleash/includes/free/free-selection.js`, `sh -n wordpress/tests/free/in-container.sh`, `git diff --check` — **PASS**.
- Full battery rerun 2026-10-08 from the clone root: every command above **PASS** with receipts `A2-*.log` in `/tmp/writeleash-approved-20261008/evidence/`. `python3 .github/ci/ownership.py <10 changed paths>` returned `{"PR_FAST": true, "CODE_INTEGRATION": ["admin", "jobs", "journal", "plan", "undo"], "RELEASE_FULL_OWNERS": ["acceptance", "admin", "jobs", "journal", "plan", "undo"], "DEFERRED_RELEASE_OWNERS": ["acceptance"]}` (`A2-ownership-selection.log`); CODE_INTEGRATION is not dispatched without runtime.
- Review-fix battery 2026-10-08 from the clone root: selection-count (46), unit (2,607), sale-price (105), variations (73), admin-audit, no-replan, ownership `--audit`, package-preflight, inventory/public audits, readme validation, historical shim and claim-matrix all **PASS** with receipts `fix-*.log` in `/tmp/writeleash-approved-20261008/evidence/pr-fix/219/`. `fix-negative-prev.log` records the expected pre-fix failure.
- GitNexus commit gate for the fix: index refreshed to the current worktree, `detect-changes --scope all` — **3 files, 10 changed symbols, 7 affected processes, risk `high`**, no `partial`/`truncated` (`fix-detect-all.json`); `--scope staged` captured before commit (`fix-detect-staged.json`). Pre-edit impacts for `resolve`, `expand_variable_parents` and `discover_count` were LOW; `resolve` reported an `epistemic: lower-bound` receiver-typing gap of 3 call sites, confirmed by text search (`class-change-plan.php`, `class-product-discovery.php`, tests) before editing.
- `bash .github/ci/pr-fast.sh` — **FAIL** at the unchanged #170 UI source/capture fingerprint in `asset-audit.php` (`UI source differs from reviewed capture`); every preceding gate in the log PASSed, so this is the first and only failure (`A2-pr-fast.log`, rerun `fix-pr-fast.log`). Historical screenshot/proof evidence was not rehashed or altered. Fresh real-browser capture review is required before this gate can pass.

## Unrun validation and blockers

Docker is unavailable: earlier `docker info` failed with `permission denied while trying to connect to the docker API at unix:///var/run/docker.sock`; in the current runtime `/var/run/docker.sock` is absent and the daemon is inactive (`A2-docker-probe.log`). Actual CODE_INTEGRATION plan/journal/jobs/undo/admin suites, new real-Woo/HTTP fixtures, no-JS/browser accessibility, default and Redis behavior are **NOT_TESTED / BLOCKED**, not PASS. The three Admin fixtures were attempted standalone and abort on missing WP runtime helpers (`admin_get`, `wp_set_current_user`) — they require `wordpress/tests/admin/run.sh` (Docker compose: pinned Woo 11.1.2/Redis 2.7.0, MySQL 8.0.44 + MariaDB 10.11.15, `wp eval-file` integration/browser e2e); receipts `A2-admin-selection-*.log`. No privileged/Docker/network workaround was attempted. RELEASE_FULL is neither authorized nor dispatched.

Prepared fixtures run through the existing plan/Admin CI entrypoints. The real Admin fixture covers nested taxonomy, count/Preview divergence, exact frozen execution excluding later category additions, authorization, unreadable targets, limits and old-plan hydration. The HTTP fixture exercises real `filter_input` transport for the toggle and native no-JS count/review with existing accessibility checks. These files are preparation, not runtime evidence.

## Safety, graph and integration

Plan/calculation/approval semantics remain owned by the existing planner and frozen material. Conflicts, bounded Resume, journal outcomes, History and eligible conflict-aware Undo are unchanged. The no-replan audit passes; actual behavior remains untested here.

Pre-edit graph impacts were LOW for category, resolve, expansion, selection form/process/context methods and the selection client. `post_input` was **CRITICAL** with six direct callers (Preview, approval, Resume, Undo, export and rendering); the warning was surfaced before adding the single checkbox field. No existing input contracts changed. Product_Discovery class and test harness class walks returned UNKNOWN; targeted text searches confirmed their real uses before the narrow addition/constructor adaptation. The previously blocked graph gate was rerun in the resolved runtime: the index was refreshed incrementally and `status` reported up-to-date with a current runner identity and 356/356 covered files; `detect-changes --scope all` and `--scope staged` both completed with no `partial` or `truncated` result (10 files, 67 changed symbols, 25 affected processes, risk `critical`). Commit `c8cbf6c283545658add407745b3c2d605fcd8b14` was created; `git diff c48e307..HEAD` sha256 equals the frozen `A-snapshot.patch` hash `31589065032ce35df2df7ed72b2263c9f6b331434986a0cbb117ab2e7ff4891d`. Compare analysis is NOT_TESTED. Process enumeration retains the analyzer's documented bounded flow inventory; absence of an enumerated flow is not treated as absence of a caller.

Actual collisions: Agent B's cache freshness substitutions touch selector Woo read calls within resolve/parent/expansion and Product_Discovery readable-product observations; Agent A preserves those call sites. Integrate B before A with semantic hunk review. Agent C edits other methods in Free_Admin, while A owns only post_input's toggle entry, build_selection, retained_inputs, process_selection, selection form and narrow Preview scope disclosure. D avoids these production lines. Do not overwrite overlapping hunks. Re-run focused contracts and all selected owners on any combined candidate.

Founder attention: review bounded raw-category discovery distinction; approve any future repository-changing integration step (push/PR/merge); arrange authorized Docker runtime execution and fresh browser capture evidence before acceptance. Code and local contract preparation are committed at `c8cbf6c283545658add407745b3c2d605fcd8b14`; the P1 review fix is committed at `899a115e5a3b8432dd92d77eddde89062ab31b7c` and pushed to draft PR #219. Remaining blockers: PR_FAST reviewed-capture fingerprint and all runtime/UI criteria; issue #206 is not claimed closed or release-ready. No tool monkeypatch, ignored error, or sandbox workaround was used.
