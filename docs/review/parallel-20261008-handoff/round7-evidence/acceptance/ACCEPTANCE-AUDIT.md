# Round 7 independent acceptance lens: #204, #206 and #210

Review date: 2026-10-08. This lens reads the live issue JSON fetched by the parent auditor in `round7-evidence/issue-{204,206,210}.json`, independently reads exact production diffs, executes focused host tests, and distinguishes retained runtime claims from newly executed observations. It makes no production correction, commit, push, readiness change, issue closure or merge.

No reproduced production defect was found in this lens. The important acceptance gap is #204: the located historical live browser receipt demonstrates running Apply, but does not demonstrate running Undo advancing without reload. Real PHP Undo progress is a different observation. #210 remains preparation only, with the full issue PARTIAL and OPEN.

## Exact review surfaces

| Surface | Commit |
|---|---|
| Live main / diff base | `c48e307efe7750d0fb36015c39d50a34675f9579` |
| #219 / #206 | `eaad5dd0b1703997667474edb55f928fd2e75d0d` |
| #221 / #204 | `fd0dc73e8a1598da1c894a6be24be28f860c286d` |
| #222 / #210 preparation | `079f5dad148a8718586db05b4abc322047908710` |
| Combined candidate | `3b4ab5e8895fb60dbcb00d26d5c597f129b9ed8f` |

The combined checkout is `/tmp/writeleash-round7-integration`. Individual focused tests use exact detached checkouts `/tmp/writeleash-round7-pr219`, `/tmp/writeleash-round7-pr221`, and this lens's task-owned `/tmp/writeleash-round7-i18n`. Exact trees, commands, start/end times, actual exit statuses and output hashes are recorded in `receipts.json` and `reviewed-shas.txt`. Complete reviewed diffs are `pr219-full.diff`, `pr221-full.diff`, `pr222-full.diff`. Host versions are PHP 8.2.32 and Node 22.23.2, recorded in `host-versions.txt`.

Graph scope limitation: the parent performed the substantive review graph setup before dispatching this domain lens. This lens's available MCP query returned baseline main symbols (for example `render_job_view` at lines 1491–1591), rather than an aligned combined index; local graph augmentation was locked. That query is discovery context only. The review does not claim independently complete exact-head graph/PDG coverage. No symbol edits or commits were made. Exact source, diffs, executable negative controls and parent graph evidence remain distinct.

## Executed tests and bounded conclusions

| Exact target | Command | Exit | Observed result |
|---|---|---|---|
| #219 | `php wordpress/tests/free/selection-count.php` | 0 | 63 contract assertions PASS; query/Woo stubs; explicitly no runtime claim |
| Combined | Same selection count command | 0 | 63 contract assertions PASS; same runtime limitation |
| #221 | `node wordpress/tests/admin/progress-client.cjs` | 0 | 9,996 enumerated cases, 69,481 assertion checks, 1,198 focused-row removal deferrals, zero violations |
| Combined | Same progress client command | 0 | Same totals PASS |
| #221 | `php wordpress/tests/admin/progress-unit.php` | 0 | 148 checks PASS; repository doubles and verified mutation/worker sentinels |
| Combined | Same PHP progress command | 0 | 148 checks PASS |
| #222 | `php wordpress/tests/release/i18n-inventory-cases.php` | 0 | Allowlist scope, parsing, deterministic output, classification and refusal fixtures PASS |
| #222 | `php wordpress/release/i18n-inventory.php --summary` | 0 | 6,362 candidates / 38 files / 42 manifest entries; byte-identical to committed summary |
| Combined | Same inventory summary command | 0 | 6,802 candidates / 39 files / 43 manifest entries; current combined inventory, not a translated catalog |

New outputs are individual `.stdout`/`.stderr` files here, referenced by `receipts.json`. These are Round 7 host receipts; they are not historical originals, actual Woo execution, hosted checks or browser accessibility proof.

