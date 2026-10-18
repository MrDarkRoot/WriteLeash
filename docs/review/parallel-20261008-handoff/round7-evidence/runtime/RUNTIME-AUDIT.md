# Round 7 independent runtime audit

Status: **HOLD — audit stopped at the user's explicit immediate-report request.** Completed receipts are retained below. Running profiles were interrupted and remain INCOMPLETE / NOT_TESTED. No further tests were launched after the stop instruction.

## Source identity and independent #109 discriminator

The original combined candidate is `3b4ab5e8895fb60dbcb00d26d5c597f129b9ed8f`, Git tree `4bbfc135378836009f1096b59b70b5d7ac46447e`. The corrected candidate is `ad6377eaf6b68548221d3e4fae0b522b0e19f7d3`, Git tree `9278c28cdb961c0fc404202ec1ea551882b4620a`. Their complete difference is one test fixture boolean cast in `wordpress/tests/durable/cache-public-integration.php`. Production subtree `0a2584cbf3572f509b7ec19a438b7b059b7cb071`, release subtree, jobs/Undo test subtrees, release tests, and public staging script are byte identical. See `original-latest-execution-equivalence.json`.

The observer fix commit `c2e3f6167879bbc5ac7ba9c798cbb43ce672608d` is reachable from corrected #220 head `b834d81ab5fb378360356ba15c54ab632df03515`. The pre-fix commit is `029a08040da0fab06bfea2a8d174aeba3d72f2d4`. Their exact public plugin sources were independently archived and staged; `before-after-sources.json` and `observer-source-analysis.json` identify source and verifier hashes. The correction to the test does not change production bytes.

`Price_Cache_Verifier::observe()` opens an independent normal-identity connection (line 25); checks committed APPLIED journal identity/binding/evidence (lines 213–236); compares independent storage with the journal and lookup coherence (lines 237–259); reads a new public product object (line 268); on disagreement deletes targeted post/meta caches and invalidates the product group (lines 274–280); reconstructs once (line 281); and throws `CACHE_VERIFICATION_FAILED` if it still disagrees (line 282). It never saves a product or repeats a committed write. The normal initial cache invalidation also remains in place. No global cache flush or Woo Internal cache class was introduced. The 43-file shipped runtime inventory found zero Internal-cache/ProductCache dependencies; see `runtime-internal-reference-inventory.json`.

The independently authored `cache-repair-probe.php` executes genuine Preview/seed/Apply and then injects the pre-write meta blob into real public Woo reads. It asserts the stale blob was actually served, counts direct fresh-product class resolution through backtraces, counts actual Woo save hooks, compares independently stored `_regular_price`/`_price` and APPLIED journal state, and requires the expected observation result. A transient blob is released after the observed read events; a permanently stale blob remains installed. Persistent blob mode means persistent disagreement; its name is separate from the actual Redis cache profile.

| Exact source | Transient stale blob | Permanent stale blob | Additional Woo saves | Durable storage/journal |
|---|---|---|---|---|
| Pre-fix `029a0804…` | Expected FAIL: `CACHE_VERIFICATION_FAILED`, exit 255; one direct read, no repair | Refuses mismatch; the post-fix repair-count assertion also fails as expected | 0 | 80.00 / APPLIED preserved |
| Observer fix `c2e3f616…` | PASS, exit 0; two direct reads total, one repair | PASS refusal discriminator, exit 0; exception `CACHE_VERIFICATION_FAILED` | 0 | 80.00 / APPLIED preserved |
| Original integrated `3b4ab5e…` | PASS, exit 0 | PASS refusal discriminator, exit 0 | 0 | 80.00 / APPLIED preserved |
| Latest integrated `ad6377e…` | PASS, exit 0 | PASS refusal discriminator, exit 0 | 0 | 80.00 / APPLIED preserved |

In fixed transient runs, actual product read event prices are `100.00`, `100.00`, `80.00`; the direct fresh constructor is invoked twice, whereas the first constructor produces an additional nested public read event. The stale blob is served twice. For permanent disagreement, the repaired read still observes 100.00 and the observer refuses it. This probe cannot pass without entering the specified repair path. See `probe-receipts.json`, `latest-repair-receipts.json` and corresponding raw logs.

The actual repository R4 fixture was also executed against exact pre-fix production and the corrected current test. It fails at fixture line 214 (the stale-cache observe call), with the pre-fix observer throwing at line 267. The same complete fixture against corrected #220 production passes all 64 assertions. See `targeted-prefixed-regression.log`, `targeted-prefixed-receipt.json`, `corrected-pr220-default.log` and `corrected-pr220-receipts.json`.

## Reproduced test defect and authorized correction

