# Issue #209 implementation handoff — Remove WooCommerce Internal cache dependencies

Scope: remove every shipped dependency on Woo `Internal` cache code (including string-held class names) from the execution/verification, selection, snapshot, observation and one-line Apply/Undo read paths, replacing them with supported public WordPress/Woo mechanisms while preserving freshness, writes, locks, fences, transactions and verification. Local preparation and local validation only; founder retains GO / NO-GO. No push, PR, merge, deployment, publication, issue update or RELEASE_FULL dispatch.

| Field | Evidence |
|---|---|
| Issue | #209 — Remove WooCommerce Internal cache dependencies |
| Base SHA | `c48e307efe7750d0fb36015c39d50a34675f9579` |
| Branch | `work/209-public-cache` in `/tmp/writeleash-approved-20261008/agent-B` |
| Implementation head | `56847070d90c84e180cf81cf60eb7a659fd5277a` (`fix(cache): remove Woo Internal cache dependencies from runtime paths (#209)`) |
| Docs commit | `docs(review): record #209 validation receipts` (this file; second commit on the same branch) |
| Implementation patch | `evidence/agent-B/B2-implementation.patch` (`git diff c48e307..HEAD --binary` at the implementation commit), SHA-256 `3af490ae1f7fd9013099a4cfc008097f8e8518b7ecea1fa6a96cf907f52e5bca` |
| Final full-tree patch | `evidence/agent-B/B2-final.patch` (`git diff c48e307..HEAD --binary` after the docs commit), SHA-256 recorded in `B2-final.sha256` |
| PR status | Not created; read-only `gh` only; no push/PR/merge/issue update |
| Scope deviation | Extension/non-core resolved classes are refused as `unreadable_product_data` instead of `unsupported_product_type` at the selector/planner boundary (and as `FAILED`/PENDING rather than `UNSUPPORTED_PRODUCT_STATE`/FAILED at the Apply/Undo boundary). No extension class can become eligible. Documented below. |
| Support claims | Unchanged (`WOOCOMMERCE_MIN 10.0.0`, `WOOCOMMERCE_MAX_EXCLUSIVE 12.0.0`); no version-specific guard needed because no version-conditional cache class is referenced. |
| Acceptance verdict | **PASS (local, executed checks)**; actual Woo/Redis/browser runtime **NOT_TESTED** (Docker unavailable) |

## Files changed

- `wordpress/writeleash/includes/free/class-product-snapshot.php` — new `Product_Price_Snapshot::fresh_product` public read boundary (literal core constructors).
- `wordpress/writeleash/includes/free/class-price-cache-verifier.php` — removed string-held `ProductCache` lookup; `invalidate()` is now public WP/Woo cleanup only; parent/observation reads use `fresh_product`.
- `wordpress/writeleash/includes/free/class-product-selector.php` — resolve/parent/expansion reads use `fresh_product`.
- `wordpress/writeleash/includes/free/class-woo-price-mutator.php` — one-line Apply read substitution.
- `wordpress/writeleash/includes/free/class-woo-undo-mutator.php` — two one-line Undo read substitutions (restore + post-commit observation).
- `wordpress/writeleash/includes/free/class-free-admin.php` — `product_observation` uses `fresh_product`; private cache-container access removed.
- `wordpress/writeleash/includes/free/class-free-support-contract.php` — comment only: version rationale no longer names the Internal class.
- `wordpress/writeleash/FREE-PRICE-APPLY-CONTRACT.md` — comment/doc only: public factory + public cache-clean action wording.
- `wordpress/tests/free/cache-public.php` — new pure 72-assertion public-read contract.
- `wordpress/tests/free/variations.php` — stub factory/cache helpers; conservative refusal expectations.
- `wordpress/tests/free/dirty-catalog-stubs.php` — stub factory/cache helpers for constructor reads.
- `wordpress/tests/free/in-container.sh` — runs `cache-public.php` in the free suite.
- `wordpress/tests/durable/cache-public-integration.php` — new real-Woo simple/variation/default/Redis focused probe.
- `wordpress/tests/durable/integration.php` — private ProductCache set/get/remove replaced by a public factory + `woocommerce_product_read` probe.
- `wordpress/tests/durable/in-container.sh` — runs the #209 probe with the instance-cache feature explicitly enabled and restores the prior option value.
- `docs/review/209/IMPLEMENTATION.md` — this report (docs commit).

