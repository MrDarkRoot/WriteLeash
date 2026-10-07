# WriteLeash — Historical PostgreSQL Product Thesis

Historical thesis snapshot: 2026-09-23

> **HISTORICAL / SUPERSEDED — product identity.** This is the preserved
> PostgreSQL-era thesis. The current WooCommerce bulk-pricing product is
> implemented; see the [root README](../README.md) and
> [plugin listing](../wordpress/writeleash/readme.txt). PostgreSQL research and
> advanced WordPress Guard/Doctor/Redirection remain separate technical assets.
> Historical `CommitCap` references below preserve the original thesis wording.

**Status:** Historical product thesis and intended PostgreSQL semantics.
Research experiments exist; this document makes no released or supported
PostgreSQL implementation claim.

## 1. One-line definition

**CommitCap turns database WRITE permission into finite, measurable, consumable mutation authority.**

Primary tagline:

> **Give automation write access. Cap the blast radius.**

Technical tagline:

> **Mutation Budgets for PostgreSQL.**

Strategic thesis:

> **Writes consume authority.**

CommitCap is designed for **flexible production automation** operating as semi-trusted database writers.

Validation workloads, in priority order, are:

1. production repair / data-remediation jobs
2. incident-response / recovery automation
3. DBRE / operator scripts
4. workflow engines / internal tooling with evolving write surfaces
5. admin automation
6. AI agents only when flexible writes are genuinely required
7. support bots only where narrow operations are insufficient

AI is a use case, not the product definition or market dependency.

Product boundary:

> **If a workload can be expressed cleanly as a narrow API or stored procedure, use that simpler architecture. CommitCap targets the middle ground between hard-coded narrow operations and unbounded database write authority.**

---

# 2. Problem

Database permissions are usually coarse:

```text
SELECT
INSERT
UPDATE
DELETE
```

If an actor is allowed to `UPDATE subscriptions`, the database often treats these two actions as equally authorized:

```sql
UPDATE subscriptions
SET status = 'refunded'
WHERE id = 817;
```

and:

```sql
UPDATE subscriptions
SET status = 'refunded';
```

The first may change one row.

The second may change 182,417 rows.

Traditional authorization answers:

> **What operation may this identity perform, and on which objects or rows?**

CommitCap adds another dimension:

> **How much durable mutation authority may this actor or task consume?**

---

# 3. Core insight

Infrastructure already treats many resources as finite:

- CPU quota
- memory limits
- API rate limits
- cloud budgets
- spend caps

But database write authority is often binary:

```text
can UPDATE
or
cannot UPDATE
```

CommitCap proposes that durable state mutation should also be bounded.

Mental model:

```text
WRITE permission
+
finite mutation budget
=
bounded durable authority
```

---

# 4. Product primitive

CommitCap measures supported relational effects and enforces a declared mutation budget before unsafe state becomes durable.

This intended enforcement boundary is relational, not a claim of complete
business consequence. For example:

```text
refunds.amount positive_delta = 100
```

means only that the declared PostgreSQL metric changed by 100 according to
CommitCap semantics. It does not prove that a payment processor transferred
$100, a customer received $100, a ledger settled $100, or an external workflow
succeeded. The application or policy issuer owns that mapping.

Example policy:

```yaml
actor: repair_worker

budget:
  subscriptions:
    max_rows_updated_per_transaction: 5

  customers:
    max_rows_deleted_per_transaction: 0

  refunds.amount:
    max_total_increase: 100

  users.role:
    deny_transitions:
      - "* -> admin"
```

Unsafe mutation:

```sql
UPDATE subscriptions
SET status = 'refunded';
```

Observed effect:

```text
subscriptions.rows_updated = 182417
budget = 5
```

Result:

```text
TRANSACTION ABORTED
```

Safe mutation:

```sql
UPDATE subscriptions
SET status = 'refunded'
WHERE id = 817;
```

Observed effect:

```text
subscriptions.rows_updated = 1
budget = 5
```

Result:

```text
COMMIT
```

---

# 5. Product evolution

## Level 1 — Mutation volume

Examples:

```yaml
subscriptions:
  max_rows_updated: 5

customers:
  max_rows_deleted: 0
```

Useful for:

- missing `WHERE`
- broad accidental updates
- unexpected delete scope
- some cascade amplification

