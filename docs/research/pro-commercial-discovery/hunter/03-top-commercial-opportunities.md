# Hunter — Top Commercial Opportunities

2026-10-13 Asia/Ho_Chi_Minh. Recommendation: **INVESTIGATE_FURTHER**. See the [ledger](01-merchant-pain-evidence-ledger.md) and [competitors](02-competitor-reality-matrix.md).

**No genuinely differentiated paid workflow is established.** Only two customer jobs have enough public pain specificity to merit a bounded discovery test. These are **HYPOTHESIS**, not approved features, a roadmap, pricing recommendation or authority to build. Their potential differentiation must survive configured-incumbent comparison. Three adjacent directions are explicitly eliminated below instead of filling a five-idea quota.

## Opportunity 1 — Close supplier-file price exceptions without replacing the importer

**Most credible candidate, still unvalidated.** The proposed job is accounting for unresolved price decisions between successive supplier updates, rather than ingesting every supplier format. Evidence anchors: E01/E02/E03; alternatives in Document 2. No source establishes that these merchants actually maintain the proposed exception backlog.

| Discovery field | Proposed answer and evidence status |
| --- | --- |
| Customer segment | **HYPOTHESIS:** Woo resellers with structured, recurring supplier sheets and repeated unresolved price rows; published core simple/variation products within Free's supported envelope. Having many products alone does not qualify. |
| Paying decision-maker | **HYPOTHESIS:** owner/catalog lead who pays for update labor and accepts the final prices; an agency only where a client pays for that recurring job. The actual payer is **NOT VERIFIED**. |
| Trigger | **HYPOTHESIS:** next supplier sheet arrives while previous unmatched/ambiguous/withheld rows remain unresolved. |
| Existing procedure | **REPORTED:** spreadsheet joins/import preparation. **NOT VERIFIED:** whether leftover rows are tracked in sheets, tickets, email or nowhere, and whether this matters after importer configuration. |
| Recurrence | Weekly supplier work appears in E02/E03. Recurring *exception closure* rather than routine updates remains **NOT VERIFIED**. |
| Operational cost | Preparation burden reported; no measured exception minutes, mistake loss or support cost. Measure time spent on joins, review, unresolved follow-up and repair separately; do not use vendor calculators as merchant economics. |
| Current alternatives | Native export/lookup/import; configured WP All Import; WPMelon paste workflow; Pricebreeze; existing spreadsheet aliases and agency script. See Document 2, including actual exception features. |
| Potential WriteLeash advantage | **HYPOTHESIS:** a local, pricing-only record tying known source rows and deliberately held decisions to reviewed supported targets and final outcomes. WriteLeash-owned intended/committed/current evidence could explain its own writes. No universal source attribution. Neither safety nor matching alone is differentiated. |
| Adoption barriers | Another tool beside the importer; preserving stable identifiers; stale cost/baseline semantics; staff training; no PDF/OCR/stock/product creation; possible need for tax/currency/unit handling; shop-wide work beyond 1,000 targets; lack of verified public users. Supplier interpretation and per-row inputs are new work, not existing Free capabilities. |
| Evidence that invalidates it | Merchant has no persistent leftovers; initial alias configuration fixes repeat pain; configured incumbent handles the leftovers; the buyer wants unattended stock/catalog sync; most value needs arbitrary files/currencies/units or unsupported products; Free History/CSV/fresh Preview is sufficient; no paid or repeat-use evidence after a useful demonstration. |

The narrow test is a **manual/read-only comparison on merchant-authorized redacted artifacts**, without a new product. Show what their incumbent already does, then isolate residual work. If residual work disappears, reject this opportunity even if the original spreadsheet looked painful. If only setup help is valuable, that suggests a service or onboarding task rather than renewable Pro demand.

Before any future implementation, a bounded definition would need explicit source-of-truth and basis decisions. An approved supplier price/cost is not necessarily the Woo selling price. Missing rows can mean partial files rather than discontinued products. Duplicate identifiers must not silently choose a product. Observed price equality is not proof that no intervening edit occurred. These are research risks, not a request for another generalized importer.

## Opportunity 2 — Verify completion of stored-price promotions already run elsewhere

**Lower-confidence investigation only.** This is a possible closeout/check job, not a scheduler, checkout rule engine or global price monitor. E06/E07 are historical leads; U01 substantially weakens them. No source establishes that merchants would buy a recurring independent closeout report.

