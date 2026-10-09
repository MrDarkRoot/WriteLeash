#!/usr/bin/env bash
# Maintainer command; never downloads tooling or runs during plugin execution.
set -euo pipefail
repo="$(cd "$(dirname "$0")/../.." && pwd)"
: "${WP_CLI_PHAR:?Set WP_CLI_PHAR to the official WP-CLI 2.12.0 release phar}"
expected=ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c
[[ "$(sha256sum "$WP_CLI_PHAR" | cut -d' ' -f1)" == "$expected" ]] || { echo 'Unexpected WP-CLI artifact' >&2; exit 1; }
cd "$repo"
include=$(sed '/^#/d; /^$/d' wordpress/release/writeleash-distribution-files.txt | paste -sd, -)
php "$WP_CLI_PHAR" i18n make-pot wordpress/writeleash wordpress/writeleash/languages/writeleash.pot \
  --domain=writeleash --include="$include" \
  --file-comment='WriteLeash translation source. Distributed under GPL v2 or later.' \
  --headers='{"POT-Creation-Date":"","Report-Msgid-Bugs-To":"https://github.com/MrDarkRoot/WriteLeash/issues"}'
