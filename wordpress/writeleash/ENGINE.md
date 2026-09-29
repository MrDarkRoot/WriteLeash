# #54 cooperative UPDATE engine contract (development candidate)

**B. ONLY COOPERATIVE GUARDED-TRANSACTION BOUNDARY IS VIABLE.**

- **Database mechanism:** a trigger counts UPDATE row events using an InnoDB
  transactional helper and `SIGNAL` denies an over-budget event. `SIGNAL` does
  **not** poison the top-level transaction; a direct SQL caller can COMMIT.
- **Lower-level #54 engine:** installs and verifies policies, opens/closes
  accounting primitives and recognizes a denial. It does **not** own the
  transaction and is **not** a public protected-write API. Direct calls to its
  lifecycle routines can reset authority (see below).
- **#56 guarded candidate:** owns START TRANSACTION, OPEN/CLOSE, callback
  execution and final COMMIT for cooperative callers. It checks accounting and
  denial state before COMMIT and rolls back while its transaction remains
  intact. If a callback has already ended the transaction, durability may
  exist; see [`GUARD.md`](GUARD.md) for failure timing and supported SQL.

Writes outside that cooperative guard are NOT protected. Callers that
can directly manipulate transactions or call lifecycle routines are outside
the supported contract. Each new top-level guarded transaction would receive
fresh authority; there is no cross-request, cross-transaction, or task wallet.

The supported *candidate* shape is ordinary nonpartitioned InnoDB tables in
one database, UPDATE row events only, budgets `0` or canonical nonzero decimal
without leading zeros, at most `2147483647`. Target versions: MySQL 8.0+ and
MariaDB 10.11+; exact fixtures tested: MySQL 8.0.44, MariaDB 10.11.15. Other
versions have no tested claim. Only simple unqualified ASCII table names up to
64 bytes are accepted. No existing user trigger graph is accepted on a newly
protected table. Tables on either side of a foreign-key relationship are also
refused: InnoDB cascades need a separate accounting proof. These limits are
deliberately narrower than WordPress SQL.

The engine rejects unrecognized/older server identities before installation
or guard initialization; accepting a parseable later version as *within the
target range* is not evidence that it has been tested. #57 must classify the
exact observed version and may refuse protection when support is unknown.

## Trusted installation and runtime

`Update_Engine::install_infrastructure()` and `install_policy()` run with a
distinct trusted installer connection. The installer needs CREATE for the helper table,
CREATE ROUTINE for the five SQL SECURITY DEFINER routines, and TRIGGER for
each protected table. Removal requires TRIGGER privilege. The restricted
runtime writer needs only UPDATE/SELECT on the protected tables and EXECUTE on
the named `commitcap_v01_open`, `commitcap_v01_close`,
`commitcap_v01_count`, and read-only `commitcap_v01_policy` routines; the
definer needs access to the helper table and trigger metadata. `OPEN` first
checks the actual table engine, trigger count/body, budget, foreign-key shape
and trusted definer through this metadata routine; a missing/altered policy
fails closed for the restricted writer without granting it TRIGGER or
helper-table permissions. This privilege split is sufficient only for
**cooperative use under a #56-owned transaction lifecycle**, not hostile
DB-call containment. #54 alone does **not** meet an adversarial requirement
that the writer cannot reset its own budget.
For #56 the restricted runtime account must have no global EXECUTE privilege
and no EXECUTE on unrelated/unreviewed procedures or functions. The Guard
detects all wpdb `CALL` statements, but a side-effecting stored function
invoked from `SELECT` can perform CLOSE→OPEN invisibly; granting extra EXECUTE
on such a function is outside the supported cooperative contract. See
[`GUARD.md`](GUARD.md) for the executable counterexample.

Do not grant runtime direct helper-table writes, protected-table ownership,
DROP/ALTER/TRIGGER, or global DDL. A typical WordPress account with DDL powers
is *not* a restricted writer, and hosting support for split installer/runtime
credentials is unproven. Never grant ALL to make the engine work.

