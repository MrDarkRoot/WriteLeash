# Sprint integration audit, 2026-11-10

Follow-up, same day, after 12:10 UTC. The verdicts below are the 12:10 snapshot. They are not the current heads.

- #336 moved to `17ae7dc1abd6c882017eae6863b8efce9533a5b3`. The false "catalog does not change until a person approves" sentence is gone. Local readme and claim-matrix validators passed on that commit. Directory publication is still a separate SVN approval.
- #331 moved to `c09c01f64ecf6280260d02356156b1c6531e35df`. The 0.2.2 listing sentence and the "no project site" sentence were corrected. Pages were HTTP 200 on 10 November 2026.
- #337 moved to `6de230155ff2af5d1eeb7c497973e3848c0fac74`. The readme-versus-zip mismatch and the Pages 404 claim were corrected. Remaining 404 notes are third-party submission pages and a media host, not the guides.
- #332 moved to `8559c6ae4d1ae7108948ad13ba9dc4ab84068cda`. A 2026-11-10 note says the live directory is 0.3.0 and the films are earlier trees. Captured JSON and captions were not rewritten.
- #338 stayed `f233f8ba15b3f812dff691022df3e89eb7772af0`. Historical Plugin Check later passed. Admin and undo were still running at the follow-up check.
- #339 evidence commit `3f57ebbbb352367eb2e9c4ebed9dd55a69ca90a3` records a PASS of the container that ran `115dc30`, which contains the #338 filesystem reader.

# Original 12:10 snapshot

