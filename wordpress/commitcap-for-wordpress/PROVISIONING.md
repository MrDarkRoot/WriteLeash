# CommitCap for WordPress: Deterministic Provisioning, Rotation, and Cleanup Plans

This document codifies the operational lifecycle, plan generation, and hosting segment
analysis for **Gate #84**.

---

## 1. Supported Operational Model

CommitCap for WordPress uses **one shared restricted database account** per site/environment.
Normal WordPress execution never possesses administrative database privileges (`root`/installer),
and installer credentials are never saved in `wp_options` or filesystem manifests
or retained in normal-request PHP memory.

```text
+-------------------------------------------------------------------------------+
| Trusted Operator / Installer (One-time or migration only)                     |
| - Generates deterministic, reviewable provisioning plans offline             |
| - Executes exact DDL (helper table, 5 DEFINER routines, target triggers)     |
| - Executes exact DCL (CREATE USER, exact GRANTs/REVOKEs)                     |
+-------------------------------------------------------------------------------+
                                      |
                                      v
+-------------------------------------------------------------------------------+
| wp-config.php (Protected Server Configuration)                               |
| define('COMMITCAP_DB_USER', 'cc_writer');                                     |
| define('COMMITCAP_DB_PASSWORD', '...');                                       |
| define('COMMITCAP_DB_HOST', 'localhost');                                     |
| define('COMMITCAP_DB_NAME', 'wp_db');                                         |
+-------------------------------------------------------------------------------+
                                      |
                                      v
+-------------------------------------------------------------------------------+
| Normal Web Request (WordPress Admin & Scheduled Jobs)                         |
| - Restricted runtime wpdb connection only                                     |
| - EXECUTE on exactly 5 DEFINER routines (open/close/count/policy/attest)      |
| - Read-only SELECT on commitcap_v01_state (unmediated accounting evidence)    |
| - SELECT, UPDATE on exact declared target tables only                         |
| - NO helper INSERT/UPDATE/DELETE, runtime DDL/TRIGGER/GRANT authority       |
| - Guard-owned cooperative UPDATE transactions within logical budget L <= P   |
| - Doctor::runtime() point-in-time verification via cross-attesting routines   |
+-------------------------------------------------------------------------------+
```

---

## 2. Deterministic Plan Renderer (`CommitCap\Provisioning_Plan`)

All database modifications are generated as inspectable, version-pinned SQL plans before execution.

### Principles:
1. **Offline Reviewability**: Plans output structured statement metadata and human-readable SQL scripts.
2. **Secret Redaction**: Passwords/secrets are masked by default (`[REDACTED_SECRET]`) in all logs, CLI outputs, and Doctor reports. Credential statements never appear in exception messages, apply results, or host error logging (`DCL` execution is wrapped in `suppress_errors`); failures report the step id and a withheld-SQL marker instead.
3. **Application Table Preservation**: Target removal and plugin uninstallation **NEVER drop application tables or delete existing application rows**. Only CommitCap-owned triggers, procedures, helper state, and runtime accounts are touched.
4. **Conflict Refusal**: Apply runs a pre-flight before any statement. A foreign helper shape, a CommitCap-named routine with a foreign body, an existing runtime account with grants outside the reviewed surface, a foreign trigger (including one that merely reuses the expected trigger name), or a non-canonical object during remove/uninstall refuses the plan. Nothing foreign is dropped, replaced or revoked.
5. **No Retained Installer Secrets**: The apply phase runs through a temporary installer connection provided by the operator; credentials are discarded immediately.
6. **Deterministic Replay**: DDL/DCL is auto-commit. Install/add/remove/uninstall can stop halfway and need trusted replay and verification. A rotated credential with a failed drain is **not** fixed by Doctor alone: use the standalone drain under the new secret, then independently verify. Never re-run an old-secret plan as a substitute for draining.

---

## 3. Plan Actions & SQL Templates

