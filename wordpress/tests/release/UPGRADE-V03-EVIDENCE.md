# Upgrade evidence: WriteLeash 0.2.2 → v0.3.0 candidate (#283)

This fixture proves that an installed 0.2.2 site upgrades to the v0.3.0 shape
without losing durable jobs, journal evidence, grants, presets, or Undo
eligibility, and that lazy migrations fail closed when their stored schema
marker is corrupt. It is repository-only: no production code, version bump, or
release metadata is changed here.

## Artifacts

| Role | Artifact | Version | SHA-256 |
| --- | --- | --- | --- |
| Source | `https://downloads.wordpress.org/plugin/writeleash.0.2.2.zip` | 0.2.2 | `09ff494b80646a5ec854bfb52f16dd3ad1a68d27da88a9e8564963b74b9f8b90` |
| Target | local v0.3.0-shaped candidate (not committed) | 0.3.0 | `9be36d7bba1096b3553af57ae2ecceb5cc712817c488b2a3b894c55ba03633dc` |

The target ZIP was built from `wordpress/writeleash` at this branch with only
the plugin header, `WRITELEASH_VERSION`, and readme Stable tag rewritten to
`0.3.0`. It is a validation candidate, not a release artifact. Final exact-SHA
evidence must be produced in #313 against the actual release ZIP.

## Environment

Local disposable stack (this run):

- WordPress 7.1.2
- WooCommerce 11.1.2
- PHP 8.2.32
- MariaDB 11.4.13 (local tarball; not the repo-pinned CI engine)
- WP-CLI 2.12.0
- No Docker daemon and no sudo were available, so the pinned compose stack
  could not be used.

CI should re-run the same driver with the pinned WordPress/MySQL or MariaDB
image from `wordpress/tests/current-core`.

## Reproduction

```sh
export WL283_SITE=/path/to/disposable/wordpress
export WL283_SOURCE_ZIP=/path/to/writeleash.0.2.2.zip
export WL283_SOURCE_SHA256=09ff494b80646a5ec854bfb52f16dd3ad1a68d27da88a9e8564963b74b9f8b90
export WL283_TARGET_ZIP=/path/to/writeleash.0.3.0-candidate.zip
export WL283_UPGRADE_RECEIPT=/path/outside/webroot/upgrade-v03.receipt.json

# 1. Install WriteLeash 0.2.2 (WL283_INSTALL_SOURCE=1) and create the fixture.
WL283_INSTALL_SOURCE=1 bash wordpress/tests/release/upgrade-v03.sh seed

# 2. Replace it with the target ZIP, activate, and verify.
bash wordpress/tests/release/upgrade-v03.sh verify

# 3a. Negative control: unknown stored grant version.
WL283_NEGATIVE_KIND=unknown-grant-schema \
  bash wordpress/tests/release/upgrade-v03.sh negative

# 3b. Negative control: non-numeric stored grant version.
WL283_NEGATIVE_KIND=malformed-grant-option \
  bash wordpress/tests/release/upgrade-v03.sh negative
```

`seed` and `verify`/`negative` must use the same receipt path. The receipt is
written with mode `0600`; it contains product IDs, user IDs, plan hashes, and
public grant/preset identifiers, so keep it outside the webroot and out of git.

The driver installs the sandbox with WP-CLI:

```sh
wp --path="$WL283_SITE" plugin deactivate writeleash
wp --path="$WL283_SITE" plugin install "$WL283_TARGET_ZIP" --force --activate
```

Before calling the harness for `verify`/`negative`, it records the schema
options immediately after activation and passes them as
`WL283_POST_ACTIVATION_STATE`. The harness cross-checks that value against its
own first read, so an activation-time migration cannot be mistaken for a lazy
one.

## Results

### Source and seed (0.2.2)

| ID | Result | Observed |
| --- | --- | --- |
| `src-01` | PASS | source SHA-256 matches the published artifact |
| `src-02` | PASS | header and constant are `0.2.2` |
| `src-03` | PASS | installed runtime is `0.2.2` |
| `src-04` | PASS | after supported seeding: grant `1`, journal `3`, job `2` |
| `src-05` | PASS | `restriction_removed_at` and `asset` are absent |
| `src-06` | BLOCKED | 0.2.2 predates #307 and has no `is_restricted_actor()` |
| `src-07` | NOT_TESTED | browser-rendered admin History is not driven |
| `seed-01` | PASS | completed job: 2/2 applied through `Job_Worker` |
| `seed-02` | PASS | resumable job: 1 applied, 2 pending |
| `seed-03` | PASS | resume-conflict job: 1 applied, 2 pending, newer price 120 |
| `seed-04` | PASS | Undo initiated for the completed job: 2 eligible |
| `seed-05` | PASS | grant accepted: 2 reservations, 1 idempotency row, 2 audit rows |
| `seed-06` | PASS | preset saved through `Price_Preset_Repository` |

