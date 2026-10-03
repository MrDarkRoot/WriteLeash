# Independent Woo plugin targets (#143 / #144)

The flagship remains at `wordpress/writeleash/`. Its runtime, 37-file public
manifest, directory graphics, version, public claims and frozen #123/#124
artifact are unchanged. Shared **build/test tooling** is allowed; no shared PHP
runtime, SDK, service container or sibling dependency was introduced.

## Target authority

`wordpress/release/plugin-targets.json` binds an explicit target to its root,
main file, version, text domain, readme, distribution manifest, assets, release
and tests paths. Duplicate JSON keys, unknown targets, overlapping/misdirected
paths and internal identity drift fail validation. Internal ownership is
specified separately from plugin display names. A later branding decision must
not migrate options/tables or change scheduler/REST authority implicitly.

| Target | Runtime namespace | Table/option prefix | Scheduler group | REST reservation |
| --- | --- | --- | --- | --- |
| price-history | `WriteLeash\PriceHistory` | `writeleash_price_history_` | `writeleash-price-history` | `writeleash-price-history/v1` |
| price-campaigns | `WriteLeash\PriceCampaigns` | `writeleash_price_campaigns_` | `writeleash-price-campaigns` | `writeleash-price-campaigns/v1` |

The two independent `0.0.0` scaffolds require WooCommerce only. Activation
stores one exact, non-autoloaded version option; network activation is refused.
Deactivation retains metadata. Uninstall deletes only that option, even when
the plugin was never activated. There are **no satellite tables, scheduled
actions, cron jobs, REST routes, nonce handlers, menu registrations or product
features**. Their reserved names are checked without adding fake runtime work.
Future schemas/workers/routes belong to later issues and need their own gates.

## Local-only artifacts

Extend the existing builder, not a separate release system:

```sh
python3 wordpress/release/build-wordpress-org.py \
  --target price-history --source /absolute/clean/checkout \
  --sha FULL_40_CHARACTER_SHA --output /outside/checkout/candidate
```

The same command accepts `writeleash` and `price-campaigns`. Explicit targets
use the clean checkout's exact SHA. Omitting `--target` deliberately retains
the original frozen reviewed-source behavior and its byte-identical flagship
artifact tests. This compatibility path is not a way to select a satellite.
Neither path publishes or grants release readiness.

Every build reads committed regular files from a finite sorted manifest. It
refuses dirty/untracked/ignored inputs, unknown production PHP, duplicate
ownership, sibling/variable includes, namespace drift, copied foreign runtime,
extra candidate files and directory graphics in runtime manifests. The
flagship's finite, already classified historical source remains excluded by
its existing #120 public closure. Satellites have no implicit historical-file
exemption. `assets-files.txt` is intentionally empty for each satellite; its
directory README stays outside runtime ZIP and SVN-style asset staging.

Output uses the existing deterministic ZIP_STORED format, fixed timestamps,
permissions and sorted paths. `evidence.json` records target, source SHA,
plugin root/main file, manifest location/hash, per-file size/SHA256, ZIP
size/SHA256, runtime tree hashes and the separate target asset inventory.
`svn/` is **local staging only**. The builder never contacts WordPress.org/SVN.

## #133 ownership extension

The existing `path-ownership.json`, classifier, PR_FAST, CI_COVERAGE and
RELEASE_FULL remain authoritative. Six new PHP paths are finite production
owners. Unknown production PHP outside tests/release fails even outside known
plugin roots (including PHP disguised as an asset).

| Change | Static targets | Integration owners |
| --- | --- | --- |
| History runtime/test/manifest | price-history | price-history |
| Campaigns runtime/test/manifest | price-campaigns | price-campaigns |
| Satellite readme/docs/directory graphics | that satellite | none |
| Shared artifact/owner/orchestration/portfolio harness | all three | price-history, price-campaigns, historical |
| Existing flagship code | existing flagship gate | existing #133 downstream owners |

Static policy/dependency/lint audits stay cheap and global. Target package
checks are selected by the exact diff; the new owner outputs are consumed by
CI_COVERAGE, including failure/cancellation/skip/missing-output cases. Fork and
SHA-bound #112 budget rules are preserved. Satellite-only changes do not
select PostgreSQL, Redirection, 10k acceptance or the historical matrix.
Shared artifact logic deliberately selects the flagship historical release
owner; assertions in that suite were not weakened.

RELEASE_FULL adds a target selector, defaulting to `writeleash`. The default
retains all 13 original evidence suites and the explicit research-toolchain
acknowledgment. Each satellite selects its own integration suite; it does not
run historical PostgreSQL/Redirection evidence. The original exact SHA equals
dispatch-ref guard remains before expensive jobs. No dispatch is automatic.

## Evidence commands and their limits

```sh
python3 .github/ci/ownership.py --audit
python3 wordpress/tests/portfolio/test-isolation.py --target price-history
python3 wordpress/tests/portfolio/test-isolation.py --target price-campaigns
python3 wordpress/tests/portfolio/test-isolation.py --target writeleash
bash .github/ci/pr-fast.sh
bash .github/ci/workflow-lint.sh
bash wordpress/tests/price-history/run.sh
bash wordpress/tests/price-campaigns/run.sh
```

Network-free tests exercise wrong-plugin injection in both directions,
flagship internal-code injection, ambiguous targets/registries, global symbols,
sibling/dynamic imports, reserved table/option/action/group/REST collisions,
foreign uninstall, broad cleanup, exact independent ZIP/evidence builds,
unknown production PHP and owner fan-out. The PHP bootstrap/lifecycle test uses
test doubles and labels itself accordingly; it does **not** prove real WP/Woo
co-installation.

The reusable satellite suites run the same pinned fixture architecture as
#111: WP 7.1.2/PHP 8.2, Woo 11.1.2 ZIP with the existing checksum, MySQL 8.0.44
and MariaDB 10.11.15 with the existing image digests. They activate each
satellite with Woo alone, then all three together, test every deactivation and
each plugin's uninstall first, and verify sibling versions, options, tables
and scheduler actions survive. They verify flagship REST registration and
reserved satellite namespaces without registering artificial satellite routes.
Tables/actions with `isolation_fixture` markers are adversarial **fixture
sentinels**, not satellite product behavior. Docker teardown is asserted.

Docker execution is required to claim that real fixture PASS. Static green
checks, synthetic lifecycle tests and deterministic ZIPs cannot substitute for
it. Public support claims and publication decisions remain separate.

## Adding a future plugin (explicit review required)

1. Add an independent sibling root; never relocate the flagship or import
   sibling PHP. Choose distinct internal namespace/data/action/REST ownership.
2. Extend the finite target registry and its canonical validation together.
   Reserve separate release/tests/assets paths. Do not derive internal state
   ownership from display-name changes.
3. Add only that plugin's sorted distribution/asset manifests. Classify every
   production PHP path in #133. Never add broad recursive runtime copying or
   an unknown-PHP exemption.
4. Add its reusable owner to PR_FAST outputs/calls, CI_COVERAGE needs and
   intentional target release selection. Update shared-tool fan-out and the
   static target selector, then prove missing/skipped owners cannot pass.
5. Extend adversarial injection, identity, ZIP and lifecycle tests and the
   supported co-install fixture. New table/REST/scheduler/nonce/menu behavior
   must replace scaffold-only constraints with specific reviewed ownership
   gates; do not merely relax the call allowlist.
6. Run existing flagship artifact/public-closure/ownership regressions and
   report expensive evidence as NOT RUN when unavailable. No self-certification,
   release, publication or shared runtime extraction follows from this tooling.
