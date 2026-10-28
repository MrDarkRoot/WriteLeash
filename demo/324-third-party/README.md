# AddSynapse negative control for issue #324

Unreleased demonstration. This records AddSynapse Store Assistant 1.3.5 preparing a WooCommerce price change as a restricted shop manager, then WriteLeash denying `WC_Product::save()` before the product rows change.

It does not replace the reviewed recording in [#332](https://github.com/MrDarkRoot/WriteLeash/pull/332). Those files stay in `demo/324-seatbelt/`. Do not copy these media into `wordpress/assets/` or WordPress.org SVN.

The build under test is repository commit `3be680557c476f30e93e45cf7bf0bc62009ef8c6`, plugin header 0.2.0. It is not the WordPress.org 0.2.2 package.

## Why AddSynapse

Three wordpress.org plugins were inspected. AddSynapse was selected because a logged-in shop manager can prepare one product price change and confirm it without an API key, and the confirm request keeps that shop manager as the current user.

| Plugin | Version | Decision |
| --- | --- | --- |
| AddSynapse – AI Website & Store Assistant (`addsynapse-store-assistant`) | 1.3.5 | Selected. Chat Approve calls `ChangesetService::approve` as `get_current_user_id()`, then `WC_Product::save()`. Slash commands run with no model key. |
| StoreHand AI Product Manager (`storehand-ai-product-manager-for-woocommerce`) | 1.0.1 | Not selected. Product writes exist, but the public chat route returns HTTP 503 unless a WordPress AI connector is present. |
| Hoobert AI Command Bar (`hoobert`) | 0.2.3 | Not live-tested. `POST /wp-json/hoobert/v1/execute` can call WooCommerce REST as the current user. Confirmation is a client flag the server does not enforce. |

AddSynapse zip sha256: `8f2d15e1d58e4efb0b34522fb47f05f5f6ad46bbfefc77b6e77084d3a0fe49f2`. License: GPLv2 or later.

## What was tested

Disposable Docker lab only. Fictional catalog. Generated passwords stayed in gitignored `runtime/secrets.json`. No provider key was present, so no model was called. Provider cost: **$0**.

The on-camera path is a labeled deterministic negative control, not an AI hallucination and not an autonomous write.

1. Riley Agent (`shop_manager`, user 2) has standing grant `27f7313a-a526-4091-8d49-9863c18a7fa1` (budget 1, expires 2027-01-01 00:00:00 UTC). A standing grant makes the actor restricted. It is not a product allow-list.
2. Riley types a plain-language request to change only Northwind Canvas Tote (product 10, `$100.00`) to `$80.00`. The page shows AddSynapse's own notice that the AI is not set up and that slash commands work without a key.
3. Riley types `/price id=11 set 49.99` for Harborline Wool Cap (product 11, `$40.00`). The plugin shows a draft preview. Nothing is saved.
4. Riley presses **Approve**, then **Yes, apply the changes**. That second button is the real server confirmation (`POST /wp-json/addsynapse-assistant/v1/changesets/{id}/approve`).
5. AddSynapse calls `WC_Product::set_regular_price` and then `WC_Product::save()` as Riley. WriteLeash throws on `woocommerce_before_product_object_save` before WooCommerce writes the post. AddSynapse catches that exception and marks the changeset failed.

Off camera, before the recording:

- Unrestricted administrator Morgan Merchant used the same Approve path on Pilot Lantern (product 12). Regular price changed from `$30.00` to `$55.00`. That shows the plugin path can write when the actor is not restricted.
- Riley used the same path on the named tote, `/price id=10 set 80.00`. WriteLeash denied that save too. The tote stayed `$100.00`. AddSynapse does not open a WriteLeash Execution Frame, so the grant does not authorize this plugin for the named product either.

Anonymous usage sharing was declined in the fixture (`addsynapse_assistant_usage` = `declined`) so the admin notice would not cover the controls. That choice sends nothing.

## Result matrix

| Check | Result |
| --- | --- |
| On-camera cap write through AddSynapse Approve | **PASS.** Denied. Cap stays `$40.00`. |
| Off-camera tote write through the same path | **PASS.** Denied. Tote stays `$100.00`. |
| Product-row hash after both Riley denials | **PASS.** Identical to the snapshot taken after Morgan's lantern change. |
| Morgan's unrestricted lantern write | **PASS** as a positive control. `$30.00` → `$55.00`. Not a restricted-actor case. |
| Live model proposal or hallucination | **NOT TESTED.** No API key in the lab. The slash command is the labeled fallback. Do not describe AddSynapse as defective or as hallucinating. |
| AddSynapse remote key / Claude relay path | **NOT TESTED.** Source limitation: that path calls `wp_set_current_user` to the administrator who minted the key (`manage_options`). A shop manager cannot hold the key. An unrestricted admin write would not be a restricted-actor denial. |
| StoreHand public chat | **BLOCKED.** Requires the WordPress AI client. |
| Hoobert execute route | **NOT TESTED** live. |
| WordPress.org WriteLeash 0.2.2 as the recorded build | **NOT TESTED.** |
| MariaDB, Redis, persistent object cache | **NOT TESTED.** Cache is the WordPress default non-persistent cache. |
| HTTP 403 from the AddSynapse approve route | **Not expected.** The route is not a WooCommerce products route. Approve returned HTTP 200 with changeset `status: failed`, `applied: 0`, `failed: 1`. The denial is the PHP exception inside `WC_Product::save()`. |

Audit lines, both `where=product_save`, `kind=price`, `actor=2`:

- Tote, off camera: `object_id` 10 at `2026-10-28T02:38:18+00:00`.
- Cap, on camera: `object_id` 11 at `2026-10-28T02:39:01+00:00`. Message shown in the card: `WooCommerce refused the change: WriteLeash denied this write outside a matching Execution Frame.`

Shared product-row sha256 from `observe.php` with no focus product id: `26a137657995712d7f82c5e0c81dad74149b7086f4aa63830a7700765b6f7275`. Counts: 3 posts, 54 meta rows, 3 lookup rows. Regular prices in all three snapshots: tote `100.00`, cap `40.00`, lantern `55.00`.

## Stack

| Item | Value |
| --- | --- |
| WriteLeash git | `3be680557c476f30e93e45cf7bf0bc62009ef8c6` |
| WriteLeash header | 0.2.0 |
| WordPress | 7.1.2 |
| PHP | 8.2.34 |
| WooCommerce | 11.1.2, zip sha256 `9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e` |
| AddSynapse | 1.3.5 |
| Database | MySQL 8.0.44, `wp_posts` engine InnoDB |
| Core image | `wordpress:7.1.2-php8.2-apache@sha256:da4c3e7b0051b70835b0a188ecdd5bce5ebf6f83d1f3df41553f1a97d237fd19` |
| CLI image | `wordpress:cli-php8.2@sha256:aaa79b1239f24fccbb1634ec0a25c3e4fac6271bc9bf2334c40c3c8ef77bce89` |
| MySQL image | `mysql@sha256:9c3380eac945af0736031b200027f581925927c81e010056214a4bd6b6693714` |
| Provider cost | $0 |

## Files

| File | What it is |
| --- | --- |
| `out/writeleash-addsynapse-deny.mp4` | 67.64 s, 1440×900, H.264 |
| `out/writeleash-addsynapse-deny.webm` | Same picture, VP8 |
| `out/writeleash-addsynapse-deny.gif` | 15.0 s, 960×600, 30 frames, loop forever |
| `out/writeleash-addsynapse-deny-poster.png` | 1440×900 denial frame |
| `out/captions.en.vtt` | English captions fitted to the 1 fps frames |
| `out/scenes.json` | Runner clock. It is not the caption clock. |
| `out/evidence.json` | Actor, HTTP body, prices, audit lines |
| `out/versions.json` | Exact stack |
| `out/db-after-*.json` | Product post, meta, and lookup snapshots |
| `run.sh` | Rebuilds the disposable lab |

`runtime/` is gitignored. It holds passwords, the screen recording, and plugin zips.

## Captions

`captions.en.vtt` was fitted to frames extracted at 1 fps. The runner clock in `scenes.json` ends at 68.496 s. The video file is 67.64 s. The runner also marked the not-configured notice as soon as that banner was on screen, which is while Riley is still typing. The committed captions follow the pictures:

- the notice is visible before the slash command
- Approve is a separate step from "Yes, apply the changes"
- the denial card is on screen before the Products list
- the evidence board is the last scene

A fresh `bash demo/324-third-party/run.sh` overwrites `out/captions.en.vtt` with runner-clock cues. Do not treat that overwrite as the reviewed track unless the frames are checked again.

## Reproduce

From this worktree:

```bash
bash demo/324-third-party/run.sh
```

The script starts only Compose project `writeleash_demo324ai`, installs WordPress, WooCommerce, WriteLeash, and AddSynapse, seeds three products, creates Riley's grant, drives the real admin UI with Playwright, and encodes the video. It does not stop other containers. `DEMO324_KEEP=1` leaves the lab up. Otherwise the script removes that project on exit.

## Boundary, stated plainly

WriteLeash was not modified to obtain this result. The in-admin AddSynapse path is inside the existing perimeter because the shop manager remains the current user and no Execution Frame is open. A remote AddSynapse session that runs as an unrestricted administrator is outside that demonstration. A changeset larger than AddSynapse's synchronous limit is queued for a later job that this recording did not exercise; the tested change was one product and ran in the approve request.
