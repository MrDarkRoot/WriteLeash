# Security Policy

CommitCap is intended to become security-sensitive PostgreSQL infrastructure.
The repository contains Phase 0 research implementations and experiment
harnesses, but no released or supported enforcement implementation. There is no
current claim that installing or using this repository protects a database.

## Reporting A Vulnerability

Do not disclose a suspected vulnerability in a public issue, discussion, pull
request, or proof-of-concept repository.

Use GitHub private vulnerability reporting for this repository if it is
enabled. If it is not enabled, do not publish sensitive details; ask the
maintainers through a non-sensitive public issue to establish a private contact
channel. This document does not invent an email address or response SLA.

Before any security-sensitive implementation is released, maintainers MUST
enable GitHub private vulnerability reporting or publish a monitored private
security contact. The current fallback is not an adequate long-term disclosure
channel.

A useful private report includes:

- the affected version or commit;
- the operation and deployment assumptions;
- the granted budget or authority;
- the durable result that exceeded it;
- a minimal reproduction;
- whether savepoints, procedures, triggers, partitions, concurrency, pooling,
  or elevated privileges are involved;
- any proposed mitigation, if known.

## What Constitutes A Security Bug

For behavior documented as supported, a high-severity security failure is:

> A supported operation causes a durable mutation exceeding granted mutation
> authority without the transaction being denied.

Examples include:

- one supported `UPDATE` commits more row-update events than its budget;
- several statements in one transaction reset or evade the cumulative count;
- a savepoint or caught exception makes an over-budget denied transaction
  committable;
- a documented indirect execution path, such as a CTE or procedure, bypasses
  accounting;
- concurrent sessions corrupt accounting and allow excess durable writes;
- pool reuse leaks or resets authority in a way that fails open;
- the documented protected-writer role can alter policy state, disable
  enforcement, or replace trusted code;
- an error path permits part of a denied transaction to become durable;
- a denied transaction commits an unprotected mutation or any other
  transactional effect instead of aborting atomically.

Severity depends on the documented support envelope, required privileges,
reachable mutation, and impact. An unsupported operation is not automatically
an implementation vulnerability, but an undocumented bypass, a fail-open
deployment default, or a false support claim may be one.

Denial of service, fail-closed errors, information disclosure, privilege
escalation, SQL injection in trusted functions, and policy-state corruption are
also security relevant even when they do not directly exceed a declared
mutation budget.

## Support Boundaries

Security reports and public claims MUST be evaluated against:

1. [docs/spec.md](docs/spec.md), which defines intended semantics.
2. [docs/test-plan.md](docs/test-plan.md), which defines canonical current test
   IDs and required behavior.
3. [docs/threat-model.md](docs/threat-model.md), which defines trust
   assumptions.
4. [docs/decisions.md](docs/decisions.md), which records accepted boundaries.
5. [docs/support-matrix.md](docs/support-matrix.md), which records the current
   research envelope and Public Research Preview preparation status.
6. Regression tests shipped by the affected release.

Root [SPEC.md](SPEC.md), historical experiment scripts, and the experiment
evidence in [docs/limitations.md](docs/limitations.md) use **legacy experiment
IDs**. Same-number IDs in the current test plan may mean different tests.

Specifications alone are not proof. No operation may be advertised as
protected until its positive, negative, atomicity, and adversarial tests pass.

The trusted PostgreSQL administrator, superusers, database-host operators, and
protected-table owners can normally bypass in-database enforcement. CommitCap
does not claim to defend against them. Granting those privileges to a protected
writer invalidates the deployment's security assumptions.

## Disclosure And Claims

Maintainers SHOULD reproduce reports against the smallest supported envelope,
identify whether the failure is an invariant violation or a documentation
error, add a regression test, and narrow claims immediately if a complete fix
is not available.

Public security claims MUST match released, tested behavior. Unknown behavior
must remain `UNKNOWN`; unsupported behavior must remain `UNSUPPORTED`.
