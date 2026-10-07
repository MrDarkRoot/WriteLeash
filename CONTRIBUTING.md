# Contributing To WriteLeash

WriteLeash is a research and product project. Its first commercial product is a
WooCommerce bulk-pricing plugin: Preview, newer-edit protection, background work
and Resume, History and eligible Undo. See the [root README](README.md) and
[plugin listing](wordpress/writeleash/readme.txt) for the product scope.
Small, explicit changes are preferred over broad abstractions. PostgreSQL/native
research and advanced WordPress Guard/Doctor/Redirection are separate research
tracks in this repository.

## Before Making A Change

Run `bash .github/ci/pr-fast.sh` locally before pushing. Listing/readme/assets
changes run cheap PR_FAST and CI_COVERAGE checks only. Runtime/test changes must
have explicit owners in `.github/ci/path-ownership.json`; new production PHP
without an owner fails the gate. Expensive fork integrations require maintainer
review and promotion of the exact audited SHA to a same-repository PR branch.
Full release evidence is an intentional exact-SHA dispatch. See
[CI policy](.github/ci/CI-POLICY.md) for requirements and contributor approval.

For PostgreSQL research changes, contributors MUST read:

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
6. Attempt a bypass using alternate execution paths for the affected component.
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

> Does this improve the supported WooCommerce workflow or solve a repeated
> request from its users? Research changes require their own explicit scope.

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

The PostgreSQL/native experiment plan is the **WRITELEASH RESEARCH** track; the
#107–#112 WooCommerce build sequence is completed product planning history.
Current WooCommerce scope is summarized in the [root README](README.md) and
[plugin listing](wordpress/writeleash/readme.txt).
Use the current issue and CI policy for the proposed change; do not add product
functionality during unrelated metadata or documentation work. Changes to the
separate Guard/Doctor/Redirection security contract require their own explicit
scope and regression review.
