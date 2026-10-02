# #121 Phase B: public minimum and historical regression separation

POST-#133 BASE MAIN: `4bc45a9345a814e75a65f6d6293756f447603e91`

PRE-PHASE-B HEAD: `56e90cc9c12057c81d1f9312aa51bec11039415f`

REBASING RESULT: `14eaa1f580848964134b4f6b0f415ee791a20c36`

## Three distinct fixture contracts

* **Public WP 6.8.3 negative**: foundation stages the exact 37-file allowlist
  before any shim/overlay. `CC63_PHASE=minimum` calls normal Core
  `activate_plugin()` with the public `Requires at least: 7.0` header. The
  required result is `plugin_wp_incompatible` naming 7.0, an inactive plugin,
  no loaded WriteLeash version constant, and unchanged product/postmeta,
  WriteLeash options and durable-table snapshot. The Woo dependency message
  is deliberately not expected: Core checks minimum compatibility first.
* **Public supported dependency proof**: the existing exact WP 7.1.2
  current-core leg stages only public files first. Woo missing must produce
  a Core refusal naming WooCommerce; Woo 11.1.2 active must satisfy Core's
  slug requirement and permit public activation. Active-dependents, dependency
  loss fail-closed and released-dependency phases run before the legacy overlay.
  WP 7.1.2 is an already-exercised point; no new support range is implied.
* **HISTORICAL TEST ONLY WP 6.8.3**: after public refusal proof in foundation,
  and before historical activation in adapter, `historical-minimum-121.php`
  changes only the copied entrypoint/readme metadata from 7.0 to 6.8.3 and adds an
  explicit HISTORICAL TEST ONLY comment. It requires an independent identical
  public copy in a real `/tmp/` WP 6.8.3 site, rejects links/production aliases,
  and verifies both production source hashes are unchanged. Staged header/readme
  requirements remain coherent for official Plugin Check. Then `legacy/stage.php` overlays
  the explicit repository-only files and external CLI bootstrap. Woo 9.9.7
  remains only the historical activation dependency; Redirection stays 5.5.2.
  Disposable container/site destruction removes the shim and overlay.

The production entrypoint stays at WP 7.0. Neither shim nor legacy bootstrap
is in `writeleash-distribution-files.txt`; allowlist-only future packaging
cannot include them. The shim adds no executable authority and normal public
staging never invokes it. Historical identity checks explicitly distinguish
the shim from public metadata; public readme/header audits run before shimming.

## Claim boundary

The stored regular price and execution preconditions govern Apply conflicts:
product existence, core-simple/type state, publication status, sale configuration,
currency/base context, price decimals, WordPress and WooCommerce versions.
SKU, name/title and category membership are provenance-only. The shared
`release/conflict-copy.php` guard requires the exact bounded claim and caption
in readme/matrix and rejects broad edit-to-conflict language. Adversarial
regressions run in the claim-matrix audit, including broad claims appended to
otherwise valid listing text.

## Cost-bounded CI and invariant preservation

The #133 classifier selects **foundation, adapter, historical**. The new
historical metadata helper has those explicit owners; current-core is historical.
Listing copy and static audit files remain PR_FAST. No acceptance-owned file,
broad workflow trigger, RELEASE_FULL dispatch, PG/native or scale suite changes.

The #61 concurrency section, trusted two-stage barrier, two restricted sessions,
real concurrent UPDATE overlap, durable accounting and no-stale-success
assertions are unchanged. Retain failed run attempts; any flake rerun must use
the exact unchanged head and unchanged assertions.

## Local validation and official readme validator

2026-10-02: PR_FAST (including metadata-shim adversarial tests, package preflight,
inventory/public-runtime/source audits, readme/claim matrix audits and PHP lint),
workflow lint and `ownership.py --audit` passed locally. Integration launch
`bash wordpress/tests/run.sh mysql` was unavailable because the Docker daemon
was stopped; passwordless sudo could not start it. A rootless fallback also
could not start because `newuidmap` was absent. Hosted selected-owner evidence
is required before treating the repaired PR as green.

Official https://wordpress.org/plugins/developers/readme-validator/ was rerun
on the final public text on 2026-10-02: **0 errors, 0 warnings**; three notes:
Contributors field missing, no Upgrade Notice section, no donate link. The
request used the form's base64-encoded UTF-8 `readme_contents` submission.

## Retained first hosted attempt and narrow correction

Run https://github.com/MrDarkRoot/WriteLeash/actions/runs/37023355769,
attempt 1, head `0943e8dc816a08cc1618761bf24be22d98792cc1`:

* PR_FAST and both foundation engines passed, including public WP 6.8.3 refusal.
* Adapter failed the unchanged #61 `await_overlap(update)` assertion after the
  row-1 barrier proof (two restricted sessions 226,221). The failure transcript
  is retained as `wordpress-redirection-adapter-attempt-1`; no overlap timeout,
  assertion, barrier, durable accounting or concurrency code was changed.
* Historical failed official Plugin Check's `readme_mismatched_header_requires`
  because the disposable header had 6.8.3 while its readme still had 7.0. The
  corrective shim now changes both staged metadata copies only, with additional
  source-readme hash and symlink/hardlink boundary regressions. This is not an
  exception/ignore rule for Plugin Check and public metadata remains 7.0.
* GitHub refused to start CI_COVERAGE because of account billing/spending limits.
  The user confirmed the budget was cleared and authorized one corrected-head
  selected-owner run. No RELEASE_FULL or acceptance execution is requested.
