# PR #241 final integration with merged #242

Commit-time status: local integration verified; new exact-head hosted CI pending.
No READY claim until the final PR handoff pins passing CI and clean mergeability.
PR remains Draft, #230 open, Founder GO outstanding. This receipt supersedes the
previous TAKEOVER.md source/CI identity for the combined runtime; old evidence
and captures remain historical and untouched.

## Refreshed audit and branch ownership

- Fetched main: `04e7f3a39b0b903d4f2cbe499ea0f20731295a37` (merged #242).
- Inspected remote/local previous head: `18a4e60dc7e73c4c3bdb31ad241c234ff44a9cda`.
- Existing branch/worktree: `work/230-price-presets`, isolated checkout.
- Branch clean, 0 ahead/0 behind remote at takeover. No concurrent PR pushes.
- Read issue #230, all #241 comments/reviews, latest blocker `6109621046`,
  #242 diff/review/HOLD-fix comments and #232 implementation documentation.
  #242 is merged, #232 closed, #230 open. Previous CI certifies only `18a4e60`.
- Main also contains accepted docs/marketing/readme changes; retained verbatim.
- Strict main checks: PR_FAST and CI_COVERAGE, enforce-admins, no force pushes or
  deletions. Exact-SHA `ci112:<head>` budget label remains required for the eight
  acceptance profiles. Owners: Plan, Journal, Jobs, Undo, Admin, Acceptance.

## Rebase and exact conflicts

All five existing feature/integration commits were replayed onto current main.
No other branch/worktree was edited. Actual conflicts:

| Path | Resolution |
|---|---|
| `wordpress/tests/admin/run.sh` | Invoke both repeat-unit and presets-unit, retaining every original unit runner. |
| `wordpress/writeleash/includes/free/class-free-admin.php` | Keep `repeat` alongside source_job, ranges, ending and all retained inputs. Separate additions merged intact. |
| `wordpress/writeleash/languages/writeleash.pot` | Regenerate from final public allowlist using official checksum-pinned WP-CLI 2.12.0; preserve both features' strings. |
| `docs/review/170/proof.json` | Keep accepted-main hash temporarily; replace with actual combined source only after genuine captures/review. Historical images/observers untouched. |

`class-change-plan.php` did not conflict and is byte-identical to main, including
SOURCE_RECOVERY/SOURCE_REPEAT, with_source_kind/source_kind, hash/hydration
validation, creation-time attachment and legacy recovery compatibility.

## Bounded semantic integration

The mechanical rebase exposed a real collision: the shared Repeat form showed
Save as preset. A new real-Woo regression failed before the fix. Preset controls
now appear only on the ordinary form, with neither Repeat nor Recovery context.
Preset processing also rejects historical workflow markers after capability and
its dedicated nonce gate, so a forged mixed action cannot persist job routing.

Native discovery/count POSTs now retain the active Repeat view and its marker.
The Repeat view independently reauthorizes and hydrates the source before using
retained editable inputs. Unchecked range, subcategory and zero-policy checkboxes
remain unchecked after discovery/validation errors rather than inheriting the
historical default again. Normal Preview, preset Load and separate approval
remain independent actions. No extra engine, storage or history authority.

Presets schema 1/2 and repository/HMAC/CAS are byte-identical to reviewed
`18a4e60`. Repeat source/form/exclusion adapters and the immutable discriminator
are preserved from main. Both use the established selection/operation/policy
builders and fresh planner. Intentional adapter separation remains: persisted
HMAC-verified preset configuration and verified historical Plan material have
different trust boundaries/shapes. Convergence is a follow-up, not a safe reason
to refactor this integration. Repeat neither loads nor creates presets.

Refined exclusions remain outside ordinary presets. Refined Repeat suggests
only included IDs, explains excluded IDs, and does not re-expand the original
category or variable-parent request. Frozen Apply/Journal/Undo stay independent.
Recovery still accepts only currently authorized chosen conflict rows. Rendering
uses frozen source_kind, never mutable source conflicts.

## New combined regression and runner wiring

`wordpress/tests/admin/presets-repeat-integration.php` runs after existing
repeat-integration in every MySQL/MariaDB × default/Redis Admin profile. It tests:

- A: preset Save/Load/edit -> Preview/approval/Apply -> actual History Repeat
  link -> current 105 price/110.25 target, new identity/hash, old approval denied;
  original job/items/Journal **and real historical Undo records** byte-identical.
- B: zero-preset normal Apply -> Repeat/approval/Apply; no preset options created.
- C: range preset -> exact membership -> exclusions -> separate Apply ->
  included-only Repeat; no excluded saves/journal, preset/source bytes unchanged.
- D: actual conflicted source -> Recovery and conflict-only Repeat -> changed
  source conflict state; frozen classifications/copy stay correct; kind tamper
  and malformed kind fail hydration. Existing #232 tests retain legacy coverage.
- E: cross-actor preset/history refusal, revoked capabilities, full-source rights
  before range, nonce separation, mixed-action refusal, preset HMAC and historical
  hash tampering, no unauthorized configuration disclosure.
- F: Repeat 1001 refusal with presets present; parent-preset expansion followed
  by exact included-variation Repeat. Existing suites retain actual 1000/1001
  catalogs, 20-slot capacity/race/CAS/reused-slot, missing and category-drift cases.

`presets-browser.cjs` retains every previous #230 assertion and adds keyboard
Preset -> Apply -> History Repeat, independent/non-nested forms, source marker
counts, no preset nonce on Repeat, no-JS count/error/unchecked-range retention,
new source_kind/hash/approval, original job preservation and zero extra presets.
These deliberately mutating checks run **after** the original read-only cases
on distinct fixture IDs. Existing #170/#205/#231 assertions are not changed.

## Actual local verification (exit 0 for final successful commands)

Environment: WP 7.1.2 / Woo 11.1.2 / PHP 8.2.32; real userland MariaDB 10.11.15,
default cache and Redis 7.4.2. Separate owned disposable prefixes/sites. Docker
daemon unavailable; no local Docker result claimed. Hosted matrices remain
required. Commands run from the feature worktree:

| Command / harness | Actual result |
|---|---|
| `wp --path=<owned site> eval-file wordpress/tests/admin/presets-repeat-integration.php` | PASS, 78 assertions on default and Redis |
| `wp ... eval-file wordpress/tests/admin/repeat-integration.php` | PASS, 121 assertions, default and Redis |
| `wp ... eval-file wordpress/tests/admin/integration.php` | PASS, 1703 assertions/default; background receipt `Admin exit=0` |
| `wp ... eval-file wordpress/tests/admin/recovery-integration.php` | PASS, 65 assertions/default |
| `wp ... eval-file wordpress/tests/admin/progress-integration.php` | PASS, real persisted Apply/Undo/actor/nonce/read-only checks/default |
| `php wordpress/tests/admin/presets-unit.php` | PASS, 3251 cumulative assertions |
| `php wordpress/tests/admin/repeat-unit.php` | PASS, 2670 cumulative, including discriminator/legacy/tamper |
| `php wordpress/tests/admin/recovery-unit.php` | PASS, 2618 cumulative |
| Range/exclusions/category/variation/CSV/localized compatibility domain harnesses | PASS; supplementary doubles, not real-Woo substitutes |
| Admin boundary + shared Jobs/Undo no-replan audits | PASS |
| `node ... wordpress/tests/admin/presets-browser.cjs` (real site, pinned Playwright/axe) | Chromium 148.0.7778.178 + Firefox 151.0: 115 checks each, enhanced/no-JS, three clean axe scans |
| `node ... wordpress/tests/admin/regression-browser.cjs` (unchanged) | Both engines: 264 assertions + 13 clean axe scans each; actual 120 Apply/93 Undo conflicts preserved |
| `WP_CLI_PHAR=/tmp/opencode/wp-cli.phar bash wordpress/release/make-pot.sh` | Official pinned extraction PASS |
| `php wordpress/tests/admin/i18n-catalog.php` | PASS, 607 entries |
| `bash .github/ci/workflow-lint.sh` | PASS, pinned actionlint |
| Public staging/closure/manifests | PASS, both feature dependencies retained; no development files shipped |
| `bash .github/ci/pr-fast.sh` | PASS: PHP lint, both manifests/inventory/closure, #170 asset audit and all 26 adversarial asset cases |

The final PR receipt records exact-head hosted outcomes. No gate/assertion is
weakened. Source_kind and both existing mapper suites remain unchanged from main
and the reviewed preset implementation respectively.

Preparation failures were not counted as passes: the discriminating Repeat-Save
failure; fixture missing global DB/counter and 105 versus 105.00 representation;
and a browser fixture-order collision caused by applying before its previous
no-JS read-only check. Fixtures were corrected, all assertions retained, and final
runs completed. No screen-reader, Safari or new actual-zoom pass claimed.

## Genuine source/capture receipt

New combined form/Preview/error/no-JS and original #170 conflict captures live in
`integration-242-captures/`; inspected agent-operated real renders and focus,
labels, disabled range, absence of preset Save on Repeat, explicit provenance
and independent approval. Historical #170 and previous #230 capture bytes remain
unchanged. Current Admin source and staged browser source hash match exactly:

- Admin: `3692444f65c3802c58d4e2132eff23d9c9af8ecd33438c3c40db95c0017e1dc7`
- Change Plan (unchanged main): `567fc78f9f9ecfcb700140010726993052f131d538dfba5bebbe7ce96be9e442`
- Presets (unchanged reviewed adapter/repository): `55d880452c753a14a87ee89590b28767ca3061f14a54b7b09d965c0373cd3b21`

Accepted JS/CSS hashes remain unchanged. The proof refresh follows the existing
#170 README procedure after genuine current-tree captures, not a hash-only
workaround. Exact-head CI, latest-main ancestry, clean mergeability, Draft and
#230-open checks are completion gates. No merge/release/publication/closure.
