# WriteLeash — PostgreSQL/Advanced Substrate Decision History

Decision-history snapshot: 2026-09-23

> These decisions record the earlier PostgreSQL research/product-development
> thesis and remain technical context for that substrate. They do not set the
> intended public Free product: #106 supersedes #67 as authority for safe
> WooCommerce bulk price changes. That product is planned, not implemented;
> #107–#112 are pending and public release is deferred. The advanced WordPress
> Guard/Doctor/Redirection substrate remains distinct; these historical
> decisions do not change its semantics.

This file records decisions so the project does not repeatedly re-litigate settled questions.

Use ADR-style entries.

Status values:

```text
PROPOSED
ACCEPTED
SUPERSEDED
REJECTED
```

---

# ADR-001 — PostgreSQL First

**Status:** ACCEPTED

## Decision

The original technical research started with PostgreSQL only.

## Why

- strong transactional semantics
- rich trigger/function model
- excellent local testing
- large relevant ecosystem
- keeps initial scope narrow

## Consequence

No MySQL or multi-database abstraction during Phase 0.

---

# ADR-002 — Product Primitive Is Mutation Authority, Not SQL Safety

**Status:** ACCEPTED

## Decision

The PostgreSQL research mechanism is centered on:

> **finite, measurable, consumable mutation authority**

not generic SQL safety.

## Why

“safe SQL” is too broad and crowded.

The strategic wedge is:

```text
actual effects
+
finite authority
+
consumption
```

---

# ADR-003 — Actual Effects Over SQL Prediction

**Status:** ACCEPTED

## Decision

The PostgreSQL research mechanism should enforce supported mutation budgets from measured relational effects rather than relying only on query-text heuristics or estimated blast radius.

## Why

Intent and actual effect can differ.

The PostgreSQL research mechanism should complement, not duplicate, pre-execution SQL review.

---

# ADR-004 — Transaction-Wide Accounting Is Mandatory

**Status:** ACCEPTED

## Decision

Per-statement limits alone are insufficient.

Budgets must accumulate across all supported mutation statements in one transaction.

## Why

Otherwise:

```text
100-row write
```

can be decomposed into:

```text
100 × 1-row statements
```

---

# ADR-005 — Cross-Transaction Authority Is Strategic, Not V0-Blocking

**Status:** ACCEPTED

## Decision

Capability-wide budgets across transactions are the strategic endpoint, but Phase 0 first proves transaction-wide semantics.

## Why

Cross-transaction authority introduces:

- concurrency
- retries
- idempotency
- lifecycle
- capability state

These should not obscure the initial proof.

---

# ADR-006 — SQL/PLpgSQL Before Native Extension

**Status:** ACCEPTED

## Decision

Use ordinary PostgreSQL features where possible for the first prototype.

Do not start with a C extension.

## Why

- faster falsification
- lower implementation burden
- easier local experimentation
- better compatibility hypothesis for managed Postgres

Native extension may be revisited only if evidence proves it necessary.

## Historical outcome clarification

The repository followed this sequence. The first SQL/PLpgSQL experiment showed
that ordinary transactional denial/accounting state can be rolled back by a
savepoint or PL/pgSQL exception subtransaction, allowing top-level commit. A
subsequent research-only native transaction-state experiment evaluated
backend-local state and transaction callbacks because of that falsification.
The native experiment is mechanism evidence, not a production-extension
selection or support claim.

---

# ADR-007 — No Generic Database Proxy in V0

**Status:** ACCEPTED

## Decision

Do not build a network proxy for Phase 0.

## Why

A proxy adds:

- protocol handling
- latency
- deployment complexity
- credential custody
- additional attack surface

The primitive should first be proven database-side.

---

# ADR-008 — Fail Closed for Unsupported Protected Paths Where Practical

**Status:** ACCEPTED

## Decision

When the PostgreSQL research mechanism cannot safely understand a mutation path touching protected state, prefer denial over silent allowance.

## Why

Security claims must not exceed enforcement coverage.

---

# ADR-009 — RLS and Stored Procedures Are Complementary, but Narrow Operations Win Known Workflows

**Status:** ACCEPTED

## Decision

Do not position the PostgreSQL research mechanism as replacing PostgreSQL RLS or stored procedures.

If a workload can be expressed cleanly as a stable, narrow API or stored procedure, prefer that simpler architecture.

The original technical thesis targeted workloads where legitimate mutation shape is broad, evolving, or not knowable enough in advance for a fixed operation catalog, or where a database-level quantitative backstop is independently valuable.

## Why

They answer different questions.

```text
RLS:
which rows?

stored procedure / narrow API:
which predefined operation?

PostgreSQL research mechanism:
how much declared relational effect may a flexible task consume?
```

The existence of a quantitative gap does not imply every workload needs a generic mutation-budget layer.

---

# ADR-010 — AI Is a Use Case, Not the Product Definition or Market Dependency

**Status:** ACCEPTED

## Decision

Product scope is broader than AI agents:

> **flexible production automation operated as semi-trusted database writers**

Initial validation should prioritize existing production repair, remediation, DBRE, operator, and workflow workloads before depending on future direct-SQL agent adoption.

