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

- Loaded WordPress version: **6.8.3** is the only integrated TESTED exact
  fixture and is the only version that can PASS. Any other observable version
  is UNKNOWN (observability is not compatibility), and an unavailable version
  is UNKNOWN. Because `wordpress` is a required check, a non-fixture version
  blocks overall PASS. #62 owns the wider WordPress matrix; untested is never
  reported as FAIL. PHP 7.4+; single-site state. Multisite and network
  activation are not validated.
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
  five definer procedures, and applicable TRIGGER for the target (schema TRIGGER
  before an unspecified/new table). Removing an owned trigger requires TRIGGER,
  **not** DROP. No installer auto-fix or grant creation is performed.
- Runtime writer: explicit EXECUTE on exactly the five reviewed procedures and
  the reviewed **read-only** `SELECT` on the helper state table (the unmediated
  accounting read used by Guard and the runtime probes); no global/schema
  EXECUTE or unrelated procedure/function EXECUTE; no helper writes (including
  schema-wide grants), no DDL that changes the protected
  table/trigger or routine and no authority to create a bypassing routine in
  another schema. **TRIGGER authority on any schema is FAIL**: a trigger is
  server-side code on the guarded connection that the lexical monitor cannot
  observe, and the runtime writer never needs it. Grant syntax the doctor
  cannot fully interpret, including roles, remains UNKNOWN. A *known dangerous
  grant* is FAIL even if other grants are incomplete. These checks use the
  runtime account's SHOW GRANTS, not a list supplied by the installer; grant
  text (which may contain secret hashes) is never returned in diagnostics.
  Routine creation is **not** needed by the runtime writer. An ordinary
  WordPress user with schema-wide UPDATE, CREATE or ALTER grants is not a
  restricted runtime account.
  The exact grant surface is EXECUTE on open/close/count/policy/attest, SELECT
  **only** on `commitcap_v01_state` (no helper INSERT/UPDATE/DELETE), and
  SELECT/UPDATE only on certified target tables. No runtime DDL/TRIGGER/GRANT
  authority. The helper SELECT is necessary because Guard makes the logical
  pre-COMMIT decision from direct state and Doctor's behavioral probes inspect
  the same state independently; routine bodies are not load-bearing for the
  logical budget. This replaces the older four-EXECUTE issue wording and needs
  Maintainer approval as a security-contract change.
- Identity-scoped physical policy: the canonical target trigger enforces only
  the certified runtime username, derived from `USER()` (the authenticated
  session identity). A trigger's `CURRENT_USER()` reports the trigger DEFINER
  instead, which is why it is rejected as an invocation identity; both facts
  were proved on the pinned engines. `runtime_ceiling()` reads the live
  `SELECT SUBSTRING_INDEX(USER(), '@', 1)` on the restricted connection and
  requires the trigger body to embed exactly that username, so a trigger for a
  different identity, with the condition removed, broadened, or accepting a
  foreign principal is FAIL. Normal WordPress/plugin writers outside that
  username are not intercepted by the policy.
- Runtime opaque trigger surface (required): a non-target INSERT/UPDATE/DELETE
  grant can fire an **existing** trigger without any TRIGGER privilege. The
  trusted installer inspects `information_schema.TRIGGERS` for every
  non-target runtime write scope (another schema included). No triggers =
  PASS; any trigger, an unreadable graph, pattern/role grant scopes, or no
  trusted installer = UNKNOWN. The target's own policy trigger is verified
  separately by #54. This is point-in-time: a trigger created later requires a
  rerun.
- When a target is supplied, the writer must also have SELECT and UPDATE on
  that exact table.
- Existing infrastructure: absent = FAIL; named object that fails #54 helper,
  routine signature/body/definer verification = FAIL; unavailable trusted
  inspection = UNKNOWN. Existing target policies use the same #54 verifier,
  not a weaker trigger-name-only match. No collision is overwritten.

