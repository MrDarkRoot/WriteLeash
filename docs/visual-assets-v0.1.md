# WriteLeash v0.1 static visual handoff

**Bulk price changes without blindly overwriting newer edits.**

The six images below are the WordPress.org sequence and the reusable GitHub,
launch and community set. Captions are copied exactly from the plugin
[`readme.txt`](../wordpress/writeleash/readme.txt). No video is needed.

| Order | Asset | Exact directory caption |
| --- | --- | --- |
| 1 | [screenshot-1.png](../wordpress/assets/screenshot-1.png) | Select products by name, SKU or category before reviewing your price changes. |
| 2 | [screenshot-2.png](../wordpress/assets/screenshot-2.png) | Review exact before-and-after Sale Price changes, including variations and skipped products, before Apply. |
| 3 | [screenshot-3.png](../wordpress/assets/screenshot-3.png) | See changed and remaining products in a background job, and Resume interrupted work. |
| 4 | [screenshot-4.png](../wordpress/assets/screenshot-4.png) | Review completed changes and explained skips; one invalid price does not stop the useful result. |
| 5 | [screenshot-5.png](../wordpress/assets/screenshot-5.png) | A later price or setting change becomes a conflict instead of a blind overwrite. A planned $100 to $80 change leaves a newer $120 edit alone. |
| 6 | [screenshot-6.png](../wordpress/assets/screenshot-6.png) | Review History, job outcomes and the availability of eligible Undo. |

## Small launch set

| Theme | Use | Accompanying message |
| --- | --- | --- |
| Hero | [Screenshot 5](../wordpress/assets/screenshot-5.png); already cropped for reuse | Preview expected $100 and planned $80. A later $120 edit remains $120, while other products change. |
| Scale | [Screenshot 3](../wordpress/assets/screenshot-3.png) | Background work, saved progress and Resume. This is a **24-product sample**, with 8 changed and 16 remaining; the supported limit is up to 1,000 products per job. Do not describe it as a pictured 1,000-product run. |
| Scope | [Screenshot 2](../wordpress/assets/screenshot-2.png), optionally [Screenshot 1](../wordpress/assets/screenshot-1.png) | Exact Sale Price Preview with Small/Large variation names and before/after values. Screenshot 4 shows Regular Price outcomes; screenshot 1 introduces both price targets. |
| Recovery | [Screenshot 6](../wordpress/assets/screenshot-6.png) | History, job outcomes and Undo availability. Screenshot 4 also shows the eligible Undo action. |

Use these original images with the launch copy in
[launch-v0.1.md](launch-v0.1.md). Keep expected, current and planned values,
the “Not changed” explanation and product identity together when sharing the
hero. Do not replace prices or add simulated interface elements.

## Reuse and provenance

Three #182 captures are reused byte for byte: original screenshots 1 and 6
retain their numbers; original screenshot 5 becomes screenshot 3.
Two minimum replacement captures reuse the already-prepared local #188
WooCommerce fixture and its synthetic Mixed catalog: screenshot 2 is an
unapplied Sale Price Preview with Small/Large variation identities; screenshot
4 is a five-product Regular Price task with four changes and one explained
invalid-price skip. No catalog was reseeded, product source changed or new
customer validation run. The captured plugin was the retained #188 local
artifact from `2e059cacf46977cc3ae1eba15eba628ad6e7570e`; this is capture
provenance, not a new acceptance result for the messaging branch. The existing
invalid-price reason is shown as the product currently renders it.

The original conflict viewport stopped before the conflict row. Screenshot 5
therefore replaces that viewport with an unaltered crop of the retained real
[#170 Chromium capture](review/170/170-chromium-apply-conflict.png):
`1240x480+180+1055`. It shows a $100 expected / $120 current / $80 planned
Regular Price row, “Not changed”, the newer-value preservation explanation,
and two changed products. No UI was reconstructed and no new customer
validation was run. Sample names and values are synthetic products
from the original real WooCommerce Admin fixture.

The source capture uses the earlier “Expected / Current / Planned” headers;
current listing captures use “Regular price expected / now / planned”. The
behavior and same-row values are unchanged. This is retained capture
provenance, not a claim of a new capture against the messaging branch. Its
original capture timestamp was not recorded; the listing inventory explicitly
records that limitation rather than assigning a new capture time.

The [listing inventory](../wordpress/release/assets-122/proof.json) records
final filenames, captions, dimensions, hashes and the hero's source/crop.
Historical runtime observations in that file remain historical.

## Banner, icons and directory handoff

Both [772×250](../wordpress/assets/banner-772x250.png) and
[1544×500](../wordpress/assets/banner-1544x500.png) banners keep the existing
artwork with the approved primary message and Preview → Apply → History →
Undo labels. The [128×128](../wordpress/assets/icon-128x128.png),
[256×256](../wordpress/assets/icon-256x256.png) icons and
[SVG fallback source](../wordpress/assets/icon.svg) are unchanged.

After plugin approval, copy the contents of `wordpress/assets/` to the
WordPress.org SVN **top-level `/assets`**, separate from `trunk`. Keep these
filenames. Directory captions come from the `== Screenshots ==` section of
`readme.txt`, not a separate SVN caption file. See the
[official WordPress.org asset guidance](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).
No SVN publication is part of this preparation.