## Mechanism (verified against cached Woo sources)

Pinned ZIP hashes verified against the repository's expected values before source use: Woo `11.1.2` `9de9350a…8e9e`, Woo `11.0.1` `da189b66…fa21`, Woo `9.9.7` `96facedd…f4dd`, Redis Object Cache `2.7.0` `0cbc41de…c635`.

- `WC_Product_Factory::get_product_type()` (`includes/class-wc-product-factory.php:106`) returns the public type or `false`; `get_product_classname()` (`:79`) returns the filtered classname, falling back to `WC_Product_Simple` only when the filtered class does not exist. Both are public and identical in 9.9.7 / 11.0.1 / 11.1.2.
- `clean_post_cache()` deletes the post/post-meta caches and dispatches the public WordPress `clean_post_cache` action. In 11.0.1/11.1.2 `ProductCacheController::register_hooks()` attaches product-instance eviction to that action (and to meta hooks); the `product_objects` group is non-persistent. In 9.9.7 no product instance cache exists at all.
- `WC_Cache_Helper::invalidate_cache_group( 'product_' . $id )` remains the public targeted group invalidation and is applied before type resolution.
- `fresh_product()` therefore does: guard suspended invalidation / missing Woo helpers → `clean_post_cache( $id )` → `wp_cache_delete( $id, 'post_meta' )` → `WC_Cache_Helper::invalidate_cache_group( 'product_' . $id )` → public type lookup → **literal** `new \WC_Product_Simple( $id )` / `new \WC_Product_Variation( $id )` / `new \WC_Product_Variable( $id )` for the exact resolved classname → `instanceof \WC_Product` + `get_id() === $id` check. Any other resolved classname or non-string raises `Price_Validation_Error( 'unreadable_product_data' )`; a genuinely absent type returns `false`. No dynamic `new $class`, no `$class::`, no container access, no global flush, no SQL write. Direct construction bypasses Woo's factory instance cache entirely, so a primed stale factory object cannot be returned.

### Exact `fresh_product` body (implementation commit)

```php
public static function fresh_product( int $id ) {
	if ( $id < 1 ) { throw new Price_Validation_Error( 'invalid_product_id' ); }
	if ( ! function_exists( 'wc_get_product' ) || ! class_exists( '\WC_Product_Factory' ) || ! class_exists( '\WC_Cache_Helper' ) || ! empty( $GLOBALS['_wp_suspend_cache_invalidation'] ) ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
	clean_post_cache( $id );
	wp_cache_delete( $id, 'post_meta' );
	// Woo's public type lookup uses this product group too. Evict it before
	// resolving the class, so a concurrent type edit cannot reuse old type.
	\WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
	$type = \WC_Product_Factory::get_product_type( $id );
	if ( ! $type ) { return false; }
	$class = \WC_Product_Factory::get_product_classname( $id, $type );
	if ( ! is_string( $class ) ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
	if ( 'WC_Product_Simple' === $class ) { $product = new \WC_Product_Simple( $id ); }
	elseif ( 'WC_Product_Variation' === $class ) { $product = new \WC_Product_Variation( $id ); }
	elseif ( 'WC_Product_Variable' === $class ) { $product = new \WC_Product_Variable( $id ); }
	else { throw new Price_Validation_Error( 'unreadable_product_data' ); }
	if ( ! $product instanceof \WC_Product || $product->get_id() !== $id ) { throw new Price_Validation_Error( 'unreadable_product_data' ); }
	return $product;
}
```

## Acceptance criteria

`PASS` below is restricted to the executed local checks. It does **not** establish actual Woo/Redis/browser behavior.

