# #240 Plugin Check SQL remediation

## Verdict and authority

**READY_FOR_INDEPENDENT_REVIEW** of the bounded remediation. This is not release
permission. Draft PR: <https://github.com/MrDarkRoot/WriteLeash/pull/255>.

| Identity | Value |
|---|---|
| Previous frozen source | `04dbbcd11a53abbf816ad1b11b7e659156daca29` |
| Proposed runtime source | `0affead9307eddb34a4d99038169a5aa3f313376` |
| Previous ZIP SHA-256 | `50901f2c034c705dff355427fe496090d1e4968f0c299853b1b9a4da46655757` |
| New ZIP SHA-256 | `a77704b42c9bbb2350f32b0de23a3c46f3a0fe76c617561b91133651ec9d8bb7` |
| Official Plugin Check | 2.1.0; tool ZIP `6ff4bd2145f3befcf907df158cc466b1649dafed5686de8369907403c3013fc4` |
| Installed-ZIP findings | **1 ERROR / 9 WARNINGS → 0 ERROR / 15 WARNINGS** |

The runtime source above is the actual clean commit used for both builds and
installed tests. This subsequent evidence-only commit has a different SHA;
its exact-HEAD CI is linked in the Draft PR checks/handoff. The payload is
source-bound, not an evidence-branch working-tree export. Only the journal PHP
member changes among 46 ZIP members; the other 45 members are byte-identical.

## Smallest safe runtime change

`Price_Apply_Journal::install()` replaces prepared-prefix-plus-concatenated DDL
with three complete literal statements, each prepared with `%i` after the
existing table-name validation. Actual column type/nullability and presence
are read from `SHOW COLUMNS`; missing/non-longtext legacy plan storage is
refused rather than fabricated or narrowed. `MODIFY plan_json` runs only when
it is still NOT NULL. Each ADD runs only for its absent column.

MySQL DDL commits independently. An interruption or SQL failure may leave an
intermediate column layout, but no compaction runs until all required DDL
succeeds. A retry introspects reality and resumes only absent changes. The
metadata-cache invalidation now also runs in `finally` after partial failure.
The existing transactional compaction, v1 identity/evidence upgrade, orphan
legacy preservation, dbDelta reconciliation, final schema assertion and
schema-version option publication are retained.

The tests assert that **explicit migration MODIFY/ADD** does not repeat on a
ready layout. This is not a zero-total-DDL claim: unchanged Core `dbDelta()`
can still normalize integer display widths on MariaDB during explicit setup.
Removing its reconciliation or widening this PR to a separate dbDelta type
normalization change would require additional schema analysis.

The first alternative (seven switch-selected prepared statements) removed
the original diagnostic but generated `WordPress.DB.PreparedSQL.NotPrepared`.
Moving preparation outside the switch also failed that check. Those development
trials were discarded. The final literal inline representation adds no scanner
suppression and passes the exact-ZIP scan with zero ERRORs.

## Findings and independently justified warnings

Every original and final finding, with scanner version, file/line/column,
triggering source statement, relevance and disposition, is in
[`findings.json`](findings.json). Full stdout/stderr, including trailing blank
lines, are serialized byte-for-byte in [`plugin-check-raw.json`](plugin-check-raw.json).
The earlier #240 raw receipt and all #254 evidence remain untouched.

| Sites | Original → final diagnostics | Reason retained / native alternative assessment |
|---|---|---|
| Journal table existence, line 121 | DirectQuery + NoCaching → same | Live `information_schema.TABLES` authority is needed after interrupted setup, table removal or partial legacy migration. An option or cached existence result is not equivalent. |
| Journal column introspection, line 123 | DirectQuery + NoCaching → same | Prepared live `SHOW COLUMNS` supplies presence, type and nullability. Cached metadata can describe an earlier DDL state. dbDelta alone does not implement this validation/nullability migration. |
| Journal DDL, old line 127 | UnescapedDBParameter ERROR → eliminated | Original fragments were literals, not request-controlled SQL; removing concatenation improves analyzability and prevents future fragment injection. Table validation and identifier preparation are retained. |
| Journal DDL, old 127 → new 130/131/132 | 1 × (DirectQuery + NoCaching + SchemaChange) → 3 × same | All three schema changes are necessary when migrating a historical layout. They are conditional and retry-safe. Caching a schema write has no equivalent correctness meaning; metadata is invalidated. Core dbDelta does not make these explicit NULL/presence transitions and failure handling interchangeable. |
| Presets slot reservation, line 133 | DirectQuery → same | Atomic `INSERT IGNORE` reserves without replacing an existing owner. Supported Core `add_option()` uses `ON DUPLICATE KEY UPDATE` (observed WP 7.1.2 `wp-includes/option.php:1142`), which can overwrite after a stale pre-read. |
| Presets CAS, line 149 | DirectQuery → same | Conditional serialized old-value/BINARY UPDATE or DELETE prevents stale actors from modifying a renamed or reused slot. `update_option()`/`delete_option()` do not provide that same old-value predicate. |

