#!/bin/sh
for i in 1 2; do
  rm -rf /tmp/wl109-mysql /tmp/wl109-mariadb
  rm -f /tmp/wlruntime/logs/diag-observe-fail.log /tmp/wlruntime/logs/diag-trace.log
  WRITELEASH_STAGE_SOURCE=/tmp/wlruntime/diag-plugin /tmp/wlruntime/ns.sh /tmp/writeleash-approved-20261008/agent-B sh /tmp/wlruntime/runners/jobs/in-container.sh > /tmp/wlruntime/logs/runner-soak-$i.log 2>&1
  if grep -qF 'one applies, one conflicts, no deadlock: {' /tmp/wlruntime/logs/runner-soak-$i.log; then echo "RUNNER ATTEMPT $i FAILED (reproduced)"; exit 0; fi
  echo "runner attempt $i PASSED"
done
echo "ALL RUNNER ATTEMPTS PASSED"
