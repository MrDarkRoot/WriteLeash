Refs #209 — **draft for code review only. Do not merge; founder GO/NO-GO pending.**

Base: `c48e307efe7750d0fb36015c39d50a34675f9579` · Round-2 head: `7496874eb0b48989814234afb703d17a5563ace0` · Round-3 head: `029a08040da0fab06bfea2a8d174aeba3d72f2d4` (docs/tests only)

## P0 review finding addressed — recovery semantics

The reviewed implementation classified every non-core resolved class as `unreadable_product_data`, turning the previously **terminal** unsupported outcome into a **retryable (PENDING)** one. This fix restores the established terminal refusal and keeps transient read failures typed and conservatively retryable.

Behavioral changes:
- `Product_Price_Snapshot::fresh_product()`
  - A resolved string that is a `WC_Product` subclass outside the three exact core classes (`WC_Product_Simple`, `WC_Product_Variation`, `WC_Product_Variable`) → `Price_Validation_Error('unsupported_product_type')` (stable unsupported, terminal), **never instantiated**.
  - A resolved string that is not a `WC_Product` at all (e.g. `stdClass`) is a broken mapping, not a product type → `unreadable_product_data` (satisfies the required `stdClass → unreadable_product_data` case).
  - `unreadable_product_data` also covers missing WP/Woo functions/classes, suspended invalidation, non-string classname, constructor failure (the literal core constructors are now wrapped in try/catch), and constructor result mismatch.
  - No `new $var`; only the three literal core constructors (unchanged public-runtime audit).
- Selector: stable `unsupported_product_type` → new `$unsupported_type` collection (top-level and `expand_variable_parents` children); everything else → `$unreadable`. Unsupported IDs are included in the final `MAX_SELECTED` count and final frozen ID set, and become `Product_Price_Snapshot::unsupported_type()` snapshots (`exists=true`, `unreadable=false`, no core identity/prices) so `Product_Price_Eligibility` yields the established `unsupported_product_type` reason. Unsupported children never drop silently from the frozen population.
- Execution boundaries (baseline outcomes verified against `c48e307`):
  - Apply pre-check: `unsupported_product_type` → `Price_Apply_Error('UNSUPPORTED_PRODUCT_STATE')` → durable `FAILED` (terminal) with zero write; any other `Price_Validation_Error` is rethrown → `FAILED` → `PENDING` (retryable).
  - Undo entry pre-check: same conversion.
  - Undo recovery/verification read: `unsupported_product_type` → `CACHE_VERIFICATION_FAILED`, matching the pre-change exact-class comparison (NEEDS_REVIEW).
  - `Price_Cache_Verifier::sync_variable_parent()`: additional restoration — a stable unsupported parent maps to `CONFLICT`, the pre-change exact-class outcome, so a concurrent parent type change can no longer demote CONFLICT to retryable. Transient read failures still rethrow.
  - `observe()` / `observe_variable_parent()` continue to convert non-`Price_Apply_Error` to `CACHE_VERIFICATION_FAILED` at their callers; `product_observation` catch-all → `Unavailable`; `variation_facts()` parent read catch → `null` — unchanged.
- No change to state-mapping arrays, conflict handling, verification strength, write paths, or resume/history semantics. CONFLICT and NEEDS_REVIEW are never reset; APPLIED is never inferred from target equality.

## Files changed
- `wordpress/writeleash/includes/free/class-product-snapshot.php` — classification + `unsupported_type()`.
- `wordpress/writeleash/includes/free/class-product-selector.php` — unsupported collection, IDs and count.
- `wordpress/writeleash/includes/free/class-woo-price-mutator.php` — Apply mapping.
- `wordpress/writeleash/includes/free/class-woo-undo-mutator.php` — entry + recovery mapping.
- `wordpress/writeleash/includes/free/class-price-cache-verifier.php` — parent-sync CONFLICT restoration.
- `wordpress/tests/free/cache-public.php` — extension → `unsupported_product_type`; extension never constructed; `stdClass`/non-product and core constructor-result mismatch → `unreadable_product_data`; suspended/absent unchanged.
- `wordpress/tests/free/variations.php` — extension parent eligibility restored to `unsupported_product_type`; unsupported snapshot `exists=true/unreadable=false`; SKU admission filter now by eligibility with the byte-match assertion unchanged.
- `wordpress/tests/durable/cache-public-integration.php` — extension class mapping at Apply → terminal `UNSUPPORTED_PRODUCT_STATE`/durable FAILED with no write; non-product classname → retryable FAILED/durable PENDING; public-API-only fixture style.

