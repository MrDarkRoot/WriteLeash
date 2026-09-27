# MySQL / MariaDB transaction-budget feasibility (#53)

**Research, not enforcement. Architecture decision: B. ONLY COOPERATIVE GUARDED-TRANSACTION BOUNDARY IS VIABLE.**

The tested InnoDB trigger + transactional helper can count UPDATE row events and
roll back *allowed* counts to a savepoint. It **cannot** make a denial sticky to
the top-level COMMIT. On **both** servers, `SIGNAL SQLSTATE '45000'` rejects
event six, but the transaction stays active and `COMMIT` durably commits the
first five events, even after `ROLLBACK TO SAVEPOINT`. A callable guard-start
procedure can even implicitly COMMIT the denied transaction. Neither result is
PostgreSQL-equivalent enforcement. #54 must not claim a database-enforced
sticky-transaction boundary based on this experiment.

## Reproduce

From the repository root (Docker Engine and Docker Compose required):

```sh
bash experiments/mysql_tx_budget/run.sh
```

The script starts fresh disposable databases, runs **both** servers' assertions
with fresh-connection durability oracles, and removes containers and volumes on
exit. A regression, unexpected privilege grant, or changed denial semantics
exits nonzero. The expected **FAIL** properties in the matrix are asserted as
observations, not hidden as skipped tests. The credentials in `setup.sql` and
Compose are for these network-isolated disposable containers only. Do not use
them outside this reproduction.

| Fixture | Exact observed server | Image |
|---|---|---|
| MySQL | `8.0.44` | `mysql@sha256:9c3380eac945af0736031b200027f581925927c81e010056214a4bd6b6693714` |
| MariaDB | `10.11.15-MariaDB-ubu2204` | `mariadb@sha256:85c39719200637250bb8abe3ccd239239d6f175156fc1698141409fcf8e01a38` |

Both run only **InnoDB** (`cc_items`, `cc_state`, `cc_autocommit_events`).
The connections use `autocommit=ON` (`@@autocommit=1`); `CALL cc_start()`
issues explicit `START TRANSACTION` and initializes the helper counter for the
current `CONNECTION_ID()`. The observed isolation on both is
`REPEATABLE-READ`. Transaction activity is read from the server protocol's
`SERVER_STATUS_IN_TRANS` flag (MySQL 8.0 does not expose `@@in_transaction`).
The fixture's SQL is in [`setup.sql`](setup.sql); the real WordPress writer
is **not** represented by the trusted fixture administrator.

### Exact mechanism / restricted writer

An installer (`root@localhost` in the disposable containers) owns the two
InnoDB objects, `BEFORE UPDATE FOR EACH ROW` trigger, and `SQL SECURITY DEFINER`
routine. The `cc_writer`@`%` role has **only** `SELECT, UPDATE` on `cc_items`
and `cc_autocommit_events`, `EXECUTE` on `cc_start`, and implicit `USAGE`.
It has no privileges on `cc_state`; no `TRIGGER`, `ALTER`, `DROP`, `CREATE`,
`CREATE ROUTINE`, `ALTER ROUTINE`, `SUPER`, `SYSTEM_VARIABLES_ADMIN`, or
`SESSION_VARIABLES_ADMIN` privileges. The installer needs `CREATE` on the
schema, `TRIGGER` on the protected table, and `CREATE ROUTINE` to install
these objects and a trusted definer with permission to update the helper.
Creation of these research objects is **not** a recommendation to grant those
privileges to a WordPress runtime writer.

```sql
CALL cc_start(); -- starts an explicit transaction, initializes own counter
UPDATE cc_items SET touched = touched + 1 WHERE id = 1; -- one row event
-- ... up to five row events in the same transaction ...
UPDATE cc_items SET touched = touched + 1 WHERE id = 6;
-- ERROR 1644 (45000): CommitCap research: UPDATE budget exhausted or no guard
COMMIT; -- SUCCEEDS; the first five changes are durable
```

The trigger increments `cc_state.consumed` only while below five; failure to
update exactly one counter row invokes `SIGNAL`. A failed UPDATE rolls back its
own statement's data/counter changes. `GET DIAGNOSTICS CONDITION 1` reads
`RETURNED_SQLSTATE='45000'` from each server; the client sees error **1644**
with message `CommitCap research: UPDATE budget exhausted or no guard`.
Five single-row updates are durable; sixth-row data are not durable. Repeated
updates of the same row fire the trigger five times; an UPDATE matching zero
rows fires it zero times. Rolled-back allowed helper changes are transactional.

## Observed result matrix

