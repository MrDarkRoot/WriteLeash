#!/usr/bin/env bash
# #283 exact-artifact upgrade driver.
#
# Builds no artifacts. Installs the exact source/target ZIPs with WP-CLI and
# runs wordpress/tests/release/upgrade-v03.php in seed|verify|negative mode.
#
# Required:
#   WL283_SITE=/path/to/disposable/wordpress
#   WL283_SOURCE_ZIP=/path/to/writeleash.0.2.2.zip
#   WL283_SOURCE_SHA256=09ff494b80646a5ec854bfb52f16dd3ad1a68d27da88a9e8564963b74b9f8b90
#   WL283_UPGRADE_RECEIPT=/path/outside-webroot/upgrade-v03.receipt.json
#   WL283_ALLOW_DESTRUCTIVE=1  explicit confirmation that WL283_SITE is disposable
#
# verify/negative additionally require:
#   WL283_TARGET_ZIP=/path/to/writeleash.0.3.0-candidate.zip
#   WL283_TARGET_SHA256=<optional; computed and recorded when omitted>
#   WL283_TARGET_VERSION=0.3.0
#
# negative also reads:
#   WL283_NEGATIVE_KIND=unknown-grant-schema|malformed-grant-option
#
# seed, verify and negative must share the same WL283_UPGRADE_RECEIPT path.
#
# Usage:
#   WL283_ALLOW_DESTRUCTIVE=1 WL283_INSTALL_SOURCE=1 upgrade-v03.sh seed
#   WL283_ALLOW_DESTRUCTIVE=1 upgrade-v03.sh verify
#   WL283_ALLOW_DESTRUCTIVE=1 upgrade-v03.sh negative
set -euo pipefail

mode="${1:-}"
case "$mode" in
	seed|verify|negative) ;;
	*) echo "usage: $0 seed|verify|negative" >&2; exit 2 ;;
esac

: "${WL283_SITE:?WL283_SITE is required}"
: "${WL283_SOURCE_ZIP:?WL283_SOURCE_ZIP is required}"
: "${WL283_SOURCE_SHA256:?WL283_SOURCE_SHA256 is required}"
: "${WL283_UPGRADE_RECEIPT:?WL283_UPGRADE_RECEIPT is required}"
WP_CLI="${WP_CLI:-wp}"
TARGET_VERSION="${WL283_TARGET_VERSION:-0.3.0}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HARNESS="$SCRIPT_DIR/upgrade-v03.php"
WP=( "$WP_CLI" --path="$WL283_SITE" )

sha_of() { sha256sum "$1" | awk '{print $1}'; }

[[ -f "$HARNESS" ]] || { echo "missing harness: $HARNESS" >&2; exit 1; }
[[ -d "$WL283_SITE" ]] || { echo "missing WordPress path: $WL283_SITE" >&2; exit 1; }
if [[ "${WL283_ALLOW_DESTRUCTIVE:-0}" != "1" ]]; then
	echo "Refusing: set WL283_ALLOW_DESTRUCTIVE=1 and point WL283_SITE at a disposable site" >&2
	exit 2
fi
case "$(realpath -m "$WL283_UPGRADE_RECEIPT")" in
	"$(realpath "$WL283_SITE")"/*) echo "Receipt must be outside the WordPress root" >&2; exit 2 ;;
esac
[[ -f "$WL283_SOURCE_ZIP" ]] || { echo "missing source ZIP" >&2; exit 1; }
source_actual="$(sha_of "$WL283_SOURCE_ZIP")"
if [[ "$source_actual" != "$WL283_SOURCE_SHA256" ]]; then
	echo "source SHA-256 mismatch: expected $WL283_SOURCE_SHA256 got $source_actual" >&2
	exit 1
fi

if [[ "$mode" != "seed" ]]; then
	: "${WL283_TARGET_ZIP:?WL283_TARGET_ZIP is required for $mode}"
	[[ -f "$WL283_TARGET_ZIP" ]] || { echo "missing target ZIP" >&2; exit 1; }
	target_actual="$(sha_of "$WL283_TARGET_ZIP")"
	if [[ -n "${WL283_TARGET_SHA256:-}" && "$target_actual" != "$WL283_TARGET_SHA256" ]]; then
		echo "target SHA-256 mismatch: expected $WL283_TARGET_SHA256 got $target_actual" >&2
		exit 1
	fi
	export WL283_TARGET_SHA256="$target_actual"
	export WL283_TARGET_VERSION="$TARGET_VERSION"
	export WL283_TARGET_ZIP
fi

receipt_dir="$(dirname "$WL283_UPGRADE_RECEIPT")"
mkdir -p "$receipt_dir"

if [[ "$mode" == "seed" ]]; then
	if [[ "${WL283_INSTALL_SOURCE:-0}" == "1" ]]; then
		"${WP[@]}" plugin install "$WL283_SOURCE_ZIP" --force --activate
	fi
	installed="$("${WP[@]}" plugin get writeleash --field=version)"
	if [[ "$installed" != "0.2.2" ]]; then
		echo "source site must run WriteLeash 0.2.2 (found $installed)" >&2
		exit 1
	fi
else
	"${WP[@]}" plugin deactivate writeleash >/dev/null 2>&1 || true
	"${WP[@]}" plugin install "$WL283_TARGET_ZIP" --force --activate
	# Capture the state immediately after activation, before any harness code
	# can trigger a lazy migration. The harness cross-checks this with its own
	# first read.
	post_state="$("${WP[@]}" eval 'echo wp_json_encode(array("grant"=>(int)get_option("writeleash_grant_schema",0),"journal"=>(int)get_option("writeleash_price_journal_schema",0),"job"=>(int)get_option("writeleash_job_schema",0)));')"
	export WL283_POST_ACTIVATION_STATE="$post_state"
	echo "#283 post-activation schema markers: $post_state"
fi

export WL283_UPGRADE_MODE="$mode"
export WL283_SOURCE_ZIP
export WL283_SOURCE_SHA256

"${WP[@]}" eval-file "$HARNESS"
chmod 0600 "$WL283_UPGRADE_RECEIPT"
echo "#283 $mode receipt: $WL283_UPGRADE_RECEIPT"
