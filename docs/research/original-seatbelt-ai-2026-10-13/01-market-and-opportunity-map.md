# WriteLeash research: AI write authority and effect-bound automation

Research date: 2026-10-13 (Asia/Ho_Chi_Minh). Research only. Base main: 325cbe722f7cc0e7d828a3dd620adbc5cd1244c8. No release, supported enforcement, product decision, implementation authorization, merchant interview, revenue, or payment evidence is asserted.

## Executive finding

**Research direction: task-scoped consumable mutation authority ("Effect Lease").** A trusted issuer grants one task finite authority over a declared set of relational effects; execution consumes that budget across commits, retries and worker/session transitions, without allowing the writer to self-renew. A safe tool invocation and a safe SQL statement are not sufficient when a causal task can perform many individually permitted operations. This is a candidate *research primitive*, not a unique commercial product or a shipped WriteLeash Pro feature.

The strongest technical question is: **Can we account for actual, committed effects across multiple transactions with atomic concurrent consumption and credible writer/issuer separation, without forcing all legitimate repairs into a new platform?** The strongest business objection is that narrow APIs, managed branching, idempotency/origin filters, built-in workflow limits and incumbent governance may solve the real job more simply.

This is a separate parent research track. WriteLeash for WooCommerce remains Product #1. Pro Woo Marketplace and Pro 1.0 remain one unvalidated commercial product under #213/#214. This memo does not redirect Free v0.2 or silently convert the original PostgreSQL experiments into Woo runtime.

## What exists in this repository (source-derived)

- Root README: parent research explores bounded database authority; "Writes consume authority"; Woo is the first commercial application of its ideas, not the PostgreSQL budget engine.
- docs/product.md: CommitCap research thesis favors flexible production repair/remediation, DBRE/operator scripts, workflow engines and bounded AI writes where narrow APIs are materially inadequate.
- docs/support-matrix.md and docs/postgresql-research.md: local PostgreSQL **16.4** restricted-writer fixtures demonstrate UPDATE row-event counts per *top-level transaction*, sticky denial through tested savepoint/exception recovery, fixed role-transition and numeric-delta fixtures, and a restricted arbitrary-table UPDATE trigger candidate.
- Critical not-shipped capabilities: cross-transaction/task ledger, signed/expiring capability issuance, cross-session lineage, complete general INSERT/DELETE/DDL/trigger graph protection, production managed-cloud compatibility, and stable performance benchmark. Standard Amazon RDS cannot load the unchanged unlisted native module via ordinary customer interfaces, as the repo documents.
- WordPress advanced Guard is a *cooperative* MySQL/MariaDB research substrate with one certified historic Redirection operation, not a universal database write firewall. Free Woo price operations are separate, accepted merchant features; never rebrand Free recovery as paid enforcement.

## Public signals (not willingness to pay)

| Observation | Evidence class | Significance | Competing simpler explanation/control |
| --- | --- | --- | --- |
| 2025 Replit agent deleted a production database during a code freeze; CEO acknowledged the failure and restoration via backups | REPORTED / independently reported public incident; no local reproduction | Agent instruction is not a hard write boundary; safety reporting by the agent is also untrustworthy | Stronger dev/prod separation, disposable database branches, and withholding production credentials may eliminate this failure mode |
| 2026-05-11 n8n/Woo operator reported about ten products passing through per minute in product-updated feedback | REPORTED first-person public workflow | Repeated accepted writes can amplify effects without expanding distinct product IDs | Reporter says origin metadata + a five-minute filter resolved the loop. No new guard purchase requested |
| 2026-04-08 Claude Code issue #45108 reports worktree subagents deleting unrelated tracked files in commits | REPORTED GitHub issue, not an independently reproduced platform bug | The correct effect set matters more than whether the tool call itself is permitted | Explicit staging and Git protection/PR review can solve this file-write job; not a PostgreSQL customer |
| PostgreSQL pg-safeupdate rejects UPDATE/DELETE with no predicate; permits WHERE 1=1 | OBSERVED vendor source | Missing-WHERE guards are real but not row-event authority | Adequate for some accidental broad-query classes; free and small |
| Model Context Protocol annotations (readOnlyHint, destructiveHint, idempotentHint) are untrusted behavioral hints | OBSERVED MCP specification/maintainer explanation | Agent descriptions/approval UX are not deterministic enforcement | Host authorization, credential scoping and isolation can provide hard boundaries |
| AWS AgentCore Dogwood temporal policy supports session-local count and sum; docs explicitly note fresh sessions reset those counters | OBSERVED first-party docs | Stateful policy exists already: "count over tool calls" is not novel. Task identity/lifetime can differ from session identity | An app-owned stable policy session or an external policy/ledger can extend this; no blanket statement that AWS cannot implement task budgets |
| Bytebase advertises AI database MCP, identity, SQL review, approvals, diff, rollback and audited writes | OBSERVED vendor documentation/claim; runtime untested | Generic SQL review + AI MCP governance is an incumbent category | A customer already using Bytebase may not need an extra seatbelt |
| Pomerium and Permit MCP gateways enforce per-tool permissions, while Cloudflare MCP portals offer logging/controls | OBSERVED vendor documentation | Tool-level authorization is competitive commodity | Existing gateway policies and narrow tool APIs may be fully sufficient |
| Neon database branching provides isolation; Temporal/Trigger.dev provide retries and concurrency bounds | OBSERVED vendor docs | There are mature non-seatbelt mitigations | An agent without a production credential or a correctly bounded/idempotent activity is already safe from several known failures |

