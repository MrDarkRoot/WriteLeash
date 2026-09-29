# V0.1 technical release matrix (#62)

**Identity:** public Plugin Name **CommitCap**, intended WordPress.org slug and
Text Domain `commitcap`; installed test root `wp-content/plugins/commitcap/`,
main file `commitcap.php`. The internal source path remains
`wordpress/commitcap-for-wordpress/`. `stage-plugin.sh` copies that subtree into
each disposable installation; it does not create a release artifact. One header
is checked with WordPress `get_plugin_data()` and a source-wide header audit.

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
| `tests/run.sh`: exact versions, installed identity, activation/deactivation, PHP lint and direct execution guards, exact uninstall | PASS | PASS |
| `tests/engine/run.sh`: helper + five routines, exact runtime grants, physical event P+1 denial, Guard errors/savepoints, #82 L<P, #83 live Doctor drift, #84 installation/foreign refusal/rotation/drain/exact cleanup | PASS | PASS |
| `tests/adapter/run.sh`: real authenticated Redirection REST (correct build/handler), 6 rows with L=10 COMMITTED/6 disabled; 6 rows with L=5 logical DENIED/6 unchanged; normal UPDATE 0, restricted UPDATE 1 | PASS | PASS |
| Adapter #78/#60/#61: disabled/version/runtime/trigger/grant/FK/partition/engine/foreign graph drift rejects without fallback; callback/DB/swallowed errors, retry, truthful local evidence, Admin budget/enable/auth/nonce/demo and CLI human/JSON, deactivation and reinstall | PASS | PASS |
| #61 trusted two-stage barrier: distinct restricted IDs simultaneously blocked on certified UPDATE *before* release, both independent COMMITTED, stale rows=0, READY after | PASS | PASS |
| Source audit + official Plugin Check stable/static against temporary installed `commitcap/` | PASS (classified findings below) | PASS (classified findings below) |
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
[Plugin Check](https://wordpress.org/plugins/plugin-check/) and its
[CLI instructions](https://github.com/WordPress/plugin-check/blob/trunk/docs/CLI.md),
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
`$wpdb->prefix`, never hard-coded `wp_` table names.

Plugin Check command (per engine, installed test root):
`wp --path="$site" plugin check commitcap --format=json` after installing official
version **2.1.0**; all stable/static categories, no `--ignore-codes` and no
experimental runtime checks. Raw findings are parsed and classified by code;
unrecognized results fail the gate. Current results per engine: **41 errors,
15 warnings**; **40 reviewed source-scan false positives**, **16 DEFERRED
metadata/package findings**, **0 unclassified security/runtime blockers**.
The escape sniff reports thrown exceptions as if they were HTML: those reviewed
locations construct/chain exceptions, while Admin/REST/CLI output uses typed,
bounded facts and the runtime tests check escaping and secret leakage. Its
`$_POST` nonce warning points to the handler's read before the guarded
`process()` method checks both `manage_options` and the action nonce; the
form-markup warning points to escaped locally constructed markup. The classifier
checks those exact files/constructs and fails on new unclassified source errors.
Errors `plugin_header_no_license` and `no_license` are **DEFERRED #64**.
`missing_readme_header_tested`, `no_stable_tag`, short-description parsing and
`unexpected_markdown_file` are **DEFERRED #63**. The official Plugin Check 2.1.0
readme parser emits `Undefined array key 0` in its own `Parser.php:475` while
parsing this development README; this is third-party tool noise, not a CommitCap
runtime warning, and will be re-evaluated with #63 readme content. No finding is
suppressed or silently classified PASS.
The real adapter fixture runs a dedicated `WP_DEBUG=true` product slice, reads its debug log and fails on
CommitCap-originated PHP warnings/notices/deprecations/fatals or doing-it-wrong
messages. Synthetic DB-error tests and the Plugin Check parser warning are
classified by their distinct test/tool source.

## Boundaries and later gates

| Classification | Result |
| --- | --- |
| SUPPORTED + TESTED | Only the reviewed Redirection 5.5.2 global/select-all Disable, cooperative Guard + restricted runtime, both exact engines under the pinned fixture. |
| UNSUPPORTED + REJECTED | Wrong/missing build/handler, disabled operation, failed Doctor, drifted grant/trigger/P/sibling/FK/partition/storage graph; no stock fallback for dangerous candidate. Deactivated plugin has **stock, unprotected** Redirection behavior. |
| OUTSIDE CONTRACT | Hostile PHP/raw DB clients, stolen credential, arbitrary plugin/callback or INSERT/DELETE budgets; normal broad WordPress DB identity. |
| UNKNOWN | COMMIT sent but confirmation lost (`commit_failed_or_unknown`), non-default isolation, multisite/network activation, staging/fleet clone trust, arbitrary FK/cascade/partition graphs, hostile runtime credential use and external side effects. Tested path did not observe external effects; rollback cannot undo them. |
| NOT APPLICABLE | Action Scheduler/queue: this Redirection operation has no queue. |
| DEFERRED | #64 final GPL-compatible license; #63 readme/Stable Tag/contributors/tags/distribution contents; #77 deterministic ZIP/SVN staging; #76 exact ZIP audit; #65 clean-install artifact acceptance; #66 publish decision. |

This is a technical **source-tree preflight**, not an exact ZIP audit,
WordPress.org approval or a claim about untested support. For full security
classifications and the bounded concurrency proof see [THREAT-MODEL.md](THREAT-MODEL.md).
