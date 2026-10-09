# Issue #205 — Conflict to fresh Preview

Implementation base: live `main` `dc151e2e554e3fcb894041c2e8ca7de852268811` (#223 merge), fetched 2026-10-09. Dedicated branch: `work/205-conflict-fresh-preview`.

Merchants open **Re-preview conflicted products** on Apply results, choose explicit products (none selected by default), edit the original operation/safety suggestions, and submit the existing Preview path. Approval and Apply are unchanged. Recovery uses no JavaScript and the action sits outside poll-managed cells. Refresh saved progress discovers conflicts created after the page was opened.

Source reads require an authenticated actor, both mutation capabilities and creator/approver/admin job authorization before plan or outcome disclosure. Only durable Apply `CONFLICT` rows bound to a changing source-plan item are admitted. Applied siblings, uncertain outcomes and Undo-only conflicts are excluded. Current per-product rights are checked again; missing products remain explainable unsupported targets. Source membership is reread after planning. The actual planner rereads current snapshots, validates eligibility/rights/policies and freezes selected IDs; any variable-parent expansion outside the chosen population is refused. Limits remain 1,000 targets, with 1,001 refused.

The new immutable plan has a fresh plan ID, new job and optional validated `source_job` public reference in its hash-covered material. Old plans retain their original shape/hash. No schema change, calculator, mutation engine or new operation was added. Percentage recovery uses the current price: 120 + 10% = 132. Later edits still fail normal Apply preconditions. Original plans, job items, job completion records and journal remain untouched by recovery/new work.

Focused tests:

- `php wordpress/tests/admin/recovery-unit.php`: PASS, 2,618 cumulative assertions (2,607 existing + 11 provenance controls).
- `php wordpress/tests/free/sale-price.php`: PASS, 105 assertions.
- `php wordpress/tests/free/variations.php`: PASS, 75 assertions.
- `php wordpress/tests/admin/progress-unit.php`: PASS, 153 checks.
- `node wordpress/tests/admin/progress-client.cjs`: PASS, 69,481 checks across 9,996 cases.
- Admin/no-replan audits: PASS.
- Added `recovery-integration.php` to existing MySQL/MariaDB × default/Redis Admin runner: runtime pending.
- Added actual HTTP Chromium recovery journey (JS/no-JS) to each existing Admin browser profile: runtime pending. Captures/results use `205-*`, separate from canonical #170.

The #170 source fingerprint is refreshed using its existing documented historical-capture/current-CI contract. Historical screenshots and observer results are preserved; candidate #170 browser execution and uploaded captures must pass before acceptance. Local Docker is stopped and sudo requires a password; rootless Docker lacks `newuidmap`. Local runtime checks are therefore blocked pending infrastructure. Hosted plan/journal/jobs/Undo/Admin and CI_COVERAGE remain mandatory.

Acceptance is provisional pending runtime evidence. No merge, deployment, publication, issue closure or Ready-for-Review transition is authorized or performed.

First hosted run `37876605197`: PR_FAST, plan and journal PASS. The new
recovery browser detected HTTP 500 from an incorrect original-operation key
(`amount` instead of persisted `input`); the mapping is corrected without
changing the browser assertion. Remaining runtime acceptance is pending.
