# Issue #212 — Stage 1 Execution Checklist

Prepared in parallel with the in-progress #211 candidate work. This checklist is test-only. It does not build, edit, or substitute the candidate. Execute only after #211 publishes a frozen candidate identity and the access/environment prerequisites are available.

## 0. Freeze and bind the artifact

- [ ] Record the candidate source commit SHA, version, exact ZIP path, builder revision, public inventory, and installation evidence supplied by #211.
- [ ] Independently hash the exact received file: `sha256sum /absolute/path/to/writeleash-candidate.zip`.
- [ ] Match the result exactly to #211's ZIP SHA-256; stop if it differs or is absent.
- [ ] Record the hash and file byte size before any installation. Keep the original candidate read-only and copy it only for installation.
- [ ] Verify ZIP path closure and installed-tree byte equivalence using the candidate's published builder/audit receipts; do not rebuild or mutate the candidate during testing.
- [ ] Every QIT run, extension run, failure record, and screenshot/log must include the same candidate SHA-256.

**Candidate identity ledger**

| Source SHA | Version | ZIP path | ZIP SHA-256 | ZIP bytes | Builder revision | Inventory/evidence path |
|---|---|---|---|---:|---|---|
| | | | | | | |

## 1. Environment and access ledger

Record actual values from the test environment, never defaults inferred from repository config.

| Field | Recorded value / evidence |
|---|---|
| Execution timestamp and operator | |
| QIT CLI version / authenticated account context (no token) | |
| WordPress version | |
| WooCommerce version | |
| PHP version and SAPI | |
| Database engine/version | |
| Object cache / other installed plugins | |
| HPOS enabled or disabled; evidence from Woo setting | |
| Legacy order storage mode and evidence | |
| Candidate SHA-256 installed | |
| Candidate install/activation outcome and logs | |
| Extension package source/version/license permission reference (no license key) | |

Use the currently applicable two latest major WordPress and WooCommerce releases from the official submission requirements. Record the actual version chosen for each run. Add the relevant previous-release configuration required by the current official window; avoid a Cartesian matrix. At minimum, use an HPOS-enabled configuration and, where supported by the same environment, a separate legacy-storage configuration. WriteLeash declares HPOS compatibility; do not infer order-management functionality from that declaration.

## 2. Official QIT suite ledger

Use current official managed-test names and documented QIT invocation syntax from the authenticated QIT environment. Do not treat repository tests, Plugin Check, or manual checks as QIT results. Preserve each run's official ID and result details.

| Category/name from current official docs | Status (PASS/FAIL/BLOCKED/NOT_TESTED) | Run ID | Candidate SHA-256 | WP/Woo/PHP | Logs/results path | Finding / access prerequisite |
|---|---|---|---|---|---|---|
| Validation | | | | | | |
| Activation | | | | | | |
| Security | | | | | | |
| PHPCompatibility | | | | | | |
| Malware | | | | | | |
| API | | | | | | |
| E2E | | | | | | |

For a missing account, permission, CLI, or fixture, mark that row **BLOCKED**, state the exact prerequisite, and do not solicit credentials in public issue/PR comments. Mark **NOT_TESTED** only when the suite is available but intentionally not executed, with reason.

## 3. Per-extension scenario worksheet

Repeat the following worksheet for each permitted extension and record its exact installed version. Keep each extension run isolated and reproducible. A missing commercial package/license is BLOCKED, never a compatibility pass.

Extensions: WooCommerce Subscriptions; WooCommerce Bookings; Product Add-Ons; Product Bundles; Min/Max Quantities; Shipment Tracking.

### Identity and activation

| Extension/version | Environment ID | Candidate SHA-256 | Installed/activated | Fatal errors/notices | Logs |
|---|---|---|---|---|---|
| | | | | | |

### Product and price workflow

For each applicable simple product and supported variation, record product/parent/variation IDs; Regular Price and Sale Price before operation; operation and Preview identifier; previewed target IDs; values and Woo lookup observations after Apply; and values after eligible Undo. Do not assert behavior for a custom product type that the candidate does not claim to support.

| Case ID | Type / product ID(s) | Before regular/sale | Preview operation/result | After Apply prices + lookup | Undo eligibility/result + restored prices | Logs/evidence |
|---|---|---|---|---|---|---|
| SIMPLE-01 | Simple | | | | | |
| VAR-PARENT-01 | Variable parent + supported variations | | | | | |

### Stale Preview / external edit conflict

1. Create the Preview and retain its identifier and displayed expected prices.
2. Change a target product/variation price externally using WooCommerce's normal product editor/CRUD path.
3. Apply the old Preview and record the outcome. Confirm whether the external price is preserved; capture IDs and before/external/observed values.
4. Record recovery/re-preview behavior if offered. Do not overwrite the external edit to make the test pass.

| Case ID | Product ID | Preview price | External price | Apply outcome/current value | Recovery/re-preview outcome | Logs |
|---|---|---|---|---|---|---|
| CONFLICT-01 | | | | | | |

### Extension metadata and unsupported custom product types

Before each Apply/Undo scenario, snapshot the extension-relevant metadata; repeat after each operation and compare canonical key/value state. Record unrelated metadata differences rather than dismissing them. For unsupported custom types, record the product type/ID, selection/Preview response, mutation count, and before/after prices/metadata; expected result is clear exclusion/refusal with no mutation.

| Extension invariant | Snapshot method / keys | Before evidence | After Apply evidence | After Undo evidence | Comparison result |
|---|---|---|---|---|---|
| Subscriptions: recurring price/subscription metadata | | | | | |
| Bookings: costs, availability, resources | | | | | |
| Product Add-Ons: configuration | | | | | |
| Product Bundles: membership, pricing mode, child/parent state | | | | | |
| Min/Max Quantities: quantity restrictions | | | | | |
| Shipment Tracking: order/shipment metadata | | | | | |

| Custom type / product ID | Excluded/refused clearly | Price and metadata before | Price and metadata after | No-mutation proof |
|---|---|---|---|---|
| | | | | |

## 4. Failure capture template

Create one record per failure; retain original logs. Redact credentials, cookies, license values, and customer data before sharing evidence.

```text
Test ID:
Extension/QIT suite:
Candidate ZIP SHA-256:
Environment ID and WordPress/Woo/PHP/database/HPOS versions:
Reproduction steps:
Expected behavior:
Observed behavior:
Product/parent/variation IDs:
Regular/Sale prices before, external edit (if any), and after:
Woo CRUD and lookup observations:
Extension metadata before/after:
Official QIT run ID or local log path:
First attempt outcome:
Reproduction attempt outcome:
Evidence and redaction notes:
Unresolved questions (avoid speculative diagnosis):
```

## 5. Local prerequisite snapshot (pre-candidate)

Observed 2026-10-09 in the current execution workspace: PHP CLI 8.2.32 and WP-CLI 2.12.0 are installed; no WordPress/WooCommerce installation is established; `qit` is not installed; Docker Compose CLI is 5.1.4 but the Docker daemon is inactive. Starting Docker via non-interactive sudo was denied because a password is required. QIT/vendor authentication and six permitted commercial extension packages/licenses were not available. These facts can change; recheck immediately before execution.

**Do not begin final acceptance until Section 0 passes.** Once it does, use a fresh isolated environment, capture versions and HPOS mode, execute the official QIT suites, then the six extension worksheets, and update `STAGE1-EVIDENCE.md` with linked evidence paths and outcomes.
