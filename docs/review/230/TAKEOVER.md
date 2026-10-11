# PR #241 takeover and accepted-main integration

Status at commit: integration implemented and locally verified; exact-head hosted
CI pending. This is not READY. The PR must remain Draft, issue #230 open, and
Founder GO outstanding. The final PR handoff pins the eventual commit SHA and
all hosted outcomes; a commit cannot embed its own SHA.

## Independent takeover audit

- Main fetched: `464cfd0b505b07de14c3276a9cbd8a261612525b`.
- Previous PR head: `90761d65361007099b33b9da16408c144cad0022`.
- Previous base: `da84e64886773a07694448a3d156e64f18489f3b`.
- Existing branch: `work/230-price-presets`; isolated worktree. It exactly
  matched the remote, with no local commits/changes or later PR pushes.
- Issue #230, complete blocker comment `6106685116`, all PR comments/reviews,
  all four original commits/diff and IMPLEMENTATION.md were inspected. No later
  review or change superseded the blocker. Original successful CI belongs to the
  old source only.
- Inspected merged range/refinement runtime and tests, #234 browser receipt,
  #170 procedure and executable CI ownership. Main protection requires strict
  PR_FAST + CI_COVERAGE, enforces admins, and forbids main force pushes/deletions.
  Workflow token is read-only; it cannot approve reviews. The exact-SHA
  `ci112:<head>` label authorizes the requested eight-profile acceptance matrix.

The original feature was complete and bounded, but its adapter predated range
hydration. A discriminating range unit test failed on the rebased code with
`invalid_preset` before the integration fix. No original repository/HMAC/CAS
method or pricing/selection/worker implementation was rewritten.

## Actual conflicts and resolution

All four original commits were replayed onto accepted main, preserving their
content/history rather than replacing the feature with a new PR.

| Conflict | Semantic resolution |
|---|---|
| `.github/ci/path-ownership.json` | Preserve both range and preset production owners and all existing downstream suites. |
| `docs/review/170/README.md` | Retain #231, #234 and #230 source-review accounts; append this genuine integrated capture receipt. |
| `docs/review/170/proof.json` | Retain accepted-main JS/CSS identity and all historical captures/observers; refresh only Admin identity after genuine integrated browser review. |
| `wordpress/release/writeleash-distribution-files.txt` | Include both new classes in sorted order. |
| `wordpress/tests/admin/integration.php` | Run both exclusion and preset integration fixtures. |
| `wordpress/writeleash/includes/free/class-free-admin.php` | Retain range enable/min/max/basis AND ending in input recovery. Existing range/refinement handlers, controls, counts and safety remain. |
| `wordpress/writeleash/languages/writeleash.pot` | Regenerate the entire catalog from the merged public allowlist with official checksum-pinned WP-CLI 2.12.0. |

The Woo-specific allowlist merged without a textual conflict but lacked main's
range class. Both allowlists now include both classes; PUBLIC-PAYLOAD counts 34
Free PHP files. No distribution gate or ownership assertion was weakened.

## Configuration adapter contract (#232 coordination)

`capture()` accepts editable form inputs; `form()` accepts a validated preset
configuration, never a Change_Plan/job. Output is ordinary editable form data.
No historical approval, nonce, hash, snapshot or permission is imported. #232
is not implemented, and its other worktree is untouched.

- New saves use schema 2, with canonical `selection.price_range` containing a
  strict boolean enable flag, independently nullable decimal-string bounds and
  explicit Regular/Sale basis. Factories remain the existing Admin/range domain.
- Enabled minimum-only, maximum-only, both bounds, exact six-place boundaries,
  zero and Sale basis round-trip. Sale basis is independent of operation field;
  blank Sale Price remains excluded. Disabled range follows #234's canonical
  disabled semantics: stale inactive bounds do not become an active filter.
- Schema 1 loads with its original **unfiltered** meaning, with no rewrite or
  re-signing. Strict canonical validation still rejects extra fields. Schema 1
  cannot carry newly invented range material; schema 2 cannot omit its range.
- Malformed types, decimals, reversed bounds, unknown basis, noncanonical or
  missing persisted range data are rejected, never coerced into no filter.

## Explicit exclusion/refinement policy

Presets represent only what the editable form can faithfully express. Individual
exclusions belong to the saved immutable Preview's `selection_refinement`.

No Save-as-preset operation exists on either the refined review or refinement
page. Both show explicit copy explaining that their frozen exclusions cannot be
saved in an ordinary preset. Ordinary preset controls also explain that fresh
exclusions must be confirmed before approval. The adapter rejects refined-plan
metadata rather than pretending to hydrate it. No second refinement engine,
plan-to-unfiltered-preset conversion or historical authority reuse was added.

Range + preset -> fresh candidates -> existing #231 exclusions -> separate
approval is tested. Loading/renaming/deleting the ordinary configuration leaves
the refined Plan byte-identical. Apply and Undo use only its frozen included IDs;
excluded products have zero saves and Journal entries. An entering-range product
can join a newly reviewed Preview but invalidates old candidate confirmation.

## Security preservation