## Test evidence
- Local PASS: `cache-public` (73), `unit` (2,607), `sale-price` (105), `variations` (75), `admin-audit`, `no-replan-audit`, `package-preflight`, `inventory-audit`, `public-audit-cases`, `readme-validate`, `historical-shim-cases`, `claim-matrix`, `source-audit`, `ownership --audit`, `dependency-audit`, `test-artifact`, PHP/JS/shell lint, `git diff --check` clean.
- GitNexus: pre-edit upstream impacts were run for every edited production symbol (`fresh_product` CRITICAL/11 direct callers, `resolve` LOW, `expand_variable_parents` LOW, `apply` HIGH lower-bound — 1 production caller `attempt_item` plus tests confirmed by text search, `restore` HIGH, `observe_undo` HIGH, `sync_variable_parent` CRITICAL/2) before editing; `detect-changes --scope all` and `--scope staged` complete (8 files / 22 symbols / 35 flows, risk critical, no partial/truncated), post-commit clean.
- Durable additions are wired into the existing Docker harness; execution **NOT_TESTED** (Docker daemon unavailable). Pure mutator-mapping coverage is durable/Docker-only — no pure harness can load the mutator without Woo/wpdb.

## Remaining blockers
- `pr-fast.sh` fails only at the unchanged **#170 UI evidence gate** (`asset-audit.php`): the UI source differs from the reviewed capture because the prior implementation commit touched `class-free-admin.php`. No evidence/assertion/fingerprint was edited. `asset-audit-cases.php` fails identically at the pre-existing head (same gate).
- Docker runtime durable Woo/MySQL/MariaDB/Redis/browser execution **NOT_TESTED**.

## Support-claim impact
None. `WOOCOMMERCE_MIN 10.0.0` / `WOOCOMMERCE_MAX_EXCLUSIVE 12.0.0` unchanged; no version-conditional branch added.

## Deviations requiring founder approval
None — the prior deviation (unsupported → unreadable / terminal → retryable) is resolved. Implementation notes, not approval-requiring: (1) non-`WC_Product` resolved strings stay `unreadable_product_data` while `WC_Product` subclasses are terminal, which is how `stdClass → unreadable` and `extension → unsupported` are both satisfied; (2) `sync_variable_parent()` is an additional restoration site beyond the listed ones, required to keep the pre-change CONFLICT outcome.

## Integration / Problem B
No shipped `Internal` reference including escaped strings, no `new $`, no `wc_get_container`, no global cache flush; targeted invalidation only. Test-only residual: `wordpress/tests/admin/integration.php:729` still primes Woo's private cache to stage stale state (not shipped; deliberately untouched).



## Round-3 recovery-semantics evidence (`029a080`)

Round-3 is verification-only: **no production code changed**. New commit `029a08040da0fab06bfea2a8d174aeba3d72f2d4` adds the recovery-semantics matrix and durable fixtures; branch is a fast-forward over `7496874e…`.

