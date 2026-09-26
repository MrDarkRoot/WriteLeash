# Public mutation-incident evidence corpus (initial pass)

Research for [#37](https://github.com/MrDarkRoot/CommitCap/issues/37), 2026-09-26.
This is **not** evidence that CommitCap prevented any incident, nor a claim of
deployment compatibility. All links below are public project-authored issues or
reporter discussions on project trackers; a reporter's reproduction is not an
independently audited postmortem. **SOURCE FACT** describes only what that
source says; **INFERENCE** is our analysis. An open issue or proposed repair is
not evidence that a fix shipped or that the repair ran. Unknown transaction
boundaries, database engines, production effects, or root causes stay unknown.

Classification: **A — materially useful residual backstop**;
**B — possibly useful, but simpler controls likely sufficient**;
**C — outside CommitCap**; **D — insufficient evidence**. B does not establish
current compatibility or a market requirement. For each case the alternative
controls challenge explicitly considers idempotency (I), atomic job claiming
(J), narrow API/stored procedure (N), scope/WHERE validation (S), and
rate limiting/batching (R).

## Cases

### 1. Eligibility-cache clobber and targeted repair — B

- **Source/date/project:** [VATUSA/webapps #159](https://github.com/VATUSA/webapps/issues/159), 2026-08-07 (project issue; cites its `api` fix).
- **SOURCE FACT — logical task / intended durable effect:** An hourly eligibility job should preserve Moodle-derived competency when it exceeds promotion-derived competency. After a forward fix, a proposed one-time repair would recompute stale cache values for about 155 controller IDs.
- **SOURCE FACT — actual/amplified durable effect:** Earlier hourly runs overwrote correct cache values; the report found 161 affected controllers, five manually corrected on 2026-08-06 and 155 remaining. The 155-row repair is requested, **not reported completed**.
- **SOURCE FACT — shape / root cause / correctness fix:** Hourly recurrence; transaction boundaries and database engine are not given. The job overwrote values unconditionally; the linked fix stops new clobbering. The proposed sweep should re-enumerate the affected IDs, derive the best unexpired competency and verify remaining rows.
- **INFERENCE — alternatives:** I: idempotency would repeat the *wrong* value; J: no duplicate claim is reported; N: a narrow recompute operation per controller is plausible; S: validate source priority and affected-ID predicate before running; R: batch the repair with row counts and verification. These correctness controls dominate.
- **INFERENCE — residual / current fit / future / requirement:** **B**, conditional on a restricted PostgreSQL writer and a measured protected UPDATE policy, neither evidenced here. A finite per-transaction ceiling might bound an unexpectedly broad *repair* batch; it cannot choose the right competency value or protect the original multi-hour sequence as one task. Task-wide relevance is a hypothesis for repeated runs; **no product requirement** follows from this case alone.

### 2. Wrong ticket-to-author backfill — C

- **Source/date/project:** [BelimbingApp/belimbing #487](https://github.com/BelimbingApp/belimbing/issues/487), 2026-09-01 (project issue with local PostgreSQL reproduction).
- **SOURCE FACT — task / intended / actual:** A production migration backfills each IT ticket's `created_by_user_id` from the joined user. An ambiguous `pluck('users.id', 'operation_it_tickets.id')` returns ticket ID as the value; local ticket 44 received author ID 44. SQLite tests passed; a PostgreSQL test failed. The author explicitly says **no production database was inspected**.
- **SOURCE FACT — shape / root cause / fix:** Migration transaction and batch size are not reported. Same-named joined `id` columns collide; alias the columns, add a PostgreSQL regression and decide whether old values can be repaired.
- **INFERENCE — alternatives:** I: repeatable wrong mapping still wrong; J: no competing worker indicated; N: a typed, narrow author-mapping operation can validate identity; S: compare mapping and FK integrity against expected users; R: batches can ease review, not fix wrong identity.
- **INFERENCE — residual / current fit / future / requirement:** **C** for the documented defect: even one incorrect row may be damaging and a row-count budget allows it. PostgreSQL is reproduced locally, but the CommitCap fixture has no ticket-table policy, and production durability is unverified. Task-wide authority does not repair wrong-row attribution. **No requirement**.

### 3. Incomplete persons-on-events backfill — C

- **Source/date/project:** [PostHog/posthog #11850](https://github.com/PostHog/posthog/issues/11850), 2022-09-16 (engineering issue, with linked migration fix).
- **SOURCE FACT — task / intended / actual:** Backfill person/group columns on events. The project reports person IDs populated where possible but only roughly 10% of rows received `person_created_at`; tests on a playground and self-hosted data exposed the inconsistency.
- **SOURCE FACT — shape / root cause / fix:** The mutation is on **ClickHouse**, not the PostgreSQL fixture. Authors discuss a predicate on `person_id` that changes during a multi-column ClickHouse mutation; they report a migration fix verified on the playground, and propose a new migration and a post-check. Exact transaction/batch boundaries are not supplied.
- **INFERENCE — alternatives:** I: rerunning the same predicate does not repair skipped columns; J: no duplicate claim; N: a narrow staged migration is clearer; S: avoid mutating a predicate before dependent columns are updated and verify every field; R: chunks help operations, not logic.
- **INFERENCE — residual / current fit / future / requirement:** **C**. The failure is missing/incomplete effects in ClickHouse; a PostgreSQL mutation ceiling neither ensures completeness nor applies to ClickHouse. No task-wide hypothesis or product requirement.

### 4. Duplicate Sentry notification-setting rows — D

- **Source/date/project:** [getsentry/sentry #69267](https://github.com/getsentry/sentry/issues/69267), 2024-04-18 (project engineering issue).
- **SOURCE FACT — task / intended / actual:** Updating notification preferences expects one provider setting per user/provider. The issue reports duplicate `sentry_notificationsettingprovider` rows for 30+ users; an update's `get()` fails with more than one row, blocking edits.
- **SOURCE FACT — shape / root cause / fix:** Creation/retry/transaction shape is **unknown**; the issue says the cause remains to be investigated despite a model-level unique constraint, and proposes a cleanup backfill. No completed cleanup is established by this source.
- **INFERENCE — alternatives:** I: deduplicate creation requests; J: relevant only if a competing creator is found; N: a constrained upsert plus a database uniqueness constraint is more direct; S: target only verified duplicate groups; R: batch and audit any cleanup.
- **INFERENCE — residual / current fit / future / requirement:** **D — insufficient evidence** to attribute duplicates to worker amplification or to choose a meaningful cap. One duplicate per user can break editing, even with a generous ceiling. Current fixture lacks this policy; task-wide need and product requirement **unknown**.

### 5. Startup recovery creates duplicate PostgreSQL queue jobs — B

- **Source/date/project:** [vercel/workflow #3119](https://github.com/vercel/workflow/issues/3119), 2026-07-27 (project issue with PostgreSQL reproduction and later reporter comments).
- **SOURCE FACT — task / intended / actual:** Recover runnable workflow runs after restart. In the reported fixture, all 521 active runs were enqueued (only 20 runnable); five restarts of 20 runnable runs left 100 Graphile jobs, five per run instead of one. Later comments describe aggregate production observations; they **do not prove** corresponding extra business-table writes from these queue entries.
- **SOURCE FACT — shape / root cause / fix:** `reenqueueActiveRuns()` includes parked runs and new queue message IDs defeat deduplication. Jobs accumulate across restarts/transactions. The issue proposes a persisted runnable/consumed predicate and durable idempotency key; no shipped fix is established here.
- **INFERENCE — alternatives:** I: stable run+event key directly addresses duplicate inserts; J: atomic ownership/claim controls concurrent recovery; N: a narrow enqueue operation can enforce identity; S: select only genuinely runnable runs; R: throttling limits load but does not correct parked-run selection.
- **INFERENCE — residual / current fit / future / requirement:** **B** only as a candidate for *bounded queue-table inserts* under a deliberately scoped policy. Current fixture does not protect Graphile inserts, so **current transaction-local fit: none**. Per-startup limits would reset on each restart; a task-wide bound is a separate, unimplemented hypothesis. No evidence here that a mutation budget would prevent harmful downstream effects, and **no requirement** is promoted.

### 6. Cron replicas race and duplicate reminders — B

- **Source/date/project:** [naiba/bonds #89](https://github.com/naiba/bonds/issues/89), 2026-04-27 (project issue with reproduction steps and maintainer fix comment).
- **SOURCE FACT — task / intended / actual:** One reminder per scheduled tick. The issue explains how two PostgreSQL-backed replicas reading `last_run_at` before either updates it can both run, with two emails and two `user_notification_sent` rows in the described reproduction. The issue does not quantify affected production users.
- **SOURCE FACT — shape / root cause / fix:** Separate replica executions; job's transaction boundaries unknown. Non-atomic read/execute/update gate. A maintainer comment reports replacing it with advisory-lock plus conditional UPDATE on PostgreSQL, and tests of competing schedulers.
- **INFERENCE — alternatives:** I: reminder identity plus uniqueness avoids duplicate records; J: atomic claim/lock is the primary fix (reported implemented); N: narrow send/mark operation helps DB state but not unsending mail; S: constrain by scheduled reminder identity; R: rate limit/batch limits volume, not duplicates.
- **INFERENCE — residual / current fit / future / requirement:** **B** only for additional database-row blast-radius bounds in a hypothetical correctly instrumented PostgreSQL deployment; **the current fixture has no reminder policy and does not cover email**. Separate transactions/replicas make a task-wide limit relevant in theory, but exact-once claiming and idempotency dominate. **No requirement**.

### 7. Failed sync retries accumulate raw rows — C

- **Source/date/project:** [airbytehq/airbyte #12336](https://github.com/airbytehq/airbyte/issues/12336), 2022-04-26 (public user report and maintainer response).
- **SOURCE FACT — task / intended / actual:** Replicate 100 rows once. Reporter describes three failed-sync retries leaving 300 rows in a destination MySQL table, with steps using an unreachable destination host. Maintainer notes raw tables can contain duplicates and points to dedup+history for final output; source does not establish an unintended *final business-table* mutation.
- **SOURCE FACT — shape / root cause / fix:** Retry across attempts; per-attempt transaction boundaries unknown. Reporter attributes duplicates to retries; maintainer's correctness mechanism is normalization/dedup, not a confirmed fix to this report. Source and destination are **MySQL** in this report.
- **INFERENCE — alternatives:** I: deterministic record/run key for retries; J: atomic attempt ownership if concurrent attempts are confirmed; N: narrow destination upsert or dedup pipeline; S: validate sync-window scope; R: batching bounds per attempt only.
- **INFERENCE — residual / current fit / future / requirement:** **C** for current CommitCap: other database, and raw-stage duplicates are explicitly compatible with the described design. Task-wide rate bounds might be studied for an analogous PostgreSQL workflow, but none is evidenced here. **No requirement**.

### 8. Bad ingester mapping leaves corpus needing repair — C

- **Source/date/project:** [pwr-ai/JuDDGES #72](https://github.com/pwr-ai/JuDDGES/issues/72), 2026-09-15 (project issue; references source fix #71).
- **SOURCE FACT — task / intended / actual:** Ingest clean tax-interpretation text. Duplicate YAML keys selected raw HTML for `full_text`, degrading previews and embeddings; the issue estimates about 479k affected objects and proposes a **future** text/embedding backfill. The issue asks for live sampling before executing repair, so its estimate is not a completed count.
- **SOURCE FACT — shape / root cause / fix:** Ingestion and re-embedding happen in a vector collection; transaction, batch and worker shapes are not specified. Config fix applies to future ingests; rederive text from saved markup and verify samples if a backfill runs.
- **INFERENCE — alternatives:** I: repeating ingestion with the same mapping preserves error; J: no duplicate worker evidenced; N: a narrowly typed text transform; S: validate mapped text and sample affected scope; R: batch re-embedding for cost and rollback checkpoints.
- **INFERENCE — residual / current fit / future / requirement:** **C**. Budgeting PostgreSQL row counts would not validate content or vector embeddings, and this is not evidence of PostgreSQL effects. No task-wide product requirement.

### 9. Room cleanup broad UPDATE — C for current mechanism

- **Source/date/project:** [mynaparrot/plugNmeet-server #915](https://github.com/mynaparrot/plugNmeet-server/issues/915), 2026-09-19 (reporter says confirmed in production; project issue remains open).
- **SOURCE FACT — task / intended / actual:** End one room. The reporter describes a 106-hour room whose expired NATS KV identifiers caused a cleanup path with empty ID/SID; a GORM condition omitting empty fields updated **all currently running rooms** as ended in **MySQL**. The report includes SQL shape and consequences for other rooms.
- **SOURCE FACT — shape / root cause / fix:** Async `onAfterRoomEnded` cleanup, transaction boundaries unknown; expired keys plus empty condition and a remaining broad `NOT is_running = 0` filter. Proposes reject-empty-identity guard, restore missing identity from DB and correct the KV TTL. Fix is proposed, not evidenced shipped.
- **INFERENCE — alternatives:** I: cannot correct a broad first write; J: unrelated; N: `end_room(id)` enforcing nonempty identity is primary; S: reject empty predicate and verify affected scope; R: batching could constrain harm but is inferior to fixing identity.
- **INFERENCE — residual / current fit / future / requirement:** **C for current PostgreSQL-only research**, despite being an instructive *shape* for a future relational budget: a single broad transaction could in principle be bounded by a correctly installed per-table row ceiling, but this report is MySQL and includes nonrelational NATS/LiveKit side effects. No task-wide requirement from this case alone.

### 10. CakePHP `WHERE 1 = 1` broad UPDATE — C for current mechanism

- **Source/date/project:** [cakephp/cakephp #2382](https://github.com/cakephp/cakephp/issues/2382), opened 2013-11-22; independent commenters added incidents in 2015 (community reports in project tracker, **not** a confirmed framework root-cause postmortem).
- **SOURCE FACT — task / intended / actual:** A reporter expected a one-row `saveField` update but found a logged `UPDATE ... WHERE 1 = 1` changing all rows; a later commenter reports 1.1 million accounts updated, triggering many emails. Neither provides a minimal reproduction of the query-generation cause.
- **SOURCE FACT — shape / root cause / fix:** **MySQL** logs; transaction boundary unknown. Maintainers and reporters debate application code and method-cache collisions; no causal explanation is confirmed here. Reporters discuss trapping unsafe SQL and upgrading, not a demonstrated CommitCap-like control.
- **INFERENCE — alternatives:** I: does not stop first broad UPDATE; J: no worker collision reported; N: target-row ID enforced inside a narrow method; S: reject `WHERE 1=1`/unscoped changes and assert expected row count; R: a cap might reduce durable rows *if* the whole update is one protected transaction, which is not established here.
- **INFERENCE — residual / current fit / future / requirement:** **C for current PostgreSQL-only mechanism**; this is shape evidence, not PostgreSQL proof. Even a hypothetical relational cap could not recall emails already sent. Task-wide relevance unsubstantiated; **no requirement**.

### 11. Counter drift blocks issue creation — C

- **Source/date/project:** [getsentry/sentry #65745](https://github.com/getsentry/sentry/issues/65745), 2024-02-23 (project engineering issue with manual fixes linked).
- **SOURCE FACT — task / intended / actual:** On each newly seen event, assign the next project issue short ID and insert a group. For some projects the counter was one behind the largest existing ID, leading to `UniqueViolation` on `(project_id, short_id)` and repeated failure to create new groups. The issue documents manual repairs and a workaround; **root cause is explicitly unresolved**.
- **SOURCE FACT — shape / root cause / fix:** `store.save_event` task; the source speculates about rollbacks, races and merge/unmerge but does not establish any as the cause. Suggested counter simplification and backfill are proposals; exact transactions unknown.
- **INFERENCE — alternatives:** I: dedup incoming events; J: claim work only if duplicate processing is demonstrated; N: an atomic per-project ID allocator; S: verify counter against maximum existing ID; R: batches do not solve counter drift. Existing uniqueness constraint already blocks duplicate durable groups.
- **INFERENCE — residual / current fit / future / requirement:** **C**: the harm is *missing* inserts, not excess committed mutations, and the relational uniqueness constraint already prevents the invalid write. No relevant current transaction-local or future task-wide mutation ceiling; **no requirement**.

## Synthesis and next step

**Counts (11 independent project cases): A 0, B 3, C 7, D 1.** Sources
concentrate on wrong predicates/values during repair or migration, retries and
duplicate scheduling, and unclear creation/cleanup lineage. The strongest
direct PostgreSQL duplicate-worker examples (#5–6) favor durable identity,
atomic claiming and idempotency; neither demonstrates a broad committed
business-table effect bounded by the present fixture. Several strong-looking
broad UPDATE reports (#9–10) instead use MySQL. #2 is PostgreSQL but a one-row
*wrong value* is not a volume failure. #3 is ClickHouse; #8 is a vector store.

**Transaction-local enough?** No case proves the exact current fixture would
have helped. A hypothetical bounded single repair transaction (#1) is the
closest row-ceiling candidate, conditional on an unknown database and batch
shape. **Task-wide required?** Restart/retry and hourly recurrence (#1, #5–7)
span logical tasks; per-transaction budgets reset, and no task-wide design is
implemented. The need for that design is *not* established by these reports:
simpler controls dominate in at least the three B cases and usually elsewhere.
Do not infer a numeric share of real-world incidents from this selected sample.

**Decision: continue research; narrow target** to an evidenced PostgreSQL
repair/backfill where one measured protected transaction can produce much more
durable relational mutation than intended *even after* a validated predicate,
staging, idempotency and a narrow-operation challenge. Seek the exact statement,
transaction/batch boundary, affected rows and preexisting guardrails. There is
**not enough repeated evidence to promote #24, #25 or #26** to design work.
