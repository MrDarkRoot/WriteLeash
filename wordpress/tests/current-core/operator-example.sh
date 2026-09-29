#!/bin/sh
# #63: run the documented public operator workflow exactly as shipped. The
# script body is extracted from operator-setup.txt so documentation drift fails
# CI; only test-infrastructure credentials are used here.
set -eu
host=$1
site=$2
doc=/opt/commitcap-for-wordpress/operator-setup.txt
script=/tmp/cc63-provision-commitcap.php
review=/tmp/cc63-operator-review-$host.txt
apply=/tmp/cc63-operator-apply-$host.txt

test -f "$doc"
sed -n '/^BEGIN commitcap-operator-script$/,/^END commitcap-operator-script$/p' "$doc" | sed '1d;$d' > "$script"
test -s "$script"
php -l "$script" >/dev/null

# Missing required environment variables must fail closed.
if COMMITCAP_PROVISION_MODE=review wp --path="$site" eval-file "$script" >/dev/null 2>&1; then
  echo 'operator example accepted missing environment variables' >&2
  exit 1
fi

# Review mode: complete redacted SQL, no secret values anywhere.
COMMITCAP_PROVISION_MODE=review \
COMMITCAP_INSTALLER_DB_USER=root \
COMMITCAP_INSTALLER_DB_PASSWORD=disposable_root_password \
COMMITCAP_INSTALLER_DB_CONNECT_HOST="$host" \
COMMITCAP_RUNTIME_DB_USER=cc63_writer \
COMMITCAP_RUNTIME_DB_ACCOUNT_HOST=% \
COMMITCAP_RUNTIME_DB_PASSWORD=cc63_runtime_secret \
wp --path="$site" eval-file "$script" >"$review"
grep -q 'REDACTED_SECRET' "$review"
if grep -q 'cc63_runtime_secret' "$review" || grep -q 'disposable_root_password' "$review"; then
  echo 'operator review output leaked a secret' >&2
  exit 1
fi

# Apply mode: same script and inputs, now executed with the trusted installer.
COMMITCAP_PROVISION_MODE=apply \
COMMITCAP_INSTALLER_DB_USER=root \
COMMITCAP_INSTALLER_DB_PASSWORD=disposable_root_password \
COMMITCAP_INSTALLER_DB_CONNECT_HOST="$host" \
COMMITCAP_RUNTIME_DB_USER=cc63_writer \
COMMITCAP_RUNTIME_DB_ACCOUNT_HOST=% \
COMMITCAP_RUNTIME_DB_PASSWORD=cc63_runtime_secret \
wp --path="$site" eval-file "$script" >"$apply"
grep -q 'Provisioning complete' "$apply"
if grep -q 'cc63_runtime_secret' "$apply" || grep -q 'disposable_root_password' "$apply"; then
  echo 'operator apply output leaked a secret' >&2
  exit 1
fi

rm -f "$script" "$review" "$apply"
echo "#63 current-core $host: documented operator script review+apply executed, redacted output, no secret leak: PASS"
