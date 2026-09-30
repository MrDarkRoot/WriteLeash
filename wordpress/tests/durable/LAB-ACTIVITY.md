# #108 lab activity

Base: `0f08c233b32bc67fa7186779790d4fdb6f2d28f8`.
Branch: `free/108-woo-journal-correctness`.
Live verification: #106/#108/#109 OPEN; #107 CLOSED/completed;
PR #114 MERGED into the recorded base. No existing untracked icons changed.

## Reproduction

Run `bash wordpress/tests/durable/run.sh`. Checksummed official Woo/Redis/Redirection
artifacts are downloaded into ignored fixture caches. Digest-pinned disposable
MySQL, MariaDB and Redis containers run the real WordPress/Woo plugin. The runner
tears down its own named compose project, including on failure. It does not use
production sites, external webhooks or Guard credentials.

Workflow: **WordPress Woo durable price journal**. The `woo-journal-108` artifact
contains the complete setup, lab markers and failure output. The CI run SHA is
the authority for candidate results. This file and the fixture scripts are
excluded from the distribution allowlist.

## Development activity

- Inspected exact Woo 11.1.2 CPT product save, metadata synchronization, lookup
  writer, cache helpers, optional instance cache, and WordPress 7.1.2 reconnect.
- Detected automatic wpdb query replay after reconnect, requiring a scoped writer
  that refuses replay while sharing the original mysqli connection.
- Implemented the narrow item journal and actual Woo CRUD primitive.
- First focused run stopped on a fixture assertion: default WordPress reports a
  null object-cache flag; assertion corrected to compare its boolean value.
- Reproduced the rollback cache counterexample on both engines with default
  cache and Redis: regular=80 while lookup=100. Targeted eviction restores
  consistent real Woo CRUD retry.
- Initial four-variant matrix completed (225/227 assertions per variant), but
  the driver exited 2 because its source was edited while Bash was running.
  This development run is not claimed as the final candidate PASS.
- Fixed the scoped writer's copied result-resource ownership before restoring
  stock wpdb; focused fixtures exercise ordinary WordPress shutdown afterward.
- Corrected overlap observation to SHOW FULL PROCESSLIST (the ordinary form
  truncates the lock query before FOR UPDATE).
- Added deterministic subprocess barriers, real SIGKILL, independent DB/WP
  observers, two-worker overlap, and a synthetic hook plugin.

- Found and corrected a repeatable-read permission race during source review:
  actor/role locks must precede the first consistent snapshot. Added a real
  paused-worker role-definition revocation test and fresh WP_Roles reload.

Final results and maintainer checklist are recorded after the final candidate
has completed focused tests and the maintained CI workflows.

## Final local candidate results

`bash wordpress/tests/durable/run.sh` exited **0**, including cleanup of its own
containers, network and volumes. No WriteLeash-originated WP_DEBUG diagnostics;
staged distribution source audit passed with 37 guarded PHP files.

| Database | Default cache | Redis persistent cache |
| --- | --- | --- |
| MySQL 8.0.44 | PASS, 278 assertions | PASS, 280 assertions |
| MariaDB 10.11.15-MariaDB-ubu2204 | PASS, 278 assertions | PASS, 280 assertions |

Both engines and both cache variants passed: clean A/B apply; known lookup-cache
counterexample then targeted fix; rollback/retry; four real SIGKILL checkpoints;
one two-item process interruption/restart; real overlapping workers; no
already-APPLIED save replay; external edit and target-equality conflict; user-role
and paused-worker role-capability revocation; real connection KILL/reconnect/
replacement/lost sentinel/failed rollback; query-replay fence; both deterministic
ambiguous-acknowledgement outcomes; lookup/journal/fresh-Woo-read disagreement;
non-InnoDB journal; duplicate/missing/malformed metadata; hook DB versus
filesystem boundary; Undo observation and actual ABA limitation.

### Maintainer self-audit

- [x] #107 calculator, selector, eligibility, immutable hash and precondition reused.
- [x] Execution consumes absolute strings; no operation recalculation or direct Woo price SQL.
- [x] Public Woo CRUD; original connection ownership and independent observer tested.
- [x] Cache counterexample reproduced; targeted recovery and actual Redis tested.
- [x] Precommit kill never exposes APPLIED; postcommit recovery never saves again.
- [x] Duplicate worker, external edit and permission revocation refuse unsafe mutation.
- [x] Ambiguous acknowledgement remains truthful; no blind replay.
- [x] Hook/external/cache-isolation/ABA limits explicitly retained.
- [x] No full Undo, job engine, Action Scheduler architecture or Admin product UI.
- [x] Guard/Doctor/Redirection implementations and their tests unchanged.

Maintained workflows must additionally pass on the exact PR candidate before it
is reported ready. Their results belong to PR checks/artifacts rather than an
unverified static assertion in this file. The A/B fixture interpretation uses
two authentic #107 plans because its single-operation contract cannot produce
80 and 90 from two identical 100 prices in one plan.

## Package audit correction

The initial PR release matrix rejected the journal INSERT's interpolated table
identifier. Journal statements now use WordPress `%i` identifier placeholders;
all values remain prepared. Only the unavoidable custom-table INSERT direct-call
and no-cache style warnings have an exact-line PHPCS rationale. The old Plugin
Check parser and all maintained test requirements remain unchanged. The revised
candidate must rerun focused tests and all CI checks.


## Maintainer plan-identity blocker repair

Audited old head: `fe4abd4957f75c9fd0c46caa224380e83fc5ebf3`.
Live PR #115/base matched; #107 completed; #108/#109 OPEN; previous checks green.
Untracked icons preserved.

Before repair, the concrete collision reproduced on MySQL/MariaDB with default
cache and Redis: P1 applied 100→80; trusted external reset to 100; a new real #107
P2 had a different plan ID and the same material hash. Seed retained only one row;
P2 returned NEEDS_REVIEW/JOURNAL_MISMATCH and zero saves. Reproduction is bounded
lab evidence, not a supported PASS.

Schema 2 uses plan_id/product_id identity, retaining and strictly verifying the
material fingerprint, existing #107 schema/hash versions, JSON and price strings.
APPLIED evidence includes identity as well as fingerprint. New tests cover
sequential same-material instances, exact seed/retry idempotence, same-ID changed
material, each immutable binding column, evidence identity, case-sensitive IDs,
independent concurrent plan claims, and preserved schema-1 history on upgrade.
Existing crash/cache/A-B tests remain intact. #107 files/semantics and the A/B
two-plan interpretation remain unchanged; no #109 implementation.

The exact repaired candidate must pass the full four-variant fixture and all nine
maintained workflows. New-head CI artifacts and PR validation are authoritative;
previous-head workflow success does not certify this repair.

Local repaired matrix: MySQL 8.0.44 and MariaDB 10.11.15 each passed **369 default /
371 Redis assertions** (1,480 total), including unchanged original fault coverage.
The v1 upgrade test uses an isolated, fixture-owned journal table to avoid treating
intentional tamper cases from the preceding cache variant as valid upgrade data.
No previous negative controls were removed or repaired silently.
