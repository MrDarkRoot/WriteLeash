# #125 — exact-ZIP clean-install acceptance

**Observed merchant/product acceptance: PASS on all 12 tested configurations.**
**Release authorization / #126 readiness: BLOCKED pending maintainer exact-SHA
RELEASE_FULL evidence.** No founder GO, submission, publication or merge.

Base main was verified live as `66a090f03555cc9a85ecb61fa9decbb45e4ac007` before
work. Candidate source is exactly `5ccc75c1d895a2fb379866ce4cf0e9901d1c276c`.
Version 0.1.0; ZIP SHA-256
`7932ed450fa4fdd43902cfd74fb264f3db14271a63030726066c17e677d4223e`, **396314 bytes**.
The old `2ecfd3edf667c15b36fc75fbb525551df07b075bdfc5696a07813a704eb6cf57`
artifact remains superseded / compliance-blocked / unauthorized. No old ZIP was
used; #139 and historical #123/#124 evidence were not modified.

The frozen-source builder reproduced the exact checksum/size before testing.
Every configuration installed that ZIP via normal WordPress Plugin_Upgrader,
then activated WriteLeash using the actual authenticated Core Plugins-screen
activation link. This also avoids the historical PHP 7.4 WP-CLI null flag issue.
Basename, Core Woo dependency recognition and Products → Bulk Prices passed.
37 installed paths/bytes matched ZIP before behavior and after normal uninstall/
reinstallation. Final tested ZIP and original rebuilt ZIP were rehashed unchanged.
Installed canonical tree hash:
`4d3fa1f34b2435a364bc62f92db75a7c417bedb23086b02eace2cf974b6cb5c4`.

## Actual matrix — no historical PASS carried forward

Every row below ran on **both MySQL 8.0.44 and MariaDB 10.11.15**. WooCommerce is
**11.1.2 exact**. Redis rows used **Redis Object Cache 2.7.0 / Predis + Redis 7.4.2**.
Each row repeated clean install, 100/101 boundaries, the complete journey,
authorization, retention, dependency loss and lifecycle/uninstall.

| WordPress | PHP | Cache | MySQL | MariaDB |
|---|---|---|---|---|
| 7.1.2 | 8.2.34 | default | PASS | PASS |
| 7.1.2 | 8.2.34 | Redis | PASS | PASS |
| 7.0.1 | 8.2.34 | default | PASS | PASS |
| 7.1.2 | 7.4.33 | Redis | PASS | PASS |
| 7.1.2 | 8.0.30 | Redis | PASS | PASS |
| 7.1.2 | 8.1.34 | Redis | PASS | PASS |

These are the six supported profiles backed by the existing acceptance workflow,
re-executed on both engines using the exact ZIP. Unlisted Cartesian combinations
are NOT_TESTED. Multisite remains UNSUPPORTED; managed/shared hosting NOT_TESTED.
The current fixture uses 256 MiB/60-second HTTP limits and makes no new throughput
or latency promise.

System Docker was unavailable. Pinned official OCI layers were fetched with
Skopeo, hash-verified and executed in unprivileged user/PID namespaces using
Bubblewrap. PHP/database/Redis binaries reported the exact requested patch
versions. No host daemon, root service or system setting was installed/changed.
Temporary mount points and CLI Unix-socket TLS wrappers are lab settings, outside
WriteLeash. Image pins, platform-manifest hashes and archive hashes are in JSON.

Lab engine initialization uses the built-in fixture account on isolated sockets.
Merchant journeys use normal WordPress connection and ordinary Administrator /
Shop Manager roles. No plugin-specific DB user, grants, trigger, routine, DBA,
SSH or manual SQL setup is required by the product flow. This lab does not claim
least-privilege database or shared-host certification.

## Newly executed journey

All requests below use real logged-in Admin forms, action-specific session
nonces and Core PRG routing. Fixture creation/external edits use Woo CRUD, never
product regular-price SQL. Independent observers are fresh WP-CLI processes plus
fresh database connections. Mu observers/faults live outside the WriteLeash tree.

