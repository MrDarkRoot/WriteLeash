# Measurement plan for issue #323

Checked 27 October 2026. No tracking code is added to the WordPress plugin. The 0.2.2 listing already says the plugin has no tracking.

## What can be measured today

| Signal | Status | Evidence |
| --- | --- | --- |
| Published article URLs | NOT_AVAILABLE | No page has been published. See [publication-manifest.json](publication-manifest.json). |
| Search impressions | NOT_AVAILABLE | No Search Console property or export is in the repository. |
| Search clicks | NOT_AVAILABLE | Same. |
| Referral visits from the articles | NOT_AVAILABLE | There is no project site and no site-analytics property in the repository. |
| Plugin installs attributable to an article | NOT_AVAILABLE | WordPress.org shows an active-installation bucket for [WriteLeash](https://wordpress.org/plugins/writeleash/): fewer than 10, as read on 27 October 2026. The directory does not give plugin authors a per-article install count. |
| On-site plugin telemetry | Not used | Do not add it. |

## What to turn on after a real URL exists

1. Verify the live URL returns the article, the disclosure, and one link to `https://wordpress.org/plugins/writeleash/`.
2. Add the property in Google Search Console and Bing Webmaster Tools only for a host the project controls. Record impressions and clicks for each article URL. Until that property exists, both stay `NOT_AVAILABLE`.
3. Keep the WordPress.org link as a normal link. Query parameters were not added. WordPress.org's public plugin stats are not an article-referral report, so a `utm_` parameter would not create an attributable install number. Do not describe UTM clicks as installs.
4. Once a quarter, note the directory's active-installation bucket and the date. A bucket change is not proof that an article caused an install.
5. Do not add a phone-home, a pixel, or an install beacon to the plugin.

## Baselines already recorded

- Directory version: 0.2.2
- Listing last modified: 18 October 2026, as shown on the plugin page on 27 October 2026
- Active installations: fewer than 10
- Article URLs: none

## Reviews and forums

Do not ask for reviews from this content set. Do not post the articles into the WooCommerce support forum. [docs/launch-v0.2.md](../../launch-v0.2.md) already marks forum promotion and unmodified AI posts on r/WordPress as out of bounds.
