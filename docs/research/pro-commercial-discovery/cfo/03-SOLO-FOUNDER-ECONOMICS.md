# Solo-Founder Economic Model

2026-10-13. **PRICING HYPOTHESIS — NOT VALIDATED CUSTOMER WTP.** Every volume, cost, hour, refund and renewal input below is an adjustable HYPOTHESIS. No observed WriteLeash revenue, paid customers or sales/conversion forecasts. USD throughout; pre-income-tax economic contribution, including founder time, not accounting profit.

## Model and adjustable inputs

Use charged annual price P after discounts, excluding pass-through sales taxes. N is annual billed license accounts, not stores; one agency account may expose many stores. Gross license revenue G = N × P. Refund rate r applies to billed dollars. All billed accounts consume the full assumed support allocation even if refunded; this is conservative. Non-renewals are separate from refunds. Acquisition cost includes allocated unsuccessful leads and founder selling time, excluding onboarding.

| Input | Base | Conservative | Adverse |
|---|---:|---:|---:|
| Annual price P, single site | 149 | 149 | 149 |
| Refund/reversal fraction r | 5% | 8% | 15% |
| Direct processing/payment overhead f, fraction of gross | 4% | 5% | 6% |
| Woo other net-revenue deductions d, fraction of gross, excluding r | 3% | 5% | 8% |
| Recurring support h, hours/account/year | 1 | 2 | 4 |
| Founder time w, USD/hour | 35 | 40 | 50 |
| Acquisition c, USD/new billed account | 40 | 60 | 100 |
| Onboarding o, hours/new account | 1 | 1.5 | 2 |
| Annual renewal q / churn 1−q | 75% / 25% | 60% / 40% | 40% / 60% |
| Woo/PHP/WP/common maintenance H, hours/year | 120 | 180 | 300 |
| Common cash overhead K, USD/year | 500 | 750 | 1,500 |
| Incremental Woo work J, hours/year | 36 | 60 | 100 |
| Incremental Woo fixtures/admin cash L, USD/year | 200 | 300 | 600 |

No processor quote, vendor deduction schedule or paid QIT tariff is implied. Direct f aggregates transaction/fixed-equivalent, billing, FX and nonreturned fee exposure; replace it with an eligible processor's actual schedule. Woo d aggregates processing, affiliate, tax/withholding and other applicable deductions not already excluded in P. Avoid double-counting taxes or refunds. Fee assumptions are deliberately not identical to a published provider quote. No SaaS infrastructure assumed.

H breakdown, base / conservative / adverse hours: Woo compatibility **36/54/90**; WordPress/PHP **24/36/60**; security and regression testing **24/36/60**; documentation/onboarding materials **24/36/60**; operational administration **12/18/30**. J breakdown: official QIT runs/remediation and fixtures **24/40/70**; listing/support routing/release administration **12/20/30**. This is attributable Pro maintenance over one shared core, not two runtime teams; do not double-count unchanged Free work. K/L are placeholders for test hosting, tooling, fixture licenses, billing/tax administration and reserves, not price quotes.

Per billed account:

```text
Direct net receipts R = P × (1 − r − f)
Woo net receipts R = P × (1 − r − d) × 0.70
Acquisition + onboarding A = c + w × o
Renewal contribution CR = R − w × h
New-account contribution CN = CR − A
Stable-cohort contribution CM = CR − (1 − q) × A
Direct annual fixed cost F = w × H + K
Woo annual fixed cost F = w × (H + J) + K + L
Annual contribution after fixed cost = N × CM − F
Break-even N = ceil(F / CM), only if CM > 0
All-new first-year break-even N = ceil(F / CN), only if CN > 0
```

The stable cohort replaces churn one-for-one. It assumes sufficient replacement customers can be acquired at c; it does not predict that this is possible. The all-new year has no renewal subsidy. Contribution margin percentage below is CM/P against gross billed revenue, not against net receipts. Any desired annual surplus S or build-cost recovery B/T raises the numerator to F+S+B/T.

