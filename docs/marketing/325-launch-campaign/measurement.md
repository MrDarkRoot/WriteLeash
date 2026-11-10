# Measurement

Baseline fetched 10 November 2026. No plugin telemetry is added.

A cell is either a value from the source on the fetch date, or `NOT_AVAILABLE`.
Do not carry a number forward without a new fetch. Do not put download events
in the active-install cell. Do not turn API `active_installs: 0` into a precise
user count. Do not turn a UTM link into a referral.

The machine-readable seed is
[metrics/2026-11-10-baseline.json](metrics/2026-11-10-baseline.json).
Refresh with [metrics/refresh.sh](metrics/refresh.sh). That script only talks
to WordPress.org.

## 10 November 2026

Distribution of 0.3.0 was already public (`last_updated` 2026-10-28). Campaign
posts have not started. See [published-actions.md](published-actions.md).

| Row | Value | Source |
| --- | --- | --- |
| Installs and active installs | API `active_installs` 0, the "Fewer than 10" bucket. Download events 2026-10-11 through 2026-11-09: **322**. Last date in the series: 2026-11-09. Download events are not this row. | [baseline](metrics/2026-11-10-baseline.json) |
| Reviews and support | `num_ratings` 0, `rating` 0, `support_threads` 0, `support_threads_resolved` 0. Reviews page: no reviews submitted. Support page: no topics. | Same baseline |
| Search impressions and clicks | `NOT_AVAILABLE` | No Search Console property. |
| Referral visits | `NOT_AVAILABLE` | GitHub Pages root and the five guides returned HTTP 200. This pack has no Search Console property and no Pages analytics export. WordPress.org does not report UTM. |
| Campaign activity | External posts, emails, review requests, and directory submissions: **0**. | [published-actions.md](published-actions.md) |
| Verified conversions | `NOT_AVAILABLE` | The download series does not attribute a link or a UTM parameter. |

### Download events by day

| Date | Events | Date | Events |
| --- | --- | --- | --- |
| 2026-10-11 | 4 | 2026-10-26 | 7 |
| 2026-10-12 | 5 | 2026-10-27 | 2 |
| 2026-10-13 | 57 | 2026-10-28 | 41 |
| 2026-10-14 | 17 | 2026-10-29 | 18 |
| 2026-10-15 | 4 | 2026-10-30 | 4 |
| 2026-10-16 | 4 | 2026-10-31 | 7 |
| 2026-10-17 | 4 | 2026-11-01 | 5 |
| 2026-10-18 | 38 | 2026-11-02 | 6 |
| 2026-10-19 | 17 | 2026-11-03 | 7 |
| 2026-10-20 | 10 | 2026-11-04 | 7 |
| 2026-10-21 | 9 | 2026-11-05 | 2 |
| 2026-10-22 | 8 | 2026-11-06 | 6 |
| 2026-10-23 | 3 | 2026-11-07 | 0 |
| 2026-10-24 | 3 | 2026-11-08 | 13 |
| 2026-10-25 | 12 | 2026-11-09 | 2 |

Sum: 322.

## How to fill a later week

1. Run `bash metrics/refresh.sh` and quote the new file. Leave the baseline in place.
2. Leave search, referral, and verified conversions as `NOT_AVAILABLE` until a property the project controls exists.
3. Count external posts only from URLs that were actually published.
4. A change in the active-install bucket is not proof that a post caused an install.

## Experimental targets

Not outcomes. Not achieved.

- Three to five honest reviews from people who use the plugin. Baseline is zero reviews. Do not compensate, script a star rating, or ask before a support thread is resolved.
- The public active-install text moves off "Fewer than 10."
- Any WriteLeash support topic that arrives gets a specific answer. Baseline is zero topics.
- Ten genuinely helpful community conversations. Published conversations: 0.
