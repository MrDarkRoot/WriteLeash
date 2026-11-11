#!/usr/bin/env bash
# Refetch the public WordPress.org plugin API and download series.
# Writes metrics/YYYY-MM-DD.json next to this script.
# Does not overwrite a *-baseline.json and does not call an analytics service.
set -euo pipefail

cd "$(dirname "$0")"

day="$(date -u +%F)"
out="${day}.json"
if [[ "$out" == *baseline* ]]; then
  echo "refusing to write a baseline file" >&2
  exit 1
fi

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

curl -fsSL -G 'https://api.wordpress.org/plugins/info/1.2/' \
  --data-urlencode 'action=plugin_information' \
  --data-urlencode 'request[slug]=writeleash' \
  -o "$tmpdir/plugin.json"

curl -fsSL -G 'https://api.wordpress.org/stats/plugin/1.0/downloads.php' \
  --data-urlencode 'slug=writeleash' \
  --data-urlencode 'limit=400' \
  -o "$tmpdir/downloads.json"

python3 - "$tmpdir/plugin.json" "$tmpdir/downloads.json" "$out" <<'PY'
import json
import sys
from datetime import datetime, timezone
from urllib.request import Request, urlopen

plugin_path, downloads_path, out_path = sys.argv[1:]
info = json.load(open(plugin_path))
raw_events = json.load(open(downloads_path))
if info.get("slug") != "writeleash" or "version" not in info:
    raise SystemExit("plugin information response did not contain writeleash")

events = {k: int(v) for k, v in raw_events.items()}
ratings = info.get("ratings") or {}
ratings_out = {
    str(k): int(ratings.get(str(k), ratings.get(k, 0)))
    for k in ("1", "2", "3", "4", "5")
}

zip_url = info.get("download_link") or ""
zip_meta = None
if zip_url.startswith("https://downloads.wordpress.org/"):
    request = Request(zip_url, method="HEAD")
    with urlopen(request, timeout=30) as response:
        zip_meta = {
            "url": zip_url,
            "http_status": response.status,
            "content_length": int(response.headers.get("Content-Length") or 0),
            "last_modified": response.headers.get("Last-Modified"),
        }

doc = {
    "schema": 1,
    "kind": "refresh",
    "fetched_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    "slug": info.get("slug"),
    "name": info.get("name"),
    "version": info.get("version"),
    "active_installs": info.get("active_installs"),
    "active_installs_meaning": "The plugin information API uses 0 for the public Fewer than 10 bucket. It is not a precise count of zero sites.",
    "rating": info.get("rating"),
    "num_ratings": info.get("num_ratings"),
    "ratings": ratings_out,
    "support_threads": info.get("support_threads"),
    "support_threads_resolved": info.get("support_threads_resolved"),
    "last_updated": info.get("last_updated"),
    "added": info.get("added"),
    "requires": info.get("requires"),
    "tested": info.get("tested"),
    "requires_php": info.get("requires_php"),
    "requires_plugins": info.get("requires_plugins"),
    "download_link": info.get("download_link"),
    "downloaded_field": info.get("downloaded"),
    "versions": info.get("versions"),
    "directory_zip": zip_meta,
    "download_events": events,
    "download_events_sum": sum(events.values()) if events else 0,
    "download_events_first": min(events) if events else None,
    "download_events_last": max(events) if events else None,
    "utm_reported_by_wordpress_org": False,
    "sources": {
        "plugin_information": "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=writeleash",
        "downloads": "https://api.wordpress.org/stats/plugin/1.0/downloads.php?slug=writeleash&limit=400",
    },
    "notes": [
        "Download events are not active installs and are not users.",
        "WordPress.org does not return UTM query parameters from these endpoints.",
        "This file is a refresh. Do not replace 2026-11-10-baseline.json.",
    ],
}
with open(out_path, "w", encoding="utf-8") as handle:
    json.dump(doc, handle, indent=2)
    handle.write("\n")
print(out_path)
PY
