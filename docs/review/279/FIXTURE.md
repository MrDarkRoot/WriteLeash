# #279 Mixed catalog fixture notes

No catalog was seeded in this prep. `docker info` fails here, so there is no disposable site. These notes describe the synthetic catalog a later capture must use. They are not evidence that the catalog exists.

No customer data. No real orders, emails, names, or payment details. No `WP_DEBUG` notices, Query Monitor, or admin debug bars in the viewport. English admin (`en_US`). Store currency USD, two decimal places, amounts rendered as `$… USD`. Disposable local admin only.

The in-repo seed `wordpress/tests/admin/listing-browser-fixture.php` is the #182 listing fixture (`WL Listing` products). It is not this catalog: it does not open nested subcategories, it moves the conflict row from `$100.00` to `$75.00` rather than `$120.00`, and it has no safety-limits viewport. `wordpress/tests/acceptance/dirty-catalog.php` is a corruption-tolerance leg, not a screenshot catalog. The historical "Mixed catalog" named in `docs/visual-assets-v0.1.md` and `wordpress/release/assets-122/proof.json` was a retained local #188 fixture; its seed is not in this tree. Do not recaption those v0.1 PNGs as this fixture.

## Catalog

Parent category `Apparel`, child `Tees` (one nested subcategory). Variable parent in `Tees` expands to variations. Simple products may sit in `Apparel` only.

| Product | Where | Prices (USD) | Role |
| --- | --- | --- | --- |
| Canvas tote | Apparel (direct) | Regular `$36.00` | Name/category selection |
| Cotton tee — Small, SKU `TEE-SMALL` | Tees variation | Regular `$24.00`, sale `$20.00` | Variation identity in Preview |
| Cotton tee — Large, SKU `TEE-LARGE` | Tees variation | Regular `$28.00`, sale `$22.00` | Variation identity in Preview |
| Clean ceramic mug | Apparel | Regular `$18.00` | Simple row; not a damaged-price skip story |
| Notebook, known product ID | none required | Regular `$12.00` | Manual ID selection |
| Price guard sample | Apparel | Regular `$100.00` at review | Conflict row |

Selection shot: English Products → Bulk Prices. Show name/SKU search, Product category, Advanced manual product IDs, and the **Include subcategories** checkbox enabled, with the sentence that nested subcategories are included once and variable parents expand to variations. Do not show the old "subcategories are not included" state as the pictured option.

Preview shot: one saved Preview that shows exact before-to-after for both price fields across the set, including the two Cotton tee variations. Current Preview column is `{Regular price\|Sale price} before to after`, plus Delta. Planned stored prices are not shopper prices. Do not approve in this frame.

Progress shot: a small in-progress job (the #182 sample is 24 products, 8 stepped, 16 remaining — do not describe any frame as a pictured 1,000-product run). Viewport must show the current live Apply progress bar and **Resume remaining products**. Not the pre-live-progress v0.1 page.

Conflict shot (do not capture until #281 is on the branch): review Regular `$100.00`, plan Set `$80.00`, then a separate Woo edit sets Regular to `$120.00` before Apply. Result row must show expected `$100.00 USD`, now `$120.00 USD`, planned `$80.00 USD`, newer value kept. Current headers are `Regular price expected`, `Regular price now`, `Regular price planned`. #281 may change outcome copy; capture only after that copy is what will ship. Do not use the #182 `$75.00` mutation.

History shot (do not capture until #281 is on the branch): History table with an eligible Undo row, `Undo available for prices changed by this job.` and `Until {site time}` inside the default 30-day window (`Undo_Repository::retention_days()` default 30). Availability is not guaranteed restoration. No completed-Undo claim. No Results/History frame in this prep.

Safety-limits shot: open `Safety limits (optional)` (`#writeleash-free-safety-limits`). Custom values keep the details open. Show maximum changing products (0–1000; picture a tightened value such as 50, not a 1,000-product run), maximum increase percent, maximum decrease percent, and **Block a preview that sets any changing price to zero** checked. Visible copy includes `A change beyond these limits blocks the whole preview.` Defaults in code are max products 1000, increase 50, decrease 50, warning threshold 20, block-zero off — a shipping frame should show the tightened custom limits, not a collapsed summary.

Shipping files, when eventually captured: `screenshot-1.png` … `screenshot-6.png` only. PNG, at least 1200×900, under 1MB, English, USD. No banners, icons, or extra names.
