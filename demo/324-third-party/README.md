# AddSynapse negative control for issue #324

Unreleased demonstration. This records AddSynapse Store Assistant 1.3.5 preparing a WooCommerce price change as a restricted shop manager, then WriteLeash denying `WC_Product::save()` before the product rows change.

It does not replace the reviewed recording in [#332](https://github.com/MrDarkRoot/WriteLeash/pull/332). Those files stay in `demo/324-seatbelt/`. Do not copy these media into `wordpress/assets/` or WordPress.org SVN.

The plugin bytes under test are repository tree `f8cf392ba30a40334c0f1ab67af0ee444ffaee08` at commit `0e4ac6881006eabd95e34ec1530a3357401d3226`, plugin header 0.2.0. That tree is the WriteLeash source that was installed. It is not the WordPress.org 0.2.2 package.

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

1. Riley Agent (`shop_manager`, user 2) has standing grant `6fdf6fc5-ec39-45e2-985b-bdc2a0b95512` (budget 1). The expiry is calculated inside WordPress at execution time as UTC now plus 30 days. This run stored `2026-11-27 05:09:46` UTC. The same string is the grant row, `versions.json`, and `evidence.json`. A standing grant makes the actor restricted. It is not a product allow-list.
2. Riley types a plain-language request to change only Northwind Canvas Tote (product 10, `$100.00`) to `$80.00`. The page shows AddSynapse's own notice that the AI is not set up and that slash commands work without a key.
3. Riley types `/price id=11 set 49.99` for Harborline Wool Cap (product 11, `$40.00`). The plugin shows a draft preview. Nothing is saved.
4. Riley presses **Approve**, then **Yes, apply the changes**. That second button is the real server confirmation (`POST /wp-json/addsynapse-assistant/v1/changesets/{id}/approve`).
5. AddSynapse calls `WC_Product::set_regular_price` and then `WC_Product::save()` as Riley. WriteLeash throws on `woocommerce_before_product_object_save` before WooCommerce writes the post. AddSynapse catches that exception and marks the changeset failed.

Off camera, before the recording:

- Unrestricted administrator Morgan Merchant used the same Approve path on Pilot Lantern (product 12). Regular price changed from `$30.00` to `$55.00`. The approve body was HTTP 200, `status: applied`, `applied: 1`, `failed: 0`. The card text was `Applied`.
- Riley used the same path on the named tote, `/price id=10 set 80.00`. WriteLeash denied that save too. The tote stayed `$100.00`. The approve body was HTTP 200, `status: failed`, `applied: 0`, `failed: 1`. AddSynapse does not open a WriteLeash Execution Frame, so the grant does not authorize this plugin for the named product either.

Anonymous usage sharing was declined in the fixture (`addsynapse_assistant_usage` = `declined`) so the admin notice would not cover the controls. That choice sends nothing.

## Result matrix

| Check | Result |
| --- | --- |
| AddSynapse 1.3.5 installed from the pinned zip | **PASS.** Header version 1.3.5. Zip sha256 matches the pin above. |
| Riley remains the user at `WC_Product::save()` | **PASS.** Both denial audits record actor 2. The approve log `user_id` values are 2. The perimeter reads `get_current_user_id()` on `woocommerce_before_product_object_save`. |
| Two real confirmations | **PASS.** The recording shows Approve, then a separate **Yes, apply the changes** button. Only the second button calls the approve route. |
| Product save denied by WriteLeash | **PASS.** Card text: `WooCommerce refused the change: WriteLeash denied this write outside a matching Execution Frame.` |
| Changeset HTTP result | **PASS.** HTTP 200, `status: failed`, `applied: 0`, `failed: 1` for both Riley saves. This is not a WooCommerce REST 403. |
| Harborline Wool Cap | **PASS.** Stays `$40.00`. |
| Northwind Canvas Tote | **PASS.** Stays `$100.00`. |
| Unrestricted administrator control | **PASS.** Morgan's lantern save applied. `$30.00` to `$55.00`. |
| Product-row hashes across the restricted denials | **PASS.** All three snapshots share `d47112ccd9a495514863253e131da83cce406009c650bedf6e15cb2b20e4f923`. Counts: 3 posts, 54 meta rows, 3 lookup rows. |
| Audit actor, product, surface, code | **PASS.** `where=product_save`, `kind=price`, `code=writeleash_perimeter_denied`, actor 2. Tote object 10 at `2026-10-28T05:10:42+00:00`. Cap object 11 at `2026-10-28T05:11:26+00:00`. |
| Grant active for the whole test | **PASS.** Status `active`, restriction not removed, expiry `2026-11-27 05:09:46` UTC, which is after the grant row (`2026-10-28 05:09:53`), both audit lines, and the check at `2026-10-28 05:11:39` UTC. |
| Live model proposal or hallucination | **NOT TESTED.** No API key in the lab. The slash command is the labeled fallback. Do not describe AddSynapse as defective or as hallucinating. |
| AddSynapse remote key / Claude relay path | **NOT TESTED.** Source limitation: that path calls `wp_set_current_user` to the administrator who minted the key (`manage_options`). A shop manager cannot hold the key. An unrestricted admin write would not be a restricted-actor denial. |
| StoreHand public chat | **BLOCKED.** Requires the WordPress AI client. |
| Hoobert execute route | **NOT TESTED** live. |
| WordPress.org WriteLeash 0.2.2 as the recorded build | **NOT TESTED.** |
| MariaDB, Redis, persistent object cache | **NOT TESTED.** Cache is the WordPress default non-persistent cache. |

## Stack

| Item | Value |
| --- | --- |
| WriteLeash git at record time | `0e4ac6881006eabd95e34ec1530a3357401d3226` |
| WriteLeash plugin tree | `f8cf392ba30a40334c0f1ab67af0ee444ffaee08` |
| WriteLeash header | 0.2.0 |
| WordPress | 7.1.2 |
| PHP | 8.2.34 |
| WooCommerce | 11.1.2, zip sha256 `9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e` |
| AddSynapse | 1.3.5 |
| Database | MySQL 8.0.44, `wp_posts` engine InnoDB |
| Core image | `wordpress:7.1.2-php8.2-apache@sha256:da4c3e7b0051b70835b0a188ecdd5bce5ebf6f83d1f3df41553f1a97d237fd19` |
| CLI image | `wordpress:cli-php8.2@sha256:aaa79b1239f24fccbb1634ec0a25c3e4fac6271bc9bf2334c40c3c8ef77bce89` |
| MySQL image | `mysql@sha256:9c3380eac945af0736031b200027f581925927c81e010056214a4bd6b6693714` |
| Grant expiry | `2026-11-27 05:09:46` UTC, source `utc_now_plus_30_days` |
| Provider cost | $0 |

## Files

| File | What it is |
| --- | --- |
| `out/writeleash-addsynapse-deny.mp4` | 68.44 s, 1440×900, H.264, 3,752,012 bytes |
| `out/writeleash-addsynapse-deny.webm` | Same picture, VP8, 5,737,427 bytes |
| `out/writeleash-addsynapse-deny.gif` | 15.0 s, 960×600, 30 frames, loop forever, scene-weighted |
| `out/writeleash-addsynapse-deny-poster.png` | 1440×900 denial frame |
| `out/captions.en.vtt` | English captions timed from the encoded frames |
| `out/caption-sync.json` | Cue clock. `encoded-video-frames` at 2 fps |
| `out/scenes.json` | Runner wall clock. Diagnostic only. Not the caption clock |
| `out/media-qa.json` | Automated file, caption, and evidence checks. 29 checks, 0 failed |
| `out/evidence.json` | Actor, HTTP bodies, prices, audit lines, grant proof |
| `out/versions.json` | Exact stack and the same grant expiry |
| `out/db-after-*.json` | Product post, meta, and lookup snapshots |
| `QA.md` | Visual pass over the regenerated frames |
| `run.sh` | Rebuilds the disposable lab and regenerates the captions from that run's video |

`runtime/` is gitignored. It holds passwords, the screen recording, and plugin zips.

## Captions

`run.sh` does not time captions from the Playwright process clock. After the MP4 duration is known, it extracts the WebM at 2 frames per second. The recorder paints a 48px square at the bottom of the admin sidebar, one saturated color per scene. `sync_captions.py` reads that square back from the frames and writes `captions.en.vtt`. A later run that reaches a scene later gets a later cue. The cue text is a fixed template. The actor and product numbers in the last cue come from that run's evidence.

`test_sync_captions.py` builds synthetic frames and checks that shifting the marker shifts the cues. It also rejects a cue past the video, an overlap, and a last cue that does not say this is an unreleased build rather than the WordPress.org 0.2.2 package. `run.sh` runs that test before Docker starts, then runs `sync_captions.py validate` against the new video.

The square is a timing fiducial. It is not part of WriteLeash or AddSynapse. On the products screen it covers the Collapse menu label. The price cell stays readable.

This run's cues, from `caption-sync.json`:

| Scene | Video time | What the caption says |
| --- | --- | --- |
| meet | 1.5–6.5 s | Riley opens AddSynapse. No AI key. |
| ask | 6.5–11.5 s | Riley types the tote-only request. |
| notice | 11.5–18.5 s | AddSynapse's own notice. No model. Cost $0. |
| command | 18.5–23.0 s | Negative control. Riley types `/price` for the cap. |
| preview | 23.0–33.0 s | Local preview, `$40.00` to `$49.99`. Nothing saved. |
| confirm | 33.0–38.5 s | Approve, then the button **Yes, apply the changes**. |
| denied | 38.5–53.5 s | Yes was pressed. WriteLeash denies the save. Zero changes applied. |
| products | 53.5–60.0 s | Harborline Wool Cap still shows `$40.00`. |
| board | 60.0–68.44 s | Unreleased repository build, not the WordPress.org 0.2.2 package. |

The first 1.5 seconds are unlabeled. Frame 0 is white and frame 2 is an empty admin shell. The meet cue starts when the chat and the marker are both on screen.

## Reproduce

From this worktree:

```bash
bash demo/324-third-party/run.sh
```

The script starts only Compose project `writeleash_demo324ai`, installs WordPress, WooCommerce, WriteLeash, and AddSynapse, seeds three products, creates Riley's grant with a UTC expiry 30 days ahead, drives the real admin UI with Playwright, and encodes the video. It does not stop other containers. `DEMO324_KEEP=1` leaves the lab up. Otherwise the script removes that project on exit.

A fresh run replaces `out/` with new hashes, a new grant id, and a new expiry. Do not copy the numbers in this file forward. Read the new `evidence.json` and `caption-sync.json`.

## Boundary, stated plainly

WriteLeash was not modified to obtain this result. The in-admin AddSynapse path is inside the existing perimeter because the shop manager remains the current user and no Execution Frame is open. A remote AddSynapse session that runs as an unrestricted administrator is outside that demonstration. A changeset larger than AddSynapse's synchronous limit is queued for a later job that this recording did not exercise; the tested change was one product and ran in the approve request.