A separate compatibility probe generated a real serialized `Change_Plan` using exact main production classes and the deterministic query/Woo test doubles, then hydrated those exact bytes using the combined production classes. Both PHP commands exited 0; original/hydrated SHA-256 were identical, `4cc2a242ada123ba809c2f545bddd71de9d1156f32f6ad7eb654a09b1a349e7d`, with `include_children:false`, frozen ID `[10]` and unchanged plan hash. `old-plan-hydration-receipt.json`, both plan JSON files and the generator/hydrator harnesses preserve the new receipt. The initial scratch harness omitted the `class-product-discovery.php` dependency and exited 255; that harness error and the consequent empty-input hydration error are separately retained as `harness-missing-dependency-*`. Exporting all exact-main PHP dependency files corrected the harness; this was not a product fix or historical migration run.

The #222 summary comparison is independently byte-complete: regenerated and committed SHA-256 both `9cf090ecb01483cc1034854ce19d3dff45e30bab334b6229400d965a510256a5`. `i18n-summary-identity.json` records equality. The unchanged #222 production source and allowlist are independently established by a zero-byte `git diff main #222 -- wordpress/writeleash wordpress/release/writeleash-distribution-files.txt`, saved as `pr222-public-source-and-allowlist.diff`. Its preparation tooling reads the current distribution manifest and covers the additional combined progress script when invoked against the combined tree. The committed summary is explicitly a main baseline; it must not be represented as the current combined inventory.

## #204: criterion-by-criterion assessment

Paths and line anchors below refer to the exact combined checkout unless otherwise stated.

| Live criterion / component | Evidence | Status and limits |
|---|---|---|
| Real running Apply advances without page reload | Retained `runtime-acceptance-evidence/live/live-progress-result.json`: same job URL, `noReload: true`, 0 changed / 22 remaining → 10 changed / 12 remaining, refresh timestamps 2:17:56 → 2:18:01 | Historical Apply observation available; source identity for that receipt belongs to the earlier combined source and must be reconciled by parent browser audit before claiming current exact-source acceptance |
| Real running Undo advances on screen without page reload | `progress-integration.php:67-75` genuinely initiates Undo, runs one worker item and compares saved snapshots; client tests update Undo counts using simulated transport | PARTIAL: no located authentic live Undo browser advancement receipt; PHP snapshots and fake DOM do not meet the on-screen criterion |
| Displayed counts agree with saved observations | `Free_Admin::progress_snapshot` reads `Job_Repository::observe`, `Undo_Repository::observe`, and `history_items`; `progress-integration.php:57-58` compares polled Apply counts with durable rows | Host boundary PASS; real exact-source runtime depends on parent runner evidence; no invented completion |
| Conflict remains distinct from success | Combined `class-free-admin.php:251-260`, `:1109-1113`: shared long conflict guidance used in polled and rendered rows; PHP/client tests demand exact long guidance and forbid abbreviated variants | PASS focused regression; preserve Round 4 fix |
| Failure/uncertain/stalled remain distinct | PHP unit tests stalled Apply and Undo, incomplete evidence, observer/plan failures; client verifies attention classes and uncertainty announcements; runtime fixture tests missing item evidence | PASS focused boundaries; authentic runtime final classification separately owned by parent |
| Polling mutates no job/product/Undo state | Progress handler uses saved reads only; PHP sentinels verify no repository writer or worker and self-test interception of every modeled writer | PASS host negative controls; real SQL audit is implemented at `progress-integration.php:14-23` and must be credited only when its actual runtime receipt exists |
| Polling installs no schema/invokes no workers | `jobs_installed` uses `Job_Schema::ready`, not installation; worker calls are absent from handler; sentinels detect run/queue/callback | PASS inspected source and host controls |
| Unauthorized/cross-actor reads refused without disclosure | `class-free-admin.php:218-235` checks login, capabilities, POST, UUID, nonce, bounded filter and offset; `load_job_for_view:1431-1440` checks actor authorization before observation; PHP negative tests include valid own nonces for other actor jobs | PASS host boundaries; actual logged-in runtime fixture requests at `progress-integration.php:38-52` require separate execution evidence |
| Out-of-order responses cannot regress state | `free-progress.js:136-159`: one in-flight controller, per-request generation; timeout/hidden/pagehide invalidate generation before abort; test resolves stale timed-out responses after later request | PASS executed transport/timer control |
| Expired/failed sessions have actionable stale message | `free-progress.js:129-134`: 401/403 stop and require reload/sign-in; repeated failures stop with manual refresh; earlier failures show stale display/backoff; last successful refresh appended | PASS executed failure paths |
| Keyboard focus retained | Existing row/control nodes retained; focus-sensitive outcome/row/pager/support updates defer; 9,996 enumerated order/focus cases and 1,198 removal deferrals PASS | PASS deterministic DOM behavior; actual browser focus mutation coverage belongs to #170 and must not be inferred from the stub DOM alone |
| Concise accessible announcements avoid rereading table | `class-free-admin.php:1630` polite atomic status region; `free-progress.js:121-122` updates only changed summary, never announces full table; focused tests verify repeated unchanged announcement behavior | PASS source/client contract; actual assistive technology behavior NOT_TESTED here |
| Manual/no-JS and targeted Admin/auth journeys remain usable | Server-rendered refresh link/notice at `class-free-admin.php:1630`; progress runtime fixture asserts both strings and absence of terminal Resume form; #170 runner contains a JavaScript-disabled context at `regression-browser.cjs:166` | Source/host PASS; authentic end-to-end result belongs to browser/runtime audit |