### 3.1 Install (`Provisioning_Plan::install`)
1. Create restricted user:
   ```sql
   CREATE USER IF NOT EXISTS 'cc_writer'@'localhost' IDENTIFIED BY '[REDACTED_SECRET]';
   ```
2. Create helper state table:
   ```sql
   CREATE TABLE IF NOT EXISTS `wp_db`.`commitcap_v01_state` (
     connection_id BIGINT UNSIGNED NOT NULL,
     policy_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
     consumed BIGINT UNSIGNED NOT NULL,
     PRIMARY KEY (connection_id, policy_id)
   ) ENGINE=InnoDB COMMENT='CommitCap V0.1 cooperative UPDATE state';
   ```
3. Create 5 `SQL SECURITY DEFINER` routines:
   - `commitcap_v01_open(IN p_policy CHAR(64))`
   - `commitcap_v01_close(IN p_policy CHAR(64))`
   - `commitcap_v01_count(IN p_policy CHAR(64), OUT p_count BIGINT UNSIGNED)`
   - `commitcap_v01_policy(IN p_table VARCHAR(64), IN p_trigger VARCHAR(64))`
   - `commitcap_v01_attest()` (cross-attesting live routine bodies)
4. Revoke inherited or broad privileges:
   ```sql
   REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'cc_writer'@'localhost';
   ```
5. Grant explicit `EXECUTE` on the 5 routines plus the reviewed read-only helper state grant:
   ```sql
   GRANT EXECUTE ON PROCEDURE `wp_db`.`commitcap_v01_open` TO 'cc_writer'@'localhost';
   GRANT EXECUTE ON PROCEDURE `wp_db`.`commitcap_v01_close` TO 'cc_writer'@'localhost';
   GRANT EXECUTE ON PROCEDURE `wp_db`.`commitcap_v01_count` TO 'cc_writer'@'localhost';
   GRANT EXECUTE ON PROCEDURE `wp_db`.`commitcap_v01_policy` TO 'cc_writer'@'localhost';
   GRANT EXECUTE ON PROCEDURE `wp_db`.`commitcap_v01_attest` TO 'cc_writer'@'localhost';
   GRANT SELECT ON `wp_db`.`commitcap_v01_state` TO 'cc_writer'@'localhost';
   ```
   **Exact runtime surface:** EXECUTE on exactly open, close, count, policy,
   attest; SELECT only on `commitcap_v01_state` (no helper
   INSERT/UPDATE/DELETE); SELECT/UPDATE only on certified target tables; no
   runtime DDL, TRIGGER or GRANT authority. Guard decides logical L by reading
   helper state directly and Doctor's behavioral probes independently read it.
   Routine bodies are not load-bearing for logical-budget correctness. This
   five-routine + helper-SELECT change from the four-routine #84 issue is a
   Maintainer architecture decision at PR acceptance.

### 3.2 Add Target (`Provisioning_Plan::add_target`)
1. Grant minimal table rights:
   ```sql
   GRANT SELECT, UPDATE ON `wp_db`.`target_table` TO 'cc_writer'@'localhost';
   ```
   These are the **only** target privileges the runtime may hold. The #78
   descriptor readiness evaluates the effective privilege set (table,
   schema-wide and global scope) and reports `NOT_READY` /
   `target_privileges_mismatch` for any extra target privilege, so scheduled
   credential drift such as an added `INSERT` or `DELETE` fails closed without
   broadening or weakening the reviewed boundary.
