# Issue #204: live progress preparation

Status: Implementation committed as `6a6a9ac91550c928101bebd2085874580a1c416a` on `work/204-live-progress`; a review fix pass is committed as the branch head (`fix(admin): tighten live progress page/filter sync and boundary tests (#204)`; exact SHA recorded in PR #221 and the pr-fix receipts). GitNexus all/staged graph gates complete; runtime/browser acceptance NOT_TESTED; PR_FAST FAIL at preserved #170 UI evidence gate. Do not merge this branch on unit evidence.

| Field | Result |
|---|---|
| Issue | #204 Refresh running job progress without reloading the page |
| Base SHA | `c48e307efe7750d0fb36015c39d50a34675f9579` |
| Branch | `work/204-live-progress` |
| Head SHA | `6a6a9ac91550c928101bebd2085874580a1c416a` (implementation commit; base `c48e307efe7750d0fb36015c39d50a34675f9579`). Previous `spawnSync git EPERM` child-process blocker resolved in the 2026-10-08 runtime; complete graph gates below |
| PR status | Not created; no push, merge, release dispatch, publication or deployment |
| Support claims | Unchanged; no listing or supported ceilings changed |
| Scope deviation | None in product scope. The approved new JS asset requires an explicit runtime allowlist/reference audit and manifest/ownership entries |

The authenticated `wp_ajax_writeleash_free_progress` callback (with an explicit no-data 403 denial for expired/anonymous sessions on the nopriv hook) accepts a job-bound nonce and bounded page/filter fields, requires both existing capabilities and actor authorization, and calls only saved plan/job/item/Undo observations. It does not install tables, invoke workers, Resume, live product observation or cache eviction. Missing saved Apply evidence disables actions and polling and reports unavailable evidence. Repository failures produce an unavailable response. Existing mutation handlers remain the authority for any separately clicked action.

The PHP job page retains its ordinary navigation, manual refresh and eligible forms with JavaScript disabled. The small client updates Apply/Undo counters, saved outcome rows, available actions and page availability. Existing current-price cells retain their explicit page-load meaning. A newly appearing filtered row asks for a reload to read its current price; polling never fetches current catalog prices. Focused action controls stay present but disabled until focus leaves; focused outcome details and removed filtered review links retain their node and display an explicit stale-detail message. A disappearing focused Next-page link becomes inaccessible to activation and hides after blur. The polite live status announces changing counters, never the product table. Only one request runs at a time; timeout and visibility cancellation advance a generation fence so a late response cannot overwrite newer observations. Polling stops for inactive/stalled/terminal work, pauses on hidden pages, times out at 15 seconds, backs off to 10/20/40 seconds, and stops after four failures. Session/capability refusal prompts reload/sign-in and preserves the last successful refresh time.

## Acceptance accounting

| Issue/user criterion | Status | Evidence and limit |
|---|---|---|
| Real running Apply and Undo advance without page reload with durable counts | NOT_TESTED | Shipped-client deterministic tests and PHP boundary doubles PASS. Added real-Woo worker/count fixture, but Docker access unavailable; no real browser advancement claim |
| Conflict/failure/uncertain/pending/unchanged remain distinct; stalled is honest | PARTIAL | Endpoint uses existing saved labels and separate durable counters; PHP double tests cover stalled/terminal/missing/error; JS tests retain attention and uncertainty. Actual Woo acceptance NOT_TESTED |
| Authenticated/capability/actor refusal; no product/job/Undo writes, worker, schema or Resume | PARTIAL | PHP boundary unit negative controls PASS. Dedicated real-Woo fixture audits every poll SQL query for DML/DDL/DCL and Woo product reads, but execution NOT_TESTED; real AJAX transport journey NOT_TESTED |
| Out-of-order observations cannot regress; session/error display actionable | PARTIAL | Actual shipped JS executed against deterministic timeout/hidden/late-response/403/network boundaries PASS; browser/server session expiry NOT_TESTED |
| Keyboard focus and concise accessible announcements | PARTIAL | Deterministic focused action, focused outcome, disappearing pager and repeated-announcement tests PASS. Browser keyboard and accessibility tree/axe NOT_TESTED |
| Existing Admin/auth/no-JS/manual refresh usable | NOT_TESTED | Manual refresh and native forms preserved; Admin source audit PASS. Focused real-Woo fixture asserts terminal form absence and manual/no-JS text but execution NOT_TESTED; existing full Admin runtime NOT_TESTED |
| No schema migration, scheduler, reapplication, mutation rewrite or broad matrix | PASS (source scope) | Only Admin view/read endpoint, isolated client, focused tests and package registration changed |

## Changed files

- `.github/ci/path-ownership.json`
- `docs/review/204/IMPLEMENTATION.md`
- `wordpress/release/PUBLIC-PAYLOAD.md`
- `wordpress/release/writeleash-distribution-files.txt`
- `wordpress/tests/admin/in-container.sh`
- `wordpress/tests/admin/run.sh`
- `wordpress/tests/admin/progress-client.cjs`
- `wordpress/tests/admin/progress-unit.php`
- `wordpress/tests/admin/progress-integration.php`
- `wordpress/tests/release/public-runtime-audit.php`
- `wordpress/writeleash/includes/free/class-free-admin.php`
- `wordpress/writeleash/includes/free/free-progress.js`

## Local verification

Commands are run from this branch checkout. PASS below is restricted to the command's actual coverage.

| Command | Actual outcome |
|---|---|
| `node wordpress/tests/admin/progress-client.cjs` | PASS; shipped client executed with deterministic DOM/fetch/timers; not a browser or Woo runtime test |
| `php wordpress/tests/admin/progress-unit.php` | PASS; real endpoint function with repository doubles; not Woo runtime correctness |
| `php wordpress/tests/admin/admin-audit.php wordpress/writeleash` | PASS existing Admin authority/source audit |
| `php wordpress/tests/release/package-preflight.php wordpress/writeleash wordpress/release/writeleash-distribution-files.txt` | PASS exact explicit public closure, 43 entries |
| `php wordpress/tests/release/inventory-audit.php wordpress/writeleash wordpress/release/PUBLIC-PAYLOAD.md wordpress/release/writeleash-distribution-files.txt` | PASS existing PHP classification; new JS documented |
| `php wordpress/tests/release/public-audit-cases.php wordpress/writeleash wordpress/release/writeleash-distribution-files.txt` | PASS existing positive/negative closure tests |
| `python3 wordpress/release/test-artifact.py` | PASS 10 artifact tests; no release publication |
| `python3 .github/ci/ownership.py --audit` | PASS existing 59 PHP/13-owner policy, no weakened rules |
| `python3 .github/ci/ownership.py <changed paths>` | PASS selected CODE_INTEGRATION `admin`; release ownership `acceptance,admin`, acceptance deferred by existing cost policy |
| `python3 .github/ci/dependency-audit.py` | PASS |
| `php wordpress/tests/release/readme-validate.php wordpress/writeleash` | PASS unchanged claims/listing |
| `php wordpress/tests/release/historical-shim-cases.php wordpress/writeleash` | PASS immutable historical shim controls |
| `php -l` changed PHP; `node --check` changed JS; `bash -n` changed scripts; `git diff --check` | PASS (`C2-lint.log`). `sh -n` applies to `in-container.sh` (PASS) but not to `run.sh`, which is `#!/usr/bin/env bash`; baseline `run.sh` fails `sh -n` identically, only invocation lines were added |
| public-runtime allowlist negative control (exact guards, no weakening) | PASS (`C2-guard-control.log`): real 43-file manifest accepted; unknown `free-progress.js.map`/`other.js` rejected `non-public artifact`; `new $class($id)` still rejected `dynamic class dependency`; unreferenced `free-progress.js` rejected. Additions are only the finite manifest entry plus the explicit referenced-asset loop; the dynamic static/`new $variable` rejects are unchanged |
| `bash .github/ci/pr-fast.sh` | FAIL at exactly `#122 assets: #170 UI source differs from reviewed capture` (`asset-audit.php:100`); `C2-pr-fast.log` is byte-identical to the prior `C-pr-fast-first.log` apart from the Python test timing; every gate before it PASSes and no new/earlier failure exists. Original capture/proof hashes and rejection are preserved; gate not edited/rehashed |
| `docker info --format '{{.ServerVersion}}'` | BLOCKED: `/var/run/docker.sock` absent, docker service inactive (2026-10-08 runtime); no bypass and no sudo attempted (`C2-docker-blocker.log`) |
| `bash wordpress/tests/admin/run.sh` / real-Woo `progress-integration.php` | NOT_TESTED runtime (Docker boundary); host invocation fails at `wp_set_current_user()` because no WordPress bootstrap exists outside the container harness. Added fixture runs in existing two-engine/default/Redis Admin suite; no new matrix |
| Real-browser keyboard, live progress, expired AJAX session and accessibility | NOT_TESTED; unit DOM does not establish browser behavior |
| GitNexus `detect-changes --scope all --repo WriteLeash` | PASS complete (exit 0): 12 files, 136 symbols, 21 affected processes, risk `critical`; no partial/truncated marker; receipt `C2-detect-all.json` |
| GitNexus `detect-changes --scope staged --repo WriteLeash` | PASS complete (exit 0): same 12/136/21 counts, risk `critical`; no partial/truncated marker; receipt `C2-detect-staged.json` |
| Exact-SHA RELEASE_FULL | NOT_TESTED; not separately authorized, not dispatched |

Failing PR_FAST evidence is retained at `/tmp/writeleash-approved-20261008/evidence/C2-pr-fast.log` (prior run `C-pr-fast-first.log`); Docker evidence at `C2-docker-blocker.log` in the same task-owned evidence directory. Prior passing baseline PR_FAST does not cover this changed head.

## 2026-10-08 resumption receipts

The staged 12-file state was re-verified against `STATE.json` byte-for-byte before the commit. Full battery receipts (all PASS unless stated) are in `/tmp/writeleash-approved-20261008/evidence/`: `C2-progress-client.log`, `C2-progress-unit.log`, `C2-admin-audit.log`, `C2-package-preflight.log` (43-file closure), `C2-inventory-audit.log`, `C2-public-audit-cases.log`, `C2-test-artifact.log`, `C2-ownership-audit.log`, `C2-ownership-selection.log` (`CODE_INTEGRATION: admin`), `C2-dependency-audit.log`, `C2-readme-validate.log`, `C2-historical-shim.log`, `C2-lint.log`, `C2-guard-control.log`, `C2-gitnexus-status.log`, `C2-gitnexus-analyze.log`, `C2-detect-all.json`, `C2-detect-staged.json`, `C2-pr-fast.log` (the expected FAIL), `C2-progress-integration-attempt.log`, `C2-docker-blocker.log`. The GitNexus index was refreshed with `analyze --index-only` (357 unchanged rows preserved) and `status` verified up-to-date before the gates. The `detect-changes` CLI text form reports complete counts and no partial/truncated marker; its listing is display-capped, and the summary counts shown are consistent with the full symbol population (15 shown + 121, 10 shown + 11). Implementation commit: `6a6a9ac91550c928101bebd2085874580a1c416a`.

## 2026-10-08 review fix pass (findings A/B)

The fix commit is the branch head after `a1b7f3a317abefe92cd1fce88775579e585df312`. Receipts: `/tmp/writeleash-approved-20261008/evidence/pr-fix/221/`.

Finding resolution:

| Finding | Resolution | Evidence |
|---|---|---|
| A. Apply/Undo counters advance without reload | VERIFIED UNCHANGED | Shipped-client boundary test (saved label/summary/undo writes) |
| A. Conflict/failure/stalled/uncertain stay distinguishable | VERIFIED UNCHANGED | `apply_attention`/`undo_attention` classes + separate durable counters in PHP unit and client tests |
| A. Visible rows match current filter/page; server row order reflected | **FIXED** (production) | `updateRows()` now reconciles DOM order to the server page list via `insertBefore`; client tests pin reorder, filter/page change, and empty-state removal. Old client fails the new reorder regression (verified) |
| A. Focused/outdated row never presented as current without explicit stale indicator | **FIXED (wording)** | Focused outcome, focused removed-review-link, and focused initial support details now state they still show the previous saved details/view; regression tests pin the phrases |
| A. Out-of-order responses cannot regress state | VERIFIED UNCHANGED | Generation fence; late response after timeout already covered and retained |
| A. Session expiry / network failures surface clearly | VERIFIED UNCHANGED | 403 message + backoff; test now also covers 401 |
| A. Terminal jobs and hidden pages stop/back off polling | VERIFIED UNCHANGED | Terminal `poll:false` schedules nothing; hidden page aborts, pauses and resumes |
| A. Manual/no-JS refresh stays functional; no framework | VERIFIED UNCHANGED | No-JS mount test (zero requests) plus source assertion that no framework is referenced |
| B. Reads require actor/capability + job-bound nonce | VERIFIED UNCHANGED | PHP unit: anonymous, cross-actor, revoked capability, wrong/missing non-string nonce, nonce bound to a different job |
| B. Cross-actor/guessed job IDs disclose no protected information | VERIFIED + TESTED | Exact two-key refusal shape; guessed public ID with its genuinely valid job-bound nonce refused after only the visibility lookup (no observe/plan/history/item reads) |
| B. Endpoint read-only, never starts a worker | VERIFIED + TESTED | `__callStatic` mutation sentinels on both repository doubles + `Job_Worker`/`Undo_Worker` sentinels assert empty after every case; negative controls confirmed the sentinels fail the suite when a mutation/worker call is injected |
| B. Resume/Undo POST handlers independently enforce authorization and durable state; UI availability/nonce never authorization | VERIFIED UNCHANGED (read) | `process_resume`/`process_undo` re-check capability, POST method, dependency, job lookup, action-bound nonce, actor authorization, terminal/resumable state, plan binding and product rights before `Job_Worker`/`Undo_Worker` run |
| B. Missing durable evidence fails closed | VERIFIED UNCHANGED | Incomplete evidence disables poll, Resume/Undo and both nonces; handler paths return INVALID/UNAVAILABLE on binding/read failure |

Behavior changes requiring justification:

1. `free-progress.js` `updateRows()` reconciles rendered row order to the server page order. Previously new rows were appended in response order and existing rows never moved, so the visible list could disagree with the durable page/filter list. A focused row is deliberately never moved (moving a focused node drops focus in browsers): it keeps its place and the remaining rows are ordered around it; with no focus (the normal case) the server order is exact. This is the only synchronization behavior change.
2. Focused stale text is now explicit ("still shows its previous saved details/view"). Mechanism unchanged (connection line as the indicator; focused nodes retained until blur).

New/strengthened local tests: server-order reconciliation (with and without focus), focused removed row/link and focused cell stale wording, filter/page change (no stale rows; empty-state removal), pager `next_url` create/update/hide/show path and terminal-shape pager (disabled Next button replaced by a link), 401 and 403 stop, first-failure backoff message, timeout abort plus retry request, announcement de-duplication retained, PHP-constant/action/field-name parity and every JS `data-*` selector checked against the PHP renderer, PHP unit job-bound nonce mismatch, guessed public ID, offset at/over the real `MAX_EVIDENCE_ROWS` (unit double now mirrors the production constant, asserted against `class-undo-repository.php`), missing/non-string nonce, method case, extra repository failure paths, and read-only mutation/worker sentinels. The integration fixture additionally audits the guessed-public-ID case through the poll-only SQL/product-read wrapper; it remains Docker-bound and NOT_TESTED.

Pager shape verification (unchanged): `render_pager()` emits one `<p>` with the Previous control first and the Next control last, so the shipped `querySelector('p').lastElementChild` targets the Next control for both the link and disabled-button shapes. The client and the test harness now both pin the terminal disabled-button shape; no selector change was needed.

## Safety and coordination

Frozen selection, exact Preview/approval, absolute planned prices, supported limits and worker/journal/Resume/Undo execution paths are unchanged. History remains repository-owned. The progress endpoint performs no catalog read or cache invalidation. Poll reads are separate read-only repository observations, so a concurrent worker can advance between queries; the view is informational and does not grant mutation authority. A later clicked Resume/Undo still revalidates through existing protected handlers. Missing saved evidence never enables actions.

The only shared production file is `class-free-admin.php`: this branch owns boot/assets/progress methods, job view and Undo view hooks; Agent A owns selection methods/form and Agent B owns `product_observation`. Independent clones prevent concurrent uncommitted edits. Resolve integration by preserving each narrow method hunk and rerunning the selected Admin tests; do not overwrite another agent or automatically merge.

GitNexus was rebuilt for this clone after copied storage was identified as foreign. Fresh schema-4 runner status and empty incomplete reasons were verified before graph-dependent work. Initial `render_undo_section` impact was HIGH (direct `render_job_view`, then render/preview flows) and reported before edits; boot/job-view/client changes were LOW. `assets` and public-runtime audit function returned UNKNOWN, so hook/callsite text confirmation was required and recorded. Graph collection has documented process-budget omissions; absence of a process is not treated as evidence of no effect. Final graph change receipts are retained separately in the task evidence directory.

Remaining blockers: recapture and review legitimate #170 UI evidence through the repository's approved process without weakening its hash gate; run the owning Admin DB/cache suite including the new real-Woo poll audit; run real browser Apply/Undo/keyboard/accessibility/session journeys; obtain founder approval before the next repository-changing integration step. No issue closure or merge recommendation on present evidence.

No sandbox bypass or checker patch was attempted. The prior `C-detect-all.json`/`C-detect-staged.json` EPERM bodies were superseded by the complete `C2` receipts; the task-owned `C-204-live-progress.patch` preserves the prepared implementation/test/document bytes for review. Code commit `6a6a9ac91550c928101bebd2085874580a1c416a` was created after all gates passed except the preserved PR_FAST #170 evidence FAIL. Focused outcome cells intentionally retain their node and may need manual refresh after terminal polling stops; real-browser validation remains required.