This level is useful but not sufficient differentiation by itself.

---

## Level 2 — Forbidden state transitions

Example:

```yaml
users.role:
  deny_transitions:
    - "* -> admin"
    - "* -> owner"
```

A one-row mutation may still exceed authority.

---

## Level 3 — Quantitative effect budgets

Example:

```yaml
refunds.amount:
  max_total_increase: 100
```

A transaction proposing:

```text
+$30
+$20
+$40
```

may pass.

Adding:

```text
+$25
```

must exceed budget and abort.

---

## Level 4 — Mutation capabilities

This is the strategic wedge.

Example:

```yaml
capability: repair-task-817
actor: repair_worker
expires_in: 10m

authority:
  subscriptions:
    rows_updated: 3

  refunds.amount:
    total_increase: 100

  users.role:
    admin_promotions: 0

  tenants:
    max_touched: 1
```

Authority is consumed across transactions:

```text
TX1 refund $30
remaining = $70

TX2 refund $40
remaining = $30

TX3 refund $50
DENY
```

Splitting work into multiple transactions must not regenerate authority.

Capability-wide consumable authority is monotonic by default:

```text
initial authority = 100
TX1: +80 COMMIT
remaining = 20

TX2: -80 COMMIT
remaining = 20
```

Ordinary compensating mutations do not replenish consumed authority. Any
future replenishment operation must be explicit and separately authorized.
Otherwise state oscillation could be used for **authority laundering**.

---

# 6. Core differentiation

CommitCap is NOT a generic SQL firewall.

It does not primarily ask:

> Is this SQL text allowed?

It asks:

> What supported durable relational effect did this task actually produce, and does that effect fit within its remaining authority?

Positioning:

```text
Traditional permissions:
WHAT may this identity do?

RLS:
WHERE may this identity mutate?

CommitCap:
HOW MUCH mutation authority may this task consume?
```

Strategic category:

> **Consumable Mutation Authority**

---

# 7. Why now

Production state is already modified by semi-trusted automation that often needs more flexibility than a single hard-coded operation:

- production repair and remediation jobs
- DBRE and operator scripts
- backfills and data-fix tooling
- workflow engines
- admin automation
- internal operations tooling
- support automation
- AI agents where flexible writes are intentionally permitted

CommitCap does not require a forecast that arbitrary AI SQL will become common. The underlying blast-radius problem exists anywhere automation has broader mutation capability than one task intends to consume.

Teams face a recurring trade-off:

```text
read-only automation
→ safer
→ limited usefulness
```

versus:

```text
write-capable automation
→ more useful
→ larger blast radius
```

CommitCap aims to create a third option:

```text
write-capable automation
+
bounded durable authority
```

---

# 8. Initial ICP

CommitCap should not target every PostgreSQL user.

Initial validation priority:

1. production repair / data-remediation jobs
2. incident-response / recovery automation
3. DBRE / operator scripts
4. workflow engines / internal tooling with evolving write surfaces
5. admin automation
6. AI agents only when flexible writes are genuinely required
7. support bots only where narrow operations are insufficient

The defining workflow is not “uses AI.” It is:

```text
needs flexible writes
+
narrow predefined operations are materially insufficient
+
unbounded database authority is unacceptable
```

Technical champions:

- Database Reliability Engineer / Database Engineer
- Platform Engineer
- SRE
- Security / AppSec Engineer
- Staff Backend Engineer
- AI Infrastructure Engineer when the workload genuinely involves flexible agent writes

Economic buyers may include:

- Head of Platform / Infrastructure
- VP Engineering / Head of Engineering
- Head of Security
- Engineering Manager
- CTO at smaller technical organizations

---

# 9. What CommitCap is not

CommitCap is not:

- a SQL generation assistant
- an AI model
- a database governance suite
- a human approval workflow
- a generic MCP firewall
- a replacement for IAM
- a replacement for RLS
- a replacement for stored procedures
- a replacement for application APIs
- a full database proxy
- a dashboard-first product
- a multi-database platform in V0

---

# 10. Relationship to native PostgreSQL controls

## Traditional permissions

Answer:

> May this role execute UPDATE?

CommitCap:

> How much mutation may this task make durable?

---

## RLS

Answers:

> Which rows may this identity access or mutate?

CommitCap:

> How much authority may be consumed across the rows it is already allowed to touch?

---

## Stored procedures and narrow application APIs

Stored procedures and narrow application APIs are the preferred solution when the valid operation is known, stable, and easy to encode explicitly.

Example:

```text
refund_customer(customer_id, amount)
cancel_subscription(subscription_id)
```

CommitCap should not compete with this architecture.

CommitCap adds material value only when:

- the legitimate mutation surface is broad or evolves faster than a fixed operation catalog;
- building one-off procedures for each repair/remediation task is impractical;
- multiple upstream write paths need the same database-level quantitative backstop; or
- actual transactional effects must be bounded independently of upstream correctness.

The approaches are complementary, but the simpler narrow operation wins whenever it cleanly solves the workload.

---

# 11. Competitive strategy

Do not compete by feature count.

Avoid building toward:

- generic governance
- approval workflows
- huge integration matrices
- dashboard-heavy admin products
- many database engines too early

CommitCap should go deep on:

```text
actual effect measurement
+
transaction-wide accounting
+
cross-transaction authority consumption
+
state-transition limits
+
quantitative effect budgets
+
task-scoped capabilities
```

The moat should come from:

- precise semantics
- hardening
- bypass resistance
- PostgreSQL compatibility knowledge
- security reputation
- policy vocabulary
- integration quality

If PostgreSQL or a cloud provider ships a basic `MAX ROWS UPDATED` feature,
row limits alone are not a durable moat. Possible layers above that primitive
include state-transition authority, quantitative effect budgets, task-scoped
capabilities, cross-transaction consumable authority, atomic concurrent
consumption, retry/idempotency semantics, and capability lifecycle. These
layers are future strategy unless and until implementation evidence says
otherwise.

---

# 12. OSS strategy

Open source is important because CommitCap sits near production writes.

Community edition should prove the real primitive.

It should not be crippleware.

OSS goals:

- trust
- inspection
- local testing
- fuzzing
- developer adoption
- integration feedback
- security review

Commercial value should come from operational complexity and organization-wide authority management.

---

# 13. Commercial direction

Do not build commercial infrastructure before production adoption.

Potential future paid surface:

- task-scoped mutation capabilities
- cross-transaction budgets
- concurrency-safe capability consumption
- signed and expiring capabilities
- centralized policy distribution
- multi-database coordination
- long-term audit evidence
- Vault/KMS integration
- enterprise support
- OEM / embedded licensing

---

# 14. Product success signals

Strong signals:

- “We need this repair/remediation job to stay flexible, but it must be physically unable to change more than X.”
- “We already built our own trigger/budget table and do not want to maintain it.”
- “Can a capability span transactions and connections?”
- “How does this behave with retries, cascades, triggers, and savepoints?”
- “Does this work on RDS / our managed PostgreSQL?”
- “Can we try this against staging?”
- “Can we get compatibility guarantees, support, or embed CommitCap?”

Strong market evidence should arise from real workflows, preferably without first teaching the interviewee the CommitCap abstraction.

Weak signals:

- GitHub stars without installs
- “cool project”
- social media impressions
- generic praise
- hypothetical willingness to use a product without a recent concrete workflow

---

# 15. Kill criteria

Kill or radically narrow CommitCap if:

- generic bypass classes cannot be closed cleanly
- reliable mutation accounting requires unreasonable complexity
- performance overhead is unacceptable
- managed PostgreSQL deployment is impractical
- real users repeatedly show that a narrow API or stored procedure solves the workflow cleanly and leaves no meaningful residual blast-radius problem
- real users do not trust DB-side enforcement
- production adoption remains absent despite strong awareness
- the exact primitive becomes commoditized before CommitCap establishes meaningful differentiation

---

# 16. Current decision

## GO PROTOTYPE

Approved now:

- PostgreSQL V0
- security proof
- adversarial testing
- benchmark
- minimal demo
- managed-PostgreSQL feasibility investigation
- market interviews in parallel with technical proof, without allowing interviews to expand scope prematurely

Not approved now:

- six months of product development
- SaaS control plane
- enterprise dashboard
- company-scale sales infrastructure
- multi-database support
- large integration roadmap

---

# 17. One sentence to remember

> **CommitCap gives flexible production automation a finite loss envelope by turning database WRITE permission into measurable, consumable mutation authority.**