2. Create the identity-scoped physical ceiling `BEFORE UPDATE` trigger:
   ```sql
   CREATE TRIGGER `wp_db`.`commitcap_v01_<hash>` BEFORE UPDATE ON `wp_db`.`target_table`
   FOR EACH ROW
   BEGIN
     IF LOWER(SUBSTRING_INDEX(USER(), '@', 1)) = LOWER('cc_writer') THEN
       UPDATE commitcap_v01_state
          SET consumed = consumed + 1
        WHERE connection_id = CONNECTION_ID()
          AND policy_id = '<policy_hash>'
          AND consumed < <physical_ceiling>;
       IF ROW_COUNT() != 1 THEN
         SET @commitcap_v01_denied = 1;
         SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 1644, MESSAGE_TEXT = 'CC54_DENIED';
       END IF;
     END IF;
   END;
   ```
   The `USER()` condition scopes enforcement to the certified runtime username
   (proved on both pinned engines; a trigger's `CURRENT_USER()` is the DEFINER
   and was rejected). Normal WordPress/plugin writers keep their ordinary table
   behavior, including the Redirection item-scoped, single-item and hit/stat
   writers. The whole condition is part of the verified canonical body and the
   runtime Doctor derives the expected username from the live restricted
   connection.
   **Runtime identity invariant:** the certified username must map to exactly
   one `mysql.user` row with the plan's host. `install` may create it; but
   `add_target`, `remove_target`, `uninstall`, `rotate_credential` and `drain`
   read `mysql.user` first and refuse before any policy trigger or target grant
   mutation when another Host row for the same username exists. A username
   collision must be resolved by the operator, then the same plan retries
   deterministically. `#84.6c` proves refusal, no trigger/grant mutation,
   explicit Doctor ambiguity FAIL and the successful retry after removal.

### 3.3 Remove Target (`Provisioning_Plan::remove_target`)
1. Drop canonical trigger:
   ```sql
   DROP TRIGGER IF EXISTS `wp_db`.`commitcap_v01_<hash>`;
   ```
2. Revoke table rights:
   ```sql
   REVOKE SELECT, UPDATE ON `wp_db`.`target_table` FROM 'cc_writer'@'localhost';
   ```
*(Application table `target_table` and its data rows remain untouched).*

### 3.4 Rotate Credential (`Provisioning_Plan::rotate_credential`)
**Required state machine (operator maintenance window; no concurrent account DCL
or new runtime sessions until verification):**

1. `PRECHECK`: before `ALTER USER`, **every** rotation - including
   `rotate_credential(..., false)` - first proves the runtime identity is
   unambiguous: permission to inspect `mysql.user` and exactly **one** row with
   matching username AND target host. When `user@%` and `user@localhost` coexist,
   refuse before ALTER: `PROCESSLIST.HOST` is the client origin, not the matched
   account host. Similar usernames are not included. `#84.6d` proves a no-drain
   rotation refusal preserves the credential hash, the surviving session, the
   trigger body, helper state and the absence of a rotation marker, and that the
   retry after removing the collision needs no trigger DDL.
   With `drain=true`, the precheck additionally proves the pinned engine and the
   drain authority: global `PROCESS` (complete processlist visibility) AND
   other-user KILL authority: global `CONNECTION_ADMIN` or `SUPER` on MySQL
   8.0.44; global `CONNECTION ADMIN` or `SUPER` on MariaDB 10.11.15.
   Root/admin-equivalent also passes. `PROCESS` alone does not authorize KILL; a
   KILL-only grant cannot prove visibility. The drain test matrix executes KILL
   against another user's session and checks both preflight refusal and actual
   drain on both pinned engines. Unknown server builds fail closed.
   Apply records a durable `ROTATED_UNSAFE` marker **before** ALTER in the
   reviewed helper state table (`connection_id=0`, SHA-256 policy marker,
   `consumed=1`), using the **trusted installer** connection only. If that DML
   fails, ALTER is refused. The restricted runtime retains helper SELECT only.
2. `ROTATED`: change database password:
   ```sql
   ALTER USER 'cc_writer'@'localhost' IDENTIFIED BY '[REDACTED_SECRET]';
   ```
   Password-only rotation keeps the authenticated username unchanged, so the
   identity-scoped policy triggers stay canonical and **no trigger DDL is
   required**. `#84.8` asserts the trigger body is byte-identical before and
   after rotation and that the rotated account is still physically enforced.
3. Update `wp-config.php` with the V2 secret while enablement is held:
   ```php
   define( 'COMMITCAP_DB_PASSWORD', '<new_secret>' );
   ```
