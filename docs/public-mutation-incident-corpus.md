# Public mutation-incident evidence corpus (initial pass)

Research for [#37](https://github.com/MrDarkRoot/WriteLeash/issues/37), 2026-09-26.
This is a dated PostgreSQL mutation-budget research corpus, not a current Free
product thesis. #106 governs planned WooCommerce bulk price changes; that work
is not implemented, #107–#112 remain pending, and public release is deferred.
References to `CommitCap` in the dated analysis preserve the former product
name and are not current active identity.
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

## PostgreSQL repair/backfill follow-up

Research added 2026-09-26 after the initial corpus review. This section is a
separate, narrower pass: PostgreSQL must be named by the source, and the task
must be an operator edit, repair, backfill, migration or closely related data
correction. Cases where the incident is not excess durable relational mutation
remain as **falsification evidence**, not as support for CommitCap. The first
case is the only identified example where a single intended-row edit became a
multi-row PostgreSQL UPDATE; even there, simpler controls dominate. These
sources are project issues or first-person engineering reports, not independently
audited incident forensics. Unknowns remain explicit.

The classification letters retain the definitions above. Every case below
challenges the alternatives: narrow API/stored procedure (N), predicate and
expected-row-count/dry-run validation (P), idempotency (I), atomic claiming (J),
approval (A), and batching/transaction boundaries (BATCH). “Not relevant” means
the source does not report that failure shape, not that the control is
universally unnecessary.

### PG-1. DBeaver table editor used a non-unique column as UPDATE key — B

- **Source/date/project:** [DBeaver #367](https://github.com/dbeaver/dbeaver/issues/367), opened 2016-04-14; project maintainer confirmed a PostgreSQL-specific DBeaver 3.6.4 defect and said a hotfix would be released.
- **SOURCE FACT — database/task/intended effect:** PostgreSQL is explicitly named in the issue/thread; the server version is not stated. An operator edited a field on one selected row in DBeaver's table-data UI; the intended durable effect was one-row editing, not a repair or backfill job.
- **SOURCE FACT — actual effect and write shape:** The tool generated `UPDATE blah SET foo = TRUE WHERE created_by_id=123`, although `created_by_id` was an ordinary indexed foreign key, not unique or the primary key; the table's primary key was `id`. All rows sharing that creator ID were changed. The issue provides table DDL and says this happened for tables with both columns. The exact count of matching rows, transaction/autocommit boundary, production environment, and whether the reported rows were later restored are not stated.
- **SOURCE FACT — root cause / corrective fix:** The reporter isolated the generated SQL; a DBeaver maintainer confirmed the bug: version 3.6.4 had treated the first non-unique index as the table's primary key. The issue says a hotfix was planned, but does not link its release or a restore operation.
- **INFERENCE — alternatives:** N: edit by explicit primary key or use a narrow operation. P: DBeaver should use the declared PK and show the generated predicate / affected-row count before accepting a one-row edit. I: unrelated to repeated execution. J: no worker or concurrent-claim issue. A: confirmation may help but does not validate the key. BATCH: not a batch; a row-count guard can reject this statement if its budget is one.
- **INFERENCE — residual / current fit / future / requirement:** **B.** This is direct evidence that a flexible operator tool can produce more row updates than one selected-row intent. A hard relation-level per-transaction cap could add independent containment if the UI/key-selection control regressed again, but the known root cause has a direct PK/predicate correction and exact-one affected-row check. The current PG16.4 fixture does not cover DBeaver or this table; the single-statement shape is only an abstract transaction-local fit. No task-wide relevance or product requirement is established.

### PG-2. One-transaction 4.5-million-row schema backfill locked production — C

- **Source/date/project:** [“Schema Migration Gone Wrong: Lessons From a Production Outage”](https://medium.com/@smdeepya/schema-migration-gone-wrong-lessons-from-a-production-outage-52b02b99edc0), first-person engineering writeup published 2025-08-03. It is not a formal company postmortem.
- **SOURCE FACT — database/task/intended effect:** The author describes a Flyway migration on PostgreSQL adding `position_id` to an events table and copying `order_id` into it for existing rows. The report says there were about 3 million existing rows in staging and 4.5 million in production. The intended target was all legacy rows.
- **SOURCE FACT — actual effect and write shape:** Staging first experienced deadlocks while automation tests queried/updated the same table during a multi-million-row update. In production, a later migration attempted the 4.5-million-row backfill in one transaction; the table was locked, API requests failed, and migration stopped before index creation. The author does not report an incorrect extra-row set or data-value corruption. Schema-version manipulation and manual recovery were used to unblock deploy; the backfill was later performed separately in the background. Exact per-row commit results and duration are not given.
- **SOURCE FACT — root cause / corrective fix:** A very large intended update was bundled in schema migration. The writeup recommends separating and batching long-running data migration, adding the index, and running `ANALYZE`; it also describes a phased API alternative that would have deferred backfill until later.
- **INFERENCE — alternatives:** N: a phased API/schema rollout avoids requiring an immediate full-table rewrite. P: dry-run and expected-count checks confirm the all-existing-row scope, but do not reduce lock duration. I: idempotency does not reduce lock duration for one full-table transaction. J: no competing claim is implicated. A: a maintenance approval/window can prevent unexpected user impact but is not itself a write-scope bound. BATCH: the direct corrective control is smaller committed batches or a background process with explicit progress and transaction boundaries.
- **INFERENCE — residual / current fit / future / requirement:** **C.** The reported effect set was the intended 4.5 million rows; the demonstrated failure was locking/availability from doing it in one transaction, not mutation amplification beyond intent. A smaller cap would abort the valid migration and require the same batch orchestration to resume; once batches and scope assertions are correct, no residual ceiling value is demonstrated. Current CommitCap has neither this schema nor arbitrary UPDATE support. No task-wide hypothesis or product requirement follows.

### PG-3. CourtListener migration generated a large intended update across replicated servers — C

- **Source/date/project:** [Free Law Project / CourtListener #1109](https://github.com/freelawproject/courtlistener/issues/1109), project-authored “DB Migration and Replication Post-Mortem,” 2019-12-31; incident period 2019-12-27 to 2019-12-30.
- **SOURCE FACT — database/task/intended effect:** PostgreSQL logical replication across a master, old master, AWS PostgreSQL/RDS server, and client PostgreSQL servers. The change added fields and converted nullable text values to Django's blank-string convention. The intended update touched existing values in the affected columns across the relevant tables; the source describes legal-case tables with millions of rows and hundreds of GB, not an unintended business-row scope.
- **SOURCE FACT — actual effect and write shape:** Before PostgreSQL 11, adding defaulted columns rewrote large tables and exhausted disk on replicas. Subsequent updates over several columns formed large transactions that replication queued; the old master entered a memory boom/bust/restart cycle. The report says 300GB of swap plus 64GB RAM was needed to flush the backlog and caused front-end downtime. It does not report rows changed beyond those targeted by the schema/data migration. Per-statement row counts are not supplied.
- **SOURCE FACT — root cause / corrective fix:** PostgreSQL pre-11 default-column rewrites plus very large logical replication transactions and the multi-hop replication topology. The author rewrote the migrations to add nullable columns without defaults, set defaults separately, schedule updates, disable subscriptions during schema coordination, and apply `NOT NULL` in a coordinated order. The report says final schema updates landed on master, old master, AWS, and clients.
- **INFERENCE — alternatives:** N: a staged schema/API transition can avoid a single all-row cutover. P: counts and replication-lag checks verify intended scope and rollout, but do not cap the WAL/memory of a huge transaction. I: retries/duplicate execution are not reported. J: no concurrent claim race is reported. A: an operational approval gates a high-risk rollout but does not reduce transaction size. BATCH: smaller commits, PostgreSQL-version-aware migration and replication redesign directly address the reported problem.
- **INFERENCE — residual / current fit / future / requirement:** **C.** This is high-quality PostgreSQL migration evidence, but the harm is resource/replication amplification of an intended all-row change, not excess durable mutation beyond intent. The report's corrective architecture and batching dominate; a hard cap would either abort intended migration work or need a separate resumption workflow. Actual mutation-accounting support in current CommitCap is absent for these tables and DDL. No task-wide requirement.

### PG-4. FX history backfill exceeded PostgreSQL's parameter limit before commit — C

- **Source/date/project:** [portfonia/portfonia #402](https://github.com/portfonia/portfonia/issues/402), project issue opened 2026-09-09, with production reproduction and a follow-up verification comment.
- **SOURCE FACT — database/task/intended effect:** PostgreSQL `fx_rates` history backfill for 14 currency pairs over about five years of daily rates. Intended durable effect was about 17,500 historical upserts; the later report says 16,901 rows were eventually inserted across 14 pairs after the fix.
- **SOURCE FACT — actual effect and write shape:** `_upsert_fx_history` formed one unbounded multi-row INSERT with about 87,000 bound parameters (five per row), exceeding PostgreSQL's 65,535 protocol limit. The first production run failed before `session.commit()` and wrote zero rows. The source documents one failed statement inside a script-level transaction; no partial committed rows were reported.
- **SOURCE FACT — root cause / corrective fix:** The test used one day of history and 14 pairs (70 binds), far below the production-sized input. [PR #404](https://github.com/portfonia/portfonia/pull/404) implemented 5,000-row batches (25,000 parameters each) and changed `test_backfill_fx_rates.py`; the issue reports a successful production rerun of 16,901 rows. One pair had only a month of source history for an unrelated provider-data limitation.
- **INFERENCE — alternatives:** N: a dedicated bulk-upsert path fits this stable operation. P: bound the expected row count and assert returned upsert count. I: `ON CONFLICT` already gives rerun semantics. J: no competing job is implicated. A: not central; deployment approval alone does not lower bind count. BATCH: the primary fix chunks statements below the parameter limit; the source reports a script-level `session.commit()`, not a separate commit for each chunk.
- **INFERENCE — residual / current fit / future / requirement:** **C.** The failure happened before any durable write, so there was no excessive mutation to contain. Chunking is the direct correctness/operability fix. The current fixture tests neither INSERT nor this relation; no task-wide issue or product requirement.

### PG-5. PostgreSQL backfill snapshot omitted future organization ownership — C

- **Source/date/project:** [“Your backfill is a photograph”](https://dev.to/enderyentar/your-backfill-is-a-photograph-2kgj), first-person MailFlat engineering writeup, posted Sep 9 (the DEV page does not state a year) and cross-posted from the author's engineering site.
- **SOURCE FACT — database/task/intended effect:** An Alembic migration created an organization for every existing user and linked it; the author explicitly reports that the initial backfill left every row present at that time correct. PostgreSQL is named in the report (the Alembic construct used in the backfill also failed locally and was caught on Postgres/staging). Later, two newly created accounts and seven API keys had no organization/owner link.
- **SOURCE FACT — actual effect and write shape:** The later missing links arose because no signup path created an organization after the one-time migration. The source does not state transaction/batch boundaries or show excess UPDATE/INSERT rows; it describes durable rows that were missing required relationships.
- **SOURCE FACT — root cause / corrective fix:** The historical snapshot backfill was not paired with a continuing write-path guarantee. The author reports moving the invariant to a SQLAlchemy `before_flush` hook that applies to every new user, plus a direct model-level test; production was reported clean after repair. The exact SQL/repair transaction and per-row counts are not provided.
- **INFERENCE — alternatives:** N: a canonical organization-creation API/service can enforce the relationship if every writer uses it; a model/database constraint is stronger where semantics permit. P: post-backfill anti-join counts reveal current null links but do not keep new writes correct. I: not a retry issue. J: no concurrent claim issue. A: approval has little bearing. BATCH: batch limits help execute a repair, not prevent future orphan creation.
- **INFERENCE — residual / current fit / future / requirement:** **C.** The incident is missing/future writes, not excessive durable effects. The guarantee belongs on the ongoing write path; a finite ceiling would neither populate ownership nor keep it valid. Current fixture has no such schema or inserts. No task-wide relevance or requirement.

### PG-6. SQLite-to-PostgreSQL port left a sequence behind table state — C

- **Source/date/project:** [matrix-org/synapse #9382](https://github.com/matrix-org/synapse/issues/9382), opened 2021-02-11; Synapse project maintainers and users discuss the repair.
- **SOURCE FACT — database/task/intended effect:** `synapse_port_db` moved a homeserver database from SQLite to PostgreSQL. The migration was intended to make the migrated event-auth-chain records and the PostgreSQL-generated ID sequence usable for later room/DM creation.
- **SOURCE FACT — actual effect and write shape:** Port completed without error, but later room creation failed with a unique-key violation because `event_auth_chain_id` lagged `event_auth_chains`; a later trace shows sequence `41232` while table max was `41233`. The transaction/batch shape of `synapse_port_db` and the full row counts are not specified. The reported harm was inability to create rooms, not extra durable table rows.
- **SOURCE FACT — root cause / corrective fix:** A sequence-consistency check was missing from the migration/startup path. The suggested `setval(sequence, max(chain_id))` repaired the issue; maintainers confirmed sequence advancement was safe while the server was offline, and a reporter confirmed the workaround restored room creation. Later code added an explicit consistency check and error guidance.
- **INFERENCE — alternatives:** N: a migration/helper that seeds sequence state from the destination table is the narrow fix. P: compare sequence last value to table maximum after migration. I: retrying migration without sequence repair would not help. J: no competing job claim is implicated. A: operator approval does not solve the mismatch. BATCH: reprocessing fewer rows has no relevance to sequence state.
- **INFERENCE — residual / current fit / future / requirement:** **C.** The defect concerns sequence metadata and missing future IDs, not over-budget relational row changes; sequences are explicitly outside current CommitCap effect semantics. A mutation ceiling would not prevent or repair it. No task-wide hypothesis or product requirement.

### PG-7. Rails `update_all` ignored a `DISTINCT ON` projection — D

- **Source/date/project:** [rails/rails #37140](https://github.com/rails/rails/issues/37140), opened 2019-09-05; Rails project issue with a minimal reproduction repository.
- **SOURCE FACT — database/task/intended effect:** The reporter expected `select("DISTINCT ON (something) *").update_all(...)` to update only the distinct rows. The issue's system configuration states PostgreSQL 11.4, while its embedded minimal test actually establishes an in-memory SQLite connection and creates only 20 synthetic rows.
- **SOURCE FACT — actual effect and write shape:** The reporter's asserted result is that all table rows would be updated because `select` projections are ignored by this API. However, the embedded minimal program connects to SQLite (which does not implement PostgreSQL `DISTINCT ON`), and the reporter says dependencies prevented executing it; Rails maintainers explain that `update_all` ignores `select` and builds one UPDATE from the relation's `where`/`order`. There is no demonstrated PostgreSQL run, production incident, actual affected-row count, or transaction boundary.
- **SOURCE FACT — root cause / corrective fix:** The intended row-set was expressed in a projection, not in a write predicate. A Rails maintainer says this is likely the expected behavior of `update_all`; using a specific SQL predicate or explicit SQL is suggested. The issue was eventually closed stale, with no recorded product fix.
- **INFERENCE — alternatives:** N: express the distinct-row result as IDs in a specific operation. P: inspect generated SQL and assert expected affected count; correct predicate selection is primary. I: repeated execution is not the reported issue. J: no concurrent claim is implicated. A: confirmation could reduce risk but is weaker than a write-scope assertion. BATCH: batching cannot make a predicate correct.
- **INFERENCE — residual / current fit / future / requirement:** **D — insufficient PostgreSQL evidence.** The reported scope mismatch is interesting but the shown reproduction runs SQLite, and the project thread has no real database incident or transaction detail. If confirmed against PostgreSQL, a relational cap might limit an accidental broad update, but the narrow predicate/API fixes the described mistake. No fit or requirement is asserted from this source.

### Follow-up synthesis

**Follow-up counts (7 cases): A 0 / B 1 / C 5 / D 1.** Direct PostgreSQL
evidence improved the corpus by establishing one real GUI-generated multi-row
UPDATE from a one-row edit (PG-1), plus several concrete migration/backfill
counterexamples. It did **not** establish repeated incidents where a backfill
committed materially more rows than its validated intended scope.

- **Did any case survive the narrow-operation challenge strongly enough for A?**
  No. PG-1 is the only positive overscope case, and the tool's wrong-key
  selection has a direct fix (use the true PK, show the generated predicate,
  assert one affected row). A per-transaction ceiling might be an independent
  residual safety layer for a generic table-editing surface, so it is B, but the
  report does not show why that layer remains valuable after those controls.
- **Did direct PostgreSQL evidence materially improve the transaction-local
  case?** It makes the mechanism's *shape* concrete: an unintended broad UPDATE
  can be one statement (PG-1). The current PG16.4 fixture does not cover that
  table, role, tool, or deployment. The other cases are expected full-table
  mutation with availability costs (PG-2/3), a backfill that fails before
  commit (PG-4), a missing write invariant (PG-5), sequence state (PG-6), or
  SQLite-only reproduction (PG-7). They do not validate current enforcement.
- **Task-wide authority?** No repeated case shows committed over-mutation
  accumulating across committed batches after each batch's scope has been
  validated. Large jobs span transactions for lock/resource management, but
  these sources either describe the intended total set, missing effects, or
  simpler batch/repair controls. Cross-transaction authority remains an
  unsupported hypothesis, not an evidence-derived requirement.
- **Hypotheses:** #24, #25 and #26 remain watchlist hypotheses. No design or
  implementation work is justified by this follow-up.

**Decision: continue research; insufficient evidence for design promotion.**
The most promising observed shape is PG-1's one-statement operator edit, but the
next search should seek an independently documented *repair/backfill* where a
validated operation still needs flexible writes, a single transaction's actual
durable row set substantially exceeded its known intent, and an independent
database-side ceiling would add value beyond a narrow API, a checked predicate,
dry-run/count assertion, approval, and safe batch boundaries. Do not promote a
concept based on a failure caused by locks, WAL, an invalid migration, missing
rows, or sequence state alone.
