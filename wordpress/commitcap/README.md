# CommitCap for WordPress — V0.1 development foundation

This subtree is a WordPress plugin foundation, not a released security control.
#55 adds a loadable plugin, baseline activation checks, conservative deactivation,
and uninstall of one plugin-owned option (`commitcap_version`). Loading the plugin
registers hooks only; activation reads WordPress/PHP/database version facts and
stores the version option if the basic runtime checks pass. It does not change
user data, database objects, or storage engines. Deactivation retains that option
and makes no database changes: **deactivation is not uninstall**. Explicit
uninstall removes only `commitcap_version` for the current site; an unrelated
option with a similar name is never removed. Network activation and multisite
cleanup have not been validated; network activation is refused without writing
plugin metadata. The version header is developmental; no plugin license decision
has been made (#64).

**MySQL/MariaDB does not currently provide the PostgreSQL-equivalent sticky
transaction boundary demonstrated by CommitCap's PostgreSQL research path.**
The planned WordPress V0.1 contract is a **cooperative guarded-transaction
model**. **The guard/engine itself is NOT implemented by #55.** See the [#53
feasibility experiment](../../experiments/mysql_tx_budget/README.md): event six
was denied on the pinned test servers, but COMMIT still succeeded after savepoint
recovery. Autocommit issues separate transaction authority for each statement.
This plugin therefore does not claim to protect WordPress writes.

The intended investigation targets are MySQL 8.0+, MariaDB 10.11+, and InnoDB.
No version range, hosting environment, storage engine, or privilege combination
has been approved by a WordPress compatibility doctor. #57 will add that doctor;
#54 adds a lower-level engine candidate and its deliberately narrow contract in
[`ENGINE.md`](ENGINE.md); #56 is still required for a guarded transaction API.
The #54 routines remain callable by a DB writer: CLOSE→OPEN in one transaction
can reset its own budget, and the session denial signal is writable. The
split-privilege fixture is only a cooperative mechanism, not an adversarial
database-writer security boundary. Application code must not invoke these
lifecycle routines directly; only the future #56 guard may own them.
Later issues cover demos and user-facing integrations. No admin UI, WP-CLI
integration, WooCommerce integration, telemetry, external network requests,
or cloud feature is present in this foundation.

## Development checks

From the repository root with Docker Engine and Compose:

```sh
bash wordpress/tests/run.sh
```

The focused harness boots WordPress 6.8.3 under PHP 8.2 against disposable
MariaDB 10.11.15, performs real activation/deactivation/uninstall calls, checks
failure paths and database objects, runs PHP lint/direct-access checks, and
destroys the fixture on exit. This is a foundation lifecycle test, not a
compatibility certification or the full WordPress matrix (#62).
