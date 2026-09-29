# V0.1 technical release matrix and package preflight (#62/#63/#64)

**Identity:** public Plugin Name **WriteLeash**, intended WordPress.org slug and
Text Domain `writeleash`; installed test root `wp-content/plugins/writeleash/`,
main file `writeleash.php`. The internal source path remains
`wordpress/writeleash/`. Package release version is **0.1.0**, and
the WordPress.org listing source is `readme.txt` with `Stable tag: 0.1.0` and
`Tested up to: 7.1`.

**Dependency:** the main plugin header declares `Requires Plugins: redirection`.
WordPress Core refuses normal activation while Redirection is missing or
inactive and withholds normal dependency deactivation while WriteLeash is active.
Core's dependency header carries no version constraint, so WriteLeash still
independently requires Redirection **5.5.2 exactly** at runtime and fails closed
when the dependency disappears through lower-level paths.

**License (#64):** founder-selected **GPL version 2 or later**
(`GPL-2.0-or-later`). The main plugin header declares `GPL v2 or later` with the
GNU license URI, the main file carries the standard GPL notice and SPDX marker,
and `LICENSE` is the verbatim GNU GPLv2 text (SHA-256
`edaef632cbb643e4e7a221717a6c441a4c1a7c918e6e4d56debc3d8739b233f6`).
`LICENSE-AUDIT.md` records the third-party/provenance review: no bundled
libraries, media, fonts, compiled assets or copied third-party source; Redirection
is an external installed GPLv3 plugin, not bundled or relicensed. This repair did
not change the #64 decision or uncover new provenance evidence.

**Distribution allowlist (#63):** `wordpress/release/writeleash-distribution-files.txt`
is the canonical 30-file list for the future `writeleash/` release root
(entrypoint, uninstall, readme, LICENSE, the public operator workflow
`operator-setup.txt`, and the 25 production includes). `stage-plugin.sh` builds
every disposable install only from that list and fails if the staged set differs,
so CI exercises the intended distribution shape rather than the monorepo folder.
No ZIP or SVN artifact is created here; that remains #77.

## Exact fixtures

**#62 baseline (regression baseline, unchanged):** WordPress **6.8.3** on
PHP **8.2**, MySQL **8.0.44** and MariaDB **10.11.15**, Redirection **5.5.2**
only; certified operation `redirection-5.5.2-bulk-disable-global`, physical
ceiling **P=2000**. PHP **7.4** is the *declared minimum*, not a tested fixture.

**#63 current-stable compatibility gate:** WordPress **7.1.2** (current stable,
released 2026-09-22) on PHP **8.2**, the same two pinned database builds and the
same pinned Redirection **5.5.2**. This is deliberately a focused product gate,
not a second full adversarial suite.

Container images are digest-pinned in `wordpress/tests/Dockerfile` and the
Compose files (the core image is a build argument); the Redirection ZIP is
checksum-pinned in `wordpress/tests/research/checksums.txt`. The official Plugin
Check 2.1.0 ZIP is verified by SHA-256 in `wordpress/tests/release/run.sh`.

## One CI gate

`WordPress V0.1 release matrix` runs `bash wordpress/tests/release/run.sh`.
Strict shell exit, the existing Compose `EXIT` cleanup + post-clean assertions,
and the #61 row-lock-barrier trap cover failure paths. Each suite uses a new
WordPress install and both pinned engines; no suites are copied. Existing jobs
(`WordPress plugin foundation`, `WordPress MySQL MariaDB engine research`,
`WordPress Redirection adapter`, `WordPress plugin research`, and
`Native PG16 security research suite`) remain separate final-head checks.

| Gate / reusable executable evidence | MySQL | MariaDB |
| --- | --- | --- |
| `tests/run.sh`: exact 6.8.3 fixture, allowlisted install, identity/license/readme checks, dependency semantics (Core block, active-dependent predicate, lower-level loss fail-closed), activation/deactivation, PHP lint and direct execution guards, exact uninstall | PASS | PASS |
| `tests/engine/run.sh`: helper + five routines, exact runtime grants, physical event P+1 denial, Guard errors/savepoints, #82 L<P, #83 live Doctor drift, #84 installation/foreign refusal/rotation/drain/exact cleanup | PASS | PASS |
| `tests/adapter/run.sh`: real authenticated Redirection REST (correct build/handler), 6 rows with L=10 COMMITTED/6 disabled; 6 rows with L=5 logical DENIED/6 unchanged; normal UPDATE 0, restricted UPDATE 1 | PASS | PASS |
| Adapter #78/#60/#61: disabled/version/runtime/trigger/grant/FK/partition/engine/foreign graph drift rejects without fallback; callback/DB/swallowed errors, retry, truthful local evidence, Admin budget/enable/auth/nonce/demo and CLI human/JSON, deactivation and reinstall | PASS | PASS |
| #61 trusted two-stage barrier: distinct restricted IDs simultaneously blocked on certified UPDATE *before* release, both independent COMMITTED, stale rows=0, READY after | PASS | PASS |
| `tests/current-core/run.sh` (#63): exact 7.1.2 fixture, documented operator script review+apply with no secret leak, canonical Doctor READY, Admin/CLI READY, real REST COMMITTED/DENIED with fresh observers, zero WriteLeash WP_DEBUG diagnostics, deactivation boundary, uninstall/reinstall | PASS | PASS |
| `package-preflight.php` + `readme-validate.php` + source audit: allowlist matches every production file, verbatim GPLv2, exact operation wording, stable tag/version/license/dependency agreement | PASS | PASS |
| Official Plugin Check stable/static against the allowlisted installed `writeleash/` | PASS (findings reviewed below) | PASS (findings reviewed below) |
| #58 Admin demo A→B→A→B and three separate CLI demo processes A→B→A→B, no normal-run reset/DDL/DCL/INSERT/DELETE; noncanonical `trusted_reset_required` | PASS | PASS |

Fresh observer means an **independent database connection opened after the
request** observes the durable Redirection/demo rows. Runtime `Last_Outcome`
records `durability_verified_by_fresh_observer=false` even when the CI observer
independently verifies those rows. The real production logical denial is a
pre-COMMIT `consumed=6 > L=5` under P=2000, distinct from the synthetic
**physical** P+1 trigger denial.

The separate `WordPress plugin research` workflow preserves #85 plugin selection
evidence. The existing `WordPress MySQL MariaDB engine research` job re-runs
`experiments/mysql_tx_budget/run.sh` as a #53 **counterexample**, never a
WordPress security guarantee; PG16 research is a separate green workflow, not a
WordPress product claim.

## WordPress.org source preflight (reviewed 2026-09-29)

Official [Plugin Developer FAQ](https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/),
[Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/),
[Common Issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/),
[Plugin Readmes](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/),
[Header Requirements](https://developer.wordpress.org/plugins/the-basics/header-requirements/),
[Plugin Check](https://wordpress.org/plugins/plugin-check/) and its
[CLI instructions](https://github.com/WordPress/plugin-check/blob/trunk/docs/CLI.md),
[Readme Validator](https://wordpress.org/plugins/developers/readme-validator/),
[Security](https://developer.wordpress.org/apis/security/),
[Sanitizing](https://developer.wordpress.org/apis/security/sanitizing/),
[Validating](https://developer.wordpress.org/apis/security/data-validation/),
[Escaping](https://developer.wordpress.org/apis/security/escaping/),
[Nonces](https://developer.wordpress.org/apis/security/nonces/),
[Roles/Capabilities](https://developer.wordpress.org/apis/security/user-roles-and-capabilities/),
and [`wpdb::prepare()`](https://developer.wordpress.org/reference/classes/wpdb/prepare/)
were consulted. Applied requirements: public slug/folder/main filename/domain
agreement; authorization **in addition to** action-bound nonce; strict canonical
budget rejection; late context escaping; prepared dynamic SQL values and strict
internal identifiers; guarded direct access; no runtime phone-home, updater,
encoded executable blob, shell process, global error-reporting override or
unexpected privileged activation. No gettext calls or compiled/minified JS/CSS,
bundled vendor libraries, or test directories occur in production source.

`readme-validate.php` asserts title, a 131-character markup-free short
description, 1–5 unique tags, `Tested up to: 7.1`, `Requires at least: 6.8`,
`Requires PHP: 7.4`, `Stable tag: 0.1.0` matching the plugin header and runtime
constant, explicit exact tested WordPress core fixtures (6.8.3 and 7.1.2),
`GPLv2 or later`, `Requires Plugins: redirection`, recognizable sections, no
placeholders, no `Baseline Disable` wording and the 10k size guidance. The
official readme validator is a web-only form; the deterministic local equivalent
runs in CI and the same file is staged for Plugin Check.

Plugin Check command (per engine, allowlisted installed test root):
`wp --path="$site" plugin check writeleash --format=json` after installing official
version **2.1.0**; all stable/static categories, no `--ignore-codes` and no
experimental runtime checks. Raw findings are parsed and classified by code;
unrecognized results fail the gate. Current results per engine: **37 errors,
3 warnings**; **40 reviewed findings**; **0 unclassified security/runtime
blockers**. The repaired packaging findings are **gone** and would now fail the
gate if they returned: `plugin_header_no_license`, `no_license`,
`missing_readme_header_tested`, `no_stable_tag`, short-description parsing,
`outdated_tested_upto_header` (current-stable evidence now exists) and
`unexpected_markdown_file` (the public operator guide ships as
`operator-setup.txt`). The only remaining reviewed findings are the unchanged
narrow source false positives: thrown exceptions matched by the escape sniff,
the guarded `$_POST` read before `process()` verifies capability and nonce, and
escaped locally constructed form markup. The classifier requires the exact file,
construct and context for each; no `--ignore-codes`, no suppressions.

The real adapter fixture runs a dedicated `WP_DEBUG=true` product slice, reads its
debug log and fails on WriteLeash-originated PHP warnings/notices/deprecations/
fatals or doing-it-wrong messages; the current-core suite does the same on
WordPress 7.1.2. Synthetic DB-error tests and the Plugin Check readme parser
warning are classified by their distinct test/tool source.

## WordPress compatibility policy

The Doctor accepts **exact tested core builds only**: `6.8.3` (baseline) and
`7.1.2` (current stable at release). Every other build — including untested
minors between them such as 6.9.x, 7.0.x or 7.1.1 — is reported `UNKNOWN` and
therefore `NOT_READY`; enabling is refused. `Tested up to: 7.1` in the readme is
a directory major/minor summary, not a claim that every 6.8–7.1 release was
certified; the readme's Supported configuration section names the exact fixtures.

## Boundaries and later gates

| Classification | Result |
| --- | --- |
| SUPPORTED + TESTED | Only the reviewed Redirection 5.5.2 global/select-all Bulk Disable, cooperative Guard + restricted runtime, on both exact engines with the two tested WordPress core fixtures. |
| UNSUPPORTED + REJECTED | Wrong/missing build/handler, disabled operation, failed Doctor, drifted grant/trigger/P/sibling/FK/partition/storage graph, untested WordPress core builds; no stock fallback for the dangerous candidate. Deactivated plugin has **stock, unprotected** Redirection behavior. |
| OUTSIDE CONTRACT | Hostile PHP/raw DB clients, stolen credential, arbitrary plugin/callback or INSERT/DELETE budgets; normal broad WordPress DB identity. The physical ceiling is a cooperative restricted-runtime policy, not hostile/raw-client containment. |
| UNKNOWN | COMMIT sent but confirmation lost (`commit_failed_or_unknown`), non-default isolation, multisite/network activation, staging/fleet clone trust, arbitrary FK/cascade/partition graphs, hostile runtime credential use and external side effects. Tested path did not observe external effects; rollback cannot undo them. |
| NOT APPLICABLE | Action Scheduler/queue: this Redirection operation has no queue. |
| DEFERRED | #77 deterministic ZIP/SVN staging; #76 exact-ZIP audit; #65 clean-install artifact acceptance; #66 publish decision. |

This is a technical **source-tree preflight**, not an exact ZIP audit,
WordPress.org approval or a claim about untested support. For full security
classifications and the bounded concurrency proof see [THREAT-MODEL.md](THREAT-MODEL.md).
