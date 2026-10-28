# Measurement

Baseline fetched 28 October 2026. No plugin telemetry is added. The public
0.2.2 listing already says the plugin has no tracking.

A cell is either a value from the source URL on the fetch date, or the token
`NOT_AVAILABLE`. Do not carry a number into a later week without a new fetch.
Do not put download events in the active-install cell. Do not turn "Fewer than
10" plus API `active_installs: 0` into a precise user count.

Experimental targets are in their own section. They are not outcomes, and none
are marked achieved.

## Week of 28 October 2026

This week is pre-distribution. Campaign execution has not started.

| Row | Value | Source | Fetch date |
| --- | --- | --- | --- |
| Installs and active installs | Public page: `Active installations Fewer than 10`. API field `active_installs`: `0`. Same bucket, not a precise count. Download events 2026-10-11 through 2026-10-27: **204**. Last date in the series: 2026-10-27. 2026-10-28 was absent. Download events are not this row's install count. | [plugin page](https://wordpress.org/plugins/writeleash/), [plugin API](https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=writeleash), [download stats](https://api.wordpress.org/stats/plugin/1.0/downloads.php?slug=writeleash&limit=90) | 2026-10-28 |
| Reviews and support | Reviews page: `No reviews have been submitted yet.` Support page: `No topics found. Select another view or start a new post.` API: `num_ratings` 0, `rating` 0, `support_threads` 0, `support_threads_resolved` 0. | [reviews](https://wordpress.org/support/plugin/writeleash/reviews/), [support](https://wordpress.org/support/plugin/writeleash/), plugin API above | 2026-10-28 |
| Search impressions and clicks | `NOT_AVAILABLE` | No public Search Console or Bing Webmaster property. | 2026-10-28 |
| Referral visits | `NOT_AVAILABLE` | No project site. GitHub Pages returned HTTP 404. | 2026-10-28 |
| Campaign activity | External posts, emails, and review requests: **0**. See [published-actions.md](published-actions.md). An independent analytics meter of campaign activity is `NOT_AVAILABLE`. | This repository's activity log. Not a third-party meter. | 2026-10-28 |
| Verified conversions | `NOT_AVAILABLE` | Directory download stats do not attribute a link, a UTM parameter, or an article to an install. | 2026-10-28 |

### Download events by day

These are the raw series. They are not users.

| Date | Events |
| --- | --- |
| 2026-10-11 | 4 |
| 2026-10-12 | 5 |
| 2026-10-13 | 57 |
| 2026-10-14 | 17 |
| 2026-10-15 | 4 |
| 2026-10-16 | 4 |
| 2026-10-17 | 4 |
| 2026-10-18 | 38 |
| 2026-10-19 | 17 |
| 2026-10-20 | 10 |
| 2026-10-21 | 9 |
| 2026-10-22 | 8 |
| 2026-10-23 | 3 |
| 2026-10-24 | 3 |
| 2026-10-25 | 12 |
| 2026-10-26 | 7 |
| 2026-10-27 | 2 |

Sum: 204.

## How to fill a later week

1. Re-fetch the three WordPress.org URLs in the table. Quote the new text.
2. Leave search, referral, and verified conversions as `NOT_AVAILABLE` until a
   property the project controls exists. Record the property URL when it does.
3. Count external posts only from URLs that were actually published. A draft in
   this directory is not activity.
4. A change in the active-install bucket is not proof that a post caused an
   install.

## Experimental targets

Not outcomes. Not achieved. No conversion rate.

The issue names these as hopes, not guarantees:

- Three to five honest reviews from people who use the plugin. Baseline is zero
  reviews. Do not compensate, script the star rating, or ask before a support
  thread is resolved. See plugin guideline 9: compensating or pressuring others
  for reviews is prohibited.
  [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/),
  fetched 28 October 2026.
- The public active-install bucket moves off the text `Fewer than 10`.
- Any WriteLeash support topic that arrives gets a specific answer. Baseline is
  zero topics, so there is nothing to answer.
- After a site the project controls exists, count that site's visits separately
  from WordPress.org download events.

Issue #325's "at least 10 genuinely helpful community conversations" is also a
target, not a result. Published conversations so far: 0.
