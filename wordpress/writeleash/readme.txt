=== WriteLeash ===
Tags: database, bulk actions, redirection, safety, rollback
Requires at least: 6.8
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Development-only technical package. The WooCommerce Free bulk-price workflow
is functional; public release is deferred.

== Description ==

WriteLeash Free changes stored regular prices for published core simple
products in the base store currency, with a frozen preview, safety limits,
durable background execution and conflict-aware Undo.

Open Products → Bulk Prices: select products by category, exact SKU or
explicit IDs, choose one of Set / +fixed / -fixed / +percent / -percent,
review the server-side paginated preview with exclusions and warnings,
approve the exact frozen plan, then follow durable progress. A closed
browser never loses truth, and a protected Resume action continues stalled
work. Undo restores eligible prices changed by WriteLeash; later
WooCommerce edits are never overwritten, and orders, sales, email, webhook
and other plugin side effects are outside Undo.

This package also documents the existing advanced Redirection V0.1
technical substrate, not the intended WooCommerce Free 1.0 product. It applies a mutation
budget to Redirection bulk Disable: inside budget it commits; over budget it is
denied before WriteLeash commits.

Bulk changes are useful because one click can change hundreds of rows.

That is also the problem.

A confirmation dialog asks if you are sure. WriteLeash asks how much change you are willing to allow.

WriteLeash puts an explicit mutation budget around one certified operation: Redirection's global/select-all Bulk Disable, with Redirection 5.5.2 exactly. You choose the logical budget. The certified request runs on a restricted database connection inside a guarded transaction, the real UPDATE row events are counted, and the answer is decided before WriteLeash commits:

* Inside your budget: the guarded transaction commits.
* Over your budget: the request is denied and the guarded transaction is rolled back before WriteLeash commits. On the pinned release matrix an independent observer then sees all six rows unchanged.
* Over the trusted physical ceiling (P = 2000): the restricted-runtime policy stops the excess row event on that certified path.

WriteLeash does not promise to protect everything. It does one job deliberately: put a mutation budget around one reviewed, version-pinned bulk operation.

= What you get =

* Tools → WriteLeash: live readiness, logical budget, enable/disable, recent local outcome, and an optional disposable demo.
* A live Doctor that fails closed: if the restricted runtime, grants, policy trigger or database shape cannot be proven, the dangerous request is refused instead of falling back to a stock write.
* WP-CLI diagnostics: `wp writeleash status`, `wp writeleash doctor` and `wp writeleash demo`, human-readable or JSON.
* Local-only operation: no WriteLeash account, cloud service, telemetry, quota, license server or upsell.

= Deliberately narrow =

This advanced V0.1 technical substrate certifies exactly one operation against one exact Redirection build. No generic hook registry, no "protect all writes" mode, no pretend coverage. That narrowness is the point: the supported operation is version-pinned, exercised on both pinned database engines, and fail-closed everywhere else.

== What WriteLeash protects ==

One operation: Redirection 5.5.2 → Redirects → select all matching → Bulk Actions → Disable (the global/select-all Disable request).

One budget: a logical UPDATE row-event budget L that you set between 0 and 2000. A trusted physical ceiling P = 2000 is installed in the database for the same table.

One decision: the certified request runs inside a guarded transaction owned by WriteLeash. Row events are counted as the reviewed UPDATE executes. If the count stays within L, the guarded transaction commits. If the request would exceed L, it is denied before WriteLeash commits.

== How it works ==

1. WriteLeash recognizes the exact certified request: the Redirection 5.5.2 global/select-all Bulk Disable route and handler (Redirects → select all matching → Bulk Actions → Disable). Anything else stays stock Redirection behavior and is not certified.
2. Readiness is evaluated live: exact Redirection version, restricted runtime connection, exact grants, canonical policy trigger and database shape. A failing or unknown check refuses the certified request; it never silently falls back.
3. The reviewed UPDATE runs on a restricted secondary database connection. The normal WordPress database account keeps its ordinary broad role and is not used for the certified mutation.
4. A guarded transaction counts real UPDATE row events. Inside your budget it commits; over budget it is denied and rolled back before WriteLeash commits; over the physical ceiling the restricted-runtime policy stops the row event.
5. The most recent certified outcome is recorded locally as bounded, informational evidence. It never controls readiness, and never claims an independently verified rollback.

