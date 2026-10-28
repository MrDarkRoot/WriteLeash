# Channel matrix

Fetched 28 October 2026. Rank is editorial judgment of fit, permission, and
founder effort. It is not a traffic or conversion ranking. Member counts are
`UNKNOWN` unless the cited page states a number. Founder account access is
`UNKNOWN` for every external community. Nothing below is cleared to post.

Direct requests to `www.reddit.com` failed DNS in this environment
(`Name or service not known`). Reddit rule text below is from page extracts
retrieved the same day through a search fetch of the subreddit and of
`/r/Wordpress/about/rules`. Treat that as a secondary capture of a primary
page, and re-open the rules JSON in a browser before any Reddit post.

## Ranked

### 1. This GitHub repository

- Audience URL: https://github.com/MrDarkRoot/WriteLeash
- Rule used: issue #325 itself. No bulk promotion, no fake engagement, no incentivized reviews. The GitHub acceptable-use page was not re-fetched for this pack.
- Permission: `PROJECT_DOCS_ONLY`
- Problem: a developer or agency can read what the plugin actually does.
- Participation: a reviewed pull request. `has_discussions` is false, so there is no Discussions post to make. Do not open a GitHub Release for a marketing draft.
- Effort: low. Risk: low, if the text stays inside the 0.2.2 boundary.
- Why first: it is the only destination this task is allowed to write, and the claim can be checked against the zip.

### 2. WooCommerce Community Slack, `#self-promotion` only

