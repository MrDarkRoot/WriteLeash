=== CommitCap ===
Tags: database, bulk actions, redirection, safety, rollback
Requires at least: 6.8
Tested up to: 7.1
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
* Over the trusted physical ceiling (P = 2000): the restricted-runtime policy stops the excess row event on that certified path.

CommitCap does not promise to protect everything. It does one job deliberately: put a mutation budget around one reviewed, version-pinned bulk operation.

= What you get =

* Tools → CommitCap: live readiness, logical budget, enable/disable, recent local outcome, and an optional disposable demo.
* A live Doctor that fails closed: if the restricted runtime, grants, policy trigger or database shape cannot be proven, the dangerous request is refused instead of falling back to a stock write.
* WP-CLI diagnostics: `wp commitcap status`, `wp commitcap doctor` and `wp commitcap demo`, human-readable or JSON.
* Local-only operation: no CommitCap account, cloud service, telemetry, quota, license server or upsell.

= Deliberately narrow =

CommitCap Free V0.1 certifies exactly one operation against one exact Redirection build. No generic hook registry, no "protect all writes" mode, no pretend coverage. That narrowness is the point: the supported operation is version-pinned, exercised on both pinned database engines, and fail-closed everywhere else.

== What CommitCap protects ==

One operation: Redirection 5.5.2 → Redirects → select all matching → Bulk Actions → Disable (the global/select-all Disable request).

One budget: a logical UPDATE row-event budget L that you set between 0 and 2000. A trusted physical ceiling P = 2000 is installed in the database for the same table.

One decision: the certified request runs inside a guarded transaction owned by CommitCap. Row events are counted as the reviewed UPDATE executes. If the count stays within L, the guarded transaction commits. If the request would exceed L, it is denied before CommitCap commits.

== How it works ==

1. CommitCap recognizes the exact certified request: the Redirection 5.5.2 global/select-all Bulk Disable route and handler (Redirects → select all matching → Bulk Actions → Disable). Anything else stays stock Redirection behavior and is not certified.
2. Readiness is evaluated live: exact Redirection version, restricted runtime connection, exact grants, canonical policy trigger and database shape. A failing or unknown check refuses the certified request; it never silently falls back.
3. The reviewed UPDATE runs on a restricted secondary database connection. The normal WordPress database account keeps its ordinary broad role and is not used for the certified mutation.
4. A guarded transaction counts real UPDATE row events. Inside your budget it commits; over budget it is denied and rolled back before CommitCap commits; over the physical ceiling the restricted-runtime policy stops the row event.
5. The most recent certified outcome is recorded locally as bounded, informational evidence. It never controls readiness, and never claims an independently verified rollback.

On the release matrix (PHP 8.2; MySQL 8.0.44 and MariaDB 10.11.15; Redirection 5.5.2; exact WordPress core fixtures 6.8.3 and 7.1.2):

* Six matching rows with L=10: HTTP 200, committed, six rows disabled as seen by a fresh independent connection.
* Six matching rows with L=5: HTTP 409, denied before CommitCap commits, all six rows unchanged as seen by a fresh independent connection.

== Installation ==

1. Install and activate Redirection 5.5.2 — the exact certified build.
2. Install and activate CommitCap. WordPress knows Redirection is a required plugin, but CommitCap itself still verifies the exact supported version 5.5.2 at runtime.
3. Have a trusted database operator provision the CommitCap restricted runtime using the operator instructions included with the plugin (`operator-setup.txt`). This is a one-time, trusted setup: it creates one restricted database account, a small helper table, five reviewed routines and one policy trigger, and adds a few constants to `wp-config.php`.
4. Open Tools → CommitCap and confirm the readiness line reports READY.
5. Choose your logical mutation budget L (0 to 2000) and save it.
6. Enable the certified operation.
7. Use Redirection normally: Redirects → select all matching → Bulk Actions → Disable. The certified request is intercepted and budgeted.
8. Review the most recent local outcome on Tools → CommitCap.

After the one-time operator setup, normal Admin use needs no PHP or SQL editing.

== Supported configuration ==

* WordPress core: exact tested fixtures 6.8.3 (original baseline) and 7.1.2 (current stable at release). `Tested up to: 7.1` means those exact builds were exercised end to end on both databases. Untested builds, including minors between them, are not certified: the Doctor reports them unknown and refuses to enable.
* Required dependency: Redirection. WordPress prevents normal activation of CommitCap while Redirection is missing or inactive, but CommitCap still independently requires Redirection 5.5.2 exactly.
* PHP: tested on 8.2. The declared minimum is PHP 7.4; 7.4 is a minimum, not a release-matrix-tested runtime.
* Database: MySQL 8.0.44 and MariaDB 10.11.15 are the pinned, tested engines. Other builds are not certified in V0.1.
* Redirection: 5.5.2 exactly. Other versions are not certified; the certified request fails closed.
* Certified operation: global/select-all Bulk Disable. Physical ceiling P = 2000; logical budget L from 0 to 2000.
* Multisite / network activation: not certified in V0.1.

Most fully managed WordPress hosts do not allow extra database users, routines or triggers. Initial setup targets self-managed, VPS, dedicated or cooperative environments; see the operator instructions included with the plugin.

== Frequently Asked Questions ==

= Does CommitCap protect every WordPress database write? =

No. It budgets one certified update path: the Redirection 5.5.2 global/select-all Bulk Disable. Other WordPress writes, other Redirection operations, and arbitrary plugin code are outside the claim.

= Does it support every Redirection version? =

No. V0.1 certifies Redirection 5.5.2 exactly. A different or unknown Redirection build makes the dangerous certified request unavailable instead of letting it run unreviewed.

= What happens if my bulk operation goes over the logical budget? =

On the supported path, the request is denied before CommitCap commits and the guarded transaction is rolled back. The tested release matrix confirms with a fresh independent database connection that the rows were unchanged.

= What is the physical ceiling? =

A trusted database policy installed during operator setup stops the 2001st row event of the certified table on the reviewed restricted-runtime path. It is not a general database firewall and is not a containment claim for hostile plugins, raw database clients, or stolen credentials.

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
