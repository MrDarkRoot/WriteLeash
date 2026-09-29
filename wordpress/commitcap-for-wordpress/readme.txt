=== CommitCap ===
Tags: database, bulk actions, redirection, safety, rollback
Requires at least: 6.8
Tested up to: 6.8
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Put a mutation budget on Redirection bulk Disable: inside the budget it commits, over budget it is denied before CommitCap commits.

== Description ==

Bulk changes are useful because one click can change hundreds of rows.

That is also the problem.

A confirmation dialog asks if you are sure. CommitCap asks how much change you are willing to allow.

CommitCap puts an explicit mutation budget around one certified operation: Redirection's global/select-all Bulk Disable, with Redirection 5.5.2 exactly. You choose the logical budget. The certified request runs on a restricted database connection inside a guarded transaction, the real UPDATE row events are counted, and the answer is decided before CommitCap commits:

* Inside your budget: the guarded transaction commits.
* Over your budget: the request is denied and the guarded transaction is rolled back before CommitCap commits. On the pinned release matrix an independent observer then sees all six rows unchanged.
* Over the trusted physical ceiling (P = 2000): the database itself stops the excess row event.

CommitCap does not promise to protect everything. It does one job deliberately: put a mutation budget around one reviewed, version-pinned bulk operation.

= What you get =

* An Admin page under Tools → CommitCap: live readiness, logical budget, enable and disable controls, the most recent local outcome, and a disposable demo on CommitCap-owned data.
* A live Doctor that fails closed: if the restricted runtime, grants, policy trigger or database shape cannot be proven, the dangerous request is refused instead of falling back to an unprotected stock write.
* WP-CLI diagnostics: `wp commitcap status`, `wp commitcap doctor` and `wp commitcap demo`, with human-readable and JSON output.
* Local-only operation: no CommitCap account, no cloud service, no telemetry, no quota, no license server, no upsell.

= Deliberately narrow =

CommitCap Free V0.1 certifies exactly one operation against one exact Redirection build. There is no generic hook registry, no "protect all writes" mode, and no pretend coverage for unreviewed write paths. That narrowness is the point: the supported operation is version-pinned, exercised on both pinned database engines, and fail-closed everywhere else.

== What CommitCap protects ==

One operation: Redirection 5.5.2 → Redirects → select all matching → Bulk Actions → Disable (the global/select-all Disable request).

One budget: a logical UPDATE row-event budget L that you set between 0 and 2000. A trusted physical ceiling P = 2000 is installed in the database for the same table.

One decision: the certified request runs inside a guarded transaction owned by CommitCap. Row events are counted as the reviewed UPDATE executes. If the count stays within L, the guarded transaction commits. If the request would exceed L, it is denied before CommitCap commits.

== How it works ==

1. CommitCap recognizes the exact certified request: the Redirection 5.5.2 global Baseline Disable route and handler. Anything else stays stock Redirection behavior and is not certified.
2. Readiness is evaluated live: exact Redirection version, restricted runtime connection, exact grants, canonical policy trigger and database shape. A failing or unknown check refuses the certified request; it never silently falls back.
3. The reviewed UPDATE runs on a restricted secondary database connection. The normal WordPress database account keeps its ordinary broad role and is not used for the certified mutation.
4. A guarded transaction counts real UPDATE row events. Inside your budget it commits; over budget it is denied and rolled back before CommitCap commits; over the physical ceiling the database policy stops the row event itself.
5. The most recent certified outcome is recorded locally as bounded, informational evidence. It never controls readiness, and it never claims an independently verified rollback.

On the pinned release matrix (WordPress 6.8.3, PHP 8.2, MySQL 8.0.44, MariaDB 10.11.15, Redirection 5.5.2):

* Six matching rows with L=10: HTTP 200, committed, six rows disabled as seen by a fresh independent connection.
* Six matching rows with L=5: HTTP 409, denied before CommitCap commits, all six rows unchanged as seen by a fresh independent connection.

== Installation ==

