# Free 1.0 immutable Woo price planning — #107

This is a **read-only preview contract**, alongside Guard/Doctor/Redirection.
It supplies neither approval storage nor execution, jobs, tables, scheduling,
history, Undo or a product Admin UI. The existing plugin dependency/activation
path remains intact. Loadable classes do not require Woo until invoked.

## Product domain

Only the exact core `WC_Product_Simple` class, `type=simple`, `status=publish`,
stored `get_regular_price('edit')`, base store currency. Extension subclasses
are conservatively excluded even if they claim to be simple. Variable parents,
variations, grouped, external, subscriptions, bundles, composites and custom
types are unsupported; draft/pending/private/future/trash are unsupported.
Tax-inclusive/exclusive storefront prices, discounts and multi-currency
extensions are not this price field. Filtered currency differing from the base
option yields `unsupported_currency_context`; caller integrations must not
claim extension-defined per-product currency support.

Sale exclusion uses edit-context sale price **strictly unequal to empty string**
(including numeric zero), or either non-null edit-context sale date. It does not
use `is_on_sale()`: configured inactive, future and expired sales and date-only
configuration are excluded (`sale_configured`). Empty regular price is distinct
from zero and is unsupported for every operation, including SET.

## Decimal contract, v1

- Input and stored-price grammar: ASCII `0` or a nonzero digit followed by digits,
  optionally `.` and 1–6 digits. Strings only; no leading zeros except `0`.
  At most 12 integer digits; maximum `999999999999.999999` before final rounding.
- Accepted: `0`, `0.00`, `100`, `12.5`, `1.005`, `0.000001`. Amounts and percentages
  are unsigned; the operation carries direction. Percent input `20` means 20%,
  without a `%` symbol. Insignificant fractional zeros normalize in provenance.
- Rejected, without repair: `1,234.56`, `1.234,56`, `1 234,56`, `12,5%`, `$10`,
  whitespace (including leading/trailing), `+10`, `-10`, `.5`, `1.`, `01`, `1e2`,
  PHP floats/integers, NaN/INF, non-ASCII digits, >6 input fractional places.
  Typed validation exceptions expose stable reason codes.
- Arithmetic uses base-10 **string digits**; only individual digit/carry operations
  use small PHP integers. No bcmath dependency or binary-float final arithmetic.
  Addition/subtraction use six-place units; percentage multiplication uses exact
  fourteen-place units (old scale 6 × percent scale 6 ÷ 100). No intermediate rounding.
- Store decimals supported: 0–6. Greater configurations fail explicitly; Woo can
  configure more, which does not imply this contract supports them.
- Round once, **half up**, after the complete operation, to store precision.
  Persist/preview the resulting string with exactly that many fractional digits
  (`80.00`, `0.00`; at precision zero `80`). The worker must use this string as-is.
  `1.005 → 1.01` at precision 2; `0.01 − 50% → 0.01`.
- Expected old price normalizes insignificant zeros, never rounds its numeric
  value to store decimals. Raw stored string is retained separately in snapshot.
  Thus an existing fractional cent is compared exactly, not silently discarded.
- Numeric old zero permits SET/fixed operations. Any percent operation from zero
  is unsupported (`percent_from_zero_undefined`), even 0%. Zero targets are valid
  math and subject to policy. Negative unrounded results are unsupported
  (`negative_target`), even if they would round to zero; never clamp. Overflow
  before input parsing or after target rounding is `price_overflow`.
- Absolute delta is signed, exact and trailing-zero normalized. Percentage delta
  is an exact signed rational numerator/denominator plus a **display-only** six-place
  half-up string. Old zero has a null ratio. Policy never uses rounded display.

## Selectors

Only explicit IDs, a single exact SKU, or a single direct category term ID.
Planning is bounded to 1,000 concrete IDs; overflow fails the entire selection
without a partial plan. This is an initial planning ceiling, not #112 scale proof.
IDs and items sort ascending numerically. The public WP query primes post/meta/
term caches before Woo object reads; no N × full-catalog query. Missing explicit
IDs remain preview items with `missing_product`. Unsupported selected products
remain with typed reasons, not silently dropped. Duplicate requested IDs are
deduplicated with a `duplicate_selection` selection warning; selected count is
the unique count. An empty explicit list is invalid; an unmatched SKU/category
may yield an empty, non-executable preview.

SKU input is 1–100 UTF-8 bytes without whitespace/control/angle brackets; `*`
alone is rejected. No trimming/case folding/Unicode normalization. Matching is
byte-for-byte `get_sku('edit')` equality. Public `WP_Query` equality narrows
candidates, then the Woo read enforces equality independently of DB collation.
Woo's standard `sku` argument is LIKE, so is not used for exact selection.
Unexpected duplicate exact matches fail `ambiguous_sku`, never select the first.
Woo setter uniqueness checks are not a planner uniqueness guarantee.

