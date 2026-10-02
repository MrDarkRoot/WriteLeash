# Contributing To WriteLeash

WriteLeash contains security-sensitive technical substrates. Small, explicit,
adversarially tested changes are preferred over broad abstractions. The
intended public Free 1.0 product is governed by #106: safe WooCommerce bulk
price changes. It is planned, not implemented; #107–#112 are ordered build and
proof gates, and public release is deferred. Existing PostgreSQL/native
research and advanced WordPress Guard/Doctor/Redirection capabilities remain
separate technical assets.

## Before Making A Change

Run `bash .github/ci/pr-fast.sh` locally before pushing. Listing/readme/assets
changes run cheap PR_FAST and CI_COVERAGE checks only. Runtime/test changes must
have explicit owners in `.github/ci/path-ownership.json`; new production PHP
without an owner fails the gate. Expensive fork integrations require maintainer
review and promotion of the exact audited SHA to a same-repository PR branch.
Full release evidence is an intentional exact-SHA dispatch. See
[CI policy](.github/ci/CI-POLICY.md) for requirements and contributor approval.

Contributors MUST read:

1. [docs/spec.md](docs/spec.md), the PostgreSQL research semantics.
2. [docs/test-plan.md](docs/test-plan.md), the canonical PostgreSQL research test IDs; WordPress suite coverage is under `wordpress/tests/`.
3. [docs/threat-model.md](docs/threat-model.md), the PostgreSQL research trust model, and `wordpress/writeleash/THREAT-MODEL.md` for the separate advanced WordPress substrate.
4. [docs/decisions.md](docs/decisions.md), the historical technical decision record and boundaries.
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

## Scope and current product sequence

The PostgreSQL/native experiment plan is historical technical context, not the
current public-product roadmap. Use [docs/roadmap.md](docs/roadmap.md) and
[#106](https://github.com/MrDarkRoot/WriteLeash/issues/106) for current product
authority. Implement WooCommerce behavior only in its ordered #107–#112 gates;
do not add product functionality during unrelated rebrand or Guard work.
Changes to the existing Guard/Doctor/Redirection security contract require
their own explicit scope and regression review. Do not change those semantics
to make a separate product path easier.
