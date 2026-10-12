# #240 selected-source re-freeze — active acceptance snapshot

**Technical verdict: READY_FOR_FOUNDER_RELEASE_GO**, subject to the final
evidence-HEAD CI/review/merge gate recorded in PR #254 and the #240 handoff.
This is artifact preparation, not WordPress.org publication authorization.

| Active release identity | Independently observed value |
|---|---|
| Founder-selected source | `8748d831f8a3a0931b320e4bc67abf85ece964b0` |
| Commit relationship | PR #255 merge; `origin/main` at verification; tree `2f5bf74f15d1a94c627bb17ca7ddf9c3dfe04592`, identical to reviewed `dafd537…` |
| ZIP A / ZIP B SHA-256 | `a77704b42c9bbb2350f32b0de23a3c46f3a0fe76c617561b91133651ec9d8bb7` / same |
| ZIP observed size / members / root | **2,152,069 bytes / 46 / one `writeleash/`** |
| Plugin Version / Stable Tag | `0.2.0` / `0.2.0` |
| Public manifest SHA-256 | `5155ca3121772cac6397dcd4f8b90b5cb726beb14f29b0cde74ae89ab321bca3` |
| Runtime / local SVN trunk / local tag tree | `ea4486c1a0b813c5773a54f4996f0ebd2cc68eb149f3157b7d648614924cdcb4` |
| Builder SHA-256 | `775d98483cf0fd65cab855c7246d3f1b8734492adbd910d9a0c07dcac2ed48cb` |
| Plugin Check 2.1.0 | **0 ERROR / 15 WARNING**, full installed-ZIP scan |
| Separate SVN assets | **11**, exact filenames/hash inventory and MIME properties verified |

The corrected fixture commit is **`4e61afb279e85e8b9ebca3f47b1f25d1e481d325`**
on the existing `release/240-final-freeze-evidence` branch / PR #254. Its later
evidence HEAD and eventual merge commit are **not** the selected release source.
The release ZIP was built twice from separate clean worktrees at **8748d83**,
never from this evidence branch. Tests/negative controls cannot enter its public
manifest. No production, builder, metadata, manifest or asset delta is introduced
by this fixture/evidence update relative to the selected source.

## Fixture hardening and negative controls

`journal-ddl-integration.php` now:

- Checks the initial RENAME before entering destructive cleanup; checks every
  setup/cleanup CREATE/ALTER/RENAME/DROP and every fixture insert.
- Reads `SHOW FULL COLUMNS` and live table metadata, verifies actual presence,
  absence, types, nullability, UTF-8 collation, defaults, Extra and InnoDB storage.
- Requires `longtext` legacy JSON, `char(64)` nonnullable fingerprint and
  `varchar(16)` nonnullable price field where each is expected.
- Prints the actual metadata for masks 0–7 and asserts **eight distinct**
  observed layouts before production migration. Empty or unavailable metadata
  cannot pass.
- Preserves the original approval/material checks, real later-DDL failure,
  partial state, retry, malformed-storage rejection and ready-layout checks.

**216 positive assertions/profile: FRESH_PASS** on MySQL/MariaDB × default/Redis.
All **20 negative controls** deliberately fail the fixture with actual exit **1**
and require the exact targeted diagnostic plus equal pre/post original-journal
hashes and no backup leak. A generic crash is not accepted.

| Injected control | Required observed failure |
|---|---|
| Replace partial-layout setup DDL with SELECT 1 | `DDL_LAYOUT plan_json.Null mask=1`; actual YES vs expected NO |
| Omit required price_field after fixture insertion | `DDL_LAYOUT price_field presence mask=0` |
| Unexpected JSON NOT NULL for mask 0 | `DDL_LAYOUT plan_json.Null mask=0` |
| Actual failed initial RENAME | `DDL_FIXTURE_SETUP_SQL_FAILED: RENAME TABLE`; original journal retained, no destructive cleanup entered |
| Wrong fingerprint varchar(64) type | `DDL_LAYOUT plan_fingerprint.Type mask=0`; expected char(64) |

The reviewer’s former SELECT-1 vacuous PASS now fails at the first incorrect
layout; it is intentionally prevented from continuing through all twelve no-ops.
The CI journal gate executes positive and all five negative controls on every
engine/cache profile. Runtime migration logic is unchanged.

One early control calibration fired during wpdb’s insert charset introspection,
before the intended layout check. The verifier rejected its generic exit 1 as
**FAIL**, not a successful negative control. The corrected driver arms metadata
sabotage only after the checked fixture INSERT. Original failed logs/exit codes
remain retained; subsequent targeted controls pass all profiles.

## Fresh selected-ZIP acceptance

