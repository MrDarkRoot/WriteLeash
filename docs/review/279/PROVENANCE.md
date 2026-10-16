# #279 screenshot provenance

Captured 2026-10-14 from the disposable acceptance lab, not from reused v0.1 files.

| Field | Value |
| --- | --- |
| Capture date (UTC) | 2026-10-14T15:58:00Z through 2026-10-14T16:00:00Z |
| Source build | `df8d804842425bdcf92c4b8eb5df8004314420c6` (origin/main after #285) |
| Lab | `http://127.0.0.1:18092` — Docker project `writeleash_woo112`, Server 29.5.2 |
| Images | `wordpress:7.1.2-php8.2-apache@sha256:da4c3e7b0051b70835b0a188ecdd5bce5ebf6f83d1f3df41553f1a97d237fd19`, `wordpress:cli-php8.2@sha256:aaa79b1239f24fccbb1634ec0a25c3e4fac6271bc9bf2334c40c3c8ef77bce89` |
| WooCommerce | 11.1.2, ZIP SHA-256 `9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e` |
| Admin | English, USD, published simple products and variations created with WooCommerce CRUD |
| Browser | Google Chrome 148.0.7778.178 via Playwright viewport screenshots (no browser chrome) |

Shot 1 is 1280×1600 so name, SKU, category, product ID, and the nested subcategory control fit in one frame. The listing audit rejects width above 1280. Shots 2–6 are 1280×960. None match the v0.1 hashes below.

| File | Dimensions | SHA-256 | Viewport | Visible claim |
| --- | --- | --- | --- | --- |
| screenshot-1.png | 1280×1600 | `efeffac78ebd9d0968708ac92b9e754b415192333b690f2a124f9350ec21927d` | 1280×1600 | Product name or partial SKU, One exact SKU, Product category, product IDs, Include subcategories, Apparel › Shirts |
| screenshot-2.png | 1280×960 | `f0d498e843f3378ca57c491ef2963b28fa3f384141f62e11be9c46146bacc417` | 1280×960 | Sale price before to after, Harbor Tee variations, regular price preserved |
| screenshot-3.png | 1280×960 | `93dad52dc9a9ae8ddb4663bb341deca54a149cf0a037a1d1d86a32870da014eb` | 1280×960 | Progress and results, Resume remaining products |
| screenshot-4.png | 1280×960 | `0947f52432cc673cc103cc69d54af84aeba42f82cf9c292debdc142d0efa59d2` | 1280×960 | Newer price kept; $100.00 expected, $120.00 now, $80.00 planned; left the newer value unchanged |
| screenshot-5.png | 1280×960 | `082e583bb7c1af6605f45af5e00deda7c040b175225bab56dde1ad7910426a33` | 1280×960 | History; Undo available until November 13, 2026 (30 days after October 14, 2026) |
| screenshot-6.png | 1280×960 | `24e8cf161074df8e0a2cabf90c5c57af9d83f4c698e29e3dac9bae41dca48a71` | 1280×960 | Maximum changing products, maximum increase percent, maximum decrease percent, block zero prices |

## v0.1 files — must not reuse

| File | SHA-256 (must not reuse) |
| --- | --- |
| screenshot-1.png | `78adbbc08784c716f0c6de62ea7fba2da83bfea40e986bddd70112a391a7819a` |
| screenshot-2.png | `543326ae8ff365407d6cd0d637c28bf55c5ac504d80fdcf6f641e50076833d03` |
| screenshot-3.png | `a12d8a9a0e2413d3c54c75f66194fc6eef68e44e91d2e6e1152d39b343a8227b` |
| screenshot-4.png | `a5ebbd44629931c4788ee4624a0b96dfb233b54b9c6bff448655a1cfe1175e37` |
| screenshot-5.png | `5181b4fba8643483cc0b57c2ebeffe4eba61c69ed278b6efd91d2b6d8dd9f956` |
| screenshot-6.png | `df7c40f4552bd8f9511e5c9320756fa5efba30cc834a0665bba78c476428d767` |
