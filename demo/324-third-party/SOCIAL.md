# Social copy for the AddSynapse negative control

Endorsed film: `demo/324-third-party/`. This copy follows `README.md`, `QA.md`, and `out/evidence.json`. It is not copy for `demo/324-seatbelt/`.

Do not call this an autonomous model decision or an AddSynapse defect. No API key was configured. Provider cost was $0. Riley Agent is a restricted shop manager. Riley typed the plain-language tote request, then typed the slash command for a different product. Playwright drove that fixed sequence. The approve route then called `WC_Product::save()` as Riley, and WriteLeash denied it.

The clip is 68.44 s, not 45–60 s. It was not trimmed or re-recorded. Plugin header on camera was 0.2.0 at commit `0e4ac6881006eabd95e34ec1530a3357401d3226`. That is not the WordPress.org 0.2.2 package and not a recording of public 0.3.0.

**2026-11-10.** The live WordPress.org directory is WriteLeash 0.3.0. These films were recorded on earlier trees and are not a recording of the published zip. This AddSynapse film is plugin header 0.2.0 at git `0e4ac6881006eabd95e34ec1530a3357401d3226`. The seatbelt film is plugin header 0.2.0 at git `3be680557c476f30e93e45cf7bf0bc62009ef8c6`. The last caption in `out/captions.en.vtt` still says the clip is not the WordPress.org 0.2.2 package. When recorded, the directory was 0.2.2. That cue matches the film and was not retimed. It is not a claim that the directory is 0.2.2 on 2026-11-10.

## Hook (101 characters)

Riley typed a price command for the wrong product. WriteLeash denied the save. The cap stayed $40.00.

## X post

Riley, a restricted shop manager, typed a tote-only request, then /price id=11 set 49.99. No model ran. WriteLeash denied the save (writeleash_perimeter_denied, actor 2, product 11). The cap stayed $40.00.

Monitored paths only, not a firewall. Stock undo is unsupported.

## WooCommerce group post

Lab recording, not a customer story. A restricted shop manager used AddSynapse Store Assistant with no AI key, so no model was called. He typed a request to change only the Northwind Canvas Tote from $100.00 to $80.00. AddSynapse showed its own notice that the AI is not set up. He then typed `/price id=11 set 49.99` for a different product, Harborline Wool Cap, previewed $40.00 to $49.99, and pressed Approve, then Yes, apply the changes.

WriteLeash denied `WC_Product::save()` before the product row changed. The card said WooCommerce refused the change because WriteLeash denied the write outside a matching Execution Frame. Zero changes applied, one failed. The products screen still showed Harborline Wool Cap at $40.00. The tote he had named also stayed $100.00. The approve response was HTTP 200 with status failed, not a WooCommerce REST 403.

Before the recording, unrestricted administrator Morgan Merchant used the same Approve path on Pilot Lantern. That save applied, $30.00 to $55.00. The end board of this clip records that contrast. It is not a second live scene.

The camera ran an unreleased WriteLeash build, plugin header 0.2.0, with WordPress 7.1.2, WooCommerce 11.1.2, and AddSynapse 1.3.5. It is not the WordPress.org package.

Monitored paths only, not a firewall. Stock undo is unsupported.

## YouTube description

WriteLeash denies a shop manager's typed price command for the wrong product. Harborline Wool Cap stays $40.00.

This 68.44-second screen recording is a labeled negative control. Riley Agent, a restricted shop manager, types a request to change only the Northwind Canvas Tote from $100.00 to $80.00. No AI key is configured, AddSynapse says the AI is not set up, and no model runs. Cost is $0. Riley then types `/price id=11 set 49.99` for Harborline Wool Cap. The preview shows $40.00 to $49.99 and says nothing is saved yet. Riley presses Approve, then the separate button Yes, apply the changes. AddSynapse calls `WC_Product::save()` as Riley. WriteLeash throws on `woocommerce_before_product_object_save`. The card fails with 0 changes applied and 1 failed. The products list still shows $40.00.

The on-screen result text is: "WooCommerce refused the change: WriteLeash denied this write outside a matching Execution Frame."

Audit evidence for the on-camera save: `2026-10-28T05:11:26+00:00`, code `writeleash_perimeter_denied`, where `product_save`, actor 2, object 11, kind `price`. The product-row sha256 stayed `d47112ccd9a495514863253e131da83cce406009c650bedf6e15cb2b20e4f923`. The approve body was HTTP 200, status `failed`, applied 0, failed 1.

Off camera, before this clip, unrestricted administrator Morgan Merchant sent `/price id=12 set 55.00` through the same Approve path. Pilot Lantern changed from $30.00 to $55.00, status `applied`. The end board shows that contrast next to the denied cap and the unchanged tote.

Chapters:
- 0:01 Riley opens AddSynapse with no AI key
- 0:06 Riley types the tote-only request
- 0:11 AddSynapse's own notice, no model, cost $0
- 0:18 Riley types `/price` for Harborline Wool Cap
- 0:23 Local preview, $40.00 to $49.99, nothing saved
- 0:33 Approve, then Yes, apply the changes
- 0:38 WriteLeash denies the save
- 0:53 Harborline Wool Cap still shows $40.00
- 1:00 End board, denial for actor 2 on product 11

Recorded stack, from `out/versions.json`: WriteLeash git `0e4ac6881006eabd95e34ec1530a3357401d3226`, plugin tree `f8cf392ba30a40334c0f1ab67af0ee444ffaee08`, plugin header 0.2.0, AddSynapse 1.3.5, WordPress 7.1.2, PHP 8.2.34, WooCommerce 11.1.2, MySQL 8.0.44 InnoDB. Grant `6fdf6fc5-ec39-45e2-985b-bdc2a0b95512` expired `2026-11-27 05:09:46` UTC. This is an unreleased repository build, not the WordPress.org 0.2.2 package, and not a recording of public WriteLeash 0.3.0.

Monitored paths only, not a firewall. Stock undo is unsupported.
