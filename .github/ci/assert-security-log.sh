#!/usr/bin/env bash
set -euo pipefail

log="${1:?pass the complete synthetic-only suite transcript}"
[[ -r "$log" ]] || { printf 'FAIL: suite transcript missing: %s\n' "$log" >&2; exit 1; }

# These are execution sentinels, not replacements for the durable-state and
# lock-wait assertions inside the integrated suite. In particular the numeric
# cases below are CANONICAL IDs; the older row-budget CC-030..033 are legacy IDs.
for marker in \
    'privilege-envelope checks: PASS' \
    'privilege-audit checks: PASS' \
    'CC-012 nested allowed rollback: PASS' \
    'CC-012 nested denial: PASS' \
    'CC-020 / CC-021 / CC-022 state-transition tests: PASS' \
    'independence subscriptions→users→refunds: PASS' \
    'independence refunds→users→subscriptions: PASS' \
    'independent-policy individual violation: PASS' \
    'canonical CC-030: PASS' \
    'canonical CC-031: PASS' \
    'canonical CC-032 single: PASS' \
    'canonical CC-032 decomposed: PASS' \
    'canonical CC-033: PASS' \
    'numeric contention scenario A: PASS' \
    'numeric contention scenario B: PASS' \
    'numeric contention scenario C: PASS' \
    'denial evidence (issue #34): PASS' \
    'native transaction-state experiment: all required tests PASS' \
    'Native suite exit status: 0'
do
    grep -Fq "$marker" "$log" || {
        printf 'FAIL: integrated suite execution marker absent: %s\n' "$marker" >&2
        exit 1
    }
done
grep -Fxq 'PostgreSQL: 16.4' "$log" || {
    printf 'FAIL: suite did not report PostgreSQL 16.4\n' >&2
    exit 1
}
grep -Fxq 'Base image: postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c' "$log" || {
    printf 'FAIL: suite did not report the documented pinned image\n' >&2
    exit 1
}
if grep -Ei '(^|[[:space:]])(SKIP|KNOWN FAIL|FAIL:)([[:space:]]|$)' "$log"; then
    printf 'FAIL: an assertion was skipped or failed\n' >&2
    exit 1
fi
printf 'Integrated PG16.4 execution markers: PASS (canonical numeric, CC-036, CC-012, independent policies, privileges)\n'