| Live issue criterion | Contract evidence | Full acceptance |
|---|---|---|
| Shipped runtime has no dependency on Woo Internal classes/code, including string references | **PASS**: `grep -rn "ProductCache\|Automattic\\\\WooCommerce\\\\Internal\|Internal\\\\Caches" wordpress/writeleash/` empty (receipt `B2-internal-grep.log`); `grep 'new \$'` and `grep wc_get_container` empty; `public-runtime-audit` (dynamic class/static guard), package-preflight, source-audit, inventory-audit all PASS; pure contract scans every `includes/**.php` for the escaped namespace string | **PASS** (static/package) |
| After a concurrent supported edit, Preview/Apply/current observation/Undo read correct values and preserve relevant newer edits | **PASS (pure contract 72 assertions)** + real fixture prepared: cross-process edit leaves the primed factory instance stale (negative control), standalone Preview reads the concurrent 120 before any Admin read, Apply conflicts without overwriting, new Preview freezes 120, fresh Apply succeeds, Undo refuses a newer edit, Admin displays 90 | **NOT_TESTED**: real Woo runtime (Docker blocked) |
| Focused default and persistent-cache tests cover simple products, variations/parent ranges, rollback, recovery and current-price display using existing harnesses | **PASS (wiring/syntax)**: `durable/cache-public-integration.php` (130 lines, real Woo) covers all five; wired into `durable/in-container.sh` with the instance-cache option explicitly enabled and restored; `free/cache-public.php` wired into `free/in-container.sh` | **NOT_TESTED**: durable execution requires the Docker Woo/MySQL/Redis harness |
| Supported versions before/after the former ProductCache availability boundary behave correctly | **PASS (source-verified)**: 9.9.7 (no ProductCache; factory is direct construction), 11.0.1 and 11.1.2 (ProductCache opt-in, hook-wired) all expose the same public factory API; no shipped code branches on a version. `WOOCOMMERCE_MIN` unchanged at 10.0.0 | **NOT_TESTED**: per-version runtime matrix (9.9.7/11.0.1/11.1.2 containers) |
| Missing support/freshness fails visibly and conservatively; uncertain outcomes are not relabeled success | **PASS (contract)**: suspended invalidation → `unreadable_product_data` (Admin shows `Unavailable`; `invalidate()` refuses `CACHE_VERIFICATION_FAILED`); non-product/mismatched-ID/missing-helper/extension-class → typed refusal; absent type → `false` (missing), never mislabeled unreadable | **NOT_TESTED**: rendered Admin state and real Apply refusal codes |

## Executed validation (exact commands, final tree)

- `php wordpress/tests/free/cache-public.php` — **PASS**, 72 assertions. Includes negative control (primed factory stale), all three literal constructors, extension-class never constructed, `stdClass`/wrong-ID/false-classname refusals, absent-type `false`, suspended-invalidation no-partial-cleanup, and the whole-`includes` namespace inventory.
- `php wordpress/tests/free/unit.php` — **PASS**, 2,607 assertions.
- `php wordpress/tests/free/sale-price.php` — **PASS**, 105 assertions.
- `php wordpress/tests/free/variations.php` — **PASS**, 75 assertions (was 73; +2 explicit unreadable/refusal assertions).
- `php wordpress/tests/admin/admin-audit.php wordpress/writeleash` — **PASS**.
- `php wordpress/tests/jobs/no-replan-audit.php wordpress/writeleash` — **PASS**.
- `php wordpress/tests/release/package-preflight.php wordpress/writeleash wordpress/release/writeleash-distribution-files.txt` — **PASS** (before the fix this failed exactly with `#120 … dynamic class dependency in includes/free/class-product-snapshot.php`; the guard was not relaxed).
- `php wordpress/tests/release/public-audit-cases.php …`, `inventory-audit.php …`, `readme-validate.php wordpress/writeleash`, `historical-shim-cases.php wordpress/writeleash`, `claim-matrix-audit.php .`, `source-audit.php wordpress/writeleash` — all **PASS**.
- `python3 .github/ci/ownership.py --audit` — **PASS** (59 production PHP files, listing-only PR_FAST). `python3 .github/ci/dependency-audit.py` — **PASS**.
- PHP lint on all 15 changed/new files, `sh -n` on both changed shell files, `git diff --check` — **PASS**.
- `bash .github/ci/pr-fast.sh` — **FAIL** at the unchanged #170 UI source/capture fingerprint: first and only failure is `PHP Fatal error: Uncaught RuntimeException: #122 assets: #170 UI source differs from reviewed capture in .github/ci/asset-audit.php:6` (21 PASS lines precede it). This is the expected consequence of touching `class-free-admin.php`; evidence/assertions were not edited.
- GitNexus (clone-local index, runner identity verified): `status` fresh (355 files); pre-edit `impact fresh_product --direction upstream` = **CRITICAL**, 11 direct callers (`resolve`, `parent_of`, `expand_variable_parents`, `observe`, `observe_variable_parent`, `sync_variable_parent`, `apply`, `restore`, `observe_undo`, `product_observation`, `variation_facts`), 23 impacted, 31 flows — caller set confirmed by text search. `detect-changes --scope all` and `--scope staged` = **complete** (15 files, 72 symbols, 31 flows, risk critical; no partial/truncated/error markers) → `B2-detect-all.json`, `B2-detect-staged.json`; post-commit `--scope all` = clean → `B2-detect-postcommit.json`; `--scope compare --base-ref c48e307` = complete → `B2-detect-compare.json`.
- `class-free-support-contract.php` edit is comment-only; its upstream graph walk is UNKNOWN/0 (graph-resolution gap), text search confirms 51 `Free_Support_Contract::` usages — no semantic change.