| Discovery field | Proposed answer and evidence status |
| --- | --- |
| Customer segment | **HYPOTHESIS:** supported Woo stores repeatedly changing stored prices for campaigns and manually checking their completion. Exclude merchants satisfied with native dates or dynamic rules. |
| Paying decision-maker | **HYPOTHESIS:** merchant/promotion operator accountable for campaign completion. Agency technician is not automatically the payer. |
| Trigger | **HYPOTHESIS:** a planned start/end has passed; operator must determine which intended stored changes actually happened and what remains. |
| Existing procedure | E06/E07 report manual fixes and staging diagnosis. **NOT VERIFIED:** present-day routine closeout checklist, campaign file or reconciliation procedure. |
| Recurrence | Daily sale activity is reported in E06; current repeat completion failures **NOT VERIFIED**. Seasonal marketing frequency alone is not failure frequency. |
| Operational cost | No measured repair minutes, revenue loss or alert burden. E07's elapsed wait for testing is not billable labor. |
| Current alternatives | Native sale dates; dynamic PW events; Bulk Price Editor; ELEX timed edits/revert; generic activity logs; checklist plus spot checks. |
| Potential WriteLeash advantage | **HYPOTHESIS:** compare a declared stored-price expectation with timestamped current values and proven WriteLeash effects; explicitly separate unknown external effects. Free remains capable of its own ordinary conflict recovery. |
| Adoption barriers | Dynamic rules often avoid restoration; shopper prices differ from stored prices; cron/timezone/cache/overlap support; another agent's writes are not proven by WriteLeash; independent checks may be an infrequent support service. |
| Evidence that invalidates it | Current supported Woo/incumbent works; operator only wants scheduling; stored mutation is unnecessary; acceptable checks are covered by existing tools; incidents are rare; useful verification requires universal checkout, external attribution or an always-on monitoring service. |

Do not infer a paid gap from a known old Woo bug. A real contemporary example with the actual mechanism and a repeated inspection cost must precede even a concept test. If this proof is absent, stop discovery on this direction.

## Adjacent directions investigated and eliminated from this shortlist

| Direction | Evidence / finding | Disposition |
| --- | --- | --- |
| Purchase-cost / margin-protection standalone | Vendor tools already propose cost/margin rules; no independent recurring margin-loss or purchase case was established. Cost freshness, VAT, discounts, shipping and markup-versus-margin semantics widen support. | **INFERRED: reject a standalone product thesis for now.** A declared cost basis may matter inside Opportunity 1, only if merchant evidence supports it. |
| Price approval / audit handoff | Pricebreeze agency roles and reports already overlap. No real repeated approval bottleneck, second-person purchasing requirement or paid compliance mandate was found. Free approval/CSV covers basic review. | **NOT VERIFIED: insufficient to call an opportunity.** Do not paywall approval or invent an enterprise buyer. |
| Multi-store coordination / synchronization disputes | E10 proves an adjacent daily client pipeline, but includes multisite/catalog/images and performance. Existing sync systems compete; WriteLeash currently excludes multisite. Independent pricing-only dispute evidence absent. | **INFERRED: reject as Pro 1.0 research candidate within current boundary.** Do not turn an agency lead into ERP/PIM/SaaS scope. |

## Relationship to the four competing issues

| Issue | Hunter finding; no issue-state change requested by this PR |
| --- | --- |
| [#215 Supplier Operations](https://github.com/MrDarkRoot/WriteLeash/issues/215) | Strongest underlying recurrence signal; test narrow residual exception work. A broad importer has no demonstrated advantage. |
| [#216 Promotion Operations](https://github.com/MrDarkRoot/WriteLeash/issues/216) | Native/dynamic alternatives and historical bug repair weaken paid scheduling/restoration. Opportunity 2 is a conditional lead, not endorsement. |
| [#217 Observability / Recovery](https://github.com/MrDarkRoot/WriteLeash/issues/217) | Logs/external tracking already exist. No independent repeated merchant investigation budget established. Do not promise all-writer attribution. |
| [#218 Conflict Center](https://github.com/MrDarkRoot/WriteLeash/issues/218) | No paid increment beyond Free conflict → current-state review → fresh Preview demonstrated. A cross-job screen is not a commercial job by itself. |

**Decision belongs to Founder/Acting CEO.** Neither opportunity earns implementation or licensing work. A successful discovery outcome may reject both and retain Free as the complete product.