Thus **no warning was eliminated** by weakening semantics. Splitting one DDL
site into three increases scanner warning locations by six; it does not add
unconditional DDL or cached transaction authority. Journal persistence,
fresh introspection, locked transitions, durable recovery and atomic Presets
reservation/CAS remain DB-authoritative. No new suppressions were added.

Presets runtime bytes are unchanged. The creator/capability checks, canonical
configuration validation, per-blog HMAC and option/notoptions invalidation
remain intact. Actual two-process contention for the last slot yields exactly
one save and one `preset_limit`, capacity 20, and the exact signed winner.
Stale rename/delete against a reused UUID fails `preset_changed`; another
manager cannot read the winner. The winning process and ordinary CRUD tests
verify cache repair. Default caches are request-local: the observing parent
explicitly discards its older request-local cache before observing child writes.
This is not a claim that separate default-cache PHP processes share a cache.

## Fresh regression evidence

[`runs.jsonl`](runs.jsonl) contains commands, timestamps, exit expectations,
fixture hashes, environment and original stdout/stderr for retained final runs.
All primary installed-ZIP assertions execute packaged PHP, not a source-staged
replacement. Bootstrap/setup calibrations and earlier failures are summarized
separately; they are not represented as passing acceptance.

| Coverage | Fresh result |
|---|---|
| New journal DDL fixture | PASS on all four DB/cache profiles; 8 partial layouts, 6 invalid identifiers, missing/malformed legacy plan storage, actual later-DDL failure after earlier commits, safe retry, approval/material preservation, no repeated explicit migration DDL |
| Journal full suite | PASS on all four profiles, 394 assertions/profile; actual v1→v3 migration, identity/evidence, row locks, rollback, process kills, ambiguous commit, reconnect/connection loss and no replay |
| Job full suite | PASS on all four profiles, 932 default / 933 Redis assertions; partial Apply/Resume, preserved approvals/History/pending Undo, v2 compaction rollback/retry, orphan evidence, fences and actual crash recovery |
| New Presets concurrency fixture | PASS on all four profiles; independent processes, final-slot cap, stale rename/delete, reuse, ownership and cache consistency |
| Full Admin fixture | PASS on all four profiles, 1,703 assertions/profile, including Presets integrity/security, approvals, Apply/Resume/Undo and real 1,000/1,001 selection boundaries |
| Progress, conflict Recovery, Presets/Repeat | PASS on all four profiles |
| Actual HTTP request-stack fixture | PASS on all four profiles; authenticated forms, nonces, POST/redirect/reload and re-login, not direct handler invocation |
| Parent-state and exact 1,000-target release fixtures | PASS on all four profiles; actual prices/journal/Undo outcomes and excluded sentinel/no-write controls |
| Historical v0.1 ZIP → new exact v0.2 ZIP | PASS on all four profiles; completed/partial jobs, public IDs, immutable plans/approvals/journals, pending Resume drift, no percentage replay, eligible Undo and conflicts |
| #254 focused PHP compatibility repeated | PASS: PHP 7.4.33 / 8.0.30 / 8.1.34 on WP 7.0.1 / Woo 10.0.0; PHP 8.2.34 on WP 7.1.2 / Woo 11.1.2; MariaDB/default cache; exact installation, missing-Woo refusal, new migration fixture, Preview/Approve/Apply/History/Undo and Presets/Repeat |
| #254 real fault fixture repeated | PASS on both engines/Redis: real SIGKILL before commit and after commit/before response, connection KILL, independent observation, natural lease expiry, recovery and actual Undo |
| Source domain / Presets unit suites | Terminal-observed PASS (2,607 / 3,251 assertions), recorded as source tests, not installed-ZIP tests |
| PR_FAST | Local PASS; runtime-source hosted PASS. Final evidence-HEAD check is separately linked from #255. |

The exact runtime-source [hosted run 38224055777](ci-runtime-source.json) is now
**SUCCESS**: PR_FAST, journal, jobs, Admin, Undo and CI_COVERAGE passed. Nine
unowned families were skipped, not passed. Its source SHA is the proposed
runtime commit above; the subsequent evidence-only HEAD receives its own run.

DBs are **MySQL 8.0.44 / MariaDB 10.11.15**. Persistent cache is **Redis 7.4.2**,
Redis Object Cache **2.7.0**, Predis. Current-core installed suites use WP 7.1.2 /
Woo 11.1.2 / host PHP 8.2. Compatibility profiles use isolated extracted PHP
packages; host PHP configuration is not changed. Tool/archive hashes and
installed observations are in [`artifact.json`](artifact.json).

The three reused #254 fixtures (`upgrade-v02.php`, `freeze-acceptance-v02.php`,
`freeze-recovery-v02.php`) are read from its separate retained checkout and
verified against Git blobs at `1086c01b9d5d75879ccba75afc2daa4703d9e123` before
execution. Their source and evidence are not overwritten or incorporated into
this branch's runtime.

### Calibration and failed attempts

