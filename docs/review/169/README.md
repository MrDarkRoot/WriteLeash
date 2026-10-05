# #169 Admin UI review evidence

Captured from real WordPress 7.1.2 / WooCommerce 11.1.2 / PHP 8.2.32 on
2026-10-05. The disposable local DB was MariaDB 10.11.14 with default cache;
the required CI Admin owner runs its pinned MySQL/MariaDB and Redis matrix.
These are review screenshots, separate from the WordPress.org listing assets.

## Screenshots

| State | 1440 px | 1024 px | 782 px | 375 px | Actual 200% zoom |
| --- | --- | --- | --- | --- | --- |
| Configuration | [PNG](169-main-configuration-1440.png) | [PNG](169-main-configuration-1024.png) | [PNG](169-main-configuration-782.png) | [PNG](169-main-configuration-375.png) | [PNG](169-main-configuration-zoom200.png) |
| Review / approval | [PNG](169-preview-1440.png) | [PNG](169-preview-1024.png) | [PNG](169-preview-782.png) | [PNG](169-preview-375.png) | [PNG](169-preview-zoom200.png) |
| Mixed Apply results | [PNG](169-mixed-conflict-1440.png) | [PNG](169-mixed-conflict-1024.png) | [PNG](169-mixed-conflict-782.png) | [PNG](169-mixed-conflict-375.png) | [PNG](169-mixed-conflict-zoom200.png) |
| Undo conflict | [PNG](169-undo-conflict-1440.png) | [PNG](169-undo-conflict-1024.png) | [PNG](169-undo-conflict-782.png) | [PNG](169-undo-conflict-375.png) | [PNG](169-undo-conflict-zoom200.png) |
| History | [PNG](169-history-1440.png) | [PNG](169-history-1024.png) | [PNG](169-history-782.png) | [PNG](169-history-375.png) | [PNG](169-history-zoom200.png) |

Additional checks: collapsed desktop sidebar at [1440](169-main-configuration-1440-collapsed.png)
and [1024](169-main-configuration-1024-collapsed.png), mobile sidebar opened/closed
without page overflow, and [long selected product names at 375](169-long-selection-375.png).
Narrow tables scroll inside their labeled, keyboard-focusable containers.

Regular screenshots: Chrome 148.0.7778.178 / Playwright 1.61.0, 900 px viewport
height. Actual zoom: Chromium 153.0.8010.12 with a disposable test extension
calling `chrome.tabs.setZoom(tabId, 2)`: 1440 px outer window, 720 CSS px inner
viewport, devicePixelRatio 2. This uses browser zoom, without CSS scaling or
viewport-only zoom simulation. The zoom job screenshots show the mixed Apply
and conflicting Undo outcomes of the same saved job after restoration.

## Validation

- Admin integration: 718 assertions pass, including formatting, frozen plan/hash,
  policy, authorization, approval, stale observations, Resume and Undo controls.
- Actual HTTP journey: menu, authenticated POST/nonces, redirects, recovery,
  selection, saved review, progress, Undo, and CSV transport/actor controls pass.
- Existing browser selection journey: 40 assertions pass, including native
  fallback, keyboard picker, long names and review continuity.
- Extended presentation browser journey: 115 assertions pass, including all
  four widths, one primary action, keyboard scroll/focus, mixed results,
  uncertain/empty views, policy block, unavailable product, expired/conflicting/
  completed Undo, History and CSV. Products, Orders and Woo settings load neither
  the WriteLeash stylesheet nor its wrapper.
- Actual 200% zoom: all five states preserve page width and keyboard table
  scrolling; controls remain rendered and reachable.
- Formatting covers saved USD/two-decimal and JPY/zero-decimal contexts despite
  current store setting changes, Woo separators/symbol placement, exact stored
  fractional values, signed deltas, unknown values, trimmed/approximate/tiny
  percentages, and UTC timestamps displayed in the site timezone.
- Observed attention text `#795000` on striped rows `#f6f7f7`: 6.61:1 contrast.
  Secondary text `#50575e`: 6.83:1; focus outline `#2271b1` on white: 5.17:1.
  Text labels carry status meaning independently of color.

Source began at main `6b74551`. The installed renderer and stylesheet bytes
matched the final worktree during capture (SHA-256):

```text
0431c9c9028f1c511801167dae95f68837a57cbd051b16ca38aeff5cc121fcbd  class-free-admin.php
51be85ec8cbf8c1ee5e9598ef20b04bcb977c710518ffea4e3b72e885f2a50bf  free-selection.css
```

Fixture preparation failures from reusing populated History/observer state were
resolved by running browser journeys on a fresh DB, separate from integration
fixtures. Native keyboard scrolling is asynchronous; the local zoom check waits
for scrolling to finish before asserting. Passing results above use those clean
runs. No backend behavior was modified to accommodate the fixtures.

The first hosted run [37261736177](https://github.com/MrDarkRoot/WriteLeash/actions/runs/37261736177)
failed in the existing selection browser harness: zero-delay `pressSequentially`
produced `WL167 BrowserCafé` instead of `WL167 Browser Café`, then the exact-term
response wait timed out. The test now types with a short per-key delay and asserts
its exact text; keyboard selection/removal and all safety assertions remain.
This is a test input correction, with no selector or runtime JavaScript change.