Here PASS means the named property was demonstrated; FAIL is an observed
counterexample, not a test-suite failure.

| Property | MySQL 8.0.44 | MariaDB 10.11.15 |
|---|---|---|
| 5 row events commit (fresh observer) | PASS | PASS |
| event 6 denied (1644 / 45000), no sixth change durable | PASS | PASS |
| row-event counting, including repeated same-row updates | PASS | PASS |
| zero-row consumes 0 | PASS | PASS |
| allowed savepoint rollback returns consumed authority | PASS | PASS |
| sticky denial after savepoint recovery | **FAIL** | **FAIL** |
| final COMMIT after denial rejected | **FAIL** | **FAIL** |
| new explicit guarded tx gets fresh state after COMMIT/ROLLBACK | PASS | PASS |
| two concurrent sessions have independent counters | PASS | PASS |
| realistic WordPress writer privilege model for a DB security boundary | **FAIL** | **FAIL** |

The denied transaction remains active (`autocommit=1`, protocol transaction
flag=1); legal `SELECT 1`, `ROLLBACK TO SAVEPOINT s`, and final `COMMIT`
all succeed. A fresh writer connection then observes exactly the first five
changes. Even without a savepoint, final COMMIT succeeds. Starting a new guard
after denial via `CALL cc_start()` **implicitly commits** those five changes;
the subsequent `ROLLBACK` is too late. An allowed transaction can instead
`ROLLBACK`, initialize again, and get a fresh budget.

## Autocommit is a hard boundary, not a cumulative budget

With `autocommit=ON`, **six bare UPDATE statements are six independent
transactions**: after each statement the protocol reports no active
transaction and a fresh connection sees another durable change immediately.
Six calls each to `cc_start()` / one guarded UPDATE / COMMIT also succeed:
each explicit top-level transaction gets a new budget of five. There is no
cross-transaction, cross-request, or task-wide authority here. An actual
WordPress write running under autocommit does not acquire a transaction-local
budget by merely loading a plugin.

Worse, after a guarded transaction commits with unused budget, its helper row
still exists. The same writer connection can issue an **unguarded** UPDATE in
autocommit mode and spend that stale counter. There is no native transaction ID
in this SQL-only helper design that reliably reinitializes/invalidates this
row at every implicit or explicit transaction boundary. The test proves this
bypass rather than treating the helper table as a production mechanism.

## Privilege and bypass observations

The restricted writer received **1142 permission denied** for reading,
updating, deleting, or dropping `cc_state`, dropping the trigger, and altering
the table. MariaDB also returned 1142 for creating/replacing a trigger with a
new definer. MySQL returned **1419** for creating a trigger with binary logging
enabled; its `CREATE OR REPLACE ... TRIGGER` attempt returned **1064 syntax
error**, so that attempt is *not* evidence of a privilege barrier on MySQL.
Changing `sql_log_bin` returned **1227 privilege denied** on each server.
MySQL has no `ALTER TRIGGER` operation; MariaDB trigger replacement is
privileged. A trusted administrator can of course change/remove these objects.

The writer **can** set session `@` variables, `foreign_key_checks=0`, and
`sql_mode=''`; these settings did not bypass the tested helper/trigger because
it uses a definer-owned transactional table rather than session variables.
The writer **can** call the authorized `cc_start()` repeatedly: it resets
its own counter and implicitly commits an existing transaction. Without the
guard, a writer can also use stale helper state after COMMIT. The tests
exercise both bypasses with only the restricted grants above.

A common WordPress DB account is also used for schema installation/migrations
and may have `CREATE`, `ALTER`, `DROP`, and/or `TRIGGER`; a user with privileges
to remove or replace the enforcement cannot be a restricted writer. A separate
privileged installer and a least-privilege runtime account would require host
support and *still* would not repair SQL `SIGNAL`'s nonsticky denial or the
callable routine/transaction-lifetime flaws. No such host topology is claimed
to be generally available on WordPress hosting.

## Contract implication

**B. ONLY COOPERATIVE GUARDED-TRANSACTION BOUNDARY IS VIABLE.** The evidence
supports a *possible* future explicitly opted-in application wrapper that
controls BEGIN/COMMIT/ROLLBACK and treats any denial as an immediate full
ROLLBACK; it does **not** prove that wrapper is implemented, complete, or
robust against callers with direct SQL access. If future work cannot constrain
those calls, the proposed wrapper must refuse protection. No production
enforcement, plugin activation, WordPress compatibility promise, MyISAM
support, INSERT/DELETE coverage, or future DB-version certification follows
from this experiment. PostgreSQL behavior remains outside this experiment.
