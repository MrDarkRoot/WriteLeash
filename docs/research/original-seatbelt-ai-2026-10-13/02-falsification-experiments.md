# WriteLeash — effect-authority falsification program

Research date: 2026-10-13. **Design-only research plan; no experiments executed by this memo.** It is not an implementation issue, release gate, supported-product claim or authorization to use production credentials. Compare original PostgreSQL thesis to current agent-governance incumbents before funding any new engine.

## Target claim to falsify

For one authorized flexible task, a trusted issuer grants a finite amount of effect authority, and the writer cannot exceed it by splitting SQL statements, commits, sessions, retries, parallel workers or causal descendants. Only **declared, instrumented, committed effects** count toward a proved durable budget; undefined external consequences remain explicitly outside scope.

The original local PG16.4 substrate does NOT satisfy this claim: its budget resets at each new top-level transaction. It provides a valuable reference control only.

## Exact baseline and comparators

- **Baseline 0:** narrow typed API/stored procedure; no arbitrary SQL. Expected to win when the permitted job is static.
- **Baseline 1:** PostgreSQL privileges + RLS (scope), transaction management and traditional rate limits.
- **Baseline 2:** pg-safeupdate (predicate presence only).
- **Baseline 3:** the repository's current PG16.4 transaction-local budget (reference for tested fixture; do not pretend task-wide).
- **Baseline 4:** AWS AgentCore session temporal count/sum on calls (if a lawful test environment is available; documentary analysis otherwise).
- **Baseline 5:** Bytebase database MCP review/approval and Neon isolated branching (documentation comparison unless legitimately provisioned).
- **Candidate:** *only if Founder separately authorizes implementation of disposable research code*, issuer-controlled cross-transaction consumable effect ledger, with a restricted writer.

No paid services or production databases required for the first comparative desk/fixture plan. Do not mark a vendor runtime PASS from documentation.

## Synthetic task / negative-control matrix

All example numbers below are deliberately invented test parameters; they are not merchant requirements or credible WTP estimates.

| Test | Legitimate job | Failure injection | Oracle / falsification |
| --- | --- | --- | --- |
| A1: single broad UPDATE | Change 3 chosen rows | UPDATE 100 rows, with WHERE 1=1 | Exact committed target row set and denial; predicate-only guard may allow |
| A2: split statements | 3 total update events | 5 UPDATE statements of 1 row each | Aggregate effects across statements, not SQL text |
| A3: split transactions | 3 update events whole task | Three commits of 2 row events each | Existing V0 permits repeated per-TX allowances: claim fails unless one trusted task balance spans all commits |
| A4: retry after acknowledgement loss | Same intended change once | Commit succeeds but caller times out and retries | Safe idempotency / no second charge effect or conservative uncertain state; never fabricate APPLIED |
| A5: same-object amplification | Touch at most 3 products | Update same 1 product 1,000 times | Unique-object bound is inadequate for row-event explosion |
| A6: two concurrent writers | Total budget 3 events | Each worker attempts 3 in parallel | At most 3 successful committed events; verify fresh DB state; no ledger overspend |
| A7: budget laundering | Spend 3 events | Restore old values and spend 3 again | Compensating writes do not replenish consumed authority |
| A8: semantic one-row failure | Change 1 normal role | One row becomes admin | Role transition policy denies; row-count policy alone fails |
| A9: quantitative delta | Raise aggregate declared amount by at most 100 units | Split +70 and +60 across two commits | Task positive-delta >100 refuses; no claim about external payment effects |
| A10: self-issued identity | Agent has one lease | Agent invents another task/session ID | Trusted issuer rejects; if arbitrary fresh ID accepted, research claim fails |
| A11: alternate write route | One certified route | Writer uses owner, SECURITY DEFINER, raw pool or unmediated tool | Mark OUTSIDE_COVERAGE/FAIL; never make a universal guarantee |
| A12: feedback descendants | One trusted sync task | Its webhook causes repeated new executions | Origin filtering should be the first comparator; proposed causal budget fails if descendant identity cannot be trusted |
| A13: crash between ledger and data effect | 3 total events | Kill worker before/after DB commit and before response | Durable state and budget agree or remain explicitly uncertain; avoid lost-charge or false-charge success |
| A14: isolation-first | Agent needs to test migration | Agent writes only to disposable Neon-style/local PG branch | If branch isolation fully removes production write risk, reject deployment of live seatbelt for this task |
| A15: performance and deadlock | Normal bounded concurrent repair | Hot ledger row or trigger contention | Measure p50/p95/p99, throughput, deadlocks, denial cost; previous repo benchmarks were inconclusive |

## What constitutes evidence without a merchant

1. **OBSERVED:** exact source commit, pinned database image digest, code/config, policy, commands, exit status, traces and independent fresh-connection durable row oracle; negative control must fail when guard is disabled.
2. **REPRODUCED EXTERNALLY:** an unrelated technical operator runs the same public fixture with complete environment and test receipts. This supports technical reproducibility, not sales.
3. **COMPARATIVE:** incumbents tested under the same job and privilege scope, with no unsupported 'missing feature' claims.
4. **INFERRED:** a residual boundary survives simplest incumbent control. This is still not demand.
5. **NOT VERIFIED:** buyer, integration appetite, performance in managed hosts, operator support, price, renewal, compliance, production behavior.

## Minimal next action — research, not Pro implementation

- First publish a **read-only competitor/fault matrix** and identify 3 concrete test cases from PG16.4 where current transaction-local V0 accepts a multi-commit overrun.
- Inspect the existing fixture's output and ensure the proposed "task-wide" oracle fails under unmodified V0; record source/commit and exact expected versus observed durable state.
- Compare that failure with narrowly typed procedure and least-privilege designs. A simple procedure that solves the intended job is a **successful falsification** of needing a generic budget.
- Write a decision memo: is a trusted issuer + ledger materially necessary, and can it be proven with less than an entire control plane?
- Only after Founder approval consider an **isolated technical spike** in disposable PostgreSQL 16.4. No production, managed SaaS, public security claims or Pro license development.
- A different independent experiment may test webhook loop causality via replayable synthetic events; do not presume transitive identity can be propagated through arbitrary vendors.

## Stop conditions

Stop or narrow if: (i) no meaningful effect claim survives narrow API, branch isolation, tool policy, idempotency and existing SQL review; (ii) writer can mint budget authority; (iii) double-commit and crash paths create false success/overspend; (iv) incremental enforcement needs an unsupported database privilege/topology for the chosen target; (v) performance/deadlocks are unacceptable; (vi) the only demonstrated faults already have simpler fixes; or (vii) differentiated mechanism exists but no adopters appear after OSS public verification.

Any paid ambition is a **separate** subsequent decision. Existing Woo Free safety and #240 publication process must remain untouched. #214 remains blocked under its actual Woo commercial validation rules.

Sources: https://github.com/MrDarkRoot/WriteLeash/blob/main/docs/support-matrix.md ; https://github.com/MrDarkRoot/WriteLeash/blob/main/docs/product.md ; https://docs.aws.amazon.com/bedrock-agentcore/latest/devguide/policy-temporal.html ; https://www.bytebase.com/database-mcp-server/ ; https://github.com/eradman/pg-safeupdate ; https://community.n8n.io/t/woocommerce-product-updated-webhook-continuously-firing/295221 ; https://docs.temporal.io/encyclopedia/retry-policies ; https://neon.com/docs/get-started-with-neon/workflow-primer
