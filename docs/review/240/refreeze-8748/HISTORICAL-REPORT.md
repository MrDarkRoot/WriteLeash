# #240 — FINAL SOURCE FREEZE & ACCEPTANCE

**Overall verdict: READY_FOR_FOUNDER_RELEASE_GO — technical artifact preparation.**

The selected source, deterministic artifact, minimum-version acceptance, focused
upgrade/recovery and local SVN staging are validated. This is **not publication
permission or an assertion of verified committer credentials**. Founder retains
the GO/NO-GO decision on the exact checksum. No remote SVN commit, release
confirmation, public distribution claim or #240 closure occurred.

## Frozen identity

| Field | Verified value |
|---|---|
| Frozen source SHA | `04dbbcd11a53abbf816ad1b11b7e659156daca29` |
| PR #253 merge parents | `566a91f3d41e2f7d030e1154478e2f023b2409bf`, `886f87bdf2507d10b115fb25a883a37e3b02bfd6` |
| Builder checksum | `775d98483cf0fd65cab855c7246d3f1b8734492adbd910d9a0c07dcac2ed48cb` |
| Manifest checksum | `5155ca3121772cac6397dcd4f8b90b5cb726beb14f29b0cde74ae89ab321bca3` |
| ZIP A SHA-256 | `50901f2c034c705dff355427fe496090d1e4968f0c299853b1b9a4da46655757` |
| ZIP B SHA-256 | `50901f2c034c705dff355427fe496090d1e4968f0c299853b1b9a4da46655757` |
| Previous accepted ZIP SHA-256 | `50901f2c034c705dff355427fe496090d1e4968f0c299853b1b9a4da46655757` |
| ZIP size / file count | 2,151,312 bytes / 46 public plugin files |
| Runtime / SVN trunk / tag tree checksum | `a1459fe64ebc0d808bddfe2cd335e7d957fb4f771a34a38a62f9799e34db8fbc` |
| Version / Stable Tag / plugin root | `0.2.0` / `0.2.0` / one `writeleash/` root |

Remote fetch, commit metadata, ancestor check and GitHub's merged-PR receipt
verified the selected commit. Two new detached worktrees were created at that
exact SHA, and their clean/ignored/untracked status was checked. No newer `main`
was substituted. [FREEZE-ARTIFACT.json](final/FREEZE-ARTIFACT.json) records both
full builder receipts, exact commands, tool versions and inventories.

**Artifact source is the frozen SHA above.** This evidence/test-only Draft PR has
its own commit identity; it does not become a new release source. Executed new
test files are bound by their content hashes in the fresh ledger. Production
runtime, public metadata, manifest, assets and builder are unchanged.

## Reproducibility

- **Two clean independent builds: PASS.** `cmp` confirms identical ZIP bytes;
  both match the previously accepted checksum. No new checksum was substituted.
- **Source-to-ZIP closure: PASS.** Canonical WordPress.org public manifest,
  committed/local bytes, exact members, fixed ZIP ordering/timestamps/permissions,
  source/readme/license/include closure and generated SVN parity pass.
- **Channel isolation: PASS.** The builder uses
  `writeleash-distribution-files.txt`, not the Woo Marketplace manifest. Eleven
  directory assets are separate from the 46-file ZIP/trunk/tag payload.
- **Corruption controls: PASS.** All eleven existing controls pass. All five
  replacement-object/observer/false-UNDONE controls pass, including genuine
  positive controls. The merged hardened v0.2 Git boundary remains unchanged.
- **Historical v0.1 regressions: PASS.** All ten existing artifact tests pass.
  The historical builder/evidence are unchanged. An additional clean historical
  rebuild matches `b8bd5f406667f6f686348ea67453ec25b90e09607cd9a13b542e6054c9f8a3e8`.
- Build tools: Python 3.12.3, Git 2.43.0, PHP 8.2.32. Compatibility runtimes were
  separately extracted; the host PHP installation was not replaced.

