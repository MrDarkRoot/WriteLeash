# #112 evidence decision — proposed Free ceiling: 100 products

> **Historical pre-cap measurement record.** The tables, CSV and original
> results below are preserved decision evidence from the predecessor. PR #119's
> repair now enforces **100** server-side in the Free Admin boundary, while the
> internal #107 engineering selector maximum remains **1,000**. See
> [SUPPORT-REPAIR.md](SUPPORT-REPAIR.md) for post-cap behavior and final-head
> refusal evidence. The pre-cap 1,000 timings are not post-cap throughput claims.

Authoritative base main: `061d5f5ff4e1867327495c8ca07dd903f1f16b06`.
The #111 product from merged PR #118 is the subject. Product implementation,
#107–#111 regression fixtures and release-chain issues are unchanged.

Checked representative measurement SHA:
`cbee8b6aee52406ec1395c899f300f3c7864c7a5`;
[complete seven-profile, two-engine run](https://github.com/MrDarkRoot/WriteLeash/actions/runs/36960433829).
The concise [CSV](results-cbee8b6.csv) is a sanitized checkpoint, not a claim
that it was measured at a later documentation commit. **Final PR verification
must additionally use the exact final-head CI run and its SHA-stamped JSON
artifacts.** Reproduction, sources and measurement definitions: [README](README.md).

## Decision and claim boundary

**Proposed initial supported maximum: 100 products/job, only for the exercised
configurations below.** This is an evidence-backed release-copy proposal, not
publication or release authorization. The existing engineering selection/
policy ceiling of 1,000 is not a supported-size promise.

100-product complete workflows passed on every advertised candidate row.
1,000 passed correctness on WP 7.1.2 / Woo 11.1.2 / PHP 8.2.34, both engines,
default and Redis cache, but is **USABLE_WITH_LIMIT**, not the initial support
ceiling:

* Approval took 28.850–36.254 seconds in this checkpoint. A PHP execution limit
  does not establish a frontend/FPM/proxy wall-time budget. The HTTP test client
  permits 120 seconds; a generic 30-second frontend has not been certified.
* One 1,000-item job retained approximately **595,988,000 logical journal field
  bytes (568.38 MiB)**, versus approximately 6,058,000 bytes (5.78 MiB) at 100.
  `Price_Apply_Journal` stores the full frozen plan in each changing item row.
  The observed tenfold item increase produced approximately 98-fold journal
  growth. Payload size therefore matters as well as item count.
* On scheduler-impaired hosts, 1,000 requires 100 bounded Apply POSTs and 100
  bounded Undo POSTs. At 100, each takes ten. This administrative burden is
  recorded, not hidden behind a worker-only throughput figure.
* Previous WP and the other PHP builds were exercised at 100, not 1,000. A
  1,000-item PHP 8.2 pass does not certify 1,000 on every compatibility row.

The ceiling is the largest common complete size supported by this evidence,
with a materially smaller latency/storage/recovery footprint. No intermediate
job size above 100 is being called certified. No 10k throughput is extrapolated.
No source optimization or change to earlier product gates was made to improve
the numbers. Metadata size and third-party hook cost affect the envelope;
these are short-name simple-product fixtures, not arbitrary-shop benchmarks.

## Scale checkpoint: complete actual Admin contracts

All times are seconds; PHP memory is peak allocated heap, not process RSS.
Fixture creation is outside every product-operation timer. The actual shop
contains 101, 1,101 and 11,101 published products because the first-use refusal
probe and earlier fixtures remain. Selected jobs contain exactly 100/1,000;
the evaluated rejected selection contains exactly 10,000.

| Engine/cache | Job | Fixture | Plan | First preview | Approval | Apply incl. progress/reopen | Undo | Peak Admin MiB |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| MySQL/default | 100 | 6.261 | 0.231 | 0.141 | 0.597 | 12.201 | 13.358 | 16 |
| MariaDB/default | 100 | 4.146 | 0.166 | 0.137 | 0.544 | 9.535 | 10.352 | 16 |
| MySQL/Redis | 100 | 8.924 | 0.255 | 0.128 | 0.553 | 12.433 | 13.668 | 16 |
| MariaDB/Redis | 100 | 6.660 | 0.190 | 0.157 | 0.469 | 9.833 | 10.488 | 16 |
| MySQL/default | 1,000 | 66.081 | 1.103 | 0.190 | 34.074 | 175.097 | 178.386 | 48 |
| MariaDB/default | 1,000 | 43.353 | 0.986 | 0.156 | 29.525 | 139.695 | 156.691 | 48 |
| MySQL/Redis | 1,000 | 91.683 | 1.432 | 0.159 | 36.254 | 172.823 | 179.802 | 48 |
| MariaDB/Redis | 1,000 | 68.339 | 1.233 | 0.150 | 28.850 | 138.604 | 165.718 | 48 |

Every completed row: applied exactly N; pending/applying/conflict/failed/review/
unsupported zero; exactly one Woo save per frozen product during Apply and one
additional save during Undo; all restored to numeric 100; price/meta/lookup/
cache/journal parity passed. Percentage targets stayed absolute 80, never 64.
All preview pages (20 rows) and item/history pages (50 rows) were walked in
deterministic order, with truthful next offsets and selected/item totals.
Detailed page/batch p50/p95/p99, queries and per-job payloads are in CI JSON.

**10,000 evaluation: USABLE_WITH_LIMIT — no supported 10k job.** Actual fixture
creation: MySQL 1,203.364s, MariaDB 907.243s; category refusal 0.113s/0.130s;
both category and explicit-ID requests refused at the existing 1,000 contract.
All 10,000 selected products remained at regular/active/lookup price 100. No
job, approval, execution, Undo or 10k journal exists; those timings are N/A.
This is actual evaluation of the product boundary, not a helper-only 10k run.

## Compatibility: exact rows, not a Cartesian/range claim

Both engines in each row are MySQL **8.0.44** and MariaDB
**10.11.15-MariaDB-ubu2204**. ZIP checksums and Docker digests are pinned in
`run.sh` and the workflow. Each PASS row exercised activation, Woo dependency,
Admin entry, frozen plan, approval/Apply, fresh-session reopen, history, Undo,
schema replay, deactivation/reactivation, retention and uninstall/reinstall.

| WP | Woo | PHP | Cache | Complete size | Result |
| --- | --- | --- | --- | ---: | --- |
| 7.1.2 | 11.1.2 | 8.2.34 | default and Redis | 100 and evaluated 1,000 | PASS |
| 7.0.1 | 11.1.2 | 8.2.34 | default | 100 | PASS |
| 7.1.2 | 11.1.2 | 7.4.33 | Redis | 100 | PASS |
| 7.1.2 | 11.1.2 | 8.0.30 | Redis | 100 | PASS |
| 7.1.2 | 11.1.2 | 8.1.34 | Redis | 100 | PASS |
| 7.1.2 | 11.0.1 | 8.2.34 | Redis | requested 100 | UNSUPPORTED: existing execution gate; zero applied |
| 6.8.3 | 11.1.2 | 8.2.34 | setup attempt | N/A | UNSUPPORTED: Woo package requires WP 7.0 |

No other patch versions, intermediate WP releases or unexercised combinations
are claimed. PHP 8.0 peak at 100 was 93.5 MiB under the 128 MiB limit; PHP 7.4
was 19 MiB, 8.1 18 MiB, and current PHP 8.2 16 MiB. Do not infer 1,000 on PHP
8.0 from the PHP 8.2 result. No WriteLeash-origin PHP warnings/deprecations/
fatals occurred in the passing matrix. The expected core `wp_update_plugins`
warning is caused by the deliberate outbound-HTTP block and is retained in
request diagnostics. The old PHP 7.4 WP-CLI `null` activation-flag failure and
old client's auth limitation are retained separately; normal single-site
activation uses core's explicit boolean flag, not a product compatibility patch.

## Cache and hook torture

Redis **7.4.2**, Redis Object Cache/drop-in **2.7.0**, bundled Predis client.
Persistent-cache rollback-before-commit, real protected retry after backoff,
external edit before Apply, external edit before Undo, clean Apply/Undo, actual
SIGKILL recovery and ambiguous-acknowledgement checkpoint all passed stored
truth checks. Before recovery, SIGKILL can leave cached 80 while committed
regular/lookup remain 100; after protected Resume the complete recovered job
has matching 80. A pre-recovery observation is not mislabeled as surviving
reconciliation. The unchanged #108 counterexample plus targeted lookup/product/
meta cache repair is a permanent additional required regression.

| Synthetic save hook | Durable observed outcome |
| --- | --- |
| same-connection DB insert, clean | row committed; eligible stored price applied and Undo restored it |
| rollback before commit | DB row absent, journal PENDING, regular/lookup/cache 100; protected retry and Undo passed |
| HTTP and mail | intercepted API attempts recorded outside the transaction; delivery was not tested |
| queue | actual public Action Scheduler enqueue attempted; external-queue reversal is not claimed |
| regular-price property changed to 88 | rollback to 100; journal/job NEEDS_REVIEW; no false APPLIED |
| RuntimeException / Error | known rollback, PENDING/retryable; no durable price mutation |
| wpdb COMMIT attempt | fenced/detected, NEEDS_REVIEW, stored price 100, hook DB row absent |
| raw mysqli COMMIT | detected ownership loss; job NEEDS_REVIEW, journal APPLYING, price 100; hook DB row survived |
| ambiguous acknowledgement after actual commit | job NEEDS_REVIEW, journal APPLIED with matching durable/cached 80; no blind replay |

**OUTSIDE CONTRACT:** emails, webhooks, remote HTTP, orders/completed sales,
external queues and arbitrary plugin side effects. Undo is stored regular-price
restoration with proven Apply evidence, never generic reversal of these effects.

## Host, scheduler, users and lifecycle

* Normal container profile: PHP 256 MiB / 60s, default cache. Constrained:
  PHP 128 MiB / 30s, persistent Redis; Action Scheduler supplied by Woo and
  initialized. Database tables are InnoDB. Normal identity has only schema-local
  SELECT/INSERT/UPDATE/DELETE/CREATE/ALTER/DROP/INDEX, no DBA grants. Standard
  WordPress config credentials are used by every product/observer connection.
* Real CREATE denial on first preview: visible INVALID, no job, zero product
  mutation, regular/active/lookup 100. Root is solely the disposable permission
  fixture controller. The plugin does not require SSH, root DB, CREATE USER,
  TRIGGER, ROUTINE, GRANT, manual SQL or custom credentials.
* DISABLE_WP_CRON, denied async loopback runner, no-traffic delay, enqueue refusal
  at approval and delayed wakes preserve durable PAUSED/pending truth. Protected
  HTTP Resume uses the unchanged lease/generation/fence/lifecycle path and
  processes at most ten items per chunk. Duplicate completed wakes and a stale
  missing-job wake changed no counts. A real killed 100-item Admin-origin job
  recovered after the actual 60-second lease expired; no generation was forged.
* Each Shop Manager created 21 interleaved 100-item plans. Two real HTTP history
  pages exposed every own job, no foreign jobs, no foreign-row pagination hiding;
  guessed access was denied. Revocation caused core 403 and zero mutations.
  Administrator override remained intentional. The catalog included the scale
  fixtures when these tests ran.
* Running 100-item Apply and Undo survived deactivate/reactivate, with callbacks
  while inactive performing zero writes and explicit protected resume completing
  work. History survived reactivation and current-schema migration/replay.
  Backdated expiry/purge removed terminal evidence, not prices; uninstall and
  reinstall passed. Exact purge costs/counts are in lifecycle JSON. Unchanged
  #108/#110 additionally cover legacy journal migration, expiry, atomic purge,
  purge/initiation and in-flight uninstall serialization races.
* A released-Free-to-released-Free package upgrade is **NOT TESTED**: no prior
  released Free package exists here. Current-schema replay and existing legacy
  journal migration must not be represented as that package certification.
* **Real managed/shared-host pilot: NOT TESTED. Multisite: UNSUPPORTED.** Container
  evidence is not shared-host certification. There is no promise of arbitrary
  save-hook throughput, arbitrary metadata size, physical tablespace shrink after
  purge, visual-browser rendering performance or external-effect reversibility.

## Preserved attempts and independent #61 flake

All attempts are retained as CI artifacts, including fixture mistakes and
infrastructure failures. The checkpoint CSV is not a replacement for them.

| Source SHA / run | Preserved result |
| --- | --- |
| `365f2089a29bbbd90a68a956591a7c4853517023` / 36957522356 | fixture Docker ARG scope failure, before product execution |
| `a2a235bdb382d869f790aea1ec1492ac32d88fce` / 36957863335 | eval-file variable scope; old PHP client auth; WP 6.8.3/Woo minimum refusal |
| `8c95de43969bd078d380e2571934f562f1da375f` / 36958168091 | fixture assumed preview `total` instead of selected summary; old CLI nullable activation flag |
| `fc281e54b0ff9816d3dd88113958a820cbc931a5` / 36958425259 | pre-recovery stale-cache assertion wrongly labelled post-reconciliation; measurements retained |
| `23790ba0333bc0757fc75c7b828e8cc4dfa42b8f` / 36958731934 | expected revoked-user core 403 misclassified by positive-page helper; corrected to require 403 |
| `494e51bcf58519d6fb2f1506b6e513d4c5aaaeac` / 36959209227 | summarizer read its empty redirected output; PHP 7.4 Docker registry reset before setup |
| `ff046eeeaeac95fb0c1ef3c592445e809e4d099d` / 36960052294 | full matrix green; catalog field then represented fixture size, corrected in next source |
| `cbee8b6aee52406ec1395c899f300f3c7864c7a5` / 36960433829 | full matrix green; whole catalog recorded separately |

#61 at exact SHA `494e51bcf58519d6fb2f1506b6e513d4c5aaaeac`:
[run 36959209286](https://github.com/MrDarkRoot/WriteLeash/actions/runs/36959209286)
attempt 1 failed `await_overlap(update) timed out; last=[]`; attempt 2 passed
at the **unchanged SHA**. Attempt-1 transcript SHA-256:
`5eef98fe191611563a665652388955247d469cb6fda8970b971e7d01aa16df54`.
No #61 assertion or overlap fixture was modified.

At checkpoint SHA `cbee8b6aee52406ec1395c899f300f3c7864c7a5`,
[run 36960433883](https://github.com/MrDarkRoot/WriteLeash/actions/runs/36960433883)
also failed the same `await_overlap(update)` condition on attempts 1 and 2.
Both were preserved and another unchanged-SHA rerun was requested. Attempt-1
transcript SHA-256:
`46a78f6e05ba4b7d2f6eaf5c2c09f8c72555fd66dc3c076f52e6fa1e5fc15068`.
The final PR check result must come from final-head CI, not this pending rerun.

No confirmed correctness kill condition was hit. The original killed-cache
assertion ran before reconciliation; recovery parity was subsequently required
and passed, rather than suppressing a surviving-cache failure.

## Proposed release claims and remaining exclusions

Permitted proposal: published core simple products, stored regular prices,
base currency, immutable preview and approval, durable truthful Apply/history,
conflict-aware eligible Undo, **up to 100 products/job** in the exact matrix
rows above. Normal schema privileges suffice; impaired scheduling requires
protected bounded manual resume. Preserve the measured latency, storage and
30-day retention limitations in future copy.

Not permitted: 1k as a default shared-host promise, 10k jobs, untargeted version
ranges/untested combinations, universal hosting/cache/hook containment, package
upgrade certification, guaranteed shopper prices or generic rollback.

**#77/#76/#65/#66 modified/executed: NO. Publication: NO. Merge: NO.**
Existing regression CI, including the historically named release matrix, is
regression evidence only; it is not execution or authorization of that chain.
