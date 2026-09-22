# Threat Model

## Status And Scope

This threat model applies to CommitCap's Phase 0 PostgreSQL proof. It describes
the intended security boundary and the experiments required to validate it. No
enforcement implementation currently exists, so no mitigation described here
should be read as deployed.

The governing invariant is:

> No supported durable relational mutation may exceed the mutation authority
> granted to the actor or capability that caused it.

The initial proof is narrower: enforce a transaction-wide row-update budget on
one protected PostgreSQL relation against a semi-trusted writer.

## Assets

### Protected relational state

Rows in protected relations whose mutation volume or future mutation effect is
intended to be constrained.

### CommitCap policy state

The declared budgets, relation bindings, and future authority grants that
define permitted mutation.

### Authority accounting state

The per-transaction counters used by V0 and any future cross-transaction
capability balances. Corruption, reset, reuse, or race conditions in this state
can cause fail-open behavior.

### Enforcement functions and objects

Triggers, functions, tables, schemas, role grants, and any future extension
code that observes, accounts for, or denies mutation.

### Database roles and privileges

Role membership, ownership, grants, default privileges, function execution,
and settings that determine whether a writer can reach or bypass enforcement.

### Security claims

The support matrix and documentation are security assets. Claiming protection
for an untested path causes operators to rely on a boundary that may not exist.

## Actors

### Trusted database administrator

Installs and configures CommitCap, owns or controls privileged owner roles, and
can bypass in-database controls. The administrator is trusted for Phase 0.

### CommitCap maintainer

Defines specifications and ships implementation changes. A compromised or
careless maintainer can introduce a bypass or overstate support.

### Protected semi-trusted writer

Has limited DML authority on protected application relations. It may be buggy
or malicious and is expected to attempt alternate SQL shapes, transactions,
savepoints, procedures, concurrency, and error handling. It MUST NOT own or
administer protected or enforcement objects.

### External application

Connects actors to PostgreSQL, possibly through a connection pool. It controls
transaction boundaries, retry behavior, role selection, and query ordering. It
is not trusted to enforce the mutation budget correctly.

### Malicious or buggy automation

An AI agent, repair job, support bot, workflow engine, script, or internal tool
that can issue SQL through the protected writer's allowed interface. It may
omit predicates, repeat operations, exploit parser or trigger edge cases, or
deliberately seek a bypass.

## Trust Boundaries

### Application to PostgreSQL

The application submits SQL and transaction-control commands. CommitCap cannot
rely on the application to report truthful row counts, preserve counters, or
avoid retries.

### Protected writer to trusted enforcement

The writer can influence SQL inputs but must not modify trusted code, policy,
accounting, ownership, or execution settings. Every privilege crossing this
boundary requires review.

### Top-level transaction to subtransactions

Savepoints and PL/pgSQL exception blocks can roll back ordinary database state
without necessarily ending the top-level transaction. This boundary is a
central falsification risk for irreversible denial and correct counter unwind.

### Session to connection pool

A backend session may serve different transactions or application actors over
time. Session-local state can leak authority or stale counters unless lifecycle
behavior is explicit and tested.

### Trusted administrator to deployment

The administrator controls grants, ownership, DDL, extension installation, and
server configuration. A deployment that gives equivalent power to the
protected writer is outside the security model.

### PostgreSQL to external effects

Relational rollback does not necessarily undo effects produced by untrusted
extensions, external network calls, or other non-transactional systems. V0
does not claim to control those effects.

## Expected Attacker Capabilities

Within granted privileges, assume the protected writer can:

- issue arbitrary SQL text and prepared statements;
- wrap mutating statements in executable forms such as `EXPLAIN (ANALYZE)`;
- open, commit, and roll back transactions;
- create and roll back to savepoints;
- invoke accessible functions and stored procedures;
- use data-modifying CTEs and `INSERT ... ON CONFLICT` if granted;
- attempt `COPY` or `TRUNCATE` if deployment grants them;
- trigger foreign-key actions and user-defined triggers reachable from allowed
  DML;
- target partition parents or children reachable through granted privileges;
- run concurrent sessions;
- provoke deadlocks, serialization failures, statement errors, and retries;
- reuse pooled connections and exercise allowed role changes;
- attempt two-phase transaction preparation when the server permits it;
- inspect errors, timing, and visible database state.

Assume the protected writer does not have operating-system access, direct
database-file access, PostgreSQL superuser, object ownership, unreviewed role
membership, or the ability to alter trusted CommitCap objects. If it does, the
deployment assumptions are invalid.

## Attacker Goals

1. Commit more protected row-update events than the transaction budget.
2. Reset or evade accounting by splitting work across statements.
3. Recover a denied transaction with a savepoint or caught exception.
4. Route a mutation through syntax or execution paths enforcement misses.
5. Corrupt, race, shadow, or directly edit policy or accounting state.
6. Disable triggers or make PostgreSQL skip them.
7. Reuse session state, role changes, pooling, or retries to gain fresh
   authority unexpectedly.
8. Cause partial durability after a denial.
9. Induce false security claims for an unsupported operation.

Availability attacks are also relevant, but preventing excess durable mutation
takes precedence over fail-open availability behavior.

## PostgreSQL Bypass Surfaces

