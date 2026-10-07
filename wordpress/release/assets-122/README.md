> Current listing curation: see [#195 visual handoff](../../../docs/visual-assets-v0.1.md).
> The capture sessions below are historical. #195 reuses three #182 images,
> reorders progress to 3, replaces the clipped conflict viewport with a retained
> #170 table crop at 5, minimally captures Sale/variation Preview at 2 and
> completed/invalid-price results at 4, and refreshes banner copy. Current file/caption/hash
> inventory is in `proof.json`; no new customer validation was run.

# #122 WordPress.org assets and real product proof

Product baseline: `07600cf13551f54c5df74db070ec344064ecb83a` (merged Phase A #137).
Canonical directory: `wordpress/assets/`. The 37-file public manifest and all
public runtime files, including readme/header/listing claims, are unchanged.
No release ZIP, SVN staging, publication, or #123 work is included.

## First-party asset policy

Reviewed **2026-10-02** against
[How Your Plugin Assets Work](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).
The page reports last updated January 31, 2025. Current guidance agrees with #122.

| Asset | Supported filename / format | Exact dimensions / guidance |
| --- | --- | --- |
| Normal icon | `icon-128x128.(png\|jpg\|gif)` | 128 × 128; square; maximum 1MB |
| Retina icon | `icon-256x256.(png\|jpg\|gif)` | 256 × 256; named size, no `@2x` suffix |
| Vector icon | `icon.svg` | Supported for icons; PNG fallback required |
| Normal banner | `banner-772x250.(jpg\|png)` | 772 × 250; maximum 4MB |
| Retina banner | `banner-1544x500.(jpg\|png)` | 1544 × 500; companion to normal banner, never alone |
| Screenshots | `screenshot-1.(png\|jpg)`, etc. | Lowercase numbering matches readme caption lines; maximum 10MB each; no fixed screenshot dimensions specified |

Localized banners may append `-rtl`, a language, or a full locale before the
extension. Localized screenshots use e.g. `screenshot-1-de.png`. No localized
variants are supplied. SVG is documented for **icons**, not banners/screenshots;
all canonical raster files here are PNG. Future SVN placement is top-level
`assets/`, alongside `trunk/` and `tags/`, never `trunk/assets` or in the runtime
ZIP. This directory is a Git source/output location, not SVN staging.

The audit deliberately caps non-icon images at 2MiB and the complete tracked
set at 8MiB, below the official banner/screenshot limits. These are repository
size controls, not additional WordPress.org requirements.

## Original artwork

The icon is a continuous controlled line, loop and clasp: a WriteLeash metaphor
that can extend beyond regular prices. No shield, badge, tiny text or third-party
artwork is used. `wordpress/assets/icon.svg` is the original vector source;
the two required PNG fallbacks are canonical raster outputs.

`banner.svg` is the original banner source. `render-artwork.sh` renders its
1544 × 500 canvas and downsamples that same image to 772 × 250. The four stages
are Preview, Approve, Apply, Recover, with a regular-price context. No benchmark,
product ceiling, host promise, review/install count, certification or universal
rollback claim appears. All artwork is original and under the repository's
GPLv2-or-later terms. DejaVu Sans is a system render font, not bundled.

Trademark review: no WordPress/WooCommerce logos, partner treatment, endorsement,
fake certification, or copyrighted decorative assets. The screenshot content
is the actual product UI, cropped to its content area; no third-party decorative
artwork was inserted.

## Capture fixture and provenance

The Phase B isolated native fixture used WordPress **7.1.2**, WooCommerce **11.1.2**,
MariaDB **10.11.15**, PHP **8.2.32**, default object cache, USD and two price
decimals. The capture PHP patch differs from the #121 acceptance row (8.2.34);
this is capture provenance, **not a new compatibility/support claim**.
Docker was unavailable. A local headless Google Chrome browser drove real
WordPress login, authenticated forms, admin-post PRG redirects and page loads.
No HTML/Figma mock, alternate product renderer, CSS/DOM rewrite, or image editor
was used to manufacture a state. The installed plugin was staged from the
unchanged public manifest, not a final release ZIP. Every staged public file
was compared byte-for-byte to the authoritative base before evidence inventory.

`fixture.php` seeds twelve understandable synthetic published core simple
products via Woo CRUD. Names/SKUs are `Canvas Tote` / `DEMO-01`, etc.; stored
prices range from 18.00 to 210.00. No sale-price/date configuration is created.
The synthetic administrator display name is Demo Manager. No real customer,
order, account email, hostname, nonce, token, filesystem path or infrastructure
identifier appears in a screenshot. Plan/job IDs shown are product identifiers,
not credentials. Browser chrome and WordPress sidebar/account chrome were
excluded through a screenshot of the real `#wpbody-content > .wrap` element.
No content text, prices, controls or warnings were changed.

`proof.json` records exact PNG dimensions, SHA-256 hashes, capture timestamps,
visible product text, frozen captions, runtime-file hashes and independent fresh
WP-CLI Woo/direct-storage price observations. Runtime hashes describe the
capture baseline; they do not freeze future legitimate product changes in CI.
The observation outputs contain only these synthetic products.

| File | Real workflow and observed result |
| --- | --- |
| `screenshot-1.png` | Preview three products at -20 percent; 18.00→14.40, 24.00→19.20, 36.00→28.80. Exact approval control still present; not executed. |
| `screenshot-2.png` | A +30 percent plan with a maximum +10 percent policy is BLOCKED. Durable progress states `BLOCKED_BY_POLICY`, `applied 0`; no approval/Resume control. Independent before/after storage reads are identical. |
| `screenshot-3.png` | Approve a twelve-product -20 percent plan, then change product 10's stored regular price from 18.00 to 21.00 using Woo CRUD. Normal bounded Resume processes ten items: nine applied, one CONFLICT, two pending. Independent storage confirms 21.00 survived. The real row visibly shows Expected 18, Current 21, Planned 14.40 and CONFLICT (ITEM_CONFLICT). Current is the merged read-only fresh Woo edit-context value. No SKU/title/category-only conflict is used. |
| `screenshot-4.png` | Interrupt automatic scheduler delivery in the disposable fixture; let the queued job remain quiet beyond its real 60-second timeout. Close browser, launch a new browser, log in and reload. Real observer shows PAUSED/LEASE_RECOVERY, applied 9, conflict 1, pending 2 and protected bounded Resume. All synthetic stored prices are independently checked after reopen. |
| `screenshot-5.png` | Protected Resume first performs lease recovery, then processes the two remaining products. Terminal state is COMPLETED_WITH_ISSUES: applied 11, conflict 1, pending 0; warning explicitly rejects generic success. Product 10 remains 21.00. Eligible restoration control and its limitations are visible. |
| `screenshot-6.png` | The retained real history capture lists the original synthetic mixed-result job with applied 11/planned 12, conflict 1 and Undo eligible; blocked/unapproved entries are not eligible. This is an eligibility screenshot, not a claim that Undo ran or universally restores all edits. |

Automatic scheduling is interrupted with `DISABLE_WP_CRON=true` and a
repository-only fixture MU plugin (`scheduler-interruption.php`) that disables
Action Scheduler's asynchronous transport. Manual Resume uses the unchanged
product default ten-item bound and real lease/fence handling. No plan, job,
item, journal or status row is directly written by capture tooling. No worker
kill or successful Undo execution is claimed for this set.

The readme's existing six #121 captions already map exactly to filenames 1–6;
no listing or caption rewrite is needed. Fixture counts are evidence values,
not scalability claims. All five operation types remain the frozen #121 scope.

## Readability and accessibility review

Screenshots are 818px wide, close to the 772px directory banner width. At 772px,
the native 13px body/table font is approximately 12.3px; no tiny annotations
were added. Status, reasons, outcome counts and controls are expressed in text
rather than color alone. Before/after and Expected/Current/Planned columns are readable;
striping, native borders, alerts and native button focus behavior are retained.
All six images were visually inspected at capture size and at 772px width.
The crop includes the required status, table and relevant controls. The new
screenshot 3 includes the entire conflict table and the unavailable-Undo
explanation. No failure row is excluded.

## Phase B refresh (2026-10-03)

PR #136 was rebased onto exact post-Phase-A main. Production PHP, manifest,
readme and claim matrix have no unique changes relative to that main. The
merged Current implementation is inherited unchanged; no ownership exemption
or additional runtime repair is included.

Screenshots **2, 3, 4 and 5** were recaptured because each includes the durable
item table changed by Phase A. Screenshots **1 and 6** retain their original
bytes, timestamps and visible text: the frozen preview and history renderer
are unchanged by #137. Their original source baseline is recorded per image,
with verification against the new product base. This preserves truthful older
synthetic state examples without relabeling them as new captures. They do not
show an obsolete durable item table. The icons, banners and artwork sources
are byte-identical to the pre-Phase-B head.

The new session repeats the full twelve-product workflow but writes only PNGs
2–5 (`WL122_SCREENSHOTS=2,3,4,5`). Screenshot 3 is checked structurally against
actual DOM text: the table headers and all six cells of its one conflict row
must match before capture. DOM access is read-only; there is no evaluate,
setContent, CSS injection, image annotation or state-text replacement. A fresh
WP-CLI process after capture records both Woo edit-context regular price and
stored `_regular_price` as **21.00** for product 10. Reopen and completion
observations also preserve 21.00. No job/item/journal rows are edited directly.

`proof.json` now records the new authoritative product base, hashes of every
staged public file, new image hashes/dimensions/timestamps/visible text, and the
new independent observation session. The readme captions and all listing
boundaries remain unchanged. Issue #122's administrative closed state is not
changed. No #123 work, release ZIP, SVN staging or publication is performed.

## Reproduce and audit

Use a **new disposable** WP/Woo site; never run fixture setup against a store.
Stage the public plugin with the existing `wordpress/tests/stage-plugin.sh` and
activate only WooCommerce and WriteLeash. Disable WP cron and copy
`scheduler-interruption.php` into that fixture's MU plugins. Run `fixture.php`
through WP-CLI with `WL122_MODE=seed`; `edit` makes the later regular-price
change and `observe` performs fresh price reads. `capture.cjs` documents the
real browser journey; it needs local Playwright and Chromium only for capture,
not in production or PR_FAST. Its environment inputs are described at its top.

Regenerate artwork with:

```sh
bash wordpress/release/assets-122/render-artwork.sh
```

After explicitly staging canonical assets, run:

```sh
git ls-files -z 'wordpress/assets/*' 'wordpress/icon/*' | php .github/ci/asset-audit.php
bash .github/ci/pr-fast.sh
bash .github/ci/workflow-lint.sh
```

PR_FAST already owns the asset audit; no runtime owner or workflow changes are
required. The audit checks all required tracked files, dimensions/formats,
contiguous numbering, exact six readme captions, capture hashes, icon-only SVG
with required PNG fallbacks, unexpected files/large binaries and exclusion from
the public runtime manifest. A valid control and eighteen deterministic rejection cases also run in PR_FAST.
Screenshot 3 must contain the six real table headers and the same product-10 row
with Expected 18, Current 21, Planned 14.40 and CONFLICT (ITEM_CONFLICT). Numbers
or conflict words placed on unrelated lines do not satisfy that guard.
Existing readme/claim/package audits remain active.