Reproduction, using new empty paths:

```sh
git fetch origin --prune
git worktree add --detach /tmp/wl240-final-a 04dbbcd11a53abbf816ad1b11b7e659156daca29
git worktree add --detach /tmp/wl240-final-b 04dbbcd11a53abbf816ad1b11b7e659156daca29
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wl240-final-a/wordpress/release/build-wordpress-org-v02.py --source /tmp/wl240-final-a --sha 04dbbcd11a53abbf816ad1b11b7e659156daca29 --version 0.2.0 --output /tmp/wl240-final-build-a
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wl240-final-b/wordpress/release/build-wordpress-org-v02.py --source /tmp/wl240-final-b --sha 04dbbcd11a53abbf816ad1b11b7e659156daca29 --version 0.2.0 --output /tmp/wl240-final-build-b
cmp /tmp/wl240-final-build-a/writeleash-0.2.0.zip /tmp/wl240-final-build-b/writeleash-0.2.0.zip
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wl240-final-a/wordpress/release/test-wordpress-org-v02.py /tmp/wl240-final-build-a
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wl240-final-a/wordpress/release/test-wordpress-org-v02-regressions.py
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wl240-final-a/wordpress/release/test-artifact.py
```

## Acceptance — fresh versus retained evidence

### Freshly rerun exact-ZIP profiles

The plugin declares WordPress 7.0 and PHP 7.4 minimum families. WooCommerce 10.0.0
declares WordPress 6.7 / PHP 7.4, and was deliberately used for older runtimes.
WordPress 7.0.1 is the tested minimum-series patch, not a claim that every 7.0/PHP
7.4 patch or every Woo/PHP cross-product was exercised.

| WordPress | WooCommerce | PHP | DB / cache | Exact-ZIP result |
|---|---|---|---|---|
| 7.0.1 | 10.0.0 | 7.4.33 | MariaDB 10.11.15 / default | PASS |
| 7.0.1 | 10.0.0 | 8.0.30 | MariaDB 10.11.15 / default | PASS |
| 7.0.1 | 10.0.0 | 8.1.34 | MariaDB 10.11.15 / default | PASS |
| 7.1.2 | 11.1.2 | 8.2.34 | MariaDB 10.11.15 / default | PASS |

Each profile installed the freshly rebuilt ZIP through normal WP-CLI
Plugin_Upgrader, verified every installed file against the archive, observed
missing-Woo activation refusal/inactive state, installed/activated compatible
Woo, then activated WriteLeash through the Core API. Each executed:

- Fresh Preview → separate Approve → actual Apply → durable History → eligible
  Undo, verifying actual values, immutable plan material and write observation.
- Bad nonce, wrong HTTP method and anonymous refusals with no product writes.
- Existing combined Presets/Repeat integration: fresh calculations/identities,
  independent approval, range/exclusion no-write controls, retained historical
  material, actor/capability/nonce/HMAC boundaries and atomic stored configuration.

PHP packages were downloaded from the already configured Ondřej PHP PPA and
extracted to isolated prefixes; no system package installation or runtime update
was performed. Exact distro builds and all package SHA-256 values are retained in
[PHP-PACKAGE-PROVENANCE.json](final/PHP-PACKAGE-PROVENANCE.json). Downloaded Core/Woo
archives are recorded in [DOWNLOADED-ARCHIVES.json](final/DOWNLOADED-ARCHIVES.json).

There are **55 fresh execution records**, including setup/installation/environment,
the eight profile fixtures, focused upgrade and fault-probe phases. See
[FRESH-RUN-LEDGER.json](final/FRESH-RUN-LEDGER.json) for executable commands,
environment, timestamps, expected/actual exit codes, raw-log hashes and test
content identities. Expected SIGKILL outcomes are fault interventions, not a
generic application PASS; independent observation/recovery assertions follow.

### Historical upgrade — stronger focused coverage

