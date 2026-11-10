# Metrics

Public WordPress.org numbers only. No plugin telemetry and no analytics SaaS.

[2026-11-10-baseline.json](2026-11-10-baseline.json) is the seed fetched on
10 November 2026. Do not overwrite it. A later week gets its own dated file.

`active_installs: 0` is the API's "Fewer than 10" bucket, not a precise count
of zero sites. `num_ratings: 0` and `rating: 0` match an empty reviews page.
`download_events_sum` counts download events, not installs. The baseline series
runs from 2026-10-11 through 2026-11-09 and sums to 322. 2026-11-10 was not in
the series.

WordPress.org does not report UTM parameters back through these endpoints.
`utm_reported_by_wordpress_org` is false for that reason.

## Refresh

From this directory:

```bash
bash refresh.sh
```

The script refetches the plugin information API and the download series, then
writes `YYYY-MM-DD.json` using the UTC date. It refuses to write a file whose
name contains `baseline`. It does not upload anything.

Compare a new file with the baseline before quoting a change on issue #325.
A change in the active-install bucket is not proof that a post caused an install.