Baseline reproduction receipts: `B2-cache-public-initial.log` (old dynamic code PASSed its own pure contract) and `B2-preflight-initial.log` (the real blocker: `dynamic class dependency`), retained honestly.

## Deviations

- **Unsupported → unreadable at the shared read boundary.** A product whose public factory maps it to any class other than the exact three core names (Woo external/grouped types, extension subclasses, filtered classnames, non-existent classnames whose factory fallback is `Simple` only when the filtered class is absent) is refused by `fresh_product` with `Price_Validation_Error( 'unreadable_product_data' )`. Consequences: the selector freezes the target as `unreadable` (previously `unsupported_product_type`), and Apply/Undo's top-level catch maps the validation error to `FAILED` (PENDING retry) instead of `UNSUPPORTED_PRODUCT_STATE` (FAILED). This is conservative in both directions: no write occurs, the item stays visible in the frozen population, and no extension class can become eligible because only the three exact core names are constructed and the existing `core_simple`/`core_variation`/`core_data_store` checks are unchanged. The pure contract asserts the extension constructor is never invoked. If diagnostic parity is required later, it must be added at the selector/planner layer, not by reintroducing dynamic instantiation.
- No other deviations. Writes, locks (`FOR UPDATE`), transaction ownership, fences, journal/Undo semantics, verification logic and eligibility rules are unchanged; all production edits outside `fresh_product` are one-line read substitutions or comments/docs.

## Unrun validation and blockers

`docker info` fails: `failed to connect to the docker API at unix:///var/run/docker.sock … no such file or directory` (receipt `B2-docker-probe.log`). Docker was not started, sudo was not used and no workaround was attempted. Therefore the durable Woo/MySQL/MariaDB/Redis harness (`wordpress/tests/durable/in-container.sh`), the per-version Woo matrix (9.9.7/11.0.1/11.1.2), the Redis default/persistent profiles, the real cross-process fixture, browser/Admin rendering and the real Preview/Apply/Undo behavior are **NOT_TESTED / BLOCKED**, not PASS. `php -l` and coverage review of the durable fixture were performed; the harness wiring was `sh -n` checked. Network/`gh` were read-only; no live issue recheck beyond the stored snapshot.

## Safety, graph and integration

- Preview/reviewed population, immutable frozen plans, approval binding, persistent job/item/journal truth, protected Resume, truthful partial/conflict states and conflict-aware Undo are untouched. Verification is neither removed nor weakened; the independent observer connection and DB-truth-first ordering are preserved.
- Integration overlap: Agent A and B both touch `class-product-selector.php`; B's edits are exactly the three read sites inside `resolve`/`parent_of`/`expand_variable_parents` and preserve A's category scope/limit/dedup semantics. `class-free-admin.php` stays method-level split: B owns `product_observation` only; A owns `post_input`/selection methods; C owns boot/assets/progress/Undo hooks. B must be integrated before A (selector read boundary first), then C, then D, with focused cache/selection/progress suites re-run on any combined candidate.
- Durable fixture review vs the lead finding: the focused probe primes a stale factory instance, performs a genuine second-request public CRUD edit, asserts the instance is stale (negative control), and only then runs **standalone Preview** with no intervening Admin/cache cleanup; standalone Preview is asserted to read the concurrent value. A second block removes all `clean_post_cache` actions and still asserts fresh Preview/Admin reads, keeping a stale-instance negative control. Private-fixture assertions were replaced only by public factory/product-read probes plus explicit negative controls, not by counters alone.
- Remaining Woo `Internal` references in `wordpress/writeleash/`: **none** (grep empty; no `new $`, no `wc_get_container`). In test-only code, `wordpress/tests/admin/integration.php:729` still primes the private cache to simulate stale state; tests are not shipped, that file is outside this lane's prepared scope and was deliberately not modified (removing a test's ability to stage the stale-cache condition would weaken it). The pure #209 inventory scans the shipped `includes` tree.

