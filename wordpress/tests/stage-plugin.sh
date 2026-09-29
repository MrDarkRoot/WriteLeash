#!/bin/sh
# Test-only installation shape; source stays at /opt/commitcap-for-wordpress.
set -eu
site=$1
source=/opt/commitcap-for-wordpress
destination="$site/wp-content/plugins/commitcap"
test -f "$source/commitcap.php"
test ! -e "$source/commitcap-for-wordpress.php"
test ! -e "$destination"
mkdir -p "$destination"
cp -R "$source/." "$destination/"
test -f "$destination/commitcap.php"
test ! -e "$destination/commitcap-for-wordpress.php"
