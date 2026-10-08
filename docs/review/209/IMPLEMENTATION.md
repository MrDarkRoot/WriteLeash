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