Fresh historical rebuild/installed hashes bind the seed to the recorded #194
artifact, not version alone. The test adds a partially applied three-item percent
job with one **pending** item externally edited to 120 before replacement.
Normal in-place ZIP upgrade then verifies:

- Original public job ID, plan JSON/hash, creator/approver/approval and old
  orchestration items preserved before Resume.
- Direct Apply-journal identity, immutable values, state/attempt/evidence and
  history timestamps preserved, allowing only genuine schema-derived fields to
  be outside the comparison.
- Old applied item remains 110, never 121; unchanged pending item becomes 110;
  edited pending item stays 120 and records a conflict.
- Exactly the two applied items are Undo-eligible; the conflicted pending item
  has no Undo row; eligible values return to 100 and durable UNDONE counts agree.
- Existing completed/Undo-eligible/clean resumable cases still pass, including
  preservation of a newer completed-item edit during Undo.

[Upgrade verification log](final/logs/upgrade-verify.txt). This is a historical
artifact fixture, **not a public-directory update test**; no public v0.1 baseline
was verified. No frozen production code was modified to obtain a PASS.

### Fresh recovery probes

On the exact installed artifact, PHP 8.2.34 / WP 7.1.2 / Woo 11.1.2 / MariaDB
default cache, three real failure-boundary cases pass:

1. Real SIGKILL after Woo save but before journal/commit: database price remains
   100 and journal PENDING; natural lease expiry then explicit Resume completes
   at 110 once, with actual eligible Undo to 100.
2. Real SIGKILL after commit but before worker response: price 110 and journal
   APPLIED remain authoritative; natural lease expiry/reconciliation does not
   replay the percentage to 121; remaining items and Undo remain truthful.
3. Actual worker-connection KILL after Woo save: rollback leaves price 100,
   uncertain old evidence remains NEEDS_REVIEW, and old job cannot claim Undo.
   A fresh current-price Preview and independent approval apply once; old
   uncertain journal is preserved, not silently rewritten as APPLIED.

The harness waits for a real checkpoint before sending SIGKILL and waits for the
normal lease TTL, rather than forcing takeover with SQL. Only task-owned DBs and
workers are affected. This covers key boundaries; it is not an exhaustive
cross-engine/cache/PHP/variation/host failure matrix.

### Evidence provenance improvements

- Actual observed installed hashes for all fresh candidate/historical sites are
  retained in [OBSERVED-INSTALLED-HASHES.json](final/OBSERVED-INSTALLED-HASHES.json),
  including 46 candidate / 42 historical paths and exact archive identities.
- All 92 supplied original log hashes verify, and original raw receipts/failures
  remain intact. Nothing was relabeled as a freshly executed PASS.
- Of the 78 old PASS records without commands, **48 command records** are
  supported by retained runner source and are appended with explicit
  `RECONSTRUCTED_FROM_RETAINED_RUNNER; NOT_ORIGINAL_COMMAND_CAPTURE` provenance.
  Four source snapshots are sanitized; original-local and redacted hashes are
  distinguished. **30 records remain unavailable**; commands are not invented.
  [COMMAND-PROVENANCE-ADDENDUM.json](final/COMMAND-PROVENANCE-ADDENDUM.json).
- Old weak parent/scale claims are superseded by merged B1–B3 installed receipts,
  not rehabilitated by checksum equality. The old upgrade's clean-Resume-only
  gap is superseded by the new pending-drift/direct-journal test.

### Previously retained exact-ZIP evidence

Because all 46 runtime-file hashes, assets and archive bytes match the accepted
candidate, the broader WP 7.1.2 / Woo 11.1.2 / PHP 8.2.32 MySQL/MariaDB ×
default/Redis Admin, browser, lifecycle/uninstall, translation and feature
receipts remain **historical reported evidence for the identical artifact**.
Official Plugin Check 2.1.0 and official web readme validation are also retained,
not claimed as freshly rerun here. See
[RETAINED-EVIDENCE-AUDIT.json](final/RETAINED-EVIDENCE-AUDIT.json).

