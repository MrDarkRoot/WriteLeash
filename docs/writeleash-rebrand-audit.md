# WriteLeash Rebrand Audit

**Audit context:** Issue #98, final CommitCap → WriteLeash rebrand integration
gate. Repository: [`MrDarkRoot/WriteLeash`](https://github.com/MrDarkRoot/WriteLeash).
**Date:** 2026-09-30 UTC.

| Revision | SHA |
| --- | --- |
| Audit base (live `main`) | `885684bbc147da7deeb0178f8f667d96a799f33c` |
| Final substantive rebrand/docs audit tree | `cd05e12b5e29766aa1d3b67c122651d2bd00b82d` (readme notice/description corrected after Plugin Check review) |
| CI-verified PR head before this report update | `fc5d64c7ea50320f0fc16299fdfb5c980a656933` |

The stale-name search counts below describe the substantive audit tree at
`cd05e12b5e29766aa1d3b67c122651d2bd00b82d`. This report is the following audit-record-only change and is
excluded from its own scan/counts to avoid self-matching. The PR head records
the final complete tree including this report.

## Search method

The repository-wide search was case-insensitive for product/package/runtime
names and separately inspected case-sensitive and case-insensitive abbreviation
families. The search excluded `.git/`, the generated WordPress research cache,
and this report:

```sh
rg -n -i --hidden \
  --glob '!.git/**' \
  --glob '!wordpress/tests/research/.cache/**' \
  --glob '!docs/writeleash-rebrand-audit.md' \
  'CommitCap|commitcap|MrDarkRoot/CommitCap|commitcap-for-wordpress|commitcap-distribution|commitcap_v01|commitcap_native|wp commitcap|\./commitcap|commitcap\.php|/commitcap/|(^|[^A-Za-z0-9])cc_[A-Za-z0-9_]*|(^|[^A-Za-z0-9])cc[0-9][A-Za-z0-9_-]*|(^|[^A-Za-z0-9])CC[-_][A-Za-z0-9_-]*'
```

Counts below are **distinct matching lines**, with each line assigned once to
the most-specific applicable class. The abbreviation patterns intentionally
produce many matches in stable test helpers, fixtures, and historical IDs; they
are not treated as product-name matches by default.

## Classification taxonomy and counts

| Class | Matching lines | Treatment |
| --- | ---: | --- |
| **1. BUG — active identity missed by rename** | **0** | No active product, package, runtime, repository, or build identity remains under the old name. |
| **2. HISTORICAL EVIDENCE — immutable/captured evidence intentionally preserved** | **3,160** | Captured evidence and transcripts remain byte/logically faithful. |
| **3. LEGACY HISTORY/REFERENCE — issue/PR/history text intentionally preserved** | **90** | Dated pre-rebrand product/technical records and original decision citations are contextualized as history. |
| **4. STABLE HISTORICAL TEST/PROTOCOL ID — intentionally preserved** | **893** | Stable `CC-*`, `CC_*`, and uppercase protocol/test IDs remain unchanged. |
| **5. NEGATIVE/ADVERSARIAL FIXTURE — old identity intentionally constructed to prove rejection** | **129** | Tests construct old-only and mixed identities and require fail-closed refusal. |
| **6. PRE-RELEASE CLEANUP LITERAL — finite uninstall/cleanup compatibility literal** | **6** | Four exact option names plus the two active documentation lines describing their finite cleanup. |
| **7. DEFENSIVE RESERVATION — old managed-looking SQL intentionally classified as reserved, not accepted** | **8** | Guard reservation and its focused assertions remain; this is not compatibility or migration authority. |
| **8. EXTERNAL PROPER NOUN — not ours to rename** | **0** | None found. |
| **9. NON-BRAND ABBREVIATION — intentionally retained** | **3,958** | Lowercase `cc_*`/`cc[0-9]*` test variables, helper aliases, and fixture names remain non-brand abbreviations. |
| **Unexplained** | **0** | Every remaining relevant line is classified above. |
| **Total relevant matching lines** | **8,244** | Unique lines, not overlapping string-match occurrences. |

### Active identity bugs found and fixed

1. Root `README.md` still presented the PostgreSQL mutation-budget research
   substrate as the product hero, linked to the old repository URL, and said
   the PostgreSQL Public Research Preview was being prepared. It now leads with
   the #106 WooCommerce Free direction, explicitly says that work is planned
   and unimplemented, says public release is deferred, and separates the
   existing PostgreSQL/native and advanced WordPress technical substrates.
2. Active roadmap/product/status docs still treated the former PostgreSQL
   thesis or #67 as current authority. They now identify #106 as intended Free
   product authority and #67 as historical context; the next sequence is
   #107–#112, followed later by rewritten release gates.
3. Active repository links and GitHub security contact copy still used
   `MrDarkRoot/CommitCap`. Current links now use `MrDarkRoot/WriteLeash`.
4. The packaged WordPress `readme.txt` described the old Redirection V0.1 as an
   initial public release. It now identifies itself as a development package,
   distinguishes the existing advanced substrate from the planned WooCommerce
   Free 1.0 product, and states that public release is deferred.
5. The active WordPress source README, operator guide, Admin/CLI, operation,
   adapter, threat-model, license, and technical release-matrix docs lacked a
   consistent product-reset distinction. They now say the existing
   Guard/Doctor/Redirection work is a separate technical substrate and does not
   implement the planned WooCommerce workflow.

### Intentionally retained old identity

- **Historical evidence:** `demo/phase0/evidence/`,
  `experiments/native_tx_state/evidence/`,
  `wordpress/tests/research/transcripts/`, historical Phase 0 coverage/run
  records, and `.github/ci/actions-evidence.md`. Captured names, hashes,
  transcripts, and tested-commit links were not cosmetically rewritten.
- **Legacy history/reference:** `SPEC.md`, the archived PostgreSQL thesis in
  `docs/product.md`, the superseded maintainer prompt in `docs/role.md`, the
  dated incident/managed-PostgreSQL research, and original review citations in
  `docs/numeric-delta-decision-proposal.md`. Their historical context is
  stated at the top or evident from their dated/legacy scope.
- **Negative/adversarial fixtures:** `wordpress/tests/old-identity-fixture.php`,
  `wordpress/tests/doctor/test-97-graph-boundary.php`,
  `wordpress/tests/adapter/cases-78.php`, lifecycle/uninstall cases, and
  package/CLI absence assertions. These construct old-only and mixed graphs to
  prove `NOT_READY`, no migration, no authorization, and no old package/CLI
  identity.
- **Pre-release uninstall literals:** `wordpress/writeleash/uninstall.php`
  deletes only `commitcap_version`,
  `commitcap_certified_operation_state`,
  `commitcap_last_certified_outcome`, and
  `commitcap_operation_budget_redirection_5_5_2_bulk_disable`, by exact name.
  No prefix/wildcard cleanup is used. The old state is never read, migrated, or
  used to enable/certify WriteLeash; lifecycle tests assert this boundary.
- **Guard defensive reservation:**
  `wordpress/writeleash/includes/class-guard-sql.php` keeps
  `@commitcap_v01_*` SQL classified as `RESERVED`. This is conservative lexical
  classification only—never compatibility, fallback, migration input, or
  authority. `wordpress/tests/guard/cases.php` asserts the reserved result.
- **Stable IDs/abbreviations:** historical `CC-*` IDs (including `CC-001`,
  `CC-030` and `CC-036`), the `CC54_DENIED` protocol token, and lower-case
  `cc_*`/`cc[0-9]*` local test/SQL helper names remain stable. They are not
  globally renamed.
- **Old repository URLs:** retained only in the historical evidence/decision
  records listed above (including `.github/ci/actions-evidence.md` and the
  dated native/PostgreSQL evidence). Active README, badges/contact links,
  install/clone examples, issue-template security links, and current docs use
  `MrDarkRoot/WriteLeash`.

## Canonical active identity lock

| Surface | Canonical identity |
| --- | --- |
| Product | `WriteLeash` |
| Repository | `MrDarkRoot/WriteLeash` |
| WordPress slug/folder | `writeleash` / `wordpress/writeleash/` |
| Main plugin file | `writeleash.php` |
| Text Domain | `writeleash` |
| PHP namespace | `WriteLeash\` |
| Constants | `WRITELEASH_*` |
| WP-CLI | `wp writeleash` |
| Distribution manifest | `wordpress/release/writeleash-distribution-files.txt` |
| DB/runtime family | `writeleash_v01_*`, `@writeleash_v01_*`, `writeleash_v01_rotation:`, `writeleash_v01_tx_*` |
| Root research CLI | `./writeleash` only |
| Native extension / schema | `writeleash_native_tx_state` / `writeleash_native` |

## Package and release assertions

- `wordpress/release/writeleash-distribution-files.txt` is the only active
  WordPress distribution allowlist. It contains the WriteLeash main file and
  does not include this report.
- The maintained package preflight passed with 30 allowlisted files; readme
  validation passed with `readme.txt` at 8,830 bytes; the staged-source audit
  passed and permits only the exact uninstall literals plus the documented
  Guard reservation.
- The maintained staging script targets `wp-content/plugins/writeleash/`,
  requires `writeleash.php`, and rejects `commitcap.php`,
  `writeleash-for-wordpress.php`, and internal audit docs. The allowlist and
  source assertions do not permit a `commitcap/` package or mixed active brand.
- The report is repository-only and absent from the manifest.
- Local package file staging could not be executed because this environment
  has no Docker daemon; final-head GitHub Actions remain the staging/regression
  gate.

## Roadmap authority and active issue audit

**#106 supersedes #67 as authority for the intended public Free product.** #67
remains historical V0.1 technical/product-development context. The WooCommerce
bulk-price workflow is planned, not implemented. After #98, begin #107 from
post-rebrand main, then #108 → #109 → #110 → #111 → #112. **Public release is
deferred.** Rewrite #77/#76/#65/#66 after #112 before considering release work.

| Issue | Active identity correct? | Roadmap assumption current? | Action |
| --- | --- | --- | --- |
| #65 | Yes — WriteLeash | No — exact-artifact acceptance assumes the old Redirection V0.1 release | **REWRITE LATER / DEFER** |
| #66 | Yes — WriteLeash | No — founder submission gate is for old release thesis | **DEFER; REWRITE LATER** |
| #67 | Yes — WriteLeash | Superseded as intended public Free authority; retain as historical context | **SUPERSEDED as authority; preserve** |
| #76 | Yes — WriteLeash | No — exact ZIP compliance gate is for old product thesis | **REWRITE LATER / DEFER** |
| #77 | Yes — WriteLeash manifest | No — old deterministic release chain must not authorize the old product | **REWRITE LATER / DEFER** |
| #100 | Yes — WriteLeash | V0.2 install-to-READY scope is not the current Free 1.0 critical path | **DEFER / RE-SCOPE LATER** |
| #101 | Yes — WriteLeash | Setup wizard assumes the existing Strict/Redirection setup | **DEFER / RE-SCOPE LATER** |
| #102 | Yes — WriteLeash | Trusted provisioning work remains a separate advanced track | **DEFER** |
| #103 | Yes — WriteLeash | Provider integration is not part of the current Free product path | **DEFER** |
| #104 | Yes — WriteLeash | Exact-artifact onboarding gate remains future release work | **DEFER / REWRITE LATER** |
| #106 | Yes — WriteLeash | Current authority: safe WooCommerce bulk price changes | **NO CHANGE** |
| #107 | Yes — WriteLeash | Current ordered product contract/plan gate | **NO CHANGE** |
| #108 | Yes — WriteLeash | Current Woo CRUD/journal proof gate | **NO CHANGE** |
| #109 | Yes — WriteLeash | Current durable job/recovery gate | **NO CHANGE** |
| #110 | Yes — WriteLeash | Current conflict-aware Undo/lifecycle gate | **NO CHANGE** |
| #111 | Yes — WriteLeash | Current Admin workflow gate | **NO CHANGE** |
| #112 | Yes — WriteLeash | Current scale/compatibility/real-host acceptance gate | **NO CHANGE** |

The `#65/#66/#76/#77/#100–#104` issues were inspected but not edited. This PR
updates only #98's stale umbrella/next-step text; the broader release-roadmap
rewrites remain future work.

## Regression and behavior boundary

- Local: `tests/writeleash_cli.sh` **PASS**;
  `tests/writeleash_identity_scan.sh` **PASS**;
  WordPress package preflight/readme validation/source audit **PASS**.
- Local Docker-backed DB regression could not run: `/var/run/docker.sock` is
  absent. `tests/writeleash_catalog.sh` stopped before fixture startup for that
  reason. Docker-backed suites were run by GitHub Actions on the PR head.
- Existing live-main CI at #105 SHA `885684bbc147da7deeb0178f8f667d96a799f33c`
  had the Native PG16, MySQL/MariaDB feasibility, WordPress foundation, engine
  research, and plugin research workflows **success**. The Redirection adapter
  and composed V0.1 release matrix **failed** in the #61 concurrent overlap
  proof: the trusted barrier queued both requests, but `await_overlap(update)`
  timed out; responses were HTTP 503/500. The release matrix stopped before
  later current-core acceptance. This did not reproduce on the final PR head.
  Guard behavior and test assertions were not changed to mask it.
- An initial #98 candidate readme notice caused Plugin Check to report one
  additional `readme_parser_warnings_trimmed_short_description` warning
  (`errors=37`, `warnings=4`); the notice was shortened and moved so the
  maintained validator reports a 71-character short description. The final
  PR-head Plugin Check result returned to the established reviewed inventory:
  **37 errors, 3 warnings, 40 reviewed, 0 security/runtime blockers**, on both
  database engines. These are reviewed exceptions, not “zero warnings.”
- The final PR head
  `fc5d64c7ea50320f0fc16299fdfb5c980a656933` completed all seven maintained
  GitHub check runs with `completed / success`:

  | Workflow/check | Run | Result |
  | --- | ---: | --- |
  | WordPress plugin foundation (MySQL + MariaDB) | [36672828700](https://github.com/MrDarkRoot/WriteLeash/actions/runs/36672828700) | PASS |
  | WordPress plugin research | [36672828692](https://github.com/MrDarkRoot/WriteLeash/actions/runs/36672828692) | PASS |
  | WordPress MySQL/MariaDB engine research (includes the maintained feasibility script) | [36672828766](https://github.com/MrDarkRoot/WriteLeash/actions/runs/36672828766) | PASS |
  | WordPress Redirection adapter | [36672828684](https://github.com/MrDarkRoot/WriteLeash/actions/runs/36672828684) | PASS |
  | WordPress V0.1 regression/release matrix | [36672828735](https://github.com/MrDarkRoot/WriteLeash/actions/runs/36672828735) | PASS |
  | Native PG16 security research | [36672828728](https://github.com/MrDarkRoot/WriteLeash/actions/runs/36672828728) | PASS |

- The final-head matrix records Doctor READY and the certified Redirection
  operation on both MySQL 8.0.44 and MariaDB 10.11.15: safe HTTP 200 / COMMITTED,
  over-L HTTP 409 / logical DENIED with a fresh observer, normal DB identity
  performs no protected UPDATE while the restricted identity does, old-only and
  mixed graphs refuse readiness, trusted reprovision restores READY, and
  deactivation/uninstall boundaries remain unchanged. The #97 matrix proves
  old-only NOT_READY and mixed A–F NOT_READY on both engines. The #61 concurrency
  overlap is proven on both engines with zero stale accounting and READY after.
- Current-core acceptance passed on WordPress 7.1.2 / PHP 8.2 with MySQL 8.0.44,
  MariaDB 10.11.15, and Redirection 5.5.2. Both engines reached Doctor READY
  and passed safe/denied real operations with fresh-observer checks.
- Plugin Check and package preflight ran in the final-head matrix. PHP runtime
  source is unchanged by this audit.
- Security semantics changed: **NO**. WooCommerce product code added: **NO**.
  Production behavior changed: **NO**.
