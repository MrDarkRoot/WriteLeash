# #57 compatibility doctor (point-in-time evidence)

`CommitCap\Compatibility_Doctor::run($table = null, $budget = null, $db = null, $installer = null)`
returns `overall` and `checks[]`. Each check has `id`, `status` (`PASS`, `FAIL`,
`UNKNOWN`), `required`, `summary`, and `detail`. No presentation layer or grant
modification is part of this service. **Overall PASS requires every required
check to PASS; UNKNOWN never becomes PASS.** FAIL takes precedence over UNKNOWN.

`$db` is the restricted runtime `wpdb` (defaults to the global connection).
`$installer` is a **different, trusted** `wpdb` connection to the same database
endpoint. It is needed to check the existing helper and procedure definitions
with the actual #54 structural verifier, and to inspect installation grants.
Do not give the WordPress runtime DB user installer credentials. If the caller
cannot provide trusted evidence, the required checks stay UNKNOWN. The doctor
does not install objects or change any grant. A table without a budget checks
whether an existing ordinary InnoDB table is ready for *new* policy
installation; a table **with** a budget verifies an installed policy through
both the installer and the restricted runtime. Without a table, the
`target_table` check is explicitly non-required UNKNOWN: the result is a
preflight for infrastructure, not certification of a future target table.
Before using Guard on a real table, rerun with its table name and budget.

## What is inspected

- Loaded WordPress version (reported, no tested WP range claimed); PHP 7.4+;
  single-site state. Multisite and network activation are not validated.
- Exact server `VERSION()` and the **#54** family parser: MySQL 8.0+ or
  MariaDB 10.11+. Only **MySQL 8.0.44** and **MariaDB
  10.11.15-MariaDB-ubu2204** are tested exact fixtures. A different admissible
  build is labelled **WITHIN TARGET RANGE BUT UNTESTED**. Unsupported and
  unreadable versions are FAIL and UNKNOWN.
- `CURRENT_USER()` (matched grant identity) versus `USER()` (connecting
  identity), active database, ready mysqli `wpdb`, default engine (informational)
  and actual target table engine, partition/FK shape, triggers and policy.
  Only InnoDB is supported; no engine conversion is attempted.
- `@@autocommit=1`, clean error state and the same collision-resistant
  transaction-state probe as Guard. An existing transaction is FAIL. The
  doctor never starts, commits or rolls back caller work. It does use its own
  random savepoint inside an existing transaction, then releases it, as #56
  describes. This is a snapshot, not a guarantee the connection stays stable.
- Trusted installer: schema CREATE for helper, schema CREATE ROUTINE for the
  four definer procedures, and applicable TRIGGER for the target (schema TRIGGER
  before an unspecified/new table). Removing an owned trigger requires TRIGGER,
  **not** DROP. No installer auto-fix or grant creation is performed.
- Runtime writer: explicit EXECUTE on exactly the four reviewed procedures,
  no global/schema EXECUTE or unrelated procedure/function EXECUTE; no helper
  writes (including schema-wide grants), no DDL that changes the protected
  table/trigger or routine and no authority to create a bypassing routine in
  another schema. Grant syntax the doctor cannot fully interpret, including
  roles, remains UNKNOWN. A *known dangerous grant* is FAIL even if other
  grants are incomplete. These checks use the runtime account's SHOW GRANTS,
  not a list supplied by the installer; grant text (which may contain secret
  hashes) is never returned in diagnostics. Routine creation is **not** needed
  by the runtime writer. An ordinary WordPress user with schema-wide UPDATE,
  CREATE or ALTER grants is not a restricted runtime account. When a target is
  supplied, the writer must also have SELECT and UPDATE on that exact table.
- Existing infrastructure: absent = FAIL; named object that fails #54 helper,
  routine signature/body/definer verification = FAIL; unavailable trusted
  inspection = UNKNOWN. Existing target policies use the same #54 verifier,
  not a weaker trigger-name-only match. No collision is overwritten.

The supported path is **B. ONLY COOPERATIVE GUARDED-TRANSACTION BOUNDARY IS
VIABLE**. See [ENGINE.md](ENGINE.md) and [GUARD.md](GUARD.md). The #56
counterexample remains: extra EXECUTE on a side-effecting stored function lets
`SELECT function(...)` reset CLOSE→OPEN and commit ten events under a budget of
five on both pinned engines. The doctor must FAIL this grant, but PASS is only
point-in-time grant/object/connection evidence. It cannot prove hostile plugin
code cannot bypass the Guard, that all WordPress jobs are protected, or that a
hosting provider is generically certified. Rerun after DB user/grant changes,
hosting migration, database upgrade, WordPress migration, CommitCap object
changes, and table engine/schema changes. No automatic rerun or monitoring is
implied.

The real-fixture assertions are in `wordpress/tests/doctor/cases.php`, executed
for both pinned servers by `bash wordpress/tests/engine/run.sh` alongside #54
and #56. The #53 feasibility counterexample remains a separate CI regression.
