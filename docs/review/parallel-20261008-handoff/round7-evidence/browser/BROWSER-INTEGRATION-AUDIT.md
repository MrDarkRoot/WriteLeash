# Round 7 integration and browser evidence audit

## Historical candidate identity

Main baseline `c48e307efe7750d0fb36015c39d50a34675f9579`. Four original heads: #220 `c2e3f6167879bbc5ac7ba9c798cbb43ce672608d`, #219 `eaad5dd0b1703997667474edb55f928fd2e75d0d`, #221 `fd0dc73e8a1598da1c894a6be24be28f860c286d`, #222 `079f5dad148a8718586db05b4abc322047908710`.

Preserved integrated commit `3b4ab5e8895fb60dbcb00d26d5c597f129b9ed8f`, full Git tree `4bbfc135378836009f1096b59b70b5d7ac46447e`. Independently reconstructed in logical #220 → #219 → #221 → #222 order as detached `6e3da0589e8a7daddf5dc9dd01446a045c0ac8c9`; same full tree, `git diff 3b4ab5e HEAD` empty. `source-identity.json` is machine readable. All 47 changed paths are classified in `candidate-path-provenance.json`.

Actual cherry-pick conflicts occurred on #219 initial/dedupe commits: `wordpress/tests/free/in-container.sh` keeps both #209 cache-public and #206 selection-count suites; `class-product-selector.php` unions fresh public-product reconstruction / stable unsupported-class refusal with descendant selection, distinct bounded discovery and duplicate child handling. Final selector union preserves `expanded_unsupported` in the repeated-child guard. Method placement restored to the preserved candidate's bytes. `variations.php` and `class-free-admin.php` merged automatically; their union files were independently compared. The integration-only `WC_Cache_Helper` no-op fixture stub (`7f47309740b4572fdf30dee8f333046b01b5a956`) supplies the public cache-helper contract required by the combined selection fixture; it changes no product runtime. The five #170 evidence files come from authentic historical evidence commit `330859ffb92477afe52266948fdf9ec216c7ce45`.

## Historical #170 records

`historical-170-audit.json` recomputes all three proof UI fingerprints and both indexed PNG hashes/dimensions. Every match is true: Chromium 1440×3905 and Firefox 1440×3906. Both result JSON files are byte-identical to retained runtime-acceptance web170 JSON; each reports 253 successful assertions, 12 axe scans, zero recorded violations and keyboard PASS. Real same-row SQL observers record expected100/current120/planned80 CONFLICT, Undo preserved93/restored20/conflict1, and rejected-request no_mutation=true. Screenshots visually show the matching named fixture actor/product/row, values and preservation guidance. These checks establish consistency; historical JSON has no embedded full source SHA or original exit status, and no per-assertion list. Retained PASS stdout logs show253 each; original failed Chromium log (older-version restoration fixture failure) and subsequent rerun remain in `/tmp/wlruntime/logs/170-chromium.log` and `170-chromium2.log`. README's older247/Firefox155/MariaDB narrative is historical, not the engine/DB identity of the later253 captures.

The captured commit is `330859ffb92477afe52266948fdf9ec216c7ce45`, tree `59a28872f22937d1466482a7d829f52244186d8e`. Latest Round6 `3b4ab5e` differs in `docs/review/209/IMPLEMENTATION.md`, `wordpress/tests/durable/cache-public-integration.php`, and `class-price-cache-verifier.php`. Its three fingerprinted UI files match, but full source is not identical and the changed cache observer participates in Apply/Undo browser safety. Fresh authentic journeys are required to call the latest exact source capture PASS; historical hashes are not rewritten.

## Original exact candidate checks

`asset-audit-preserved.log` and receipt: exit0; #122 packaging assets and #170 source/capture policy PASS. `pr-fast-preserved.log` and receipt: complete local `bash .github/ci/pr-fast.sh` exit0, includes ownership audit, dependency audit, artifact tests, asset audit and negative cases, package preflight, inventory/public audits, readme/shim/claim matrix/source audits and all176 PHP fixture/source lint checks. Local PASS is not hosted PR_FAST.

`individual-source-gates.json` computes proof hashes straight from exact Git blobs. #219 FAIL (`class-free-admin.php`, `free-selection.js`), #220 FAIL (`class-free-admin.php`), #221 FAIL (`class-free-admin.php`), #222 source gate PASS. These are individual #170 source gates only; full hosted status is audited separately.

