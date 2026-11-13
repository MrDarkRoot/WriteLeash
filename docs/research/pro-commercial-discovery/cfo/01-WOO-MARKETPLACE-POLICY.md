# Woo Marketplace Policy Verification

Verified 2026-10-13, Asia/Ho_Chi_Minh. Public official documentation only; no private vendor terms, application, purchase or representative contact. This is a CFO research record, not acceptance of a contract or legal advice to the Founder.

**Conclusion: eligibility is conditional and operating obligations are material.** A useful Free edition plus additional paid functionality can fit freemium. A paid listing does not establish demand. Pro and the paid Marketplace edition remain one product.

Evidence labels throughout this directory: **OBSERVED** = inspected repository/page/response; **PUBLIC SIGNAL** = published vendor assertion or indexed page, not independent performance evidence; **REPORTED** = attributed account; **INFERRED** = analytical consequence; **HYPOTHESIS** = proposed assumption; **NOT VERIFIED** = unresolved. Policy rows are OBSERVED public rules, not confirmation of WriteLeash approval. All exact URLs below were checked on the verification date.

## Rules and implications

| Area | Official requirement or guidance | WriteLeash implication |
|---|---|---|
| Monetization [M] | Standalone paid single-site licensing and freemium are supported. No wholly free plugin without a monetization path. Externally billed integrations require Billing API or an approved partnership; first-party integration development is preferred. | Additional recurring value must exist before seeking listing. Do not invent SaaS to qualify. |
| Freemium [S, M] | Two submissions, Free and paid, combine into one product page. Free core plus an additional premium plugin fits the guidance. | One commercial product over useful Free; no paid safety unlock. |
| Fees [A §§2,20.10] | Vendor receives 70% of Product Net Revenue; deductions include affiliates, refunds, reversals, processing and taxes. Monthly payout within 30 days after month-end. | Model deductions before share. No verified universal processing/affiliate schedule or separate listing fee. Unknown is not zero. |
| Subscription [L] | One live store plus staging/test/development activation; updates/support require an active subscription. Renewal defaults to automatic, at current full price; cancellable. Installed software remains usable after expiry. | Annual single-site is the simplest comparable offer. Agency tiers belong to direct-channel testing. |
| Eligibility [S] | Approved vendor account before submission; business, code and UX review. Revenue model, competition and rationale are examined. | #211 packaging completion is not approval. |
| Platform [S] | PHP 7.4+ and two latest major WordPress/Woo releases; accessibility standards. PHP 8.3+ strongly recommended. | Mandatory compatibility work differs from a recommended runtime. Final artifact evidence must support claims. |
| Coexistence [S] | Breaking popular extensions can cause rejection/removal: Bookings, Subscriptions, Product Add-Ons, Bundles, Min/Max Quantities, Shipment Tracking. | Test coexistence, not a promise to mutate custom product types. |
| QIT submission [S] | API, E2E, Activation, Security, PHPCompatibility, Malware, Validation. | Local PR_FAST/Plugin Check cannot substitute. #212 remains blocked. |
| QIT metadata [Q] | Accurate version/license headers and readme; Woo identifier added during deployment. HPOS requirement applies to order-interacting extensions; cart/checkout compatibility is contextual. | Do not fabricate headers, certification or unrelated capabilities. |
| Updates [U] | Staying current and updating at least every six months avoids removal flags. Quarterly major releases and monthly improvements are recommendations. Urgent security fixes as needed. | Budget recurring maintenance even at low sales; six months is no permission to leave a vulnerability open. |
| Support [H] | Timely email, reviews and feature-request handling. Woo handles account/refund/coupon/live-chat routing; product support remains the vendor's job. Arrange coverage for absence. | Budget diagnosis, communication and holidays. Public page provides no numeric vendor response SLA. |
| Refunds [R] | Extensions/themes: full refund within 30 days. Billing API services follow third-party policy. | Refund customers can already have consumed onboarding/support. Renewal-specific exceptions are not explicit on this page. |
| Licensing [A] | GPL required; paid features cannot also be the free offering. Documentation and listing must be accurate. | Define an additional workflow, not a renamed Free capability. |
| External commerce [S, A] | No outside-Marketplace upsell links, spam or affiliates in submitted products; no third-party links on Partner Page. Same product price parity. | Do not insert direct checkout or license-server diversion into the Marketplace flow. |
| Continuing liability [A] | Policy changes: 15-day compliance. Removal may require support through expiry or refunds for prior-year buyers. Privacy/security duties and indemnity apply. | Founder must assess obligations and fund exit/incident reserve before launch. |

