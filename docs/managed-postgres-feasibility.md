# Managed PostgreSQL feasibility: native transaction-state experiment (#10)

**Documentary review snapshot: 2026-09-23.** The analysis was originally performed against `origin/main` `2538963906967f2a7d7ae79e6dfdc992a3b7d199`; PR #13 was later refreshed onto current `main` without changing the substantive feasibility conclusions. Candidate: **Amazon RDS for PostgreSQL 16** (including the 16.4 column in the provider's [extension-version table](https://docs.aws.amazon.com/AmazonRDS/latest/PostgreSQLReleaseNotes/postgresql-extensions.html#postgresql-extensions-16x)). Verdict for the **existing C `.so`/preload mechanism unchanged: BLOCKED by the documented service model**. Real managed deployment: **NOT TESTED** (no authorized instance/access/cost approval). This is a compatibility analysis, not a provider-side failure log or a statement about all architectures/clouds.

> Current product authority is #106: safe WooCommerce bulk price changes,
> planned but not implemented; #107–#112 remain pending and public release is
> deferred. This dated assessment covers only the prior PostgreSQL/native
> research mechanism. Any `CommitCap` wording below is historical terminology
> from the 2026-09-23 review, not active repository or product identity.

## Existing mechanism and compatibility/constraint matrix

The local [Dockerfile](../experiments/native_tx_state/Dockerfile) installs an out-of-tree `.so` and `.control`/extension SQL into PostgreSQL's server filesystem. [Compose](../experiments/native_tx_state/docker-compose.yml) starts `postgres -c shared_preload_libraries=writeleash_native_tx_state`. [C source](../experiments/native_tx_state/writeleash_native_tx_state.c) registers `RegisterXactCallback` and `RegisterSubXactCallback` in `_PG_init`, allocates top-memory backend-local state, and enforces sticky `XACT_EVENT_PRE_COMMIT` denial. [Setup](../experiments/native_tx_state/setup.sql) calls `CREATE EXTENSION`, uses a trusted owner for tables/functions and a separate restricted writer. Local PG16.4 [PR #8 evidence](../experiments/native_tx_state/evidence/2026-09-23-cc020-cc022-a996eed.txt) does **not** test RDS.