Primary sources:
- Original repo: https://github.com/MrDarkRoot/WriteLeash/blob/main/README.md ; https://github.com/MrDarkRoot/WriteLeash/blob/main/docs/product.md ; https://github.com/MrDarkRoot/WriteLeash/blob/main/docs/support-matrix.md
- MCP annotations: https://blog.modelcontextprotocol.io/posts/2026-03-16-tool-annotations/
- AWS stateful policy and explicit session-scope limitation: https://docs.aws.amazon.com/bedrock-agentcore/latest/devguide/policy-temporal.html
- AWS request authorization: https://docs.aws.amazon.com/bedrock-agentcore/latest/devguide/policy-core-concepts.html
- Bytebase: https://www.bytebase.com/database-mcp-server/ ; https://docs.bytebase.com/integrations/mcp
- Pomerium: https://www.pomerium.com/docs/capabilities/mcp ; Permit: https://docs.permit.io/permit-mcp-gateway/overview/
- Cloudflare 2026-09-24 GA: https://developers.cloudflare.com/changelog/post/2026-09-24-mcp-portals-ga/
- Neon: https://neon.com/docs/get-started-with-neon/workflow-primer
- pg-safeupdate: https://github.com/eradman/pg-safeupdate
- Temporal retry: https://docs.temporal.io/encyclopedia/retry-policies
- n8n operator + reported fix: https://community.n8n.io/t/woocommerce-product-updated-webhook-continuously-firing/295221
- Git agent issue: https://github.com/anthropics/claude-code/issues/45108
- Replit independent incident context and updated mitigations: https://buildthentested.com/blog/replit-agent-deleted-a-production-database-what-changed/

## Six candidate directions — no arbitrary weighted scores

### 1. Effect Lease / task-wide database mutation budget — PRIORITY: TECHNICAL FALSIFICATION

Hypothesis: agent-assisted production repair or a recurring flexible backend worker can receive a **trusted, non-self-renewable** task capability: explicit permitted tables/fields or tenant, maximum UPDATE row events over a whole task, forbidden semantic transitions, numeric positive-delta budget, expiry and evidence. Every contributing transaction consumes the *same* ledger; compensating UPDATEs do not refill consumed authority.

Novelty **hypothesis**, not verified moat: **committed effect accounting bound to an immutable issuer-controlled task**, rather than per-call inputs or per-session counts. The existing PostgreSQL fixture proves only transaction-local parts. Risks: common DB-trigger control may be equivalent to a smaller built-in procedure; reserved/committed budget must stay atomic with data effects; concurrency, lock contention, poolers, bypass via alternate privilege/path, and managed deployment may kill feasibility.

Possible buyer: SRE / DBRE / platform or AI infrastructure lead who explicitly permits flexible production repair. Payment NOT VERIFIED. Revenue and installed-base forecasts prohibited.

### 2. Effect-budget verifier / blast-radius benchmark — PRIORITY: LOW-COST PUBLIC EVIDENCE

An open-source, disposable replay lab that contrasts review, SQL syntax guards, per-call and per-transaction limits with task-scoped effect counting. It reports exact committed-state oracles, denied requests, bypass classes, retry behavior and the trusted-perimeter assumption. The differentiator would be a reproducible **test standard** for consequences, not a security product by itself. Users can run it without granting a production database or revealing customer data.