AI agents remain a use case only when they genuinely require flexible mutation authority.

## Why

Current product viability should not depend on the assumption that arbitrary AI SQL becomes common.

The underlying problem predates AI: trusted or semi-trusted automation can hold authority much broader than one task's intended consequence.

---

# ADR-011 — OSS Core Must Be Real

**Status:** ACCEPTED

## Decision

Community edition must demonstrate the core primitive without artificial crippling.

## Why

The PostgreSQL research mechanism needs:

- trust
- inspection
- security review
- local adoption
- community testing

Commercial value should come from operational complexity and organization-scale capability management.

---

# ADR-012 — Security Claims Must Be Narrow

**Status:** ACCEPTED

## Decision

Use the claim:

> **The WriteLeash PostgreSQL research mechanism governs supported transactional relational mutations.**

Avoid claims such as:

> “WriteLeash controls all PostgreSQL side effects.”

## Why

Some effects are outside ordinary relational rollback semantics or may be opaque.

---

# ADR-013 — No Dashboard Before User Demand

**Status:** ACCEPTED

## Decision

Do not build a web dashboard during technical proof or initial OSS V0.

## Why

A dashboard does not prove the primitive.

---

# ADR-014 — No Commercial Platform Before Production Adoption

**Status:** ACCEPTED

## Decision

Do not build central policy, SSO, billing, HA control plane, or enterprise workflows before real production demand.

## Why

Premature productization creates false progress.

---

# ADR-015 — Technical Credibility Before Marketing Hype

**Status:** ACCEPTED

## Decision

Marketing should make the primitive easy to understand, but no public claim may exceed tested behavior.

## Why

Security reputation is part of the moat.

---

# ADR-016 — Current Phase Is Security Proof

**Status:** ACCEPTED

## Decision

Current project phase:

```text
Phase 0 — Security Proof
```

Highest-priority implementation work:

```text
transaction-wide mutation accounting
+
adversarial tests
```

ADR-019 market falsification and ADR-020 managed-PostgreSQL feasibility proceed
in parallel as evidence tracks. They must not expand implementation scope
prematurely.

---

# ADR-017 — Relational Effects Are Not Automatically Business Truth

**Status:** ACCEPTED

## Decision

The PostgreSQL research mechanism enforces explicitly declared **relational effects**.

It does not claim that a database delta is automatically identical to the complete external business consequence.

Example:

```text
refunds.amount positive_delta = 100
```

means that the declared relational metric increased by 100 according to documented WriteLeash PostgreSQL research semantics.

It does not by itself prove that:

- a payment processor transferred $100;
- a ledger settled $100;
- a customer received $100;
- no external side effect occurred elsewhere.

The application or policy issuer owns the mapping between business intent and the relational metric chosen for enforcement.

## Why

The closer a policy moves toward economic or domain meaning, the more application-specific its semantics become.

The PostgreSQL research mechanism should be precise about what PostgreSQL can observe and enforce rather than overclaiming business truth.

---

# ADR-018 — Consumable Capability Authority Is Monotonic by Default

**Status:** ACCEPTED

## Decision

For capability-wide consumable budgets, committed inverse or compensating mutations do not automatically restore authority already consumed.

Example:

```text
initial positive-delta authority = 100
TX1 commits +80
remaining = 20
TX2 commits -80
remaining remains = 20
```

A future explicit replenishment model may be designed separately, but replenishment must never emerge accidentally from ordinary inverse mutations.

Rolled-back effects remain different: effects that never become durable do not finalize capability consumption.

## Why

Allowing ordinary compensating writes to regenerate authority can create an authority-laundering loop where a buggy or adversarial writer oscillates state to manufacture fresh spending capacity.

---

# ADR-019 — Early Market Validation Targets Broad-but-Bounded Workflows

**Status:** ACCEPTED

## Decision

Run market validation in parallel with Phase 0 technical proof.

Prioritize interviews with engineers operating:

- production repairs and data fixes;
- incident remediation;
- DBRE/operator automation;
- backfills;
- internal workflows with flexible write surfaces.

For each workflow, first challenge the mutation-budget research model with the simpler alternatives:

```text
narrow API
stored procedure
trigger
approval step
```

Only treat the workflow as evidence for the historical PostgreSQL thesis if broad-but-bounded authority remains materially useful after that challenge.

## Why

The main product risk is not that a large vendor copies the primitive first.

The main product risk is that most legitimate workflows are already better served by a simpler, narrower operation boundary.

---

# ADR-020 — Managed PostgreSQL Feasibility Is an Early Evidence Gate

**Status:** ACCEPTED

## Decision

Do not postpone managed-PostgreSQL feasibility until late productization.

During Phase 0 / Phase 1, determine what the chosen enforcement architecture can and cannot support on at least one realistic managed PostgreSQL environment.

This is a feasibility investigation, not a commitment to support every cloud provider.

## Why

A technically correct primitive that requires privileges or extension mechanisms unavailable to likely users may be commercially boxed in.

Deployment constraints must therefore be discovered before deep product expansion.

---