Auditor worktree: `qa/sprint-integration-audit` at `57c0de9d41a8c2d915394b7eb186e650f63db8f6` (`origin/main`, merge of PR #335). `git status` was clean before this report. No half-applied merge. No issues were closed. No runtime code is changed by this pull request.

Watch window: open PRs polled every 3 minutes from 2026-11-10 11:29:11Z through 12:08:38Z (14 polls). Comments below are on the SHAs still current at 12:10:01Z.

## Closure recommendations

Do not close any of these from this audit.

### #280 — KEEP OPEN

Pair-rule code is on `main` and in the distributed plugin. `Free_Support_Contract` keeps modern floors `WORDPRESS_MIN = 7.0` and `WOOCOMMERCE_MIN = 10.0.0`, with a legacy band only for WordPress 6.7 up to but not including 7.0 and WooCommerce 9.0.0 up to but not including 10.0.0. `support_band()` is the gate. Directory headers are `Requires at least: 6.7` and `WC requires at least: 9.0` in both git and the 0.3.0 zip. Those headers are minimums, not a rectangle.

PR #330 merged at `3be680557c476f30e93e45cf7bf0bc62009ef8c6`. Its acceptance jobs succeeded for `legacy-wp67-woo90`, `legacy-wp67-woo90-redis`, `legacy-wp68-woo99`, `below-wordpress`, `below-woo`, the current 7.1.2 / 11.1.2 cells, previous WordPress, previous Woo, unsupported Woo 9.9.7 on WordPress 7.1.2, and PHP 7.4 / 8.0 / 8.1. `wordpress/tests/acceptance/EVIDENCE.md` records the same pairs and says the local transcripts were not repeated after #329 merged. The GitHub acceptance jobs ran on the #330 head, which already contained #329. Between `3be6805` and `57c0de9`, `class-free-support-contract.php` did not change. Plugin edits were the 0.3.0 version strings, readme, changelog, and grant-contract comments.

PR #335 (`57c0de9`) left the `acceptance` check **SKIPPED**. That skip is not a new compatibility pass. The issue also asked for softened "Current limitations" wording. Both git and the live readme still say custom product types and multisite are "unsupported." The issue is not fully met.

### #306 — recommend CLOSE, do not close here

Children #307, #308, #309, #310, #311, #312, and #313 are closed. PR #328 (`796fe096`, product-create denial) and PR #329 (`c7d5e876`, non-grant writes) merged with `CI_COVERAGE` success. `Perimeter_Guard::should_enforce_save()` denies a restricted actor when the product id is still below 1, before `wp_insert_post`. That method is in the published PHP: the 0.3.0 zip matches `main` on every payload except `readme.txt`.

`Grant_Repository::is_restricted_actor()` still fails open when the grant store cannot be read. `FREE-GRANT-CONTRACT.md` and the readme already say that is not a universal fail-closed guarantee. Direct `wp_insert_post` of a product, outside the Woo save hook, stays an unmonitored path. Those are published limits, not open children of this umbrella. Closing #306 would not close #280 or #322.

### #322 — KEEP OPEN

Not closeable. The listing text this issue asks for is not what WordPress.org serves.

| Surface | Short description |
| --- | --- |
| Git `main` `wordpress/writeleash/readme.txt` | Protection against accidental WooCommerce price and stock overwrites, with Preview, conflicts, History and eligible Undo. (121 characters) |
| SVN trunk, tag `0.3.0`, and `writeleash.0.3.0.zip` | See WooCommerce price changes before they go live. Keep newer edits, undo eligible prices, and leash restricted agents. (119 characters) |

`git log -S` does not find the live short description in git. The live description still opens with "One bulk edit can turn a $199 product into $19." Tags on the live listing are `woocommerce`, `prices`, `stock`, `variations`, `undo`. The seatbelt limitation paragraph is present, so the live page does not claim a firewall. It is still not the #322 reposition.

Live check, 2026-11-10: plugin API version `0.3.0`, `requires` 6.7, `tested` 7.1.4, `requires_php` 7.4, `last_updated` 2026-10-28 2:58pm GMT. `https://downloads.wordpress.org/plugin/writeleash.latest-stable.zip` redirects to `writeleash.0.3.0.zip`, 1,618,335 bytes, SHA-256 `d0a44133d17fe1cd4dea564dc6ad7a3578288728a80e8b074e6e1b87481bd475`, `Last-Modified: Wed, 28 Oct 2026 14:59:05 GMT`.

## #331 and #332

Both are mergeable and not behind `main` after their updates. Both still fail the claim check.

### #331 — FAIL

SHA `34a18951bbbdee9f35f564f8d1d023800e4a918d`, branch `docs/323-seo-content`. Comment: https://github.com/MrDarkRoot/WriteLeash/pull/331#issuecomment-6766951601

The old head `5ad7e0b` said the installable plugin was 0.2.2. That was true on 27 October 2026 and is false now. The new head retargets the articles at 0.3.0 and keeps the firewall / agent-safe / direct-SQL negations. `PR_FAST` and `CI_COVERAGE` succeeded. `publication-manifest.json` says `LIVE`, and the GitHub Pages URLs returned HTTP 200 during this review.

`docs/marketing/323-seo-content/keyword-evidence.md` still calls the live directory the 0.2.2 listing and says there is no project site. That appendix is stale. No runtime code.

### #332 — FAIL

SHA `e585712423368da247d0a8d1be3faa1dff2888a5`, branch `demo/324-seatbelt-video`. Comment: https://github.com/MrDarkRoot/WriteLeash/pull/332#issuecomment-6766951988

Compared with merged PR #333 (`f43d59397bba6094bf65aafeca4939143bce784b`, head `f7b4853e7aa3c7b1392dc0d9acdc2733d73cea57`): #333 is already on `main` and records AddSynapse 1.3.5 calling `WC_Product::save()` as a restricted shop manager. Header on camera was 0.2.0 at `0e4ac688`. It does not replace the seatbelt film, and this PR does not replace #333. `demo/324-third-party/SOCIAL.md` on this head says the film is not the 0.2.2 package and not public 0.3.0.

The seatbelt recording is still the `3be6805` tree, plugin header 0.2.0. `demo/324-seatbelt/out/versions.json`, `run.sh`, and `out/captions.en.vtt` still name the directory version as 0.2.2. The directory is 0.3.0. Credentials in the compose file are disposable fixture passwords, not production secrets. `PR_FAST`, `CI_COVERAGE`, and native succeeded. Acceptance skipped. No enforcement change.

## Pull requests reviewed in the watch

| PR | SHA | Branch | Verdict |
| --- | --- | --- | --- |
| #331 | `34a18951bbbdee9f35f564f8d1d023800e4a918d` | `docs/323-seo-content` | FAIL |
| #332 | `e585712423368da247d0a8d1be3faa1dff2888a5` | `demo/324-seatbelt-video` | FAIL |
| #336 | `b524248edc071d967c76aebfe01067da9e7d38c6` | `docs/322-merchant-listing` | FAIL |
| #337 | `14eee1e320c14302cd72046b7214fe3c87a9860e` | `docs/325-v030-distribution` | FAIL |
| #338 | `f233f8ba15b3f812dff691022df3e89eb7772af0` | `feat/326-protection-report` | BLOCKED |
| #339 | `115dc30bdb5afbd0b284ff0cf8676942f92002a6` | `feat/327-disposable-woo-demo` | FAIL |

### #336 — FAIL

https://github.com/MrDarkRoot/WriteLeash/pull/336#issuecomment-6766952399

`PR_FAST` and `CI_COVERAGE` succeeded. The only runtime edit is the plugin header Description in `wordpress/writeleash/writeleash.php`. Version stays 0.3.0. The seatbelt sentence remains in `readme.txt`. The new opening, "the catalog does not change until a person approves," is broader than the grant contract and broader than the conversion note in `docs/marketing/322-listing-conversion.md`. `CLAIM-MATRIX.md` row 21 was not updated. This PR does not publish the directory, so #322 stays open anyway.

### #337 — FAIL

https://github.com/MrDarkRoot/WriteLeash/pull/337#issuecomment-6766952857

Docs only. The 0.3.0 version, requirement, tested value, and zip length match the live fetch. Drafts keep the firewall and direct-SQL limits and do not call the AddSynapse film the distributed zip. `launch-truth.md` is wrong where it says the `main` readme matches the distributed listing. Only `readme.txt` differs, and the short descriptions differ as in the #322 table. It also says GitHub Pages returned HTTP 404. The pages were HTTP 200 at review time. Download sum 322 was not rechecked. `metrics/refresh.sh` was not run.

### #338 — BLOCKED

https://github.com/MrDarkRoot/WriteLeash/pull/338#issuecomment-6766953288

Read-only report. `class-protection-report-query.php` uses `SELECT` and `WP_Filesystem` on the local debug log. `class-protection-report-admin.php` does not call `wp_remote_` or `curl_`. It does not change `Perimeter_Guard`. Head `141d439` failed historical Plugin Check on `fopen` / `fclose`. `f233f8b` replaced that read. On `f233f8b`, historical and the redirection adapter succeeded and `PR_FAST` succeeded. At 12:10 UTC, `admin`, `jobs`, `journal`, and `undo` were still running, so `CI_COVERAGE` was not green. Acceptance stayed skipped. A log over the byte cap now returns no rows. The unit test was not run locally.

### #339 — FAIL

https://github.com/MrDarkRoot/WriteLeash/pull/339#issuecomment-6766953705

Disposable lab only. Evidence shows a grant job HTTP 201 (40.00 to 30.00) and a REST PUT HTTP 403 with the notebook still 25.00, and `http_requests_during_report` 0. No production credential is in `out/evidence.json`. Compose passwords are the disposable fixtures. `curl` in `run.sh` fetches the public WooCommerce zip and the local site.

`out/evidence.json` and `VERSIONS.md` record git `141d439` and tree `ef809180`. The head is `115dc30`, which includes the later `WP_Filesystem` reader and a `check.php` load of `wp-admin/includes/file.php`. The PASS was not re-recorded on this head. Woo admin/jobs/historical checks are skipped on this SHA even though the report classes are in the diff. `PR_FAST`, `CI_COVERAGE`, and native succeeded. The demo was not re-run here.

## NOT_READY

No pull request after the watch:

- `docs/323-publish-site` — not on `origin`. The static pages are served from branch `docs/323-seo-pages` at `3445680abd944e5260e5d3ade7cd5bceb29147cf` with no open PR. Article copy is in #331.
- `demo/324-v030-proof` — no separate PR. The social-copy commit `e585712` is the head of #332.
- `feat/327-disposable-woo-demo` arrived as #339 and is reviewed above, not left NOT_READY.

## Claim spot-check

`wordpress/writeleash/readme.txt` on `main` and `wordpress/writeleash/FREE-GRANT-CONTRACT.md` use firewall, universal agent-safe, and direct SQL only as denials. The readme sentence is "price and stock"; the contract says "price/stock". `CLAIM-MATRIX.md` row 20 locks the readme wording. Neither file claims universal protection.

The live zip readme also contains that seatbelt denial. Its failure is the lead sentence, not a missing negation.

Stale on `main` today: `docs/marketing/325-launch-campaign/launch-truth.md` still says v0.3.0 is not DISTRIBUTED and the directory is 0.2.2. That was the 28 October note. #337 tries to replace it and does not yet do so accurately.

`class-free-support-contract.php` does not claim the directory floor is a supported rectangle.

## Tests

| Check | Result |
| --- | --- |
| Plugin API, SVN trunk short description, zip download, SHA-256, payload diff against `57c0de9` | Done. Only `readme.txt` differs. |
| GitHub check rolls for #328, #329, #330, #333, #335, and the open heads above | Read from the API. Not re-run. |
| GitHub Pages HTTP status for the three #331 URLs | HTTP 200 on 2026-11-10. |
| `acceptance` on PR #335 | Observed SKIPPED. Not a pass. |
| `wordpress/tests/admin/protection-report-unit.php` | NOT_TESTED locally. |
| Container journeys, demo `run.sh`, `metrics/refresh.sh` | NOT_TESTED. |
| Download-event sum claimed by #337 | NOT_TESTED. |
