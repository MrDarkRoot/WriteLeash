# #244 — WordPress.org SEO brief and listing handoff

Prepared and public sources checked **2026-10-11** for
[#244](https://github.com/MrDarkRoot/WriteLeash/issues/244).
Base: latest fetched `origin/main`, **c291cdb596b7d5b6f2a0345d7f1ac91070619794**,
which includes merged #247. Reuses [#245 safety messaging](245-safety-messaging.md),
[#121 claim matrix](../../wordpress/release/CLAIM-MATRIX.md),
[#192 launch copy](../launch-v0.1.md), #195 [asset provenance](../visual-assets-v0.1.md)
and #196/#200 listing/README refinements. This is source-backed marketing
preparation, not a distributed ZIP certification or publication approval.

## Official guidance

| Official source, checked 2026-10-11 | Applied decision |
| --- | --- |
| [How your readme.txt works](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/) | Preserve recognizable sections, plain short description under 150 characters, at most five tags and concise readme. Stable Tag determines the served tagged readme; #240 must reconcile the final artifact. |
| [Plugin assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/) | Six numbered captions match real screenshot-1.png through screenshot-6.png. Directory assets belong in the top-level SVN assets directory; this task does not stage SVN. |
| [Detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) | Accurate bounded claims, no keyword stuffing, competitor-name tags or unsupported guarantees. Keep the WriteLeash title and plugin identity. |
| [Official readme validator](https://wordpress.org/plugins/developers/readme-validator/) | Validate final published branch readme; record exact response in the Draft PR alongside repository checks. |

## Search intent and keyword strategy

**Primary phrase: WooCommerce bulk price editor.** Merchants want to change
many catalog prices with a reviewable result, including sales and variations.
Use this once in the short description; introduce the natural verb form in
the opening. Keep the product promise: **Change WooCommerce prices. Protect
newer edits.** Do not add keywords to the official plugin name.

| Secondary phrase | Merchant intent / placement |
| --- | --- |
| Bulk edit WooCommerce prices | Set or adjust many stored prices; opening description. |
| Bulk edit sale prices | Start, change or clear a sale; operations and FAQ. |
| Variation prices | Price specific variations or children of a variable product; scope and FAQ. |
| Preview price changes | Inspect exact targets and skips before approval; Preview heading and CSV FAQ. |
| WooCommerce price undo | Recover eligible job prices; History/Undo section with explicit conditions. |

**Unverified keyword hypotheses:** These phrases plausibly describe merchant
queries; none has measured search volume, ranking, difficulty, conversion or
market share. Preview/conflict/Undo wording may distinguish the purchase intent,
but its discovery or conversion effect is unmeasured. No SEO result is promised.

**Recommended tags (five):** `woocommerce`, `bulk edit`, `prices`, `variations`,
`undo`. These describe actual source functionality. Replace vague `safety` with
`variations`; no competitor names or extra tag variants.

## Comparable listings: public evidence, not runtime audits

All four actual WordPress.org pages were inspected on 2026-10-11. Free/paid
boundaries below are vendor listing statements at that date; competitor
implementation, conflict behavior and advertised performance were not tested.

| Plugin and verified listing | Title / description and intent | Useful lesson and supported positioning difference |
| --- | --- | --- |
| [PW WooCommerce Bulk Edit](https://wordpress.org/plugins/pw-bulk-edit/) | Names WooCommerce bulk editing directly; describes catalog work, live preview, variations and undo before saving. Price adjustment intent is easy to recognize. Sale Price features are listed under Pro. | Lead with a familiar task and make the review step visible. WriteLeash source includes Sale Price operations in Free and conditional Undo after Apply safely finishes; do not equate that with PW's pre-save undo. |
| [BEAR — Bulk Editor for WooCommerce Professional](https://wordpress.org/plugins/woo-bulk-editor/) | Current title also promotes its AI/MCP assistant. Broad catalog filtering/editing, variations, CSV and History rollback; regular/sale fields appear in its Free section with count limits. | Concrete operations and a distinct Free inventory help comparison. WriteLeash should focus on reviewed price targets and bounded conflict handling rather than suggesting general catalog or AI tooling. Its conditional Undo does not inherit BEAR's broad rollback claims. |
| [Smart Manager](https://wordpress.org/plugins/smart-manager-for-wp-e-commerce/) | Title emphasizes WooCommerce bulk editing and a spreadsheet. Description spans products, orders and users. Lite lists regular/sale fields and variations; bulk update, scheduling and Undo/History are promoted in Pro. | Explain scope and edition limits plainly. WriteLeash's price-focused Free Preview/Apply/eligible Undo workflow is different from a general store-data grid; it does not edit orders, schedule updates or promise the vendor's scale claims. |
| [Bulk Edit Products for WooCommerce — WP Sheet Editor](https://wordpress.org/plugins/woo-bulk-edit-products/) | Title and opening promise spreadsheet product editing/creation; Free includes simple-product regular/sale prices, while variations are listed under Premium. | Task examples and editable-field FAQs answer merchant questions quickly. WriteLeash source includes supported core variations in Free, with a narrower approved-price workflow rather than product creation/general field editing. |

Do not claim uniqueness or that competitors lack newer-price protection; their
public copy alone cannot establish that. The strongest defensible difference
is WriteLeash's documented workflow and limits, demonstrated by accepted tests.

## Listing decisions and evidence

Edit the existing [readme.txt](../../wordpress/writeleash/readme.txt), preserving
metadata, section structure and existing changelog. Short description: 99 ASCII
characters. Natural task headings, scannable operation/range/exclusion sections,
merchant FAQ and qualified historical captions replace repetitive v0.1 copy.

| Claim decision | Source and accepted tests |
| --- | --- |
| Preview + explicit approval; conflict-aware Apply and History; eligible guarded Undo | #245 E1–E6: Change Plan, Admin, repositories and mutators; Free unit, Admin integration/browser, durable integration and Undo integration/parent-state tests. |
| Simple products, variations, Regular/Sale operations | #245 E7; free sale-price/variation and parent-state tests. Scope stays core published products and base currency. |
| Clear Sale / relative Sale Discount and optional Price Endings | Merged #235/#237; operation/calculator and frozen plan; free/Admin/Undo/acceptance sale-operations tests; [#208 record](../review/208/IMPLEMENTATION.md). Clear is blank, dates unchanged; relative discount checks reviewed Regular Price; endings respect precision and final policy. |
| Inclusive Price Range | Accepted #234 commit `778af88` (included in merged PR #239); selector and `wordpress/tests/free/price-range.php`: inclusive endpoints, own variation prices, blank sales excluded. |
| Exclude individual products | Issue #231 via merged PR #239; free/Admin/acceptance exclusion tests: fresh reviewed plan, excluded targets absent from Apply/Undo; limits remain. |
| Frozen Preview CSV | Issue #233 via merged PR #238; Admin Preview CSV unit/integration and acceptance Preview CSV tests; read-only saved Preview, not live catalog or approval. |
| Tested 1,000-target envelope and compatibility | #245 E9 and claim matrix: support boundaries, acceptance journey and exact compatibility fixtures. No unlimited scale, arbitrary product types/extensions, multisite or real-host certification. Remove the older all-WordPress-7.x support sentence; retain exact exercised fixtures without changing metadata. |
| Presets and Repeat Previous Job | #241/#242 remain open/unmerged at research handoff; omitted. Recheck selected source before #240 publishes, then inspect accepted tests if merged. |

The added [claim-matrix addendum](../../wordpress/release/CLAIM-MATRIX.md)
records source/test paths and supersedes older sale-operation wording. The old
matrix's 0.1 metadata baseline remains historical; current listing metadata is
unchanged. No new runtime acceptance run is implied by source inspection.

**Conflict example:** Otherwise eligible published core product, USD/two decimals,
limits allowing $100 → $80; before Apply reaches it, current Regular Price is
$120. Plan precondition and locked target-field comparison conflict, preserving
$120. Durable/browser/recovery tests support this. Fresh Preview requires a new
approval. Checks compare relevant current values, not every intervening edit;
name/SKU/category alone do not conflict. Regular edits preserve later sales
where valid; Clear/relative discount check the frozen Regular Price basis.

**Undo:** Available only after Apply safely finishes, within the available window
and with eligible evidence/current values. History availability is not guaranteed
restoration. Sale Undo checks its recorded Regular Price. No complete rollback
of orders, completed sales, hooks, emails or remote/external effects.

## Authentic screenshot captions and #240 recapture guidance

Reuses #245 audit for 2/5/6; all six original PNGs were inspected. Only caption
fields in [asset proof](../../wordpress/release/assets-122/proof.json) are synced
because the repository audit requires an exact match. No capture facts, hashes,
timestamps or PNGs changed. No synthetic Admin images were generated.

| Asset | What it shows / caption decision | Targeted recommendation for #240 |
| --- | --- | --- |
| screenshot-1.png | Historical name/SKU/category selection; screenshot text says subcategories are excluded. Caption explicitly says earlier example, with current optional descendants explained. | Prioritize authentic recapture of current category/operation controls; old text conflicts with the new optional descendants behavior. |
| screenshot-2.png | Actual Set Sale $20 Preview, five products/two variations, four changes/one skip. | Optional current full-screen Preview capture for logo, CSV and exclusion controls. Existing image does not prove those controls or range/endings. |
| screenshot-3.png | Actual 24-product job, eight changed/16 remaining, Resume/reload instructions. Caption states it predates live progress. | Recapture if presenting live progress; do not caption as a 1,000-product run. |
| screenshot-4.png | Completed Regular Price decrease, four changes/one skip; no completed Undo. | Reusable results example; optional header refresh. |
| screenshot-5.png | Expected $100/current $120/planned $80, Not changed. | Reusable conflict example; optional current field-specific headings/re-preview controls capture. Do not claim those newer controls are pictured. |
| screenshot-6.png | History outcomes and Undo availability/expiry, including unfinished/unapproved jobs. | Reusable availability example; optional header refresh. Does not show completed Undo or Repeat. |

Caption qualification permits review now; any recapture belongs to #240's
chosen source, real test data and evidence process, and must not block release.

## Proposed v0.2 changelog handoff — #240 owns integration

Location: `wordpress/writeleash/readme.txt`, existing `= 0.2.0 =` section;
reconcile `wordpress/writeleash/changelog.txt` and final artifact as #240 requires.
Keep existing accepted live-progress/subcategory/conflict-recovery/cache/i18n
entries. Proposed additional bullets, only if present in the chosen final ZIP:

* Added Clear Sale Price and a sale discount from each reviewed Regular Price; sale dates are preserved.
* Added optional Price Endings, checked against store precision, eligibility and safety limits.
* Added inclusive stored-price range filters and individual exclusions before approving a refreshed Preview.
* Added read-only CSV download of the saved Preview before approval.

Old v0.1 passages that imply only Set creates a first sale or all operations
need an existing sale are superseded by merged relative discount/Clear behavior.
Historical screenshots do not establish new controls. Broader launch/contract
cleanup is deferred to avoid unrelated documentation churn.

## Verification and release coordination

Local formatting/traceability review checks description length, tags, numbered
caption/proof equality, six real asset references, unchanged version headers,
relative Markdown links and whitespace. PHP is unavailable in this workspace;
repository readme/claim/asset gates run in GitHub PR_FAST on the exact final HEAD.
The Draft PR records official validator response and exact-HEAD CI job statuses
as PASS/FAIL/SKIPPED/BLOCKED/NOT_TESTED, without presenting skipped runtime
matrices as fresh acceptance. CI and validator results belong in the PR receipt
because this file cannot embed its own final commit hash.

[#240](https://github.com/MrDarkRoot/WriteLeash/issues/240) and its available
comments did not establish a final-source/artifact freeze at this review.
No runtime, PHP version/header, WRITELEASH_VERSION, Stable Tag, compatibility
numbers, builders, manifests or SVN files change. No unfinished PR is cherry-picked.
#240 selects the final source/version/ZIP and performs release validation;
marketing changes alone do not prove distributed features. If a freeze occurs
before integration, keep this as a proposed patch: integration requires an
authorized rebuild and retest, not editing frozen files. Marketing must not
block #240. Founder retains GO/NO-GO; this task never merges, publishes or closes #244.