- An initial CLI invocation used an unsupported combined low-severity flag;
  it did not scan. The corrected full scan used both documented flags.
- Development fixture corrections: an initial private-method setup call,
  associative row-key ordering after ADD COLUMN, and an overly broad
  zero-total-DDL assertion. An empty missing binding column is repairable by
  existing dbDelta; it was not mislabeled as a required rejection. The final
  negative cases address missing or malformed legacy plan storage.
- The standalone jobs fixture requires its disposable database to be named
  `wp_test`. A first generic-site run did not satisfy that contract. MariaDB's
  binary installer also creates anonymous localhost accounts which shadow its
  `%` restricted test actor. Only task-owned installer accounts were removed;
  fresh MySQL/MariaDB fixture-contract reruns passed.
- An early orchestration timeout interrupted unfinished Admin execution; the
  subsequent background matrix completed. MySQL/Redis scale was interrupted
  once by launcher timeout and once by operator abort; its final retained retry
  passed. Partial output is not a PASS or a substituted result.
- The early lab wrapper used an engine-only shared ZIP-identity metadata hint.
  Historical tests on a different site could overwrite that hint while a main
  suite was running. Affected receipt hints are explicitly preserved as
  `original_helper_zip_sha256` and corrected using the exact CLI site, captured
  installation sequence and independently verified per-file installed trees.
  The main/plugin test sites were never replaced with the historical ZIP;
  only the separate historical sites were. This is a metadata correction,
  not an assertion that old-artifact tests passed against new bytes.

## Artifact reproduction and local SVN parity

Two independent clean worktrees at the proposed source produced identical ZIP
bytes. The 46-member public manifest, source/ZIP/extracted/local staging tree
identity, metadata, 11 directory assets and 11 artifact corruption controls
pass. A **new** anonymous SVN checkout was staged locally with trunk/tag/assets;
trunk and local `tags/0.2.0` match every candidate ZIP member. All remote SVN
operations are reads. Credentials used: none; network SVN writes: none.

```sh
git worktree add --detach /tmp/wlpc-a 0affead9307eddb34a4d99038169a5aa3f313376
git worktree add --detach /tmp/wlpc-b 0affead9307eddb34a4d99038169a5aa3f313376
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wlpc-a/wordpress/release/build-wordpress-org-v02.py --source /tmp/wlpc-a --sha 0affead9307eddb34a4d99038169a5aa3f313376 --version 0.2.0 --output /tmp/wlpc-build-a
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wlpc-b/wordpress/release/build-wordpress-org-v02.py --source /tmp/wlpc-b --sha 0affead9307eddb34a4d99038169a5aa3f313376 --version 0.2.0 --output /tmp/wlpc-build-b
cmp /tmp/wlpc-build-a/writeleash-0.2.0.zip /tmp/wlpc-build-b/writeleash-0.2.0.zip
sha256sum /tmp/wlpc-build-a/writeleash-0.2.0.zip
python3 /tmp/wlpc-a/wordpress/release/test-wordpress-org-v02.py /tmp/wlpc-build-a
# Install that ZIP into disposable WordPress with Woo and Plugin Check 2.1.0.
wp --path=<site> plugin check writeleash --format=json --include-low-severity-errors --include-low-severity-warnings --include-experimental --require=<site>/wp-content/plugins/plugin-check/cli.php
wp --path=<site> eval-file /tmp/wlpc-a/wordpress/tests/durable/journal-ddl-integration.php
wp --path=<site> eval-file /tmp/wlpc-a/wordpress/tests/admin/presets-concurrency.php
```

Use empty build destinations and the source commit shown; do not reuse the old
checksum or source freeze. The new regression fixtures are included in the
existing two-engine/default/Redis source CI gates. They are not packaged.

Graph pre-edit impact for `install` was UNKNOWN (no resolved callers; three
receiver-typing gaps), not low risk. Text verification identifies the explicit
`Job_Repository::ensure_schema()` caller. Graph change analysis before the
runtime commit reported the installer/class and one preparation flow, MEDIUM;
Git diff independently confines production changes to that method. New test
symbols absent from the original index are not evidence of zero impact.

## Freeze / #254 reconciliation and remaining acceptance

All former **whole-artifact** acceptance, Plugin Check, installed-tree, fault,
compatibility and historical-upgrade results tied to the previous ZIP remain
historical, not proof for these new bytes. This report supplies fresh affected
coverage. #254 is preserved as the old-checksum evidence PR; its frozen-source,
artifact identity and release-readiness instructions must be reconciled by an
explicit Founder decision if this remediation is adopted. Nothing in this PR
silently selects a new main/release source.

Before release: independent review; completion/review of the Draft PR's hosted
path-owned checks; Founder selection of the final release source and GO for
the exact **new** checksum; and the existing final RELEASE_FULL/publication
procedure for that selected source. Families skipped by path ownership are
not inferred to have passed. No WordPress.org publication, SVN network write,
merge, #240 closure or modification of #254 evidence is authorized or performed.
