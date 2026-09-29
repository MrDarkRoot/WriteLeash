# #56 guarded transaction API (development candidate)

**B. ONLY COOPERATIVE GUARDED-TRANSACTION BOUNDARY IS VIABLE.**
`WriteLeash\Guard::update()` is the one supported execution path for bounded
UPDATE work. It owns exactly one explicit transaction, exactly one #54
accounting policy and the only supported COMMIT/ROLLBACK. It is cooperative
application-level enforcement. It does not turn MySQL/MariaDB into a
hostile-writer database security boundary, and SQL outside this API is not
protected.

```php
$result = \WriteLeash\Guard::update(
	$wpdb->prefix . 'my_table',
	50,
	function () use ( $wpdb ) {
		foreach ( $ids as $id ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $wpdb->prefix . 'my_table SET state = %s WHERE id = %d',
					'repaired',
					$id
				)
			);
		}
		return count( $ids );
	}
);
```

The fourth optional argument selects the `wpdb` connection; it defaults to
`$GLOBALS['wpdb']`. The policy trigger is scoped to one certified runtime
username, so the guarded connection must authenticate as that identity:
`verify_runtime_budget()` compares the live `USER()` username against the
canonical trigger body and refuses with `policy_unverified` when they differ.
The callback must use that same connection for protected work. On success the
callback return value is returned after COMMIT. Failures throw:

- `WriteLeash\Budget_Denied` — a #54 row-event denial; `details()` returns
  table, budget, consumed, attempted, reason, sqlstate, errno.
- `WriteLeash\Unsupported_Transaction_State` — the guard refused before running
  the callback (existing transaction, nested guard, `autocommit=0`, dirty error
  state, stale accounting, unsupported connection).
- `WriteLeash\Guard_Error` — any other guarded failure (callback exception, DB
  error, reserved SQL, connection change, commit outcome, post-commit state).
  `reason()` returns a short code and callback exceptions are available through
  `getPrevious()`.

## Supported flow

```text
validate table and budget (canonical #54 rules)
→ prove autocommit=1 and no active transaction (collision-resistant probe)
→ verify the runtime policy through the #54 definer routine
→ prove no committed accounting row exists for this connection+policy
→ START TRANSACTION
→ begin_guard_state() and begin_accounting() once
→ execute the callback while monitoring every wpdb query
→ deny / error checks (denial, reserved SQL, DB errors, connection identity)
→ require an active owned transaction and a readable accounting count
→ end_accounting() once
→ require an active owned transaction
→ COMMIT
→ require the expected post-commit state
```

The caller must never finish the supported transaction itself.

## Failure timing and durability

The rollback guarantee holds for failures observed **before** the Guard sends
COMMIT **while its original transaction still exists**. A callback may already
have committed through an unmonitored path before the Guard observes an error:

| Timing | Guarantee |
|---|---|
| PRE-COMMIT FAILURE with original transaction intact (denial, callback error, DB error, reserved SQL) | full ROLLBACK; no guarded mutation from this transaction is durable |
| CALLBACK-ISSUED COMMIT (`COMMIT`, `START TRANSACTION`, DDL or `$wpdb->dbh` control) | detected contract violation; changes may already be durable and are not undone |
| GUARD COMMIT OUTCOME UNKNOWN (`commit_failed_or_unknown`) | durability unknown; the best-effort ROLLBACK cannot be assumed to undo a commit the server already accepted |
| POST-COMMIT STATE ANOMALY (`post_commit_state`) | the COMMIT call appeared to succeed but the connection state is inconsistent; changes may already be durable |
| Mid-callback reconnect / replaced handle (`connection_changed`) | the old server session ordinarily rolls back open work; nothing is claimed about ambiguous in-flight work or an earlier callback-issued COMMIT |

`transaction_lost` and `transaction_lost_after_close` mean the owned
transaction ended unexpectedly; durability may already exist because the guard
did not perform that COMMIT itself.

## Protected

- one explicit Guard-owned transaction;
- one configured UPDATE policy/table per invocation;
- ordinary cooperative application code running inside the callback;
- row events from repeated statements, repeated rows, broad statements and
  no-op assignments, exactly as documented in [`ENGINE.md`](ENGINE.md).

## NOT protected