## Plugin Check dispositions

The exact frozen/installed runtime bytes match those inspected by the independent
reviewer. Original [PLUGIN-CHECK-RAW.json](PLUGIN-CHECK-RAW.json) remains untouched;
its stdout digest `d79651d251587581e50c76415933ea79a8c09264bd19af01979dd886890b05d6`
verifies. The actual output still contains **one ERROR and nine WARNINGs**.

Paths below are under `wordpress/writeleash/includes/free/`.

| Finding / source location | Disposition | Source-level rationale |
|---|---|---|
| journal 121:25 DirectQuery | JUSTIFIED_IMPLEMENTATION | Owned-table existence; table-name value prepared with `%s`. |
| journal 121:25 NoCaching | JUSTIFIED_IMPLEMENTATION | Live migration state must not be inferred from stale cached metadata. |
| journal 123:24 DirectQuery | JUSTIFIED_IMPLEMENTATION | SHOW COLUMNS uses regex-validated table name and prepared `%i` identifier. |
| journal 123:24 NoCaching | JUSTIFIED_IMPLEMENTATION | Actual schema inspection is migration authority. |
| journal 127:26 UnescapedDBParameter ERROR | SCANNER_FALSE_POSITIVE | `$changes` contains only the three literal fragments at 124–126; DB column names choose fragments, never become SQL fragments. Table remains validated/prepared. |
| journal 127:28 DirectQuery | JUSTIFIED_IMPLEMENTATION | Explicit owned-journal ALTER; failure throws before execution can proceed. |
| journal 127:28 NoCaching | JUSTIFIED_IMPLEMENTATION | DDL is not a cacheable read; table metadata is invalidated at 128/154. |
| journal 127:58 SchemaChange | JUSTIFIED_IMPLEMENTATION | Plugin-owned durable-schema migration followed by material/schema validation, not product/Core DDL. |
| presets 133:25 DirectQuery | JUSTIFIED_IMPLEMENTATION | Prepared bounded INSERT IGNORE reservation avoids overwriting a competing slot owner; option/notoptions cache invalidation follows. |
| presets 149:20 DirectQuery | JUSTIFIED_IMPLEMENTATION | Prepared BINARY old-value compare-and-swap rejects stale rename/delete; option-key/notoptions cache repaired after success. |

No TRUE_SECURITY_DEFECT, demonstrated COMPLIANCE_BLOCKER or unresolved injection
was established by these ten findings. No blanket exclusion, suppression or
production change was added. Core options-table identity is not request-derived;
capability/POST/action-bound nonce/actor ownership and canonical configuration
validation remain. **These dispositions do not guarantee WordPress.org automated
security-review acceptance or waive the scanner's ERROR on behalf of the directory.**

## Additional gaps — explicit classification

| Gap | Classification / rationale |
|---|---|
| Key process-kill/connection-loss boundaries | Executed before proposed GO; focused probes PASS above. |
| Expanded cross-engine/cache/PHP/variation failure matrix | Optional post-release follow-up within existing claims. Exact runtime is unchanged from broader retained safety receipts; fresh critical boundaries passed. NOT_TESTED as a full matrix; mandatory if a discrepancy appears or support claims expand. |
| Manual screen-reader review | Optional post-release follow-up under current bounded copy. No WCAG/full assistive-technology certification is advertised; retained automated axe/keyboard/no-JS results are not called manual SR PASS. NOT_TESTED. |
| Shared/managed hosts, multisite and unobserved WP/Woo/PHP combinations | NOT_TESTED; outside certified claims. Mandatory before making broader compatibility claims, not silently covered by these local profiles. |
| Public distribution/update API and release confirmation/security-hold state | Mandatory live checks only after separately authorized publication; cannot be PASS before a release exists. |
| Directory disposition of retained Plugin Check diagnostics | Founder should explicitly accept the documented source rationale when deciding GO; platform may still withhold distribution and require separate reviewed remediation. |

