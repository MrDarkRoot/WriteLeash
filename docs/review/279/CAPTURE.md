# #279 how a real capture is produced

This prep did not capture anything. `docker info` fails in this environment (exit 1). Do not invent a site URL, a container, or a WooCommerce admin.

## Existing harness (not sufficient, and not run here)

`wordpress/tests/admin/selection-browser-run.sh` is the only in-repo listing capture path. It needs Docker Compose (`wordpress/tests/admin/docker-compose.yml`, project `writeleash_woo111`), a disposable WordPress + WooCommerce 11.1.2 site, and Playwright Chromium. On MySQL/default cache it seeds `wordpress/tests/admin/listing-browser-fixture.php` with `WL167_LISTING_MODE=seed` and runs `wordpress/tests/admin/listing-capture.cjs`.

That script requires `WL167_BASE_URL` and `WL167_LISTING_FIXTURE`. It logs into the disposable admin, sets a 1280×900 viewport, and writes `listing-1-select.png` … `listing-6-history.png` under `WL167_EVIDENCE` only. It does not commit PNGs. It does not know the #279 caption order, nested-subcategory frame, safety-limits frame, or the `$100 / $120 / $80` conflict. Its conflict seed writes `$75.00`. Its progress assertion is the older "Progress and results" / "remaining" copy, not proof of the live progress bar.

Do not point that script at `wordpress/assets/` and do not rename its evidence onto `screenshot-*.png`.

## Later capture (when a live disposable site exists)

1. Confirm `docker info` works. Build the disposable site from the commit that will ship, English admin, USD, two decimals, debug display off.
2. Seed the catalog in `FIXTURE.md`. Do not load customer data.
3. Set `WL279_BASE_URL` to that site. `docs/review/279/capture-refuse.cjs` exits before any image write if the variable is missing, not http(s), or not responding. It also exits if asked to copy `wordpress/assets/screenshot-*.png`. It never writes a PNG.
4. Capture only from the live admin, full pixels from the browser, PNG, ≥1200×900, <1MB. Files stay `screenshot-1.png` … `screenshot-6.png`.
5. Do not capture shots 4 or 5, or any Results/History frame, until #281 is on that same branch. Outcome copy must be the copy that ships.
6. In one commit: replace the six PNGs, paste `CAPTIONS.md` into `readme.txt`, delete every `Earlier …` line, and fill `PROVENANCE.md` (capture date, source commit, dimensions, SHA-256, viewport, browser). Reject any file whose SHA-256 matches the must-not-reuse table.
7. Do not scale, crop, or composite the v0.1 PNGs. Do not add banners, icons, or an accessibility-certified claim.
