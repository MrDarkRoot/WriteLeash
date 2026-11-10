# Channel matrix

Checked 10 November 2026. Rank is fit and permission, not traffic. Member
counts are `UNKNOWN` unless the cited page states a number. Nothing below was
posted.

Direct requests to `www.reddit.com` returned no HTTP response in this
environment. Reddit rule text is the 28 October 2026 capture already in this
file's history, not a fresh rules JSON. Re-open the rules in a browser before
any Reddit post. Until then Reddit is **BLOCKED: no account.**

## Ranked

### 1. This GitHub repository

- Audience: https://github.com/MrDarkRoot/WriteLeash
- Permission: `PROJECT_DOCS_ONLY`
- `has_discussions` is false. Do not enable Discussions. Do not open a GitHub Release for a draft.
- The allowed write from this task is a docs pull request and a comment on issue #325. That comment is not an acquisition channel.
- Risk: low if the text stays inside the 0.3.0 limits in [launch-truth.md](launch-truth.md).

### 2. WooCommerce Community Slack, `#self-promotion` only

- Join page: https://woocommerce.com/community-slack/
- Rule: [WooCommerce Community Code of Conduct](https://developer.woocommerce.com/woocommerce-community-code-of-conduct/), fetched 28 October 2026.
- Quote from that fetch: "The #self-promotion channel is the only place to promote your plugin, SaaS, tool, service, or availability for hire." Also: "No cold DMs." Disclose "I built this." No affiliate links. Participation has to outweigh promotion.
- The join page's "25,000+" sentence is Woo's, not our measurement.
- Permission: `RESTRICTED`. Account access: `UNKNOWN`. **BLOCKED: no account.**

### 3. r/woocommerce, comments that answer a question

- Audience: https://www.reddit.com/r/woocommerce/
- Rule source: sidebar extract on 28 October 2026, not re-fetched today.
- Quote from that extract: "POSTS — Promotional posts are NEVER ALLOWED." Comments may recommend something relevant, including your own, when it answers the question. Affiliate links are not allowed.
- Permission: `HELPFUL_REPLY_ONLY`. A launch post is `NOT_ALLOWED`.
- **BLOCKED: no account.** No Reddit CLI is installed.

### 4. WordPress.org support forums

- Audience: https://wordpress.org/support/plugin/woocommerce/ and https://wordpress.org/support/plugin/writeleash/
- Rule: [Forum Guidelines](https://wordpress.org/support/guidelines/), fetched 28 October 2026. "The forums are not a venue to promote your own (or others’) plugins, themes, websites or services." Unvetted AI-generated responses are named as a moderation reason.
- Permission: `HELPFUL_REPLY_ONLY` on an open thread. Promotion and review asks are `NOT_ALLOWED` except the single post-resolution review note inside a WriteLeash thread, and only after the person confirms the fix.
- WriteLeash's own forum has no topics.
- **BLOCKED: no account.** Local WP-CLI is not a WordPress.org login.

### 5. r/Wordpress, one founder-written exception at most

- Audience: https://www.reddit.com/r/Wordpress/
- Rule source: 28 October 2026 search capture of the rules payload. Not re-fetched today.
- Quote from that capture: sharing a useful free WordPress.org or GitHub project can fit, and promotional titles must start with `[PROMO]` after the 16 June 2026 moderator note.
- Permission: `RESTRICTED`. An AI draft is not the post. The founder writes it or skips it.
- **BLOCKED: no account.**

### 6. Directory and newsletter forms

- https://wpcontent.io/submit/ returned HTTP 200 on 10 November 2026. Do not submit a plugin URL.
- https://thewpweekly.com/submit/ returned HTTP 200 later the same day, after one failed connection. Submissions are discretionary.
- https://wptavern.com/contact/ returned HTTP 200. It is a contact form, not a directory.
- Permission: `SUBMISSION_REVIEW`. **BLOCKED: no account**, and there is no live article URL.

## Not used

| Channel | Why |
| --- | --- |
| Facebook groups | Rules were not readable without an account. **BLOCKED: no account.** No account was created. |
| X and LinkedIn | No CLI and no account. **BLOCKED: no account.** |
| Email | No mailer was used. **BLOCKED: no account.** Do not send. |
| r/ecommerce | 28 October rules capture: no self-promotion. Not used. |
| Cold email to a scraped list | Out of scope. |
| Paid ads | Issue #325 lists paid ads as a non-goal. |
| WooCommerce.com marketplace | WriteLeash is not a WooCommerce.com product. |

## Do not post yet

- No external account was verified.
- Every draft that mentions WriteLeash still needs the founder to decide. On WordPress.org the founder has to vet it.
- Reddit rules need a direct browser check. This environment could not resolve `www.reddit.com`.
