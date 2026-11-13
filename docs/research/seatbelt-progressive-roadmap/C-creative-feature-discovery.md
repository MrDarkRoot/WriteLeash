# C — Creative Feature Discovery

2026-10-13. All candidates are **HYPOTHESIS**, not approved features. Existing mechanisms and external alternatives are evidenced in [B](B-technical-gap-analysis.md) and [A](A-state-of-ai-write-safety-2026.md). There are **18 candidates**, including ideas to combine or reject. Newness means a difference from the inspected baseline, not a patentability or market-first assertion.

## Common boundaries and evaluation rules

All candidates can initially be evaluated with synthetic data and no production credentials. Trusted administrators, host operators and a compromised trusted implementation remain outside proposed enforcement coverage. A writer is non-owner, non-superuser and cannot modify policies, issue authority or obtain a bypassing role. Where an adapter is the boundary, all in-scope mutations must use it and its credentials must be inaccessible to the agent. Unknown alternate paths invalidate a complete-coverage claim.

F01–F05 are developer/research tools: they detect/report; they do not prevent production writes. F06–F09 concern narrow database enforcement. F10 is commit evidence, not prevention. F11–F15 propose one-database authority control. F16–F18 are integration/frontier research. This distinction applies to every card below.

Difficulty below is **engineering / verification / maintenance**, each Low, Medium, High or Very high. Size is a **rough incremental engineering-day range for one experienced maintainer**, including tests/docs but excluding independent review, learning time and production support. It is not a delivery promise; do not add ranges into a launch date. Reuse paths are indexed in B. Every experiment ID refers to a **design, not a completed test**, in [E](E-reproducible-security-research-plan.md).

## EASY — useful without a new enforcement engine

### F01 — Effect-boundary evidence bundle — PRIORITIZE FIRST

- **Job/actor/resource:** A developer reviewing an agent repair or guard cannot tell whether an apparent denial actually left durable data. Produce a versioned evidence bundle linking fixture, policy, writer privileges, SQL outcome and fresh-connection state oracle.
- **Minimum:** Extend the existing arbitrary-table demo with machine-readable evidence and a disabled-guard negative control. Distinguish expected counterexamples from enforcement failure. Reuse demo, current native module and exact-row assertions; no new native logic.
- **Boundary/exclusions:** Trusted disposable harness and independent observer; unsigned evidence, no production attestation, no universal receipts. All fixture writes must be enumerated; no control of unrelated database paths claimed. An application adapter may consume the report, but is unnecessary.
- **Success/negative:** E01–E04 pass their expected classifications; forged success log, missing oracle, truncated run and disabled enforcement cannot yield an enforcement PASS.
- **Cost/dependencies:** No new product dependency; local Docker/Compose and trusted fixture setup. Low/Medium/Low; **3–6 days**.
- **Alternative/invalidation/commercial:** Existing demo logs may already answer the question; stop if the bundle adds no reproducibility or automation value. Suitable for future OSS licensing. Potential later customer: database tooling maintainer buying reviewed compatibility, not paying for a JSON file.

### F02 — Point-in-time write-boundary inspection report

- **Job:** Developers mistakenly run a guard under owner credentials or assume an unsupported table is covered. Extend existing CLI/catalog checks into a report of supported, unsupported and unknown paths with provenance.
- **Minimum/reuse:** Existing doctor, `verify_update_protection.sql`, role-reachability checks; export table/trigger/role findings. Never print grant secrets or auto-fix permissions. No native change; optional read-only catalog adapter.
- **Boundary:** Point-in-time inspection, not continuous prevention or complete recursive graph certification. Unknown roles/functions/other writers remain UNKNOWN. Read-only catalog inspection must not claim the separate WP Doctor performs no transactional probes.
- **Test/size:** E05: missing writer, elevated role, extra trigger and unsupported version never produce overall PASS. Low/Medium/Medium; **3–7 days**, depends on F01 output contract; trusted catalog visibility required.
- **Alternative/kill/payer:** Plain SQL audit may suffice. Reject standalone product if reports mostly duplicate existing preflight. OSS utility; potential platform-engineering buyer only for maintained environment coverage, unvalidated.

### F03 — Strict V0 policy-plan validator