4. `DRAINING` → `DRAINED`: terminate surviving sessions. `ALTER USER` does not
   terminate already-authenticated sessions on MySQL 8.0.44 or MariaDB
   10.11.15 (proved by `#84.8`: a connection opened before the rotation keeps
   executing SQL). The plan includes a `DRAIN` step that lists
   `information_schema.PROCESSLIST` for the uniquely mapped runtime username
   on the trusted installer connection, executes `KILL <id>` for each surviving
   session, and verifies zero remain. Rechecks the unique account mapping during
   drain. Only after that does the trusted installer delete the unsafe marker.
   If the precheck fails, old credentials and all existing sessions remain
   untouched. `rotate_credential(..., false)` is diagnostic-only; its result is
   `success=false, state=ROTATED_UNSAFE`, never a supported completed rotation.
5. `VERIFIED` (operator gate): new connections with the old secret fail; all
   previously authenticated session IDs are gone; V2 connection succeeds;
   `Doctor::runtime()` is PASS and Guard still enforces. The plan returns
   `DRAINED` after server-side zero-session verification, **not** `VERIFIED`.

If `ALTER USER` succeeds but `KILL` fails, apply throws `ROTATED_UNSAFE` and
explicitly warns that old authenticated sessions may still exist. Do not enable
protected work based solely on V2 authentication: Doctor reads the unsafe marker
and returns FAIL, and Guard refuses before the callback. Doctor cannot inventory
other accounts' sessions without privileged credentials; the trusted marker is
the only durable safety signal across requests. A raw direct `ALTER USER` outside
this plan bypasses this protocol and cannot be certified as a supported rotation.
Keep runtime traffic paused; retain the V2 config secret; re-run
`Provisioning_Plan::drain(user, host)` with a qualified operator after correcting
the failure; require zero remaining sessions, then perform the `VERIFIED` checks.
An absent marker means no *plan-recorded* unsafe rotation; it is not proof that
no external administrator ever changed credentials directly.
If the account host collision persists, remove/resolve it with the account owner
before rerunning drain; do not kill by username across that collision. No
automatic transactional rollback of `ALTER USER` is possible.

The installer must have both global drain privileges, `SELECT` on `mysql.user`
for the exact account inventory, `SELECT/INSERT/UPDATE/DELETE` on the CommitCap helper
for the durable marker, and `ALTER USER` authority. These are operator
privileges, never runtime grants. An outage, racing account DCL, or processlist
visibility loss suspends enablement until a fresh drain and verification.

### 3.5 Canonical pre-flight privileges
`apply()` reads object definitions to refuse foreign collisions. On MySQL 8.0
that requires `SHOW_ROUTINE` (definitions are NULL otherwise); on MariaDB 10.11
it requires `SELECT` on the `mysql` schema. `SHOW GRANTS FOR` other accounts
requires `SELECT` on the `mysql` schema. `root` satisfies all of these.

### 3.6 Uninstall (`Provisioning_Plan::uninstall`)
1. Drop triggers on all active target tables (only canonical CommitCap triggers;
   a foreign-bodied expected-name trigger refuses the plan).
2. Drop the 5 stored procedures (only canonical bodies; foreign bodies refuse).
3. Drop helper table `commitcap_v01_state`.
4. Revoke all remaining privileges from `cc_writer`.
5. Drop user `cc_writer`.
*(Application tables and data rows are NEVER dropped).*

---

## 4. Hosting Segment Feasibility Matrix

Mapping the complete recipe (routine EXECUTE, exact table grants, trigger/routine DDL, account/DEFINER, rotation) across mainstream WordPress hosting segments:

| Hosting Segment | Self-Service DB User Creation | Routine & Trigger DDL Support | Feasibility Classification | Operational Reality |
| :--- | :--- | :--- | :--- | :--- |
| **Self-Managed / VPS / Dedicated** (DigitalOcean, AWS EC2, Linode, Hetzner) | YES (full root/DBA access) | YES (MySQL 8.0+ / MariaDB 10.11+) | **SUPPORTED (Fully Self-Service)** | Administrator/Operator runs rendered plan via mysql CLI or Ansible/script. Complete independence. |
| **cPanel / WHM Shared/Reseller** | YES (via cPanel MySQL Databases UI) | CONDITIONAL (requires `TRIGGER` & `CREATE ROUTINE` privileges; binary logging often requires `log_bin_trust_function_creators=1` set in my.cnf by host) | **SUPPORTED WITH OPERATOR/HOST SETUP** | Standard cPanel users can create DB users and assign privileges. Triggers/routines can be imported via phpMyAdmin if host enables function creators. If host restricts triggers, support ticket is required. |
| **Plesk Web Admin / Pro** | YES (via Plesk Database Users UI) | YES (via Plesk phpMyAdmin or SSH with subscription user rights) | **SUPPORTED WITH OPERATOR/HOST SETUP** | Subscription administrators can provision secondary users and apply routines/triggers. |
| **Managed WordPress** (WP Engine, Kinsta, Pressable, WordPress.com VIP) | NO (Single database user auto-provisioned per site environment; secondary database users not exposed in customer portal) | RESTRICTED (Stored procedures and triggers are disabled or blocked by default managed security rules) | **UNKNOWN / NO DOCUMENTED SELF-SERVICE** | Standard customer self-service cannot create secondary restricted DB users or install custom DEFINER routines. Deploying CommitCap in this segment requires host partner cooperation or a dedicated/custom database tier. |

---

## 5. Persona Step Count Matrix

The required steps across roles for each lifecycle event:

| Lifecycle Action | Operator Steps (DBA / Host / DevOps) | Host / Config Steps (`wp-config.php`) | Admin Steps (Site Owner in WP) | Total Steps |
| :--- | :---: | :---: | :---: | :---: |
| **Initial Setup / Install** | 2 (review rendered SQL, apply via installer) | 1 (add `COMMITCAP_DB_*` constants) | 1 (verify Doctor READY in WP) | **4** |
| **Add Target Operation** | 2 (review target SQL plan, apply DDL/DCL) | 0 | 1 (run Doctor verify, choose logical budget $L$) | **3** |
| **Adjust Budget ($L \le P$)** | **0 (No DDL needed! Proved by Gate #82)** | **0** | **1 (select logical budget in WP)** | **1** |
| **Rotate Credentials** | 1 (execute `ALTER USER` + supported session-drain plan) | 1 (update secret in `wp-config.php`) | 1 (verify Doctor READY) | **3** |
| **Remove Target Policy** | 2 (review removal plan, apply drop trigger & revoke) | 0 | 0 (or disable in plugin settings) | **2** |
| **Uninstall / Cleanup** | 2 (review uninstall plan, apply cleanup) | 1 (remove constants from config) | 1 (uninstall plugin in WP admin) | **4** |

### Key Product Implication for Admin:
- **Zero SQL for Admin**: The WordPress Admin / Site owner never writes or sees SQL.
- **Routine Budget Adjustments**: Because Gate #82 proved $0 \le L \le P$ logical budgeting below the physical trigger ceiling, **normal day-to-day budget adjustments require 0 Operator/DBA steps and 0 DDL statements**.
- **Resource limit remains open**: logical $L$ is a semantic rollback bound, not
  an early execution/resource bound. When $P \gg L$, a broad statement can run
  $O(P)$ row events (including helper writes and locks) before Guard refuses
  COMMIT. The #82 CI medians on MySQL 8.0.44 were ~130.9 ms at 2k, ~619.9 ms
  at 10k and ~3176.1 ms at 50k (~62–65 ms / 1k row events). These figures
  describe that CI run, not a throughput guarantee. Choosing acceptable P and
  operational resource limits remains a Maintainer product decision.
- **One-time Operator Assistance**: Initial setup and adding certified integrations require operator/host assistance on managed hosting.