Every command record is classified in `RUNS.jsonl`, including its command,
timestamps, expected/actual exit, environment, fixture hash, raw stdout/stderr
hashes and original output. Every installed acceptance command verifies its own
site’s actual packaged hashes before and after execution. No shared engine-only
archive hint or source-staged plugin is used.

| Required coverage | Result / boundary |
|---|---|
| Fresh exact ZIP install, missing Woo refusal, Core activation | FRESH_PASS on both engines; missing-Woo refusal/inactive state observed before compatible Woo activation |
| Preview → Approve → Apply → History → actual eligible Undo | FRESH_PASS on all four current DB/cache profiles and all four PHP profiles |
| Presets/Repeat, canonical integrity, creator ownership, capability, nonce and no-write controls | FRESH_PASS via focused freeze and combined integration fixtures; independent-process final-slot/CAS/cache fixture also FRESH_PASS ×4 |
| Progress / conflict Recovery | FRESH_PASS ×4 |
| Corrected partial-layout migration / failure / retry | FRESH_PASS ×4; all observed schemas and twenty expected-failure controls retained |
| Historical v0.1 exact artifact → new candidate | FRESH_PASS ×4; old public IDs/plans/approvals/journals, actual partial Resume drift, no percentage replay, eligible Undo and later-edit conflicts |
| Full journal durability matrix | FRESH_PASS ×4; 394 assertions/profile, v1→v3, actual APPLIED/PENDING evidence, real process kills, ambiguous commit, rollback/cache/connection-loss controls |
| Full job matrix | FRESH_PASS ×4; 932 default / 933 Redis assertions, preserved approvals/partial jobs/journals/history/pending Undo, v2 compaction rollback/retry/orphans, fences and actual interrupted Resume |
| Real focused fault probes | FRESH_PASS ×4: actual SIGKILL before commit, after commit/before response, connection KILL; independent observation, natural lease expiry, safe recovery and actual Undo |
| PHP minimum/support profiles | FRESH_PASS: 7.4.33 / 8.0.30 / 8.1.34 on WP 7.0.1 / Woo 10.0.0; 8.2.34 on WP 7.1.2 / Woo 11.1.2; MariaDB/default; fresh installs/missing Woo, corrected fixture, freeze and Presets/Repeat |
| Official readme validator | FRESH_PASS on exact ZIP readme; byte-identical server echo; no errors/warnings, only no Upgrade Notice/donate-link informational notes |
| Packaging corruption controls / B1–B3 / historical artifact controls | FRESH_PASS: 11 corruption controls, 5 B1–B3 regressions with positive controls, 10 historical artifact controls |
| Local PR_FAST | FRESH_PASS; final exact evidence-HEAD CI is separately linked from #254 / #240 |

Current profiles use WP **7.1.2**, Woo **11.1.2**, host PHP **8.2**, MySQL
**8.0.44** and MariaDB **10.11.15**; persistent cache uses Redis **7.4.2**,
Object Cache **2.7.0** and Predis. PHP packages were extracted into isolated
prefixes; host PHP configuration was not changed. Historical seed records are
explicitly bound to the old **v0.1 baseline** checksum, not mislabeled as tests
of the new v0.2 archive. This is not a public-directory update claim.

## Plugin Check and warning dispositions

Official scanner ZIP SHA-256:
`6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4`.
`PLUGIN-CHECK.json` retains the complete unmodified stdout/stderr, including
trailing blank lines, full flags, hashes and all fifteen source statements.

- Journal 121:25: DirectQuery and NoCaching — live table-existence authority.
- Journal 123:24: DirectQuery and NoCaching — actual column/type/nullability
  authority cannot be replaced with a stale schema/option cache.
- Journal 130:74 / 130:104: DirectQuery, NoCaching, SchemaChange — conditional
  legacy JSON NULL transition with validated/prepared table identity.
- Journal 131:75 / 131:105: same three codes — conditional fingerprint ADD.
- Journal 132:70 / 132:100: same three codes — conditional price-field ADD.
- Presets 133:25: DirectQuery — atomic INSERT IGNORE reserves without replacing
  another owner; Core add_option’s upsert is not equivalent.
- Presets 149:20: DirectQuery — BINARY old-value CAS prevents stale rename/delete
  against changed/reused slots; Core option APIs lack that same predicate.

All codes are `WordPress.DB.DirectDatabaseQuery.{DirectQuery,NoCaching,SchemaChange}`.
No suppression/exclusion was added. Warnings are justified owned-storage,
freshness and concurrency diagnostics, not permission to relax data safety.
The preserved previous scan remains **1 ERROR / 9 WARNING** for its old archive.
Neither classification guarantees directory acceptance.

## Local SVN parity and public readiness

