# #256 loaded Preset selection labels

Base: latest GitHub main `3118e67db63a7c56aeca24d504801a175a755dde` (2026-10-12). Work is isolated in `fix/256-preset-readable-selection`; the Founder's dirty main checkout is preserved.

The configuration adapter always reopened IDS presets as advanced comma-separated IDs, leaving the named picker empty. It now prefills that existing picker with the exact stored IDs. The renderer uses the existing authorized Woo discovery labels and retains missing/inaccessible IDs as generic removable placeholders; planner authorization remains strict.

Before: `Advanced: manual product IDs — 18,19`. After: editable name/SKU/variation labels before Preview, with IDs 18 and 19 unchanged ([installed Admin capture](loaded-variations.png)). Direct Admin verification removed #18, changed the amount to 6 and created a fresh, unapproved Preview for #19 at 121.00 → 127.00.

Validation uses disposable WordPress 7.1.2 / WooCommerce 11.1.2 / PHP 8.2.32 / MariaDB 10.11.15 / default cache / Chrome 148. Source changes were copied only to disposable installed plugin trees. The release ZIP was not rebuilt or modified.

- PASS: existing Admin integration, 1,703 assertions (selection, range, exclusions, Presets, limits, approvals, Apply/Undo).
- PASS: existing Preset/Repeat integration, 78 assertions.
- PASS: preset unit tests, 3,251 cumulative assertions; public preset acceptance.
- PASS: existing Presets browser, JS and no-JS CRUD/range/refinement and Apply/History/Repeat, separate new approval.
- PASS: focused server-rendered display regression, 13 assertions: exact IDs, readable permitted labels, escaping, deleted/draft/inaccessible placeholders, hidden unauthorized names, planner refusal and zero Woo product saves. This regression fails on the accepted pre-fix ZIP.
- PASS: focused real Admin browser regression in JS and no-JS: variations and simple products show name/SKU/attributes before Preview, exact selected ID sets survive Load, Remove edits the population, a current-price edit produces 116.00 with fresh approval, and a deleted target remains visible/removable without broadening.
- PASS: PHP/JS syntax and diff whitespace; deterministic POT regenerated for the new explanation.

The existing browser fixture initially inherited zero-decimal currency configuration; its two-decimal Repeat assertion failed. The disposable setting was corrected to the fixture's expected two decimals and the unchanged test passed. Initial standalone browser runs hit pointer/keyboard activation and screenshot timeouts. The focused script now checks visible native controls and dispatches their click events; the existing suite independently covers keyboard operation, and direct Admin pointer use completed the loaded-preset journey. No assertions were dropped; ID comparisons use sets because discovery display order is not selection authority.

No full compatibility matrix, other browser engine or newly built release ZIP was tested. After merge, #240 must rebuild and record a new source/checksum, then rerun relevant acceptance and merchant selection checks. Old accepted ZIP SHA-256 `a77704b42c9bbb2350f32b0de23a3c46f3a0fe76c617561b91133651ec9d8bb7` still matches; the modified runtime/POT bytes will supersede that artifact when packaged. The earlier merchant verdict does not certify those new bytes.

Packaged bytes differ only in `includes/free/class-free-admin.php`, `includes/free/class-price-presets.php` and `languages/writeleash.pot`. Added regressions are `wordpress/tests/admin/preset-labels-integration.php` (also included in the existing Admin suite) and `preset-labels-browser.cjs`. The existing 1001-target check now edits the loaded picker IDs as well as the advanced fallback IDs; the refusal assertion is unchanged.

Focused browser command: set `WL167_SITE`, `WL167_BASE_URL` to a disposable store, `WL167_PLAYWRIGHT_MODULE` to the existing Playwright install, and `WL256_USER`/`WL256_PASSWORD` to its local fixture account; run `node wordpress/tests/admin/preset-labels-browser.cjs`. Server regression: `wp --path=<disposable-store> eval-file wordpress/tests/admin/preset-labels-integration.php`.