- **Job:** A developer accidentally represents a transaction limit as a task limit or emits malformed trusted installation SQL.
- **Minimum/reuse:** Validate an offline manifest of existing qualified-table/integer-budget/writer inputs; explicitly require `scope=transaction`, `metric=update_row_events`; call existing validation/generation. Reject unknown fields. No new policy DSL or native logic.
- **Boundary:** Plan syntax/meaning only; no claim that installed catalogs match. Application-side/offline implementation is enough; no all-path enforcement needed because it enforces none.
- **Test/size:** E05 rejects injection identifiers, negative/overflow budgets, task scope and unsupported DML. Low/Low/Low; **2–4 days**; depends on F01 terminology, no DB privileges.
- **Alternative/kill/payer:** Existing CLI may be simpler. Combine into it; reject if manifest merely adds configuration burden. OSS, negligible standalone commercial value.

### F04 — Disposable repair replay and effect comparison

- **Job:** A repair/migration author wants to compare intended IDs with actual touched objects before approving a real operation.
- **Minimum/reuse:** Synthetic two-tenant fixture, existing demo setup, exact before/after rows plus a fixture event oracle. Compare broad predicates and repeated updates. No production snapshot import or promotion button.
- **Boundary:** Simulation of this input state only; final row diff alone cannot count no-op/repeated events. Isolation is the prevention boundary. No native requirement beyond optional existing guard comparator; adapter can replay fixture SQL. No external triggers/network effects allowed.
- **Test/size:** E06 and E10: intentionally stale snapshot and equal final state after oscillation must invalidate “predicted production effect” claims. Medium/Medium/Low; **4–8 days**, depends F01; local fixture admin.
- **Alternative/kill/payer:** Disposable SQL scripts or Neon branches may suffice. Retain only if comparison reduces review effort; OSS, possible internal-tools customer later.

### F05 — Reproducible benchmark protocol and runner

- **Job:** Researchers mistake noisy timings for low overhead. Package controlled paired baseline/guarded runs with raw distributions and environment metadata.
- **Minimum/reuse:** Existing benchmark design and security oracles; alternate order, reset identical data, separate denied paths, report variance and INCONCLUSIVE. No optimization or performance promise.
- **Boundary:** Measurement only; no all-path prevention. Native unchanged; adapters unnecessary. Controlled local hardware needed for meaningful numbers.
- **Test/size:** E14: deliberate background contention must degrade confidence rather than produce a preferred percentage. Medium/Medium/Low; **4–9 days**, depends F01; benchmark reproducibility can exceed this range.
- **Alternative/kill/payer:** pgbench/custom scripts may be enough. Do not duplicate PR #22's work without identifying its reproducibility defect. OSS; no credible standalone subscription.

## MEDIUM — narrow, explicitly constrained behavior

### F06 — Auditable UPDATE installation contract

- **Job:** An operator installs a proven primitive in an unproven topology. Turn the existing SQL/preflight flow into a versioned, reproducible installation contract for **one PG16.4 ordinary table**, with drift detection before each lab run.
- **Minimum/reuse:** Existing generic guard, catalog verifier and demo; object fingerprints and explicit unsupported shapes. This does not invent per-table or multi-table budgets, which already exist.
- **Boundary:** Ordinary UPDATE under restricted writer; grants deny alternate DML/DDL. Owners and later privileged changes excluded. Existing native module/preload required; an adapter alone cannot certify arbitrary SQL paths.
- **Test/size:** E02/E03/E05; changed owner/trigger or second connection with broader grants fails readiness. Medium/High/Medium; **5–10 days**, depends F01–F03.
- **Alternative/kill/payer:** Reviewed SQL runbook may be sufficient. Defer packaging until independent reproduction identifies installation failures. OSS primitive; commercial support only after actual deployment need.

### F07 — Approved-object/tenant scope comparator — PREFER INCUMBENT

- **Job:** A repair agent stays below a row budget but edits the wrong tenant. Demonstrate intersection of row-event allowance with a fixed authorized object set.
- **Minimum/reuse:** Two-tenant fixture plus ordinary grants/RLS or a narrow procedure; compare to count-only guard. Trusted binding, not caller-set tenant text. No custom RLS replacement or new native code.
- **Boundary:** All writes by that role must obey the chosen RLS/procedure path; owner/BYPASSRLS excluded. An adapter works only with DB grants enforcing its exclusive path.
- **Test/size:** E06: one forbidden tenant row is denied even below count budget; privileged negative control demonstrates exclusion. Medium/High/Medium; **4–8 days**, depends F04.
- **Alternative/kill/payer:** RLS likely solves it fully. Retain a benchmark/reference recipe, reject standalone feature unless composition exposes a real gap. OSS; no independent paid differentiation established.

