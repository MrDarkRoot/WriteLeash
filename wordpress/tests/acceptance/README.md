# #112 Free acceptance evidence gate

This fixture drives the **merged Admin product** over authenticated HTTP:
selection → immutable preview → approval → background progress → reopen →
protected bounded manual resume → history → Undo. It does not benchmark a
planner or worker loop instead of the product. The current product supports
**1,000 products per new job**; the suite exercises that boundary, regular and
sale price targets, variable-product variation freezes and parent-range
refresh, dirty-catalog tolerance, and truthful mixed outcomes. The original
measurement is preserved in `EVIDENCE.md`/`results-cbee8b6.csv`; see
`SUPPORT-REPAIR.md` for the refused-before-journal and recovery boundary.

Run with the exact image pins in
`.github/workflows/wordpress-woo-acceptance.yml` and:

```sh
export WL112_SHA="$(git rev-parse HEAD)"
export WL112_RESULTS=/absolute/existing/evidence-directory
export WL112_CORE_IMAGE=... # exact pinned image from the workflow
export WL112_CLI_IMAGE=...  # exact pinned image from the workflow
export WL112_SIZES='100 101 1000'
export WL112_WOO=11.1.2
export WL112_CACHE=persistent
bash wordpress/tests/acceptance/run.sh
```

Every JSON result records the source SHA and exact runtime versions. Fixture
creation is measured independently. Constrained HTTP requests have a 128 MiB
PHP limit and 30-second execution limit; normal/default-cache requests have
256 MiB and 60 seconds. CLI fixture generation has its separately recorded
limit (the pinned CLI uses unlimited memory). Request instrumentation counts queries across all wpdb
connections (after mu-plugin load), peak PHP allocated memory and request
duration; it does not collect cookies, nonces or credentials. Timing includes
real Admin bootstrap/PRG and progress reads. The test client is not a graphical
browser; visual/human usability is not inferred from HTTP correctness.

`summarize.py /path/to/downloaded/artifacts` prints concise measured rows,
nearest-rank batch/page p50/p95/p99, request PHP allocated-memory peaks and
query counts. The JSON request start/end line indices attribute metrics to
each actual size. Logical field bytes and exact row counts are measured per
job, alongside explicitly approximate physical table allocations. None of
these metrics is a measurement of process RSS or graphical-browser render time.
The selected fixtures contain exactly 100/101/1,000 products. Earlier
fixtures plus the one first-use refusal product remain in the shop, so the
whole catalog count is recorded separately. These are not mislabeled as
empty-shop catalogs of the selected size.

The test DB identity has only schema-local SELECT/INSERT/UPDATE/DELETE/
CREATE/ALTER/DROP/INDEX. Root is used solely to construct disposable lab
permissions. Plugin installation requires neither root nor SSH.

MySQL lab authentication is explicitly `mysql_native_password`: the archived
PHP 7.4 CLI image's MariaDB client lacks `caching_sha2_password`. This is a
fixture/client restriction, not proof for every host authentication setup.
Activation uses core's public `activate_plugin(..., false)` API, matching the
single-site Admin flag. The archived PHP 7.4 WP-CLI command passes `null` and
fails the existing typed hook; its failed attempt is retained, not called a
normal Admin success.

## Exact sources

WordPress is copied from digest-pinned official images. PHP is executed by
digest-pinned official CLI images; the observed patch version is recorded.
Woo and Redis Cache ZIPs come from `https://downloads.wordpress.org/plugin/`
and are verified against the checksums in `run.sh`. Redis server is pinned to
the existing 7.4.2 fixture digest. This is source/staged-install acceptance,
not a release ZIP, SVN submission or release authorization.

## Interpretation

The Free Admin **new-work** boundary and the engineering selector maximum are
both **1,000**. `journey.php` runs a complete merchant workflow per size: the
frozen population is the full selected size, while only three reviewed products
change, so a real 1,000-product plan stays CI-practical. The 1,000 leg proves a
frozen category plan completes in one durable job with paginated
preview/results; one product is edited externally after approval, so Apply ends
`COMPLETED_WITH_ISSUES` with the newer price preserved and Undo restores only
the applied products. A requested size above 1,000 records
`REFUSED_BEFORE_JOURNAL` with the typed support message and zero Woo saves. Woo
11.1.2 and Woo 11.0.1 both run the complete Preview → Apply → History → Undo
workflow (two supported-range releases, not one exact fixture). Woo 9.9.7,
below the supported 10.0 floor, records `UNSUPPORTED_EARLY`, with no usable
preview/approval/resume path and zero jobs/journal rows/price mutations.
Do not bypass these boundaries for a helper-only run.