| Surface | Risk | Phase 0 disposition |
| --- | --- | --- |
| Table ownership | Owners can perform DDL and disable ordinary enforcement mechanisms | Protected writer MUST NOT own protected tables |
| `SUPERUSER` | Can bypass permissions and alter execution behavior | Explicitly outside the protected-writer model |
| Trigger disabling | `ALTER TABLE ... DISABLE TRIGGER`, replication settings, or equivalent paths may suppress accounting | Revoke capability; test documented role grants |
| DDL | Can drop, replace, detach, rename, or rewrite protected objects | Unsupported for protected writers; deny by privilege |
| Policy/accounting writes | Can increase budgets or reset counters | No direct protected-writer access permitted |
| `SECURITY DEFINER` | Unsafe owner, grants, dynamic SQL, or role behavior can escalate privilege | Not yet designed; mandatory focused review if introduced |
| `search_path` | Object shadowing can redirect trusted function references | Trusted functions require fixed safe path and qualified names where needed |
| Stored procedures | Invoker/definer identity and internal statements may bypass hooks or attribution | `UNKNOWN`; test before support |
| Data-modifying CTEs | Alternate syntax may reach update paths differently | Must obey V0 accounting; `CC-011` is release-blocking |
| Prepared statements | Repeated execution could accidentally receive statement-local authority | Must obey transaction-wide accounting; `CC-025` |
| `EXPLAIN (ANALYZE)` | Executes the wrapped mutating statement under ordinary privileges | Must obey transaction-wide accounting; `CC-033` |
| `MERGE` | Update actions are another write form on supported PostgreSQL versions | Must obey the update budget or the server version is unsupported; `CC-026` |
| `INSERT ... ON CONFLICT` | May select insert or update behavior in one command | `UNKNOWN`; not supported in V0 |
| `COPY` | Bulk insert path is not covered by an update-only budget | Unsupported in V0; do not imply protection |
| `TRUNCATE` | Removes all rows without row-level delete events | Unsupported; protected writer MUST lack privilege |
| Foreign-key cascades | One allowed action may cause many indirect row changes | `UNKNOWN`; causal accounting must be tested |
| Nested triggers | One row event may generate additional protected mutations | `UNKNOWN`; recursion and counting must be tested |
| Rewrite rules and inheritance | An ordinary-looking update may be redirected or expanded | Excluded from the V0 schema; boundary must be checked |
| Partition routing | Trigger placement, child privileges, and row movement can change event shape | `UNKNOWN`; not supported until characterized |
| Savepoints | Can recover from ordinary errors and roll back accounting state | Central falsification target; denial must poison the top-level transaction |
| PL/pgSQL exception blocks | Can catch errors using subtransactions | Same requirement as savepoints; `CC-024` must pass |
| Concurrent sessions | Races or shared state can reset, merge, or corrupt counts | Required isolation and contention tests |
| Shared capabilities | Multiple transactions may overspend a common future balance | Future work; no V0 capability claim |
| Connection pooling | Session-local state may survive transaction or actor reuse | Lifecycle cleanup and role identity must be tested |
| Transaction retries | A new transaction receives a new V0 budget | Explicit V0 limitation; future capability needed for aggregate authority |
| `SET ROLE` and role membership | Writer may inherit or assume an enforcement-owner or DDL-capable role | No bypass-capable membership permitted; `CC-028` |
| Two-phase commit | Commit may complete from another session after state cleanup | Disabled in V0; `CC-029` must verify exclusion |
| Counter overflow | An exhausted counter may wrap into apparently available authority | Reject unsafe bounds and use overflow-safe checks; `CC-031` |
| Replication and logical decoding | Downstream effects and trigger behavior differ from primary writes | Outside initial proof; no protection claim |

None of the entries marked as required or unknown are claimed mitigated.

## Security Assumptions

- PostgreSQL itself, its host, and the trusted administrator are not
  compromised.
- Protected writers receive only the minimum DML and function privileges in
  the documented deployment.
- Untrusted extensions are not used to create non-transactional side effects
  under a CommitCap claim.
- Every protected execution path is identified explicitly. Protection is not
  inferred from similar syntax.
- Policy installation and ownership are performed by a trusted administrator.
- Public claims cite a tested PostgreSQL version and support envelope.

These assumptions require deployment tests where practical. They are not a
substitute for enforcement.

## Non-Goals

Phase 0 does not attempt to:

- constrain PostgreSQL superusers, object owners, host administrators, or a
  malicious CommitCap owner;
- replace PostgreSQL IAM, grants, RLS, schema design, backups, or auditing;
- validate SQL intent or generate SQL;
- approve human workflows;
- provide semantic transition or quantitative-effect budgets;
- provide cross-transaction capabilities;
- protect MySQL or other databases;
- build a proxy, control plane, dashboard, or observability platform;
- prevent all denial of service;
- undo external non-transactional side effects.

## Claim Discipline

A mitigation moves from proposed to supported only when:

1. its behavior is normative in `SPEC.md`;
2. deployment privileges are explicit;
3. positive and adversarial regression tests pass;
4. transaction failure semantics are inspected;
5. known bypass surfaces are either tested or excluded and failed closed;
6. `docs/limitations.md` is updated.

An unknown is not a mitigation. A trigger firing in one manual example is not
proof of coverage.