## USD 149 outcomes

| Scenario / channel | Net receipts R | New CN | Renewal CR | Stable CM | CM / gross | Fixed F/year | Stable break-even accounts | All-new break-even |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Base / Direct | 135.59 | 25.59 | 100.59 | 81.84 | 54.9% | 4,700 | 58 | 184 |
| Base / Woo | 95.96 | -14.04 | 60.96 | 42.21 | 28.3% | 6,160 | 146 | None |
| Conservative / Direct | 129.63 | -70.37 | 49.63 | 1.63 | 1.1% | 7,950 | 4878 | None |
| Conservative / Woo | 90.74 | -109.26 | 10.74 | -37.26 | -25.0% | 10,650 | None | None |
| Adverse / Direct | 117.71 | -282.29 | -82.29 | -202.29 | -135.8% | 16,500 | None | None |
| Adverse / Woo | 80.31 | -319.69 | -119.69 | -239.69 | -160.9% | 22,100 | None | None |

These are break-even conditions, not obtainable volumes. Base stable direct 58 accounts implies USD 8,642 gross; Woo 146 implies USD 21,754 gross. At 100 accounts gross is USD 14,900: annual base contribution after fixed costs is **USD 3,484 direct**, **−USD 1,939.40 Woo**. This already pays the assumed founder hourly opportunity cost but excludes new feature development, general company overhead not in K, income taxes, litigation and catastrophic incidents. The 4,878-account conservative direct result is mathematically positive but commercially unattractive: it consumes over 9,700 annual recurring support hours before onboarding. No solo-founder capacity should be inferred from algebraic break-even.

Base Woo new-account contribution is negative even though renewals are positive. Scaling new sales can deepen the funding gap. One initial 100-account all-new cohort yields **−USD 2,141 direct** or **−USD 7,564.40 Woo**, before build investment. Upfront annual cash is not freely spendable: future service, refunds, remittance timing and potential exit costs remain.

## Maximum sustainable recurring support

For a stable cohort, h ceiling = `(R − (1−q)A − F/N − S/N − B/(T×N)) / w`. A negative result means the price cannot cover the selected costs even with zero support. Values below use base inputs, no surplus/build recovery. “Before fixed” is a variable-contribution ceiling, not a sustainable operating target. At the ceiling there is zero economic profit and no contingency.

| Annual USD P | Direct ceiling before fixed, h | Direct ceiling at 100 accounts, h | Woo ceiling before fixed, h | Woo ceiling at 100 accounts, h |
|---|---:|---:|---:|---:|
| 79 | 1.52 | 0.18 | 0.92 | -0.84 |
| 99 | 2.04 | 0.70 | 1.29 | -0.47 |
| 149 | 3.34 | 2.00 | 2.21 | 0.45 |
| 199 | 4.64 | 3.30 | 3.13 | 1.37 |
| 449 | 11.14 | 9.80 | 7.73 | 5.97 |
| 999 | 25.44 | 24.10 | 17.85 | 16.09 |

USD 79/99 are low-price stress cases; USD 149/199 are A hypotheses; USD 449 and 999 are B/C account prices. Agency Woo columns are **arithmetic only**, not supported Marketplace multi-site licenses. At 100 accounts, USD 149 direct permits about 2.00 recurring hours/account/year; Woo about 0.45, in addition to churn-replacement onboarding. At USD 199 those figures are 3.30/1.37. One four-hour support incident costs USD 140 at the base hourly value, exceeding a USD 149 account's modeled net receipts in either channel.

## Agency exposure and founding discount

Model agency recurring hours as `h = account coordination hours + activated stores × hours/store`. With 1 hour coordination and 0.75 hour/store/year, fully activated five-site USD 449 has h=4.75; twenty-five-site USD 999 has h=19.75. Base direct fixed allocation at 100 accounts gives ceilings of 9.80 and 24.10 hours respectively **only under single-site acquisition/onboarding assumptions**.