Issue-level verdict until the running Undo browser gap is filled: **CODE_PASS / ACCEPTANCE_PARTIAL / HOLD**. This is an evidence gap, not a demonstrated production defect. Per-PR hosted gates are independent and are not adjudicated by this lens.

## #206: criterion-by-criterion assessment

| Live criterion / component | Evidence | Status and limits |
|---|---|---|
| Direct-only remains default | `Price_Selection_Spec::category:39-41` default false; `resolve:66` explicit fallback false; Admin only string `'1'` enables opt-in | PASS exact source and executed contract |
| Explicit descendants across nested categories | WP `tax_query.include_children`; fixture root/child/grandchild; contract asserts exact direct and descendant ID lists | PASS executed stub contract; real WP execution separately owned by runtime audit |
| Overlapping membership deduplicated | Token-scoped `posts_distinct` before LIMIT, then raw-ID keying at selector `:86-87`; short duplicate page accepted, full stripped-DISTINCT window refused before Woo reads | PASS negative and positive controls; nested unrelated query keeps its own DISTINCT clause and finally cleanup verified |
| Variable parent expansion matches planner/count targets | `discover_count` calls the same resolver as Preview; parent/children and direct-category variation overlap fixtures assert identical deduplicated final semantics | PASS executed contract; no second execution-time count authority |
| Empty selections explained before approval | count returns selected=0; native form renders no-match guidance; real fixture `subcategory-integration.php:53-54` | PASS contract/source; runtime receipt needed for current real form |
| Unreadable/missing populations explained | count tracks unreadable/missing frozen target slots; fixture throws real Woo product-class read failure; permission-denied target refuses even count disclosure | PASS contract and source; unreadable is not silently dropped |
| 1,000 accepted / 1,001 refused | 2,001 distinct raw sentinel accommodates parents+children before final 1,000 bound; 63 tests include 1,000 plain targets, 1,001 expanded targets, parent+1,000 direct children, 1,001 no-child parents, oversized variable early-stop and crowded memberships | PASS executed boundary controls; actual WP category bounds runtime fixture at `subcategory-integration.php:69-76` |
| Count is informational, creates no job/approval | count discovery shares resolution but never creates or approves; actual runtime fixture checks unchanged job count despite invalid amount; form states counts do not authorize change | PASS inspected code and contract; runtime unchanged job population requires actual fixture receipt |
| Preview independently resolves and freezes after stale count | Admin `process_preview` builds selection anew, calls planner, asserts bound, creates plan; forged `selection_count` ignored; contract adds product between count and Preview and observes larger independently frozen set | PASS executed contract |
| Execution never reselects live category | plan retains frozen `resolved_product_ids`; worker consumes hydrated items; real fixture adds later product after Preview, executes saved job, confirms late product stays 100.00 | PASS source and frozen hydration control; actual no-overwrite final value runtime owned separately |
| Review and retained plan disclose toggle/scope | Native checkbox help; preview `class-free-admin.php:1477-1479` renders direct versus descendants; selection material is hashed in plan | PASS inspected source and saved-material contract |
| Existing direct plans remain compatible | existing main plans already contained `include_children:false`; category default retained; Change_Plan hydration unchanged; separate main→combined plan probe preserves exact JSON bytes and plan hash | PASS bounded cross-version material compatibility; generated main material uses test doubles and is not a retained historical merchant plan or real-Woo migration receipt |

