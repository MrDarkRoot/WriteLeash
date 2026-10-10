# #234 price-range filter — genuine browser capture evidence

Feature branch `feat/free-234-price-range-filter` (#236) for issue #234.
Captured against the exact candidate tree; reviewed below before the
#170 `source_hashes` refresh. No screenshot, assertion or audit
requirement was removed or relaxed.

## Environment (disposable, never a store)

- Database: MariaDB 10.11.15 (generic Linux tarball, userland server on
  127.0.0.1:3307, harness credentials) — MySQL 8.0.44 evidence for the same
  head lives in the free/admin integration logs, not here.
- WordPress 7.1.2, WooCommerce 11.1.2 (pinned SHA
  `9de9350a…5fce8e9e`), PHP 8.2.32 (`php -S` local server).
- Plugin staged from the candidate tree through the distribution
  allowlist (`stage-plugin.sh`).
- Browser: Firefox 151.0 headless via Playwright 1.61.0 (pinned),
  viewport 1440. Chromium capture is unavailable in this sandbox
  (environment-specific compositor stall on POST-response pages, proven
  content-independent: byte-identical static HTML captures fine and the
  same live pages capture fine under Firefox); hosted CI remains the
  authority for Chromium journeys.
- Fixture: 8 simple products (19.99 / 20.00 / 50.00+sale 45.00 /
  60.00 blank sale / 150.00 / 150.01 / 0.00 / nested 70.00), one
  metadata-malformed product, one variable parent (regular 999.00) with
  variations at 25.00 and 500.00, nested child category, fresh
  shop_manager actor. Fixture script: disposable scaffold, not shipped.

## Journey (keyboard-first, 40 assertions, all passing)

`range-result.json`: 40 checks, 2 axe scans with zero serious/critical
violations, keyboard PASS.

- Disabled default: native checkbox unchecked, bounds disabled, currency
  labels, help copy (`range-disabled.png`).
- Enabled regular 20–150 over 11 explicit IDs: count reads “6 matched · 4
  excluded · 0 unreadable · 0 missing” (`range-count.png`); Preview
  freezes Minimum/Middle/No Sale/Maximum/Nested/variation-25 with exact
  +8% targets and the provenance paragraph including the no-adds promise
  (`range-review.png`).
- Invalid min>max: typed refusal with both bounds retained, no job
  created (`range-invalid.png`).
- Zero min=max=0: exactly the zero product matches (`range-zero.png`).
- Sale basis 40–50: only the 45.00 sale matches; blank sales excluded
  (`range-sale-basis.png`).
- Minimum-only 150+: matched count (`range-min-only.png`).
- No-JavaScript context: identical frozen 2-product preview
  (`range-nojs.png`).
- Approve + Resume on min=max=20: exactly Minimum 20.00 → 21.60 applied
  once; excluded 150.01 product untouched; Undo available
  (`range-applied.png`).

## Review notes (agent-operated inspection of real renders)

- Inclusive bounds render literally: Minimum/Maximum rows show before
  prices equal to the entered bounds and are included.
- The malformed product never appears in any frozen row; the review
  provenance counts it under “unsupported, unreadable or missing”.
- The blank-sale product matches nothing under a sale basis and is
  counted excluded, never unsupported.
- The 500.00 variation is excluded while its 999.00 parent never admits
  anything: variation-own-price semantics hold in the rendered rows.
- Invalid input preserves every entered value (bounds, basis, operation,
  amount) and creates no job; the refusal names the control.
- No-JS preview is row-identical to the JS preview for the same inputs.
- Focus outlines were asserted programmatically at every Tab stop
  (visible-outline check in the journey); axe 4.11.0 reports zero
  serious/critical violations on the range form and the range review.

## Relation to the #170 gate

This directory is new review evidence for #234. The #170 historical
conflict captures and result JSONs are unchanged. Only the two UI source
hashes affected by #234 (`class-free-admin.php`, `free-selection.js`)
are refreshed in `../170/proof.json`, following the established source
refresh precedent; `free-selection.css` is untouched.

Merge-order note: open PR #235 rewrites the same two hashes to its own
tree. Whichever PR merges second must re-capture against the merged tree.
