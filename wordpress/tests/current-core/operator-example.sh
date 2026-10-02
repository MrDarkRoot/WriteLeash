#!/bin/sh
# #63 historical regression: run the repository operator workflow. The
# script body is extracted from operator-setup.txt so documentation drift fails
# CI; only test-infrastructure credentials are used here.
set -eu
host=$1
site=$2
doc=/opt/writeleash/operator-setup.txt
script=/tmp/cc63-provision-writeleash.php
review=/tmp/cc63-operator-review-$host.txt
apply=/tmp/cc63-operator-apply-$host.txt

test -f "$doc"
sed -n '/^BEGIN writeleash-operator-script$/,/^END writeleash-operator-script$/p' "$doc" | sed '1d;$d' > "$script"
test -s "$script"
php -l "$script" >/dev/null

# Missing required environment variables must fail closed.
if WRITELEASH_PROVISION_MODE=review wp --path="$site" eval-file "$script" >/dev/null 2>&1; then
  echo 'operator example accepted missing environment variables' >&2
  exit 1
fi

# Review mode: complete redacted SQL, no secret values anywhere.
WRITELEASH_PROVISION_MODE=review \
WRITELEASH_INSTALLER_DB_USER=root \
WRITELEASH_INSTALLER_DB_PASSWORD=disposable_root_password \
WRITELEASH_INSTALLER_DB_CONNECT_HOST="$host" \
WRITELEASH_RUNTIME_DB_USER=cc63_writer \
WRITELEASH_RUNTIME_DB_ACCOUNT_HOST=% \
WRITELEASH_RUNTIME_DB_PASSWORD=cc63_runtime_secret \
wp --path="$site" eval-file "$script" >"$review"
grep -q 'REDACTED_SECRET' "$review"
if grep -q 'cc63_runtime_secret' "$review" || grep -q 'disposable_root_password' "$review"; then
  echo 'operator review output leaked a secret' >&2
  exit 1
fi

# Apply mode: same script and inputs, now executed with the trusted installer.
WRITELEASH_PROVISION_MODE=apply \
WRITELEASH_INSTALLER_DB_USER=root \
WRITELEASH_INSTALLER_DB_PASSWORD=disposable_root_password \
WRITELEASH_INSTALLER_DB_CONNECT_HOST="$host" \
WRITELEASH_RUNTIME_DB_USER=cc63_writer \
WRITELEASH_RUNTIME_DB_ACCOUNT_HOST=% \
WRITELEASH_RUNTIME_DB_PASSWORD=cc63_runtime_secret \
wp --path="$site" eval-file "$script" >"$apply"
grep -q 'Provisioning complete' "$apply"
if grep -q 'cc63_runtime_secret' "$apply" || grep -q 'disposable_root_password' "$apply"; then
  echo 'operator apply output leaked a secret' >&2
  exit 1
fi

rm -f "$script" "$review" "$apply"
echo "#63 current-core $host: documented operator script review+apply executed, redacted output, no secret leak: PASS"
