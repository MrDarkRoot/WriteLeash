# #133 pre-public audit checkpoint

Audit date: 2026-10-02. Exact base:
`22a89ec0d12fb4860ec9ffb1347332af02e95788`.
Repository: **PRIVATE**. This checkpoint authorizes neither a visibility change
nor WordPress.org publication. Maintainer audit/merge is the Phase A stop gate.
PR #134 / #121 is not repaired here; #122 and #123 are not started.

## Baseline: all 13 workflow files at the exact base

`PR` = pull_request, `push` = automatic branch push. No baseline workflow used
paths-ignore or concurrency. Every job used `ubuntu-24.04`, `contents: read`,
and SHA-pinned checkout/upload-artifact. All artifacts below upload on
`always()`; feasibility has no upload.

Observed durations are **job start-to-completion seconds**, not timeouts and
not GitHub's rounded billing ledger. The representative completed base runs
were created 2026-10-02 at 12:25 UTC. Matrix sums count every runner separately.

| Workflow file | Base triggers / path filters | Jobs × fanout | Timeout/job (min) | Base observed job seconds / run ID | Upload / retention | Invariant | New layer |
| --- | --- | --- | ---: | --- | --- | --- | --- |
| mysql-mariadb-feasibility.yml | PR + push main; experiments/mysql_tx_budget/**, wordpress/**, own workflow | 1 × 1 | 15 | 43 / 37006546643 | none | #53 two-engine feasibility counterexamples | CODE_INTEGRATION + RELEASE_FULL |
| native-pg16-security.yml | every PR + push main; no filter | 1 × 1 | 30 | 172 / 37006546591 | four synthetic transcripts / 7d | native PG16 security, canonical marker checks, demo/CLI | CODE_INTEGRATION + RELEASE_FULL |
| wordpress-engine.yml | PR + push main; wordpress/**, engine/foundation workflow files | 1 × 1 | 20 | 119 / 37006546487 | engine transcript / 7d | engine, Guard, Doctor + #53 | CODE_INTEGRATION + RELEASE_FULL |
| wordpress-foundation.yml | PR + push main; wordpress/**, foundation/engine workflow files | 1 × 2 engines | 15 | 63 + 85 = 148 / 37006546507 | lifecycle transcript/engine / 7d | activation, dependencies, lifecycle/uninstall | CODE_INTEGRATION + RELEASE_FULL |
| wordpress-plugin-research.yml | PR + push main; wordpress/**, own workflow | 1 × 1 | 25 | 67 / 37006546602 | plugin tracing transcript / 7d | #85 real plugin SQL research | CODE_INTEGRATION + RELEASE_FULL |
| wordpress-redirection-adapter.yml | PR + push main; wordpress/**, own workflow | 1 × 1 | 25 | 178 / 37006546549 | attempt-named adapter transcript / 30d | Redirection 5.5.2; #61 actual overlap | CODE_INTEGRATION + RELEASE_FULL |
| wordpress-release-matrix.yml | PR + push main; wordpress/**, experiments/mysql_tx_budget/**, own workflow | 1 × 1 | 90 | 381 (failure) / 37006546467 | attempt-named matrix transcript / 30d | historical #62 + #61 + current-core + Plugin Check | CODE_INTEGRATION + RELEASE_FULL |
| wordpress-woo-plan.yml | PR + push main/free/107-woo-price-contract-plan; wordpress/**, own workflow | 1 × 1 | 30 | 79 / 37006546585 | plan transcript / 7d | #107 immutable planning/arithmetic | CODE_INTEGRATION + RELEASE_FULL |
| wordpress-woo-journal.yml | PR + push main; wordpress/**, own workflow | 1 × 1 | 35 | 329 / 37006546598 | journal transcript / 7d | #108 durable journal/crash/cache/concurrency | CODE_INTEGRATION + RELEASE_FULL |
| wordpress-woo-jobs.yml | PR + push main; wordpress/**, own workflow | 1 × 1 | 75 | 432 / 37006546660 | jobs transcript / 7d | #109 leases/scheduling/crash/recovery | CODE_INTEGRATION + RELEASE_FULL |
| wordpress-woo-undo.yml | PR + push main; wordpress/**, own workflow | 1 × 1 | 75 | 687 / 37006546593 | Undo transcript / 7d | #110 eligibility/conflicts/races/retention | CODE_INTEGRATION + RELEASE_FULL |
| wordpress-woo-admin.yml | PR + push main; wordpress/**, own workflow | 1 × 1 | 75 | 196 / 37006546541 | Admin transcript / 7d | #111 authenticated actual Admin journey | CODE_INTEGRATION + RELEASE_FULL |
| wordpress-woo-acceptance.yml | PR + push main; wordpress/**, own workflow | 1 × 7 profiles | 75 | 972,1688,548,593,110,568,652 = **5131** / 37006546628 | per-profile SHA-stamped JSON, measurements, diagnostics/transcript / 30d | #112 seven-profile/two-engine acceptance, Redis, limits/recovery | RELEASE_FULL only |

Baseline: **13 workflow definitions, 13 logical jobs, 20 expanded jobs**.
The completed base wave consumed **7,962 job-seconds (~132.7 minutes)**,
including its failed historical run. Acceptance alone was ~85.5 minutes.
The separate #121 wave at `56e90cc` unnecessarily started the same suites;
foundation/adapter/historical matrix failed early on minimum-WP activation.
Those failures are Phase B work and have not been hidden by changing assertions.
The acceptance run was still incomplete when recent timing metadata was captured;
its partial duration is not used as a completed/billed total.
The account billing API was unobservable (404 / additional user scope needed).
The maintainer's near-quota warning is authoritative for budgeting: full hosted
release evidence is deferred, not asserted as executed by this audit.

## History and current-tree sensitive material

Two complementary approaches were used after fetching branches/tags **and all
advertised PR head/merge refs**:

1. Gitleaks **8.30.0**, `git --log-opts='--all --full-history' --redact=100`.
   Zero findings. Gitleaks reports 161 non-merge/diff-bearing commits (~5.82 MB).
2. `git rev-list --objects --all` + `git cat-file` content/object-name analysis:
   **167 local refs, 220 reachable commits, 1,138 distinct named blobs** at the
   pre-implementation audit snapshot. Token prefixes, private-key markers,
   credential assignments, private networks, URLs, email and sensitive file
   extensions were checked without printing candidate values. The seven retained
   historical gzip/tar objects were decompressed in memory: **185 payloads**;
   both targeted analysis and Gitleaks stdin scanning found no secrets in them.

This includes material deleted from main and retained on old/PR refs. Fetching
cannot establish the contents of inaccessible server-side unreachable objects;
the scope is every fetched/advertised ref, not just the default branch.

| Type | Location / object | Classification | Remediation |
| --- | --- | --- | --- |
| Restricted DB fixture credential | wordpress/tests/jobs/integration.php; blobs 3e4c022eca3b44e6d2039e6c2bec45d9a86f8ce1 and 62d4f55260ad7ef74f8b121e2e208adfe2058e5f | SYNTHETIC; harness CREATE USER / DROP USER pair | Retain isolated test; never reuse in deployed infrastructure |
| DB fixture config | .env.example and disposable Compose/tests | SYNTHETIC | Keep explicit fixture labeling and ignored real config |
| Compressed benchmark records | benchmarks/phase0/evidence/followup-6ff8c92/** on historical branch | SYNTHETIC engineering evidence, not customer dumps | Reviewed decompressed payloads; retain history |
| Assignment-pattern matches | historical benchmarks/phase0/run.py | Code expressions supplying synthetic fixture passwords, not an embedded API credential | No secret remediation needed |
| URLs | research/license/contract docs | Public provider/docs/incident citations | Retain; no private package/production URL identified |
| Git author/committer identity | Git metadata across all refs: 3 distinct email identities, 1 GitHub noreply and 2 other identities; representative commits 6ffc773f10bd0d5a17f1b5e7254f655763252180 and 56e90cc9c12057c81d1f9312aa51bec11039415f | **Public intent not established for the 2 non-noreply identities**; not credentials | Maintainer must review privately and confirm author consent before visibility change; no values copied here |

**HISTORY SECRET SCAN: PASS (credential scope).** No possibly active credential
was found. Public-visibility clearance remains pending the author-identity review
and settings checklist below. Deletion from HEAD is never history sanitation.

**CURRENT TREE SECRET SCAN: PASS.** Gitleaks stdin scanned all tracked current
files plus new CI implementation files (312 at that checkpoint), zero findings.
The tree path inventory has only synthetic `.env.example`, no tracked private
key/certificate/credential config, backup, dump, real log or cache archive.
A wider working-directory scan also inspected ignored third-party ZIP caches:
140 generic-api-key candidates occurred **only inside ignored official
WooCommerce/Fluent Forms packages**, outside repository/package exposure; none
were repository source findings. They are not used to claim clearance of arbitrary
third-party product secrets. Existing untracked directory graphics are excluded
from this change. `.gitignore` adds cert/key identities, credential dotfiles,
archives, database files and backup suffixes; SQL research sources remain tracked.

## GitHub configuration inventory: names and usage only

| Category | Observable result | Purpose / consumer / PR access / needed before #126 / recommendation |
| --- | --- | --- |
| Actions repository secrets | 0 names | No configured consumer; no values read; no release secret needed for these test workflows |
| Actions variables | 0 names | No values exported |
| Environments | 0 names; consequently 0 configured environment-secret namespaces | No release environment currently inventoried |
| Deploy keys | 0 keys | No SSH deployment consumer observable |
| Webhooks | 0 hooks | No configured consumer observable |
| GitHub App installation / integrations | API 401; **MANUAL SETTINGS REVIEW REQUIRED** | Confirm repository-selected apps and permissions privately; remove unused access |
| Release PAT / WordPress.org / SVN credentials | No workflow reference to secrets, PAT or SVN credentials | External processes/account credentials **not observable**; manual review required; ordinary PR access NO by workflow policy; not needed before #126 for current source/testing work |
| Default workflow token | read; PR review approval false | Test consumers only; preserve contents:read; checkout persist-credentials:false |
| Allowed Actions | all; server SHA-pinning enforcement false | Committed policy enforces SHA pins; maintainer settings review below |
| main protection | protected; enforce_admins true; force pushes/deletions false; no required checks; rulesets [] | Require PR_FAST and CI_COVERAGE after green proof; preserve existing protection |
| Fork approval policy | API unobservable | **MANUAL SETTINGS REVIEW REQUIRED**; configure all outside collaborators before public |

Never infer "none" for inaccessible installed Apps, account PATs or external SVN
processes. Every future secret inventory must record name, purpose, consumer,
least privilege, PR availability, need-before-#126 and deletion/rotation owner.
No long-lived release credential is needed or referenced by these PR/test jobs.

## Representative public log/artifact exposure review

Downloaded privately into temporary audit storage; no raw log archive or secret
scan report is committed/uploaded. Review covered **25 run-attempt log archives**
and **39 retained artifact archives**, **507 member files / 16,296,317 bytes**.
Gitleaks redacted stdin scan over those members: zero findings. Targeted inspection
also covered credentials, nonces, paths, hostnames, private IPs, identifiers and
customer-like material. Results are synthetic: Docker/runner network context,
disposable DB identities/WordPress sessions/nonces, `/opt`/runner temporary paths,
generated fixture IDs and public registry/support URLs. No real customer/store
data, production ID, user token or private deployment infrastructure was found.
GitHub-runner internal DNS addresses are runner context, not target infrastructure.

| Representative run IDs | Material / outcome inspected |
| --- | --- |
| 37006546628, 36959209227 | #112 all seven artifact profiles, successful and failed measurements/diagnostics/WordPress debug output; MySQL/MariaDB/Redis and Docker transcripts |
| 37006546549, 37008157831, 36675520107 | Redirection success, minimum-WP failure, historical failed attempt |
| 36998556798, 36977470706 | historical release matrix success/failure |
| 36569613945 attempts 1–4, 36581449757 attempts 1–3, 36697050660 attempts 1–2 | unchanged-SHA historical #61 failed/retried evidence and all retained run artifacts |
| 37008157823, 37008157945, 37001772866 | durable journal, actual Admin and failed Admin diagnostics |
| 37008157861, 36214730269 | native suite success/failure; disposable SQL/demo output |
| 37006546507, 37008157814, 37008157792, 36666161788 | foundation and engine successful/failed DB/lifecycle transcripts |

**No Actions run/artifact was deleted.** Existing #112 sanitized durable decision
records remain in `wordpress/tests/acceptance/EVIDENCE.md`, `SUPPORT-REPAIR.md`
and `results-cbee8b6.csv`. Historical #61 failed attempts remain at 30-day
retention; the existing attempt hashes/run references in EVIDENCE.md remain.
Ordinary integration artifacts go from 7d to 3d; intentional exact-SHA release
transcripts use 30d. Adapter/historical matrix retain 30d even on code PRs to
preserve #61 failed attempts. Retention edits affect future uploads only.

## Supply chain / boundary

- All external Actions remain pinned to immutable 40-character SHAs. Local
  reusable workflows resolve with the caller's commit; no secrets are inherited.
- All **53 literal CI/fixture image references** are digest-pinned. The native
  Dockerfile and root Compose now directly pin the same already-reviewed PG16
  digest; fixture behavior and assertions are unchanged.
- **11 plugin ZIP fetch harnesses** use versioned WordPress.org downloads and
  SHA256 verification; manifests/checksums were inspected. Woo 11.1.2 canonical
  ZIP SHA256 remains
  `9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e`.
  WP core/WP-CLI come from digest-pinned images. Redis/plugin research ZIPs,
  Woo 9.9.7/11.0.1, Redirection 5.5.2, Plugin Check 2.1.0 and research plugins
  have existing explicit checksums. No floating latest download was approved.
- Workflow syntax uses actionlint 1.7.11's versioned archive, SHA256
  `900919a84f2229bac68ca9cd4103ea297abc35e9689ebb842c6e34a3d1b01b0a`,
  verified **before** executing it; the digest was observed from upstream release
  asset metadata. It is the only new remote executable download path.
- **Unpinned dependency:** native PG research Dockerfile `apk add --no-cache
  build-base` and its transitive Alpine packages. Release preflight fails by
  default. A reviewed explicit dispatch boolean can acknowledge this research-only
  reproducibility exception; it never affects the WordPress runtime package.
  Do not describe an acknowledged exception as an immutable toolchain.
- GitHub-hosted Ubuntu runner/tool versions are managed by GitHub, not an
  immutable build environment; release artifacts must record actual versions.
- Public manifest closure/source audits reject custom updater, remote executable,
  secret-fixture and legacy product dependency paths in the runtime.

**PUBLIC GITHUB SOURCE REPOSITORY != WORDPRESS.ORG RUNTIME PACKAGE.** Intentional
historical PG/Guard/Doctor/Redirection research remains repository-only. The #120
authority is still `wordpress/release/writeleash-distribution-files.txt`:
37 files, exact 35-PHP public include closure. No historical product code or CI
audit tool is added to that manifest and no release ZIP is built here.

See [CI-POLICY.md](CI-POLICY.md) for gate ownership, dispatch, required checks,
fork settings, exact manual review steps and post-visibility re-audit.

## Local implementation verification

- PR_FAST, package preflight/public runtime closure, complete PHP inventory,
  readme validator, staged source audit and **137 source/fixture PHP lint checks:
  PASS**. Conditional #121 claim audit is wired correctly but absent at this base,
  so no claim-matrix execution is claimed here.
- Actionlint **1.7.11** syntax/expressions/reusable-call checks on all 15 workflows:
  **PASS**, using both the local binary and checksum-verified release binary.
- Ownership/policy audit **PASS**: all 57 production PHP files, all 13 release
  suites, docs/claim/asset cheap classification, metadata-only header case and
  injected-comment/runtime rejection. CI_COVERAGE positive cases and selected
  failure/skipped/cancelled rejection passed.
- The actual #121 seven-path diff (`22a89ec` → `56e90cc`) classifies to
  **PR_FAST only / zero integration owners**. Narrow first-header metadata and
  exact static claim-audit wiring exceptions reject runtime/terminator changes.
- Native local Docker integration could not start: no Docker daemon socket in
  this environment. Its new immutable base references preserve the existing
  digest and select exactly the native suite for the final PR; test assertions
  are unchanged. Hosted result must be read from the final-head PR check.
- RELEASE_FULL composition is statically validated: **not executed** at this
  checkpoint; near-quota account and explicit research-toolchain exception.
  No #61 retry is initiated and no unchanged-SHA failure is discarded.