On the technical regression matrix (PHP 8.2; MySQL 8.0.44 and MariaDB 10.11.15; Redirection 5.5.2; exact WordPress core fixtures 6.8.3 and 7.1.2):

* Six matching rows with L=10: HTTP 200, committed, six rows disabled as seen by a fresh independent connection.
* Six matching rows with L=5: HTTP 409, denied before WriteLeash commits, all six rows unchanged as seen by a fresh independent connection.

== Installation ==

1. Install and activate WooCommerce — the tested fixture is 11.1.2. WordPress knows WooCommerce is a required plugin.
2. Install and activate WriteLeash.
3. Open Products → Bulk Prices and build a frozen preview. No SSH, database operator setup, custom database users, triggers, routines, grants or manual SQL is needed for the Free workflow.
4. The advanced Redirection substrate below remains optional: install and activate Redirection 5.5.2 — the exact certified build — only to use the guarded bulk Disable path.
3. Have a trusted database operator provision the WriteLeash restricted runtime using the operator instructions included with the plugin (`operator-setup.txt`). This is a one-time, trusted setup: it creates one restricted database account, a small helper table, five reviewed routines and one policy trigger, and adds a few constants to `wp-config.php`.
4. Open Tools → WriteLeash and confirm the readiness line reports READY.
5. Choose your logical mutation budget L (0 to 2000) and save it.
6. Enable the certified operation.
7. Use Redirection normally: Redirects → select all matching → Bulk Actions → Disable. The certified request is intercepted and budgeted.
8. Review the most recent local outcome on Tools → WriteLeash.

After the one-time operator setup, normal Admin use needs no PHP or SQL editing.

== Supported configuration ==

* WordPress core: exact tested fixtures 6.8.3 (original baseline) and 7.1.2 (current stable at the last regression). `Tested up to: 7.1` means those exact builds were exercised end to end on both databases. Untested builds, including minors between them, are not certified: the Doctor reports them unknown and refuses to enable.
* Required dependency: WooCommerce. WordPress prevents normal activation of WriteLeash while WooCommerce is missing or inactive. The tested fixture is WooCommerce 11.1.2; untested builds are not certified for the Free bulk-price workflow.
* PHP: tested on 8.2. The declared minimum is PHP 7.4; 7.4 is a minimum, not a release-matrix-tested runtime.
* Database: MySQL 8.0.44 and MariaDB 10.11.15 are the pinned, tested engines. Other builds are not certified in V0.1.
* Redirection: 5.5.2 exactly, only for the optional advanced Guard substrate. Other versions are not certified; the certified request fails closed.
* Certified operation: global/select-all Bulk Disable. Physical ceiling P = 2000; logical budget L from 0 to 2000.
* Multisite / network activation: not certified in V0.1.

Most fully managed WordPress hosts do not allow extra database users, routines or triggers. Initial setup targets self-managed, VPS, dedicated or cooperative environments; see the operator instructions included with the plugin.

== Frequently Asked Questions ==

= Does WriteLeash protect every WordPress database write? =

No. Only Redirection 5.5.2 global/select-all Bulk Disable is certified. Other
WordPress writes, Redirection operations and arbitrary plugin code are outside
the claim. Unknown Redirection versions fail closed.

= What happens when the logical budget is exceeded? =

The request is denied before Guard commits, and the tested matrix confirms the
rollback using a fresh database connection. The physical ceiling is P=2000;
neither limit is a general database firewall or hostile-plugin boundary.

= Does uninstall remove the database users, routines or triggers? =

No. WordPress uninstall removes only WriteLeash's local WordPress state. The restricted database account, helper table, routines, grants and policy trigger are managed by the trusted operator lifecycle and are intentionally left in place until an operator removes them.

= Is this a "protect everything" security suite? =

No. The cooperative contract does not contain hostile plugins or stolen
database credentials, reverse external effects, or certify multisite.

== Changelog ==

= 0.1.0 development candidate (not a public release) =
* Pre-release technical package for the advanced Redirection substrate.
* Mutation budget for the certified Redirection 5.5.2 global/select-all Bulk Disable operation (physical ceiling 2000).
* Release-matrix coverage on MySQL 8.0.44 and MariaDB 10.11.15 with fresh-observer durability checks.
* Admin readiness/budget/outcome interface and WP-CLI status, doctor and demo commands.
* GPLv2-or-later licensing with the full license text included.
