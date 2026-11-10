# Media QA for the AddSynapse negative control

Recorded 2026-10-28 from `bash demo/324-third-party/run.sh`. Video duration 68.44 s, 1440×900. Captions were derived from the 2 fps frames of that file, then checked against the same frames. This note is the visual pass. `out/media-qa.json` is the automated pass: 29 checks, 0 failed.

The colored square in the sidebar is the recorder's timing fiducial. It is not a WriteLeash or AddSynapse control.

## Automated

| Check | Result | Evidence |
| --- | --- | --- |
| MP4 decodes, 68.44 s, 1440×900, 3,752,012 bytes | PASS | `gst-discoverer` in `video-report.json` matches the file size |
| WebM decodes, 68.44 s, 1440×900, 5,737,427 bytes | PASS | Same report. Duration matches the MP4 |
| GIF 15.0 s, 960×600, 30 frames, loop 0, 2,437,633 bytes | PASS | `image-report.json`. All 30 frames hash differently at 48×30 |
| Poster 1440×900, denial marker | PASS | `frame-00092.png` at 46.0 s, inside the denied cue |
| Every cue inside 68.44 s, no overlap, no negative time | PASS | `sync_captions.py validate` |
| Cue order and disclaimer | PASS | Last cue says this is an unreleased build, not the WordPress.org 0.2.2 package |
| No black story frame | PASS | 138 frames, none with mean luminance under 8 |
| Evidence, versions, grant expiry, and the three database snapshots agree | PASS | `media-qa.json` |
| Secret scan of text outputs | PASS | Generated passwords are not in `out/` text files |

OpenH264 printed `cmInitParaError` while encoding. `gst-discoverer` still reported a 68.44 s H.264 file, and the extracted frames match the WebM. The warning is not treated as a failed encode.

## Visual sample

Times are the 2 fps frame clock (`frame N` is about `N / 2` seconds).

| Moment | Frame | Result | What is on screen |
| --- | --- | --- | --- |
| Lead-in | 0 | PASS | White frame. No caption covers it. Meet starts at 1.5 s. |
| Admin shell | 2 | PASS | AddSynapse menu selected, content area still empty. Still before the meet cue. |
| Chat open | 3 and 8 | PASS | Riley Agent is logged in. Store Assistant is open. No key is configured. Red marker. |
| Typing the tote request | 16 | PASS | Composer shows the Northwind Canvas Tote sentence being typed. Green marker. Ask cue. |
| Notice | 28 and 36 | PASS | Pink banner: the AI is not set up, slash commands work without it. The composer still shows the typed sentence, and the conversation list already says New conversation. The caption calls this AddSynapse's own notice. It does not call it a model reply. |
| Slash command | 38 | PASS | Composer shows `/price id=11 set 49.99` being typed. Yellow marker. |
| Settled preview | 56 | PASS | Harborline Wool Cap, regular price `$40.00` to `$49.99`, Approve and Reject, "Nothing has changed yet." Magenta marker. Preview cue. |
| Yes, still separate | 66 and 72 | PASS | Approve has opened the confirmation. The button is **Yes, apply the changes**. Nothing saved yet. Cyan marker. Confirm cue 33.0–38.5 s. |
| Denial | 78 and poster 92 | PASS | Status Failed. Result: WooCommerce refused the change because WriteLeash denied the write. `0 changes applied. 1 failed.` Orange marker. Denied cue starts at 38.5 s. |
| Price unchanged | 112 | PASS | Products search Harborline. Harborline Wool Cap, SKU WL-AI-CAP, price `$40.00`. WooCommerce 11.1.2. Purple marker covers the Collapse menu label. The price cell is readable. |
| Board | 128 | PASS | Unreleased repository build, not the WordPress.org 0.2.2 package. HTTP 200. Cap `40.00 → 40.00`. Tote `100.00 → 100.00`. Lantern `30.00 → 55.00` for unrestricted Morgan. Audit actor 2, object 11, `product_save`, `writeleash_perimeter_denied`. |

Approve and Yes are different screens. The denial is a third screen, after Yes.

## GIF

| Check | Result |
| --- | --- |
| Loop shows the command, the Yes button, the denial, and the unchanged prices | PASS |
| Not a run of identical holds | PASS. 30 of 30 sampled frames differ |
| Readable at 960×600 | PASS. The denial sentence and the `$40.00` before-value are readable. The sidebar fiducial is dulled by the 48-color palette. |

## Credentials and consistency

| Check | Result |
| --- | --- |
| Passwords, API keys, or application passwords in the sampled frames | PASS. None seen. Login is off camera. |
| Passwords in committed JSON, VTT, or README | PASS. Secret scan. |
| Grant id, fictional names, and the audit line are visible | PASS. Those are the demonstration, not secrets. |
| Source SHA, plugin versions, expiry, and database hash | PASS. `versions.json` git commit `0e4ac6881006eabd95e34ec1530a3357401d3226`, plugin tree `f8cf392ba30a40334c0f1ab67af0ee444ffaee08`, header 0.2.0, AddSynapse 1.3.5, expiry `2026-11-27 05:09:46` UTC in the grant row and the evidence. Database sha256 `d47112ccd9a495514863253e131da83cce406009c650bedf6e15cb2b20e4f923` on all three snapshots. |
| Full-frame OCR of every pixel | NOT TESTED. The pass above is the sampled frames plus the text-file secret scan. |

## Not claimed

| Check | Result |
| --- | --- |
| A live model was called | NOT TESTED. Cost $0. No key was configured. |
| AddSynapse hallucinated | NOT TESTED. The slash command was typed. The action was confirmed. |
| WordPress.org 0.2.2 package was the recorded build | NOT TESTED. |
| MariaDB or Redis | NOT TESTED. |
| The remote AddSynapse key path | NOT TESTED. |