## Ambiguities requiring resolution before a commercial commitment

1. **Price parity:** [A] requires identical outside/Marketplace pricing; [M] says closely matching single-site pricing; [S] says equal-or-less and matching. Use identical single-site list prices in hypotheses. No assumption that direct-only discounts, founding offers or agency bundles are approved exceptions.
2. **QIT:** [S] lists seven submission suites. [U] lists six update suites without Validation, says all checks first, and identifies Activation/Security/Malware as deployment-required. Preserve the stronger submission checklist; verify actual update gates and current names in the authorized vendor environment. A documented ambiguity is not a waiver.
3. **Compatibility:** agreement says two most recent releases; submission says two latest major releases. The subscription FAQ also describes L-1 using minor/major versions. Submission requires all new extensions to be HPOS-compatible; QIT describes order-interacting scope. Preserve coexistence and accurate declarations without adding order features. Budget the stricter relevant window until clarified. Public headers or earlier broader tests do not prove every profile on the final ZIP.
4. **Support:** agreement requires Woo's support system; [H] describes routing to vendor email. Follow the routed workflow, then confirm private onboarding and any response targets. No invented 24-hour contractual SLA.
5. **External licensing:** no blanket permission or ban for every license library was verified. Standard standalone billing belongs to Woo; any proposed additional account/license gate needs specific review. No licensing design or code is authorized here.
6. **Financial schedules:** actual deductions, FX, withholding, payout-country eligibility, discounts/bundles and private addenda remain NOT VERIFIED. Founder jurisdiction is not inferred from timezone. Obtain applicable terms before choosing payment infrastructure.
7. **Refund interpretation:** broad 30-day public policy does not explicitly distinguish renewal orders. Model refunds on both new and renewal charges rather than assert renewals are excluded.

## Repository readiness, not certification

Live main fetched before changes: `325cbe722f7cc0e7d828a3dd620adbc5cd1244c8`. [#211](https://github.com/MrDarkRoot/WriteLeash/issues/211) is completed package preparation. [#212](https://github.com/MrDarkRoot/WriteLeash/issues/212)'s latest comment records seven suites blocked by vendor access and six coexistence checks blocked by licensed fixtures, on a provisional candidate. [Final Free acceptance](https://github.com/MrDarkRoot/WriteLeash/blob/325cbe722f7cc0e7d828a3dd620adbc5cd1244c8/docs/review/240/final-c219/FINAL-ACCEPTANCE.md) is a separate WordPress.org artifact receipt, not official QIT, business acceptance or public distribution evidence. These blockers do not prevent economic research.

## Official source register

- [A — Marketplace Vendor Agreement](https://woocommerce.com/woo-vendor-agreement/), especially §§1–3, 8, 20.10.
- [M — Monetization expectations](https://developer.woocommerce.com/docs/woo-marketplace/monetization-expectations/).
- [S — Submitting your product](https://developer.woocommerce.com/docs/woo-marketplace/submitting-your-product/).
- [G — Getting started](https://developer.woocommerce.com/docs/woo-marketplace/getting-started/).
- [L — WooCommerce.com subscription FAQ](https://woocommerce.com/document/frequently-asked-questions/), subscription questions.
- [Q — QIT Validation](https://qit.woo.com/docs/managed-tests/validation/).
- [U — Product update guidelines](https://developer.woocommerce.com/docs/woo-marketplace/product-update-guidelines/).
- [H — Support guidelines](https://developer.woocommerce.com/docs/woo-marketplace/support-guidelines/).
- [R — Refund policy](https://woocommerce.com/refund-policy/).

Next: [benchmarks](02-PRICING-LICENSING-BENCHMARK.md), [economics](03-SOLO-FOUNDER-ECONOMICS.md), [channels](04-DIRECT-VS-MARKETPLACE.md), [investment gates](05-INVESTMENT-GATES.md).
