# WriteLeash Free v0.2.0 — final release acceptance

Acceptance run: 2026-10-12–13 (Asia/Ho_Chi_Minh). Distribution channel: WordPress.org. This record is for the Founder-selected, immutable source SHA below. It does not authorize SVN publication.

## Source

| Item | Result |
|---|---|
| Selected source | `c219bc857d1f7d0a205caed3b22236fb5683c6bb` |
| Fetched `origin/main` at final verification | `c219bc857d1f7d0a205caed3b22236fb5683c6bb` |
| PR #257 merge | `c219bc857d1f7d0a205caed3b22236fb5683c6bb` |
| Source tree SHA | `c9b4d83a6d6ab81e6e70798f99e4ad946c4014e6` |
| Founder’s local checkout | Dirty, left untouched; all package builds ran in separate detached worktrees |
| Replace/graft/alternate objects | None found. Builder worktrees were clean and empty of ignored/untracked inputs. |

Exactly three packaged files differ from the prior v0.2 candidate: `includes/free/class-free-admin.php`, `includes/free/class-price-presets.php`, and `languages/writeleash.pot`.

## Artifact

| Item | Result |
|---|---|
| ZIP A SHA-256 | `60d1b816a6cce6a067043aa2113c0e6b6ce510bc6bac24f7f644b493a1d1b017` |
| ZIP B SHA-256 | `60d1b816a6cce6a067043aa2113c0e6b6ce510bc6bac24f7f644b493a1d1b017` |
| Byte size | 2,153,128 bytes |
| Members | 46, all below the single `writeleash/` root |
| Public manifest SHA-256 | `5155ca3121772cac6397dcd4f8b90b5cb726beb14f29b0cde74ae89ab321bca3` |
| Installed runtime tree SHA-256 | `146de1dc95b716cfa9ef93aa9c30c78ac17c72f08dcdac3545e1c60a81696a56` |
| Reproducibility | PASS; two separate worktrees, byte-for-byte equal ZIPs |
| Designated artifact | `/tmp/wl240-final-v020-c219-a/writeleash-0.2.0.zip` |

Build A and B ran the existing `wordpress/release/build-wordpress-org-v02.py` from clean detached worktrees with `python3 -B`. Python bytecode generation is disabled so the imported historical helper does not create its own ignored cache and trip the builder’s clean-tree gate. The builder verifies source SHA, per-file content, public closure, deterministic ZIP metadata, package preflight, forbidden payloads, and trunk/tag/asset parity. Both evidence JSON files retain the complete 46-file and 11-asset inventories and hashes.

The PHP header and runtime constant are version 0.2.0, and the Stable Tag is 0.2.0. The PR #257 Admin/adapter/POT changes are present in both builds: [A receipt](artifact-a.json), [B receipt](artifact-b.json), and the [public manifest](public-manifest.txt). All four checked WordPress installations contain exactly 46 files byte-matching the ZIP; see [installed per-file hashes/profiles](installed-hashes-and-profiles.json).

## Acceptance

Fresh exact-ZIP installs were tested on WordPress 7.1.2 / WooCommerce 11.1.2 / PHP 8.2.32 / MariaDB 10.11.15 / default cache. Chrome 148 exercised the real Admin UI.