## Graph/process limits

Root registered graph is not the exact detached reconstruction. `resolve` impact reports MEDIUM, five direct consumers including planner preview and free regression fixtures, Preview process and Free/Undo axes; three unresolved receiver call sites make it a lower bound. Class-only query returned UNKNOWN, confirmed real static usages by source search. Initial temporary replay commits happened before a graph receipt. Subsequent selector union commit was preceded by complete detect_changes(all), mapping two symbols. No PR commit/push was made by this lane. Root audits aligned complete candidate separately; no claim that this stale graph proves no impact.

## Integration proposals

Option1 sequential #220 → #219 → #221 → #222: after founder approval of each step, update the next existing Draft branch with actual new main using non-force ancestry; recapture #170 on that exact branch when source differs; rerun owning real runtime suites; require hosted PR_FAST and CI_COVERAGE on its current SHA with strict up-to-date rules. Current individual failing gates cannot be bypassed by integrated evidence. Founder decides Ready and merge after green required checks.

Option2 proposed single integration PR: exact combined source and reviewed union conflict resolutions above, complete four-head provenance, fresh exact source #170 browser evidence, supported Woo runtime matrix and required hosted PR_FAST/CI_COVERAGE. Publish branch/open PR only after explicit founder authorization. Use Refs #204/#206/#209/#210 and preserve current Draft/issue audit history. Hosted integrated CI remains BLOCKED_PENDING_AUTHORIZATION until a legitimate remote PR/check target exists. No fifth PR created.

Fresh browser execution is pending the reproduced #220 fixture correction and the root's updated exact candidate.

## Updated exact candidate and current checks

Root reproduced a strict fixture assertion comparing WordPress default object-cache `null` with `false`; #220 correction `b834d81ab5fb378360356ba15c54ab632df03515` normalizes the boolean cache flag without changing runtime source. Replayed updated combined source `ad6377eaf6b68548221d3e4fae0b522b0e19f7d3`, tree `9278c28cdb961c0fc404202ec1ea551882b4620a`; only fixture file differs from preserved candidate. Production subtree remains `0a2584cbf3572f509b7ec19a438b7b059b7cb071`. Graph FILE impact UNKNOWN (not indexed), caller confirmed actual durable runner line38; all/staged detect_changes maps1file/0symbols, so not a clean absence-of-impact proof. `source-identity-updated.json` records this. Complete local PR_FAST updated exact tree exit0 (`pr-fast-updated.log`, `.exit-status`).

Fresh browser site `/tmp/wlruntime/sites/round7-browser` uses dedicated MySQL database `round7_browser`, WP7.1.2/Woo11.1.2/PHP8.2.32/MySQL8.0.44/default object cache. All85 source files installed exactly match production source (`installed-source-preserved.json`); the fixture correction does not change those bytes. Original browser launcher failed before assertions because the runner's `/opt` mount hides system Chrome's symlink target; failure log/exit preserved. Authentic host Node harness with native WP-CLI/direct DB and mapped public HTTP runtime resolves this environment limitation. No source/assertion changes.

## Final interrupted snapshot

User requested an immediate report because quota exhausted. No further validation launched. New Chromium journey was interrupted before final result JSON / PASS exit receipt. Genuine partial Chromium screenshots (Preview, Apply conflict and History) remain in `170-chromium/`, with actual HTTP server/runner logs. Firefox was not started. New live Apply/Undo focused workflow was interrupted before receipt; no actual product focus defect is confirmed. Both exact-current-source journeys and new live Undo remain NOT_TESTED / incomplete. The native neutral-browser focus lead (Chrome disables focused button→BODY; Firefox retains BUTTON) is not counted as a product finding. No #221 edits. Task-owned journey/browser/server process trees were terminated; other runtime agents and unrelated labs were untouched. Historical253-result evidence remains unchanged and consistent, but predates changed cache observer, so latest exact tree cannot claim INTEGRATED_SOURCE_CAPTURE_PASS.

Completed latest source checks: complete local PR_FAST exit0; workflow-lint exit0; CI ownership classifies admin/jobs/journal/plan/undo and deferred acceptance. Proposed combined SHA `ad6377eaf6b68548221d3e4fae0b522b0e19f7d3`, tree `9278c28cdb961c0fc404202ec1ea551882b4620a`. Hosted integrated CI remains blocked pending explicit integration-PR authorization. Overall combined browser acceptance HOLD.