`support-boundaries.php` proves UI default/max 1,000, a 1,000-item explicit
preview/approval using the frozen population rather than a tampered counter,
and 1,001 explicit/category typed refusals independent of the safety policy
that create no job, journal or Undo evidence. It also builds a hash-consistent
durable predecessor above 1,000, refuses its approval, and then proves that an
already-approved older-large job still finishes and Undoes through the real
Admin HTTP contract with its frozen 1,001-item plan unchanged. The lower-level
plan contract is preserved, not weakened to manufacture these fixtures.

`legacy-recovery.php` uses trusted internal predecessor construction/approval
of 101 items — a supported size now — before any recovery assertion. The
registered scheduler callback and real HTTP protected Resume consume the same
#109 lease/generation/fence authority (including a common live-lease refusal),
with frozen/journal bindings unchanged. Existing completed work keeps
conflict-aware Undo; a partial Undo continues the same operation after
fresh-session reopen. The above-1,000 recovery path is proved in
`support-boundaries.php`; a 101-item job is never labeled legacy-oversized. An
out-of-range Woo still refuses recovery with the supported range shown.
PLANNED/BLOCKED work remains non-executable/unapprovable. The fixture does not
install a public bypass or a production migration flag.

`variations.php` proves a selected variable parent freezes its exact variation
IDs at preview, a variation created after preview never enters that frozen
plan, two variations Apply and Undo with the parent lookup min/max refreshed
after both, and one externally edited variation conflicts without failing its
sibling. `dirty-catalog.php` proves a malformed stored regular/sale price and an
unreadable Woo read are skipped at preview with typed reasons while a clean
sibling still changes, and duplicate `_regular_price` rows or a missing
`_price` fail closed at the write boundary as needs-attention with zero
overwrite. Both legs use the real Admin HTTP transport.

Failed/partial results and logs are uploaded on **every attempt**, with the
attempt number. CI artifacts carry detailed batch/request data; checked
evidence summaries should stay concise. DB table bytes are engine estimates,
not precise physical allocation; fixture, Apply and Undo snapshots separate
catalog costs from evidence growth. No extrapolation establishes support.

Emails, webhooks, remote HTTP, orders, external queues and arbitrary plugin
side effects are **OUTSIDE CONTRACT**. WriteLeash-owned stored-price/journal
truth and conflict-aware eligible Undo are the tested contract.

The synthetic hook plugin records same-connection DB effects, intercepted
HTTP/mail attempts, actual Action Scheduler enqueue attempts, property
mutation, exception/Error, wpdb COMMIT, raw mysqli COMMIT and ambiguous commit
acknowledgement at the existing checkpoint seam. Intercepted attempts are not
delivery evidence. A rolled-back attempt may replay external effects on retry.
Admin-origin 100-item jobs are killed with actual SIGKILL; the real 60-second
lease TTL expires before protected HTTP Resume. Pre-recovery stale cache is
recorded separately from the required post-reconciliation parity check.
The #108 permanent counterexample/regression remains in the unchanged durable
journal suite and is required alongside this workflow.

Multi-user tests interleave 21 real 100-item plans per Shop Manager, verify
two HTTP history pages, guessed-job refusal, capability revocation and the
intentional administrator override. Running Apply and Undo survive
deactivate/reactivate boundaries; terminal history survives reactivation and
current-schema replay, expires and is purged before uninstall/reinstall.
Concurrency, stale-generation fencing, legacy journal migration and
uninstall races remain covered by the unchanged #108–#110 regression suites.
Current-schema replay is not a claim about upgrading from a released Free
package: no such prior released package exists in this evidence gate.

Real shared/managed-host pilot: **NOT TESTED**. Container evidence must not be
renamed as shared-host certification. Multisite: **UNSUPPORTED**.