| Gate | Evidence and result |
|---|---|
| All five operations | Each starts at 18: SET 15 → 15; + fixed 2 → 20; − fixed 2 → 16; +10% → 19.80; −20% → 14.40. Frozen expected/absolute target, plan identity/hash, durable counts/APPLIED journal, one real Apply save and fresh stored/Woo/lookup readback agree. Each also receives eligible Undo back to 18. PASS. |
| Approval binding | Crafted approval extra selector/operation/amount/policy/target fields cannot replan the stored approved material. Modified material—including recomputed hashes—is rejected by hydrate/binding. Changing preview inputs creates a different plan. Blocked policy jobs refuse approval before journal seed or Woo save. PASS. |
| Selectors | Explicit IDs; exact byte-matching SKU; direct category excluding descendants. SKU/title/category drift after planning does not become an invented Apply conflict. Newly matching category product stays untouched; original frozen products execute. PASS. |
| Preview pagination | 45-item frozen preview navigates offsets 0/20/40 with exact ID order and identical plan JSON. Draft, sale-configured and empty-price products are UNSUPPORTED; unchanged target remains unchanged. No preview save. PASS. |
| Safety | Product count boundary, increase/decrease equality vs one-micro-percent-over limits, zero-price block and warning equality/strict-over threshold. Blocking policy blocks the whole plan; warnings do not override it. PASS. |
| 100 / 101 | Every profile completes 100-item percentage Apply/Undo with exactly one Apply and one Undo save per frozen product and durable/cache/lookup parity. 101 explicit-ID/category selections are REFUSED before job/items/journal/Undo seed, with zero Woo saves and unchanged owned row counts. Refusal validation PASS; behavior remains REFUSED. |
| Clean Apply / false APPLIED | Durable journal authority, not target equality, establishes APPLIED. External current price equal to target still yields CONFLICT, zero APPLIED and zero WriteLeash save. Hostile raw-commit hook is a labeled negative: NEEDS_REVIEW, never false APPLIED. PASS. |
| Close/reopen | Fresh login/request after a bounded 10-item chunk reconstructs 10 applied / 2 pending from durable truth. No browser-local success state. PASS. |
| Interruption / Resume | Actual registered worker callback is SIGKILLed after Woo save before journal/commit. Independent storage proves the uncommitted item remained 18. Actual 60-second lease expires; UI shows LEASE_RECOVERY. Protected Resume finishes the same immutable 12-item plan at 14.40, with completed products not repeated. No durable authority rows are forged. PASS. |
| Stale conflicts / partial truth | Expected 18, planned 14.40, external current 21 → CONFLICT and current 21 survives. Separate sale-configuration drift also conflicts. The mixed job retains applied 1 / conflict 2 and COMPLETED_WITH_ISSUES on fresh-session reopen/history. Expected/Current/Planned UI and fresh truth are inspected. No generic global success. PASS. |
| Duplicate / retry | Repeated registered wakes and protected Resume on completed work change neither evidence nor counts and issue no completed product save / percentage double application. PASS. |
| Undo conflict | Two proven applied products: one restores 18; another edited to 25 remains 25. Undo reports UNDONE 1 / conflict 1 and UNDO_COMPLETED_WITH_ISSUES. This is eligible stored-price restoration. PASS. |
| Authorization | Admin and Shop Manager succeed; subscriber, cross-actor valid-nonce Resume/Undo, missing/invalid nonce and GET mutation are denied with unchanged products/durable state. Existing installed nonce observer re-executes 111 assertions; actual REST anonymous/subscriber/cross-actor/GET/malformed controls also rerun for both WP versions. PASS. |
| Dependency/version loss | Actual Woo deactivation and actual Woo 11.0.1 installation safely REFUSE; no preview form/new job or product mutation. Exact 11.1.2 is restored. No price SQL fallback. Refusal validation PASS. |
| Cache | Both modes exercise Apply, stale conflicts, interrupted Resume/retry and Undo conflict. Fresh supported Woo/storage/lookup and journal authority agree after recovery. Process-local observer cache clearing in the scale helper is not a production global cache-flush strategy. PASS. |
| Retention | Real public-origin QUEUED, PAUSED, PLANNED, NEEDS_REVIEW and unexpired eligible Apply/Undo evidence survive purge. Only a 40-day expired terminal Apply+completed Undo candidate is purged atomically; each engine/profile reports one job and its evidence, zero purge failures. Timestamp-only synthetic clock fixture does not edit status/actor/material/target/lease/generation or prices. PASS. |
| Lifecycle / uninstall | With completed, mixed/conflict and Undo evidence present, Core deactivate/reactivate/uninstall preserves all Woo posts/meta/lookup/terms and durable evidence hashes. Runner active→deactivated→missing; owned action canceled; unrelated action remains pending. A process loaded from exact installed bytes before uninstall refuses afterward with runner inactive and zero Woo saves. Fresh after-uninstall observer uses WP/Woo only. Zero lifecycle DDL/DCL/product DML. Exact ZIP reinstalled and rechecked. PASS. |
| External effects | Apply and Undo synthetic save hooks make intercepted HTTP/mail attempts. Their occurrence is not erased or called delivery/reversal evidence. Email, webhook, order, HTTP, external queue and arbitrary plugin effects remain OUTSIDE_CONTRACT. |

