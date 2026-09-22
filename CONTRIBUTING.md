# Contributing To CommitCap

CommitCap is security-sensitive infrastructure. Small, explicit, adversarially
tested changes are preferred over broad abstractions.

## Before Making A Change

Contributors MUST read:

1. [SPEC.md](SPEC.md), the normative behavior contract.
2. [docs/threat-model.md](docs/threat-model.md), the trust and attacker model.
3. [docs/limitations.md](docs/limitations.md), the current support boundary.
4. [SECURITY.md](SECURITY.md), for private vulnerability reporting.

If a proposed change conflicts with `SPEC.md`, update and review the
specification explicitly before treating the new behavior as valid. Do not let
implementation accidents redefine product semantics.

## Required Workflow

```text
SPEC
-> adversarial test
-> implementation
-> regression test
-> diff review
-> bypass attempt
-> merge
```

For every meaningful security behavior:

1. State the invariant or requirement being implemented.
2. Add a positive test for allowed behavior.
3. Add a negative or adversarial test for denied behavior.
4. Implement the smallest change that can satisfy or falsify the requirement.
5. Inspect transaction failure, rollback, and cleanup semantics.
6. Attempt a bypass using alternate PostgreSQL execution paths.
7. Document every unsupported or unknown case discovered.

Green CI alone is not sufficient evidence for a security-sensitive change.
Reviewers need to understand why the tested boundary is complete enough for the
claim being made.

## Change Discipline

- Keep pull requests small and single-purpose.
- Avoid unrelated refactors, dependency additions, and formatting churn.
- Do not widen the support envelope silently.
- Do not add speculative multi-database, SaaS, orchestration, or enterprise
  abstractions.
- Do not add compatibility behavior without a demonstrated need.
- Prefer explicit, boring, reviewable PostgreSQL behavior over cleverness.
- Do not grant a protected writer ownership or privileges that bypass
  enforcement.
- Do not describe an operation as protected without a named regression test.
- Use `UNKNOWN` when behavior has not been characterized and `UNSUPPORTED` when
  it is outside the accepted envelope.

Before accepting a feature, ask:

> Does this help prove the mutation-authority primitive, make the OSS security
> demo easier to adopt, or solve a repeated request from real users?

If not, defer it.

## Security-Sensitive Pull Requests

A pull request that changes enforcement, policy state, trusted SQL, roles,
transactions, or supported operations SHOULD include:

- the relevant `CC-*` test IDs;
- the exact support envelope before and after the change;
- privilege assumptions;
- positive and adversarial test output;
- rollback and savepoint behavior;
- concurrency considerations where applicable;
- observed performance, without extrapolated benchmarks;
- new limitations or removed limitations;
- a brief bypass attempt and its result.

Any `SECURITY DEFINER` function requires focused review of owner identity,
fixed safe `search_path`, `EXECUTE` grants, dynamic SQL, object shadowing, and
privilege escalation.

## Scope During Phase 0

Phase 0 work is limited to the smallest PostgreSQL experiment needed to prove
or falsify transaction-wide update accounting. Do not add dashboards, frontend
code, billing, AI features, MCP controls, queues, microservices, Kubernetes,
multi-database support, or a generic proxy.

Follow the [Implementation Agent Contract](SPEC.md#12-implementation-agent-contract):

> Do not "finish the product." Implement only the requested invariant or test.