The original exact combined durable runner passed the MySQL/default #108 matrix (394 assertions), then failed its newly included #209 probe: strict `wp_using_ext_object_cache()` comparison yielded `[null,false]`. WordPress 7.1.2's default cache leaves the global unset; the API returns null. Existing durable/jobs/Undo assertions explicitly cast to bool. The root agent corrected only #220's profile assertion in commit `b834d81ab5fb378360356ba15c54ab632df03515`, matching those existing tests. The original failure and actual exit 1 are preserved in `durable.log` and `suite-receipts.json`.

After the correction the real complete #209 probe passes 64 assertions, while an intentionally wrong persistent-cache expectation on the default-cache site still fails `[false,true]` with actual exit 1. The correction retains cache-profile discrimination. No production behavior was modified.

Early independent-probe attempts were invalid audit setup: the audit started before standalone plugin activation finished, and the first instrumentation counted a nested public classname callback as a separate direct reconstruction. These original receipts and the original probe are retained in `probe-initial-fixture-failures/`. Valid later before/after receipts use completed setup and distinguish direct freshness resolution from nested Woo callbacks. Those initial failures are not product findings.

## Runtime fixture and provenance

These runs use authorized disposable native fixtures, not Docker containers: MySQL 8.0.44, MariaDB 10.11.15-MariaDB, Redis 7.4.2, WordPress 7.1.2, Woo 11.1.2, PHP 8.2.32, Redis Cache 2.7.0. Each namespace binds the exact reviewed tree's tests, release manifest, and plugin source to the supported `/opt` runner layout. Shell scripts preserve the repository's pinned versions and full assertions. The sole database-version adaptation accepts the native MariaDB distribution suffix instead of `-ubu2204`; version 10.11.15 remains exact. Other adaptations use dedicated site/database names to avoid shared-test interference, and add read-only environment/hash diagnostics. Complete runner diffs are retained. These are native runner executions of the real integration tests, not a claim that unexecuted Docker orchestration passed.

Environment receipts capture actual SQL `SELECT VERSION()`, DB host/name, WP/Woo/PHP versions, real persistent-cache status, Redis prefix, drop-in hash, product instance-cache option, manifest hash and every staged file's SHA-256. The integrated population is 43 files, not an assumed 42; #222 adds the POT catalog. Individual #220's manifest is separately measured. Redis-enabled receipts use a real loaded drop-in, and supported scripts' cross-process cache tests prove persistence. Command, start/completion times, and actual child-process exit statuses are retained in JSON and raw logs.

## Historical receipts

`historical-runtime-review.json` indexes retained original Round 6 logs without altering them. The original `runner-diag-1-FAIL.log` contains the actual different-jobs assertion failure, and its DB receipt contains the winning APPLIED/WOO_CRUD_VERIFIED journal with target/evidence 80.00, competing CONFLICT and winning job NEEDS_REVIEW/CACHE_VERIFICATION_FAILED. This receipt's winning value is 80.00, rather than repeating the separate 90.00 summary case. Retained traces attribute the exception to the observer; post-fix runner receipts contain the actual engine/default/Redis profile markers and pinned DB identities.

The summary's “5/5 affected runner attempts” is not a substitute for a complete five-attempt raw receipt population. This audit establishes the failure mechanism independently with the pre-fix production discriminator and the real current fixture, plus fresh fixed/combined executions. Historical two-passing-run summaries alone are not used to close it. Hash consistency does not certify command execution or full provenance; fresh receipts supply explicit source/staging/environment identities.

## Compatibility scope

The current production support contract is Woo >=10.0.0 and <12.0.0, WP >=7.0 and <8.0. Woo 9.9.7 is known unsupported and cannot be counted as a supported compatibility PASS. Official Woo archive identities in `woo-archive-identities.json` prove 10.4.4 lacks ProductCache and 10.5.3 contains it. These supported versions therefore straddle the actual former availability boundary. Official download URLs, exact bytes and SHA-256 hashes for the newly downloaded versions are retained in `compat-download-receipts.json`.

The narrow version matrix runs the unchanged focused cache-public probe and the independent repair/refusal probe on exact corrected #220 source. Each version has a fresh separate database/site, and both default and real Redis profiles. Full #108/#109/#110 matrix coverage remains explicitly tied to Woo 11.1.2; narrower 10.x/11.0.1 cache tests are not labeled full concurrency/Undo coverage.

Woo 10.0.x through earlier 10.4 patches, other untested 10.x/11.x releases, WordPress 7.0 and other WP patch/minor combinations remain NOT_TESTED. Broader Marketplace range acceptance and release approval must consider those gaps separately from #209's changed-cache-assumption acceptance. The founder decides whether the remaining advertised-range gaps block Marketplace/release acceptance; this audit does not broaden a compatibility claim.

## Execution tables

