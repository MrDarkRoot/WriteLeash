# #279 screenshot captions (paste block)

Do not paste this into `wordpress/writeleash/readme.txt` until `wordpress/assets/screenshot-1.png` through `screenshot-6.png` are real captures from the commit that ships them. The PNGs in the tree today are v0.1 images. Applying these captions while those files remain would make the listing lie.

Update `readme.txt` in the same commit as the new PNGs. Delete every `Earlier …` line in `== Screenshots ==`, including `Earlier authentic captures; current labels and controls may differ.` Do not keep `Not all features are pictured.` The replacement section is the block below, verbatim.

Issue #281 will change Results/History outcome copy after this prep. Shots 4 and 5 are not captured in this pass.

## Paste into `== Screenshots ==`

```
== Screenshots ==

1. Select products by name, SKU, category or ID – with nested subcategory option.
2. Exact before-and-after Preview of Regular & Sale prices (including variations).
3. Live background job progress with Resume.
4. Conflict protection: newer price is kept instead of overwritten.
5. History + eligible Undo within 30 days.
6. Safety limits: max products, max % increase/decrease, block zero prices.
```

## Shot map (not for readme.txt)

| File | Caption | This pass |
| --- | --- | --- |
| screenshot-1.png | Select products by name, SKU, category or ID – with nested subcategory option. | Not captured. v0.1 file must not be reused. |
| screenshot-2.png | Exact before-and-after Preview of Regular & Sale prices (including variations). | Not captured. v0.1 file must not be reused. |
| screenshot-3.png | Live background job progress with Resume. | Not captured. v0.1 file must not be reused. |
| screenshot-4.png | Conflict protection: newer price is kept instead of overwritten. | BLOCKED until #281 is on the branch. Do not ship a Results frame under this name. |
| screenshot-5.png | History + eligible Undo within 30 days. | BLOCKED until #281 is on the branch. Do not ship a History frame in this pass. |
| screenshot-6.png | Safety limits: max products, max % increase/decrease, block zero prices. | Not captured. v0.1 file is History, not this screen, and must not be reused. |
