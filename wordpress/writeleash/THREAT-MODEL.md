# WriteLeash for WordPress V0.1 threat model

Final review for [#61](https://github.com/MrDarkRoot/CommitCap/issues/61). This is
the authoritative statement of what the cooperative shared-runtime design does
and does not protect. It is written from the adversarial suites in
`wordpress/tests/` and the merged gates #54/#56/#57/#78/#82/#83/#84/#87/#58/#60.

## Security claim

WriteLeash protects **one** certified operation under a cooperative model:

```text
Redirection 5.5.2 exactly
  Redirects -> select all matching -> Bulk Actions -> Disable
  POST /wp-json/redirection/v1/bulk/redirect/disable
  global=true, items empty, filterBy empty
  operation id: redirection-5.5.2-bulk-disable-global
  mutation: UPDATE wp_redirection_items, physical ceiling P=2000, logical budget 0<=L<=2000

known versioned adapter
  -> restricted shared runtime identity
  -> Guard owns START TRANSACTION / COMMIT / ROLLBACK
  -> supported UPDATE row events counted by the reviewed trigger
  -> logical check before Guard COMMIT
  -> COMMIT or typed DENIED
```

Explicit non-claims: hostile raw DB client containment, credential-theft
containment, generic SQL firewall, arbitrary plugin containment, task-wide or
cross-transaction budgets, INSERT/DELETE budget enforcement, and rollback of
email/HTTP/filesystem effects. Redirection 5.5.2's other routes, other plugins
and normal WordPress writes are **not** protected by this claim.

## Actors

| Actor / path | Credentials | Allowed DB authority | Can call helper routines | Can bypass Guard | Inside claim |
| --- | --- | --- | --- | --- | --- |
| Trusted operator / installer | root or DBA | full lifecycle DDL/DCL | yes | yes (trusted) | Operator lifecycle only, never retained by product |
| WordPress Admin / site owner | WP session + `manage_options` | none direct | no | no | Yes: config/enable/disable/demo surfaces |
| Normal WordPress DB identity | wp-config | ordinary broad application grants | yes, in this fixture (schema-wide EXECUTE) | yes (by design) | No: ordinary WordPress/plugin work |
| Restricted shared runtime | `WRITELEASH_DB_*` | 5 routine EXECUTE, helper SELECT, exact target SELECT/UPDATE | yes, reviewed routines only | no (trigger + Guard) | Yes: certified adapter execution only |
| WriteLeash Guard | PHP | owns transaction on runtime | yes | owns COMMIT/ROLLBACK | Yes: cooperative enforcement point |
| Reviewed Redirection adapter | PHP + runtime | invokes `Red_Item::set_status_all()` | via Guard only | no | Yes: the one certified call path |
| Unrelated normal plugin | normal WP DB identity | ordinary application grants | yes | yes | No: out of scope |
| Hostile PHP plugin | normal WP DB identity | ordinary application grants | yes | yes; can forge local options and call raw SQL | No: OUTSIDE CONTRACT |
| Raw DB client with runtime credentials | stolen runtime credential | same as runtime | yes | yes; no PHP Guard | No: OUTSIDE CONTRACT |
| Raw DB client with normal credentials | stolen wp-config credential | ordinary application grants | yes | yes | No: OUTSIDE CONTRACT |
| Fresh independent observer | test/operator only | read/verify | no | n/a | Evidence tooling, not a product actor |
| WP-CLI operator | local shell on host | invokes product services | through product | n/a | Yes: status/doctor/demo diagnostics |

## Trust boundaries

* **Installer boundary.** Installer/root credentials are used only by
  operator/tooling plans (`Provisioning_Plan`) and are never stored in
  `wp_options`, transients, normal runtime PHP, REST responses, CLI JSON or
  Admin HTML. The pinned fixture proves this with a DB-level scan of
  `wp_options` and secret checks on status/Admin output
  (`cases-61-threat-model.php`, `cases-60.php`).
* **Identity boundary.** Normal WordPress DB identity and restricted runtime are
  distinct accounts; the runtime holds only the reviewed grants. In the pinned
  fixture the normal identity keeps broad ordinary application authority
  (including schema-wide EXECUTE), which is why it is outside the cooperative
  envelope (`cases-61-threat-model.php`, `cases.php`).
* **Cooperative boundary.** Guard protects the supported adapter call, not the
  database. Any PHP that bypasses `Guard::update()` or any raw client is
  outside contract.

## Database identities

| Identity | Authority | Evidence |
| --- | --- | --- |
| Installer/operator | privileged lifecycle DDL/DCL, rotation/drain | `test-84-provisioning-plan.php` |
| Normal WordPress | ordinary application grants; no WriteLeash constraint | `cases.php`, `cases-61-threat-model.php` |
| Restricted runtime | EXECUTE on open/close/count/policy/attest; SELECT on `writeleash_v01_state`; exactly SELECT, UPDATE on each certified target; no DDL/TRIGGER/GRANT; no WordPress options | `cases-78.php`, `cases-61-threat-model.php`, `test-83-shared-runtime-doctor.php` |

## Reachable DB graph (pinned fixture)

* Reviewed helper `writeleash_v01_state` and five DEFINER routines.
* Redirection target `wp_redirection_items` with one canonical BEFORE UPDATE
  trigger (P=2000).
* Optional WriteLeash-owned disposable demo target `wp_writeleash_demo_rows` with
  one canonical trigger (P=6), only while provisioned.
* Observed target schema: InnoDB, no foreign keys, no partitions, isolation
  `REPEATABLE-READ` (both engines). Exact values are printed by
  `cases-61-threat-model.php`.
* Any other runtime-writable object without a reviewed policy is either absent
  or causes Doctor UNKNOWN → production NOT_READY. Unreviewed FK cascade,
  MyISAM storage and foreign triggered paths are refused, not certified
  (`cases-61-threat-model.php`, `test-83-shared-runtime-doctor.php`).

## Budget semantics

* L is a **logical per-transaction** bound below physical P; the physical
  trigger counts row events and P is a hard DB ceiling.
* A statement may execute row events up to P, then Guard's pre-COMMIT check
  denies when `consumed > L`, rolling back while the owned transaction is
  intact. Logical and physical denials are distinct typed outcomes
  (`test-82-logical-budget.php`, `cases.php`, `cases-78.php`).
* L is **not** a cross-request wallet: every certified request gets a fresh
  transaction, connection-scoped accounting and its own decision
  (`cases-61-threat-model.php`).
* Bounded overlapping concurrency is proven with a trusted two-stage row-lock
  barrier (`concurrent-61.php`): two real certified Redirection requests were
  held concurrently inside distinct restricted DB transactions. Both distinct
  runtime sessions were observed blocked on the certified target UPDATE before
  the barrier was released, and both committed independently with
  per-connection accounting. This covers this bounded tested pattern only; it
  is not a claim about all concurrent workloads.

## Fail-closed rules

For the dangerous certified candidate, if WriteLeash cannot prove exact version,
exact handler identity, restricted runtime, exact grants, canonical policy,
canonical P=2000 and valid config, then there is **no stock fallback and no
mutation**: 503 typed refusal with zero normal or restricted plugin UPDATE
(`cases.php`, `cases-78.php`, `cases-60.php`, `cases-61-threat-model.php`).
Version drift, handler drift, missing/corrupt trigger, P drift, extra/missing
grants, corrupt sibling policy and unreviewed reachable triggers all converge
on this rule.

## Adversarial test matrix

| Case | Classification | Evidence |
| --- | --- | --- |
| Real safe 6-row COMMIT (L=10) + fresh observer | SUPPORTED + TESTED | `cases.php`, `cases-60.php` |
| Real 6-row logical denial (L=5) + fresh observer | SUPPORTED + TESTED | `cases.php`, `cases-78.php` |
| Physical denial at P | SUPPORTED + TESTED | `cases.php`, `test-82-logical-budget.php` |
| Wrong Redirection version / unavailable | UNSUPPORTED + REJECTED | `cases.php`, `cases-78.php` |
| Wrong matched handler identity at exact 5.5.2 | UNSUPPORTED + REJECTED | `cases-61-threat-model.php` |
| Disabled / missing / malformed config | UNSUPPORTED + REJECTED | `cases.php`, `cases-78.php`, `cases-60.php` |
| Runtime unavailable / wrong credential | UNSUPPORTED + REJECTED | `cases-78.php`, `cases-60.php`, `cases-61-threat-model.php` |
| Missing/extra/wrong-kind target privileges | UNSUPPORTED + REJECTED | `cases-78.php`, `test-83-shared-runtime-doctor.php` |
| Missing/foreign/P-changed/extra target trigger | UNSUPPORTED + REJECTED | `cases.php`, `cases-61-threat-model.php` |
| Corrupt reachable demo sibling (P/trigger/grant) | UNSUPPORTED + REJECTED | `cases-61-threat-model.php` |
| Absent (unprovisioned) demo sibling | SUPPORTED + TESTED (not corruption) | `cases-61-threat-model.php` |
| Foreign runtime-writable triggered path | UNSUPPORTED + REJECTED | `test-83-shared-runtime-doctor.php`, `cases-61-threat-model.php` |
| Runtime direct helper/routine/trigger/grant tamper (13 probes) | UNSUPPORTED + REJECTED | `cases-61-threat-model.php`, `test-84-provisioning-plan.php` |
| Trusted helper/routine tamper detection | UNSUPPORTED + REJECTED (Doctor FAIL) | `test-83-shared-runtime-doctor.php` |
| Callback exception / DB error / swallowed error | SUPPORTED + TESTED (fail closed) | `cases.php`, `guard/cases.php`, `test-82-logical-budget.php` |
| Savepoints inside the supported callback | SUPPORTED + TESTED (no false accounting) | `guard/cases.php`, `test-82-logical-budget.php` |
| Redirection 5.5.2 path issues no transaction control (static scan) | SUPPORTED + TESTED | `cases-61-threat-model.php` |
| Callback COMMIT/ROLLBACK, mysqli COMMIT, CLOSE→OPEN reset | OUTSIDE CONTRACT + DETECTED where cooperative | `guard/cases.php` |
| autocommit off / existing transaction / nested guard | UNSUPPORTED + REJECTED | `guard/cases.php`, `cases.php` |
| Mid-callback connection death (server rollback) | SUPPORTED + TESTED (typed failure, fresh observer clean) | `cases-61-threat-model.php` |
| Dropped connection with transparent wpdb reconnect | OUTSIDE CONTRACT (new full path, per-connection accounting) | `cases-61-threat-model.php` |
| Unrecoverable runtime credential | UNSUPPORTED + REJECTED (typed) | `cases-61-threat-model.php` |
| Two overlapping certified requests (trusted row-lock barrier; both distinct runtime sessions observed blocked on the certified UPDATE) | SUPPORTED + TESTED (per-connection accounting) | `concurrent-61.php` |
| Denied retry then commit | SUPPORTED + TESTED (per-transaction budget) | `cases-61-threat-model.php` |
| Unsupported Redirection routes (items/global=false/filtered/Enable/Reset/Delete/edits/hits) | SUPPORTED (stock) | `cases.php`, `cases-60.php` |
| WriteLeash deactivated | OUTSIDE CONTRACT (protection inactive; stock) | `cases-61-deactivated.php` |
| Admin capability/nonce/GET/array/CSRF-style direct call | UNSUPPORTED + REJECTED | `cases-60.php` |
| CLI invalid args/format | UNSUPPORTED + REJECTED | `cases-60.php`, adapter `in-container.sh` |
| Forged/malformed Last_Outcome | OUTSIDE CONTRACT (ignored; never authority) | `cases-60.php` |
| Uninstall local state / reinstall defaults | SUPPORTED + TESTED | `cases-60-uninstall.php`, `cases-60-reinstall.php` |
| Credential rotation + drain + ROTATED_UNSAFE | SUPPORTED + TESTED | `test-84-provisioning-plan.php` |

Every row above is executable in CI on both pinned engines, except where the
matrices cite Guard/Doctor/Provisioning suites that run in the engine workflow.

## Outside-contract paths

* Raw DB clients holding runtime or normal credentials, and any SQL outside
  `Guard::update()`.
* Hostile PHP plugins, alternate `mysqli`/`wpdb` objects, and any code that
  does not route through the certified adapter.
* Callback-issued COMMIT/ROLLBACK/autocommit changes and direct lifecycle
  routine calls. Guard detects many of these, but cannot promise rollback once
  a callback ended the owned transaction.
* Normal WordPress writes and every non-certified Redirection route.
* External effects (HTTP, email, filesystem) are never rolled back by the
  database transaction.

## Unknown / untested paths

* **Ambiguous COMMIT** (COMMIT sent, confirmation lost): durability is
  unknown; Guard reports `commit_failed_or_unknown` and never claims rollback.
  Not deterministically testable in CI → UNKNOWN, documented.
* **Isolation modes other than the engine default `REPEATABLE-READ`**: only the
  default is recorded/tested; others UNKNOWN.
* **Mid-COMMIT network ambiguity** and process exit after COMMIT was sent:
  durability may already exist; classified UNKNOWN.
* **Multisite/network activation and provisioning**: UNKNOWN/unsupported for
  V0.1; Doctor fails closed on network activation.
* **Arbitrary FK/cascade/partition write graphs**: unsupported; only the
  observed Redirection graph (no FK/partition, InnoDB) is tested.
* **Arbitrary plugin transaction control**: outside contract, not generalized.
* **Fleet/staging clone behavior**: a DB clone plus copied runtime credential
  can duplicate credential/environment assumptions; rotate per environment and
  re-run Doctor. No fleet management is implemented.
* **External effects**: unobserved for the selected path; not proven absent.

## Credential lifecycle

* Installer/operator credentials are used only by explicit trusted plans; the
  product never retains or requests them (`test-84-provisioning-plan.php`,
  `cases-61-threat-model.php`).
* Runtime credentials live only in protected `wp-config.php` constants; the
  runtime grants are exactly the reviewed surface.
* Rotation: unique-account precondition, durable `ROTATED_UNSAFE` marker before
  `ALTER USER`, deterministic session drain, and blocked Doctor/Guard until
  drain completes (`test-84-provisioning-plan.php`). An already-authenticated
  session is not invalidated by `ALTER USER` alone.
* Stolen runtime credentials are OUTSIDE CONTRACT: the raw client can call
  reachable objects without Guard. The design bounds authority, it does not
  defeat credential theft.

## Deactivation and uninstall

* **Deactivation** removes WriteLeash's REST interception; the global Bulk
  Disable becomes stock/unprotected. Proven executably in
  `cases-61-deactivated.php`. Protection is inactive while the plugin is
  inactive.
* **Uninstall** removes only local WordPress state: `writeleash_version`, the
  certified-operation config option, the last-outcome option and the stale #87
  budget option. Trusted DB users, grants, triggers, routines, helper state,
  demo objects and Redirection data remain; a reinstall starts disabled with
  no budget/evidence (`cases-60-uninstall.php`, `cases-60-reinstall.php`).
* Surviving DB policies do not imply protection without the PHP adapter/Guard.

## Residual risks

* A hostile plugin with normal WordPress DB credentials can bypass the
  cooperative envelope entirely; this is inherent to the chosen model.
* A stolen runtime credential can be used outside Guard.
* Local `Last_Outcome` is informational and forgeable by anyone who can write
  options; it is never a readiness or execution authority.
* The claim is pinned to Redirection 5.5.2 on MySQL 8.0.44 / MariaDB 10.11.15
  with the observed schema graph; other versions, engines and schemas are
  fail-closed but not certified.
* Admin notices are ephemeral bounded feedback and may briefly survive a
  reinstall within their 120-second TTL; they never carry a production
  COMMITTED/DENIED record and are not wildcard-scanned.

## Release-claim wording

Approved:

> WriteLeash protects one certified Redirection 5.5.2 global Bulk Disable
> operation under a cooperative restricted-runtime, Guard-owned transaction.

Not approved: "WriteLeash protects WordPress writes", "database firewall",
"plugins cannot modify more than N rows", "all side effects are rolled back",
"protects your database", or any universal host/compatibility claim.