## Founder attention

Review the unsupported→unreadable classification deviation and authorize runtime execution (Docker Woo/MySQL/Redis + browser) and fresh #170 UI evidence before acceptance. #209 is not claimed closed or release-ready. No commit was amended or force-pushed; no gate was edited, weakened or monkeypatched.

---

# Round-3 recovery-semantics evidence (PR #220, issue #209)

Scope: prove that the transient retryable refusal cannot re-execute an unverified write, and that every uncertain outcome is durably fenced. **No production code was changed in round 3**; this section and the durable fixtures below are the only round-3 edits. Branch `work/209-public-cache`, implementation head `7496874eb0b48989814234afb703d17a5563ace0`; round-3 commit (tests + this section) is the only new commit.

## A. Recovery-semantics matrix

`#108` journal state is the only apply write evidence; `#109`/`#110` item state is the only lifecycle evidence. "Terminal item" = `Job_Item_State::TRANSITIONS[state] === []` (`class-job-state.php:82-91`) or `Undo_Item_State::TRANSITIONS[state] === []` (`class-undo-state.php:74-81`). "Zero write" means the Woo `save()` at `class-woo-price-mutator.php:150` / `class-woo-undo-mutator.php:191` was never reached or was rolled back with the item transaction.

| Reason / code | Where raised | Durable journal | Durable item | Retryable? | What guards re-execution |
|---|---|---|---|---|---|
| planning `unsupported_product_type` | `class-product-snapshot.php:59-64` → `class-product-selector.php:83-87,127-129`; eligibility `class-product-snapshot.php:210` | no row seeded for `UNSUPPORTED` (`class-price-apply-journal.php:181`) | `UNSUPPORTED`, terminal at import (`class-job-state.php:80,87,100-104`) | No | Never eligible; zero write |
| `UNSUPPORTED_PRODUCT_STATE` (apply) | `class-woo-price-mutator.php:104-111`; `class-price-cache-verifier.php:94,118-119,158`; parent sync `class-price-cache-verifier.php:97,105,109` | `FAILED` (`class-woo-price-mutator.php:180,191-192`) | `FAILED` terminal (`class-job-worker.php:231-234`) | No | Thrown before `set_field`/`save` (`:149-150`); zero write |
| transient `unreadable_product_data` at Apply read | `class-product-snapshot.php:49,58,63,69,70`; rethrow `class-woo-price-mutator.php:105-109` | `PENDING`, reason `FAILED` (`:173,180,191-192`) | `PENDING`, reason `FAILED`, `retry=true` (`class-job-worker.php:251-254`) | Yes, bounded | Every retry is a fresh `apply()`: journal `FOR UPDATE` + `PENDING`-only gate (`:76,86-89`), post/meta/lookup row locks (`:91-95`), targeted invalidation (`:103`), frozen `precondition` MATCH (`:117-118`), stored expected price (`:127-129`), sale/regular coherence (`:133-138`), re-authorize (`:146`), only then save (`:150`); rollback proves no committed write. Max 3 attempts, then `FAILED`/`RETRY_BUDGET_EXHAUSTED` (`class-job-repository.php:24,400`) |
| transient `unreadable_product_data` at observation | `class-price-cache-verifier.php:255,283-284` → `CACHE_VERIFICATION_FAILED` `:276` | unchanged (read path) | Admin shows `Unavailable` (`class-free-admin.php` `product_observation`) | n/a (read) | Never certifies; suspended invalidation refuses `CACHE_VERIFICATION_FAILED` (`:13`) |
| `CACHE_VERIFICATION_FAILED` | `class-price-cache-verifier.php:13,276,281,284-285` | unchanged, or `NEEDS_REVIEW` when reachable (`class-woo-price-mutator.php:179,191`) | `NEEDS_REVIEW` terminal (`class-job-worker.php:256-257`) | No | Never saved; never auto-retried |
| `CONFLICT` | `class-woo-price-mutator.php:92,116,118,128,135,137`; `class-price-cache-verifier.php:97,105,109` | `CONFLICT` (`:191-192`) | `CONFLICT` terminal (`class-job-worker.php:223-225`) | No | Precondition/coherence before any save; newer stored edit preserved |
| generic `FAILED` (caught rollback) | `class-price-apply-connection.php:75`; reason fallback `class-woo-price-mutator.php:173` | `PENDING` (`:191`) | `PENDING`, retry (`class-job-worker.php:251-254`) | Yes, bounded | Same fresh-transaction preconditions; rollback proven by `owns_attempt()`/`rollback()` (`class-price-apply-connection.php:83-90`) |
| `AMBIGUOUS_COMMIT` | lost commit response `class-price-apply-connection.php:80`; committed+`FAILED` `class-woo-price-mutator.php:174` | unchanged or `NEEDS_REVIEW` | `NEEDS_REVIEW` terminal | No | `$committed` ⇒ review (`:179-180`); a later attempt adopts `ALREADY_APPLIED` only from durable `APPLIED` (`:80-85`) |
| `TRANSACTION_LOST` | `class-price-apply-connection.php:41-50,64,72` | unchanged (server rollback) | `NEEDS_REVIEW` terminal | No | Rollback-failure detection `class-woo-price-mutator.php:175-177`; durable proof `durable/integration.php:271-290` |
| Undo `UNDO_CONFLICT` | `class-woo-undo-mutator.php:157,166,173,267-271` | n/a (Undo item row) | `UNDO_CONFLICT` terminal (`:244-245,258`; `class-undo-state.php:78`) | No | Field-scoped applied-value check + sale guard before `set_*`/`save` (`:158-175,186-191`); newer edit preserved |
| Undo uncertain → `UNDO_NEEDS_REVIEW` | `TRANSACTION_LOST`/`AMBIGUOUS_COMMIT`/`CACHE_VERIFICATION_FAILED`/`JOURNAL_MISMATCH`/`UNDO_PROVENANCE_MISMATCH` (`class-woo-undo-mutator.php:243`) | n/a | `UNDO_NEEDS_REVIEW` terminal (`:258`; `class-undo-state.php:80`) | No | `undo/integration.php:436-441` (ambiguous COMMIT, no blind retry) |
| Undo transient `FAILED` | `class-woo-undo-mutator.php:132-137,237` | n/a | `UNDO_PENDING` (`:258`; `class-undo-worker.php:266-271`) | Yes, bounded | Same full fresh transaction + provenance/fingerprint re-verification (`:95-117,155-175`); kill-before-COMMIT retry proven `undo/integration.php:385-407` |