- **Preset simple and variation labels: PASS.** Saved presets load readable names, SKUs, and variation attributes before Preview in both JavaScript and no-JavaScript Admin. Exact selected IDs remain selected and editable through the existing picker. [Loaded variation, JavaScript](screenshots/loaded-variation-javascript.png) and [no-JavaScript](screenshots/loaded-variation-no-javascript.png).
- **Unavailable references: PASS.** Deleted, draft, and inaccessible targets remain explicitly selected as generic removable placeholders. The inaccessible product name is not disclosed, and a new Preview still enforces authorization.
- **Range and fresh Preview: PASS.** Loaded range bounds/basis persist. The UI regression edits the Woo price through ordinary Woo APIs and confirms the new Preview uses the current price (110.00 → 116.00) and requires a new approval (`approver_id=0`).
- **No writes on Load: PASS.** Browser price/job snapshots and a product-save hook observe zero price writes on Preset save/load. Loading does not approve or alter prior jobs.
- **Apply / History / Undo: PASS.** The installed-ZIP Chromium browser regression completed 264 assertions. A newer $120.00 edit survived Apply instead of the reviewed $80.00; Undo preserved a later $93.00 edit and restored 20 eligible prices. [Apply conflict](screenshots/newer-price-conflict.png), [History](screenshots/post-apply-history.png), and [Undo](screenshots/conditional-undo-results.png), and the [fresh Preview](screenshots/installed-zip-preview.png).
- **Authorization and target limits: PASS.** The installed Admin suite completed 1,703 assertions, including capability/nonce refusal, missing-Woo refusal, 1,000 selected targets accepted and 1,001 refused.
- **Presets / Repeat / Exclusions: PASS.** Existing real-browser Preset, inclusive Range, Exclusions and Repeat journeys passed in JS and no-JS modes, including fresh Preview and separate approval. Preset/Repeat integration passed 78 assertions; Preset slot-concurrency/CAS/ownership passed 34.
- **Journal and recovery: PASS.** Existing durable-journal suite passed 394 assertions, covering actual worker interruption, lost connection, ambiguous commits, no replay and Undo conflicts. The focused Admin recovery integration passed 65 assertions.
- **Superseded-candidate data preservation: PASS.** A disposable installation whose 46-file tree matched the previous accepted runtime was updated through normal WP-CLI `Plugin_Upgrader`. After installing the exact new ZIP, its 3 jobs, 11 journal rows and sampled Woo prices were unchanged. This is not evidence of an update from a public v0.1 release; no public v0.1 installation was available.
- **Package/release regressions: PASS.** Existing artifact tests passed 10 tests; B1/B2/B3 corruption/replacement controls passed 5 tests; local exact-source `PR_FAST` passed, including PHP lint for 224 source/fixture files. The official WP-CLI 2.12.0 readme validator check echoed the packaged readme byte-for-byte, with no errors/warnings; it reports only informational notes that no Upgrade Notice or donate link was provided. [Validation result](distribution/readme-validator.json).
- **Official Plugin Check: PASS with existing warnings.** WordPress.org Plugin Check 2.1.0 static and runtime-enabled commands both exited 0 and reported 0 ERROR / 15 WARNING. The findings match the prior documented direct-SQL warnings: 13 owned-journal migration SQL findings and 2 Preset reservation/CAS findings. `class-price-apply-journal.php` is byte-identical to the previous source; the Preset warning sites were not changed by PR #257. No check was excluded or suppressed. See [scanner command/result metadata](plugins/PLUGIN-CHECK.json) and the original [static output](plugins/plugin-check-static.console.gz)/[runtime output](plugins/plugin-check-runtime.stdout.gz). Version 2.1.0 is listed on the official [Plugin Check page](https://wordpress.org/plugins/plugin-check/).
- **Exact-source CI: PASS on identical code tree.** GitHub run [38260845880](https://github.com/MrDarkRoot/WriteLeash/actions/runs/38260845880) succeeded for PR #257 head `a1d4d0c43d2f4d61f92abed32b2c64e934ddae6e`; its tree SHA is identical to selected merge source `c219bc8…`. PR_FAST, Plan, Journal, Jobs, Undo, Admin and CI_COVERAGE passed. Other path-owned families were skipped. The merge commit itself has no separate check run; exact selected SHA also passed local PR_FAST.

## Distribution readiness

- **Local layout: PASS.** A rehearsal in an isolated `file://` SVN repository has 46 files directly under `trunk/`, a byte-identical `tags/0.2.0/`, and 11 source-matched top-level assets. PNG/SVG MIME types are set. Its working-copy status/diff contains only the 11 planned asset MIME-property changes; no payload additions, deletions or replacements are unrelated. See the [local SVN rehearsal](distribution/local-svn-rehearsal.json).
- **Public state: NOT RELEASED.** Read-only SVN shows empty `trunk/`, `tags/` and `assets/`; the Plugin Info API reports “Plugin not found,” and the directory URL redirects to search. No public download or update is available/claimed.
- **Slug-specific SVN committer access: NOT TESTED.** No private credential or SVN write was used. Remote SVN writes, release confirmation and publication: NONE.

## Independent verification and verdict

[The focused ten-point review disposition](INDEPENDENT-REVIEW.json) records the artifact and result checks. The Release Acceptance reviewer ran the requested second-pass checks against this new artifact and retained ZIP-bound results rather than relying on superseded evidence. The reviewer checked source/main identity, both build receipts and byte equality, installed per-file hashes, Preset UI and authority, no-write/approval separation, Apply/History/Undo, data preservation, Plugin Check, local SVN parity, and the explicit NOT_TESTED boundaries. PR #257’s merge/CI supports the source fix but does not substitute for this installed-artifact review. Outcome: `GO_WITH_DOCUMENTED_NONBLOCKING_RISKS`.

**Independent review outcome: `GO_WITH_DOCUMENTED_NONBLOCKING_RISKS`.** No product/runtime or artifact blocker was demonstrated.

**Overall verdict: READY_FOR_FOUNDER_RELEASE_GO.** The Founder must still authorize publication for this exact checksum. After that GO, the publisher must privately confirm slug-specific SVN rights, publish trunk/tag/assets, complete any release-confirmation/security-review process, and verify public download/update visibility. Keep #240 open until actual publication and public verification are complete.

Other explicit `NOT_TESTED` profiles: PHP 7.4/8.0/8.1; MySQL; persistent Redis cache; manual screen-reader review; other browsers/host combinations; an installed/public v0.1 upgrade path (none is publicly distributed); private publisher authorization; and public release visibility.
