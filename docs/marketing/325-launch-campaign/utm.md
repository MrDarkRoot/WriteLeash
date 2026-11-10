# UTM scheme

Checked 10 November 2026.

Every draft that links the plugin uses this destination:

`https://wordpress.org/plugins/writeleash/`

Query parameters, and only these:

| Parameter | Value |
| --- | --- |
| `utm_source` | The place the link would be posted: `github`, `woo-slack`, `reddit-woocommerce`, `reddit-wordpress`, `wporg-support`, `partner-email`, `wp-content`, or `wp-weekly`. |
| `utm_medium` | `docs`, `community`, `email`, `support`, or `submission`. |
| `utm_campaign` | `writeleash-v030-30d` |
| `utm_content` | The draft id, for example `launch-note` or `reply-csv-parent`. |

Do not add `utm_term`. Do not shorten the URL. Do not add plugin telemetry
to recover the same data.

Example, not a live post:

`https://wordpress.org/plugins/writeleash/?utm_source=github&utm_medium=docs&utm_campaign=writeleash-v030-30d&utm_content=launch-note`

That URL returned HTTP 200 on 10 November 2026. The page is still the public
plugin page. The query string does not change the zip.

## WordPress.org will not report UTM back

The plugin information API and the public download series do not echo
`utm_source` or any other query parameter. A directory visit with these
parameters is indistinguishable from a plain visit in the only meters this
pack uses. `utm_reported_by_wordpress_org` is false in
[metrics/2026-11-10-baseline.json](metrics/2026-11-10-baseline.json).

Do not report a UTM link as an install, a referral visit, or a conversion.
Those cells stay `NOT_AVAILABLE` in [measurement.md](measurement.md).

The parameters are a label on the draft, so a later note can say which text
was used. They are not a WordPress.org report. GitHub Pages answered HTTP 200
on 10 November 2026, including the five guide URLs in
[launch-truth.md](launch-truth.md). This pack has no Search Console property
and no Pages analytics export, so those visits are not counted here either.