The supported path is **B. ONLY COOPERATIVE GUARDED-TRANSACTION BOUNDARY IS
VIABLE**. See [ENGINE.md](ENGINE.md) and [GUARD.md](GUARD.md). The #56
counterexample remains: extra EXECUTE on a side-effecting stored function lets
`SELECT function(...)` reset CLOSE→OPEN and commit ten events under a budget of
five on both pinned engines. A second retained counterexample is the
cross-schema trigger: `wordpress/tests/doctor/cases.php` creates a writable
sidecar table whose trigger `CALL`s `commitcap_v01_close`/`open`, invokes it
through an ordinary sidecar `UPDATE` inside `Guard::update(..., 5, ...)`, and
proves ten durable target events. On MariaDB 10.11.15 the restricted writer can
create that trigger itself (log_bin=0). On MySQL 8.0.44 the same writer is
refused `CREATE TRIGGER` by the pinned binary-logging rule (error 1419,
non-SUPER), so the fixture creates the trigger as the trusted installer and the
writer activates it with only `SELECT, UPDATE` on the sidecar schema. The
doctor must FAIL runtime TRIGGER authority and cannot PASS a non-target
writable object with any trigger. PASS is only point-in-time
grant/object/connection evidence. It cannot prove hostile plugin code cannot
bypass the Guard, that all WordPress jobs are protected, that a trigger is
never created after the check, or that a hosting provider is generically
certified. Rerun after DB user/grant changes, hosting migration, database
upgrade, WordPress migration, CommitCap object changes, and table
engine/schema/trigger changes. No automatic rerun or monitoring is implied.

The real-fixture assertions are in `wordpress/tests/doctor/cases.php`, executed
for both pinned servers by `bash wordpress/tests/engine/run.sh` alongside #54
and #56. The #53 feasibility counterexample remains a separate CI regression.

## Gate #83: Shared-Runtime Doctor (No Retained Installer Credentials)

`CommitCap\Compatibility_Doctor::runtime($policies, ?\wpdb $db = null)`
provides point-in-time shared runtime verification for normal web requests where
**no installer credentials exist in PHP memory or `wp-config.php`**.

### 1. Evidence Channel Evaluation
- **Signed manifest / `wp_options`**: Disqualified. Options and local files are mutable by WordPress plugins, can fall out of sync with actual database state, and cannot detect database-level tampering or drift.
- **Dedicated metadata table**: Disqualified. A state table duplicates `information_schema` data, requires synchronization DDL, and risks silent desynchronization from the real DB schema.
- **One `SQL SECURITY DEFINER` evidence procedure**: Insufficient. A single reporter that only returned counts/signatures and whose own body was never checked could not distinguish a replaced body (proved by adversarial test: the previous Doctor PASSed a body-only tampered `commitcap_v01_count`).
- **Two mutually cross-attesting `SQL SECURITY DEFINER` procedures plus unmediated metadata**: **Adopted** (`commitcap_v01_policy` and `commitcap_v01_attest`). Each reports the live `ROUTINE_DEFINITION` of all five reviewed routines. A body replaced in any single object is reported by the other canonical object and compared against the canonical body shipped in the plugin.

### 2. Runtime evidence trust root (exact)
The runtime does not trust any single routine. `runtime_attestation()` combines:
1. **Cross-attestation**: `commitcap_v01_policy` and `commitcap_v01_attest` each return the live body of every reviewed routine (including the other evidence routine). Each body must normalize-equal both the other report and the canonical body in `Update_Engine::routines()`. All five must be `SQL SECURITY DEFINER` with one shared DEFINER.
2. **Unmediated `information_schema` metadata**: the restricted account reads `ROUTINES.SECURITY_TYPE/DEFINER/CREATED/LAST_ALTERED` itself (definitions are NULL for it). Reports must match these rows, and all five `CREATED` values must fall inside one install batch (10 s tolerance) — replacing a body changes `CREATED`, and on MySQL `DROP`+`CREATE` also removes the runtime's EXECUTE grant.
3. **Unmediated grant evidence**: `SHOW GRANTS` must show exactly the reviewed surface: EXECUTE on the five procedures and read-only SELECT on `commitcap_v01_state`.
4. **Unmediated helper shape**: `information_schema` table/column/index/trigger reads prove the helper table shape with no routine involved.
5. **Behavioral probes**: the runtime calls `open`, reads the helper row directly, calls `count` and compares with the direct read, sets the denial signal and requires `close` to reject, then closes and requires the row gone. `runtime_trigger_probe()` opens accounting on a target, issues one **data-preserving no-op `UPDATE ... SET col = col LIMIT 1`** (the pinned engines fire `BEFORE UPDATE` triggers for it), and requires the direct helper read and `count` to both report exactly one event before rolling back.

