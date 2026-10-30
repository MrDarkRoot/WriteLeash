# Issue #324 seatbelt demo

Unreleased demonstration of repository commit `3be680557c476f30e93e45cf7bf0bc62009ef8c6` (plugin header 0.2.0). The disposable site runs that tree through the public distribution allowlist. This is not a recording of the package distributed on WordPress.org.

The clip shows two behaviors on one fictional product:

1. Bulk Prices previews a regular-price decrease from $100.00 to $80.00. A second shop manager sets the stored regular price to $120.00. Apply compares the stored price with the reviewed price, reports a conflict, and leaves $120.00 in place.
2. A shop manager who is the actor on a WriteLeash grant sends `PUT /wp-json/wc/v3/products/10` with `regular_price` `49.99`. WooCommerce REST returns HTTP 403 `writeleash_perimeter_denied`. The product-row hash is unchanged. The debug log records the denial for route `/wc/v3/products/10`.

The conflict is the Bulk Prices optimistic check on the price that was reviewed. The denial is the grant perimeter on a direct WooCommerce product REST write by a restricted user. The audit line's `object_id` is the actor user id (`3`), and `kind` is `"0"`. It is not the product id.

## Status

| Deliverable | Result | Evidence |
| --- | --- | --- |
| MP4 | PASS | `out/writeleash-seatbelt-demo.mp4`, 1440×900, H.264, 63.8 s, 5,071,159 bytes. Decoded frames match the WebM. |
| WebM source | PASS | `out/writeleash-seatbelt-demo.webm`, 1440×900, VP8, 63.8 s, 4,986,621 bytes. |
| Looping GIF | PASS | `out/writeleash-seatbelt-loop.gif`, 960×600, 15.0 s, loop forever, 448,895 bytes. |
| Poster PNG | PASS | `out/writeleash-seatbelt-poster.png`, 1440×900, 138,873 bytes. Conflict row: expected $100.00, now $120.00, planned $80.00. |
| English captions | PASS | `out/captions.en.vtt`, fitted to frames taken from this MP4. |
| Docker fixtures and scenario | PASS | This directory. `run.sh` exits 0 only after the price and denial assertions. |
| Database evidence | PASS | Preview stores `100.00`. After the second edit, after Apply, and after the denied PUT, `_regular_price` is `120.00` and the product-row sha256 is `eb57b6df695e52df0bba3856b7ed70c485e6233b7d22997bc49f299285ebdde9`. |
| Asset metadata | PASS | `out/versions.json`, `out/video-report.json`, `out/image-report.json`, `out/evidence.json`. |
| WordPress.org SVN upload | NOT PERFORMED | Do not upload these files and do not treat this branch as a release. |
| MariaDB and Redis modes | NOT_TESTED | This recording uses MySQL 8.0.44 InnoDB and WordPress's default non-persistent cache. |
| Distributed 0.2.2 zip as the recorded build | NOT_TESTED | The camera ran the repository tree named above. |

The H.264 encode logs one OpenH264 `cmInitParaError` line and still writes a playable file. Frame samples from the MP4 match the WebM at 8 s, 40 s, and 57 s.

## Stack recorded in versions.json

- WordPress 7.1.2 from `wordpress:7.1.2-php8.2-apache@sha256:da4c3e7b0051b70835b0a188ecdd5bce5ebf6f83d1f3df41553f1a97d237fd19`
- PHP 8.2.34 from `wordpress:cli-php8.2@sha256:aaa79b1239f24fccbb1634ec0a25c3e4fac6271bc9bf2334c40c3c8ef77bce89`
- WooCommerce 11.1.2, zip sha256 `9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e`
- MySQL 8.0.44, `wp_posts` engine InnoDB, image `mysql@sha256:9c3380eac945af0736031b200027f581925927c81e010056214a4bd6b6693714`
- Object cache: WordPress default. No Redis and no `object-cache.php`.
- `WP_DEBUG_LOG` on, `WP_DEBUG_DISPLAY` off, `WP_ENVIRONMENT_TYPE=local`, `DISABLE_WP_CRON` on
- Permalinks `/%postname%/`. `router.php` sends non-file requests to `index.php` because PHP's built-in server has no rewrite engine. That router is fixture-only.
- `mu-plugin.php` turns off Action Scheduler's async runner and WooCommerce's setup redirect so the second edit and the Resume step stay on camera. Apply still runs through Resume and the real worker.

Accounts, the product, and the store name are fictional. Passwords are generated per run into `runtime/secrets.json` (mode 600, gitignored) and are not in the video, captions, or JSON evidence.

## Reproduce

From a checkout of this branch, with Docker, Node, Python 3, Pillow, Google Chrome, and GStreamer (`vp8dec`, `openh264enc`, `h264parse`, `mp4mux`, `gst-discoverer-1.0`):

```bash
bash demo/324-seatbelt/run.sh
```

The script builds a disposable compose project, stages the plugin with `wordpress/tests/stage-plugin.sh`, installs WordPress and WooCommerce, seeds Northwind Canvas Tote, creates the grant, records the admin with Playwright, sends the PUT from the host, and writes `demo/324-seatbelt/out/`. It removes the containers on exit. `scenes.json` uses the runner clock. The committed `captions.en.vtt` was fitted to this MP4 because saving GIF frames made that clock longer than the WebM. Check a fresh recording against its own pictures before replacing the captions.

## Publication

Keep these files under `demo/324-seatbelt/out/`. Do not add the MP4, GIF, or poster to `wordpress/assets/`, do not change `readme.txt` for this demo, and do not commit them to the WordPress.org SVN directory. The GIF and poster are both under the repository's 2 MiB non-icon image cap, and they are still demo assets rather than listing banners or icons. Listing banners are 772×250 and 1544×500. Icons are 128 and 256. This poster is a 1440×900 frame of the conflict row.

Do not merge this branch as a plugin release.