Original repository methods remain intact: 20 store-wide atomic non-autoloaded
slots, 20,000 configuration bytes, 100 UTF-8 name bytes, creator/admin ownership
and mandatory Woo capabilities, dedicated action/ID-bound POST nonces,
blog/salt-bound HMAC and strict canonical validation. INSERT IGNORE reservation,
full-record conditional rename/delete and options/notoptions cache invalidation
are retained. Reused-slot stale rename coverage supplements original stale
delete/concurrent-save tests. CRUD still writes only options and zero products;
loading creates no job. Existing plans/jobs survive deletion unchanged.

Fresh Preview uses current prices and whole-source per-product authorization,
including an unreadable/out-of-range source boundary; final-target limits,
variation semantics, immutable identity, fresh approval and post-Preview CONFLICT
remain the existing shared pipeline. No selector runs during Apply/Undo.

## Local verification and genuine browser evidence

Actual local environment: WP 7.1.2, Woo 11.1.2, PHP 8.2.32, userland MariaDB
10.11.15/default cache; Redis 7.4.2 where enabled. Separate disposable table
prefixes/sites were created; other agents' sites/worktrees were not modified.
Docker daemon is unavailable, so these are userland real-Woo results, not Docker
results. MySQL 8.0.44/default also passed the expanded acceptance preset fixture.

- Preset unit + original Plan units: **3,251 assertions PASS** (644 added
  configuration checks), including backward compatibility and malformed ranges.
- Full real-Woo Admin MariaDB/default: **1,680 assertions PASS** before adding
  the additional fresh-range drift/stale-rename cases. The final cases run in the
  later Redis/full hosted matrix; do not count this earlier total as their proof.
- Full real-Woo Admin MariaDB/Redis 7.4.2: **1,690 assertions PASS** on the
  final tests, including stale rename, fresh-range population drift and normal
  post-Preview CONFLICT preserving the external price.
- Existing range domain (146 checks), exclusions/category/variation, frozen CSV,
  Admin boundary, shared worker/Undo no-replan and localized presentation/old-Plan
  compatibility checks: PASS. These domain doubles are supplementary.
- PHP lint, JS syntax and official POT/catalog (597 entries): PASS.
- Pinned actionlint workflow validation: PASS.
- PR_FAST: PASS; 209 PHP files linted, both public manifests/closure/inventory
  audited, #170 source/evidence audit and all 26 adversarial asset cases PASS.
- New Chromium 148.0.7778.178 and Firefox 151.0: **57 checks each**, enhanced
  and no-JS keyboard Save/Load/Rename/Delete, invalid-range recovery, fresh range
  membership, explicit refined-save limitation, no writes, new approval and
  byte-identical refined Plan after ordinary CRUD. Two axe scans each: no
  violations. Captures were inspected; the full-page configuration capture's
  fixed Admin bar is positioned at the scroll location, not a runtime overlay
  defect. DOM checks independently verify both bound values.
- Original unchanged #170 Firefox: **264 assertions, 13 axe scans PASS**;
  actual same-row 100.00/120.00/80.00 CONFLICT, zero duplicate saves, external
  93.00 Undo conflict preserved, 20 restored, actor/nonce/dependency/lifecycle
  negatives and bounded reads. New screenshots/results live in `captures/`.

The initial local Admin invocation reached 495 assertions before the tool's
120-second timeout; the longer background run completed. The initial new browser
fixture failed because it used hyphenated range bound IDs instead of the real
underscore IDs. Selectors were corrected; all assertions were retained and both
browsers passed. These preparation attempts are not PASS evidence. Screen reader,
Safari and actual zoom for these new captures are NOT_TESTED.

## Source identity and #170 procedure

Final runtime bytes inspected/captured (SHA-256):

| Source | SHA-256 |
|---|---|
| `class-free-admin.php` | `6e906a6b8fd591e39c7e54053d7581a90d9a71d7ddad58ccf2f094dd9672fcdf` |
| `class-price-presets.php` | `55d880452c753a14a87ee89590b28767ca3061f14a54b7b09d965c0373cd3b21` |
| `free-selection.js` | `01a855e15865aca82f746f2802eb738b0b32694d370d05d2429fad2f7adbd854` |
| `free-selection.css` | `1b57bf6b002995be559615af213ec51098c2d46081308c72145ddd42d5eb0668` |

Historical `docs/review/170` PNGs, result JSONs, baseline and independent observer
proof remain byte-identical to main. The current-source identity refresh follows
the established README procedure after real captures and inspection. It is not
acceptance by itself: exact-head hosted Admin must run all original assertions.

## Delivery gate

Run PR_FAST (including asset/adversarial audits), selected Plan/Journal/Jobs/Undo/
Admin and CI_COVERAGE, and all eight exact-head acceptance profiles:
current-normal-default, current-constrained-redis, previous-wordpress,
previous-woo, php74, php80, php81, unsupported-woo. Distinguish PASS, FAIL, SKIPPED,
CANCELLED and NOT_RUN. The final PR description/blocker reply supersedes this
commit-time pending status with exact SHA/run links and latest main/mergeability.
Only `--force-with-lease` against the inspected PR head may publish the rebase.
No merge, release, publication, issue closure or Draft-status change is authorized.