### End-to-end traces and the required proofs
- Full matrix (reason → durable journal/item state → retryable? → what guards re-execution) plus all seven required proofs with branch-HEAD `file:line` citations: `docs/review/209/IMPLEMENTATION.md`, section “Round-3 recovery-semantics evidence”.
- Proof 1 (terminal unsupported): round-2 durable case confirmed at `tests/durable/cache-public-integration.php:126-137` (`WC_Product_External` mapped for the Apply read → `UNSUPPORTED_PRODUCT_STATE`/durable `FAILED`/zero write); planning-level terminal refusal cited at `class-product-selector.php:83-87,127-129`.
- Proof 2 (transient refusal ≠ safe-to-retry write): every retry is a fresh `apply()` whose first actions are the journal lock + `PENDING` gate, storage row locks, targeted invalidation, frozen `precondition` MATCH, expected-price and sale/regular coherence, and re-authorization — the Woo save is last and shares the transaction (`class-woo-price-mutator.php:76-150`). New durable regressions: `cache-public-integration.php:149-186` (transient refusal → zero saves + durable `PENDING`; retry → one save; a concurrent edit between attempts → `CONFLICT`, zero overwrite) and `tests/jobs/integration.php:516-558` (job-level `PENDING`/`FAILED`/`attempt_count=1`/`RETRY_BACKOFF`; retry re-runs the precondition and preserves the newer edit).
- Proof 3 (no uncertain write repeated): `$committed+FAILED ⇒ AMBIGUOUS_COMMIT` (`:174`); failed rollback ⇒ `TRANSACTION_LOST` (`:175-177`); both ⇒ `NEEDS_REVIEW` (`:179-194`), which is terminal at item level (`class-job-state.php:90`) and never re-claimed (`claim_next_item` PENDING-only).
- Proof 4 (durable state gates retry): `APPLIED` ⇒ `ALREADY_APPLIED`, no second save (`class-woo-price-mutator.php:80-85`); job-row transactional fence + journal row lock + lease-generation CAS fence duplicate Resume. New job fixture: killed worker between checkpoints (`AFTER_WOO_SAVE_BEFORE_JOURNAL`, `AFTER_JOURNAL_BEFORE_COMMIT`) leaves durable `PENDING`/original price; recovery reconciles and applies once (`tests/jobs/integration.php:485-514`).
- Proof 5 (no blind overwrite): precondition + stored truth + coherence before save; newer edits win as `CONFLICT` terminal with zero write.
- Proof 6 (Undo conflict-aware): `UNDO_CONFLICT` terminal, `UNDO_NEEDS_REVIEW` for uncertainty, later edits/sale configuration preserved; interrupted Undo already covered at `tests/undo/integration.php:385-441`.
- Proof 7 (no uncertain→APPLIED/UNDONE): success states are written only by the two in-transaction transitions (`class-woo-price-mutator.php:164`, `class-woo-undo-mutator.php:228`); target-equality without journal evidence is `CONFLICT` (`durable/integration.php:232-235`).

### Test deltas and executed checks
- `tests/durable/cache-public-integration.php` +38 assertions; `tests/jobs/integration.php` + interrupted-before-COMMIT recovery loop and transient-retry blocks; `tests/jobs/worker.php` + test-only public `woocommerce_product_class` fault knob. `php -l` clean; `git diff --check` clean.
- Battery PASS on the round-3 tree: `cache-public` 73, `unit` 2607, `sale-price` 105, `variations` 75, admin-audit, no-replan-audit, ownership, dependency-audit, package-preflight, inventory-audit, public-audit, readme, historical-shim, claim-matrix, source-audit.
- GitNexus (clone-local index, runner identity verified, status fresh at `029a080`): `detect-changes --scope all` and `--scope staged` complete (4 files / 10 symbols / 0 processes / risk low), post-commit clean.

### Remaining divergence + founder approval
The transient `unreadable_product_data` → `FAILED` → durable `PENDING` retry **remains a divergence** from the pre-#209 terminal baseline. Round-3 proves it is not unsafe re-execution (rollback-before-retry, full precondition re-run, journal-gated `PENDING`, 3-attempt budget) but it is **not approved**: explicit founder sign-off is still required. No production change was made to remove it.

### Blockers / NOT_TESTED
- `bash .github/ci/pr-fast.sh` fails only at the unchanged **#170 UI source/capture fingerprint** (21 PASS, then `asset-audit.php:6`), exactly as before.
- Docker daemon unavailable (`docker info` exit 1, no `/var/run/docker.sock`): all new durable fixtures and the Docker battery (`durable/`, `jobs/`, `undo/`) are **NOT_TESTED**, not PASS. Receipts: `evidence/pr-fix3/220/`.
