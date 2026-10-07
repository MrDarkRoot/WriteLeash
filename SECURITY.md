# Security Policy

WriteLeash's current product is a WooCommerce bulk-pricing plugin. Its supported
workflow is described in the [plugin listing](wordpress/writeleash/readme.txt).
Reports should identify the affected product operation and version or commit.

The repository also preserves PostgreSQL/native research and an advanced
WordPress Guard/Doctor/Redirection substrate. Those are separate research
assets, not generally released database security controls. Their historical
boundaries below do not describe the WooCommerce product's installation path.

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

## WooCommerce product reports

Report unauthorized price changes or access to jobs, overwriting a newer relevant
edit after Preview, or Undo restoring a value when it should preserve a later
edit. Include the actor role, selected price field, steps and observed result.
Ordinary conflicts and ineligible Undo are expected protections, not proof of a
security failure.

## Historical PostgreSQL research criteria

**HISTORICAL / SUPERSEDED — product identity.** The following criteria and
research documents apply to the separate PostgreSQL fixture.

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

## PostgreSQL research support boundaries

Security reports and public claims MUST be evaluated against:

1. [docs/spec.md](docs/spec.md), which defines intended semantics.
2. [docs/test-plan.md](docs/test-plan.md), which defines canonical current test
   IDs and required behavior.
3. [docs/threat-model.md](docs/threat-model.md), which defines trust
   assumptions for the PostgreSQL research mechanism; the separate advanced
   WordPress substrate is described in
   [wordpress/writeleash/THREAT-MODEL.md](wordpress/writeleash/THREAT-MODEL.md).
4. [docs/decisions.md](docs/decisions.md), which records accepted boundaries.
5. [docs/support-matrix.md](docs/support-matrix.md), which records the current
   PostgreSQL/native research envelope and release status.
6. Regression tests shipped by the affected release.

Root [SPEC.md](SPEC.md), historical experiment scripts, and the experiment
evidence in [docs/limitations.md](docs/limitations.md) use **legacy experiment
IDs**. Same-number IDs in the current test plan may mean different tests.

Specifications alone are not proof. No operation may be advertised as
protected until its positive, negative, atomicity, and adversarial tests pass.

The trusted PostgreSQL administrator, superusers, database-host operators, and
protected-table owners can normally bypass in-database enforcement. The
PostgreSQL research mechanism does not claim to defend against them. Granting
those privileges to a protected writer invalidates that deployment's security
assumptions.

## Disclosure And Claims

Maintainers SHOULD reproduce reports against the smallest supported envelope,
identify whether the failure is an invariant violation or a documentation
error, add a regression test, and narrow claims immediately if a complete fix
is not available.

Public security claims MUST match released, tested behavior. Unknown behavior
must remain `UNKNOWN`; unsupported behavior must remain `UNSUPPORTED`.