Category uses public WP taxonomy query `product_cat`, term ID, **no descendants**.
Multiple memberships do not duplicate IDs. Missing term is invalid. Both products
and variations are queried so selected unsupported variations remain explainable.
Concrete IDs are frozen; subsequent membership changes are **provenance only**,
not drift conflicts. SKU/name changes are also provenance only. Workers do not
re-query selectors. Title search: **DEFER** (search interpretation/cost unproven).
Stored regular-price range: **DEFER** (distinct from active/lookup/display price;
decimal comparison/cost not certified). No other filters are exposed.

## Domain and immutability

`Price_Decimal`, `Price_Operation`/`Price_Calculator`, `Price_Store_Context`,
`Product_Price_Snapshot`/`Eligibility_Result`, `Price_Selection_Spec`,
`Product_Price_Selector`, `Safety_Policy`/`Policy_Result`/`Policy_Evaluator`,
`Change_Plan_Item`, `Change_Plan`/`Plan_Hasher`, `Woo_Price_Planner` are small
focused values/services under `includes/free/`, in the existing WriteLeash namespace.

Plans retain schema version, ID, UTC creation time, actor, WP/Woo versions,
currency/decimals/base-context, selector, frozen IDs, canonical operation input,
policy snapshot/result, PREVIEW/BLOCKED status, hash version/hash and summary.
Items retain ID, SKU/name/type/status/category snapshots, raw regular/sale prices,
UTC sale dates, core-class flag, canonical expected and **absolute planned price**,
delta/ratio, eligibility, result CHANGING/UNCHANGED/UNSUPPORTED, blockers/warnings.

Private fields, no setters, dynamic assignment/unset rejection, immutable nested
values, copy-on-write output arrays: material edits require a new plan. No mutable
approved-plan pathway exists. Later approval persistence must bind **plan ID +
schema/hash version + hash** and the immutable policy snapshot; this issue does
not implement an approval workflow. JSON is an export/domain persistence boundary,
not an untrusted plan import or an authorization token. Later repositories must
verify a trusted stored fingerprint; do not construct authorization from client JSON.

Canonical JSON recursively sorts map keys, preserves lists, rejects floats/objects
and invalid UTF-8. SHA-256 covers all material, including actor, context, selection,
snapshots/display provenance, operation, policies, results and ordered items.
Identity ID/time are intentionally excluded, so identical canonical material has
the same hash across new preview instances; display/provenance changes can change
the hash although they do not require execution conflict. Hash v1 is
`sha256-canonical-json-v1`. Material changes mean a new identity/fingerprint.

## Policies and counts

Summary separates unique selected, eligible, changing, unchanged, unsupported,
blocked, conflicted (0 at preview), warning items/count and selection warning count.
Eligible means eligibility **and calculation** succeeded. Unchanged is exact
numeric expected=planned, and does not count toward maximum products **changed**.
Unsupported items have no executable target; later executor must only consider
CHANGING items. A negative/overflow calculation is unsupported, not executable.

Policies require explicit max changed count (0–1000), max percentage increase,
max percentage decrease, block-zero bool, warning threshold percentage. All are
snapshotted. Evaluate actual canonical old/new deltas, not requested operations.
Limits are inclusive: exactly 20% allowed; 20.01%, or one minimal supported unit
over, blocks. Cross-multiplication evaluates exact rational percentages. Fixed/SET
increases from zero have no finite percentage ratio: `percentage_limit_from_zero_undefined`
blocks; there is no silent cap bypass. Unchanged zero does not violate zero policy.

Any changing eligible zero target with block-zero enabled, any percentage limit
violation, or max changing count violation makes the **whole plan BLOCKED**.
Blocked summary count equals all changing products; none are authorized, even
individually clean ones. Precondition immediately returns BLOCKED. Warnings
`large_price_increase`/`large_price_decrease` use strict > threshold from the same
canonical values, are informational and cannot override blockers. Result states
are ALLOW, ALLOW_WITH_WARNINGS or BLOCKED. ALLOW describes policy only, not approval.

## Future executor preconditions (no executor here)

