# Free price apply: #108 engineering contract

> Journal schema 3 supersedes the full-JSON-per-item statements below. Public
> jobs retain the canonical full plan once in `writeleash_jobs`; item rows keep
> identity/versions, the existing material hash, a supplemental SHA-256 of the
> complete canonical JSON, the price field and expected/absolute target values.
> New rows have NULL legacy `plan_json`. Validated job-backed v2 rows compact
> transactionally without changing progress, attempts, evidence or timestamps.
> Primitive v2 rows without a durable job keep their original full material and
> strict legacy binding; migration never invents approval or Undo authority.

> #178 supersedes the regular-only statements below: Apply targets the plan's
> price field (`regular_price` or `sale_price`). Verification proves the target
> field, the preserved other field and sale dates, the computed active `_price`,
> lookup min/max and onsale. Evidence adds `price_field`, `field_value`,
> preserved regular/sale, active and lookup values; fresh observer
> certification is field-aware and no longer requires a sale-free product.
> Legacy evidence without `price_field` keeps the old strict path exactly.
>
> #179 supersedes the simple-only statements below: eligible core variations
> use the same CRUD path. After a variation save the item transaction locks the
> parent row, calls `WC_Product_Variable::sync()` on the same pinned connection,
> and certifies the parent lookup min/max against the visible children's
> `_price` values (typed `LOOKUP_MISMATCH` => review, zero commit). Evidence adds
> the frozen `parent_id`. Stock simple evidence keeps its exact legacy shape.

This is an item correctness experiment consumed through PHP, with no public
mutation endpoint, automatic journal installation, approval UI, job engine,
Action Scheduler execution, full Undo or release claim. #109 must retain these
invariants before adding scheduling. Guard/Strict and Redirection are separate.

## Authoritative input and supported scope

`Woo_Price_Mutator::apply(Change_Plan, product_id)` accepts the existing #107
immutable object and a trusted seeded journal row. The row retains the exact
canonical plan ID/JSON/hash, plan schema/hash versions, journal schema version,
product ID, expected canonical string
and planned absolute string. Blocked and nonchanging items cannot execute. The
executor uses #107's `precondition()` and `Price_Decimal::parse()` comparison; it
contains no operation arithmetic, target recalculation or selection query.

#107 has **one operation per plan**. Two products with identical initial prices
cannot have different targets in one unchanged #107 plan. The mandatory A/B lab
therefore uses two authentic single-item #107 plans in one fixture: decrease
20% creates A=80.00; decrease 10% creates B=90.00. Both initial prices are 100.00.
Only planning computes these values. Test workers deserialize locally generated
trusted objects; production has no unserialization or untrusted import endpoint.
The #107 contract is unchanged. This discrepancy must remain visible in review.

Scope is core `WC_Product_Simple`, `publish`, no configured sale price/dates,
base currency, unchanged store decimals and supported WP/Woo versions. The attempt
runs on WooCommerce 10.0 through 11.x, the stock `WC_Product_Data_Store_CPT`, a
standard transactional mysqli `wpdb` (ordinary drop-in subclasses included), a
single site, and InnoDB tables. Active
price must already agree with regular price and lookup before apply. Ambiguous,
missing, duplicate or malformed price metadata is refused, not repaired silently.
Observed abnormal storage in both engines: duplicate `_regular_price` rows make
Woo return the first `100.00` value; missing metadata returns an empty string;
fixture raw `not-a-price` is exposed by Woo's setter normalization as `--`.
The executor inspects cardinality and strict raw strings before trusting this
public read, and refuses all three cases without saving.

The actual lookup's decimal representation must compare equal; incompatible
lookup precision fails verification rather than manufacturing an APPLIED result.

## Plan instance identity and fingerprint

`(plan_id, product_id)` is execution identity, with a case-sensitive ASCII plan
ID column matching #107's ID grammar. `plan_hash` is the immutable material
fingerprint. #107 intentionally excludes identity ID/time from material hashing;
two independently created plan instances may share the same hash. They receive
separate journal rows, row locks, attempts and outcomes. The existing A/B fixture
continues to use two authentic single-item plans in a test-only two-item worker.