`FINAL-RUNTIME-SNAPSHOT.json` and `user-stop-process-receipt.json` record the stop time, completed receipts and the 11 task-owned processes signaled with SIGTERM. Database/Redis services and unrelated labs were left running. Whole-run return codes are unavailable for interrupted managers; these are not converted into exit 0. Each completed version/repair probe has its true command and exit status in the receipt JSON.

| Runner/source | MySQL/default | MySQL/Redis | MariaDB/default | MariaDB/Redis | Whole-run exit |
|---|---|---|---|---|---|
| Original `3b4ab5e` durable + cache | #108 PASS394; #209 FAIL `[null,false]` | NOT_TESTED | NOT_TESTED | NOT_TESTED | 1 |
| Latest `ad6377e` #108/#209 | #108 PASS394, #209 PASS64 | INCOMPLETE; #108 through268 assertions; #209 not reached | NOT_TESTED | NOT_TESTED | Interrupted; unavailable |
| Original `3b4ab5e` #109/jobs | Complete #109932-assertion scenario + #171324-assertion Unicode segments; individual process exits unavailable | Complete scenario/Unicode segments; see exact totals in raw jobs.log; individual process exits unavailable | INCOMPLETE, started then user stop | NOT_TESTED | Interrupted; unavailable |
| #110/Undo runner | NOT_TESTED fresh | NOT_TESTED fresh | NOT_TESTED fresh | NOT_TESTED fresh | Not launched |
| Latest `ad6377e` Admin core | INCOMPLETE; real Admin assertions through479 including variation Apply/Undo/parent range retained | NOT_TESTED | NOT_TESTED | NOT_TESTED | Interrupted; unavailable |
| Latest `ad6377e` progress endpoint fixture | NOT_TESTED | NOT_TESTED | NOT_TESTED | NOT_TESTED | Not launched |

The old/new jobs and Undo source equivalence receipt permits attribution of executed unchanged code to the latest production bytes. It does not turn unfinished profiles or the unstarted Undo suite into PASS. The two completed MySQL jobs segments lack a completed top-level four-profile runner exit, so they remain bounded executed-scenario evidence.

| Exact #220 version/source | WP | DB/cache | Complete focused public probe | Repair + permanent-refusal probes | Coverage boundary |
|---|---|---|---|---|---|
| Woo10.4.4 / `b834d81` | 7.1.2 | MySQL8.0.44/default | PASS58, exit0 | Both PASS, exit0; zero added saves | Supported pre-ProductCache |
| Woo10.4.4 / `b834d81` | 7.1.2 | MySQL8.0.44/Redis7.4.2 | PASS58, exit0 | Both PASS, exit0; zero added saves | Supported pre-ProductCache |
| Woo10.5.3 / `b834d81` | 7.1.2 | MySQL8.0.44/default | PASS64, exit0 | Both PASS, exit0; zero added saves | Supported post-ProductCache |
| Woo10.5.3 / `b834d81` | 7.1.2 | MySQL8.0.44/Redis7.4.2 | PASS64, exit0 | Both PASS, exit0; zero added saves | Supported post-ProductCache |
| Woo11.0.1 / `b834d81` | 7.1.2 | MySQL8.0.44/default | PASS64, exit0 | Both PASS, exit0; zero added saves | Focused changed cache assumptions |
| Woo11.0.1 / `b834d81` | 7.1.2 | MySQL8.0.44/Redis7.4.2 | PASS64, exit0 | Both PASS, exit0; zero added saves | Focused changed cache assumptions |
| Woo11.1.2 / `b834d81` | 7.1.2 | MySQL8.0.44/default dedicated site | PASS64, exit0 in correction check | Fixed and latest combined repair probes PASS | Dedicated version-matrix setup later interrupted; no full-matrix PASS |
| Woo11.1.2 / `ad6377e` | 7.1.2 | MySQL8.0.44/default | PASS64; #108PASS394 | Latest source repair/refusal PASS | Exact combined completed default leg |
| Woo11.1.2 | 7.1.2 | MySQL/Redis latest combined; MariaDB/cache combinations | INCOMPLETE / NOT_TESTED fresh full completion | No false PASS | Required remaining four-profile closure |
| Woo9.9.7 | — | — | KNOWN_UNSUPPORTED, runtime not exercised this run | NOT_TESTED | Excluded by current >=10.0,<12 support contract |
| Woo10.0.x, other untested10.x/11.x; WP7.0 and other WP versions | — | — | NOT_TESTED | NOT_TESTED | Founder Marketplace/release evidence decision remains |

The supported pre/post cache-availability version criterion now has direct narrow real execution evidence. Missing full MySQL/MariaDB default/Redis #108/#109/#110 acceptance prevents closing the full #109 finding under this audit's requested closure rules. **#220 remains HOLD / ACCEPTANCE_PARTIAL**, despite the verified cache repair mechanism and fixed test defect. No new production defect was demonstrated in completed runs. The broad Marketplace compatibility range and release decision remain separate founder decisions.

