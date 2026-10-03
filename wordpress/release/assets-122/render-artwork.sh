#!/usr/bin/env bash
# Original vector artwork -> canonical PNGs; release-only ImageMagick tooling.
set -euo pipefail
repo="$(git rev-parse --show-toplevel)"
cd "$repo"
convert -background none wordpress/assets/icon.svg -resize 256x256 PNG32:wordpress/assets/icon-256x256.png
convert wordpress/assets/icon-256x256.png -resize 128x128 PNG32:wordpress/assets/icon-128x128.png
convert -background none wordpress/release/assets-122/banner.svg PNG24:wordpress/assets/banner-1544x500.png
convert wordpress/assets/banner-1544x500.png -resize 772x250 PNG24:wordpress/assets/banner-772x250.png