`commitcap_v01_policy` therefore attests the live bodies of all five routines; it is not trusted by its own report — its body is attested by `commitcap_v01_attest` and vice versa, and a replacement of either changes `CREATED` and (on MySQL) drops its EXECUTE grant. **UNKNOWN is never upgraded to PASS.**

**Residual (explicit):** a principal that can coherently replace *both* evidence routines *and* the enforcement objects (installer-equivalent / full DB control) is not distinguishable by any database-resident check without an external secret. The Doctor's checks are single-object-tamper proof; against an installer-equivalent adversary the operator must re-run `Doctor::run()` with trusted credentials. This is the documented boundary, not a PASS claim.

### 3. Guard is not dependent on routine bodies
`Guard::update()` reads the accounting state for the stale pre-check, the logical pre-COMMIT decision, denial attribution and the post-COMMIT state check **directly from `commitcap_v01_state`** (reviewed SELECT grant). A replaced `commitcap_v01_count` body cannot cause an over-budget COMMIT; it is detected by the Doctor and the direct-state denial still fires and rolls back (tested as `#83.10`).

### 4. Multi-Policy Sibling Recognition
When multiple code integrations share the same restricted database connection, passing an array of known policies (e.g. `array('table_a' => 10, 'table_b' => 20)`) allows the Doctor to:
- Inspect and verify each target policy's physical ceiling and trigger template.
- Validate that logical budgets $L$ satisfy $0 \le L \le P$ for each integration.
- Exclude verified sibling tables from being classified as unreviewed "foreign" trigger surfaces.

### 5. Shared Reachability Invariant
When integrations share a restricted database connection, they share a single transaction boundary:
- If **any** writable sibling table has a corrupted, missing, or tampered trigger, or lacks proper grants, writes to that sibling during a transaction can bypass CommitCap accounting.
- **Invariant**: Any corrupted or failing sibling integration forces all otherwise valid sibling integrations on the shared connection to fail closed to `UNKNOWN`, and forces the aggregate status to `DEGRADED`. A shared runtime can NEVER report `PASS` for integration A if sibling B is corrupt.

### 6. Adversarial evidence (both pinned engines)
`wordpress/tests/doctor/test-83-shared-runtime-doctor.php` executes body-only tampers
with identical name, parameters, `SQL SECURITY DEFINER` and apparent object counts
(the tampered body keeps the canonical statement shape and changes one behavioral
token; EXECUTE is re-granted to simulate an installer-capable attacker):
`open`, `close`, `count`, `policy`, `attest`, plus signature-level tamper,
target-trigger tamper, dropped trigger, ceiling mismatch, sibling corruption,
foreign writable triggered object and altered grants. Every tamper leaves the
aggregate not-PASS and forces sibling integrations to `UNKNOWN`; restoration
returns to PASS.

`#83.12` additionally tampers the target trigger's runtime-identity condition
with the exact canonical signature and structure: wrong runtime username,
condition removed (`1 = 1`), broadened (`OR 1 = 1`), and an extra accepted
principal (`IN (..., 'foreign')`). All four fail Doctor and integration status;
the canonical restore returns to PASS. `#54` runs the same identity-condition
mutations through the trusted `verify_policy()` and restricted
`verify_runtime_policy()` verifiers.

### 7. Normal Web Request vs. Operator Verification
- **Normal Web Request**: `Doctor::runtime()` runs with only the restricted `$writer` connection in `$wpdb`. Installer credentials are never stored, parsed, or retained in PHP.
- **Operator Verification**: `Doctor::run()` is invoked only during explicit setup, migration, or auditing by an administrator with a separate, temporary installer connection. Full grant listings and structural verifiers are evaluated directly.

**Credential rotation qualification:** Doctor checks the trusted helper-state
`ROTATED_UNSAFE` marker before claiming PASS, and Guard refuses a protected
callback while it exists. Doctor does not inventory surviving V1 sessions; the
trusted installer marks the rotation before ALTER and clears it only after a
zero-session drain. Only a trusted standalone drain followed by V2 Doctor and
Guard checks qualifies the operator-verified READY state. Direct external
credential changes outside the plan cannot be certified by this protocol. See
[PROVISIONING.md](PROVISIONING.md#34-rotate-credential-commitcapprovisioning_planrotate_credential).