`Change_Plan::precondition(id, current_snapshot, current_context)` returns MATCH,
CONFLICT, BLOCKED or NOT_CHANGING. It never calculates a new price or resolves a
selector. Missing/wrong ID, numeric regular-price change, type/core-class change,
status change, any sale configuration change, currency/base-context change,
decimals change, or WP/Woo version change conflicts. Numeric equivalent `100`
and `100.00` match; malformed/empty live prices conflict. Category/SKU/title are
provenance only. The worker must re-read public Woo state freshly under its own
concurrency boundary, check edit rights and approved binding, then consume only
the persisted ID/expected/target strings. This API supplies no locks, freshness,
transactions, product saves, crash/cache/concurrency guarantees or approval.

## Preview and entry boundary

`summary()` is independent of paginated detail. `preview_page(offset, limit)`
accepts nonnegative offset, limit 1–100, returns bounded copied rows and next offset.
Rows show stored regular price, expected, planned, exact delta, percentage display,
name/SKU, typed eligibility, warnings and blockers. No HTML is produced; future UI
must escape snapshot text. These are planned amounts, not committed outcomes or
shopper display prices. No unbounded HTML API exists.

The Woo adapter requires current `manage_woocommerce`, `edit_products` and per-ID
`edit_post`, captures the current actor, checks settings consistency across the
read. No REST/POST/Admin handler exists. Future entry adapters must authenticate,
validate input, use action-bound nonces and escape output. Domain factory is for
trusted internal consumers, not an authorization boundary.

## Source research and reproducible evidence

Exact fixture: WooCommerce **11.1.2**, official WordPress.org ZIP:
`https://downloads.wordpress.org/plugin/woocommerce.11.1.2.zip`, SHA-256
`9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e`.
Source inspected from that ZIP and official
[`11.1.2` source](https://github.com/woocommerce/woocommerce/tree/11.1.2/plugins/woocommerce/includes):

- `wc-product-functions.php`: `wc_get_product()` factory requires initialized
  Woo post types/taxonomies; `wc_get_products()` wraps `WC_Product_Query`.
- `abstracts/abstract-wc-product.php`: regular/sale/status/SKU getters delegate
  to `get_prop`; edit context bypasses view filters. Sale dates return nullable
  WC_DateTime. `WC_Product_Simple::get_type()` is simple. Setter calls
  `wc_format_decimal($price)` with default false rounding.
- `abstracts/abstract-wc-data.php`: `save()` fires before/after object-save hooks
  around data-store create/update. Tests instrument the before-save hook to fail
  immediately if planning calls it, and compare fresh persisted snapshots.
- `wc-formatting-functions.php`: false dp leaves string precision intact;
  specified dp uses float `number_format`, which the planner deliberately avoids.
  `wc_get_price_decimals()` is filtered absint; >6 is unsupported here.
- `wc-core-functions.php`: `get_woocommerce_currency()` returns filtered base option.
- `class-wc-product-query.php` and `data-stores/class-wc-product-data-store-cpt.php`:
  include maps to post__in, category to slug taxonomy query (default descendants),
  SKU to LIKE (with `*` special case). The planner's documented exact SKU/direct
  category semantics use public WP queries instead. Integration exercises Woo
  include as source-behavior evidence as well.
- Official API docs: <https://developer.woocommerce.com/docs/extensions/core-concepts/wc-get-products/>.

Run `php wordpress/tests/free/unit.php`, then `bash wordpress/tests/free/run.sh`.
CI uses pinned WordPress 7.1.2/PHP 8.2 images, MySQL 8.0.44 and MariaDB 10.11.15,
and installs Redirection 5.5.2 solely to retain the existing activation contract.
The real fixture covers sale active/future/expired/date-only, empty/zero,
unsupported status/types, known SKU, categories, setter-string roundtrip separately
from planning, policy blocks, hash/immutability/pagination, and drift. This is
evidence for those versions only, **not a general Woo compatibility claim**.

## Maintainer scope audit

No Woo mutation implementation, price SQL, Action Scheduler runner, job tables,
journal, leases, retries, Undo, Admin product UI, telemetry, network runtime,
Pro or Guard integration. Test setup alone uses Woo saves and one public metadata
API SKU anomaly; planner saves are zero. #108–#112 remain subsequent work.

## Merchant discovery (#167)

Admin name/partial-SKU search is read-only discovery, not a new saved selector.
Each request reads at most two 11-row candidate windows (title and literal
partial SKU), returns at most 20 caller-readable/editable published products,
and caps pagination at 50 windows per query. Permission filtering may leave
an empty page with further candidates; the UI offers another page or a narrower
query. Category discovery reads at most 21 terms per page and resolves at most
20 ancestors per returned label; category membership still excludes descendants.
Search text is capped at 100 bytes. No catalog-sized browser payload or total
count query is required. Selected concrete IDs use the existing IDS plan and
100-product public support limit. Eligibility is rechecked by the planner.
Saved preview reopening hydrates the original plan; it never reruns discovery.