- SQL outside `Guard::update()`;
- manual transaction control (START TRANSACTION / COMMIT / ROLLBACK) by the
  callback — reserved SQL is detected and the guard never reports success, but
  durability created by an already-executed direct COMMIT cannot be undone;
- direct calls to the #54 `commitcap_v01_*` routines — detected through wpdb,
  outside the contract, and able to reset authority if used directly from a DB
  client;
- calls to **any** stored procedure inside the callback, even one with an
  unrelated name: stored procedure bodies are opaque to the wpdb filter and
  can invoke CLOSE→OPEN or COMMIT. Every callback `CALL` is recorded as a
  contract violation; the query filter does not prevent its execution, so a
  wrapped COMMIT can already be durable before the Guard returns an error;
- invoking stored functions with side effects from SELECT/DML or invoking
  procedures through other unmonitored paths. The restricted writer must not
  have EXECUTE on unreviewed stored routines (especially SECURITY DEFINER
  routines), nor global EXECUTE. SQL inside an accessible stored function is
  not individually visible to the Guard and cannot be generally inspected or
  constrained by its lexical classifier. On both pinned engines, a test-only
  definer function invoked by `SELECT` wrapped CLOSE→OPEN and the Guard
  returned success with 10 durable events under a budget of 5 **when extra
  EXECUTE privilege was deliberately granted**. This capability must be absent
  from the supported runtime writer;
- direct mysqli / `$wpdb->dbh` SQL, which bypasses the monitor;
- new `wpdb` objects or other database connections; only the guarded connection
  and `$GLOBALS['wpdb']` are monitored, and protected writes attempted from
  another connection are outside the contract. The #54 trigger denies unguarded
  writes made **as the certified runtime identity**; it is scoped to that
  username so normal WordPress/Redirection writers keep their ordinary
  behavior. Guard refuses to run on a connection with any other identity
  (`policy_unverified`), and an explicitly granted foreign DB principal is
  outside cooperative enforcement entirely;
- hostile plugin code deliberately bypassing the guard;
- new transactions, retries and cross-request work;
- INSERT/DELETE budgets, task-wide or cross-request authority.

## Transaction ownership

`Guard_Transaction::active()` uses documented SAVEPOINT semantics: outside a
transaction `SAVEPOINT` is a no-op, so `ROLLBACK TO SAVEPOINT` fails with
MySQL error 1305; inside a transaction the savepoint exists and the rollback
succeeds. The pinned fixtures MySQL 8.0.44 and MariaDB 10.11.15 showed the same
behavior for the restricted writer. This probe deliberately avoids
`INNODB_TRX` (needs PROCESS and misses bare transactions) and
`@@in_transaction` (not defined on MySQL 8.0). The probe statements execute
through the mysqli handle so the expected 1305 does not pollute wpdb error
state or logs. Unexpected probe results fail closed as
`Unsupported_Transaction_State`.

Each probe uses a cryptographically random, collision-resistant name
(`commitcap_v01_tx_` plus 16 hex characters from `random_bytes()`). A
collision with a caller's savepoint is negligibly likely, not impossible.
The old fixed name is used by the regression test: a caller-owned savepoint
with that name survives and still controls its own rollback window after the
guard refuses.

The guard refuses before the callback when the connection is already inside a
transaction, including a bare `START TRANSACTION` with no statements, and when
`@@autocommit` is not `1`. Nested guards are refused before any nested
mutation. The connection identity is captured before the callback and compared
afterwards; a reconnect or replaced handle fails closed as `connection_changed`.

## Failure detection

The monitor attaches to WordPress's `query` filter, which runs before wpdb
flushes its error state. A failed `$wpdb->query()` that a callback ignored is
latched even if a later successful query clears `last_error`, so a swallowed
`false` cannot silently commit. The exact #54 denial identity
(`CC54_DENIED` message, errno 1644, SQLSTATE 45000) is latched separately at the
next query boundary, so a denial followed by `SELECT 1` still returns a typed
`Budget_Denied` instead of a generic error. The latching is identity-based, not
prose-based: an ordinary SQLSTATE 45000 from another source is not reported as
a WriteLeash budget denial.