Replace those assumptions for agencies: hypothesize c=100, o=2 for five sites; c=200, o=5 for twenty-five. At base q/w/fees, steady direct contributions are **USD 199.84** and **USD 124.09** per account; ceilings at 100 accounts fall to **9.12** and **21.95** hours. The larger tier leaves little room for correlated incidents across 25 installations. One extra hour/store wipes out USD 875 on the 25-site account. A single expert agency contact could reduce cost, but this is not established.

Founding offer D: hypothesize USD 99 for the first year, one site, with explicit USD 149 renewal, no permanent discount or unlimited support. Base direct new-account contribution is **−USD 19.91** before fixed costs, versus **USD 25.59** at 149. A capped cohort would be a research subsidy, not proof of sustainable pricing. Set a Founder-approved loss/time budget before any offer; no offer is made here. Lifetime licensing has no demonstrated funded service horizon and should not be tested now.

## Renewal sensitivity

Holding other base assumptions constant at P=149:

| Renewal q | Churn | Direct stable CM | Direct break-even N | Woo stable CM | Woo break-even N |
|---|---|---:|---:|---:|---:|
| 40% | 60% | 55.59 | 85 | 15.96 | 387 |
| 60% | 40% | 70.59 | 67 | 30.96 | 199 |
| 75% | 25% | 81.84 | 58 | 42.21 | 146 |
| 90% | 10% | 93.09 | 51 | 53.46 | 116 |

At N=100, required q is `1 − (CR − F/N)/A`: **28.55% direct**, **100.86% Woo**. The latter is impossible under these inputs, even perfect renewals. At N=200 Woo requires **59.79%**; this is a condition, not observed retention. For acquisition payback over at most three years, use `CN + q×CR/(1+i) + q²×CR/(1+i)²`, then allocate fixed/build costs; do not use infinite lifetime value to excuse losses. At q=75%, i=10%, base three-year pre-fixed contribution is approximately USD 140.94 direct / USD 55.85 Woo.

Founder capacity gate: define annual available hours Tcap; require `N×h + N×(1−q)×o + H + J(channel) + acquisition hours ≤ Tcap`. Acquisition c includes time economically, but hours still consume capacity. A hypothetical 600-hour Pro budget, excluding acquisition hours, caps base stable support at 384 direct or 355 Woo accounts. Replace Tcap with the Founder's actual allocation. No customer count forecast is made.

## Recalculate

The following standard-library Python reproduces the scenario table; edit inputs for measured data. No production or licensing code is involved.

```python
from math import ceil
scenarios = {
    "Base": (0.05, 0.04, 0.03, 1, 35, 40, 0.75, 1, 120, 500, 36, 200),
    "Conservative": (0.08, 0.05, 0.05, 2, 40, 60, 0.60, 1.5, 180, 750, 60, 300),
    "Adverse": (0.15, 0.06, 0.08, 4, 50, 100, 0.40, 2, 300, 1500, 100, 600),
}
P = 149
for label, (r, f, d, h, w, c, q, o, H, K, J, L) in scenarios.items():
    for channel in ("Direct", "Woo"):
        woo = channel == "Woo"
        R = P * (0.70 * (1-r-d) if woo else 1-r-f)
        A = c + w*o
        CR, CN = R-w*h, R-w*h-A
        CM = CR - (1-q)*A
        F = w*(H + (J if woo else 0)) + K + (L if woo else 0)
        print(label, channel, {
            "net": round(R, 2), "new": round(CN, 2),
            "renewal": round(CR, 2), "stable": round(CM, 2),
            "margin": round(CM/P, 4), "fixed": F,
            "break_even": ceil(F/CM) if CM > 0 else None,
            "all_new_break_even": ceil(F/CN) if CN > 0 else None,
        })
```

Source for the only contractual coefficient, 0.70: [official vendor agreement](https://woocommerce.com/woo-vendor-agreement/), §§2.2,20.10, verified 2026-10-13. Everything else is an assumption or arithmetic. See [policy](01-WOO-MARKETPLACE-POLICY.md) and [gates](05-INVESTMENT-GATES.md).
