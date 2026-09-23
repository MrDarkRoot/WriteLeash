# Real production-write workflow validation — founder research kit (#16)

**Status: preparation only, 2026-09-23.** Interviews conducted: **0**;
independently validated qualifying workflows: **0/3**. This document is a
founder-run protocol, not customer evidence or authorization for outreach. Use
the [product thesis](product.md), [roadmap](roadmap.md), and ADR-009/019 in
[decisions](decisions.md) as the selection boundary: a clean narrow API or
stored procedure wins over a generalized mutation budget. Start with recent
production repair, data remediation, incident-response recovery, DBRE/operator
scripts and backfills; investigate workflow/AI writers only when actual tasks
require them. Do not ask hypothetical product-interest questions first.

## Founder-run interview (20–30 minutes)

Ask permission to take anonymized notes, quote a paraphrase, and follow up to
verify facts; do not request production credentials, SQL, row data, incident
identifiers or personal contact information for a public PR. Keep original
notes privately with access limited to the founder; publish a sanitized
worksheet only after permission. One interviewee may describe multiple tasks,
but track independently evidenced *workflows*, including their organization
and provenance privately to avoid double counting.

1. “Show me the **last real production repair, remediation, incident recovery,
   backfill or operator write** where your credential could modify more than
   this particular task needed.” Record when it occurred, role and the concrete
   task in the interviewee's words before introducing CommitCap.
2. What PostgreSQL role or service identity executed it? What tables/columns,
   tenant scopes and operations **could** it change, and what row/amount/state
   changes did this task actually **need**? How many transactions, sessions and
   retries were involved? Was the writer an owner or able to disable triggers?
3. How were changes approved, previewed, limited, logged and rolled back?
   Which RLS/grants, API/procedure, transaction, change review, trigger or
   bespoke budget already helped? Ask for a sanitized example of a real
   failure/near miss if one exists; do not infer one from general anxiety.
4. Why did the task require flexible SQL or evolving write shapes? What would
   break if the permitted operation had to be predefined? Probe whether the
   workflow can instead be split into a small catalog of explicit operations.
5. If the database rejects an over-bound transaction, what happens to the
   incident, on-call response, lock duration, retries and partial external
   effects? Is denial acceptable, and at what bound and scope (per transaction
   versus across the whole task)?
6. Which PostgreSQL version, hosting/deployment model (self-managed, operator-
   managed Kubernetes, or fully managed DBaaS), privilege restrictions and
   extension/preload rules apply? Is changing the topology plausible?
7. Ask for permission to independently verify a sanitized artifact (runbook,
   change ticket, redacted script, schema/grant summary or second operator
   account). Record what was *shown*, what was *said*, and what remains unknown.
   Do not solicit confidential data for a public repo.

## Privacy-safe worksheet — duplicate once per actual workflow

Do not fill placeholders with illustrative customer results. Store evidence
references as private opaque IDs (never links to private tickets in a public
PR); publish only consented, de-identified summaries. Redact hostnames,
company/person names, credentials, customer data, production SQL predicates,
incident times if identifying, and exact amounts where sensitive. Round counts
only if the distinction between possible and intended blast radius survives.

| Field | Founder-entered observation / evidence |
| --- | --- |
| Workflow ID, category, interview date and source role | `[pending]` |
| Independent source / verification method / consent for public paraphrase | `[pending]` |
| Actual recent task, approximate timing and reason | `[pending]` |
| Writer credential: theoretically permitted tables, columns, operations and tenant scope | `[pending]` |
| Task's actually required mutations, bounded effect and transaction/session/retry pattern | `[pending]` |
| Current safeguards, incident/near-miss evidence (or explicitly none), outcome | `[pending]` |
| Why flexible SQL was needed; alternative narrow operation attempted | `[pending]` |
| API / stored procedure / trigger / approval challenge results (each separately) | `[pending]` |
| Database denial acceptable? abort/retry/partial-external-effect consequences | `[pending]` |
| PostgreSQL deployment model/version; native extension, preload and admin/DDL constraints | `[pending]` |
| Private sanitized artifact IDs and independently corroborated facts | `[pending]` |
| Contradictory facts, unknowns, verification owner and follow-up date | `[pending]` |
| Classification and specific reason; reviewer/date | `inconclusive — no evidence yet` |

## Narrow-operation challenge and classification

For the **same real task**, attempt these four boundaries with the interviewee;
record the proposed input/output shape, who can call it, which mutations it
allows, what it forbids and why it succeeds or fails:

| Alternative | Challenge question |
| --- | --- |
| Narrow API | Could one typed operation (or small stable catalog) encode every required write without arbitrary SQL? |
| Stored procedure | Could a parameterized, privilege-restricted PostgreSQL routine cover the legitimate task and prevent the broad write? |
| Trigger / RLS / grants | Could fixed database rules or row scopes enforce the observed upper bound without introducing new authority? |
| Approval step | Would review, a dry run or existing change control bound the real failure mode sufficiently, including retry/incident timing? |

Classify **broad-but-bounded remains useful** only when a specific recent
production workflow is corroborated, its credential's possible vs required
effects are distinct, database denial is operationally acceptable, broad SQL
is materially needed, and the narrow-operation challenge leaves a concrete
gap or independent database-level backstop. Classify **simpler operation
suffices** if a narrow boundary cleanly solves the real task; this is a
falsification signal, not a failed interview. Classify **inconclusive** if
permission, authentic artifact, deployment constraints, denial semantics or
challenge reasoning is missing; do not count it. A per-transaction research
fixture does not meet a workflow needing task-wide protection against
transaction splitting. Count at most one instance of a duplicated workflow;
require **three separate independently verified actual workflows** before
claiming Success Checklist #3. Record any staging/install/commercial interest
only when actually volunteered and evidenced, independently of this count.

## Founder decision memo (complete only after authentic evidence)

```text
Decision date / reviewer: [pending]
Verified workflow IDs and independent corroboration: [none; 0/3]
Qualifying / simpler / inconclusive counts: [0 / 0 / 0]
Most important narrow-operation counterexample: [pending]
Denial and deployment blockers: [pending]
Transaction-local vs task-wide requirement: [pending]
Actual staging, adoption or commercial signals: [none evidenced]
Decision: [proceed / narrow target / investigate more / stop — undecided]
Reason, contrary evidence and next falsifiable question: [pending]
```

The founder controls access and outreach. This kit supplies no evidence that
any team wants to install CommitCap, that a staging pilot exists, or that the
original six-part Success Checklist has advanced. Leave #16 open for actual
workflow validation.
