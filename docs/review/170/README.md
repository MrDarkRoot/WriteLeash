# #170 browser/accessibility/safety gate

### #233 current-source identity refresh

The current Admin source fingerprint includes the read-only Preview CSV form
and handler. Historical screenshots and result JSONs retain their original
bytes and baseline; this refresh does not claim new captures. Exact-HEAD Admin
CI re-runs the existing Chromium/Firefox safety journeys plus focused native
HTTP CSV tests, including the no-JavaScript path. No audit assertion is relaxed.

Base: main `bd66a79` (includes #167–#169). Runtime fixes are confined to the
existing picker JS/CSS: enhanced control names/roles/controls relationships,
closing empty/error listboxes while retaining visible status/native fallback,
a visible picker focus outline, and underline for inline text links.
No PHP engine, price math, support, scheduler, state or storage change.

The existing disposable Admin runner executes the same new 22-product journey
in Chromium and Firefox on MySQL/default cache. Existing Admin selection,
presentation, HTTP, nonce, lifecycle, jobs/Undo consumer and bounded-read checks
retain their MySQL/MariaDB × default/Redis coverage. Dependencies stay test-only:
Playwright 1.61.0 and axe-core 4.11.0. No RELEASE_FULL dispatch.

Local results: Chromium 148.0.7778.178 and Firefox 155.0 each PASS 247
assertions and 12 axe scans with zero violations. Local stack: WordPress 7.1.2,
Woo 11.1.2 (actual 11.0.1 negative), PHP 8.2.32, MariaDB 10.11.14/default cache.
Actual zoom check: Chromium 153.0.8010.12, PASS.

## Review evidence

- [Chromium result/axe/independent safety observations](170-chromium-result.json)
- [Firefox result/axe/independent safety observations](170-firefox-result.json)
- [Chromium Apply conflict](170-chromium-apply-conflict.png), [Firefox Apply conflict](170-firefox-apply-conflict.png)
- [Chromium review](170-chromium-preview.png), [Firefox review](170-firefox-preview.png)
- [Chromium Undo](170-chromium-undo-results.png), [Firefox Undo](170-firefox-undo-results.png)
- [History](170-chromium-history.png)
- [Actual 200% browser zoom: configuration](170-chromium-configuration-zoom200.png), [History](170-chromium-history-zoom200.png)

The journey uses real keyboard Tab/Shift+Tab, Arrow/Enter/Escape, removal,
native form fields, validation recovery, paging, approval, Resume, History,
Undo, CSV and contained table scrolling. Actor-scoped fresh SQL observations
prove exact frozen IDs/hash, no preview saves, one Apply save per eligible
product despite duplicate Resume, preserved 120.00 Apply conflict, preserved
93.00 Undo conflict and restoration of 20 eligible prices. Lost approval response
is simulated by forwarding the real POST then dropping its browser response;
no mutation provider is mocked. Role/session/nonce/GET, a real missing-Woo
dependency negative and an older in-range 11.0.1 install preserve the observed
durable rows and prices.

Both engines check 1440/1024/782/375 CSS px on critical views. Actual 200% zoom
uses Chromium's `chrome.tabs.setZoom(..., 2)`, verified by a 1440 px outer window,
720 CSS px viewport and DPR 2. Firefox actual browser zoom: NOT_TESTED.
Safari: NOT_TESTED. WebKit: NOT_TESTED (installed runner cannot launch because
`libgav1.so.1` is unavailable). Screen reader: NOT_TESTED; Orca 49.4 is installed
but no speech/assistive-technology operator pass was performed. Accessible-name
and axe results are not a screen-reader pass or formal WCAG certification.

Manual review is agent-operated inspection of real browser renders, focus,
labels and keyboard behavior, not a human user study. Automated results and
coverage limitations above are explicit. Review images remain separate from
WordPress.org assets; #159 has not started. Historical #122/#169 captures and
provenance are unchanged. The new proof/audit binds current source bytes,
PNG hashes/dimensions/captions, row identity, observer values and preservation
copy, with negative tampering controls.

Harness corrections preserve assertions: wait for real navigation/approval
commit; use Shift+Tab when returning to earlier controls instead of relying on
Firefox wrapping Tab out of browser chrome; disable opcode caching in the
version-swap fixture server; require successful package restoration. Preparation
failures were not counted as product passes.

## Source refresh after the product/core-backlog merge

`source_hashes` above were refreshed when `origin/main` (#184) was merged into
`product/core-backlog` (#177–#182). The product backlog intentionally renames
merchant-facing labels and extends the picker (regular/sale target, 1,000-product
ceiling) in `class-free-admin.php` and `free-selection.js`, while #184 adds
close-on-empty/error and ARIA naming. The PNG captures in this directory are the
original #170 images: they show the same same-row conflict, preservation and Undo
evidence but predate the #182 copy changes. The #170 Chromium/Firefox journey is
re-executed by the Admin CI job against the merged source on every run, and its
result JSONs are uploaded as CI artifacts alongside the refreshed hashes.

## Final journal release fix: copy identity refresh

### #207 sale-operation source refresh

The source identities now include Clear Sale Price, relative sale discounts,
and explicit blank-value presentation. The original #170 PNGs and result JSON
remain historical; this does not claim new screenshots. The existing hosted
Admin browser journeys must run against the #207 PR head, and focused #207
Admin and Undo fixtures verify the new execution and presentation contracts.
No screenshot, assertion or audit requirement was removed or relaxed.

The source hash now includes the one-heading correction from “Shoppers pay
now” to “Shoppers would pay after Apply”. The reference PNGs and their capture
baseline remain historical and unchanged; this refresh does not claim new
screenshots. The existing Admin browser journey must pass against the exact
PR head, as with the earlier source refresh above. No asset or CI assertion is
relaxed to accommodate the wording correction.

## Issue #205 candidate source refresh

The Admin source fingerprint includes the Apply-conflict selection and fresh
Preview handoff. Historical PNGs, independent observer results and their hashes
remain unchanged. This follows the existing source-refresh contract above; it
does not claim that those historical screenshots show the new recovery flow.
The exact candidate must pass the hosted Admin gate, which reruns #170 in
Chromium and Firefox and uploads candidate captures and results. The same runner
now also executes #205 recovery with JavaScript enabled and disabled, saving
`205-recovery-result.json` and recovery/Preview screenshots separately. Until
that runtime check passes, the fingerprint refresh is not acceptance evidence.

<<<<<<< HEAD
## #208 Price Ending source refresh

The current-source identities include the optional ending control and frozen
ending provenance in task descriptions. Historical PNGs/result JSONs and their
hashes remain unchanged. The existing exact-HEAD Chromium/Firefox journey now
also selects .99 by keyboard and independently checks the frozen final target.
The refreshed fingerprints alone are not browser acceptance evidence.

## Issue #234 candidate source refresh

The Admin source fingerprint includes the optional inclusive price-range
selection filter (#236): the enable checkbox, Regular/Sale basis select and
minimum/maximum inputs in `class-free-admin.php`, plus the enable/disable
toggle and stale-count triggers in `free-selection.js`. Historical PNGs,
independent observer results and their hashes remain unchanged. This follows
the existing source-refresh contract above; it does not claim that those
historical screenshots show the new range controls. Genuine range captures
(new screenshots plus a keyboard/axe result JSON, reviewed in
`../234/README.md`) were captured against the exact candidate tree with
Firefox 151.0 on MariaDB 10.11.15; the exact candidate must still pass the
hosted Admin gate, which reruns #170 in Chromium and Firefox. Until that
runtime check passes, the fingerprint refresh is not acceptance evidence.
>>>>>>> cf12940 (docs(review): genuine #234 range capture evidence; refresh #170 source hashes (#236))