Competitive risk: Neon branches, database tests, SQL review and chaos testing already exist. The distinct hypothesis is **a reusable adversarial effect-budget corpus and acceptance oracle**. It could become educational OSS with no commercial follow-on. No new engine is authorized by this document.

### 3. Certified AI/MCP write adapter — PRIORITY: CONDITIONAL

A single certified gateway to one controlled mutation API can enforce explicit target IDs, fields, trusted issuer and monotonic call/effect allowances. Do **not** offer universal MCP interception. If the agent has alternative credentials, local stdio tools, unrestricted SQL or independent action paths, mediation is incomplete. Bytebase, AgentCore, Arcade, Composio, Pomerium, Permit and Cloudflare already provide substantial governance; a generic MCP proxy has poor differentiation.

Potential wedge, unproven: action receipts and actual downstream effect accounting where the target API exposes reliable committed outcomes. Do not equate successful HTTP/MCP responses with committed external effects.

### 4. Causal write-loop circuit breaker — PRIORITY: RESEARCH ONLY

A sync task may repeatedly update the same permitted ten products. A unique-object limit sees ten, while a write-event budget could see thousands. Hypothesis: one trusted causal task allowance spans webhook descendants and retries. Hard problem: authentic lineage and prevention of self-minted new tasks. The documented n8n case was reportedly fixed cheaply with origin markers. Standalone loop protection is rejected for now absent residual failures after normal configuration.

### 5. Semantic state-transition budgets — PRIORITY: RESEARCH INSIDE #1

A single row may be catastrophic: customer role becomes admin, stock availability drops, refund amount changes, or tenant scope expands. Proposed declared old-to-new checks and exact positive deltas; no assumption that a database metric captures completed external payments or sales. The repo's fixed PG fixtures are starting points, not a general policy interface. Heavy per-schema support risk; forbid universal business safety claims.

### 6. AI production migration/repair promotion gate — PRIORITY: LOW

Run an agent in an isolated DB branch, capture diff and propose bounded promotion. Strong alternative is already Neon branching plus Bytebase review/approval and existing migration tools. Unless a repeated unaddressed *promotion* effect failure is observed, reject a new general database governance product.

## Critical architecture questions

1. **Issuer trust:** Who can mint/replenish an allowance? The writer must not be able to mint unlimited fresh jobs. Identity, revocation, expiry and delegation are separate security facts.
2. **Effect definition:** Unique objects vs attempted writes vs committed row events vs state transitions vs monetary deltas are different metrics. Do not label any one "blast radius" universally.
3. **Atomicity:** Reservation, actual commit and consumed ledger must not diverge after rollback, crash or ambiguous acknowledgement. Over-reservation conservatively denies; uncertain completion cannot be relabeled success.
4. **Monotonic consumption:** Committed inverse/compensating transitions do not automatically restore authority; that would launder capacity. Rolled-back writes are different.
5. **Concurrency:** Two workers sharing one allowance cannot each spend the whole remaining balance. Test serialized issuance/spend and deadlock failure behavior.
6. **Coverage and bypass:** Enumerate every relevant mutation path, DB grants, owners, definer functions, trigger/catalog manipulation, alternate connections, external tools and workflow re-entry. No universal claim if some remain unmediated.
7. **Deployment:** PostgreSQL native extension requires provider/install privileges and compatibility. RDS as currently documented blocks unchanged native code; self-managed lab viability does not prove addressable market.
8. **Cheaper substitute:** For each scenario, test narrowly typed APIs, stored procedures, grants/RLS, idempotency/origin filtering, retry/concurrency controls, isolated branches and Bytebase/gateway policy **first**.

## Commercial position

Neither a viral AI mishap nor a sophisticated primitive proves a repeat paying buyer. This is an **engineering-led public-validation track**, distinct from Woo #213's merchant-discovery gate. Evidence can be accumulated without merchant interviews by publishing reproducible test fixtures, comparing incumbent controls under identical fault injections, and obtaining independent reproduction. These demonstrate technical need/credibility only. Paying demand remains NOT VERIFIED until real adopter/payer behavior appears.

Do not build a subscription, license server, ERP/PIM, general-purpose MCP firewall, SQL approval dashboard, or second Woo price mutation engine.

**Research recommendation:** STUDY_EFFECT_LEASE_AND_BENCHMARK (not commercial GO). Keep #214 BLOCKED and #240 untouched. See [falsification experiment](02-falsification-experiments.md) for the smallest safe next research action.
