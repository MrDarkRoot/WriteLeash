# #240 — WordPress.org Free v0.2.0 candidate

## RELEASE CANDIDATE VERDICT: BLOCKED

The available exact-ZIP checks passed after the documented fixture corrections. **This is a proposed release artifact, not an authorized publication or a final accepted source freeze.** Draft [PR #253](https://github.com/MrDarkRoot/WriteLeash/pull/253) requires review/merge and source revalidation. The remaining advertised WP/PHP profiles and private #133 committer readiness are outstanding. Founder publication GO is **NOT GRANTED**.

## Identity and channel

| Identity | Value |
|---|---|
| Founder-selected / fetched main | `566a91f3d41e2f7d030e1154478e2f023b2409bf` |
| Proposed candidate source / builder Git SHA | `39d67bfba9cd4f265c3dfa34657c76abe203fa79` |
| Initial tooling source, retained in original run rows | `ba249dc96d3ea8318df0f3e65cf5a0833422e10a` |
| Builder file SHA-256 | `f44627b25a13da59fdb93f86b588c00a42245dfcc20d450c1b50522621ae83b1` |
| Public manifest SHA-256 | `5155ca3121772cac6397dcd4f8b90b5cb726beb14f29b0cde74ae89ab321bca3` |
| ZIP SHA-256 | `50901f2c034c705dff355427fe496090d1e4968f0c299853b1b9a4da46655757` |
| Version / Stable Tag | `0.2.0` / `0.2.0` |
| ZIP / payload | `writeleash-0.2.0.zip`; 2,151,312 bytes; 46 files, 39 PHP |
| Runtime / local SVN trunk/tag hash | `a1459fe64ebc0d808bddfe2cd335e7d957fb4f771a34a38a62f9799e34db8fbc` |

The release branch adds only tooling, three focused acceptance fixtures, and evidence. Plugin runtime, readme, Woo changelog, screenshots/assets, and the existing public manifest are unchanged from selected main. The test-only second commit was rebuilt twice; both archives match each other **and the first tooling commit's ZIP byte-for-byte**. Original run source labels remain intact. [ARTIFACT.json](ARTIFACT.json), [PAYLOAD-EQUIVALENCE.json](PAYLOAD-EQUIVALENCE.json) and installed-file hash receipts document the identity relation explicitly; no changed package inherits an old checksum.

The new `build-wordpress-org-v02.py` uses the canonical `writeleash-distribution-files.txt` and the reviewed deterministic helpers in the **unchanged** historical v0.1 builder. It does not use the Woo builder/manifest. This channel's accurate public changelog is the readme Changelog; repository README and Woo-only `changelog.txt` are not runtime payload. The separate Woo 45/47 discrepancy is not changed or called a WordPress.org defect.

## Feature inventory — source and executed evidence

Every merge below was checked as an ancestor of the selected source using Git and GitHub merged-PR receipts, not issue closure. Full receipts: [FEATURE-INVENTORY.json](FEATURE-INVENTORY.json). PASS here means the specified source/functional evidence, not every advertised platform.

| Issue | Accepted PR / merge prefix | Shipped behavior and current evidence | Verdict |
|---|---|---|---|
| #204 | #223 / `dc151e2` (integrated #221) | Bounded authenticated read-only Apply/Undo progress; real `progress-integration.php` plus shipped-client model and Chromium regression | PASS |
| #205 | #224 / `fd7a0dc` | Conflict recovery requires new current-price Preview and separate approval; `recovery-integration.php` on both engines/cache modes | PASS |
| #206 | #223 / `dc151e2` (integrated #219) | Explicit nested categories, bounded selection counts, frozen targets; Admin suite cases | PASS |
| #207 | #235 / `5a10195` | Blank Clear Sale and discount from reviewed Regular basis; Admin and domain controls | PASS |
| #208 | #237 / `3e90043` | Precision/eligibility/safety-checked endings; Admin suite and Chromium keyboard final-Preview controls | PASS |
| #209 | #223 / `dc151e2` (integrated #220) | Public core product reads and targeted repair; no former Internal ProductCache dependency; default/Redis real mutation and conflict tests | PASS |
| #210 | #225 / `f02db25` | Merchant PHP/JS translation hooks and committed POT in ZIP; i18n/catalog/client checks | PASS — translation plumbing; no new human-language certification |
| #230 | #241 / `1ab8af4` | Bounded signed editable settings, not old targets/approval/exclusions; real option/actor/nonce/CAS controls; browser JS/no-JS/keyboard | PASS |
| #231 | #239 / `464cfd0` | Reviewed refinement; confirmed excluded IDs absent from Apply/Undo and journal; 350→343 real fixture, zero excluded saves/journal | PASS |
| #232 | #242 / `04e7f3a` | Current-price new plan, distinct identity and approval; included-only refined suggestions; real Repeat and combined Presets/Repeat browser | PASS |
| #233 | #238 / `da84e64` | CSV from frozen saved plan, author/nonce/integrity/no-write checks and current-catalog drift | PASS |
| #234 | #236 / `778af88` | Inclusive stored Regular/Sale bounds, independent variation prices, blank-sale refusal; Admin plus range round trips | PASS |
| #229 | #229 / `5d8d8cd` | Draft/non-core parent Apply refusal; frozen-parent Undo conflict; unchanged-parent restore controls, both fields | PASS — existing DB fixture reused with a measured save observer |

#244/#245/#246/#250 documentation merges (#248/#247/#249/#251) were also verified. Author `Duy Tran`, contributor `duyytrann`, GPLv2-or-later, dependency and honest compatibility metadata match the source. Readme is 9,491 UTF-8 bytes, five tags, 99-character description. It explicitly requires fresh Preview/approval for Presets and Repeat and does not announce a verified public download. Six historical screenshot captions explicitly say earlier captures/current controls may differ; all eleven approved asset names/hashes/dimensions and numbering pass the existing asset audit. No new screenshot was silently advertised as historical proof.

## Acceptance levels and actual environment

All artifact-bound results use the ZIP checksum in the identity table. [RUN-LEDGER.json](RUN-LEDGER.json) and [logs](logs/) preserve completed PASS/FAIL/BLOCKED/NOT_TESTED records and original failure attempts.

| Level | Executed evidence |
|---|---|
| SOURCE TESTED | PR_FAST plus 20 collected DB-free commands; separate Presets/Repeat unit controls; no-replan and Admin static boundaries |
| BUILT ZIP TESTED | Two clean independent proposed-source builds; sorted single `writeleash/` root, exact committed bytes, metadata, public closure, license, payload scan and eleven corruption controls |
| INSTALLED ZIP TESTED | Normal WP-CLI plugin installation uses WordPress Plugin_Upgrader; Core activation API with explicit network-wide=false; all 46 installed files match in MySQL, MariaDB, upgrade and missing-dependency sites |
| UPGRADE TESTED | Historical #194 v0.1 ZIP independently rebuilt and matched `b8bd5f406667f6f686348ea67453ec25b90e09607cd9a13b542e6054c9f8a3e8`; old completed/Undo-eligible/unfinished percent jobs seeded before in-place v0.2 ZIP replacement |
| SVN LOCALLY STAGED | Generated trees and actual local SVN working-copy additions; local `svn copy trunk tags/0.2.0`; trunk/tag/ZIP and all eleven assets byte-equivalent |
| PUBLIC DISTRIBUTION VERIFIED | **No installable WriteLeash release verified.** See public-state evidence below |

Real disposable profiles: WordPress **7.1.2**, WooCommerce **11.1.2**, PHP CLI/server **8.2.32**, MySQL **8.0.44**, MariaDB **10.11.15-MariaDB**. Default cache and Redis **7.4.2** / Redis Object Cache **2.7.0** / Predis **2.4.0** ran on both engines. Docker daemon is unavailable, so retained portable binaries were reused **read-only** with new task-owned data directories/ports; other agents' databases and sites were not used or modified. Core/Woo archives were checked, including official installed Core/Woo checksum verification. Git 2.43.0, Python 3.12.3, WP-CLI 2.12.0 and SVN 1.14.3 were observed.

The existing Admin feature suite completed **1,703 assertions in each of four DB/cache profiles**. Separate progress, recovery, Repeat, combined Presets/Repeat and nonce-normalization fixtures completed in all four. Existing real-HTTP form/PRG/session/Preview/Apply/History/Undo/CSV tests passed in both persistent profiles and the corrected default-profile retests. A cache-mode-specific Core update transient had to be cleared in the disposable fixture; no assertion or runtime was changed.

Chromium **148.0.7778.178**: 115 Presets/Repeat checks including JS enabled/disabled, keyboard CRUD, range/refinement, current-price new Preview and separate approval; 264 existing regression/safety/accessibility checks including Price Endings. Automated axe scans emitted no plugin-region violations. Screen-reader manual testing is NOT_TESTED. Local browser captures remain outside the public plugin/SVN assets.

Focused real-DB checks also passed:

- **1,000 selected targets:** two changing and 998 unchanged, truthful durable outcomes and eligible Undo of two changes; 1,001 targets refused. This does not claim 1,000 changed items or publish elapsed-time benchmarks.
- **Historical upgrade:** original plan JSON/hash and creator/approver/approval fields retained; old item material unchanged before Resume; partially applied percent job finished at 110, not 121; eligible Undo restored 100 and an externally changed 120 remained a conflict. This is a historical artifact fixture, not a public-directory update test.
- **Missing Woo:** normal installation refused activation with a clear dependency warning; inactive plugin, zero products and zero plugin tables.
- **Lifecycle/uninstall:** existing real Core hook fixture proved runner-first shutdown, no DDL/privilege/product writes, preservation of product metadata and durable evidence, and cancellation limited to owned action groups. Reactivation succeeded.
- **Parent guards:** existing #229 fixture ran on both engines/default/Redis. The wrapper explicitly measures successful control saves so refusal zero-save assertions cannot be vacuous.
- **Direct access:** all 39 packaged PHP files exited silently without WordPress; no output/stderr.

## WordPress.org compliance / security

Current [directory guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/), [official Plugin Check](https://wordpress.org/plugins/plugin-check/), and [readme validator](https://wordpress.org/plugins/developers/readme-validator/) were consulted. Official Plugin Check API reports **2.1.0**; the downloaded tool ZIP matches repository-pinned SHA-256 `6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4`.

Official readme validation submitted the **exact ZIP readme** using the site's Base64 UTF-8 form contract and verified the returned readme byte-for-byte. No errors/warnings; informational notes: no Upgrade Notice and no donation link. The first transport calibration sent unencoded text and did not validate a candidate; its receipt is retained as NOT_TESTED, not PASS.

Plugin Check ran on an **installed** exact ZIP with runtime bootstrap, low-severity and experimental checks enabled, without check/category/file/code exclusions or AI filtering. Raw result: **one ERROR and nine WARNING findings**, independently reviewed below; exit code 0 alone is not clearance. Raw output and check inventory are retained. All ten are supported implementation diagnostics/false positives, not silently discarded; no suppression was added.

| Location / code | Assessment and disposition |
|---|---|
| journal `121:25` DirectQuery / NoCaching | Prepared live owned-table existence probe; caching would make migration decisions stale. Justified two warnings |
| journal `123:24` DirectQuery / NoCaching | `SHOW COLUMNS` uses prepared `%i` validated identifier; must inspect actual structure. Justified two warnings |
| journal `127:26` UnescapedDBParameter (ERROR) | `$changes` contains only the three literal DDL fragments at 124–126; request/DB values never enter fragments. Table regex plus `%i` preparation remains. Source-backed false positive |
| journal `127:28` DirectQuery / NoCaching; `127:58` SchemaChange | Required plugin-owned schema migration. Static fragments, prepared identifier, cache metadata invalidation and durable validation; justified three warnings |
| presets `133:25` DirectQuery | Prepared `INSERT IGNORE` reserves a bounded option slot atomically without overwriting its owner; Core add_option may upsert. Value/name/autoload are prepared and option/notoptions caches invalidated. Justified warning |
| presets `149:20` DirectQuery | Prepared old-serialized-value compare-and-swap prevents stale rename/delete on a reused slot; per-key/notoptions cache repair. Justified warning |

Fresh source/installed-byte review preserves independent capability, POST, normalized action-bound nonce and job/creator ownership gates; strict bounded selector/operation/configuration validation; late HTML/URL escaping and Unicode/formula CSV defenses; signed canonical Preset configuration with no standing approval; immutable Plan/journal/Undo binding and variation-parent guards; prepared SQL and pinned transaction/fence ownership; durable recovery and conditional Undo. No new tracking, remote executable code/updater, license/paywall, unsolicited advertising or review nag was found in the public closure. Direct journal SQL/custom tables were not removed to appease a scanner. This is bounded review and executable evidence, not a universal security certification or Woo QIT result.

## Failure attempts and their resolution

1. An interrupted first run and a subsequent 360-second launcher budget did not complete the large Admin fixture; BLOCKED, no full-suite PASS. Re-execution used streaming logs and a sufficient local fixture budget; the unchanged full four-profile suite completed.
2. Both initial default-cache HTTP runs (and the first MariaDB retest) failed the notice-role assertion. Captured offending DOM was **Core's** `notice notice-warning update-nag inline`, “WordPress 7.1.3 is available!”, not WriteLeash output. Matched the existing fixture's network blocking, then cleared Core's separate database transient **after disabling external cache**. Unchanged default HTTP tests passed on both engines. All failures are retained and linked to retests.
3. Initial new parent wrapper's save counter was local to WP-CLI eval scope, making zero-save instrumentation insufficient. Declared it global and required measured successful control saves; corrected tests passed in both engines/cache modes. Plugin bytes/checksum did not change. Earlier weak observer output is not used as no-write proof.

## Public listing / SVN / credentials

[PUBLIC-STATE.json](PUBLIC-STATE.json) contains URLs, timestamps and responses. Listing returns the “no results” search page; plugin-info API and `writeleash.0.1.0.zip` download return **404**. Public SVN is readable but trunk/tags/assets are empty; no public Stable Tag exists. The synthetic 0.1 update API query returns no WriteLeash update. Thus no distributed v0.1 baseline or public update PASS is claimed. Approval remains founder-reported; no private approval/hold reason was inferred from these public observations.

Published contributor identity is `duyytrann`. Authorized committer/account and credential readiness still require the **private #133 maintainer confirmation**; issue remains open with no completion acknowledgment observed. No credentials were used for SVN staging, requested in public comments, or added to payload/evidence. Only anonymous read-only SVN checkout/list/info and **local** add/copy/status/diff occurred. [SVN-INVENTORY.json](SVN-INVENTORY.json) records all tree hashes; full local diff is retained at `/tmp/opencode/wl240-env/evidence/svn-local-diff.txt` and working copy `/tmp/opencode/wl240-svn-working`.

## Reproduction / CI / outstanding gaps

```sh
git fetch origin work/240-wporg-v02-candidate
git worktree add --detach /tmp/wl240-repro-a 39d67bfba9cd4f265c3dfa34657c76abe203fa79
git worktree add --detach /tmp/wl240-repro-b 39d67bfba9cd4f265c3dfa34657c76abe203fa79
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wl240-repro-a/wordpress/release/build-wordpress-org-v02.py --source /tmp/wl240-repro-a --sha 39d67bfba9cd4f265c3dfa34657c76abe203fa79 --version 0.2.0 --output /tmp/wl240-repro-build-a
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wl240-repro-b/wordpress/release/build-wordpress-org-v02.py --source /tmp/wl240-repro-b --sha 39d67bfba9cd4f265c3dfa34657c76abe203fa79 --version 0.2.0 --output /tmp/wl240-repro-build-b
cmp /tmp/wl240-repro-build-a/writeleash-0.2.0.zip /tmp/wl240-repro-build-b/writeleash-0.2.0.zip
sha256sum /tmp/wl240-repro-build-a/writeleash-0.2.0.zip
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wl240-repro-a/wordpress/release/test-wordpress-org-v02.py /tmp/wl240-repro-build-a
```

Use new empty output paths and clean source worktrees. Normal install command is `wp --path=<disposable-site> plugin install <exact-ZIP>` followed by Core activation with explicit false network-wide flag. Existing `wordpress/tests/admin/` fixtures run with WP-CLI against that ZIP-installed plugin; do not use stage-plugin.sh to replace it. `upgrade-v02.php` uses `WL240_UPGRADE_MODE=seed|verify` and a private `WL240_UPGRADE_RECEIPT`, with old ZIP installed before seed and exact new ZIP installed through Plugin_Upgrader before verify. `parent-state-v02.php` and `scale-v02.php` run via `wp eval-file`; scale creates 1,001 synthetic products, so use only a disposable site. Runtime and browser commands/versions are enumerated in the ledger.

Proposed-source [CI run 38184219781](https://github.com/MrDarkRoot/WriteLeash/actions/runs/38184219781): exact HEAD `39d67bf…`, PR_FAST and CI_COVERAGE **PASS**. All thirteen downstream families were **SKIPPED** under existing path ownership, not PASS. Evidence-only final HEAD receives its own CI result in the PR handoff. Graph impacts/change checks were run; file-node UNKNOWN references were checked against actual builder/test consumers. PHP name-fallback/process truncation prevents treating empty graph effects as proof of isolation; Git diff independently confirms no production delta.

- **Unresolved FAIL:** none established as a product/security defect. Fixture failures above are preserved and resolved.
- **BLOCKED:** draft tooling proposal review/merge and final accepted-source revalidation; private #133 committer readiness; Founder GO on the exact accepted artifact.
- **NOT_TESTED:** exact-ZIP WordPress 7.0.1 and PHP 7.4.33/8.0.30/8.1.34/8.2.34 profiles; a new full process-kill/connection-loss matrix; manual screen reader; public-directory update/distribution. Historical/source CI is not substituted for these installed-artifact results. Shared/managed hosting remains outside tested claims.
- **Smallest next actions:** review #253; freeze the accepted resulting SHA; rebuild and recheck identity; provision remaining advertised WP/PHP profiles using the existing isolated fixtures and execute required upgrade/recovery checks; obtain private committer confirmation. Only then seek Founder publication GO. Any runtime, listing, asset or builder change requires new artifact identity and affected reruns.

**Release authority:** Founder publication GO NOT GRANTED · remote SVN writes NONE · release confirmation NOT PERFORMED · public distribution NOT CLAIMED · #240 remains open.