## B. Required proofs (branch HEAD citations)

1. **Terminal unsupported class.** Round-2 durable case retained: `tests/durable/cache-public-integration.php:126-137` maps a live product to `WC_Product_External` for the Apply read only, asserts `UNSUPPORTED_PRODUCT_STATE` + `reason=UNSUPPORTED_PRODUCT_STATE` + zero price change + durable `FAILED` (never `PENDING`). Planning-level terminal refusal: `class-product-selector.php:83-87,127-129` freezes the ID via `Product_Price_Snapshot::unsupported_type` (`class-product-snapshot.php:110-116`), so it is never seeded (`class-price-apply-journal.php:181`) and lands `UNSUPPORTED` at import (`class-job-state.php:80`). Still passes `php -l` and the pure `free/cache-public.php` 73-assertion contract; runtime execution remains NOT_TESTED (Docker blocked).
2. **Transient read refusal is not a verified safe-to-retry write.** The refusal happens before any `save()` (`class-woo-price-mutator.php:104-110` vs `:150`), leaves the journal `PENDING` (`:191-192`), and a retry is a brand-new call whose first mutating statement is the re-lock/re-read/re-verify sequence (`:76-118,125-138,146`) with the Woo save last (`:150`). New durable regression: `tests/durable/cache-public-integration.php:149-186` — `stdClass` class fault staged on the Apply read ⇒ `FAILED` + zero saves + durable `PENDING`; retry with the fault removed ⇒ `APPLIED` with exactly one save; external edit between the refusal and a second retry ⇒ `CONFLICT` with zero further saves and the newer price preserved. Job-level regressions: `tests/jobs/integration.php:516-558` with the test-only public `woocommerce_product_class` fault knob at `tests/jobs/worker.php:47-50` — transient refusal ⇒ item `PENDING`/`reason=FAILED`/`attempt_count=1`, `RETRY_BACKOFF`; retry after a newer edit ⇒ `CONFLICT`, zero saves, price 90 preserved; retry without an edit ⇒ `APPLIED` exactly once.
3. **No uncertain write is automatically repeated.** `$committed && 'FAILED'` ⇒ `AMBIGUOUS_COMMIT` (`class-woo-price-mutator.php:174`); failed rollback with a live attempt ⇒ `TRANSACTION_LOST` (`:175-177`); both, plus `CACHE_VERIFICATION_FAILED`/`LOOKUP_MISMATCH`/`JOURNAL_MISMATCH` and any `$committed` outcome, set review (`:179-180`) and durably land `NEEDS_REVIEW` (`:191-194`). `Job_Item_State::TRANSITIONS[NEEDS_REVIEW] = []` (`class-job-state.php:90`) and `claim_next_item` selects `state=PENDING` only (`class-job-repository.php:402,406-408`), so Resume may run other pending items but can never re-claim the uncertain one; `$committed` retries adopt `ALREADY_APPLIED` only from durable `APPLIED` (`class-woo-price-mutator.php:80-85`). Durable proofs already at HEAD: `durable/integration.php:271-312` (lost/changed/reconnected/transaction-ended/kill-during-query/ambiguous COMMIT/cache-read-fault all `NEEDS_REVIEW`, no replay) and `undo/integration.php:408-441`.
4. **Durable journal state gates retry.** `PENDING` gate `class-woo-price-mutator.php:86-89`; `APPLIED` ⇒ rollback + `ALREADY_APPLIED`, zero second save `:80-85`; `transition()` updates exactly one unique-key row `class-price-apply-journal.php:195-198`; transaction ownership is transport-enforced (`class-price-apply-connection.php:41-50,63-90`); the item transaction additionally locks the job row and verifies `(lease_owner, lease_generation)` through `Job_Transaction_Fence::acquire` (`class-job-transaction-fence.php:38-49`) and the journal row via `FOR UPDATE` (`class-woo-price-mutator.php:76`); lease acquisition is a generation CAS (`class-job-repository.php:301-316`), so duplicate Resume requests serialize (second gets `LEASE_HELD`) and a sequential duplicate sees durable `APPLIED`. `checkpoint` is an observation hook only (`class-woo-price-mutator.php:8-10`); it injects test faults and is not a fence. Durable proofs: `durable/integration.php:159-162` (APPLIED retry replay=0), `durable/identity.php:79-88` (same-plan retry replay=0), job-level one-save takeover (`jobs/integration.php` stale-takeover + killed-worker blocks), Undo duplicate `undo/integration.php:377-381` (`ALREADY_UNDONE`, one save), new interrupted-before-COMMIT job fixtures `tests/jobs/integration.php:485-514` (durable `PENDING` after rollback, one net change on recovery).
5. **Resume never blindly overwrites newer edits.** Frozen `precondition` must be `MATCH` (`class-woo-price-mutator.php:117-118`); stored `_regular_price`/`_sale_price` truth is compared under `FOR UPDATE` before any write (`:125-138`); failure is `CONFLICT` (`:180,191`), item `CONFLICT` terminal (`class-job-worker.php:223-225`; `class-job-state.php:88`). Durable proofs: `durable/integration.php:228-235` (external edit and target-equality-without-journal both `CONFLICT`, zero save), `tests/durable/cache-public-integration.php:73-74,82-83`, new R3 conflict block `:174-186`, E2E job conflict (`tests/jobs/integration.php:262-264`).
6. **Undo preserves conflict-aware protections.** `UNDO_CONFLICT` is terminal (`class-woo-undo-mutator.php:244-245,258`; `class-undo-state.php:78`) and every product-state drift maps into it (`:267-271`); uncertainty maps to `UNDO_NEEDS_REVIEW` (`:243,258`; `class-undo-state.php:80`). The restore refuses when the target field no longer holds the applied value (`:158-166`) and refuses to clear a newer sale (`:170-174`); `matches_field` preserves the untouched field/dates captured pre-save (`class-price-cache-verifier.php:193-207`, `class-woo-undo-mutator.php:176-181`). Durable proofs: `undo/integration.php:300-335` (newer edit not overwritten, sale config preserved, zero restore saves on conflict) and `:385-441` (kill before/after COMMIT and ambiguous COMMIT).
7. **No uncertain outcome becomes APPLIED/UNDONE without proof.** The only writes of the success states are the in-transaction transitions `class-woo-price-mutator.php:164` and `class-woo-undo-mutator.php:228`, each after stored-truth verification and each committed atomically with the Woo save on the same connection. The worker adopts `APPLIED` only for `APPLIED`/`ALREADY_APPLIED` codes (`class-job-worker.php:219-221`) or durable journal `APPLIED` during reconciliation (`:284-287`); Undo adopts `UNDONE` only from the durable row (`class-undo-worker.php:213-221`). `Price_Cache_Verifier::observe()` is read-only (`class-price-cache-verifier.php:209-278`). No APPLIED-from-target-equality: `durable/integration.php:232-235` proves a product already equal to the target without journal evidence is `CONFLICT`, not APPLIED (`Change_Plan::precondition` at `class-change-plan.php:255-290`).