`Guard_Sql::classify()` tokenizes each callback query and detects Guard-reserved
SQL despite database qualification, backtick quoting, comments
(`/* */`, `-- `, `#`), mixed keyword case and formatting:

- every `CALL`, including qualified and quoted forms (the body is opaque);
- `COMMIT`, `ROLLBACK`, `START TRANSACTION`, `BEGIN`, `SAVEPOINT`,
  `ROLLBACK TO SAVEPOINT`, `RELEASE SAVEPOINT`;
- `SET autocommit` in its `SESSION`/`LOCAL`/`GLOBAL`/`@@`/`@@session.`/
  `@@local.`/`@@global.` variants;
- `SET @commitcap_v01_*` with `=` or `:=`, and any other reference to a
  `@commitcap_v01_*` session variable;
- `PREPARE`, `EXECUTE`, `DEALLOCATE` (dynamic SQL that could reconstruct
  reserved statements).

Statements the tokenizer cannot classify safely — executable comments
(`/*! ... */` and MariaDB `/*M! ... */`, including versioned variants),
unterminated comments or strings, or multiple statements — fail closed and
are treated as reserved. Ordinary
DML/SELECT and reserved-looking string literals are not blocked.

## Detected versus prevented

| Callback behavior | Result |
|---|---|
| ignores event-six denial (including after a later `SELECT 1`) | `Budget_Denied`, full rollback, zero durable changes |
| ignores a failed wpdb query (any error mode) | `Guard_Error` (`database_error`), full rollback |
| callback throws `Exception`/`Error` | `Guard_Error` (`callback_failed`), full rollback, cause preserved |
| `COMMIT` through wpdb | `Guard_Error` (`callback_issued_reserved_sql`); already-committed changes are **not** prevented |
| `ROLLBACK` through wpdb | `Guard_Error` (`callback_issued_reserved_sql`), nothing durable |
| qualified/backtick/commented `CALL ...commitcap_v01_close/open` | `Guard_Error` (`callback_issued_reserved_sql`), full rollback, never success |
| unrelated-name `CALL` wrapping CLOSE→OPEN | `Guard_Error` (`callback_issued_reserved_sql`), full rollback after execution; no success |
| unrelated-name `CALL` wrapping COMMIT | `Guard_Error` (`callback_issued_reserved_sql`); earlier writes may already be durable |
| `SELECT` invoking a side-effecting stored function with extra EXECUTE privilege | outside supported contract; tested 10 durable under budget 5 on both fixtures |
| ambiguous/executable-comment SQL while the owned transaction remains intact | `Guard_Error` (`callback_issued_reserved_sql`), full rollback |
| MariaDB executable-comment `COMMIT` | `Guard_Error` (`callback_issued_reserved_sql`); already-committed changes are not undone (two durable in the pinned fixture) |
| `COMMIT` through `$wpdb->dbh` | `Guard_Error` (`transaction_lost`); already-committed changes are **not** prevented |
| direct `SET @commitcap_v01_denied = 0` / `:=` | `Guard_Error` (`callback_issued_reserved_sql`), full rollback |
| replaced connection | `Guard_Error` (`connection_changed`), nothing durable from the old session |

A direct callback COMMIT also commits the accounting row. The guard then fails
closed with `stale_accounting_state` on that connection and policy until a
trusted administrator removes the committed row (or the connection is
replaced). The #54 CLOSE→OPEN authority reset remains confirmed for direct DB
clients and outside this contract; see [`ENGINE.md`](ENGINE.md).

## Limitations

- The guard is point-in-time and depends on the merged #54 policy verification.
- It cannot undo work a callback already committed through a path the monitor
  cannot see, and it does not claim to.
- A COMMIT whose outcome is unknown leaves durability unknown; no retry or
  recovery is attempted.
- It does not make arbitrary SQL safe, does not protect other tables or other
  connections, and does not create cross-request or task-wide budgets.
- The callback must not invoke stored functions/procedures with side effects;
  the runtime writer must not be granted general or unreviewed routine EXECUTE.
  Granting access to an opaque definer function breaks the supported boundary
  even when the outer SQL is a `SELECT` through the guarded wpdb connection.
- A PHP fatal error mid-callback can leave the connection's transaction and
  session state behind; reconnect or trusted cleanup is required. The guard
  itself never silently repairs state.
