# UTM conventions

Checked 28 October 2026.

## Do not use UTM on the directory link

WordPress.org's public download series does not attribute `utm_source` or any
other query parameter to an install. A link such as
`https://wordpress.org/plugins/writeleash/?utm_source=reddit` would still be an
ordinary directory visit in the only public meter we have. Do not report those
clicks as installs or as verified conversions.

Drafts in this pack use the plain URL
`https://wordpress.org/plugins/writeleash/` when they link the plugin at all.

## Where UTM is appropriate

UTM is appropriate only on a URL whose host the project controls, after that
host exists and its own logs or analytics can read the query string.

Those visits are visits to that host. They are not WordPress.org installs.

GitHub Pages for this repository returned HTTP 404 on 28 October 2026. There is
no host to attach a parameter to. GitHub itself is not treated as that host:
this repository has no Discussions, and a GitHub Release does not exist.

## Names to use later

When a controlled host exists, use only these parameters:

| Parameter | Value |
| --- | --- |
| `utm_source` | The community or site the link was posted on, for example `woo-slack` or `wp-content`. |
| `utm_medium` | `community`, `email`, or `article`. |
| `utm_campaign` | `writeleash-30d` plus the distribution date, for example `writeleash-30d-2026-11-15`, and only after #313 is DISTRIBUTED. |
| `utm_content` | The asset id, for example `week-2-slack` or `article-undo-bulk-edit`. |

Do not add `utm_term`. Do not add plugin telemetry to recover the same data.
Do not put UTM on a WordPress.org, WooCommerce.com, Reddit, or Slack link and
then describe the directory download count as the result.