1. Install and activate CommitCap.
2. Install and activate Redirection 5.5.2 — the exact certified build.
3. Have a trusted database operator provision the CommitCap restricted runtime using the operator instructions included with the plugin (`OPERATOR-SETUP.md`). This is a one-time, trusted setup: it creates one restricted database account, a small helper table, five reviewed routines and one policy trigger, and adds a few constants to `wp-config.php`.
4. Open Tools → CommitCap and confirm the readiness line reports READY.
5. Choose your logical mutation budget L (0 to 2000) and save it.
6. Enable the certified operation.
7. Use Redirection normally: Redirects → select all matching → Bulk Actions → Disable. The certified request is intercepted and budgeted.
8. Review the most recent local outcome on Tools → CommitCap.

After the one-time operator setup, normal Admin use needs no PHP or SQL editing.

== Supported configuration ==

* WordPress: tested on the exact 6.8.3 fixture used by the release matrix. Other WordPress versions are not certified in V0.1; the Doctor reports them as unknown rather than pretending they were tested.
* PHP: tested on the 8.2 fixture. The declared minimum is PHP 7.4; PHP 7.4 itself is a minimum, not a release-matrix-tested runtime.
* Database: MySQL 8.0.44 and MariaDB 10.11.15 are the pinned, tested engines. Other builds are not certified in V0.1.
* Redirection: 5.5.2 exactly. Other versions are not certified; the certified request fails closed instead of running an unreviewed write.
* Certified operation: global/select-all Bulk Disable. Physical ceiling P = 2000; logical budget L from 0 to 2000.
* Multisite / network activation: not certified in V0.1.

Most fully managed WordPress hosts do not allow extra database users, routines or triggers. Initial setup targets self-managed, VPS, dedicated or cooperative environments where a trusted operator has the required database privileges; see the operator instructions included with the plugin.

== Frequently Asked Questions ==

= Does CommitCap protect every WordPress database write? =

No. It budgets one certified update path: the Redirection 5.5.2 global/select-all Bulk Disable. Other WordPress writes, other Redirection operations, and arbitrary plugin code are outside the claim.

= Does it support every Redirection version? =

No. V0.1 certifies Redirection 5.5.2 exactly. A different or unknown Redirection build makes the dangerous certified request unavailable instead of letting it run unreviewed.

= What happens if my bulk operation goes over the logical budget? =

On the supported path, the request is denied before CommitCap commits and the guarded transaction is rolled back. The tested release matrix confirms with a fresh independent database connection that the rows were unchanged.

= What is the physical ceiling? =

A trusted database policy installed during operator setup stops the 2001st row event of the certified table even if the application layer were bypassed. It bounds the certified path; it is not a general database firewall.

= Does CommitCap need an account, cloud service or quota? =

No. There is no CommitCap account, no SaaS dependency, no telemetry, no quota, no license-key server and no paywall. Everything runs locally in your WordPress installation.

= Can a normal site administrator provision the database runtime? =

Not necessarily. Initial setup requires a trusted database operator with privileges to create a database account, helper table, routines and trigger, and to edit `wp-config.php` once. After that, day-to-day budget and enable/disable work happens in the WordPress Admin with no SQL.

= Does uninstall remove the database users, routines or triggers? =

No. WordPress uninstall removes only CommitCap's local WordPress state. The restricted database account, helper table, routines, grants and policy trigger are managed by the trusted operator lifecycle and are intentionally left in place until an operator removes them.

= Does CommitCap protect against a stolen database credential or a hostile plugin using raw database access? =

No. That is outside the cooperative contract. CommitCap assumes a known reviewed plugin path and an operator-managed restricted account.

= Does it roll back email, HTTP or filesystem effects? =

No. Database rollback does not undo external effects. The certified operation is a database update; email, HTTP calls and filesystem writes are outside the rollback guarantee.

= Does it support multisite? =

Multisite is not certified in V0.1. Network activation is refused.

= Is this a "protect everything" security suite? =

No. CommitCap is a narrow mutation budget for one reviewed operation. That is exactly what makes its claim testable.

== Changelog ==

= 0.1.0 =
* Initial public release.
* Mutation budget for the certified Redirection 5.5.2 global/select-all Bulk Disable operation (physical ceiling 2000).
* Release-matrix coverage on MySQL 8.0.44 and MariaDB 10.11.15 with fresh-observer durability checks.
* Admin readiness/budget/outcome interface and WP-CLI status, doctor and demo commands.
* GPLv2-or-later licensing with the full license text included.