| Constraint | RDS for PostgreSQL 16 evidence (official sources consulted 2026-09-23) | Status for current experiment |
| --- | --- | --- |
| PostgreSQL engine and extension availability | AWS publishes a PG16 version/extension table, including 16.4; check the running minor and `SHOW rds.extensions` for a real instance. CommitCap is not a listed provider extension. [Version table](https://docs.aws.amazon.com/AmazonRDS/latest/PostgreSQLReleaseNotes/postgresql-extensions.html#postgresql-extensions-16x), [extension support](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/PostgreSQL.Concepts.General.FeatureSupport.Extensions.html). | **verified** documented version table; **blocked** for this unlisted module via documented installation. No actual instance checked. |
| Upload native binary/control/SQL | RDS has **no shell/filesystem access** for the customer. AWS describes its installable extensions as those available for the engine version; `rds.allowed_extensions='*'` means *any available supported extension*, not arbitrary native uploads. The experiment requires files under `$libdir` and extension share directory. [RDS DBA differences](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Appendix.PostgreSQL.CommonDBATasks.html), [supported extensions and restriction](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/PostgreSQL.Concepts.General.FeatureSupport.Extensions.html). | **blocked** for unchanged module through documented customer interfaces. Provider exception/partner packaging **unverified**. |
| Privileged installation and writer separation | No PostgreSQL superuser account: highest customer role is `rds_superuser`. Most extensions require `rds_superuser`; trusted preinstalled extensions may be installed by database-`CREATE` users on PG13+. `writeleash_native_admin` in Docker is true superuser; `writeleash_owner` and `writeleash_writer` are separate roles with narrow grants. [DBA differences](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Appendix.PostgreSQL.CommonDBATasks.html), [roles](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Appendix.PostgreSQL.CommonDBATasks.Roles.html), [extensions](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Appendix.PostgreSQL.CommonDBATasks.Extensions.html). | **verified** RDS privilege distinction; **unverified** whether exact owner/grant topology works on a supplied provider-installed equivalent. Do not give writer `rds_superuser`/ownership. |
| `shared_preload_libraries`, GUCs and restarts | RDS permits configuring available modules via a **custom DB parameter group**, not arbitrary `.so` paths; current Compose explicitly preloads CommitCap to define its `PGC_SUSET` test parameters in every backend. AWS describes custom parameter groups/preload for available modules; restart/apply behavior depends on parameter. [Extensions/preload](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Appendix.PostgreSQL.CommonDBATasks.Extensions.html), [parameter groups](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_WorkingWithParamGroups.html). | **blocked** for absent native module; exact parameter allowlist/reboot behavior for hypothetical packaged module **unverified**. No cloud settings changed. |
| Trusted Language Extensions (`pg_tle`) | AWS supports `pg_tle` on RDS PG16.1+; it supports JS, Perl, Tcl, PL/pgSQL and SQL extension bodies with a documented SQL hook API. A `pg_tle` *extension name* does not load this C library or provide its native xact/subxact callback registration and sticky state. [TLE overview](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/PostgreSQL_trusted_language_extension.html), [hooks reference](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/PostgreSQL_trusted_language_extension-hooks-reference.html). | **verified** documented language/interface limits; equivalence for `XACT_EVENT_PRE_COMMIT`/`SUBXACT_EVENT_ABORT_SUB` **not established**; treating TLE as a drop-in C callback host is **blocked**. |
| Triggers, object ownership, policy-state tampering | Provider must allow trusted installation and enforce writer's lack of protected-table ownership, trusted schema/functions, DDL/trigger controls, role membership, and replication-role changes. Current fixture checks these on Docker in `run.sh`, not RDS. [Roles](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Appendix.PostgreSQL.CommonDBATasks.Roles.html), [local privilege audit](../experiments/native_tx_state/README.md#privilege-boundary). | **unverified** in managed environment. Native state is backend-local; no SQL policy table in this experiment. |
| Major upgrade / availability / operational isolation | PostgreSQL's server C ABI must be revalidated per major; AWS documents extension version availability and notes extension upgrades are **not automatic** during engine upgrades. A disposable DB/instance, parameter-group change, reboot and teardown would need explicit authorization. [RDS extension lifecycle](https://docs.aws.amazon.com/AmazonRDS/latest/PostgreSQLReleaseNotes/postgresql-extensions.html), [parameter groups](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_WorkingWithParamGroups.html), [local ABI note](../experiments/native_tx_state/README.md#callback-interface). | **verified** provider upgrade warning; exact operational cost/downtime and rollback **unverified**. |

## Decision proposal (not an implementation)

**Do not select the unchanged native mechanism for standard RDS deployment.** RDS's documented customer interface cannot receive the experiment's native binary/control files, which must exist before `CREATE EXTENSION` or preload can work. `pg_tle` provides different, restricted interfaces: rewriting the extension in a trusted language or substituting ordinary transactional PL/pgSQL state would not automatically preserve sticky top-level denial (the [SQL/PLpgSQL falsification](limitations.md#irreversible-denial-falsification-evidence) shows why). A provider-packaged native module would require explicit provider support, maintenance and versioned callback proof; that route is **unverified**, not a promised minimal adaptation. Alternatively evaluate a narrow trusted API/stored procedure for a known operation, with a *separate* proof of whole-transaction sticky denial and confinement under the same writer trust boundary. Never silently relax the invariant or pivot to an unverified proxy.

If the maintainer obtains explicit authorization for an actual disposable nonproduction RDS instance **and its costs/settings**, first capture provider tier/minor, `SHOW server_version; SHOW rds.extensions; SHOW shared_preload_libraries;`, and `SELECT name FROM pg_available_extensions WHERE name='writeleash_native_tx_state';` via redacted read-only SQL, then document any actual installation/preload attempt and fresh-connection durable-state oracle. This step has **not** occurred; no provider credentials, cloud resources, or billed settings were used.

## Additional deployment paths — documentation reviewed 2026-09-23

### Candidate A: CloudNativePG 1.28 on Google Kubernetes Engine (GKE Standard)

This is **operator-managed PostgreSQL running on managed Kubernetes**, **not Google Cloud SQL or a fully provider-managed PostgreSQL DBaaS**. Google [documents deployment of PostgreSQL with CloudNativePG on GKE](https://cloud.google.com/kubernetes-engine/docs/tutorials/stateful-workloads/cloudnativepg). The operator [accepts compatible custom PostgreSQL images](https://cloudnative-pg.io/docs/1.28/container_images), so a **customer-controlled image containing the PG16.4 native `.so` and extension control/SQL files** is a documented distribution path in principle (different from RDS customer uploads). [Custom `shared_preload_libraries`](https://cloudnative-pg.io/docs/1.28/postgresql_conf#shared-preload-libraries) can be set in the Cluster resource; if the binary is absent, **startup fails**. It is technically plausible for the existing PG16.4 research binary/callback interface to run when compiled against a matching server/image ABI, but neither CloudNativePG nor GKE documentation certifies CommitCap's `RegisterXactCallback`/`RegisterSubXactCallback` behavior. PostgreSQL's [extension/transaction API and version-specific callback test](../experiments/native_tx_state/README.md#callback-interface) still require an actual managed-environment reproduction.

| Requirement | Dated official evidence | Status for CommitCap |
| --- | --- | --- |
| Exact server version and C ABI | [CNPG image requirements](https://cloudnative-pg.io/docs/1.28/container_images): only supported PG versions; image tag must identify major, e.g. `16...`; accepts compatible custom image. Build/install against the **same PG16.4** server distribution/architecture or rebuild and rerun tests on a current PG16 minor. | **Verified-by-docs** custom image model; **UNTESTED** actual PG16.4 runtime/ABI and callback registration on GKE. |
| Native extension installation | A custom image can embed the experiment's `.so`, `.control`, SQL just as the [local Dockerfile](../experiments/native_tx_state/Dockerfile) does; SQL `CREATE EXTENSION` requires an operator-controlled trusted setup role. CNPG's [PG18+ ImageVolume extensions](https://cloudnative-pg.io/docs/1.28/imagevolume_extensions#requirements) offer a separate OCI packaging path but **require PostgreSQL 18+** and Kubernetes/CRI versions listed there, so they are **not an unchanged PG16.4 alternative**. | **Technically plausible, not demonstrated** with customer-built PG16 image. PG18 ImageVolume for unchanged binary: **blocked by version/ABI**. |
| Preload and native callbacks | [CNPG preload configuration](https://cloudnative-pg.io/docs/1.28/postgresql_conf#shared-preload-libraries) supports explicit libraries through `.spec.postgresql.shared_preload_libraries`; restarting with a missing library prevents recovery. Native callback runtime is provided by upstream PG16.4, **not attested by provider documentation** for this particular extension. | Preload configuration **verified-by-docs**; actual CommitCap callbacks/sticky `XACT_EVENT_PRE_COMMIT` after SAVEPOINT/catch **UNTESTED**. |
| Trusted operator vs protected writer | [CNPG security model](https://cloudnative-pg.io/docs/1.28/security#trusted-cluster-resource-writers): Kubernetes Cluster-resource writers have superuser-level influence and must be trusted/RBAC-restricted; PostgreSQL superuser access disabled for remote connections by default, but operator reconciles as superuser. Separate NOLOGIN owner and restricted writer grants must be created/checked on a real instance. | **Verified-by-docs** cluster-writer trust boundary; exact SQL role topology, trigger/DDL/SET ROLE/GUC isolation **UNTESTED** on GKE. Never grant untrusted writer Cluster CR write access. |
| Changes and upgrades | [CNPG rolling updates](https://cloudnative-pg.io/docs/1.28/rolling_update) replace replicas then primary on image/config changes; [preload docs](https://cloudnative-pg.io/docs/1.28/postgresql_conf#shared-preload-libraries) warn missing library prevents startup. Extension versioning/PG C ABI must be revalidated per major/minor. Cloud service costs include GKE compute/cluster, storage, registry and logging. | Operational mechanism **verified-by-docs**; image availability, cluster policy/admission, safe rollback, HA and downtime **UNTESTED**. |

**Verdict:** credible **operator-managed-on-GKE hypothesis, not an actual managed PostgreSQL demonstration**. It preserves the possibility of native callbacks and restricted SQL writer because the infrastructure operator, not the writer, controls the image and Cluster configuration. It adds substantial customer responsibility for PostgreSQL operations and is **not equivalent to RDS** for prospective users. No Kubernetes/cloud object was created and no container image was pushed as part of this research.

### Candidate B: Crunchy Bridge PG16, provider packaging request

Crunchy Bridge officially [lists supported PostgreSQL 16 and its version/upgrade lifecycle](https://docs.crunchybridge.com/concepts/postgres-versions). Its [extension catalog and preload guide](https://docs.crunchybridge.com/extensions-and-languages) describes preinstalled extensions and changing `shared_preload_libraries` with the administrative `postgres` role followed by a restart; for *unlisted* extensions it instructs customers to [contact support](https://docs.crunchybridge.com/extensions-and-languages#need-something-you-dont-see). The [role guide](https://docs.crunchybridge.com/concepts/users) distinguishes admin `postgres` from app/users and permits administrator-controlled grants. These sources do **not** say that arbitrary user-supplied C binaries/control files can be uploaded or that this extension will be accepted/packaged; `ALTER SYSTEM` for a nonexistent `.so` is not an installation path. Exact PG16 **minor 16.4** availability, callback ABI/behavior, elevated privileges, cluster isolation and extension update/rollback policy remain **UNVERIFIED**. Provider-approved packaging is only a **research hypothesis** and would need explicit founder approval even to contact support. Real installation/sticky-denial proof: **NOT TESTED**. Do not call Bridge technically supported for CommitCap on these documents alone.

### Maintainer decision and proposed authorized test (no action taken)

The most actionable *technically documented* native installation route is a **customer-operated CloudNativePG PG16 image on GKE Standard**, conditional on whether an operator-managed database meets the founder's target adoption/deployment bar. A **fully managed DBaaS** native extension path remains **unconfirmed**: RDS unchanged blocked; Bridge provider packaging unverified. If that distinction makes GKE unsuitable, request founder decision to explore provider approval rather than inventing an architecture or weakening sticky top-level denial.

Before any real managed-platform attempt: founder chooses GKE/CNPG or approves provider contact; supplies an explicit nonproduction cloud account/project and a **specific maximum cost/region/duration** after a provider-pricing estimate; authorizes Kubernetes resource creation, custom image/registry, database parameter/restart and cleanup. A bounded experiment would use a disposable PG16.4 (or rebuilt current PG16) instance with synthetic rows, trusted cluster/image operator separate from a no-DDL/no-owner SQL writer; confirm loaded library/engine version, positive and forbidden UPDATE, SAVEPOINT and caught-error sticky denial, PRE_COMMIT rejection, and fresh trusted-admin durable state. Record cluster tier/storage/image digest/redacted commands and then remove the instance, storage and image. **No such approval, access, deployment, provider confirmation or provider-side PASS exists today; Success Checklist deployment #2 remains NOT MET.**

### Proposed nonproduction experiment protocol (NOT EXECUTED)

This protocol is for **CloudNativePG 1.28 on GKE Standard**, conditional on a
founder decision that operator-managed PostgreSQL is a useful deployment
category. It does not establish compatibility with a fully managed DBaaS.
Official [GKE's CloudNativePG tutorial](https://cloud.google.com/kubernetes-engine/docs/tutorials/stateful-workloads/cloudnativepg),
[CNPG image requirements](https://cloudnative-pg.io/docs/1.28/container_images),
[preload configuration and missing-library warning](https://cloudnative-pg.io/docs/1.28/postgresql_conf#shared-preload-libraries),
and [Cluster-resource trust boundary](https://cloudnative-pg.io/docs/1.28/security#trusted-cluster-resource-writers)
were rechecked on 2026-09-23. These establish *configuration mechanisms*, not
successful CommitCap installation or runtime behavior.

1. **Authorization and inventory before any resource creation.** Record the
   selected deployment category; authorized project/account, region and
   namespace; operator and Kubernetes versions; named image registry; maximum
   spend and duration; storage class, size and deletion policy; and who may
   create/delete the GKE cluster, operator, `Cluster` resource and registry
   image. Obtain a dated provider-pricing estimate including cluster control
   plane, nodes, persistent disks, registry, networking and logging; set a
   stop time and teardown owner. No production network or database access.
2. **Preflight.** On a disposable single-instance PG16 environment, build an
   operator-compatible image containing the existing `.so`, `.control` and SQL
   files, compiled against the *exact* server build and CPU architecture. Tag
   it with a detectable `16...` major prefix and record its immutable digest.
   Confirm CNPG's required PostgreSQL executables, image user/filesystem
   compatibility, workload admission, resource quotas, disk availability and
   registry pull rights. A locally working Docker image is only a preflight,
   never a managed-environment PASS. PG18 ImageVolume packaging is not a PG16
   substitute.
3. **Disposable deployment.** The trusted Kubernetes operator alone configures
   `.spec.postgresql.shared_preload_libraries` to include
   `writeleash_native_tx_state`; CNPG manages the configuration (do not use
   `ALTER SYSTEM`). Restrict Cluster-resource writes, image publication and
   secret access to trusted operators; the SQL writer must not hold Kubernetes
   privileges. Record the actual server version, image digest, loaded preload,
   extension availability and operator logs. If the instance does not start or
   cannot load the module, stop before issuing writer credentials. Only the
   trusted PostgreSQL setup administrator installs the extension and creates
   the existing NOLOGIN owner / restricted `writeleash_writer` fixture; verify
   ownership, trigger state, grants, memberships, GUC-setting permissions and
   `max_prepared_transactions=0` rather than assuming local Docker defaults.
   Replace the local Compose test passwords with ephemeral secrets **never
   printed in public logs**.
4. **Security proof, in order.** On synthetic `subscriptions`, `users` and
   `refunds`, execute the integrated `run.sh` *assertions* against the actual
   managed PostgreSQL service using a reviewed adaptation of its connection
   setup (the existing `run.sh` itself starts and deletes local Compose, so
   running it unchanged is **not** a cloud test). Prove permitted COMMIT;
   six individually small subscriptions updates in one transaction ABORT;
   forbidden role promotion ABORT; exact numeric 90.00/100.00 COMMIT and
   101.00 ABORT; both-order independent policy accounting; sticky denial after
   `ROLLBACK TO SAVEPOINT` and caught exceptions; nested CC-012 and two-session
   READ COMMITTED CC-036 lock-contention A/B/C with verified blocking PIDs.
   Every denied transaction requires a **fresh trusted-admin connection** to
   check all protected rows and an unprotected sibling audit row for rollback.
   A zero-exit local Compose run cannot substitute for these managed results.
5. **Record outcome and exit.** Preserve redacted commands/manifests, exact
   Git and image SHAs, PostgreSQL/CNPG/GKE versions, region and resource sizes,
   writer/admin role audit, assertion output, SQLSTATE, fresh-connection rows,
   skipped-test list and actual exit status. Any supported over-authority
   durable write is a security failure: retain the minimum synthetic repro,
   stop testing and return it for maintainer review. No missing, skipped or
   flaky contention assertion may be called PASS.

| Expected failure mode (not observed here) | Stop / rollback / evidence |
| --- | --- |
| Image cannot satisfy CNPG requirements, registry pull or Pod admission fails | Stop before PostgreSQL setup; record sanitized Pod events. Fix the *disposable* image/manifest only after review or delete the candidate cluster. |
| Missing/mismatched native library, preload rejects the setting or prevents startup | Record server/operator error and image digest. Revert the **disposable** Cluster to a known compatible image/configuration with the module removed (CNPG documents restart on preload removal); if recovery fails, delete the disposable Cluster and its volumes. Do not apply the change to an existing database. |
| Extension installation or role/grant/trigger topology unavailable | Do not relax the writer boundary or grant owner/superuser rights; record SQLSTATE, exact actor and available provider permissions, then terminate the experiment. |
| Sticky denial, callback cleanup, numeric precision or contention differs from local PG16.4 evidence | Retain exact sanitized reproduction and fresh-admin durability oracle; classify FAIL or UNTESTED, stop that deployment claim, do not weaken assertions. |
| Restart/rollout/upgrade changes the ABI or stops the disposable instance | Preserve version/digest and redacted operator logs. Restore the compatible disposable image/configuration or tear down; a new PostgreSQL version needs its own full proof. |

**Teardown verification:** after collecting only sanitized evidence, revoke
ephemeral SQL credentials; delete the test `Cluster`, confirm its Pods, Services,
Secrets and PVC/PV resources are removed according to the approved retention
policy; remove the test image/tag from the approved registry, the dedicated
namespace and (if created solely for this test) operator/GKE cluster; inspect
project billing and remaining storage/network/logging resources against the
approved inventory. Do not delete shared infrastructure. A provider-approved
Crunchy Bridge native-package experiment would require a separate provider
confirmation, scope, rollback and authorization; nothing in this protocol
authorizes outreach or provisioning.
