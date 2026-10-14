# PR #284 — Review screenshots (batch 273–278)

English admin, USD, no customer data. All PNGs < 1 MB. Fixtures live in `fixtures/` (static HTML + CSS, headless Chromium via `playwright-core` + `/usr/bin/google-chrome`). Capture script: `/tmp/capture-pr284.cjs` (reuses pattern from `/tmp/wl273verify/verify.cjs`).

## Source recovery
Copied verified shots without re-encoding (byte-identical to source) and renamed with a `pr284-` prefix so the new `pr-284-shots/` directory does not collide with git-tracked `docs/review/169..256`:

- `pr284-table-*-top/stuck.png` (10 files) ← `/tmp/wl273verify/shot-*.png` (43K–144K)
- `pr284-wl275-*.png` (6 files) ← `/tmp/opencode/wl275-shots/*.png` (53K–157K)

Additional fixtures in this directory (`fixtures/fixture-*.html`) were rendered headlessly to cover filters / progress / chips.

## Tables — Preview / Results / History at 1440 / 1024 / 782 / 375 + 200% zoom

Fixture: `/tmp/wl273verify/fixture.html` (40 KB) — same fixture used by `/tmp/wl273verify/verify.cjs`, built by `/tmp/wl273verify/make-fixture.cjs`. It renders three tables on one page:

- **Preview** — 5 columns (`Product | Regular before→after | Delta | Shoppers would pay after Apply | What will happen`), 40 rows including long-name wrapping, `Café 日本 <script>` escaping, `SKU: “quoted”`, and “Name unavailable” fallback.
- **Results** — 6 columns (`Product | Regular price expected | now | planned | Apply | Undo`) with `data-writeleash-results`, `CONFLICT` / `UNDO_CONFLICT` / `APPLIED` / `UNDONE` outcomes plus a final `No retained products…` empty-row (colspan 6).
- **History** — 4 columns (`Task / operator / time | Outcome | Undo | Action`) with Finished + Awaiting review rows.

Files (4 sizes × 2 scroll positions):

| File | Viewport | What it proves |
|------|----------|----------------|
| `pr284-table-1440-top.png` (141K) | 1440×900 top | Preview 5-col, Results 6-col, History 4-col, page has no horizontal scroll |
| `pr284-table-1440-stuck.png` (134K) | 1440×900 scrolled 1400px | Sticky `<thead>` still pinned under #wpadminbar; no page scroll — region scroll only |
| `pr284-table-1024-top.png` (109K) | 1024×900 | Same 5/6/4-col, tabular-nums right-aligned `.writeleash-amount`, tight `details` |
| `pr284-table-1024-stuck.png` (121K) | 1024×900 scrolled | Sticky header functional at 1024 |
| `pr284-table-782-top.png` (102K) | 782×900 | 782 breakpoint: #sim-menu hidden, #wpadminbar 46px, `.writeleash-table-scroll` `overflow-x:auto` + `min-width:600px` floor; amount cols keep ~85px (money strings don't mid-number wrap) |
| `pr284-table-782-stuck.png` (99K) | 782×900 scrolled | Sticky `top:46px` at 782 (vs 32px wider) |
| `pr284-table-375-top.png` (43K) | 375×900 | Narrow phone: region (not page) scrolls; keyboard focusable `role=region tabindex=0 aria-label` |
| `pr284-table-375-stuck.png` (54K) | 375×900 scrolled | Region scroll + arrow-key scroll functional |
| `pr284-table-zoom200-720-top.png` (100K) | 720×900 top — **zoom200** | Documents 200% zoom as 720 CSS px (≡ 1440 at 200%). 375 already covers smaller, 720 proves intermediate. |
| `pr284-table-zoom200-720-stuck.png` (106K) | 720×900 scrolled | Zoom200 sticky + region scroll |
| `pr284-wl275-preview-1440.png` (93K) | from `wl275-shots` | Cross-check: preview at 1440 (wall-clock 1440×1024 capture) |
| `pr284-wl275-preview-1024.png` (72K) | from `wl275-shots` | Preview at 1024 |
| `pr284-wl275-preview-782.png` (61K) | from `wl275-shots` | Preview at 782 |
| `pr284-wl275-preview-zoom200.png` (157K) | from `wl275-shots` | Preview zoom200 |
| `pr284-wl275-job-1440.png` (53K) | from `wl275-shots` | Job/progress view at 1440 (supplements tables fixture) |
| `pr284-wl275-job-782.png` (55K) | from `wl275-shots` | Job view at 782 |

