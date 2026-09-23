# Contributing To CommitCap

CommitCap is security-sensitive infrastructure. Small, explicit, adversarially
tested changes are preferred over broad abstractions.

## Before Making A Change

Contributors MUST read:

1. [docs/spec.md](docs/spec.md), the intended behavior contract.
2. [docs/test-plan.md](docs/test-plan.md), the canonical current test namespace.
3. [docs/threat-model.md](docs/threat-model.md), the trust and attacker model.
4. [docs/decisions.md](docs/decisions.md), the accepted product and architecture boundaries.
5. [SECURITY.md](SECURITY.md), for private vulnerability reporting.

If a proposed change conflicts with `docs/spec.md`, update and review the
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
- Use canonical IDs from `docs/test-plan.md` for current work. Preserve old IDs
  only when citing historical evidence, and label them `legacy experiment ID`.
- Use `UNKNOWN` when behavior has not been characterized and `UNSUPPORTED` when
  it is outside the accepted envelope.

Before accepting a feature, ask:

> Does this help prove the mutation-authority primitive, make the OSS security
> demo easier to adopt, or solve a repeated request from real users?

If not, defer it.

## Security-Sensitive Pull Requests

A pull request that changes enforcement, policy state, trusted SQL, roles,
transactions, or supported operations SHOULD include:

- the relevant canonical `CC-*` test IDs from `docs/test-plan.md`;
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

Phase 0 implementation work starts with the smallest PostgreSQL experiments
needed to prove or falsify mutation-authority semantics. The Phase 0 proof
surface covers row-count, state-transition, and numeric-delta effect classes;
see [docs/spec.md](docs/spec.md) and [docs/roadmap.md](docs/roadmap.md).
Parallel market falsification and an early managed-PostgreSQL feasibility
investigation are also Phase 0 evidence tracks, but must not expand
implementation scope prematurely. Do not add dashboards, frontend code,
billing, AI features, MCP controls, queues, microservices, Kubernetes,
multi-database support, or a generic proxy.

Follow the scope discipline in [docs/role.md](docs/role.md#9-scope-discipline):

> Do not "finish the product." Implement only the requested invariant or test.
