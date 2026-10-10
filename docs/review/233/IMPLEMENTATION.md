# #233 — Download the frozen Preview CSV before Apply

## Identity and review boundary

- Fetched `origin/main` first; exact base is the expected
  `3e90043a4225f1f413721e94c4f6cbf78a370c64` (merged #207 and #208).
- Branch: `work/233-preview-csv`; isolated worktree `/tmp/opencode/writeleash-233`.
- Final HEAD is the immutable commit containing the final version of this document.
  Its exact SHA, Draft PR URL and completed exact-HEAD Actions run are published in
  the PR handoff. A tracked document cannot embed its own commit hash. Completed
  earlier implementation runs, if any, are distinguished from final-HEAD evidence.
- Read issue #233, Free Admin, Change Plan, snapshots, serialization/hydration,
  repository binding, permissions, pagination, Job CSV and existing tests before
  implementing. Existing frozen name/SKU fields made a schema extension unnecessary.

**This is a Preview snapshot, not the current live catalog and not an offline
approval.** Download is a native POST form on the verified final Preview, before
the separate Approve and apply form. Blocked previews can be reviewed/exported.
Results/History Download job CSV remains the existing implementation and schema.

## Reused authoritative material

`Job_Repository::read_by_public_id` → existing job authorization → dedicated nonce
→ reviewable PLANNED/BLOCKED state → `Job_Repository::hydrate_plan` →
`Change_Plan::preview_page` → CSV spool → response. Hydration verifies canonical
hash, Plan/item shape, summary and independent job identity/creator/store binding.
No selector, live product reader, calculator, approval, scheduler, worker, journal
writer or mutator is invoked. The supplied offset, IDs, amount or Plan ID cannot
choose or alter exported material. Only the authenticated POST job identifies the
stored Plan; the nonce binds both its job public ID and fingerprint.

## Deterministic schema

```text
plan_id,product_id,product_name_at_preview,sku_at_preview,price_field,
expected_price,planned_price,delta,percent_delta,result,reason,currency,
exported_at_utc,plan_hash,plan_status,warnings,plan_warnings,
regular_price_at_preview,stored_price_at_preview,plan_blockers
```

- First 13 columns follow #233. Seven appended columns retain integrity/status,
  warnings/blockers and raw reviewed before prices without changing the proposal.
- `expected_price` is the exact stored canonical expected string. Preview renders
  the raw snapshot price; `stored_price_at_preview` retains that literal too.
  `regular_price_at_preview` retains the frozen Regular Price, including the
  relative-sale basis. No amounts are cast to floats or reformatted/recalculated.
- `planned_price` is the persisted final target, including #208 rounding. Clear
  Sale is canonical blank, distinct from numeric `0`/`0.00`. Blank deltas and
  percentages mean the Plan has no numeric delta/ratio; they are not fabricated.
- `percent_delta` uses the frozen signed percentage display, not a fresh ratio.
- `result` retains CHANGING/UNCHANGED/UNSUPPORTED. A blocked changing row has
  `plan_status=BLOCKED` and `plan_policy_blocked` in reason: CHANGING is a proposed
  target, not permission to Apply. Eligibility/item blocker reasons are joined
  with semicolons. No unsupported row is invented outside the Plan.
- Item warnings are semicolon-separated reason codes. `plan_warnings` is canonical
  JSON containing selection and structured policy warnings; `plan_blockers` retains
  the full canonical structured blocker list, including plan-level refusals.
- Name/SKU are frozen Woo snapshot values, never live fallbacks. A missing legacy
  identity field becomes blank, not a catalog read. Variation IDs are the frozen
  child IDs; the name field is the snapshot's Woo name.
- Ascending frozen product ID order, UTF-8 RFC-style CSV via `fputcsv` with comma,
  double-quote enclosure and empty proprietary escape. No comment/preamble rows.
  One UTC ISO-8601 export timestamp is shared by all rows in a file.

## Security and bounded response

Requires `manage_woocommerce` and `edit_products`, then existing
creator/approver/administrator policy. Other managers, anonymous/revoked actors,
guessed IDs and malformed input are denied without returning Plan/job material.
POST only; nonce is separate from Job CSV and approval and binds public ID/hash.
Invalid/expired nonces, missing/corrupt/unverifiable Plans and no-longer-reviewable
jobs fail before CSV generation. Plans have no existing Preview TTL: this feature
does not invent one or confuse Undo expiry with Preview validity. Retention-deleted
Plans are missing and denied; cancelled/applied jobs use the existing Job CSV.

Every text cell beginning with `=`, `+`, `-`, `@`, whitespace, Unicode separator,
control or format character is prefixed with an apostrophe. Invalid UTF-8 also
fails conservatively into apostrophe guarding. Tab/CR/LF and leading disguises are
covered. Only strict `-?[0-9]+(\.[0-9]+)?` strings in the six explicit amount columns
are exempt; negative deltas stay numeric and numeric-looking SKUs remain guarded.
CSV quoting preserves commas, quotes, embedded line breaks and Unicode.

Plan hydration necessarily holds the existing bounded immutable Plan (maximum
1,000 review targets). Writer copies at most 100 rows per page, without building
another all-row CSV array. The response uses `php://temp/maxmemory:1048576`, spills
to disk above 1 MiB, and completes generation before sending headers/private bytes.
Failures return a generic refusal rather than a partial download. Filename uses
only the repository-validated UUID public ID and a fixed Preview prefix. Response
is no-cache `text/csv; charset=UTF-8`, attachment, `nosniff`.

## Verification and no-write proof

Local PHP 8.2.32 PASS:

- `preview-csv-unit.php`: frozen Regular/Sale, simple/variation, blank versus zero,
  Clear/relative sale, .99/.95 final values, negative deltas, unchanged/refused/
  blocked outcomes, structured warnings/blockers, post-Preview live drift,
  genuine old serialized Plans and formula/whitespace/control/Unicode variants.
- 1,000-row export with >1 MiB content: complete sorted IDs, retained memory growth
  **1,584,232 bytes**, below the asserted 2 MiB bound. Output parsed row by row.
- PR_FAST, workflow syntax lint, Admin/no-replan audits, original progress/recovery,
  i18n/old-plan checks and shipped progress-client exhaustive assertions.
- POT regenerated using SHA256-verified official WP-CLI 2.12.0.

Hosted Admin fixtures trace SQL, Woo reads/saves and full persisted job equality
around export, prove creator/admin access and denial/nonce/integrity cases, and
download the actual native form over HTTP without JavaScript. Existing Job CSV
tests remain in the same suite. Acceptance exports from first and final Preview
pages for 100/101/1,000 targets and compares normalized hashes, every frozen value,
durable evidence counts and zero Woo-save metrics before existing Apply/Undo.

Local Docker is unavailable (`/var/run/docker.sock` absent). Real database/browser
results require hosted Actions: **HOLD until the exact final HEAD completes**.
Selected gates: PR_FAST, Plan, Journal, Jobs, Undo, Admin, CI_COVERAGE and all eight
acceptance profiles (current normal/default, current constrained/Redis, previous
WordPress, previous Woo, PHP 7.4/8.0/8.1 and unsupported-Woo refusal). Existing
reusable suites cover MySQL/MariaDB and default/Redis where selected. An explicit
test-path ownership rule selects the four additional requested compatibility
gates; direct acceptance wiring selects acceptance with a SHA-bound budget label.
Legitimate ownership skips are reported as skips, never as passes.

Graph gates use the isolated worktree's refreshed schema-4 runner/index. Boot and
Preview rendering impact LOW; file-level UNKNOWN callers were verified against
actual shell/CI consumers. Whole-repository process enumeration is budget-limited;
precommit changed-symbol gating separately requires nonpartial/nontruncated output.

### Completed implementation matrix

Draft PR: https://github.com/MrDarkRoot/WriteLeash/pull/238.
Implementation HEAD `958a38c6acb4b3a4d84b80cef3396e5462104537` completed green:
https://github.com/MrDarkRoot/WriteLeash/actions/runs/38027950565.

| Selected gate | Conclusion | Job ID |
|---|---|---|
| PR_FAST | PASS | 114142770344 |
| Plan | PASS | 114142844253 |
| Journal | PASS | 114142844257 |
| Jobs | PASS | 114142844270 |
| Undo | PASS | 114142844250 |
| Admin | PASS | 114142844269 |
| CI_COVERAGE | PASS | 114150615830 |
| current-normal-default | PASS | 114142844392 |
| current-constrained-redis | PASS | 114142844469 |
| previous-wordpress | PASS | 114142844438 |
| previous-woo | PASS | 114142844494 |
| php74 | PASS | 114142844448 |
| php80 | PASS | 114142844464 |
| php81 | PASS | 114142844441 |
| unsupported-woo refusal | PASS | 114142844384 |

Admin logs explicitly pass #233 security/integrity/no-write/live-drift tests and
native no-JS HTTP downloads on MySQL/MariaDB × default/Redis. The full Admin PHP
journey passes **951 assertions per profile**. Existing #168 Job CSV HTTP/security
tests pass in every profile too. Chromium 154.0.8037.97 and Firefox 151.0 each pass
264 existing keyboard/accessibility/safety assertions. WriteLeash-originated
WP_DEBUG warnings/notices/deprecations/fatals/headers-sent: zero.

Current-normal-default logs prove both database engines exported **100, 101 and
1,000** targets, matching every frozen value, identical normalized first/last-page
CSV hashes and zero saves. The Redis and other supported acceptance profiles also
complete the added CSV checks; unsupported Woo is an intentional early-refusal
profile, not a claim of export execution with an unsupported dependency.

CI_COVERAGE confirms every selected owner succeeded. Legitimate ownership skips:
engine, feasibility, research, adapter, native, foundation and historical. The
initial same-SHA preparation run `38027942597` was cancelled by attaching the
required SHA-bound budget label, and is not counted as a pass.

This evidence-only commit does not change runtime or tests. Its **own final-HEAD
matrix must complete** before READY; the exact final SHA/run/job conclusions are
recorded in PR #238 after that run, without a later source-changing evidence push.
The implementation run above completed before this documentation update.

## Inventory and compatibility

1. `wordpress/writeleash/includes/free/class-free-admin.php`
2. `wordpress/writeleash/languages/writeleash.pot`
3. `wordpress/tests/admin/preview-csv-unit.php`
4. `wordpress/tests/admin/preview-csv-integration.php`
5. `wordpress/tests/admin/preview-csv-http.php`
6. `wordpress/tests/admin/run.sh`
7. `wordpress/tests/admin/integration.php`
8. `wordpress/tests/admin/browser-e2e.php`
9. `wordpress/tests/acceptance/preview-csv.php`
10. `wordpress/tests/acceptance/journey.php`
11. `wordpress/tests/release/source-audit.php` (one exact safe filename literal)
12. `.github/ci/path-ownership.json`
13. `docs/review/170/README.md`
14. `docs/review/170/proof.json` (current-source identity; historical images/results unchanged)
15. `docs/review/233/IMPLEMENTATION.md`

No Change Plan, selector, Apply/Undo behavior, release metadata, historical v0.1
receipts, #194 evidence, #211/#212 review ZIPs or candidate ZIPs are modified.
#207 clear/relative semantics and #208 final rounding are consumed verbatim.
For #231, export must remain on the verified final included Plan; an uncommitted
exclusion-selection screen must not offer this form. No exclusions or second
planner are implemented here, and Agent B's branch/worktree is independent.

CSV viewers may autoformat numbers; decimal fidelity is the parsed CSV string
contract, not a spreadsheet's visual formatting. A CSV is not an import/approval
artifact or proof of live prices. Founder retains final GO/NO-GO.