### F08 — One configurable forbidden transition

- **Job:** A repair worker changes one account into a privileged state; a five-row ceiling is irrelevant.
- **Minimum/reuse:** Generalize the tested text-role fixture to one administrator-selected text column and forbidden destination, retaining shared sticky denial. Explicit handling of NULL and same-value transitions; no arbitrary predicates or policy language.
- **Boundary:** Defined UPDATE column/table only; no general authorization engine or external business semantics. Native changes if preserving current sticky denial; a narrow procedure/constraint can supply a weaker/different contract.
- **Test/size:** E07: forbidden destination, NULL, savepoint recovery and mixed siblings; no denied transactional effect durable. Medium/High/Medium; **8–15 days**, depends F06.
- **Alternative/kill/payer:** A CHECK, grants excluding that column or narrow procedure may solve the real requirement better. Defer unless ordinary flexible UPDATE is necessary. OSS rule; hypothetical support buyer, no demand evidence.

### F09 — One configurable positive-delta metric

- **Job:** A repair worker increases one numeric field far beyond approved scope while touching few rows.
- **Minimum/reuse:** Generalize exact numeric fixture to one specified column/domain; cumulative positive increases, no replenishment from decreases. Keep one database/version and documented precision.
- **Boundary:** Relational metric only, not payments or cross-TX task allowance. Existing native arithmetic/callbacks reusable; native changes required for generic sticky path. Procedure alternative should be measured first; all scoped UPDATE paths must be covered.
- **Test/size:** E08: +80, -80, +30 against 100; overflow, NaN/infinity/scale, contention, savepoints. High/High/High; **10–20 days**, depends F06 and fixture semantic review.
- **Alternative/kill/payer:** Domain ledger/stored procedure may already own this invariant. Reject generic financial branding. OSS semantics; enterprise integration relevance only if a concrete numeric job survives comparison.

### F10 — Commit-outcome resolver and effect receipt

- **Job:** A worker loses its response after commit and cannot distinguish “did not run” from “committed.”
- **Minimum/reuse:** Fixture operation marker written atomically with mutation, fresh observer query returning committed/absent/unknown plus policy/operation/version references. A state diff alone is insufficient under concurrent changes. Reuse durable oracle; add a narrow SQL/procedure experiment.
- **Boundary:** Trusted marker writer and reader; no cryptographic truth, external side effects or universal transaction proof. A forged adapter response is untrusted. All in-scope operations need atomic marker insertion; unrelated paths remain outside receipt coverage.
- **Test/size:** E09: drop acknowledgment, crash before commit, replay mismatched parameters; UNKNOWN on unavailable observer. Medium/High/Medium; **6–12 days**, depends F01/F04, no native requirement.
- **Alternative/kill/payer:** Transactional outbox/idempotency tables may fully solve it. Keep only cross-tool evidence value; OSS schema, hypothetical automation-platform integration revenue later.

## HARD — durable authority, one database first

### F11 — Trusted task issuance and binding

- **Job:** An agent renews its allowance by inventing IDs or opening sessions. Separate trusted issuer from restricted writer; bind task to role, scope and initial allowance.
- **Minimum:** One SQL-backed grant record; no writer INSERT/replenishment, no caller-controlled GUC as proof of identity. Reuse role tests, not a nonexistent task subsystem. No native code intrinsically required.
- **Boundary:** Trusted issuer's authorization decisions remain trusted; issuer compromise or legitimately unlimited grants are outside coverage. Adapter can convey identity only if the database validates binding and no alternate credentials exist.
- **Test/size:** E11 self-mint, ID theft, role substitution, replay expired ID. High/High/Medium; **10–20 days**, depends F06/F10, issuer schema ownership and reviewed grants.
- **Alternative/kill/payer:** Fixed procedure per job may avoid task issuance. Defer until it cannot. OSS authority contract; potential platform/security team payer remains HYPOTHESIS.

### F12 — Atomic cross-transaction committed-effect ledger