Source tables are created lazily, so `src-04` and `src-05` run after the seed
workflows materialize them.

### Target upgrade (v0.3.0 candidate)

| ID | Result | Observed |
| --- | --- | --- |
| `src-11` | PASS | source ZIP still matches the seeded artifact |
| `tgt-01` | PASS | target SHA-256 recorded and matched |
| `tgt-02` | PASS | header and runtime constant are `0.3.0` |
| `env-01` | PASS | WordPress 7.1.2, WooCommerce 11.1.2, PHP 8.2.32 |
| `mig-01` | PASS | activation left grant `1`, journal `3`, job `2` |
| `mig-02` | PASS | idempotent job replay migrates journal `3 → 4` |
| `mig-03` | PASS | idempotent acceptance replay migrates grant `1 → 2` (`replayed=true`) |
| `mig-04` | PASS | neither migration wrote a product or stock value |
| `jrn-01` | PASS | all 10 pre-v4 rows kept; only `asset` added |
| `jrn-02` | PASS | every pre-v4 row has `asset='price'` |
| `jrn-03` | PASS | all 4 pre-upgrade APPLIED rows intact |
| `grt-01` | PASS | marker column added; old grant row not rewritten |
| `grt-02` | PASS | actor 2 still restricted; no removal marker |
| `grt-03` | PASS | reservations, idempotency, audit rows byte-identical |
| `job-01` | PASS | durable job/item columns unchanged; 4 plan hashes match |
| `res-01` | PASS | resume completes the frozen plan at 110 |
| `res-02` | PASS | conflict item becomes `CONFLICT`, price stays 120 |
| `res-03` | PASS | pre-upgrade APPLIED evidence not lost or promoted |
| `undo-01` | PASS | 2 eligible, 0 undone before the Undo worker |
| `undo-02` | PASS | Undo restores both completed products to 100 |
| `undo-03` | PASS | conflict item never eligible; other 2 undone; 120 preserved |
| `pre-01` | PASS | preset options unchanged; preset still loads |
| `pre-02` | PASS | History renders 2 job/Undo items from the durable repositories |
| `wri-01` | PASS | control/grant/stock unchanged; only expected Resume/Undo prices changed |

Migration details observed:

- Journal option moved `3 → 4`. Pre-existing v3 rows stay `schema_version=3`
  and receive `asset='price'`; only v2 rows are compacted by `install()`.
- Grant option moved `1 → 2`. The existing grant row stays
  `schema_version=1`, `restriction_removed_at` is `NULL`, and actor 2 remains
  restricted.
- Activation itself did not migrate either schema.

### Negative controls

Both were run on a freshly seeded 0.2.2 site, then the target was installed and
activated.

| Kind | Result | Observed |
| --- | --- | --- |
| `unknown-grant-schema` (`99`) | PASS | `neg-01`..`neg-04`: typed `SCHEMA_UNAVAILABLE`, zero table/product writes, recovery on restore |
| `malformed-grant-option` (`not-a-number`) | PASS | same behavior |

The refused migration leaves grants, reservations, idempotency, audit, jobs,
job items, journal rows, and products byte-identical. Restoring the option to
`1` lets the same idempotent acceptance replay migrate to v2 and keep the actor
restricted.

## Limitations

- The target is a 0.3.0-shaped candidate. Final released-artifact evidence is
  owned by #313 and must repeat this run on the release SHA.
- Browser rendering of admin History is not driven; only the server-side
  renderer is asserted (`src-07` is `NOT_TESTED`).
- The pre-upgrade restriction predicate cannot be asserted because 0.2.2 has no
  `is_restricted_actor()` (`src-06` is `BLOCKED`). The post-upgrade predicate is
  asserted.
- The local database engine is MariaDB 11.4.13, not the repo-pinned CI engine.
  The fixture is engine-independent, but CI should run the pinned stack.
