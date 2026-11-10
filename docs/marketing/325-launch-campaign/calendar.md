# Thirty-day calendar

Status: **not started.**

Day 0 is the date issue #313 is verified DISTRIBUTED: the public zip version is
0.3.0, the SVN tag `0.3.0` exists, and the plugin information API shows that
version. On 28 October 2026 none of those are true. Do not convert the dates
below into October or November 2026 dates.

Until day 0, the allowed work is research, drafts, measurement, and a helpful
reply on an open thread when the founder is allowed to post and has checked
the answer. That pre-launch work is what this pull request contains. It is not
week 1 of the campaign.

Each week ends with a comment on issue #325 using the table in
[measurement.md](measurement.md). Targets in that file are not results.

## Before day 0 (this pull request)

| Work | Source | State |
| --- | --- | --- |
| Public version check | [launch-truth.md](launch-truth.md) | Done 2026-10-28. Directory version 0.2.2. |
| Channel rules | [channel-matrix.md](channel-matrix.md) | Done. No posts. |
| Drafts | [assets/](assets/) | Written. Not cleared to publish. |
| Baseline | [measurement.md](measurement.md) | Recorded. Search, referral, and conversions are `NOT_AVAILABLE`. |

## Week 1, days 1–7 — see the stored price before it is written

Theme: [assets/weekly-themes.md](assets/weekly-themes.md), week 1.

| Action | Do it only if | Source |
| --- | --- | --- |
| Confirm the WordPress.org readme matches the zip that was just distributed. | The zip hash and Stable tag were checked after distribution, not only after an SVN commit. | [launch-truth.md](launch-truth.md) |
| Publish at most three of the five #323 guides, on a host the project controls. | [PR #331](https://github.com/MrDarkRoot/WriteLeash/pull/331) is merged or otherwise approved, and a real canonical URL exists. GitHub Markdown is not a published article. | `docs/marketing/323-seo-content/` on that branch |
| Use a demo GIF only when the recording is of the distributed build. | The #333 recording is an unreleased repository build. [PR #332](https://github.com/MrDarkRoot/WriteLeash/pull/332) is not merged. Neither is cleared against 0.2.2, and neither is cleared against 0.3.0 until the bytes match. | `demo/324-third-party/` on `main`; PR #332 |
| One founder-controlled note, if wanted. | The note uses [assets/launch-announcement.md](assets/launch-announcement.md) updated to the verified version, with v0.3.0 claims added only from the shipped readme. | launch announcement draft |

No Slack, Reddit, or forum launch post in this week.

## Week 2, days 8–14 — answer a real catalog problem

Theme: week 2 of the themes file. Channel rules: [channel-matrix.md](channel-matrix.md).

| Action | Gate | Source |
| --- | --- | --- |
| Reply on an open thread where the question matches a draft. | The thread is open. The founder rewrites the draft. WordPress.org gets no plugin link unless the reply is still the answer with the link removed. r/woocommerce gets a comment, not a post. | [assets/community-responses.md](assets/community-responses.md) |
| One `#self-promotion` note. | The founder already participates in the WooCommerce Slack, and this note is not their usual post. No other channel gets the same text. No direct message. | [assets/social-posts.md](assets/social-posts.md), post 2 |
| Skip r/Wordpress unless the founder writes the `[PROMO]` post themselves. | Rules re-checked in a browser. Direct DNS to Reddit failed in the environment that wrote this file. | social post 4 |

Aim at helpful replies. The issue's "10 conversations" target is not met by drafts.

## Week 3, days 15–21 — ask for a review only after support

Theme: week 3.

| Action | Gate | Source |
| --- | --- | --- |
| Answer any WriteLeash support topic. | A topic exists. On 28 October 2026 the forum was empty. | [measurement.md](measurement.md) |
| Send the review request once in that same thread. | The person has confirmed the issue is resolved. Nothing is offered. No star rating is named. | [assets/review-request.md](assets/review-request.md) |
| If no thread was resolved, write that on issue #325 and do not ask anyone. | Always. | published-actions log |

## Week 4, days 22–30 — one relevant partner note, then stop

Theme: week 4.

| Action | Gate | Source |
| --- | --- | --- |
| Send one email to one person the founder already has a reason to write to. | The founder picks the person. No scraped list. No second chase unless they reply. | [assets/partner-emails.md](assets/partner-emails.md) |
| Submit one article URL to WP Content, if an article was published in week 1. | The submit page still allows a release or guide URL and forbids a bare plugin link. The page returned HTTP 200 on 28 October 2026. Login is required. | social post 5 |
| Retrospective comment on #325. | What was published, what was refused, and the new scorecard. Close nothing. | measurement table |

## Standing refusals for all 30 days

- No paid ads, no paid installs, no incentivized reviews.
- No UTM on the WordPress.org listing. See [utm.md](utm.md).
- No plugin telemetry.
- No WordPress.org SVN commit from this issue.
- No claim that restricted-agent denial or grant stock protection is installable
  unless that exact behavior is in the distributed zip and the readme says so.