- **Job:** A worker commits batches each below five but exceeds a task allowance. Serialize remaining allowance with mutation inside one database transaction.
- **Minimum:** One operation/table, committed UPDATE-event ceiling, ledger row lock and debit in the same transaction. Rollback releases uncommitted consumption; committed compensation never refills. Start with a narrow SQL procedure before native integration.
- **Boundary:** All scoped writes must reach this ledger; direct unmediated UPDATE must be denied or intercepted. No cross-database/external effects. SQL implementation may enforce a committed ceiling without sticky poisoning of the entire outer transaction; those contracts must not be conflated.
- **Test/size:** E10–E12: split commits, compensation, concurrent workers, crash between debit/effect. High/Very high/High; **20–40 days**, depends F11; no mandatory new native code for procedure-only proof.
- **Alternative/kill/payer:** A short application-specific procedure may be the whole solution. Reject broad infrastructure if it adds no reusable property. Strongest later primitive/OEM candidate; willingness to pay NOT VERIFIED.

### F13 — Retry operation identity and payload binding

- **Job:** Timeout retries duplicate a committed change or reuse an operation key for a different mutation.
- **Minimum:** Task-scoped operation ID, canonical parameter digest, atomic result/consumption record; same ID/same input returns prior result, different input denies. Stable business operation identity is issuer/application responsibility.
- **Boundary:** Writer cannot edit dedup records or escape task identity; TTL cannot silently restore spent authority. New legitimate operation IDs still consume shared budget. SQL/procedure or exclusive adapter possible; native not inherently required.
- **Test/size:** E09/E12: concurrent duplicate, lost acknowledgment, altered payload, key expiry. High/High/High; **10–20 days**, depends F10–F12.
- **Alternative/kill/payer:** Correct existing idempotency eliminates the duplicate-execution problem. Retain only if task aggregate limits still matter; OSS semantics, later certified workflow integration may have value.

### F14 — Expiry/revocation with a defined ordering point

- **Job:** Background worker continues using old approval. Define revocation as serialized admission: once revocation commits, later admissions deny; already admitted transactions may finish.
- **Minimum:** Lock/check task record with explicit database clock semantics; no claim that revocation retroactively cancels committed work or instantly interrupts an in-flight transaction. Commit-time expiry would be a separate stronger design.
- **Boundary:** Trusted issuer/clock/database; no self-renewal. SQL procedure can implement admission contract; native required only if a later contract needs commit callbacks. All scoped admission paths required.
- **Test/size:** E13 waits/clock boundary/revoke race; bounded lock timeout and separate trusted recovery authority. High/Very high/High; **10–20 days**, depends F11/F12.
- **Alternative/kill/payer:** Short-lived credentials and gateway revocation may suffice. Defer absent a precise in-flight requirement. OSS definition; enterprise policy integration hypothetical.

### F15 — Multi-worker crash-correct authority composition

- **Job:** Concurrent repairs overspend or leave stranded allowance after cancellation/crash. Compose ledger, dedup and existing native sticky transaction semantics where required.
- **Minimum:** Two workers, one task, deterministic interleavings, consistent lock order; count accepted commits and ledger together after process death/restart. No distributed consensus service or throughput SLA.
- **Boundary:** Single PostgreSQL durability boundary; native integration may be needed for the selected sticky contract. All task mutation paths must bind the same ledger. Does not cover networked downstream side effects.
- **Test/size:** E12–E13; deliberately remove lock/debit atomicity and require detection. Very high/Very high/High; **20–45 days**, depends F12–F14 and controlled failure-injection lab.
- **Alternative/kill/payer:** Serialized narrow worker may meet throughput needs more cheaply. Reject complexity without concurrency requirement. OSS tests/core; later DB platform support is plausible but unvalidated.

## ADVANCED — deferred research, not shipping commitments

### F16 — One certified MCP mutation adapter

- **Job:** An AI tool says success despite a failed/partial operation, or reconnects with broader authority.
- **Minimum:** One named mutation tool mapped to one reviewed database operation and task ledger; server-side identity binding; committed/denied/unknown result vocabulary. “Certified” means a published versioned test envelope, not third-party accreditation.
- **Boundary:** Agent lacks raw database credentials and alternate mutation tools. MCP transport/auth is reused, not reinvented. Native only if underlying contract needs it; adapter alone cannot police other paths.
- **Test/size:** E15 plus all ledger tests: forged success, dropped reply, extra SQL tool, token/task mismatch. High/Very high/High; **15–30 days after dependencies**, F10–F15.
- **Alternative/kill/payer:** Existing Bytebase/governed narrow tool may suffice. Reject universal gateway ambitions. OSS adapter contract; maintained version certification/OEM integration could be paid later.

### F17 — Causal workflow-loop allowance