## C. Re-verification receipts (branch HEAD)

- `grep -rn "ProductCache|Automattic\\WooCommerce\\Internal|Internal\\Caches|wc_get_container" wordpress/writeleash/` → empty (receipt `B3-internal-grep.log`); `grep -rn 'new $'` → empty; no `call_user_func`/dynamic class instantiation added by #209 (pre-existing `class-guard.php` untouched in this branch).
- Targeted invalidation only: `class-price-cache-verifier.php:8-23` uses `clean_post_cache()` + `wp_cache_delete($id,'post_meta')` + `wp_cache_delete('lookup_table','object_'.$id)` + `wc_delete_product_transients($id)` + `WC_Cache_Helper::invalidate_cache_group('product_'.$id)`; no `wp_cache_flush`, no global group flush (grep empty).
- No direct Woo price SQL: the only `_regular_price`/`_sale_price`-adjacent SQL is the plugin-owned journal INSERT on its own table (`class-price-apply-journal.php:183`); product writes go through Woo CRUD (`set_regular_price`/`set_sale_price` + `save()`).

## D. Divergence and founder approval

The transient `unreadable_product_data` → `FAILED` → durable `PENDING` retry **is still a divergence from the pre-#209 baseline** (baseline treated that read failure as terminal `UNSUPPORTED_PRODUCT_STATE`/`FAILED`). Round-3 evidence shows it is not unsafe re-execution (proof B2/B3/B4): it writes nothing while failing, every retry re-runs the full fenced precondition sequence before any save, the durable journal is the retry gate (`PENDING` only), and the retry budget is bounded (3 attempts, `class-job-repository.php:24,400`). **This divergence remains and still requires explicit founder approval.** No production behavior was changed in round 3; the only way to remove it would be a deliberate mutator-level mapping change, which was not made.

