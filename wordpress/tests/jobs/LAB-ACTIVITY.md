# #109 lab activity

Base: `e62446f977a05dbbab4f4118a4a1c8094cf67e09`.
Branch: `free/109-durable-job-engine`.
Live verification: #106/#109/#110 OPEN; #107/#108 CLOSED/completed;
PR #114 and PR #115 MERGED into the recorded base. No existing untracked icons changed.

## Reproduction

Run `bash wordpress/tests/jobs/run.sh`. Checksummed official Woo/Redis/Redirection
artifacts are downloaded into ignored fixture caches. Digest-pinned disposable
MySQL, MariaDB and Redis containers run the real WordPress/Woo plugin. The runner
tears down its own named compose project, including on failure. It does not use
production sites, external webhooks or Guard credentials.

Workflow: **WordPress Woo durable job engine**. The `woo-jobs-109` artifact
contains the complete setup, lab markers and failure output. The CI run SHA is
the authority for candidate results. This file and the fixture scripts are
excluded from the distribution allowlist.

## Development activity

- Designed the durable schema, lease/fence model and #108 journal ownership
  before implementation; kept Action Scheduler as wake-up only.
- Found WordPress 7.1.2 changed missing-nonce handling from a 403
  `rest_cookie_invalid_nonce` to an unauthenticated downgrade; the manual-resume
  negative tests assert the real behavior (401 from the permission callback,
  wrong nonce still 403).
- Found Action Scheduler 4.0.0 `as_unschedule_all_actions($hook, array(),
  $group)` does not wildcard empty args; deactivation now cancels through the
  store's group-scoped `cancel_actions_by_group()`.
- Found `renew_lease()` reported a false negative when an update within the same
  second changed no column value; ownership is now confirmed by a fence read
  instead of affected rows.
- Found MySQL/MariaDB cache connection privileges, so the no-DDL host test uses
  a restricted account with no `CREATE` privilege rather than in-place REVOKE.
- Added lazy idempotent setup (`ensure_schema`) instead of activation-time DDL
  so the #58 activation-table snapshot invariant is preserved.
- `Change_Plan` gained a trusted `hydrate()` rehydration factory plus a shared
  summary helper; the #107 unit oracle and the whole #108 durable matrix still
  pass unchanged after the refactor.
- Plugin Check 2.1.0 against the staged plugin reports zero unreviewed
  findings.

## Final local candidate results

The local equivalent of the container entrypoint (WordPress 7.1.2, PHP 8.2,
WooCommerce 11.1.2, MariaDB 10.11.15, default cache, `wp eval-file
tests/jobs/integration.php`) exited **0** with **662 assertions** in 99 seconds
and zero WriteLeash-originated `WP_DEBUG` diagnostics; `no-replan-audit.php`
passed. The #108 durable matrix (369 assertions) still exits 0 with the same
`Change_Plan` source.

Covered: schema fresh/replay/unknown/partial/wrong-engine/no-DDL host; 17-item
mixed E2E (12 applied, 1 conflict, 2 unchanged, 2 unsupported, never generic
success); browser-close request exit with process-level scheduler callback;
20-item bounded batching; duplicate/concurrent callbacks; stale lease takeover
with `processed = 0` and one save per product; real SIGKILL after COMMIT with
journal reconciliation and no Woo replay; controlled exception between items;
scheduler failure; Woo dependency loss and reactivation; REST resume security
negatives; permission revocation and actor deletion; multi-job expected-old
conflicts including identical targets and parallel isolation; counter
tamper/repair; pagination; exhaustive job/item transition tables; direct DB
tampering; cancel; deactivation; timestamps.

## Final CI candidate results

Workflow **WordPress Woo durable job engine** run `36716163468` at commit
`01fd505` exited 0: MySQL 8.0.44 and MariaDB 10.11.15, each with default and
Redis 7.4.2 persistent object cache, **663 assertions per variant**, ending in
`#109 durable job engine two-engine/default/Redis gate: PASS`. The `woo-jobs-109`
artifact contains the full log. The sibling `woo-durable-journal` (#108, 369
assertions), `woo-plan-107` (#107), `wordpress-foundation` and
`redirection-adapter` gates are green on the same commit.

`wordpress-v01-release-matrix` fails at the existing `#61 await_overlap(update)
timed out` step; the same failure is present on `main` at the #115 merge commit
before this branch, so it is unrelated to #109. All #60 lifecycle, uninstall and
Plugin Check steps inside that suite pass for this change.