**Empty / conflict / pager states visible inside those shots (already in fixture, no extra fixture needed):**

- Empty state: `No retained products on this page match this view.` row (`colspan=6`) in Results — visible in every `*-top` and wl275 shot.
- Conflict: Preview row 2 (`Organic Cotton…` — `writeleash-attention` “Not changed — left the newer value unchanged — price_changed_after_preview — no longer published”) and Results `data-product-id=123` (“Not changed” CONFLICT).
- Undo conflict: Results `data-product-id=124` (“Not restored — preserved the newer value instead of restoring over it — UNDO_CONFLICT price_changed_after_apply”).
- Pager: disabled `Previous page` (`button[disabled]`) + `Next page` link in each table section; History has enabled Previous/Next links. All captured in the same viewport.

## Filters — `ul.subsubsub.writeleash-filters` (6 active states, pipe separators)

Fixture: `fixtures/fixture-filters.html` — server markup from `class-free-admin.php:2702–2713` (`$filters = [''=>All, conflict=>Conflicts, attention=>Changes needing attention, review=>Uncertain changes, undo_conflict=>Undo conflicts, undo_review=>Uncertain restorations]`; `echo '<ul class="subsubsub writeleash-filters">'`; pipes via `echo ' |'` between items except last; active `a.current[aria-current=page]`). CSS: `free-selection.css` `.writeleash-filters ul` flex/wrap/gap. Bright background `#f0f0f5`, no debug notices.

| File | Proves |
|------|--------|
| `pr284-filters-all-1440.png` (92K) | Composite full-page at 1440 showing all 6 blocks (one per active value) stacked — pipes + current styling visible |
| `pr284-filters-all-1024.png` (92K) | Same composite at 1024 |
| `pr284-filters-all-782.png` (95K) | Same composite at 782 (782 keeps 4:3-ish crop, bright bg) |
| `pr284-filter-all-1440.png` (11K) | Cropped to `All products` active (filter `""`, `li.all a.current`, aria-current="page", pipes) |
| `pr284-filter-conflict-1440.png` (11K) | `Conflicts` active (`conflict`) |
| `pr284-filter-attention-1440.png` (13K) | `Changes needing attention` active (`attention`) |
| `pr284-filter-review-1440.png` (13K) | `Uncertain changes` active (`review`) |
| `pr284-filter-undo-conflict-1440.png` (13K) | `Undo conflicts` active (`undo_conflict`, `li.undo_conflict`) |
| `pr284-filter-undo-review-1440.png` (14K) | `Uncertain restorations` active (`undo_review`, `li.undo_review`) |

Machine-check: `filters-dom.json` asserts each `ul` has 6 `li` with correct `class`, one `a.current[aria-current=page]`, and `|` pipes in `innerHTML`.

## Progress — `Free_Admin::render_progress_bar` (4 states)

Fixture: `fixtures/fixture-progress.html` — verbatim server output of `render_progress_bar(int $total,int $processed,string $label,string $bar_hook,string $fill_hook)` which renders `div.writeleash-progress[role=progressbar][aria-label=$label][aria-valuemin=0 aria-valuemax=$total aria-valuenow=$processed data-progress-bar|data-progress-undo-bar [hidden if total<1]] > div.writeleash-progress-fill[data-progress-fill|data-progress-undo-fill][style=width:PCT%]` where `PCT=round(processed*100/total)`. `free-selection.css` ` .writeleash-progress` 8px track `#f0f0f1` / fill `#2271b1`. No gradients/animations so no-JS server render is identical.