## E. Round-3 test deltas and NOT_TESTED

- `tests/durable/cache-public-integration.php` +~38 assertions (R3 transient-retry + precondition-conflict block, public Woo filter staging only); still wired at `durable/in-container.sh:38`.
- `tests/jobs/integration.php` + interrupted-before-COMMIT recovery loop (both mutator checkpoints) and + transient-retry job blocks; `tests/jobs/worker.php` + test-only `class_fault` public-filter knob. Wired at `jobs/in-container.sh:27`.
- `php -l` clean on all three files; `git diff --check` clean; no production file touched (`git status` = docs + 3 test files).
- **NOT_TESTED:** Docker is unavailable in this environment (no `/var/run/docker.sock`; receipt `B3-docker-probe.log`), so the new durable fixtures and every `jobs/`/`undo/`/`durable/` runtime assertion are **NOT_TESTED**, not PASS. They are structurally lint-checked and follow the existing harness patterns exactly (real Woo 11.1.2, public APIs/filters only, no mutator mocks).
- Executed local checks (PHP 8.2): the full round-2 battery re-run on the round-3 tree — `free/cache-public.php` 73, `free/unit.php` 2607, `free/sale-price.php` 105, `free/variations.php` 75, admin-audit, no-replan-audit, ownership, dependency, package-preflight, inventory, public-audit, readme, historical-shim, claim-matrix, source-audit, lint and `git diff --check` all PASS; `bash .github/ci/pr-fast.sh` fails only at the unchanged #170 UI capture fingerprint (expected; receipts under `evidence/pr-fix3/220/`).
- **Defect found in round 3: none.** No stop condition (blind overwrite, duplicate completed work, uncertain→success, Undo destroying later edits) was violated; no production edit was made.
