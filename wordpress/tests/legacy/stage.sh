#!/bin/sh
# Disposable historical lab ONLY. Never used by Woo/public package tests.
set -eu
site=$1
sh /opt/tests/stage-plugin.sh "$site"
php /opt/tests/legacy/stage.php "$site/wp-content/plugins/writeleash"