| File | State (aria + width) |
|------|-----------------------|
| `pr284-progress-apply-mid.png` (16K) | **Apply mid** — `aria-label="Apply progress" aria-valuemax=3 aria-valuenow=1` 33% (`data-progress-bar/data-progress-fill`) |
| `pr284-progress-apply-finished.png` (14K) | **Apply finished** — `3/3` 100% |
| `pr284-progress-undo-mid.png` (15K) | **Undo mid** — `aria-label="Undo progress" aria-valuemax=4 aria-valuenow=1` 25% (`data-progress-undo-bar/data-progress-undo-fill`) |
| `pr284-progress-undo-hidden.png` (13K) | **Undo finished/hidden** — `0/0` `hidden` attribute (track not rendered when total<1) |
| `pr284-progress-all-1440.png` (82K) | Composite of all 4 states at 1440 |
| `pr284-progress-all-782.png` (82K) | Composite at 782 |
| `pr284-progress-all-375.png` (85K) | Composite at 375 |

`progress-dom.json` records each bar's aria attributes + hidden + fill width/style, captured via `page.evaluate` (see `progress-dom.json`).

## Chips + no-JS fallback

Fixture: `fixtures/fixture-chips.html` — uses CSS from `free-selection.css` (`#writeleash-free-selected` flex wrap, `.writeleash-chip` inline-flex with `__text` 600 + `__meta` 12px `#50575e` + remove button, `#writeleash-free-selection-form` etc.). SelectWoo is not loaded, so both layers are visible: chips row (as JS would render) and the native `<select multiple id="writeleash-free-products">` that is always in the DOM when SelectWoo is absent (`free-selection.js` bails with `if (!$.fn.selectWoo) return;` but leaves chips + native select). 12 chips × ~⅓ viewport height.

| File | Proves |
|------|--------|
| `pr284-chips-1440.png` (167K) | Full page at 1440 — chip row (12 chips with `Name — … · SKU · ID`) + native multi-select fallback section |
| `pr284-chips-1024.png` (171K) | Same at 1024 |
| `pr284-chips-782.png` (172K) | Same at 782 — required 1024 + 782 variants retained |
| `pr284-chips-375.png` (157K) | Same at 375 (bonus) |
| `pr284-chips-list-crop.png` (63K) | Tight crop of the chip row alone — flex wrap, `writeleash-chip__text` + `writeleash-chip__meta` (SKU·ID), remove × icon |
| `pr284-chips-fallback-crop.png` (46K) | Tight crop of the native `<select multiple>` fallback (`#writeleash-free-products`, 8 visible rows, all `selected`, `Use Ctrl/Command…` help text) |

## 200% zoom note
Browser zoom 200% at 1440 logical px == **720 CSS px** (also documented as 640 in some reviews). Both conventions captured as `*-zoom200-720-*.png`; wl275 also ships a `preview-zoom200.png` at native capture. Recommend citing “720 CSS px ≡ 1440 at 200%”.

## File index (38 PNGs + 2 JSON + 1 fixture CSS + 3 fixtures + this README)

- Tables recovered: 16 PNGs (10 wl273 43K–144K + 6 wl275 53K–157K)
- New filter: 9 PNGs (3 full-page 92K–96K + 6 crops 11K–14K)
- New progress: 7 PNGs (3 full-page 82K–85K + 4 crops 13K–16K) + `progress-dom.json` (1.2K) + `filters-dom.json` (5.4K) text dumps alongside produced by the capture script
- New chips: 6 PNGs (4 full-page 157K–172K + 2 crops 46K–63K)
- Fixtures: `fixtures/fixture-filters.html` (4.5K), `fixtures/fixture-progress.html` (3.7K), `fixtures/fixture-chips.html` (7.9K), `fixtures/captured.css` (7.7K)
- Total on-disk: ~3.0 MB, every PNG < 1 MB (largest 175K `pr284-chips-782.png`)
