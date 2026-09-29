# WriteLeash for WordPress Free V0.1: Admin and WP-CLI surfaces (#60)

Tools → WriteLeash presents **one** certified production operation:
Redirection **5.5.2** → Redirects → select all matching → Bulk Actions →
Disable (`redirection-5.5.2-bulk-disable-global`). The real mutation remains
Redirection's authenticated REST path and WriteLeash's existing #87 adapter.
There is no second execution button for Redirection, generic operation picker,
SQL editor or provisioning form. Protection is cooperative UPDATE row-event
enforcement on the restricted secondary connection, not a database firewall;
external email/HTTP/filesystem effects are outside database rollback.

The operation readiness vocabulary (`READY`, `DISABLED`, `UNSUPPORTED`,
`MISCONFIGURED`, `NOT_READY`) is **separate** from runtime Doctor evidence
(`PASS`, `FAIL`, `UNKNOWN`, `DEGRADED`). The page and CLI status show both.
Disabling leaves global/select-all Bulk Disable **unavailable**, not stock
unguarded. Initial restricted account, canonical triggers and the demo table
are prepared under [PROVISIONING.md](PROVISIONING.md) and [DEMO.md](DEMO.md)
by a trusted operator. Ordinary Admin/CLI commands cannot install or repair
these objects and never require installer credentials.

## Mutation surface inventory for #61

| Surface | Required authority | Nonce? | Accepted input | Existing service | Persistent change | Restricted DB side effect? | Installer credential? |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Admin `admin_post_writeleash_action` / budget | `manage_options`, POST | `writeleash_budget` | `logical_budget` canonical 0..2000 only | `Operation_Config::set_logical_budget()` | Only `writeleash_certified_operation_state` (non-autoloaded); short-lived local notice | No | No |
| Admin enable | `manage_options`, POST | `writeleash_enable` | Fixed action only | `Certified_Operation_Status::can_enable()` then `Operation_Config::set_enabled(true)` *only* on live READY | Same single config option; short-lived notice | Read-only Doctor probes plus rolled-back canonical probe; no production mutation | No |
| Admin disable | `manage_options`, POST | `writeleash_disable` | Fixed action only | `Operation_Config::set_enabled(false)` | Same single config option; short-lived notice | No | No |
| Admin disposable demo | `manage_options`, POST | `writeleash_demo` | Fixed action only | `Disposable_Demo::run()` | A↔B on **WriteLeash-owned disposable data**; short-lived notice | Exactly the #58 restricted Guard safe/denied UPDATE legs | No |
| `wp writeleash status [--format=json]` | Local WP-CLI operator | No (local process) | Optional JSON format | `Product_Status::snapshot()` | No config mutation; live Doctor behavioral probe is rolled back | Doctor probes; no production mutation | No |
| `wp writeleash doctor [--format=json]` | Local WP-CLI operator | No (local process) | Optional JSON format | Same live snapshot / existing Doctor | No config mutation | Same rolled-back Doctor probe | No |
| `wp writeleash demo [--format=json]` | Local WP-CLI operator | No (local process) | Optional JSON format | `Disposable_Demo::run()` | A↔B on **WriteLeash-owned disposable data** | Same #58 restricted Guard legs | No |

Admin forms target `admin-post.php` and bind each nonce to its exact action.
The handler independently checks `manage_options`, POST, recognized action and
nonce before calling any mutation. Successful posts redirect back to Tools.
One user-scoped, **120-second** informational transient carries only a bounded
action notice; it has no role in readiness or Guard execution. GET/page display
never writes operation config or performs a demo run. The page escapes all
dynamic text with WordPress HTML/attribute/URL escaping.

Enabling runs the **same live verification** as production READY: exact plugin
version/adapter, restricted connection, exact target grants, canonical Doctor,
physical P=2000 and logical L≤P. Missing budget, an unsupported version,
grant drift or a Doctor UNKNOWN/FAIL/DEGRADED result leaves `enabled=false`.
Changing L uses only the existing config option; no physical trigger or grant
changes. Redirection's stock non-certified paths remain outside WriteLeash's
certified interception.

## Local evidence and demo truthfulness

Only the last certified production COMMITTED or DENIED adapter outcome is
retained in the non-autoloaded `writeleash_last_certified_outcome` option (schema
version 1). The record is informational, bounded and strictly validated on
read. It holds no credentials, raw SQL, requests, exceptions or database
handles. A failed save cannot change the REST/Guard outcome. Malformed records
are ignored. The page says **rollback attempted**, **completion unknown** and
**not independently verified by this request** for a real denied operation.
Fresh-observer proof in the pinned CI fixture is stronger evidence than the
normal production response and is not promoted into that response.

The synthetic **WriteLeash-owned disposable demo** uses the unchanged #58
A↔B state machine with L=5/P=6. Once trusted setup/initial seed is complete,
normal Admin clicks and `wp writeleash demo` invocations can repeat without a
trusted reset or privileged credentials. A noncanonical state returns
`NOT_READY / trusted_reset_required`; only trusted operator recovery calls
`Disposable_Demo_Setup::reset()`. The demo does not prove Redirection was
protected or imply all WordPress writes are protected.

CLI JSON has stable scalar status/reason/Doctor fields and one validated last
outcome; it never serializes raw Doctor results or connection objects. Doctor
exits zero only for operation READY with Doctor PASS; demo exits zero only for
COMPLETE. Missing runtime constants fail closed without switching to WordPress
`$wpdb`. No CLI command accepts an operation ID, SQL, table, arbitrary count,
PHP callback or installer credential.

## WordPress uninstall boundary

WordPress uninstall removes WriteLeash's local WordPress configuration and
last-outcome evidence: `writeleash_version`, the certified-operation config
option and the last-outcome option, plus the stale #87 budget option if an old
install left it. It uses exact option names only — never a wildcard
`writeleash_%` deletion — so unrelated options and Redirection's own options and
data survive.

Uninstall does **not** remove trusted DB users, grants, triggers, the helper
table, the five reviewed routines, demo objects or any Redirection data. Those
remain explicit operator lifecycle tasks under
[PROVISIONING.md](PROVISIONING.md) and [DEMO.md](DEMO.md); uninstall needs no
installer or root credential and performs no DDL/DCL. Because trusted DB
policies can outlive uninstall, a reinstall still starts `enabled=false` with no
logical budget and no last outcome until an Admin configures it again. The
120-second user notice transient is accepted ephemeral behavior: it carries
only bounded action feedback (never a production COMMITTED/DENIED record), so
it cannot restore old authorization or evidence, and it is deliberately not
wildcard-scanned after uninstall.