- Audience URL: https://woocommerce.com/community-slack/
- Rule URL: [WooCommerce Community Code of Conduct](https://developer.woocommerce.com/woocommerce-community-code-of-conduct/), HTTP 200 on 28 October 2026.
- Quote: "The #self-promotion channel is the only place to promote your plugin, SaaS, tool, service, or availability for hire." Also: "No cold DMs." "Disclose your relationship (“I built this” / “I work there”)." "No affiliate links." "Genuine participation should always outweigh self-promotion by a meaningful margin."
- The join page says "Come chat with our community of 25,000+ WooCommerce developers." That sentence is Woo's, fetched the same day. It is not our measurement of reach.
- Permission: `RESTRICTED` (`#self-promotion` after real participation). `#community-help` and direct messages are not a launch channel.
- Problem: store builders comparing ways to change a catalog without losing a newer edit.
- Effort: medium, because the participation rule has to be true first. Risk: high if the same text is cross-posted or sent as a DM. Removal is the stated consequence for cold DMs.
- Account access: `UNKNOWN`.

### 3. r/woocommerce, comments that answer a question

- Audience URL: https://www.reddit.com/r/woocommerce/
- Rule source: subreddit sidebar extract, 28 October 2026. A 30 September 2025 moderator post, [No Solicitation or Hiring Requests](https://www.reddit.com/r/woocommerce/comments/1nu4493/new_rule_no_solicitation_or_hiring_requests/), prohibits hiring and paid requests. An earlier rules post is [Recent changes to post flair and community rules](https://www.reddit.com/r/woocommerce/comments/1f5e8q8/recent_changes_to_post_flair_and_community_rules/) (30 August 2024). The sidebar extract is the later text used here.
- Quote from the sidebar extract: "POSTS — Promotional posts are NEVER ALLOWED." "COMMENTS — Recommendations, references, and links are ALLOWED when they directly help answer the original post." "Affiliate, referral, or monetized links are never allowed." Rule 5 in the same extract: recommendations, "including your own if they are genuinely relevant," belong in comments, "must directly address the user’s needs," and "Links are allowed but may be held for moderation."
- Permission: `HELPFUL_REPLY_ONLY`. A launch post is `NOT_ALLOWED`.
- Problem: bulk price mistakes, sale prices that became zero, imports that did not show on the variable product.
- Effort: medium. Risk: high. A promotional comment can be removed, and a post will be.
- Account access: `UNKNOWN`. Do not create an account for this task.

### 4. WordPress.org support forums

- Audience URL: https://wordpress.org/support/plugin/woocommerce/ and https://wordpress.org/support/plugin/writeleash/
- Rule URL: [Forum Guidelines](https://wordpress.org/support/guidelines/), HTTP 200. Last updated 25 July 2024.
- Quote: "The forums are not a venue to promote your own (or others’) plugins, themes, websites or services." Also listed as a moderation reason: "Unhelpful responses including but not limited to unvetted AI-generated responses" and "Posting only to solicit feedback for a site, theme, or plugin."
- Permission: `HELPFUL_REPLY_ONLY` for a specific answer on an open thread. Promotion and review asks are `NOT_ALLOWED`.
- Problem: the same price-overwrite reports as the drafts in `assets/community-responses.md`. WriteLeash's own forum has no topics, so there is no support interaction to answer.
- Effort: high if the reply is actually checked. Risk: high for an AI paste, a plugin link, or a review ask.
- Account access: `UNKNOWN` for posting. This task did not log into WordPress.org.

### 5. r/Wordpress, one founder-written exception at most

- Audience URL: https://www.reddit.com/r/Wordpress/
- Rule source: search fetch of https://www.reddit.com/r/Wordpress/about/rules on 28 October 2026. Direct DNS failed.
- Quote from that rules payload: "We do not allow posts or comments intended for promotion or advertising. These will be removed. Asking people to DM you is considered as advertising." "Sharing free open-source products/services/projects via Wordpress.org or Github, that are useful to the community, are permitted as long as they don't break any other rule."
- A moderator post on 16 June 2026, [Tweak to rule 1 regarding promotional posts](https://www.reddit.com/r/Wordpress/comments/1u7cme5/tweak_to_rule_1_regarding_promotional_posts/), says promotional titles must start with `[PROMO]` and that the criteria are unchanged: free plugins or themes from WordPress.org or GitHub.
- Permission: `RESTRICTED`. The exception is narrow. A commenter in r/WordpressPlugins reported a ban for answering an old thread with their own plugin ([discussion](https://www.reddit.com/r/WordpressPlugins/comments/1n2tx7v/discussion_advice_dont_make_plugins_for_wordpress/), 29 August 2025). That report is one user's account, not a rule, and it is enough to keep this channel on hold.
- Effort: medium. Risk: high. An AI-prepared draft is not what gets posted. The founder writes any post in their own words, once, with `[PROMO]` if they use the exception.
- Account access: `UNKNOWN`.

### 6. WP Content, article submission after a real URL exists

- Audience URL: https://wpcontent.io/submit/
- Fetched HTTP 200 on 28 October 2026.
- Quote: "Please don't post links directly to your product, plugin or theme pages. If your product has a new release, submitting the release post is fine." "Large amounts of articles submitted from your site in one go likely means it's spam." "First time submissions will be held for review until approved by the team." "You must be logged in to submit."
- Permission: `SUBMISSION_REVIEW`
- Problem: readers looking for a WooCommerce how-to, not a plugin page.
- Blocked now: the five #323 articles have no live URL. There is nothing to submit.
- Effort: medium after publication. Risk: medium (account removal if it looks like spam).
- Account access: `UNKNOWN`.

## Considered and not used

| Channel | Why it is not a target |
| --- | --- |
| The WP Weekly, https://thewpweekly.com/submit/ | HTTP 403 on 28 October 2026. Permission `UNKNOWN`. Do not assume the older submission route is open. |
| Facebook groups for WooCommerce store owners or agencies | Group rules were not readable without an account. Permission `UNKNOWN`. No account was created. |
| r/ecommerce | Rules payload retrieved the same day: "No Self-Promotion" and "Your post/comment will be removed, and you will be banned without warning." Permission `NOT_ALLOWED`. |
| r/ProWordPress | Rules were not retrieved. Permission `UNKNOWN`. Not used. |
| DEV Community | Not re-fetched this session. The October 2026 launch note already marked product posts as a poor fit. Left unused rather than re-stated from memory. |
| Make WordPress Slack `#forums` | The forum guidelines send people there to reach moderators, not to announce a plugin. |
| WooCommerce.com marketplace partner program | WriteLeash is not a WooCommerce.com product. Out of scope. |
| Cold email to a scraped host or agency list | Not a community. Unsolicited bulk mail is out of scope. The three email drafts have no recipients. |
| Paid ads | Issue #325 lists paid ads as a non-goal. |

## Do not post yet

- v0.3.0 is not DISTRIBUTED. See [launch-truth.md](launch-truth.md).
- No external account authorization was verified.
- Every draft that mentions WriteLeash still needs the founder to decide, and on WordPress.org the founder has to vet it. An unvetted AI reply is named as a removal reason.
- Reddit rules need a direct browser check before use, because this environment could not resolve `www.reddit.com`.