The helper is a trusted InnoDB table keyed by `(CONNECTION_ID(), policy_id)`.
The row trigger is **scoped to one certified runtime username**: its canonical
body starts `IF LOWER(SUBSTRING_INDEX(USER(), '@', 1)) = LOWER('<runtime_user>')
THEN ...` and only then increments the counter before each row UPDATE. `USER()`
is the server-authenticated session identity set at authentication time;
`CURRENT_USER()` was rejected because a trigger reports its DEFINER instead
(proved on both pinned engines). `LOWER()` keeps any case variant of the runtime
username inside enforcement (fail closed). Normal WordPress/plugin writers keep
their ordinary table behavior; the certified runtime is enforced and denied
whenever no Guard accounting row exists. The whole identity condition is part
of the verified canonical body, so a wrong-identity, removed or broadened
condition never passes trusted or runtime verification. Because the condition
is username-scoped, provisioning refuses to create or modify a policy trigger
when the certified username maps to more than one `mysql.user` account row. A missing/exhausted
counter issues SQLSTATE `45000`, numeric error `1644`, with a
stable `CC54_DENIED` marker. `denial_details()` combines the numeric code,
SQLSTATE, table/budget and transactional count into structured data immediately
after a failed statement; a missing counter is identified separately and has
no fabricated consumption count. For a denied broad statement, its helper
increments roll back with that statement: `returned_count` is the remaining
transactional count, while `consumed`/`attempted` describe the limit reached
inside the failed trigger (`budget`/`budget + 1`). The trigger also sets a
**writable session-local denial signal** before SIGNAL; it survives statement
and savepoint rollback. `begin_guard_state()` initializes it once per new
PHP-owned transaction. `denial_seen()` and `CLOSE` refuse success if a callback
swallows the SQL error and continues. This is *not* server-enforced sticky
denial: direct SQL can reset the variable or manually COMMIT regardless. #56
must immediately ROLLBACK on any observed error and recheck denial before
committing. Direct SQL can set the signal to `1` or reset it to `0`: it is
cooperative bookkeeping, not tamper-resistant state. A successful `OPEN` is
transactional: it creates the helper row **after PHP has started the explicit
transaction**, without starting a transaction in SQL. Opening the same table
twice *while its helper row exists* is an error, not a
counter reset. A stale row from an unsupported manual COMMIT likewise makes a
new guard fail closed instead of inheriting its authority. Allowed savepoint
rollback also rolls back its counter writes. `CLOSE` deletes the row **before
PHP COMMIT**, in the same
transaction; missing state is an error, not a successful close. A full ROLLBACK
removes all work and state. On connection death, the server rolls back the open
transaction. A new guard starts a new transaction and opens new counters; there
is no intentionally committed helper row. The
engine never assumes a SQL-only transaction ID or automatic cleanup hook.

**CLOSE→OPEN same-transaction reset: CONFIRMED on both pinned servers.** A
restricted writer with EXECUTE on the routines can OPEN, consume five events,
CLOSE, OPEN again, consume another five and COMMIT all ten in one top-level
transaction nominally budgeted for five. `OPEN` cannot reset a still-open row
or another session's row, but `CLOSE` deletes its own row and reopening creates
a fresh counter. SQL alone does not provide the transaction-lifetime identity
needed to distinguish this sequence safely; no tombstone or implicit-COMMIT
routine has been added. **Application code must not call OPEN/CLOSE directly.**
Only #56 may call them for a supported application job; this direct-call
counterexample remains in executable tests. `OPEN` itself does not issue
`START TRANSACTION` or implicitly COMMIT. A direct unguarded UPDATE by the
certified runtime with no open row fails (the old design also broke every other
writer; the scoped trigger removed that), but neither the trigger nor #54
protects arbitrary direct SQL or principals outside the certified runtime
identity.
The engine is **not** a PostgreSQL-equivalent security boundary. If #56 cannot
prove transaction ownership and prevent lifecycle reuse, it must refuse to run.

Installation refuses unknown helper/routine objects, unsupported tables,
existing triggers, duplicate policies, and mismatched trigger definitions.
Verification checks the exact parameter order/name/mode/type/length,
unsignedness and material ASCII collation of every routine. Engine-generated
SQL compares case-exact source after folding only whitespace *outside* quoted
literals; keywords are deliberately also case-exact (stricter than SQL), while
the policy hash, quoted identifiers and denial marker are never case-folded.
Thus a different `MESSAGE_TEXT` literal cannot pass verification just because
its lowercased spelling matches the expected marker. The metadata routine
exposes trigger bodies to the restricted verifier without granting TRIGGER.
`denial_details()` captures the original mysqli errno/SQLSTATE and exact
`CC54_DENIED` message **before** calling the count routine; later queries
cannot synthesize a stale denial result.
Removal verifies the *entire* expected trigger definition, owner and table
before dropping just that trigger; no wildcard cleanup or user-table DROP.
Actual installation is manual/trusted, never triggered by plugin activation.

Password-only rotation does not change the authenticated runtime username, so
the identity-scoped trigger remains canonical and **no trigger DDL is required
for a password rotation** (#84 re-proves the body is unchanged and that the
rotated account is still physically enforced). Renaming the runtime account or
changing its username is not part of the lifecycle and requires re-running
`add_target` for each protected table.

The #53 [counterexample](../../experiments/mysql_tx_budget/README.md) remains
an independent required regression: `SIGNAL` followed by savepoint recovery
allows a final COMMIT on both tested engines. The supported guarded execution
API is the #56 `WriteLeash\Guard::update()` path in [`GUARD.md`](GUARD.md);
application jobs must use it instead of calling the lower-level routines. The
engine contract itself is unchanged by #56.
