# F — CEO / Founder Decision Memo

2026-10-13. **Recommendation: READY_FOR_BOUNDED_EXPERIMENT.** This approves nothing by itself. The present package is research-only; runtime, release files, licensing, canonical decisions and PRs #259–#262 remain untouched.

## Decision

The best initial capability is **F01: a reproducible effect-boundary evidence bundle around the existing PostgreSQL demo**. It is independently useful for reviewing a security claim and can falsify an overstated one. It does not require an AI model, production database, new native mechanism or control plane. Estimated incremental effort: **3–6 engineering days plus 1–2 independent review/reproduction days**, subject to environment availability.

The smallest existing prevention primitive worth demonstrating is the restricted-writer, per-transaction UPDATE row-event ceiling with sticky denial. The next commercial-sounding idea—durable task authority—requires a different architecture and is not established by today's test evidence.

## Five opportunities worth further research

1. **Evidence developers can reproduce:** distinguish denied request, aborted transaction and actual durable effects (F01/F04/F10).
2. **Broad-but-bounded repair tasks:** aggregate effects across committed batches while preventing self-renewed authority (F11/F12).
3. **Ambiguous retry resolution:** bind one operation and payload to a durable result, then charge distinct operations against an aggregate allowance (F10/F13).
4. **Small but catastrophic mutations:** compare one forbidden transition or positive-delta constraint against simpler DB controls (F08/F09).
5. **Cross-worker authority correctness:** find overspend, compensation refill and revocation races before an adapter exposes the mechanism (F14/F15).

These are technical opportunities, not five validated markets. Strong recurring production pain, customer budget and willingness to pay remain **NOT VERIFIED**. The strongest public automation example in A also supplies its own inexpensive resolution.

## Five easiest useful features

F01 evidence bundle; F02 extension of existing inspection reporting; F03 strict V0 plan validator; F04 disposable repair replay/diff; F05 controlled benchmark runner. Combine them with existing CLI infrastructure where appropriate. Do not market a new doctor or multi-table budget as if the repo did not already implement them.

## Five hardest features

F12 effect/debit atomicity across transactions; F14 precise expiry/revocation; F15 multi-worker/crash composition; F17 causal allowance across executions; F18 deployable equivalent contracts across restricted hosting. F16 becomes credible only after the underlying boundary survives those tests relevant to its claimed scope.

## Three attractive but premature ideas

- **A universal MCP firewall:** established gateways already cover identity, policy and approvals. A new proxy does not inherently know committed effects.
- **A causal authority control plane:** useful-sounding, but trusted lineage, root issuance and alternate paths are harder than counting calls.
- **Cryptographically signed effect receipts:** signatures establish provenance of an assertion, not truth of the database effects. First build a reliable atomic marker and independent resolver.

## Critical technical problem and misconception

**Unresolved:** atomically couple durable consumption to actual in-scope mutations under a trusted, non-self-renewable task identity, including retries, concurrency and crash recovery. Start with a one-database restricted procedure; add native integration only if the required contract needs it.

**Largest misconception:** “A transaction budget, a stateful tool policy, or a successful tool reply already proves a task-wide limit on committed database changes.” They have different lifetimes and trust boundaries. A cumulative relational metric also does not prove an external payment or business outcome.

## Alternatives that can make the roadmap unnecessary

Use PostgreSQL grants/RLS and a narrow stored procedure first. Correct idempotency and origin filtering can eliminate the retry/loop job. Disposable branches can remove production access entirely. Bytebase may already meet change governance and recovery requirements; pg-safeupdate may suffice for accidental missing-WHERE mistakes. AgentCore temporal policy is a particularly strong counterexample to broad novelty claims about stateful agent authorization. Pomerium, Permit and Cloudflare address gateway governance.

Reject or shrink the direction if:

1. F01 does not add reproducibility beyond current demo output, or fails its disabled-guard/missing-oracle controls.
2. The desired repair can be expressed as a small existing procedure/RLS/idempotent operation with no material loss of utility.
3. Writers can mint new task authority or reach an unmediated write path under the proposed deployment.
4. Committed effect/debit atomicity cannot survive deterministic crash/concurrency tests.
5. Intended users cannot install the needed mechanism and a SQL-only alternative loses the required property.
6. Correctness holds but overhead or recovery interruption is unacceptable for an identified workload; no numeric acceptance threshold is invented here.
7. Independent developers do not reproduce/use the tooling and no repeated broad-but-bounded workflow emerges. Technical novelty alone does not justify commercialization.

## Product and licensing structure

Maintain one repository with clear research/tooling/adapter boundaries. Preserve WooCommerce as the existing separate merchant-facing execution engine. Do not launch five products or create five codebases.

**Recommendation, not current license status:** make the primitives, semantics, negative tests and reproducibility tooling open-source under a separately selected Founder-approved license. The non-WordPress research currently has no repository-wide selected license. Change none in this task.

Possible later paid value: maintained deployment compatibility, reviewed adapter support, enterprise/OEM integration and support obligations. Buyers would plausibly be platform engineering/security leads, database tooling vendors or automation infrastructure teams. This is **HYPOTHESIS**, not evidence of purchases or budgets. A generic dashboard, JSON receipt or wrapper around free RLS is commercially weak.

## Exact first implementation/experiment approval to request

> Approve one isolated, lab-only implementation PR for F01 and execution of E01–E04 plus the disabled-guard and missing-oracle negative controls, using the existing pinned PostgreSQL 16.4 synthetic Docker fixture. Permit changes only to the demo/evidence harness, its tests and scoped documentation; keep native enforcement semantics unchanged. Require fresh-connection durable-state verification, two clean runs, explicit transaction-splitting counterevidence and cleanup. No production credentials/data, cloud purchases, MCP server, task ledger, licensing changes, release modifications or merge authorization. Stop and report if a supported invariant fails or this scope requires native changes.

This is the concrete next-scope approval needed because the present mandate explicitly excludes implementation. No additional permission is needed to review these documents. The first milestone is fulfilled only when an independent clean rerun produces the specified evidence; **it has not been performed in this research task**.

## Package links

- [A — State of AI Write Safety 2026](A-state-of-ai-write-safety-2026.md)
- [B — Technical Gap Analysis](B-technical-gap-analysis.md)
- [C — Creative Feature Discovery: 18 candidates](C-creative-feature-discovery.md)
- [D — Progressive Feature Roadmap](D-progressive-feature-roadmap.md)
- [E — Reproducible Security Research Plan: 17 designs](E-reproducible-security-research-plan.md)

The recommendation is readiness for a narrowly bounded **experiment**, not production readiness, product-market fit, or authorization to implement the later roadmap.
