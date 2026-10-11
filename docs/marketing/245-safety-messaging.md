# #245 — Safety messaging for WriteLeash Free v0.2.0

## Source identity

Prepared 2026-10-11 for [#245](https://github.com/MrDarkRoot/WriteLeash/issues/245).
Reviewed `main` at **`464cfd0b505b07de14c3276a9cbd8a261612525b`**
([source](https://github.com/MrDarkRoot/WriteLeash/tree/464cfd0b505b07de14c3276a9cbd8a261612525b)).
References below describe that source, not a distributed v0.2 ZIP.

Reuses completed #192/#195/#196/#200 work: [launch copy](../launch-v0.1.md),
[visual provenance](../visual-assets-v0.1.md), [claim matrix](../../wordpress/release/CLAIM-MATRIX.md),
[product README](../../wordpress/writeleash/README.md) and [listing source](../../wordpress/writeleash/readme.txt).
Some historical contracts/listing passages lag merged features; current code and tests take precedence.

**Verdict: READY FOR FOUNDER REVIEW — marketing preparation.** Every material
claim below has identified source and a limitation. Code/test inspection and
direct image review are complete; this task does not establish fresh runtime
acceptance, certification or publication readiness.

## Hero copy

**Change WooCommerce prices. Protect newer edits.**

Preview your bulk price changes before applying them. WriteLeash preserves conflicting newer prices, shows what happened in History, and lets you undo eligible changes when you need to.

- Review exact before-and-after prices before Apply.
- Keep conflicting newer prices instead of overwriting them.
- Review results and undo eligible price changes.

Scope line to accompany the hero: **For supported simple products and variations,
with Regular Price or Sale Price changes. Protection checks relevant current
prices and settings; Undo is conditional.**

Optional WordPress.org-friendly alternative: **Preview bulk price changes. Keep conflicting newer prices.**

## Three proof-led blocks

### Preview before you apply

See the proposed before-and-after price for each selected product or variation before you approve the update. Preview also shows warnings and explains skipped items, so you can review the list and prices together. Creating a Preview does not change prices. If you change the selection or pricing settings, create and review a new Preview.

**Caption:** Review proposed Sale Price changes, variation identities, warnings and skipped products before approval.

Pair with [screenshot-2.png](../../wordpress/assets/screenshot-2.png).
Evidence: E1, E2. This image is a historical Set Sale Price example, not a picture of every v0.2 control.

### Keep newer prices when changes conflict

You preview a supported product’s Regular Price changing from $100 to $80. Before Apply reaches it, someone updates that Regular Price to $120. WriteLeash leaves $120 unchanged and reports a conflict, while other eligible products can continue. Review the affected product, create a fresh Preview at its current price, and approve the new plan if you still want to change it.

**Caption:** Expected $100, planned $80, now $120: the newer Regular Price stays unchanged and the product is marked “Not changed”.

Pair with [screenshot-5.png](../../wordpress/assets/screenshot-5.png).
Evidence: E3, E4. Example assumes an otherwise eligible published core simple
product, USD, two decimal places and safety limits allowing the original change.
The same target-field check applies to eligible variations. This is a check of
relevant current values, not a record of every intervening edit or a guarantee
against every plugin or failure. Product name, SKU and category edits alone do
not trigger this conflict.

### Review your work. Undo eligible changes.

History brings your jobs, results and Undo availability together. Open a job to see which products changed and which need attention. Once Apply has safely finished, Undo can restore eligible prices changed by that job within its available window. If a relevant current price or checked setting no longer allows restoration, that product is left alone and reported for review.

**Caption:** Review job outcomes in History and see whether Undo is available for prices changed by each job.

Pair with [screenshot-6.png](../../wordpress/assets/screenshot-6.png).
Evidence: E5, E6. The image shows availability, not a completed Undo or guaranteed
restoration. Undo does not reverse orders, completed sales or external effects.

## Merchant FAQ

### 1. Can I preview price changes before applying them?

Yes. Preview shows the selected products, proposed prices, warnings and skipped
items without changing prices. Approve the reviewed Preview to start Apply.
Changing the selection or pricing settings requires a new Preview. (E1, E2)

### 2. What happens if someone changes a product price after Preview?

If the price being changed no longer matches the reviewed value when WriteLeash
reaches that product, it leaves the newer value alone and shows a conflict.
Other eligible products can continue. Review the conflict, create a fresh
Preview and approve it separately. Renaming a product or changing its SKU or
category alone does not cause this conflict. (E3, E4)

### 3. Can I undo a previous bulk-price change?

Yes, for eligible prices confirmed as changed by that job, after Apply safely
finishes and while the Undo window is open. History shows availability; actual
restoration still checks each product. Regular Price Undo preserves later sale
changes if restoration remains valid. Sale Price Undo also checks the Regular
Price recorded when Apply ran. (E5, E6)

### 4. What changes might Undo refuse to reverse?

Undo can refuse a product whose changed price, product eligibility, variation
parent, currency or price precision no longer passes its checks. A later Regular
Price change also conflicts with Sale Price Undo. Missing restoration records,
unfinished or uncertain work and an expired window can prevent Undo. It does
not restore unconfirmed changes or reverse orders, completed sales, emails,
webhooks or effects in other plugins and services. (E5, E6)

### 5. Which WooCommerce product and price types are supported?

Published core simple products and published variations of published core
variable products, using stored Regular Price or Sale Price in the base store
currency. Select a variable parent to include its variations; the parent price
is not edited directly. Set, fixed and percentage adjustments are supported;
Sale Price also supports Clear and a percentage discount from each reviewed
Regular Price. Sale schedules, stock and orders are not edited. Custom product
types and every third-party pricing extension are not supported. Up to 1,000
selected final targets per new job in the tested configuration. (E7, E8, E9)

### 6. Should I back up my store before a large price update?

Yes. Keep a recent backup and try the operation on staging first. Preview,
conflict checks and eligible Undo help you review and recover supported price
changes, but they do not replace a backup or reverse every external effect.
This is practical advice, not a built-in backup feature. (E6)

## Reusable benefit statements

- Preview exact proposed price changes before approval. (E1, E2)
- Preserve conflicting newer prices and review the affected products. (E3, E4)
- Review job outcomes and Undo availability in History. (E5)
- Restore eligible price changes while the Undo window and current checks allow it. (E5, E6)
- Change Regular Price or Sale Price across supported simple products and variations. (E7)

## Claims, evidence and limitations

VERIFIED means supported by inspected current implementation and identified
tests/accepted records. It does not mean those runtime suites were rerun here.
NARROW_WORDING means use the replacement wording and limitation shown.

| Proposed marketing claim | Actual source/test evidence | Honest limitation | Verdict |
| --- | --- | --- | --- |
| Preview before Apply; approval uses the reviewed products and targets | E1: frozen plan, hash validation, approval binding; E2: Admin form and tests | Preview is read-only; configuration changes require fresh review. Planned stored prices are not guaranteed shopper prices. | VERIFIED |
| “Protect newer edits” | E3: `precondition()` and locked expected-field comparison; E4: exact $100/$120/$80 browser assertions | Say “preserve conflicting newer prices”. Checks current relevant values; name/SKU/category are reference details. Values changed away and back can match again. No universal external-write protection. | NARROW_WORDING |
| Leave a conflict unchanged; fresh review needed to proceed | E3; E4: fresh conflict Preview tests and separate approval | Other eligible items continue where safe. Resume does not authorize the conflicted item. A new Preview can conflict again if prices change afterward. | VERIFIED |
| History shows jobs, outcomes and Undo availability | E5: scoped History reads, table and availability rendering | Retained, authorized records only; default retention is 30 days, internally configurable. Availability is not proof every item will restore. | VERIFIED |
| Undo eligible changes | E5: terminal job, confirmed Apply and retention gates; E6: fingerprint and guarded restore tests | Relevant newer values, missing evidence, ineligible products and uncertain outcomes can refuse restoration. Does not reverse external effects; equality cannot detect every intervening edit. | VERIFIED |
| Simple products and variations | E7: exact core class/publication/parent eligibility; variation and parent-drift tests | No direct variable-parent price edits, custom subclasses, subscriptions, bundles or all extensions. | VERIFIED |
| Regular/Sale Price operations, including Clear and relative sale discount | E7, E8: operation/eligibility/calculation and Apply/Undo tests | Clear means blank, not zero; dates remain. Relative discount uses each frozen Regular Price and conflicts if that basis changes. New sale targets must pass eligibility/policy. | VERIFIED |
| Up to 1,000 selected final targets per new job in tested configurations | E9: support ceiling; boundary/selection/acceptance tests | Variations count individually. Not unlimited scale, a throughput promise or arbitrary hosting certification. Exclusions do not authorize unbounded source discovery. | VERIFIED |
| Set safety limits before approval | E10: policy evaluator, plan-level blocker and tests | Product-count, increase/decrease caps and zero-target control; warnings alone do not block. A blocker refuses the whole Preview. Ratio caps lack an empty-sale baseline; zero/count checks still apply. | VERIFIED |
| Automatically protects all edits; guaranteed Undo | E3, E6 explicitly limit the checks/restoration | Omit. Raw writers, third-party effects and edits returning checked values to their prior state are not comprehensively observed. | UNSUPPORTED |
| Saved presets; Repeat Previous Job available in this release | Open [PR #241](https://github.com/MrDarkRoot/WriteLeash/pull/241) / [PR #242](https://github.com/MrDarkRoot/WriteLeash/pull/242); absent from reviewed main | Not advertised here. Recheck accepted final source and tests before adding either feature. | PENDING_FINAL_SOURCE |

### Evidence index — actual repository paths

All paths resolve against the pinned source above; function names identify the relevant behavior.

- **E1:** [Change Plan](../../wordpress/writeleash/includes/free/class-change-plan.php) (`create`, `hydrate`, `precondition`), [Job repository](../../wordpress/writeleash/includes/free/class-job-repository.php) (`approve`, `assert_binding`, `assert_item_material`); [plan unit tests](../../wordpress/tests/free/unit.php), [job integration](../../wordpress/tests/jobs/integration.php).
- **E2:** [Free Admin](../../wordpress/writeleash/includes/free/class-free-admin.php) (`process_preview`, approval handling, `render_preview_view`); [Admin integration](../../wordpress/tests/admin/integration.php), [frozen Preview CSV tests](../../wordpress/tests/admin/preview-csv-integration.php), [browser journey](../../wordpress/tests/admin/regression-browser.cjs).
- **E3:** [Change Plan](../../wordpress/writeleash/includes/free/class-change-plan.php) (`precondition`), [Apply mutator](../../wordpress/writeleash/includes/free/class-woo-price-mutator.php) (`apply`: compares locked stored target field before save), [job worker](../../wordpress/writeleash/includes/free/class-job-worker.php); [durable integration](../../wordpress/tests/durable/integration.php) (external edit, zero saves, target-equality refusal).
- **E4:** [browser journey](../../wordpress/tests/admin/regression-browser.cjs) (independent $120 preserved / $80 applied values, durable conflict); [conflict recovery tests](../../wordpress/tests/admin/recovery-integration.php) (fresh $120 +10% = $132 Preview, no write until approval, later drift conflicts); [recovery unit](../../wordpress/tests/admin/recovery-unit.php).
- **E5:** [Undo repository](../../wordpress/writeleash/includes/free/class-undo-repository.php) (`initiate`, `insert_items`, `history_jobs`, retention/eligibility), [Free Admin](../../wordpress/writeleash/includes/free/class-free-admin.php) (`undo_availability`, `render_history_table`); [Undo integration](../../wordpress/tests/undo/integration.php) (History, eligibility, expiry, partial conflicts).
- **E6:** [Undo fingerprint](../../wordpress/writeleash/includes/free/class-undo-fingerprint.php) (`verify`), [Undo mutator](../../wordpress/writeleash/includes/free/class-woo-undo-mutator.php) (`restore`); [Undo integration](../../wordpress/tests/undo/integration.php), [sale-operation Undo](../../wordpress/tests/undo/sale-operations.php), [parent drift](../../wordpress/tests/undo/parent-state.php); [Undo contract](../../wordpress/writeleash/FREE-UNDO-HISTORY-CONTRACT.md) (value-equality/ABA and external-effects limits).
- **E7:** [product snapshot/eligibility](../../wordpress/writeleash/includes/free/class-product-snapshot.php) (`fresh_product`, `Product_Price_Eligibility::evaluate`), [selector](../../wordpress/writeleash/includes/free/class-product-selector.php); [variation tests](../../wordpress/tests/free/variations.php), [sale-price tests](../../wordpress/tests/free/sale-price.php), [parent-state tests](../../wordpress/tests/undo/parent-state.php).
- **E8:** [price operations/calculator](../../wordpress/writeleash/includes/free/class-price-operation.php), [sale-operation tests](../../wordpress/tests/free/sale-operations.php), [sale-operation Undo](../../wordpress/tests/undo/sale-operations.php); accepted [PR #235](https://github.com/MrDarkRoot/WriteLeash/pull/235) ([review record](../review/207/IMPLEMENTATION.md)). Optional Price Endings are also merged in [PR #237](https://github.com/MrDarkRoot/WriteLeash/pull/237); [review record](../review/208/IMPLEMENTATION.md).
- **E9:** [support contract](../../wordpress/writeleash/includes/free/class-free-support-contract.php) (`MAX_JOB_PRODUCTS`, `assert_job_size`), [support boundary tests](../../wordpress/tests/acceptance/support-boundaries.php), [acceptance journey](../../wordpress/tests/acceptance/journey.php), [selection integration](../../wordpress/tests/admin/selection-integration.php); [accepted acceptance record](../../wordpress/tests/acceptance/EVIDENCE.md), [exclusion tests](../../wordpress/tests/free/exclusions.php).
- **E10:** [safety policy](../../wordpress/writeleash/includes/free/class-safety-policy.php) (`Policy_Evaluator::item`, `plan`), [Change Plan](../../wordpress/writeleash/includes/free/class-change-plan.php) (`compute`, summary/blocking); [unit policy boundaries](../../wordpress/tests/free/unit.php), [sale-operation policy tests](../../wordpress/tests/free/sale-operations.php).

Accepted merged-feature receipts include [#235 CI](https://github.com/MrDarkRoot/WriteLeash/actions/runs/38015215444),
[#237 CI](https://github.com/MrDarkRoot/WriteLeash/actions/runs/38023035749),
[#238 CI](https://github.com/MrDarkRoot/WriteLeash/actions/runs/38030920949) and
[#231 review/CI record](../review/231/IMPLEMENTATION.md).
These are historical source-specific receipts, not reruns on the marketing commit.

## Authentic screenshot audit

Directly inspected all three original PNGs. Provenance is in
[visual-assets-v0.1.md](../visual-assets-v0.1.md) and the
[asset inventory](../../wordpress/release/assets-122/proof.json).
No images or product values changed.

| File | What it actually shows | Accuracy against reviewed Admin source | Caption / recapture decision |
| --- | --- | --- | --- |
| `screenshot-2.png` | Set Sale Price to $20; five products, two variations; four proposed changes, one invalid-price skip; warnings and approval | Core Preview remains accurate. Historical screen lacks current header logo, Download Preview CSV and Exclude individual products controls; optional range/refinement context is not pictured. | Use Block 1 caption. Optional targeted recapture for a current full-screen v0.2 view. Do not present this image as proof of CSV, exclusions or all new controls. |
| `screenshot-5.png` | Real crop: expected $100/current $120/planned $80; “Not changed”, review/new Preview link, two other changed rows | Behavior remains accurate. Historical headers “Expected / Current / Planned” now read “Regular price expected / now / planned”. Crop does not show the newer conflict re-preview action. | Use Block 2 caption. Retain as a historical conflict example; optional recapture if exact current labels/actions are wanted. |
| `screenshot-6.png` | History table with task/operator/time, outcomes, Undo availability/expiry, progress and job CSV links; unfinished and unapproved jobs show Undo unavailable | Table structure and availability match current rendering. Header lacks current logo. Shows availability only; no completed Undo is pictured. | Use Block 3 caption. Reusable as a History example; optional header refresh only. |

These differences do not require a design dependency for the three behavior
blocks. Label the images as real example captures, not captures of the final
v0.2 release. #240 decides whether its final exact-UI asset requirements need
a targeted recapture; this document does not waive those requirements.

## Copy handoff and final-source caveats

- **#244:** Hero, scope line, three blocks/captions, six FAQ answers and five benefit statements are candidate copy ready to reuse. #244 owns listing wording/SEO/tags and actual `readme.txt` edits. Keep field-specific limitations near broad claims.
- **#246:** Reuse the Regular Price $100 → planned $80 → newer $120 scenario and screenshot 5; use screenshot 2 for review-before-approval and screenshot 6 for History/Undo availability. No launch date, live listing, customers, rankings or campaign results are asserted.
- **#240:** Core Preview/conflict/History/eligible Undo copy is supported on the pinned main. Clear/relative sale, endings, Preview CSV, exclusions and price range are merged, but this is not confirmation they are in the final chosen ZIP. Presets (#230/#241) and Repeat (#232/#242) are unmerged and omitted from merchant claims. Reconcile final feature inventory, copy and assets against the selected source before reuse. Do not reopen a frozen artifact for this document.

The existing listing's “Use Set to add a first Sale Price” / five-operation
summary does not fully describe merged relative sale/Clear behavior. Historical
contracts also retain earlier deferred-selector statements. This document uses
current implementation, not those obsolete passages. Do not copy the old claim
matrix wholesale as a v0.2 feature inventory. No listing, release metadata,
manifest, ZIP or SVN changes are included here.

The latest inspected [#240 coordination comment](https://github.com/MrDarkRoot/WriteLeash/issues/240#issuecomment-6106542786)
still requests the founder-selected exact final source; no final freeze was
established by this review. Marketing preparation can finish independently.

## Verification and founder review

- Completed: inspected source/tests, merged #235/#237/#238 and current main; reviewed #245 and reused #192/#195/#196/#200 materials; inspected all three screenshots against current Admin rendering; checked links/files, block lengths, six-question FAQ and whitespace.
- Not executed: PHP documentation/claim guards or runtime suites; this environment has no PHP executable. Existing `wordpress/tests/release/readme-validate.php` and `claim-matrix-audit.php` validate the unchanged listing/matrix, not this new document. No runtime PASS is claimed for this task; no unrelated expensive matrix was started.
- No blocker to reviewing/reusing the prepared copy. Final release-source acceptance and any exact-UI recapture remain with #240.

Founder review checklist:

- [ ] Approve the headline with its scope line and conditional-Undo wording.
- [ ] Approve the three blocks, six FAQ answers and original-image captions.
- [ ] Choose whether a current full-screen capture is needed for the final release.
- [ ] Hand accepted copy to #244/#246; recheck only claims affected by final source changes.

Founder retains GO/NO-GO. This preparation does not merge, publish, release or close #245.