- **Job:** Fresh webhook executions and child workers renew authority while repeatedly modifying the same records.
- **Minimum:** Trusted root task plus delegated child allocation; children cannot mint new root allowance; commits debit common authority. One workflow family, one DB, bounded fan-out.
- **Boundary:** Every scoped producer/consumer must propagate authenticated lineage. Unknown external webhook ancestry is not magically recoverable. Native DB alone cannot identify causal origin; trusted application adapter required.
- **Test/size:** E16: fork/replay/drop lineage and feedback loop; absent lineage denies in-scope mutation. Very high/Very high/Very high; **25–60 days**, depends F11–F16.
- **Alternative/kill/payer:** Origin tagging/filtering and correct idempotency may eliminate the loop, as the public n8n case reports. Strong default is defer; OSS experiment, possible automation operator payer only after unresolved real cases.

### F18 — Managed-deployment contract comparison

- **Job:** A technically correct native mechanism cannot be installed by its intended operator.
- **Minimum:** Compare unchanged native/self-managed path with SQL-only restricted-procedure path on a provider-like restricted local role. Publish lost properties explicitly; later verify **one** authorized provider, not a matrix of clouds.
- **Boundary:** Local emulation does not establish managed support. Provider packaging, pooling, extensions and upgrades are external dependencies. SQL adapter needs exclusive grants; native path requires provider/operator cooperation.
- **Test/size:** E17: unavailable preload, stripped privilege, pooling and alternate connection. High/Very high/Very high; **10–25 days local research; provider work unestimable**, depends F06/F12.
- **Alternative/kill/payer:** SQL procedure may remove the need for native productization. Reject deployment claims without exact-version reproduction. Open compatibility evidence; paid maintained builds/support only where adoption justifies it.

## Ideas deliberately not promoted

**INFERRED:** A new portal, generalized importer, arbitrary SQL proxy, ERP/PIM, multi-engine runtime, policy language, cryptographic receipt service and universal AI safety score add burden before proof. Signing a false receipt only authenticates its signer; add signatures only after a real cross-trust-boundary verifier exists. Multiple table budgets and a basic doctor are implemented, not fresh roadmap achievements. Generic migration approval and tenant isolation have strong incumbents.

## Skeptical review applied to every candidate

| Candidate group | Simpler controls (procedure, least privilege/RLS, MCP, branching, idempotency) | Identity/alternate-path and transactional challenge | Recovery / evidence / hosting decision |
|---|---|---|---|
| F01–F05 | Compare controls rather than replace them; branch/disposable lab is mandatory. | Report reset/split/retry/counterexample outcomes, never pretend to prevent them. No ID issuance. | Missing oracle => UNKNOWN; no production recovery interference; local-only installation. |
| F06 | Prefer reviewed existing SQL. RLS restricts objects; idempotency does not bound distinct in-TX events. | New transaction resets by design; alternate owners excluded. Savepoint/caught-denial must still abort; independent workers have independent allowances. | Entire transaction can abort legitimate sibling work: document it. Native hosting limitation explicit. |
| F07–F09 | RLS/procedure/constraint usually wins; retain only a precise compositional benefit. | Below-budget forbidden row/state still denies; compensation semantics explicit; no task IDs or shared worker budget claimed. | A recovery operation may need separate trusted scope. Synthetic domain checks do not establish business truth. |
| F10 | Transactional outbox/result table is baseline; logs alone lose. | Atomic marker, payload binding and concurrent observation required; signed text cannot repair missing commit atomicity. | Observer unavailable => UNKNOWN; receipt is observability, no prevention. SQL-only possible. |
| F11–F15 | First implement the narrow SQL comparison, not a platform; correct dedup is the retry baseline. | Deny self-mint/reset, restrict alternate connections, test rollback/no refill, lock overspend and false success. Each semantics change needs E09–E13. | Define admission vs commit revocation, lock timeout and separately issued recovery budget. Never replenish through compensation. Managed native support unproven. |
| F16–F18 | Existing gateways/origin filters/provider-approved SQL may make them unnecessary. | Missing trusted lineage or alternate credentials defeats coverage. All dependencies' negative tests remain mandatory; session count is not shared committed effects. | No real-service certification from a lab. Deadlock/expiry must not strand recovery permanently. Provider/adapter upgrades reopen evidence. |

Each group's shared checks apply in addition to individual card tests. A feature is rejected if its minimum useful property cannot survive the applicable negative control, or if an incumbent supplies that property more simply under the same trust assumptions.