No speculative correction is justified. Preliminary lens verdict: **CODE_PASS**; **ACCEPTANCE_PARTIAL** until exact-source real WP/Admin runtime receipts are reconciled. Genuine recorded runtime may close those rows; host-only checks must never be relabeled Woo runtime PASS. Hosted CI remains independent.

## #210: PR #222 preparation versus full issue

| Live acceptance criterion | Independent observation | Status |
|---|---|---|
| Generated runtime/Admin/JS/accessibility catalog with domain, plural/context extraction | `i18n-inventory.php` purpose is literally `literal-review-candidates-not-gettext-catalog`; tokenizer/regex inventory, zero production conversion; current docs explicitly declare known missed interpolated/template strings and JS comment false positives | PARTIAL preparation; catalog requirement NOT_TESTED/unimplemented in this PR |
| Controlled non-English/pseudo-locale for selection/Preview/progress/errors/conflicts/History/Undo and 0/1/many/long labels | No locale/catalog/translation conversion in production diff; preparation doc explicitly says pseudo-locale runtime not done | NOT_TESTED |
| Canonical prices/IDs/plan/journal/CSV machine identifiers remain stable with translations; old plans hydrate | Never-translate policy documented; exact #222 production diff is empty, so tooling cannot alter runtime values | Preparation boundary PASS; behavior under translations NOT_TESTED |
| Focus/browser checks cover translated text and live updates | Translation functions/assets not added; no translated runtime or keyboard receipt in this lane | NOT_TESTED |
| Catalog/asset additions comply with allowlist/local asset model | Exact #222 distribution manifest and public runtime diff empty; docs require future allowlist and local loading compliance | PASS for preparation scope; future catalog additions NOT_TESTED |

Tooling is reproducible and bounded. Its fixture verifies allowlist scope including an unlisted-file negative, PHP comment exclusion/source lines, JS candidates, classification, summary counts, repeated byte determinism, explicit full-tree `--all`, missing root/manifest/file, unsafe entry, empty scan and unknown options. The source openly states its extraction limits; it is suitable as a literal-review aid, not translation-completeness proof.

Verdict: **PR #222 PREPARATION PASS / ISSUE #210 PARTIAL / OPEN**. No full conversion is started by this audit. #222's possible merge readiness depends on parent live hosted checks and founder approval; completing preparation does not close #210 or grant a Marketplace translation-readiness claim.

## Required evidence separation and follow-up

1. A local client/unit PASS is distinct from authentic WordPress runtime, browser accessibility and hosted CI. Parent runtime/browser lanes own those executions; no runtime site or runner was modified by this lens.
2. Historical Round 4 reports name MySQL/default+Redis Admin PASS logs, but the report alone is a claim. Their named `logs/` population was not available at the handoff-relative paths inspected by this lens. The parent complete-manifest result must determine availability before crediting them.
3. Historical `live-progress-result.json` demonstrates only Apply; `defect/live-undo-page-dump.txt` records a terminal Undo page and an original conflict-guidance failure, not live advancing Undo. Original failures remain preserved.
4. The authoritative #170 source provenance and current capture/result assertion totals belong to the parent browser lane. An integrated capture cannot satisfy each individual PR source gate automatically.
5. No production changes were made because this review did not reproduce a code defect. The Round 4 conflict-copy correction remains present and exercised by exact-source tests.
