#!/usr/bin/env bash
# Immutable actionlint release, verified before execution. Local override is useful offline.
set -euo pipefail
if [[ -n "${ACTIONLINT:-}" ]]; then
  "$ACTIONLINT" -shellcheck='' -pyflakes=''
  exit
fi
temp="$(mktemp -d)"
trap 'rm -rf "$temp"' EXIT
curl -fsSL --retry 2 https://github.com/rhysd/actionlint/releases/download/v1.7.11/actionlint_1.7.11_linux_amd64.tar.gz -o "$temp/actionlint.tar.gz"
printf '%s  %s\n' 900919a84f2229bac68ca9cd4103ea297abc35e9689ebb842c6e34a3d1b01b0a "$temp/actionlint.tar.gz" | sha256sum -c -
tar -xzf "$temp/actionlint.tar.gz" -C "$temp" actionlint
"$temp/actionlint" -shellcheck='' -pyflakes=''