## SVN readiness

- **Actual existing public distribution state:** anonymous SVN info/inventory
  confirms empty trunk/tags/assets. Plugin-info API and v0.1 download both return
  404; listing redirects to a search page. No installable previous release is
  verified. Founder reports v0.1 approval; approval is not labeled distribution.
- **Local trunk/tag/ZIP parity:** PASS. Fresh anonymous working copy; files directly
  beneath trunk, no nested `trunk/writeleash`; local `svn copy trunk tags/0.2.0`;
  both 46-file trees match exact ZIP payload byte-for-byte.
- **Eleven assets:** PASS; exact expected names/hashes, top-level assets only.
- **Local SVN properties:** PNGs use `image/png`, SVG uses `image/svg+xml`;
  no externals, keyword/eol expansion or executable transformations. These local
  MIME properties do not alter frozen artifact/file bytes.
- **Status/diff reviewed:** 110 additions including necessary folders; no deletion,
  replacement, conflict, unrelated file, credential or ZIP. All staged file bytes
  were reconciled against inventories. No historical tags exist or were altered.
- **Private committer readiness: NOT_CONFIRMED by this run.** Founder directed that
  #133 issue closure is not a prerequisite and reiterated v0.1 approval. This
  report does not infer current slug-specific write permission from approval or
  request/publicize secrets. The publisher must confirm its authorized account
  privately before actual writes; this is an operator pre-publication action,
  not an invented test PASS or an insistence on closing #133.
- **Remote SVN writes: NONE.** Reads used a new empty SVN config, no auth cache or
  credentials. Only local add/copy/status/diff occurred.

[SVN-READINESS.json](final/SVN-READINESS.json) retains remote observations, every
trunk/tag/asset hash, commands, status/diff-summary logs and the full local diff
digest/path. It does not substitute an anonymous read for write authorization.

## Outstanding findings and smallest remaining actions

- **Blocking technical failures:** none established; no frozen runtime fix required.
- **Previously missing requested profiles:** all now exercised as listed. Unlisted
  cross-combinations and host/accessibility/full-failure-matrix coverage remain
  NOT_TESTED, not generalized PASS.
- **Evidence limitation:** 30 historical command invocations remain unavailable;
  the final ledger is executable/hash-bound and the old limitation is preserved.
- **Founder decisions/actions:** review this evidence/test-only Draft PR; decide GO
  on the exact ZIP and documented Plugin Check rationale; privately establish
  publishing-account readiness immediately before authorized SVN execution.
- **After authorized publication only:** verify server-side trunk/tag/assets,
  immutable revision, confirmation/security-hold status, public directory ZIP
  payload and update/distribution visibility. An actual hold/failure must be
  investigated, not called DISTRIBUTED or repaired by silently rewriting a tag.

## GitHub evidence and Founder authority

The merged tree equals PR #253's reviewed head. Its exact-head CI
[38203965947](https://github.com/MrDarkRoot/WriteLeash/actions/runs/38203965947)
passed PR_FAST and CI_COVERAGE with all thirteen downstream jobs SKIPPED and zero
selected integrations. No dedicated workflow run at merge SHA `04dbbcd…` was
found; fresh local artifact/acceptance here is not replaced by that older CI.
The evidence/test Draft PR receives its own exact-head checks, linked in the
#240 handoff; that evidence commit is distinct from the frozen artifact source.
Local final PR_FAST also passed, including PHP lint for all 220 staged
source/fixture files ([log](final/logs/final-pr-fast.txt)).

- **Exact ZIP proposed for publication:**
  `50901f2c034c705dff355427fe496090d1e4968f0c299853b1b9a4da46655757`.
- **Public SVN publish permission: NOT GRANTED.**
- **Release confirmation: NOT PERFORMED.**
- **Public distribution: NOT CLAIMED.**
- **#240 remains open; Founder final GO/NO-GO required.**
