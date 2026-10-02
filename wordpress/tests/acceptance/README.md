# #112 Free acceptance evidence gate

This fixture drives the **merged Admin product** over authenticated HTTP:
selection → immutable preview → approval → progress → reopen → protected
bounded manual resume → history → Undo. It does not benchmark a planner or
worker loop instead of the product. No product files are changed.

Run with the exact image pins in
`.github/workflows/wordpress-woo-acceptance.yml` and:

```sh
export WL112_SHA="$(git rev-parse HEAD)"
export WL112_RESULTS=/absolute/existing/evidence-directory
export WL112_CORE_IMAGE=... # exact pinned image from the workflow
export WL112_CLI_IMAGE=...  # exact pinned image from the workflow
export WL112_SIZES='100 1000 10000'
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
The selected fixtures contain exactly 100/1,000/10,000 products. Earlier
fixtures plus the one first-use refusal product remain in the shop, so the
whole catalog count is recorded separately (101/1,101/11,101 in the scale
sequence). These are not mislabeled as empty-shop catalogs of the selected size.

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

The current product already refuses selections above 1,000, and execution
accepts exactly Woo 11.1.2. A real 10,000-product fixture is evaluated through
both category and explicit-ID Admin requests. If refused, record
`USABLE_WITH_LIMIT`: the catalog exists, but a 10k job is unsupported and no
execution/Undo timing exists. Do not bypass the cap to generate a misleading
helper-only result. A previous Woo refusal is `UNSUPPORTED`, not a pass for
editing prices on that version.

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