Each configuration's machine record includes synthetic plan/job IDs, frozen
operation values, actual durable counts, conflicts, fresh price observations,
interruption evidence, retention classifications, lifecycle hashes, checksum
checks and environment. No credentials, cookies, live nonce values, tokens,
private hosts or customer/order data are committed.

## Claim mapping and package boundary

The unchanged claim matrix audit ran against the exact ZIP-installed readme and
passed its 15 topics / forbidden-claim controls. `CLAIM_MAPPING` in JSON maps each
material claim to the new #125 measurements: preview, policy, frozen approval,
no blind overwrite, durable progress, protected Resume, mixed results, eligible
conflict-aware Undo, 100-product ceiling, exact Woo and actually exercised
WP/PHP/DB/cache profiles. Multisite and hosting boundaries are preserved.

Human-readable public payload remains 37 files / 35 PHP / one header. Manifest,
metadata, assets and production runtime are unchanged. Release tools, observers,
reports, fault fixtures and evidence never enter the manifest or ZIP.

## Fixture corrections disclosed

Failed launcher/observer attempts were not counted as successful configurations.
Corrections were limited to repository-only tools:

- Resolve relative Core activation link; fully terminate test server process
  groups and check startup. A stale server caused a failed login.
- Handle the current CLI client's Unix-socket TLS default in temporary client
  wrappers; use unprivileged OCI execution after unavailable Docker helpers.
- Declare WP-CLI eval-file globals explicitly; the initial null report is not PASS.
- Read the effect counter independently from DB, since long-lived CLI option
  cache retained zero while two real Apply/Undo attempts had occurred.
- Support Woo-inactive observation without calling absent Woo functions; label
  its Woo readback REFUSED while verifying unchanged product storage.
- New #125 auth observer accepts both required WP versions. Historical #124
  observer/report remains unchanged.
- Initial default-row interruption reporter re-read before counts after
  recovery. Supplemental evidence explicitly retains that mistaken field and
  records the exact passed pre-kill 10/2 assertions. Supplement also tests
  rehashed binding and external target equality with fresh actual requests.

None required a production fix, ZIP replacement or acceptance against another
artifact. Unresolved product findings: NONE. Source change required: NO.

## Reproduction and CI handoff

Prepare the pinned OCI images listed in JSON using `skopeo copy --override-arch
amd64 ... dir:<image>` and `oci-runtime-125.py extract`; use the recorded verified
Core/Woo/Redis archives. The runtime helper exposes only task work/evidence and
read-only repository files in the namespace. Start the two pinned engines and
Redis with isolated task data. `run-exact-125.py` provisions a fresh supported
site and invokes normal Core installation/activation followed by all phases.
`matrix-125.py <mysql|mariadb>` runs the remaining advertised profiles; the first
WP 7.1.2/PHP 8.2/default row is run explicitly for each engine.

```sh
python3 wordpress/release/build-wordpress-org.py --source /tmp/writeleash-124-regen-source-1 --sha 5ccc75c1d895a2fb379866ce4cf0e9901d1c276c --output /tmp/writeleash-125-build
python3 wordpress/release/identity-125.py /tmp/writeleash-125-build/writeleash-0.1.0.zip
python3 wordpress/release/run-exact-125.py --db mysql
python3 wordpress/release/run-exact-125.py --db mariadb
python3 wordpress/release/matrix-125.py mysql
python3 wordpress/release/matrix-125.py mariadb
python3 wordpress/release/summarize-exact-125.py /tmp/writeleash-125-work/evidence
```

No automatic CI invocation performs this matrix or RELEASE_FULL. Existing
PR_FAST artifact tests additionally reject #125 checksum and installed-tree
injection. Expected PR_FAST / CI_COVERAGE: PASS on the exact final head;
selected runtime owners NONE, unique production runtime diff NONE.

[Current CI policy](../../.github/ci/CI-POLICY.md) lines 43–44 requires intentional
exact-main RELEASE_FULL again for #123–#125 authorization. Lines 18–23 require an
explicit exact-SHA dispatch matching the selected reviewed ref. User instructions
forbid automatic dispatch, so **RELEASE_FULL NOT RUN; #126 READY NO;
#126 EXACT ARTIFACT BLOCKED** pending maintainer release evidence. These local
exact-ZIP observations do not substitute for that policy gate. No founder GO,
#126 implementation, submission, SVN operation, GitHub Release/tag, publication,
issue-state change or merge was performed.
