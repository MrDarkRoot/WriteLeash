#!/bin/sh
set -u
export PATH=/tmp/wlruntime/bin:$PATH
M=/tmp/wlruntime/mysql/bin/mysql
SITE=/tmp/wl109-mysql
FIXTURE=/tmp/writeleash-approved-20261008/agent-B/wordpress/tests/jobs/integration.php
for i in 5 6 7 8; do
  $M --no-defaults --socket=/tmp/wlruntime/sock/mysql.sock -u root -pdisposable_root_password -e "DROP DATABASE IF EXISTS wp_test; CREATE DATABASE wp_test; GRANT ALL PRIVILEGES ON wp_test.* TO 'wp_test'@'%'; FLUSH PRIVILEGES;" 2>/dev/null
  wp --path=$SITE core install --url=http://127.0.0.1:8080 --title=RaceDiag --admin_user=admin --admin_password=disposable_admin_password --admin_email=admin@example.test --skip-email >/dev/null 2>&1
  wp --path=$SITE plugin activate woocommerce >/dev/null 2>&1
  wp --path=$SITE plugin activate writeleash >/dev/null 2>&1
  wp --path=$SITE plugin activate redirection >/dev/null 2>&1
  wp --path=$SITE plugin activate redis-cache >/dev/null 2>&1
  rm -f /tmp/wlruntime/logs/diag-trace.log
  env WL109_ROOT_USER=root WL109_ROOT_PASSWORD=disposable_root_password WL109_CACHE=default wp --path=$SITE eval-file $FIXTURE > /tmp/wlruntime/logs/diag-loop-$i.log 2>&1
  if grep -qF 'one applies, one conflicts, no deadlock: {' /tmp/wlruntime/logs/diag-loop-$i.log; then
    echo "ATTEMPT $i FAILED (reproduced)"
    exit 0
  fi
  echo "attempt $i PASSED"
done
echo "ALL ATTEMPTS PASSED"