After locating by identity, seed, mutator and observer verify journal schema,
plan ID/product, plan schema/hash versions (using `Change_Plan::SCHEMA_VERSION`
and `HASH_VERSION`), fingerprint, full trusted JSON and expected/target strings.
An exact repeated seed preserves the row and execution state. Its nondestructive
INSERT collision handling always performs full binding validation afterward;
same-ID changed material raises JOURNAL_MISMATCH. Diagnostic refusal must not
rewrite a legitimate row belonging to different material under that same ID.

APPLIED evidence includes both plan ID and hash, plan schema/hash versions,
product, target and attempt. Observation verifies them against both the row and
supplied plan. An identical hash never permits observation of another instance.
Same-plan APPLIED retries suppress save. A fresh plan after a legitimate external
reset from 80 to 100 can independently apply 100 to 80 despite sharing a hash.

Journal schema **2** replaces the branch-local schema-1 fingerprint unique key.
Install backfills identity/version columns from validated stored plan JSON,
reuses #107's material hasher, and attaches identity/version to historical
APPLIED evidence. It preserves row IDs, states, attempts and timestamps. Reviewed
ALTER statements tighten NOT NULL constraints and remove the obsolete index;
`dbDelta` installs/verifies the new shape. Mutation/seed refuse incomplete schema
or wrong identity collation/index. Malformed legacy bindings are refused rather
than assigned an invented identity. Upgrade/repeated install and historical
APPLIED recovery are tested on both engines/cache modes. This is a narrow
branch-local upgrade, not a public migration or #109 job schema.

## Source ownership evidence

The official Woo 11.1.2 artifact SHA256 is
`9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e`.
Relevant exact-version source:

- [WC_Product_Data_Store_CPT](https://github.com/woocommerce/woocommerce/blob/11.1.2/plugins/woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php):
  `update()` uses global `$wpdb` for post timestamps; `update_post_meta()` uses
  WordPress metadata APIs; `handle_updated_props()` synchronizes `_price` from
  regular price when no sale applies, then updates the lookup through the data
  store. `clear_caches()` calls Woo transient/group helpers and removes optional
  product instance cache entries.
- [WC_Data_Store_WP](https://github.com/woocommerce/woocommerce/blob/11.1.2/plugins/woocommerce/includes/data-stores/class-wc-data-store-wp.php):
  `update_lookup_table()` uses global `$wpdb->replace()` and caches its payload
  as key `lookup_table`, group `object_<id>`. Equal cached payload means **no SQL**.
- [WordPress wpdb](https://github.com/WordPress/WordPress/blob/7.1.2/wp-includes/class-wpdb.php):
  `query()` can reconnect and call `_do_query()` a second time without repeating
  the query filter. A query filter alone cannot fence that replay.
- [Product factory](https://github.com/woocommerce/woocommerce/blob/11.1.2/plugins/woocommerce/includes/class-wc-product-factory.php)
  and [ProductCache](https://github.com/woocommerce/woocommerce/blob/11.1.2/plugins/woocommerce/src/Internal/Caches/ProductCache.php):
  optional object caching is evicted through `ProductCache::remove()`, not
  invented internal cache keys.

`Price_Apply_Connection` is a temporary stock-wpdb subclass using the **same
mysqli handle** as the original writer. It inherits normal WP query handling,
but prohibits `db_connect()`/`check_connection()` replay. It copies accessible
parent configuration and restores the original global in `finally`. It checks the original wpdb handle as well as the scoped
handle identity and a unique transaction savepoint before/after every query.
`RELEASE` + replacement `SAVEPOINT` is a non-destructive ownership probe.
BEGIN/COMMIT/ROLLBACK use that pinned handle directly, preventing wpdb's automatic
reconnect around transaction control. An existing caller transaction is refused;
it is never committed on behalf of this slice. General SQL or DB abstraction is
not provided. Concurrent request globals remain separate PHP processes.

The lab records connection IDs inside actual Woo save hooks and the journal
APPLIED evidence. Independent observers use a **new** connection with the normal
WP identity, not Guard credentials. Observer connections are closed explicitly.
This tests ownership rather than inferring it from the variable name `$wpdb`.

## Item transaction and concurrency

1. Require transactional tables: posts, postmeta, lookup, journal, options,
   users/usermeta and taxonomy tables. Installation uses versioned `dbDelta` and
   InnoDB; only a small nonautoloaded schema option is created.
2. Start a short transaction on the original WP connection; install its ownership
   sentinel. Lock journal `(plan_id,product_id)` via `SELECT ... FOR UPDATE`.
3. Match full frozen JSON and price strings. If APPLIED, release transaction and
   independently verify evidence; return ALREADY_APPLIED without a product save.
   Other terminal states do not re-enter mutation.
4. Set APPLYING inside this transaction; lock product post, all its metadata,
   lookup row, product term relationships, currency/decimal option rows, and
   actor user/capability metadata and the role-definition option. All these locks
   precede the first consistent snapshot read under repeatable-read isolation. Evict caches and reload a new Woo product.
5. Reuse #107's fresh precondition; verify raw regular/active/lookup equivalence
   and exact supported data-store class. Refresh role definitions with `WP_Roles::for_site()`, reload the approved actor and require
   `manage_woocommerce`, `edit_products`, per-product `edit_post`, and matching
   current actor. Background/root implicit authorization is not provided.
6. Use `set_regular_price(frozen_target)` then `save()`. Check connection ownership,
   raw price/lookup facts, then write APPLIED evidence in the same transaction.
7. COMMIT on the pinned connection. Independently observe journal, metadata,
   lookup and fresh Woo object before returning APPLIED.

No lease is needed: APPLYING is uncommitted while row locks are held. Process
death disconnects the writer, rolls back APPLYING/product/journal and releases
locks. Another worker can process PENDING. A committed APPLIED row suppresses a
second save regardless of duplicate worker arrival. Locks are database locks,
not cache, PHP memory, transients or scheduler uniqueness.

Ordinary Woo writes wait on affected DB rows; a completed earlier external edit
becomes CONFLICT. This is not containment of hostile raw clients, hooks issuing
transaction control on saved raw handles, DDL, or writers bypassing WP's global
connection. WriteLeash does not own every Woo write in the installation.

## Journal and typed results

`<prefix>writeleash_price_items`: numeric primary key; unique plan_id/product_id;
version, immutable JSON, old/target prices, PENDING/APPLYING/APPLIED/CONFLICT/FAILED/
NEEDS_REVIEW, attempt UUID, UTC timestamps, reason and JSON evidence. APPLIED
records plan ID/hash/versions/product/attempt, writer connection, target, raw regular/active
and min/max lookup values. Other sessions see it only after COMMIT.

Stable returned `code` and `reason` are separate from human display copy:
APPLIED, ALREADY_APPLIED, CONFLICT, PERMISSION_DENIED,
UNSUPPORTED_PRODUCT_STATE, TRANSACTION_UNAVAILABLE, TRANSACTION_LOST,
CACHE_VERIFICATION_FAILED, LOOKUP_MISMATCH, JOURNAL_MISMATCH, AMBIGUOUS_COMMIT,
NEEDS_REVIEW, FAILED, PLAN_POLICY_BLOCKED. No response status, save return value,
in-memory product or coincidental target equality certifies success.

Known caught rollback leaves PENDING with a typed failed-attempt reason for an
explicit retry. Permission/unsupported refusals persist FAILED; conflicts persist
CONFLICT. Ownership loss and unknown commit return NEEDS_REVIEW. Diagnostic
writes use a separately locked transaction and never downgrade committed APPLIED.
A durable APPLIED row with later external divergence returns NEEDS_REVIEW and
still suppresses save. Journal evidence describes a historical committed apply;
it cannot promise the product never changes subsequently.

## Ambiguous commit and observers

The lab injects lost acknowledgement on both sides of COMMIT. The committed
case returns NEEDS_REVIEW; a later independent observation proves APPLIED and
returns ALREADY_APPLIED without save. The rolled-back case persists NEEDS_REVIEW
and refuses automatic retry. The ambiguity injection is deterministic control
flow, not a claim that a network proxy reproduced a lost COMMIT packet. Actual
connection kills (including between a query fence and SQL execution), original
wpdb reconnection, handle replacement, lost transaction sentinel and SIGKILL are
separate runtime tests.

Observer checks `_regular_price`, `_price`, one lookup row's min_price/max_price
and onsale, matching journal plan/attempt/evidence, and fresh Woo regular/active
edit reads. Product objects used by the writer are discarded. Observer DB reads
are never taken from WordPress object cache. The verifier does not certify
simultaneous unrelated future external writes or all storefront display filters.

## Cache model and hazard

SQL rollback cannot undo WordPress/Woo/Redis cache writes. The lab deliberately
reproduces: save 80 inside a transaction, rollback, post/meta cleanup only, retry
80, resulting in durable regular=80 while lookup stays 100. The remaining cached
lookup payload=80 causes Woo to skip its lookup REPLACE.

All additional invalidation lives in `Price_Cache_Verifier::invalidate()`:

- `clean_post_cache(id)`: post, post-meta, term cache and post change marker;
  explicit post-meta eviction ensures a fresh metadata load.
- Exact-source `lookup_table` / `object_<id>` eviction prevents lookup suppression.
- `wc_delete_product_transients(id)` and Woo product-group invalidation retain
  Woo's own invalidation semantics. Woo's helper also deletes some shared product
  transients; this is the public helper's cost, not a global object-cache flush.
- `ProductCache::remove(id)` also covers optional instance caching where the
  class exists (Woo 10.5+); older supported releases skip this step. Woo 11.1.2
  deliberately marks its `product_objects` group nonpersistent; the lab primes
  and evicts an actual entry via the cache API. Post/meta/lookup cache entries
  still use Redis when enabled.
- Store option and user capability caches are refreshed at their precondition
  boundaries. Cache invalidation suspension is refused.

The same targeted cleanup runs on entry, successful independent observation,
rollback and recovery. A killed PHP process cannot run cleanup; the next attempt
or observer evicts residue before interpreting product state. Until recovery,
nontransactional caches can expose stale/uncommitted values to unrelated readers;
there is **no claim of transactional storefront/cache isolation**. External
hook-owned caches or option caches are outside price-cache recovery; a rolled-back
option cache can also change the hook behavior on retry. Only supported product
price caches are repaired by this primitive.

Persistent fixture: Redis **7.4.2-alpine**, digest
`sha256:02419de7eddf55aa5bcf49efb74e88fa8d931b4d77c07eff8a6b2144472b6952`;
Redis Object Cache **2.7.0**, official ZIP SHA256
`0cbc41dea351688693b8e7db1165b5d92b6c8ef7231ac0ff5abec7b83d62c635`;
plugin's own `object-cache.php`, Predis, per-engine `WP_REDIS_PREFIX`, host `redis`.
The lab asserts actual cross-process persistence and enabled drop-in. WP_HTTP_BLOCK_EXTERNAL disables fixture telemetry and update HTTP; actual
CRUD, hooks, database coordination and Redis remain enabled. The same
full matrix runs with default cache and Redis on MySQL 8.0.44 and MariaDB 10.11.15.

## Hook, Undo and stop boundaries

The test-only hook plugin records before/after save, updated properties and
product update to a filesystem surrogate, an InnoDB log and a normal WP option.
Same-connection DB rows roll back in the tested fixture; the file append remains.
Rolled-back attempts may replay hooks on explicit retry. Durably APPLIED retries
must replay no save hooks. No email/HTTP/webhook/filesystem/order rollback promise.
No transaction support is inferred for additional third-party MyISAM writes.

Undo prototype calls observation only: current==applied yields UNDO_ELIGIBLE;
current!=applied yields UNDO_CONFLICT. It does not restore prices. ABA
100→80→70→80 cannot be distinguished by value equality and is explicitly tested.
Full fingerprints, provenance and Undo belong to #110.

Product stop signals remain unchanged: unrepairable price/lookup/journal or
persistent-cache divergence, duplicate committed item mutation, untruthful unknown
commit handling, or a need for direct Woo metadata mutation/recalculation. Tests
must fail instead of promoting these outcomes. #109 may begin only after this
gate is merged and approved by the maintainer. See the lab activity record and
workflow artifacts for actual results; source research alone is not a PASS.
