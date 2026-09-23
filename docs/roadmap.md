# CommitCap — Roadmap

Last updated: 2026-09-23

## Rule

CommitCap advances through evidence gates.

Do not skip phases.

Do not build future-platform features because they sound exciting.

---

# Phase 0 — Security Proof + Parallel Falsification

## Goal

Prove or disprove the core mutation-authority invariant in PostgreSQL while simultaneously testing the two largest product assumptions without expanding build scope:

```text
Do real teams have broad-but-bounded write workflows?
Can the chosen architecture be deployed realistically on managed PostgreSQL?
```

## Build

- Docker Compose PostgreSQL environment
- protected demo tables
- transaction-wide row accounting
- protected state-transition rule
- basic numeric delta budget
- minimal policy storage
- trusted enforcement functions
- adversarial SQL test suite
- basic benchmark harness

## Parallel founder validation track

While the maintainer works on technical proof, run a small number of structured conversations with engineers responsible for:

- production data fixes / repairs
- DBRE / database operations
- incident remediation
- backfills
- operator automation
- flexible workflow systems

For each real workflow ask:

```text
What could the credential modify?
What was this task actually supposed to modify?
How was blast radius bounded?
Why was a narrow API or stored procedure not enough?
Would database-level denial be operationally acceptable?
```

Do not ask only “Would you use CommitCap?”

Also perform an early managed-PostgreSQL feasibility spike against at least one realistic managed environment. Investigate extension installation, available privileges, superuser restrictions, preload requirements, deployment model, and upgrade/operational constraints. The goal is to determine whether the chosen enforcement architecture can realistically run somewhere likely users deploy, not to promise broad cloud support.

Market findings may falsify or narrow the product thesis, but should not create new Phase 0 features unless the same need repeats.

## Required demo

```text
safe mutation
→ COMMIT

unsafe broad mutation
→ ABORT

many small statements exceeding same transaction budget
→ ABORT

forbidden state transition
→ ABORT

numeric delta above budget
→ ABORT
```

## Gate to pass

- core invariant survives obvious bypass attempts
- rollback/savepoint behavior is correct
- unsupported operations are documented
- writer cannot disable enforcement
- benchmark data exists
- managed-PostgreSQL deployment constraints are explicitly known for at least one realistic environment
- market discovery has produced concrete evidence about whether broad-but-bounded workflows exist, including evidence that may require narrowing the thesis

## If fail

- fix architecture
- narrow scope
- or kill product

---

# Phase 1 — OSS V0

## Goal

Make the primitive understandable and runnable by another developer in minutes.

## Build

- clean CLI if actually useful
- stable-enough policy format
- generated protection setup
- `docker compose up` demo
- installation docs
- README killer demo
- SECURITY.md
- CONTRIBUTING.md
- regression test suite
- benchmark report

## Experience target

A new developer should be able to see:

```text
safe mutation → COMMIT
unsafe mutation → ABORT
```

within roughly five minutes.

## Gate to pass

- independent users can install it
- users understand what it does
- no major security claim mismatch
- real GitHub issues contain concrete technical questions

---

# Phase 2 — Real Users / Design Partners

## Goal

Convert Phase 0 market signals into real attempted integrations and determine whether broad-but-bounded writes are a repeatable workflow rather than an interesting abstraction.

## Do

Continue targeted conversations until roughly 15–20 relevant builders or engineers have been studied across real workflows.

Prioritize production repair/data-remediation jobs, incident-response/recovery automation, DBRE/operator scripts, workflow/internal tooling, and admin automation, in that order, before AI agents or support bots.

Ask people to show a recent task, then investigate:

- what credential the job used
- what the credential could theoretically modify
- what the task was actually supposed to modify
- how blast radius was bounded
- RLS usage
- stored procedures and narrow APIs
- triggers / bespoke budget logic
- human approval
- direct credentials
- accidental broad writes / near misses
- why the task could not be reduced cleanly to a narrow operation
- whether database-level denial is acceptable
- managed-database deployment objections

For every candidate use case, run a “narrow-operation challenge”: try to solve it with one API, one stored procedure, one trigger, or one approval step. CommitCap only wins if broad-but-bounded authority remains materially better or a valuable independent backstop.

## Build only repeated needs

Possible examples:

- RDS compatibility
- per-role budgets
- tenant constraints
- richer delta policies
- better audit output
- packaging improvements

## Gate to pass

Strong signals include:

- “We deliberately need this writer to stay flexible, but it must not be able to change more than X.”
- “We already maintain something similar internally.”
- “Can we try this in staging?”
- “Can budgets survive multiple transactions/connections?”
- “Can it enforce tenant, row, or numeric-effect budgets under retries/cascades?”
- “Does it work on our managed PostgreSQL?”

If users consistently say:

```text
we would express this as a narrow API or stored procedure
```

and can do so cleanly without residual blast-radius pain, narrow or stop the commercial thesis.

---

# Phase 3 — Mutation Capabilities

## Goal

Move from transaction safety to consumable authority infrastructure.

## Build

- task-bound capability IDs
- TTL
- cross-transaction budgets
- atomic authority consumption
- concurrency control
- retry semantics
- capability expiration
- capability audit trail

## Core demonstration

```text
capability = $100 refund authority

TX1 commits $30
remaining = $70

TX2 commits $40
remaining = $30

TX3 requests $50
DENY
```

Concurrent sessions must not overspend the capability.

## Gate to pass

- atomicity proven under concurrency
- retries understood
- practical user demand exists
- capability model is simpler than custom application logic for target users

---

# Phase 4 — Productization

## Goal

Make production operation boring.

Possible work:

- upgrade tooling
- policy distribution
- richer audit receipts
- deployment packaging
- support for common managed PostgreSQL environments
- compatibility hardening
- performance tuning
- observability
- failure diagnostics

Do not build enterprise platform yet unless demanded.

---

# Phase 5 — Commercial

## Goal

Monetize production complexity.

Potential paid areas:

- central capability issuance
- multi-database policy
- signed/expiring capability workflows
- Vault/KMS integration
- long-term audit evidence
- HA coordination
- enterprise deployment support
- support SLA
- OEM / embedded licensing

## Gate

Only enter after production adoption and explicit willingness to pay.

---

# Anti-roadmap

Do NOT build early:

- AI model
- AI SQL generation
- dashboard
- SaaS control plane
- generic MCP firewall
- MySQL
- 20 database engines
- generic IAM
- generic approval platform
- broad agent governance
- enterprise SSO
- billing system
- giant integration marketplace

---

# Current status

```text
CURRENT PHASE:
Phase 0 — Security Proof
```

Current priority:

> **Prove transaction-wide mutation accounting, test managed-PostgreSQL feasibility early, and look for real broad-but-bounded production workflows without expanding the product surface.**
