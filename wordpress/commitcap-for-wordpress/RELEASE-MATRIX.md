# V0.1 technical release matrix and package preflight (#62/#63/#64)

**Identity:** public Plugin Name **CommitCap**, intended WordPress.org slug and
Text Domain `commitcap`; installed test root `wp-content/plugins/commitcap/`,
main file `commitcap.php`. The internal source path remains
`wordpress/commitcap-for-wordpress/`. Package release version is **0.1.0**, and
the WordPress.org listing source is `readme.txt` with `Stable tag: 0.1.0`.

**License (#64):** founder-selected **GPL version 2 or later**
(`GPL-2.0-or-later`). The main plugin header declares `GPL v2 or later` with the
GNU license URI, the main file carries the standard GPL notice and SPDX marker,
and `LICENSE` is the verbatim GNU GPLv2 text (SHA-256
`edaef632cbb643e4e7a221717a6c441a4c1a7c918e6e4d56debc3d8739b233f6`).
`LICENSE-AUDIT.md` records the third-party/provenance review: no bundled
libraries, media, fonts, compiled assets or copied third-party source; Redirection
5.5.2 is an external installed GPLv3 plugin, not bundled or relicensed.

**Distribution allowlist (#63):** `wordpress/release/commitcap-distribution-files.txt`
is the canonical 30-file list for the future `commitcap/` release root
(entrypoint, uninstall, readme, LICENSE, public operator guide, and the 25
production includes). `stage-plugin.sh` builds every disposable install only
from that list and fails if the staged set differs, so CI exercises the intended
distribution shape rather than the monorepo folder. No ZIP or SVN artifact is
created here; that remains #77.

**Exact CI fixture:** WordPress **6.8.3** on PHP **8.2**, MySQL **8.0.44** and
MariaDB **10.11.15** (Ubuntu image reports `10.11.15-MariaDB-ubu2204`),
Redirection **5.5.2** only; certified operation
`redirection-5.5.2-bulk-disable-global`, physical ceiling **P=2000**.
PHP **7.4** is the *declared minimum*, not a tested fixture. Container images
are digest-pinned in `wordpress/tests/Dockerfile` and the Compose files;
the Redirection ZIP is checksum-pinned in `wordpress/tests/research/checksums.txt`.
The official Plugin Check 2.1.0 ZIP is verified by SHA-256 in
`wordpress/tests/release/run.sh` before use.

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
| `tests/run.sh`: exact versions, allowlisted install, identity/license/readme checks, activation/deactivation, PHP lint and direct execution guards, exact uninstall | PASS | PASS |
| `tests/engine/run.sh`: helper + five routines, exact runtime grants, physical event P+1 denial, Guard errors/savepoints, #82 L<P, #83 live Doctor drift, #84 installation/foreign refusal/rotation/drain/exact cleanup | PASS | PASS |
| `tests/adapter/run.sh`: real authenticated Redirection REST (correct build/handler), 6 rows with L=10 COMMITTED/6 disabled; 6 rows with L=5 logical DENIED/6 unchanged; normal UPDATE 0, restricted UPDATE 1 | PASS | PASS |
| Adapter #78/#60/#61: disabled/version/runtime/trigger/grant/FK/partition/engine/foreign graph drift rejects without fallback; callback/DB/swallowed errors, retry, truthful local evidence, Admin budget/enable/auth/nonce/demo and CLI human/JSON, deactivation and reinstall | PASS | PASS |
| #61 trusted two-stage barrier: distinct restricted IDs simultaneously blocked on certified UPDATE *before* release, both independent COMMITTED, stale rows=0, READY after | PASS | PASS |
| `package-preflight.php` + `readme-validate.php` + source audit: allowlist matches every production file, verbatim GPLv2, stable tag/version/license agreement | PASS | PASS |
| Official Plugin Check stable/static against the allowlisted installed `commitcap/` | PASS (findings reviewed below) | PASS (findings reviewed below) |
| #58 Admin demo A→B→A→B and three separate CLI demo processes A→B→A→B, no normal-run reset/DDL/DCL/INSERT/DELETE; noncanonical `trusted_reset_required` | PASS | PASS |

Fresh observer means an **independent database connection opened after the
request** observes the durable Redirection/demo rows. Runtime `Last_Outcome`
records `durability_verified_by_fresh_observer=false` even when the CI observer
independently verifies those rows. The real production logical denial is a
pre-COMMIT `consumed=6 > L=5` under P=2000, distinct from the synthetic
**physical** P+1 trigger denial. Normal WordPress keeps its broad DB account;
only the reviewed UPDATE runs on the restricted secondary connection.

The separate `WordPress plugin research` workflow preserves #85 plugin selection
evidence. The existing `WordPress MySQL MariaDB engine research` job re-runs
`experiments/mysql_tx_budget/run.sh` as a #53 **counterexample**, never a
WordPress security guarantee; its verbose research/GRANT transcript is not part
of the product release-matrix log. PG16 research is a separate green workflow, not a
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
The private Admin form's `$extra` is locally built escaped input markup, not
request HTML. The actual Admin handler, real REST path, and CLI have executable
behavioral tests; static checks are supplementary. Production target names use
`$wpdb->prefix`, never hard-coded `wp_` table names. `Requires Plugins:
redirection` is intentionally **not** declared: WordPress dependency semantics
would interfere with deactivating Redirection, contradicting the tested
fail-closed boundary where a missing Redirection makes the certified request
unavailable while the plugin remains active.

`readme-validate.php` asserts title, a 131-character markup-free short
description, 1–5 unique tags, `Tested up to: 6.8`, `Requires at least: 6.8`,
`Requires PHP: 7.4`, `Stable tag: 0.1.0` matching the plugin header and runtime
constant, `GPLv2 or later`, recognizable sections, no placeholders and the 10k
size guidance. The official readme validator is a web-only form; the deterministic
local equivalent is used in CI and the same file is staged for Plugin Check.

Plugin Check command (per engine, allowlisted installed test root):
`wp --path="$site" plugin check commitcap --format=json` after installing official
version **2.1.0**; all stable/static categories, no `--ignore-codes` and no
experimental runtime checks. Raw findings are parsed and classified by code;
unrecognized results fail the gate. Current results per engine: **38 errors,
4 warnings**; **42 reviewed findings**; **0 unclassified security/runtime
blockers**. The #62-era license/readme deferrals (`plugin_header_no_license`,
`no_license`, `missing_readme_header_tested`, `no_stable_tag`,
short-description parsing, internal markdown files) are **gone** and would now
fail the gate if they returned. Two reviewed findings are new and explicit:

* `outdated_tested_upto_header` (readme.txt) — the certified operation is pinned
  to the tested WordPress 6.8.3 fixture, so claiming a newer `Tested up to` value
  would be a false compatibility claim; the limitation is also stated in the
  readme and this is reported as a known product limitation, not hidden.
* `unexpected_markdown_file` (OPERATOR-SETUP.md) — the public database-operator
  guide is intentionally shipped documentation; Plugin Check's allowed
  root-markdown list simply does not include that name. No internal/research
  Markdown is staged.

The remaining reviewed source findings are the unchanged narrow false positives:
thrown exceptions matched by the escape sniff, the guarded `$_POST` read before
`process()` verifies capability and nonce, and escaped locally constructed form
markup. No finding is suppressed; the classifier requires the exact file,
construct and context for each. The real adapter fixture runs a dedicated
`WP_DEBUG=true` product slice, reads its debug log and fails on
CommitCap-originated PHP warnings/notices/deprecations/fatals or doing-it-wrong
messages. Synthetic DB-error tests and the Plugin Check readme parser warning are
classified by their distinct test/tool source.

## Boundaries and later gates

| Classification | Result |
| --- | --- |
| SUPPORTED + TESTED | Only the reviewed Redirection 5.5.2 global/select-all Disable, cooperative Guard + restricted runtime, both exact engines under the pinned fixture. |
| UNSUPPORTED + REJECTED | Wrong/missing build/handler, disabled operation, failed Doctor, drifted grant/trigger/P/sibling/FK/partition/storage graph; no stock fallback for dangerous candidate. Deactivated plugin has **stock, unprotected** Redirection behavior. |
| OUTSIDE CONTRACT | Hostile PHP/raw DB clients, stolen credential, arbitrary plugin/callback or INSERT/DELETE budgets; normal broad WordPress DB identity. |
| UNKNOWN | COMMIT sent but confirmation lost (`commit_failed_or_unknown`), non-default isolation, multisite/network activation, staging/fleet clone trust, arbitrary FK/cascade/partition graphs, hostile runtime credential use and external side effects. Tested path did not observe external effects; rollback cannot undo them. |
| NOT APPLICABLE | Action Scheduler/queue: this Redirection operation has no queue. |
| DEFERRED | #77 deterministic ZIP/SVN staging; #76 exact-ZIP audit; #65 clean-install artifact acceptance; #66 publish decision. |

This is a technical **source-tree preflight**, not an exact ZIP audit,
WordPress.org approval or a claim about untested support. For full security
classifications and the bounded concurrency proof see [THREAT-MODEL.md](THREAT-MODEL.md).
