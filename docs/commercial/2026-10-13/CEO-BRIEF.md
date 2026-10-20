# WriteLeash Pro 1.0 — CEO brief

Research date: 2026-10-13, Asia/Ho_Chi_Minh. Repository baseline: `325cbe722f7cc0e7d828a3dd620adbc5cd1244c8` (`main`). Research only; no build, launch or commercial obligation is authorized here.

## Recommendation: GO_TO_VALIDATE

Validate **recurring supplier-file price review**, narrowed to existing products, stable SKU identifiers and structured CSV. Pro 1.0 and any eventual paid Woo Marketplace listing are **one product**. This is permission being requested for an experiment, not a finding of commercial viability or a request to build Pro. **#214 remains BLOCKED.**

The proposed paying buyer is a reseller's owner/catalog lead, or an agency already accountable for that merchant's updates. A new supplier cost file triggers the job. The operator needs to compare it with the last accepted file and current Woo prices, prepare proposed retail changes, resolve exceptions and account for the outcome. Recurrence and preparation savings for a reachable buyer are **HYPOTHESIS**, not established WriteLeash demand.

## Strongest argument against it

The market already has adequate solutions. An agency [reports](https://www.reddit.com/r/woocommerce/comments/1q6d2s5/how_do_you_safely_update_woocommerce_prices_when/) stable native CSV and WP All Import workflows, including price/stock runs three times daily, file checks and result emails. [WPMelon](https://wpmelon.com/bulk-edit/) advertises spreadsheet matching; [Pricebreeze](https://pricebreeze.com/how-it-works) advertises supplier layout/mapping memory, review and checked writes. A local CSV-only product could be too limited to switch to, while adding formats, stock and ERP connections could make it too costly for a solo founder.

**INFERRED:** the possible wedge is less repeated preparation for a narrow buyer, not CSV ingestion, scheduling, larger limits or unique safety. Local operation may matter to some merchants; no such preference was validated.

## What the evidence actually supports

| Finding | Classification | Implication |
|---|---|---|
| Free source contains price operations, Presets, Repeat, exclusions/ranges, Preview/CSV, separate approval, conflicts, Resume, History and eligible Undo | OBSERVED: source/document inspection | These are the baseline, not paid unlocks |
| Final Free ZIP is accepted; #256 is completed | REPORTED: [artifact acceptance](../../review/240/final-c219/FINAL-ACCEPTANCE.md), [#256](https://github.com/MrDarkRoot/WriteLeash/issues/256) | Executed AI-perspective UX tests do not validate human demand |
| WordPress.org distribution remains pending in #240; official QIT/coexistence evidence remains blocked in #212's latest receipt | REPORTED: issue histories | Neither release acceptance nor Marketplace preparation proves paid eligibility |
| A merchant describes weekly supplier CSV work, but includes stock and new-product needs | REPORTED: [workflow](https://www.reddit.com/r/woocommerce/comments/1q4kfi1/best_practice_for_weekly_csv_updates_pricesstock/) | Recurring event exists; pricing-only fit is uncertain |
| Promotion, restoration and price-history alternatives already exist | PUBLIC SIGNAL: [PW mechanism](https://woocommerce.com/document/pw-sales-scheduler-for-woocommerce/), [Bulk Price Editor](https://woocommerce.com/products/bulk-price-editor/) | No reason to build a general scheduler or audit tool first |
| WriteLeash paying buyers, switching pressure, saved time, repeat use and renewals | NOT VERIFIED | No implementation commitment is justified |

The [Issue Decision Memo](ISSUE-DECISION-MEMO.md) records the frozen Free source/ZIP and repository histories. This research did not rerun acceptance or inspect a new public download.

## Smallest additional paid workflow, if validated

**HYPOTHESIS:** one reusable CSV profile and exact SKU match; last accepted supplier-cost baseline; one declared same-currency/unit/tax-basis multiplier; current-Woo comparison; explainable proposed Regular Prices and unresolved rows; a result linked to the approved operation. No automatic updates. Reject ambiguity rather than guess. Unmatched rows never create products.

Supplier preparation would feed validated per-item proposals into the **one Free/core plan and mutation path**. The existing UI does not import supplier files or offer that per-row preparation adapter. It is new work, not an already shipped integration. Its feasibility and benefit must be demonstrated before definition.

In [Pro Hypothesis Comparison](PRO-HYPOTHESIS-COMPARISON.md), promotions face dynamic alternatives that avoid restoration. Investigation/reconciliation have no demonstrated standalone paid increment beyond Free; inspect them within real workflows, not separate roadmaps.

## Missing evidence and smallest experiment

With one consenting merchant, inspect two successive real, redacted supplier files and the current preparation method. Replay the next preparation manually in a read-only spreadsheet, alongside the incumbent. Let the operator resolve ambiguous matches and reject proposals. At the following supplier cycle, ask them to initiate the same review again. Measure operator minutes, researcher assistance, correction rates and unresolved work. A single merchant can falsify the idea; a positive result still needs an independent buyer case.

No Pro code, production write or claim of an implemented Apply integration is needed. [Merchant Validation Plan](MERCHANT-VALIDATION-PLAN.md) specifies the comparison and stopping rules. No merchant was contacted during this mandate.

## Economics and Founder decision

Test **USD 149/site/year** as a pricing hypothesis, with USD 99 and 199 as sensitivity cases. Woo's [terms](https://woocommerce.com/woo-vendor-agreement/) pay 70% of Product Net Revenue. At an undiscounted, deduction-free USD 149, that is USD 104.30 before support and maintenance. The direct-sales comparison uses assumed fees, not an actual processor quote. File onboarding could consume the entire contribution. [Packaging and Pricing](FREE-PRO-PACKAGING-PRICING.md) records obligations and arithmetic; no sales forecast is made.

**Exact Founder decision requested:** authorize a manual, read-only supplier-workflow experiment, capped at eight Founder/researcher hours over up to four weeks, with voluntary redacted files and no production credentials. Assess one concrete workflow first; expand only if it survives the incumbent comparison. Keep Free release work independent, #214 blocked, and all Pro implementation/licensing/submission obligations unauthorized. If this experiment is declined, retain the evidence and do not build Pro from this report.
