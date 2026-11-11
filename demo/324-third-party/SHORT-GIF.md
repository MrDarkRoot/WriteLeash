# Short price-block GIF

File: `out/writeleash-agent-price-block-short.gif`

Three stills from `out/writeleash-addsynapse-deny.mp4`, held for 1.6s, 1.8s, and 1.8s. The frames are the one-frame-per-second grabs at 28s, 40s, and 56s.

1. The restricted shop manager, Riley, has a draft to change Harborline Wool Cap from $40.00 to $49.99. Nothing is saved.
2. Riley applies it. WriteLeash denies `WC_Product::save()`. The card says Failed and 0 changes applied.
3. The product list still shows $40.00.

Riley typed `/price id=11 set 49.99`. No model ran. Provider cost was $0. This is not an autonomous AI decision.

The source recording is plugin header 0.2.0 at `0e4ac6881006eabd95e34ec1530a3357401d3226`. It is not the WordPress.org 0.3.0 zip. Do not upload this GIF to the plugin directory without a separate asset review.
