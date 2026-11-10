# Measurement plan for issue #323

Checked 10 November 2026. No tracking code is added to the WordPress plugin or to the static guides. The 0.3.0 listing does not add a phone-home for these articles.

## What can be measured today

| Signal | Status | Evidence |
| --- | --- | --- |
| Published article URLs | LIVE | HTTP 200 on 10 November 2026 for `https://mrdarkroot.github.io/WriteLeash/` and the five article URLs in [publication-manifest.json](publication-manifest.json). |
| Search impressions | NOT_AVAILABLE | No Search Console property or export is in the repository. |
| Search clicks | NOT_AVAILABLE | Same. |
| Referral visits from the articles | NOT_AVAILABLE | No site-analytics property is in the repository. The static site has no analytics snippet. |
| Plugin installs attributable to an article | NOT_AVAILABLE | WordPress.org showed an active-installation bucket for WriteLeash of fewer than 10 on 10 November 2026. The directory does not give a per-article install count. |
| On-site plugin telemetry | Not used | Do not add it. |

## What to turn on after a real URL returns HTTP 200

1. Confirm the live URL returns the article, the disclosure, and one link to `https://wordpress.org/plugins/writeleash/` plus one link to the GitHub repository.
2. Add the property in Google Search Console and Bing Webmaster Tools only for a host the project controls. Record impressions and clicks for each article URL. Until that property exists, both stay `NOT_AVAILABLE`.
3. Keep the WordPress.org link as a normal link. Query parameters were not added. WordPress.org's public plugin stats are not an article-referral report, so a `utm_` parameter would not create an attributable install number. Do not describe UTM clicks as installs.
4. Once a quarter, note the directory's active-installation bucket and the date. A bucket change is not proof that an article caused an install.
5. Do not add a phone-home, a pixel, or an install beacon to the plugin.

## Baselines recorded on 10 November 2026

- Directory version: 0.3.0
- Listing last modified: 28 October 2026, as shown on the plugin page
- Active installations: fewer than 10
- Tested up to, as displayed: 7.1.4. Exercised pairs are the ones named in the requirements text, not this field alone
- Search volume: not measured

The 27 October 2026 baseline was directory version 0.2.2 and the same active-installation bucket. That version is no longer the listing.

## Reviews and forums

Do not ask for reviews from this content set. Do not post the articles into the WooCommerce support forum. [docs/launch-v0.2.md](../../launch-v0.2.md) already marks forum promotion and unmodified AI posts on r/WordPress as out of bounds.
