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
creation is measured independently. All HTTP requests have a 128 MiB PHP
limit and 30-second execution limit; CLI fixture generation has its separately
recorded limit. Request instrumentation counts queries across all wpdb
connections (after mu-plugin load), peak PHP allocated memory and request
duration; it does not collect cookies, nonces or credentials. Timing includes
real Admin bootstrap/PRG and progress reads. The test client is not a graphical
browser; visual/human usability is not inferred from HTTP correctness.

The test DB identity has only schema-local SELECT/INSERT/UPDATE/DELETE/
CREATE/ALTER/DROP/INDEX. Root is used solely to construct disposable lab
permissions. Plugin installation requires neither root nor SSH.

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

Real shared/managed-host pilot: **NOT TESTED**. Container evidence must not be
renamed as shared-host certification. Multisite: **UNSUPPORTED**.