New anonymous read-only public info/inventory/checkout observed empty public
trunk/tags/assets. New local staging, not an old tree relabeled, has 46 members
directly under trunk; local `tags/0.2.0` matches every member and the exact ZIP.
No nested trunk/writeleash, ZIP upload, unrelated file or historical tag mutation.
Exactly eleven assets match the selected source, with `image/png` and
`image/svg+xml` MIME properties and no keyword/eol/externals transformations.
Status, full diff, summary, properties and per-file inventories are recorded.

**Slug-specific publisher permission: NOT_CONFIRMED. Credentials logged/used:
NONE. Remote SVN writes: NONE. Release confirmation/publication: NOT_PERFORMED.**
Fresh public API/download/listing observations remain read-only; no public
distributed v0.1 baseline or public v0.2 release is inferred.

## Historical evidence reconciliation

Original `docs/review/240/final/` receipts/logs, `PLUGIN-CHECK-RAW.json` and
remediation receipts remain byte-for-byte preserved. `HISTORICAL-REPORT.md` is
the exact old #254 final report blob. The main final report now has an active
summary and explicitly labels its entire former body historical.

`CLASSIFICATIONS.json` distinguishes:

- **FRESH_PASS:** new executable selected-source/package acceptance and expected
  negative-control failures (actual exit 1 retained).
- **RETAINED_HISTORICAL_EVIDENCE:** original 04dbbcd/509… whole-artifact acceptance,
  hashes/SVN staging/scan/fault/upgrade/PHP/browser/lifecycle/translation/scale
  receipts; previous #255/independent-review reruns remain earlier receipts,
  even where artifact bytes match. No historical row is rewritten as fresh.
- **SKIPPED:** CI families not selected by path ownership, never PASS.
- **NOT_TESTED:** unlisted PHP/WP/Woo cross-products, multisite/shared hosts,
  manual screen-reader certification and authorized remote publication/update
  verification. Existing bounded support claims are not expanded.
- **FAIL:** the explicitly rejected control calibration; no unresolved final
  runtime, artifact, migration or scanner ERROR remains.

The old 30 unavailable command invocations and reconstructed-versus-original
provenance distinction remain gaps; no command was invented. Unchanged source
builder/manifest, v0.1 baseline, tool/package provenance and eleven assets remain
component facts, not blanket new-runtime acceptance. Only the journal member
differs from the previous 46-member archive; the other 45 members are identical.

## Independent final review and merge gate

The corrected fixture is separately checked against the former no-op
counterexample and all targeted sabotage shapes, with captured actual metadata,
checked SQL, original-journal equality and checked restoration. Source review
confirms RENAME failure cannot enter destructive cleanup. Fresh four-profile
runtime/migration/failure probes verify that only test evidence changed.
Graph impact of test-file targets is UNKNOWN where script invocation is not
resolved; actual in-container/compose callers are verified in source. Graph
change analysis is run before commits and final merge; empty graph hits are not
used as proof of safety.

The fixture commit’s [CI run 38244054531](https://github.com/MrDarkRoot/WriteLeash/actions/runs/38244054531)
at **4e61afb…** passed PR_FAST, journal and CI_COVERAGE; twelve unowned families
were **SKIPPED**. Final evidence-HEAD results, independent review and merge SHA
are recorded in the #254 review/handoff and #240 comment after completion;
they do not select a newer release source. No merge until all those gates pass.

The issue explicitly covers publication, so **#240 remains OPEN** after technical
merge. The sole remaining release action is the Founder-authorized publisher’s
WordPress.org SVN publication/confirmation/public verification procedure on
these exact bytes, including private permission verification before writes.

## Reproduction

```sh
git worktree add --detach <clean-a> 8748d831f8a3a0931b320e4bc67abf85ece964b0
git worktree add --detach <clean-b> 8748d831f8a3a0931b320e4bc67abf85ece964b0
PYTHONDONTWRITEBYTECODE=1 python3 <clean-a>/wordpress/release/build-wordpress-org-v02.py --source <clean-a> --sha 8748d831f8a3a0931b320e4bc67abf85ece964b0 --version 0.2.0 --output <empty-output-a>
PYTHONDONTWRITEBYTECODE=1 python3 <clean-b>/wordpress/release/build-wordpress-org-v02.py --source <clean-b> --sha 8748d831f8a3a0931b320e4bc67abf85ece964b0 --version 0.2.0 --output <empty-output-b>
cmp <empty-output-a>/writeleash-0.2.0.zip <empty-output-b>/writeleash-0.2.0.zip
# Install that ZIP into disposable WP/Woo; use the corrected fixture commit separately.
wp --path=<site> eval-file <evidence-checkout>/wordpress/tests/durable/journal-ddl-integration.php
WL240_DDL_CONTROL=noop wp --path=<site> eval-file <evidence-checkout>/wordpress/tests/durable/journal-ddl-negative-controls.php
# The last command must exit 1, with targeted DDL_LAYOUT proof and journal_preserved=true.
```
