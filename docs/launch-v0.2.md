# WriteLeash Free v0.2.0 — organic launch content

Prepared **2026-10-11** for [#246](https://github.com/MrDarkRoot/WriteLeash/issues/246).
Reviewed latest fetched `origin/main`: **898c7602f86edcc2647cb619fc249f3ddd28629e**.
The specified #244 merge is an ancestor of this source (and is the current base).
#245/PR #247 and #244/PR #248 are merged. No existing launch-v0.2 document was
present. This adapts [#196's v0.1 launch work](launch-v0.1.md), [#195's genuine
assets](visual-assets-v0.1.md), the [current listing](../wordpress/writeleash/readme.txt),
[#244 SEO brief](marketing/244-wporg-seo-brief.md) and [#245 safety evidence](marketing/245-safety-messaging.md).
The older product README/launch text is context, not the preferred current claim source.

**Marketing preparation; zero confirmed customers.** Finished English drafts
follow. No announcement, submission, outreach, release or customer result has
occurred through this task. Founder chooses whether, when and where to publish.

## 1. Source, artifact and public distribution are separate

Public checks on 2026-10-11 (latest-download check at 10:37 UTC / 17:37 Vietnam):

| Status | Observed evidence / decision |
| --- | --- |
| Implemented in main | Preview/approval, conflict-aware Apply/History, eligible Undo, core simple/variation Regular/Sale operations, Clear/relative sale, Price Endings, inclusive range, exclusions and frozen Preview CSV are in the reviewed source, with accepted test references below. |
| Included in final WordPress.org artifact | **NOT_VERIFIED here.** [#240](https://github.com/MrDarkRoot/WriteLeash/issues/240) owns selected source, freeze, exact ZIP, acceptance, metadata and SVN. Available issue/comments did not establish a final artifact receipt. A provisional Woo Marketplace ZIP is a different channel. |
| Public listing/version | GET [wordpress.org/plugins/writeleash/](https://wordpress.org/plugins/writeleash/) redirected to [search/writeleash/](https://wordpress.org/plugins/search/writeleash/), HTTP 200, search reported no matches. No public plugin version was displayed. Approval is founder-reported in #240, not public distribution evidence. |
| Public plugin-information API | [Slug query](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=writeleash) returned HTTP 404. |
| Usable public ZIP | [Latest ZIP](https://downloads.wordpress.org/plugin/writeleash.zip) and [0.2.0 ZIP](https://downloads.wordpress.org/plugin/writeleash.0.2.0.zip) returned HTTP 404. No usable directory download verified. These are diagnostic URLs, not install links. |
| Presets / Repeat Previous Job | PR [#241](https://github.com/MrDarkRoot/WriteLeash/pull/241) and [#242](https://github.com/MrDarkRoot/WriteLeash/pull/242) are open/unmerged; absent from this source and omitted from all finished copy. |

The directory and ZIP results were checked with direct HTTP GETs and redirect
inspection; the web-search service could not retrieve the slug page. Do not
interpret an inaccessible search result alone as proof of non-distribution.
Together, the observed checks do **not** verify a public release; no cause or
release date is inferred.

**Publishing gate for every draft:** retain `[VERIFIED_PLUGIN_URL]` until #240
confirms the public v0.2.0 version and a usable directory download. Then replace
it with the verified listing URL, reconcile every advertised feature with that
ZIP and confirm its supported scope. Do not publish a placeholder or describe
main's features as already distributed. If final source differs, edit the drafts
accordingly; do not change or reopen #240's frozen files for this document.

## 2. Eight organic opportunities, with actual rules

All channel/rule URLs below were inspected **2026-10-11**. Priorities are an
editorial judgment about audience fit and effort, not measured reach or conversion.
**VERIFIED** means an explicit public route permits the stated content class;
**RESTRICTED** means a known limitation rules out some/all promotion;
**UNKNOWN** means the specific account access or permission has not been established.
No founder membership, posting history, enabled repository Discussions, moderator
approval or editor acceptance is assumed. Account-level access is UNKNOWN for all
external communities. Never substitute a promotional post for a helpful answer.

| Channel / direct URL | Audience and relevance | Rule source / permission | Recommended content and priority |
| --- | --- | --- | --- |
| [WriteLeash GitHub project](https://github.com/MrDarkRoot/WriteLeash) | WordPress developers, agencies and technically comfortable freelancers can inspect the actual source and limitations. | [GitHub acceptable-use policy, sections 4/10](https://docs.github.com/en/site-policy/acceptable-use-policies/github-acceptable-use-policies): project-related description/README images and links permitted; bulk promotion, artificial stars and advertising in others' accounts prohibited. **VERIFIED** project-documentation route; not a claim that a GitHub release or Discussions exists. | **HIGH:** founder-approved project announcement/documentation using Post A and a tutorial. Directly reviewable evidence; no dependency on third-party editorial selection. Any actual release remains #240/founder-owned. |
| [WooCommerce Community Slack](https://woocommerce.com/community-slack/) | Woo developers, agencies and store builders; price-review workflows are directly relevant. | [Official community code](https://developer.woocommerce.com/woocommerce-community-code-of-conduct/): promotion only in `#self-promotion`; participation should outweigh promotion, disclose authorship, no affiliates, cross-channel copies or cold DMs. **RESTRICTED**, permitted channel identified; access/history UNKNOWN. | **HIGH once eligible:** Post C or B in `#self-promotion` with “I built this.” Do not post launch copy in developer/help/marketplace channels or manufacture participation. Strong fit and a specific permitted location. |
| [The WP Weekly — Submit](https://thewpweekly.com/submit/) | WordPress professionals, agencies and freelancers; newsletter includes tools/launches. | Submission page invites WordPress articles, guides, news and new products; publication depends on editorial quality/discretion. **VERIFIED** submission route; inclusion and form usability for founder UNKNOWN. | **MEDIUM:** Post A as a concise release-news submission, or Tutorial 3 as a guide after hosting it. No paid slot requested and no implied feature placement. One submission can convey the workflow without a campaign. |
| [WP Content — Submit](https://wpcontent.io/submit/) | WordPress builders, developers and agencies discovering articles/tutorials. | Same page's guidelines: English; no direct plugin/product-page submissions; release articles allowed; no bulk submissions/spam; login needed and first submissions moderated. **RESTRICTED**, article route verified; access/approval UNKNOWN. | **MEDIUM:** one hosted Tutorial 1 or 3, or a hosted release article containing Post A, after the public release check. Submit that article URL, not the plugin URL or this placeholder. Useful education fits better than an ad. |
| [r/WooCommerce](https://www.reddit.com/r/woocommerce/) | Merchants and Woo builders with concrete price/variation problems. | Sidebar rules 1/5 prohibit promotional posts; relevant comments may recommend one's own solution when directly answering the question, with links subject to moderation. No affiliate/monetized links. **RESTRICTED**; no launch post. | **MEDIUM for help, LOW for launch:** independently answer a real matching question, disclose authorship if mentioning WriteLeash, explain limits; add a normal non-affiliate link only where allowed and directly useful. Tutorial 2/3 provides background knowledge, not a pasted ad or manufactured question. |
| [r/WordPress](https://www.reddit.com/r/Wordpress/) | Broad WordPress users/developers, including Woo maintainers. | Sidebar rule 1 permits useful free/open-source projects once via WordPress.org/GitHub with `[PROMO]` title. Rules 9/14 also prohibit LLM/AI-generated content; no blog/affiliate/shortened links. **RESTRICTED**: these AI-prepared drafts are not eligible for direct posting there. | **LOW / HOLD:** no copy-paste of any draft. Only consider a genuinely founder-authored post consistent with current rules, confirmed one-time eligibility and any necessary moderator clarification. Do not disguise AI text, evade rules or claim changing a title cures the restriction. |
| [WooCommerce WordPress.org support forum](https://wordpress.org/support/plugin/woocommerce/) | Merchants with existing Woo issues; useful support builds understanding, not an advertising placement. | [Forum guidelines](https://wordpress.org/support/guidelines/): no plugin/service advertising or feedback solicitation; bounded relevant external-link rules; unvetted AI responses can be removed. **RESTRICTED**: no launch/tutorial advertisement. | **LOW for distribution:** voluntary, specific, checked support replies only. Do not seed support topics, redirect unrelated problems or use tutorials as promotional links. WriteLeash support belongs in its own appropriate support location once available. |
| [DEV Community](https://dev.to/) | Developers/freelancers; review-before-write concepts are educationally relevant, less merchant-focused. | [Terms §11](https://dev.to/terms), [code](https://dev.to/code-of-conduct), [AI guidance](https://dev.to/guidelines-for-ai-assisted-articles-on-dev): substantial original content, not primarily promotion/backlinks; AI content needs disclosure and must not promote a business/program, including one's own. **RESTRICTED**: these WriteLeash launch/tutorial drafts are not recommended for DEV. | **LOW / HOLD:** a separate, founder-understood, nonpromotional educational article might qualify under the rules; no repackaged product pitch, AI-generated comments or hidden AI involvement. Included to prevent assuming all tutorial platforms permit product marketing. |

**Practical first choice:** project documentation, then Woo Slack only if the
founder meets its participation/access rules; optionally one editorial/article
submission. The restricted channels remain useful research, not a checklist to
post everywhere. No mass DMs, automated accounts, cross-channel spam, unsolicited
outreach, review incentives or engagement manipulation.

## 3. Three finished launch posts

These adapt #196's merchant/agency framing with #244's preferred public promise
and #245's bounded evidence. Text inside each draft is ready for founder review;
channel notes and captions are separate editorial instructions. Use only after
the publishing gate above passes.

### Post A — public WordPress/WooCommerce announcement

**Format/channel:** project announcement on the founder-controlled GitHub project;
also suitable as release-news text for The WP Weekly's submission route. Do not
publish a GitHub release through this task. Not a ready-to-paste r/WordPress post.

**Draft begins**

**WriteLeash Free v0.2.0: Change WooCommerce prices. Protect newer edits.**

Bulk price changes deserve a review before they reach your store. Another edit
can also happen between that review and Apply.

I built WriteLeash, a free WooCommerce bulk price editor. Select supported
products by name, SKU or category, review exact before-and-after prices in
Preview, then explicitly approve Apply. It handles Regular Price and Sale Price
for standard simple products and variations, with background processing and
Resume for interrupted work.

If a relevant current price or checked setting no longer matches Preview,
WriteLeash preserves the conflicting newer value and reports a conflict.
History shows the outcomes, and Undo can restore eligible prices after Apply
safely finishes, within the available window and while current state permits.

The tested scope is up to 1,000 selected products per new job. It does not edit
sale dates, stock or orders, support arbitrary extension product types, or undo
orders and external effects. Start on staging with a backup and a small selection.

Plugin details and supported scope: [VERIFIED_PLUGIN_URL]

**Draft ends**

**Optional image:** [screenshot-5.png](../wordpress/assets/screenshot-5.png).
Caption: “Earlier real Regular Price example: reviewed $100, planned $80, current
$120 remains unchanged.” It shows the conflict result, not all v0.2 controls.

### Post B — WooCommerce merchants

**Format/channel:** concise merchant post in Woo Slack's `#self-promotion`, only
with permitted access/participation; otherwise founder-controlled project copy.
Never use it as an r/WooCommerce promotional post.

**Draft begins**

**Review your WooCommerce price changes before you Apply.**

I built WriteLeash for the moment when you need to change a group of prices
without losing track of what you reviewed.

Choose Regular Price or Sale Price for supported simple products and variations.
Preview shows the proposed prices, warnings and skipped items. You approve the
reviewed changes separately before Apply starts.

What if someone changes a price while you are reviewing? If that relevant current
price or a checked setting no longer matches your Preview, WriteLeash leaves the
conflicting newer value alone. Review the conflict and create a fresh Preview
before deciding whether to change it.

History keeps the results visible. Undo is conditional: Apply must safely finish,
the window must still be open, and each price must remain eligible to restore.

Try a small selection on staging with a backup first. The tested limit is up to
1,000 selected products per new job, using standard simple products/variations
and stored prices in the base store currency. Sale dates, orders and stock are
not changed; Undo does not reverse purchases or external effects.

Details and supported scope: [VERIFIED_PLUGIN_URL]

**Draft ends**

**Image:** [screenshot-2.png](../wordpress/assets/screenshot-2.png).
Caption: “Earlier real Sale Price Preview: review values, variations, warnings
and skipped items before approval.” It does not picture newer CSV/exclusion controls.

### Post C — agencies and WooCommerce freelancers

**Format/channel:** Woo Slack `#self-promotion` when eligible, or founder-controlled
project announcement. Useful agency framing; not a message to invented clients.

**Draft begins**

**A price-change review you can check before updating a client catalog.**

A client store may keep changing while you prepare a bulk update. I built
WriteLeash to make the price plan reviewable and avoid blindly overwriting
conflicting newer relevant prices.

For supported published standard simple products and variations, choose Regular
Price or Sale Price, select the products and Preview the exact proposed values.
Review warnings and skips, then approve Apply separately. If a relevant current
price or checked setting no longer matches that review, the item is reported as
a conflict and its newer value is preserved.

History keeps job outcomes and Undo availability visible. Undo can restore
eligible prices only after Apply safely finishes, within its available window
and where current state allows it. It does not reverse orders, completed sales
or other plugins' external effects.

Test the intended operation on staging and take a backup before a larger update.
The tested envelope is up to 1,000 selected products per new job, in the base
store currency. Sale-date editing and arbitrary custom product types are outside
the supported scope; compatibility with every Woo extension is not promised.

Free plugin details and supported scope: [VERIFIED_PLUGIN_URL]

**Draft ends**

**Image:** [screenshot-6.png](../wordpress/assets/screenshot-6.png).
Caption: “Earlier real History view: job outcomes and eligible Undo availability.”
Availability is not proof that all prices will restore; the image shows no completed Undo.

## 4. Optional personal introduction

Use only in an already welcome conversation or other explicitly permitted
context. Founder customizes it manually; no recipient is identified, no contacts
are collected and no sending is required. Never use for cold Woo Slack DMs.

**Draft begins**

Hi [Name] — I'm an independent developer working on WriteLeash, a free WooCommerce
bulk price editor. It lets you review price changes before Apply and preserves
conflicting newer relevant prices instead of blindly overwriting them. History
and conditional Undo help you review what happened. If this fits the catalog work
we're discussing, the supported scope is here: [VERIFIED_PLUGIN_URL]. No pressure
to try it; a backup and a small staging test should come first.

**Draft ends**

Optional pairing: screenshot 5 with Post A's historical conflict caption, only
if an image is welcome in that conversation.

## 5. Three merchant tutorial drafts

**Publication context:** These walkthroughs describe the reviewed source, not an
installed final ZIP tested by this task. Publish after the distribution/artifact
gate, with the verified install link and any required final-source corrections.
Suitable full-text home: founder-controlled project documentation or a publication
the founder controls; a hosted English article can be submitted individually to
WP Content or The WP Weekly under their rules. Do not paste these promotional,
AI-prepared drafts into DEV, r/WordPress or support forums.

### Tutorial 1 — How to Preview Bulk Price Changes in WooCommerce

Changing prices starts with choosing what you mean to change. A Preview gives
you a chance to catch the wrong products, an unexpected percentage or an invalid
price before approving the update. In WriteLeash, creating a Preview does not
change prices. Apply is a separate decision.

**Before you start:** Install the verified WriteLeash Free v0.2.0 release from
[VERIFIED_PLUGIN_URL], with a supported WooCommerce/WordPress setup. Use staging
and a backup first. This walkthrough covers stored prices in your base store
currency on supported published standard simple products and variations of
published standard variable products. Begin with a few familiar items.

1. **Open Products → Bulk Prices.** Select by product name, exact SKU or category.
   Selecting a variable product includes its variations; selecting a specific
   variation keeps the task focused. Category selection uses direct members
   unless you explicitly include nested subcategories. Check the selected list
   rather than assuming every item in a category is eligible.
2. **Choose the price field and operation.** Regular Price and Sale Price are
   different stored values. For a first small test, use a clear target such as
   Set Regular Price to 80 on an eligible staging product whose regular price
   is 100 and whose other prices allow that result. Percentage and fixed-amount
   changes are also available; check which field you selected.
3. **Set your limits before Preview.** Choose the product-count, price-increase,
   price-decrease and zero-price settings appropriate to this task. A low trial
   count makes the review manageable. Limits cannot raise the supported ceiling:
   up to 1,000 selected products per new job in the tested configuration.
4. **Create Preview and read every page.** Check identity, variation attributes,
   stored before price and proposed after price. Distinguish rows that will change,
   rows already at their target, and explained skips. A skipped invalid or
   unsupported item is not a successful update. Warnings deserve review; a
   safety-limit blocker can prevent the whole Preview from proceeding.
5. **Correct the plan before approval.** If the field, operation or selection is
   wrong, create a new Preview. For individual exclusions, confirm the refined
   list and review its updated Preview before approving it. Do not rely on an
   earlier screen or an unconfirmed exclusion.
6. **Download Preview CSV if that control is in your verified release.** Keep it
   for your own review if useful. The download reflects the saved Preview; it
   does not refresh catalog prices, approve anything or Apply changes. Protect
   catalog information when sharing files; do not publish client data.
7. **Approve only the final reviewed Preview.** Apply uses the reviewed products
   and targets. Changes to your selection or settings require a new Preview.
   Follow progress and check History/results afterward, including conflicts and
   skips. Do not treat the submitted approval as proof that every row changed.

**Reading the real example:** The paired screenshot shows Set Sale Price to $20
for five products, including two variations. Four changes are proposed and one
invalid stored price is skipped. Some rows have decrease warnings. These are
fixture products, not a customer result or a recommendation to use those values.

Preview is a saved review, not a live catalog lock. A relevant price or setting
can change afterward; Apply checks the relevant current state and leaves a
conflicting newer value unchanged. Stored targets are not guaranteed shopper
prices: taxes, currency/display settings and other pricing behavior may differ.
Preview and conditional Undo do not replace backups.

**Image:** [screenshot-2.png](../wordpress/assets/screenshot-2.png).
Caption: “Earlier Sale Price Preview with two variations, warnings and an explained
invalid-price skip.” CSV and exclusion instructions describe reviewed source;
those newer controls are not pictured. Current labels/header may differ.

### Tutorial 2 — Editing Sale Prices and Variation Prices More Carefully

A percentage off Sale Price is not the same as a percentage off Regular Price.
For example, with a regular price of $100 and an existing sale price of $80,
a 10% reduction of Sale Price targets $72; a 10% sale discount from Regular
Price targets $90, before any optional endings and safety/eligibility checks.
Choose the operation that matches your intended pricing rule.

**Before you start:** Use the verified Free v0.2.0 release at
[VERIFIED_PLUGIN_URL] on staging with a backup. These instructions assume the
selected release includes the reviewed sale operations, endings and range controls.
If #240 excludes a feature, remove its instructions before publication.

1. **Choose supported products.** Standard published simple products and
   variations of published standard variable products are supported. Select a
   variable parent to include its variations or select individual variations.
   The stored variation price is the target; the parent is not a price row you
   directly overwrite. Do not assume a subscription, bundle or other extension's
   custom type is covered merely because WooCommerce displays it.
2. **Decide whether you are editing Regular Price or Sale Price.** A regular
   change preserves the existing sale and its dates where the new relationship
   remains valid. A nonblank sale target must stay below Regular Price; a regular
   target must remain above an existing Sale Price. Inspect skips/refusals rather
   than forcing a result that would invalidate the other price.
3. **Pick the right operation.** Set assigns an explicit target. Fixed and
   percentage increases/decreases adjust the selected stored field. Set can add
   a first Sale Price; fixed/percentage sale adjustments require an existing
   usable sale value. A discount from Regular Price calculates from each
   product's own reviewed regular value and can create a first sale.
4. **Clear a sale deliberately.** Clear Sale makes Sale Price blank, not zero,
   and preserves the stored sale dates. It does not schedule a promotion or
   change dates for you. Clear and a relative sale discount also depend on the
   reviewed Regular Price; a changed basis before Apply becomes a conflict.
5. **Use optional Price Endings only when appropriate.** Reviewed source offers
   .99, .95, .90 and whole-number choices where store precision permits. Leave
   the default if you do not want this adjustment. Inspect the final target:
   endings can move it in a direction you did not expect, and final eligibility,
   direction and safety checks still apply. Clear ignores endings.
6. **Narrow by an inclusive stored-price range if useful.** Pick Regular Price or
   Sale Price as the basis, independently of which field you plan to edit. With
   a range of 20 to 150, both endpoints count. Each variation uses its own stored
   price; the parent's displayed range is not the basis. Blank Sale Prices do
   not match a numeric sale range, so that filter will exclude items with no sale.
7. **Set limits and review Preview.** Confirm each variation identity and final
   price, not just the parent's name. Check count/increase/decrease/zero limits,
   warnings and explained skips. Exclude individual items through the confirmed
   refinement workflow where needed and review the updated plan. Approve Apply
   separately only after this review.
8. **Review results and storefront behavior.** Check History for actual changed,
   skipped and conflicted rows. WooCommerce's own synchronization refreshes
   parent price ranges after variation changes. It does not make an unsupported
   extension compatible. Verify your storefront on staging: stored prices are
   not a guarantee of tax-inclusive, currency-converted or dynamically adjusted
   shopper prices.

The tested envelope is up to 1,000 selected products per new job in the tested
configuration, not unlimited catalogs or arbitrary hook loads. Stock, orders
and sale-date editing are outside this workflow. There is no automatic price
scheduling. Eligible Undo has state/window/evidence conditions; it does not
reverse a sale that a customer already completed.

**Image:** [screenshot-2.png](../wordpress/assets/screenshot-2.png).
Caption: “Earlier real Set Sale Price Preview showing simple products and two
individual variations.” It demonstrates the core sale/variation review, not
Clear, relative discount, Price Endings, range or exclusion controls. Screenshot
1 is not needed: its old subcategory wording would require qualification.

### Tutorial 3 — What Happens If a WooCommerce Price Changes After Preview?

A useful price review can become stale. Someone may edit a product after you
review it but before a background job reaches it. WriteLeash compares relevant
current prices and checked settings with the reviewed plan; when they conflict,
it preserves the newer relevant value rather than blindly writing the old target.

**A concrete example:** In an otherwise eligible published standard product,
with USD/two-decimal prices and limits allowing the change, you review Regular
Price **$100** and plan **$80**. A separate edit changes current Regular Price
to **$120** before Apply reaches that product. WriteLeash detects the relevant
price mismatch, reports a conflict and leaves **$120 unchanged**. Other eligible
items can continue where safe. This is an educational example from accepted
fixtures, not a claim about a customer's store.

Use this workflow when you encounter such a conflict:

1. **Open the job's progress/results from History.** Read the outcome for the
   specific product or variation. A conflict means that planned item was not
   applied; it is not a silent success at $80. The paired real capture shows
   expected $100, current $120, planned $80 and “Not changed.”
2. **Check the current product before deciding.** The $120 may be an intentional
   update. Confirm which field changed and why with your store's usual process.
   Do not remove someone else's intended edit simply to clear a warning.
3. **Create a fresh Preview if the change is still wanted.** Use the conflict-to-
   fresh-Preview workflow where available, or select the affected supported
   products again. Review their current values, operation and limits. A fresh
   Preview is a new review, not an automatic retry of the old approval.
4. **Approve separately.** Decide whether the newly reviewed target is still
   appropriate. Nothing should Apply just because you opened the conflict or
   created a new Preview. Another relevant change before the new Apply reaches
   the item can cause another conflict.
5. **Review the completed job, not only the conflict row.** History may show a
   mix of changed, skipped and conflicted items. If work is interrupted, inspect
   progress and Resume remaining work through the existing workflow rather than
   starting the same percentage operation again on all products.
6. **Consider Undo only for confirmed eligible changes.** A product left at
   $120 by this conflict has no confirmed WriteLeash change from this attempt to
   undo. For other items that did change, wait until Apply safely finishes and
   check History's available Undo window. Availability does not guarantee that
   every item can restore: current relevant state, eligibility and retained
   evidence must still permit restoration. Inspect the Undo outcomes too.

**What the protection does and does not check:** Target price, relevant product/
parent eligibility, currency and precision matter. Name, SKU and category are
reference details; changing them alone does not cause a conflict. A regular edit
preserves later sale configuration where valid. Clear Sale and a sale discount
from Regular Price also check their reviewed Regular Price basis. Checks compare
current values, not every intervening edit: a value changed away and back to the
reviewed value may not be detected.

Undo is a guarded price restoration, not a complete store rollback. Sale Price
Undo also checks its recorded Regular Price. Missing evidence, expired windows,
ineligible items, uncertain outcomes or relevant current changes can prevent
restoration. Orders, completed sales, emails, webhooks and external plugin/service
effects are not reversed. Keep backups and test intended workflows on staging.

Verified release and supported scope: [VERIFIED_PLUGIN_URL]

**Primary image:** [screenshot-5.png](../wordpress/assets/screenshot-5.png).
Caption: “Earlier real conflict: expected $100, planned $80, current $120 stays
unchanged.” Optional second image: [screenshot-6.png](../wordpress/assets/screenshot-6.png),
captioned as History/Undo availability, never completed restoration. The conflict
crop predates current field-specific headings and newer recovery controls.

## 6. Genuine screenshot pairings and limits

The original PNG contents were directly inspected for this task. Reuse #245's
audit and #244's updated listing captions; [asset proof](../wordpress/release/assets-122/proof.json)
records historical capture hashes/provenance. No image or release-asset file changes.

| Original asset | Pairings / what it actually proves | Caption boundary |
| --- | --- | --- |
| [screenshot-2.png](../wordpress/assets/screenshot-2.png) | Post B, Tutorials 1/2: Set Sale $20, five products/two variations, four proposed changes, one skip and warnings before approval. | Historical header/labels; no current logo, Preview CSV or individual exclusion controls. Not proof of Clear/relative discount/endings/range UI or an actual customer. |
| [screenshot-5.png](../wordpress/assets/screenshot-5.png) | Post A, optional introduction, Tutorial 3: $100 expected / $120 current / $80 planned conflict, newer Regular Price preserved; two other rows changed. | Historical crop/generic columns, not all current recovery controls; otherwise eligible USD example. No blanket “every edit conflicts” claim. |
| [screenshot-6.png](../wordpress/assets/screenshot-6.png) | Post C, optional Tutorial 3 second image: History outcomes, availability/expiry and unfinished/unapproved jobs. | Availability only; no completed Undo, guaranteed restoration or Repeat. Old header, fixture timestamps are not a launch date or universal retention promise. |

Do not pair screenshots 1/3 with new-control claims: #1's old text excludes
subcategories despite today's optional descendants; #3's 24-product example
predates live progress. Neither is selected here. Authentic recaptures, if #240
wants them, belong to its release process and do not block this preparation.
When copying prose outside GitHub, attach the original PNG manually or use a
verified hosted image URL; repository-relative links are not public-page embeds.

## 7. Claim boundaries and evidence map

Canonical positioning: **Change WooCommerce prices. Protect newer edits.**
Use the [merged WordPress.org listing](../wordpress/writeleash/readme.txt) for
merchant wording and [#245 E1–E10](marketing/245-safety-messaging.md) for evidence
and limitations. The [claim matrix](../wordpress/release/CLAIM-MATRIX.md) #244
addendum records updated operation/selection/CSV boundaries. Existing documents
remain untouched; older “first sale only with Set,” all-7.x support, marketing
launch tense and historical captions do not override current evidence.

| Material claim in drafts | Accepted source / tests (repository-relative evidence) |
| --- | --- |
| Preview, immutable review and independent approval | [Change Plan](../wordpress/writeleash/includes/free/class-change-plan.php), [Admin](../wordpress/writeleash/includes/free/class-free-admin.php); [Free unit](../wordpress/tests/free/unit.php), [Admin integration](../wordpress/tests/admin/integration.php), #245 E1/E2. |
| Relevant-current-value Apply conflict and $100/$80/$120 example | [Apply mutator](../wordpress/writeleash/includes/free/class-woo-price-mutator.php), Change Plan precondition; [durable tests](../wordpress/tests/durable/integration.php), [browser journey](../wordpress/tests/admin/regression-browser.cjs), [fresh Preview recovery](../wordpress/tests/admin/recovery-integration.php), #245 E3/E4. |
| History, retention/availability and guarded Undo | [Undo repository](../wordpress/writeleash/includes/free/class-undo-repository.php), [fingerprint](../wordpress/writeleash/includes/free/class-undo-fingerprint.php), [Undo mutator](../wordpress/writeleash/includes/free/class-woo-undo-mutator.php); [Undo integration](../wordpress/tests/undo/integration.php), [parent-state](../wordpress/tests/undo/parent-state.php), [sale-operation Undo](../wordpress/tests/undo/sale-operations.php), #245 E5/E6. |
| Core simple/variation scope and Regular/Sale behavior | [Snapshots/eligibility](../wordpress/writeleash/includes/free/class-product-snapshot.php); [sale-price](../wordpress/tests/free/sale-price.php), [variations](../wordpress/tests/free/variations.php), parent-state tests, #245 E7. |
| Clear/relative sale and optional endings | [Operation/calculator](../wordpress/writeleash/includes/free/class-price-operation.php), Change Plan, Apply/Undo mutators; [sale operations](../wordpress/tests/free/sale-operations.php), [acceptance sale operations](../wordpress/tests/acceptance/sale-operations.php); merged #235/#237, [#208 receipt](review/208/IMPLEMENTATION.md). |
| Inclusive range and exclusions | [Selector](../wordpress/writeleash/includes/free/class-product-selector.php), Admin; [range tests](../wordpress/tests/free/price-range.php), [exclusion tests](../wordpress/tests/free/exclusions.php), [Admin exclusions](../wordpress/tests/admin/exclusions-integration.php), [acceptance exclusions](../wordpress/tests/acceptance/exclusions.php); #234 accepted source and merged PR #239. |
| Frozen Preview CSV | Admin; [CSV unit](../wordpress/tests/admin/preview-csv-unit.php), [CSV integration](../wordpress/tests/admin/preview-csv-integration.php), [acceptance CSV](../wordpress/tests/acceptance/preview-csv.php); #233 via merged PR #238. |
| Safety limits, background/Resume and tested envelope | [Safety policy](../wordpress/writeleash/includes/free/class-safety-policy.php), [support contract](../wordpress/writeleash/includes/free/class-free-support-contract.php), [worker](../wordpress/writeleash/includes/free/class-job-worker.php); [boundaries](../wordpress/tests/acceptance/support-boundaries.php), [journey](../wordpress/tests/acceptance/journey.php), [jobs integration](../wordpress/tests/jobs/integration.php), #245 E9/E10. |

These are inspected implementation/accepted-test references, not freshly executed
runtime suites on this marketing commit. Exact compatibility evidence remains in
[acceptance EVIDENCE](../wordpress/tests/acceptance/EVIDENCE.md) and the listing;
do not infer coverage of every version/extension/host. No certified security,
absolute safety, zero loss, unlimited scale, arbitrary custom types, automatic
scheduling, guaranteed Undo, customers/testimonials or ROI claims. Keyword
phrasing reuses #244 without claiming measured ranking or search demand.

## 8. Small pre-publication checklist

- [ ] Founder approves the content and selected location; no publishing authorization
  is inferred from this PR or a verified submission route.
- [ ] #240 confirms public version, usable directory ZIP and selected source/feature
  inventory. Replace every `[VERIFIED_PLUGIN_URL]`; omit absent features.
- [ ] Recheck that channel's live rules, founder access/history and AI/self-promotion
  constraints. Do not use prohibited drafts or post in an UNKNOWN permission context.
- [ ] Choose one relevant post/article, disclose “I built this” where applicable,
  pair only the genuine image with its qualification and keep client data private.
- [ ] Preserve conditional Undo and the tested 1,000-product scope. Test on staging
  with a backup; this copy is not release acceptance or an extension certification.

This document does not change release metadata, runtime, listing, claim matrix,
assets, ZIP manifests/builders or SVN. #240 alone coordinates freeze/rebuild/
retest and publication; marketing must not delay it.

## 9. Optional observations after founder publication

1. Note the date and actual channel/article URL if something is published.
2. If available, look at directory downloads, voluntary reviews and concrete
   support questions. Downloads are not customers, activations or revenue.
3. Correct confusing instructions or unsupported claims using actual feedback.
   No response or no reviews is also a valid observation; invent no outcome.

No recruitment quota, mandatory outreach, analytics system, conversion experiment
or retrospective is required to complete #246.

## Verification receipt

Documentation review covers local Markdown links, source/test and asset paths,
placeholder consistency, single-file ownership, whitespace, authentic PNG review,
public HTTP status and primary policy sources. Exact-HEAD GitHub CI results and
final commit/base identity are recorded in the Draft PR; this file cannot embed
its own commit hash. Runtime tests/final ZIP acceptance are NOT_TESTED by this
documentation task. Publication is currently on hold for unverified distribution
and account/editor prerequisites; that does not block founder review of the
completed research, three posts, introduction and three tutorials.
