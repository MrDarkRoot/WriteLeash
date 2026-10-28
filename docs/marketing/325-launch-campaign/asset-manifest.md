# Asset manifest

Checked 28 October 2026. A file in Git is not a published page.

## Ready to use

Usable now as founder-reviewed text about the public 0.2.2 plugin, or as a
help reply that does not need the plugin. Not cleared to post until the
founder approves the destination.

| Asset | Where |
| --- | --- |
| Public listing | https://wordpress.org/plugins/writeleash/ version 0.2.2 |
| Launch truth, channel rules, baseline, UTM rules, calendar | This directory |
| Four weekly themes | [assets/weekly-themes.md](assets/weekly-themes.md) |
| Ten community drafts | [assets/community-responses.md](assets/community-responses.md) |
| Five social drafts | [assets/social-posts.md](assets/social-posts.md) |
| Three partner emails | [assets/partner-emails.md](assets/partner-emails.md) |
| 0.2.2 description inside the announcement draft | [assets/launch-announcement.md](assets/launch-announcement.md) |
| Review request wording | [assets/review-request.md](assets/review-request.md). Do not send. |

## Requires release

Do not show these as the behavior of the directory plugin until #313 is
DISTRIBUTED and the zip matches.

| Asset | Why |
| --- | --- |
| v0.3.0 agent denial, perimeter guard, restricted-actor checks | Not in the 0.2.2 zip (`Perimeter` and `is_restricted_actor` absent on 28 October 2026). |
| Grant-scoped stock edits and the `main` readme stock sentence | Public trunk readme says stock is not changed. `Woo_Stock_Mutator` is absent from the 0.2.2 zip. |
| `demo/324-third-party/` on `main` (PR #333) | Recorded header 0.2.0. README says the WordPress.org 0.2.2 package was not the build under test. |
| PR #332 seatbelt demo | Open. Not on `main`. Includes a restricted-write denial. |
| Default-branch pair rule (WordPress 6.7 with WooCommerce 9) | On `main` after PR #330. The directory plugin requires WordPress 7.0 and WooCommerce 10.0. |
| Grant setup steps | 0.2.2 changelog calls this disabled-by-default foundations. No setup guide in this pack. |

## Requires a publication destination

| Asset | Why |
| --- | --- |
| Five #323 articles | [PR #331](https://github.com/MrDarkRoot/WriteLeash/pull/331) is open. `publication-manifest.json` has `live_url: null` for every article. GitHub Pages returned HTTP 404. |
| WP Content submission | Needs one of those live article URLs. The plugin URL is the wrong thing to submit. |
| The WP Weekly | `https://thewpweekly.com/submit/` returned HTTP 403. No submission. |
| Search and referral numbers | No Search Console property and no site. `NOT_AVAILABLE`. |

## Needs founder approval

| Decision | Why it is not made here |
| --- | --- |
| Any external post, email, or review request | No community account was authorized. Drafts are AI-prepared. WordPress.org can remove an unvetted AI reply. |
| r/Wordpress `[PROMO]` post | The founder has to write it. The draft is a shape, not the post. |
| WooCommerce Slack `#self-promotion` | Requires an account whose other posts outweigh promotion. Access is `UNKNOWN`. |
| Starting the 30-day clock | Blocked until #313 is DISTRIBUTED. |
| WordPress.org SVN, including the #322 listing copy on `main` | Founder GO. Trunk is still the 0.2.2 bulk-edit listing. |
| Merging this pull request, #331, or #332 | Not done from this task. |
| Creating a GitHub Release | The releases list is empty. This pack does not add one. |
