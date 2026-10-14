# #279 screenshot provenance

No capture happened. Capture date is blank on purpose. Today in this prep environment is 2026-10-14; that is not a capture date. Source commit, dimensions, viewport, and browser for the shipping PNGs stay blank until a real browser capture of the commit that will ship.

Shots 4 and 5 are BLOCKED until #281 is on the branch. Do not capture a Results or History frame in this pass. Issue #281 will change outcome copy; a screenshot must match the commit that ships.

`wordpress/assets/screenshot-1.png` through `screenshot-6.png` were not replaced, copied, scaled, or composited.

## Shipping capture (blank until real)

| Field | screenshot-1.png | screenshot-2.png | screenshot-3.png | screenshot-4.png | screenshot-5.png | screenshot-6.png |
| --- | --- | --- | --- | --- | --- | --- |
| Capture date (UTC) | | | | BLOCKED until #281 | BLOCKED until #281 | |
| Source commit | | | | | | |
| Dimensions | | | | | | |
| SHA-256 | | | | | | |
| Viewport | | | | | | |
| Browser | | | | | | |

Required when filled: PNG, width ≥ 1200, height ≥ 900, file size < 1MB, English admin, USD. Record the full SHA of the commit that contains both the PNGs and the pasted `readme.txt` captions.

## v0.1 files — must not reuse

Computed with `sha256sum` on 2026-10-14 against the files still at `wordpress/assets/`. These hashes are rejection keys for a later audit. A shipping PNG with any of these hashes is the old asset.

| File | Dimensions | Bytes | SHA-256 (must not reuse) |
| --- | --- | --- | --- |
| wordpress/assets/screenshot-1.png | 1280×900 | 154904 | `78adbbc08784c716f0c6de62ea7fba2da83bfea40e986bddd70112a391a7819a` |
| wordpress/assets/screenshot-2.png | 1238×1442 | 162600 | `543326ae8ff365407d6cd0d637c28bf55c5ac504d80fdcf6f641e50076833d03` |
| wordpress/assets/screenshot-3.png | 1280×900 | 121002 | `a12d8a9a0e2413d3c54c75f66194fc6eef68e44e91d2e6e1152d39b343a8227b` |
| wordpress/assets/screenshot-4.png | 1238×1600 | 164855 | `a5ebbd44629931c4788ee4624a0b96dfb233b54b9c6bff448655a1cfe1175e37` |
| wordpress/assets/screenshot-5.png | 1240×480 | 77459 | `5181b4fba8643483cc0b57c2ebeffe4eba61c69ed278b6efd91d2b6d8dd9f956` |
| wordpress/assets/screenshot-6.png | 1280×900 | 162465 | `df7c40f4552bd8f9511e5c9320756fa5efba30cc834a0665bba78c476428d767` |

`screenshot-5.png` is 480px tall, below the shipping minimum of 900. `screenshot-2.png` and `screenshot-4.png` are 1238px wide, below the shipping minimum of 1200. None of the six may be renamed or cropped into a new listing file.

Prep baseline (not a capture commit): `867c04bb065a31a6718cafeb9431501231279013` on `marketing/279-shots`.
