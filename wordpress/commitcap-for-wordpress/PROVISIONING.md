# CommitCap for WordPress: Deterministic Provisioning, Rotation, and Cleanup Plans

This document codifies the operational lifecycle, plan generation, and hosting segment
analysis for **Gate #84**.

---

## 1. Supported Operational Model

CommitCap for WordPress uses **one shared restricted database account** per site/environment.
Normal WordPress execution never possesses administrative database privileges (`root`/installer),
and installer credentials are never saved in `wp_options`, filesystem manifests, or PHP memory.

```text
+-------------------------------------------------------------------------------+
| Trusted Operator / Installer (One-time or migration only)                     |
| - Generates deterministic, reviewable provisioning plans offline             |
| - Executes exact DDL (helper table, 4 DEFINER routines, target triggers)     |
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
| - EXECUTE on exactly 4 DEFINER routines (commitcap_v01_open/close/count/policy)|
| - SELECT, UPDATE on exact declared target tables only                         |
| - NO TRIGGER, NO CREATE ROUTINE, NO helper DML, NO broad grants              |
| - Guard-owned cooperative UPDATE transactions within logical budget L <= P   |
| - Doctor::runtime() point-in-time verification via DEFINER routine           |
+-------------------------------------------------------------------------------+
```

---

## 2. Deterministic Plan Renderer (`CommitCap\Provisioning_Plan`)

All database modifications are generated as inspectable, version-pinned SQL plans before execution.

### Principles:
1. **Offline Reviewability**: Plans output structured statement metadata and human-readable SQL scripts.
2. **Secret Redaction**: Passwords/secrets are masked by default (`[REDACTED_SECRET]`) in all logs, CLI outputs, and Doctor reports.
3. **Application Table Preservation**: Target removal and plugin uninstallation **NEVER drop application tables or delete existing application rows**. Only CommitCap-owned triggers, procedures, helper state, and runtime accounts are touched.
4. **Conflict Refusal**: Attempting to add a target table that has an unreviewed foreign trigger, is not InnoDB, or has foreign keys/partitions is refused before any DDL is applied.
5. **No Retained Installer Secrets**: The apply phase runs through a temporary installer connection provided by the operator; credentials are discarded immediately.

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
3. Create 4 `SQL SECURITY DEFINER` routines:
   - `commitcap_v01_open(IN p_policy CHAR(64))`
   - `commitcap_v01_close(IN p_policy CHAR(64))`
   - `commitcap_v01_count(IN p_policy CHAR(64), OUT p_count BIGINT UNSIGNED)`
   - `commitcap_v01_policy(IN p_table VARCHAR(64), IN p_trigger VARCHAR(64))`
4. Revoke inherited or broad privileges:
   ```sql
   REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'cc_writer'@'localhost';
   ```
5. Grant explicit `EXECUTE` on the 4 routines:
   ```sql
   GRANT EXECUTE ON PROCEDURE `wp_db`.`commitcap_v01_open` TO 'cc_writer'@'localhost';
   GRANT EXECUTE ON PROCEDURE `wp_db`.`commitcap_v01_close` TO 'cc_writer'@'localhost';
   GRANT EXECUTE ON PROCEDURE `wp_db`.`commitcap_v01_count` TO 'cc_writer'@'localhost';
   GRANT EXECUTE ON PROCEDURE `wp_db`.`commitcap_v01_policy` TO 'cc_writer'@'localhost';
   ```

### 3.2 Add Target (`Provisioning_Plan::add_target`)
1. Grant minimal table rights:
   ```sql
   GRANT SELECT, UPDATE ON `wp_db`.`target_table` TO 'cc_writer'@'localhost';
   ```
2. Create physical ceiling `BEFORE UPDATE` trigger:
   ```sql
   CREATE TRIGGER `wp_db`.`commitcap_v01_<hash>` BEFORE UPDATE ON `wp_db`.`target_table`
   FOR EACH ROW
   BEGIN
     UPDATE commitcap_v01_state
        SET consumed = consumed + 1
      WHERE connection_id = CONNECTION_ID()
        AND policy_id = '<policy_hash>'
        AND consumed < <physical_ceiling>;
     IF ROW_COUNT() != 1 THEN
       SET @commitcap_v01_denied = 1;
       SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO = 1644, MESSAGE_TEXT = 'CC54_DENIED';
     END IF;
   END;
   ```

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
1. Update user password in database:
   ```sql
   ALTER USER 'cc_writer'@'localhost' IDENTIFIED BY '[REDACTED_SECRET]';
   ```
2. Update `wp-config.php`:
   ```php
   define( 'COMMITCAP_DB_PASSWORD', '<new_secret>' );
   ```
3. Drain surviving sessions (existing connections terminate upon timeout or process recycle; new connections use rotated secret).

### 3.5 Uninstall (`Provisioning_Plan::uninstall`)
1. Drop triggers on all active target tables.
2. Drop 4 stored procedures.
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
| **Rotate Credentials** | 1 (execute `ALTER USER` plan) | 1 (update secret in `wp-config.php`) | 1 (verify Doctor READY) | **3** |
| **Remove Target Policy** | 2 (review removal plan, apply drop trigger & revoke) | 0 | 0 (or disable in plugin settings) | **2** |
| **Uninstall / Cleanup** | 2 (review uninstall plan, apply cleanup) | 1 (remove constants from config) | 1 (uninstall plugin in WP admin) | **4** |

### Key Product Implication for Admin:
- **Zero SQL for Admin**: The WordPress Admin / Site owner never writes or sees SQL.
- **Routine Budget Adjustments**: Because Gate #82 proved $0 \le L \le P$ logical budgeting below the physical trigger ceiling, **normal day-to-day budget adjustments require 0 Operator/DBA steps and 0 DDL statements**.
- **One-time Operator Assistance**: Initial setup and adding certified integrations require operator/host assistance on managed hosting.
