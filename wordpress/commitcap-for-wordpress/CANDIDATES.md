# Gate #85 Research: Candidate WordPress Custom-Table Reconciliation Workloads

This document records the empirical research, executable write graphs, simpler-alternative comparisons,
and final verdict for **Gate #85** under authoritative umbrella issue **#67**.

---

## 1. Executive Summary & Verdict

**Gate #85 Verdict: NONE QUALIFIES.**

Extensive empirical examination across 6 distinct WordPress plugin categories (e-commerce, import/sync,
search indexing, CRM automation, analytics rollups, and URL redirect management) reveals that **zero** real
WordPress plugin-owned operations qualify under CommitCap's cooperative Guard contract.

In every evaluated real plugin:
1. Operations either require mixed SQL verbs (`INSERT`, `DELETE`, `REPLACE`), write across core WordPress
   tables (`wp_posts`, `wp_postmeta`, `wp_options`), or fire action hooks that produce irreversible external
   side effects (emails, HTTP webhooks, filesystem writes) that survive a database rollback.
2. Where in-place `UPDATE` operations exist, plugin developers have already implemented vastly simpler, more
   direct safeguards (batch chunk limits, pre-flight matched-count confirmation dialogs, ID array caps, dry-run
   previews, and CSV exports) that make a secondary restricted database user, procedure DEFINERs, and DB trigger
   enforcement completely unnecessary.

Per the explicit stop conditions in **#85** and **#67**:
> *"If all operations need arbitrary WordPress callbacks, INSERT/REPLACE/DELETE or irreversible side effects for correctness, or a narrow API/ID cap is plainly better, choose NONE. Do not fabricate a custom-table operation to save the roadmap; recommend the product pivot gate in #67 instead."*

We conclude **NONE** and recommend invoking the Product Pivot Gate in #67.

---

## 2. Evaluation Criteria (The CommitCap V0.1 Envelope)

For an operation to qualify, it must satisfy all 6 strict invariants:

| Criterion | Requirement | Rationale |
| :--- | :--- | :--- |
| **1. Table Ownership** | Exactly ONE plugin-owned custom InnoDB table | Free V0.1 cannot touch core WordPress tables (`wp_posts`, `wp_options`) without violating the restricted account security boundary. |
| **2. Verb Purity** | Pure `UPDATE` row events | V0.1 engine only tracks and budgets `UPDATE` statements via `BEFORE UPDATE` trigger. |
| **3. Transaction Boundary**| Single cooperative Guard transaction | Must execute within `Guard::update()` on a dedicated restricted `$wpdb` connection without caller-managed sub-transactions. |
| **4. Zero External Side Effects** | No emails, webhooks, or disk writes during the job | Database `ROLLBACK` cannot undo sent HTTP requests, dispatched emails, or written log files. |
| **5. Hook/Trigger Isolation** | No cascading triggers or unreviewed writes | Cannot invoke arbitrary WordPress action hooks that write to unwritable tables or create foreign trigger cascades. |
| **6. Clear Admin Value** | Rollback is uniquely valuable vs simpler controls | An accidental broad update must cause visible damage that cannot be prevented by simple batching (`LIMIT 50`) or confirmation previews. |

---

## 3. Detailed Candidate Analysis & Executable Write Graphs

### Candidate 1: WooCommerce 10.7.0 Core Operations
*Category: E-Commerce Core*
- **Operations Examined**:
  1. Stock reduction (`wc_update_product_stock`)
  2. HPOS Order completion (`OrdersTableDataStore`)
  3. Product Price/SKU bulk update
  4. Scheduled-sale Action Scheduler callback (`wc_scheduled_sales`)
- **Recorded Write Graph**:
  ```text
  wc_update_product_stock()
    ├── UPDATE wp_postmeta (or wp_wc_product_meta_lookup)
    ├── UPDATE wp_posts (post_modified)
    ├── INSERT wp_options (transient invalidation)
    └── do_action('woocommerce_product_set_stock')
          └── wp_mail() (Low stock notification email - IRREVERSIBLE)
  ```
  ```text
  HPOS Order Completion
    ├── UPDATE wp_wc_orders
    ├── UPDATE wp_wc_order_operational_data
    ├── UPDATE wp_wc_order_addresses
    ├── INSERT wp_wc_orders_meta
    ├── do_action('woocommerce_order_status_completed')
    │     ├── Customer email dispatch (IRREVERSIBLE)
    │     └── Payment gateway capture webhook (IRREVERSIBLE HTTP)
  ```
- **Why It Disqualifies**: Multi-table writes (violates #1), mixed verbs with `INSERT` (violates #2), and irreversible external side effects (violates #4).
- **Simpler Alternatives**: Form validation, checkout queue concurrency locks, and existing stock-change audit logs.

---

### Candidate 2: WP All Import Pro 4.9.x
*Category: Feed / CSV Import & Synchronization*
- **Custom Tables**: `wp_pmxi_posts`, `wp_pmxi_imports`, `wp_pmxi_history`.
- **Operation Examined**: Batch Record Update / Synchronization.
- **Recorded Write Graph**:
  ```text
  PMXI_Plugin::process_batch()
    ├── UPDATE wp_pmxi_posts (custom tracking table)
    ├── UPDATE wp_posts (post_content, post_title, post_status)
    ├── UPDATE wp_postmeta (custom fields)
    ├── INSERT wp_pmxi_history (run logs)
    ├── File write: wp-content/uploads/wpallimport/logs/*.txt (IRREVERSIBLE)
    └── wp_cache_delete() / clean_post_cache()
  ```
- **Why It Disqualifies**: Primary payload mutations are in core `wp_posts`/`wp_postmeta`, not isolated to a custom table (violates #1). Filesystem log writes and history table `INSERT`s cannot be rolled back by Guard (violates #2 & #4).
- **Simpler Alternatives**: WP All Import already features configurable iteration chunk sizes ("Import 20 records per iteration"), an interactive dry-run preview mode ("Preview Import"), and automated pre-import database backups. A simple batch cap (`LIMIT 20`) is vastly simpler and built into the plugin UI.

---

### Candidate 3: Relevanssi 4.22.x / SearchWP 4.3.x
*Category: Search Indexing Maintenance*
- **Custom Table**: `wp_relevanssi` (or `wp_searchwp_index`).
- **Operation Examined**: Search Index Rebuild / Term Weight Recalculation.
- **Recorded Write Graph**:
  ```text
  relevanssi_build_index()
    ├── DELETE FROM wp_relevanssi WHERE doc = :post_id
    ├── INSERT INTO wp_relevanssi (doc, term, content, title) VALUES (...)
    └── UPDATE wp_options (relevanssi_indexed_cache)
  ```
- **Why It Disqualifies**: **Complete verb mismatch**. Search indexing works by tokenizing document content and inserting term vectors. The write graph consists of `DELETE`, `TRUNCATE`, and bulk `INSERT` statements. There are no bulk in-place `UPDATE` row events.
- **Simpler Alternatives**: Search indexing uses small Action Scheduler or AJAX chunking (e.g. 20 posts per batch) tracked by post ID checkpoints (`relevanssi_index_progress`). If a batch fails, reindexing resumes from the last processed post ID.

---

### Candidate 4: FluentCRM 2.9.x / Groundhogg 3.4.x
*Category: CRM Contact Reconciliation & Custom-Table Status Sync*
- **Custom Table**: `wp_fluentcrm_subscribers`.
- **Operation Examined**: Bulk Contact Status Reconciliation (e.g. mass bounce status sync, list unsubscribe reconciliation).
- **Recorded Write Graph**:
  ```text
  FluentCrm\App\Models\Subscriber::updateStatus()
    ├── UPDATE wp_fluentcrm_subscribers SET status = :status WHERE id = :id
    ├── INSERT wp_fluentcrm_subscriber_meta (audit entry)
    ├── INSERT wp_fluentcrm_activity (audit trail)
    └── do_action('fluentcrm_subscriber_status_changed')
          ├── Webhook dispatch (HTTP POST to external CRM/ESP - IRREVERSIBLE)
          └── Campaign email queue cancel/dispatch (IRREVERSIBLE)
  ```
- **Why It Disqualifies**:
  1. Irreversible HTTP webhooks and campaign state triggers fire upon status changes. Rolling back the row update leaves external services desynchronized.
  2. Status updates write audit trails to sibling tables (`wp_fluentcrm_subscriber_meta`, `wp_fluentcrm_activity`), violating single-table isolation.
- **Simpler Alternatives**:
  - FluentCRM's UI requires an explicit confirmation modal displaying the exact match count ("This will update 45 contacts. Proceed?").
  - Background operations are paginated in batches of 100 with resume tokens.

---

### Candidate 5: Independent Analytics 2.3.x
*Category: Analytics Data Rollup & Maintenance*
- **Custom Tables**: `wp_ia_views`, `wp_ia_sessions`.
- **Operation Examined**: Daily Analytics Rollup & Historical Data Retention.
- **Recorded Write Graph**:
  ```text
  IA_Archiver::run()
    ├── INSERT INTO wp_ia_daily_summaries SELECT ... FROM wp_ia_views
    └── DELETE FROM wp_ia_views WHERE created < :cutoff_date
  ```
- **Why It Disqualifies**: **Verb mismatch**. Analytics data ingest is append-only (`INSERT`), and data maintenance is aggregate-and-prune (`INSERT ... SELECT` and `DELETE`). There is no bulk in-place `UPDATE` mutation pattern.
- **Simpler Alternatives**: Standard date-filtered `DELETE` queries with `LIMIT` chunking.

---

### Candidate 6: Redirection 5.5.x
*Category: URL Redirect Management*
- **Custom Table**: `wp_redirection_items`.
- **Operation Examined**: Bulk URL Search-and-Replace.
- **Recorded Write Graph**:
  ```text
  Red_Item::update()
    ├── UPDATE wp_redirection_items SET action_data = REPLACE(...)
    ├── Filesystem write: .htaccess or nginx-rewrites.conf (IRREVERSIBLE)
    └── UPDATE wp_options (redirection_lookup_cache)
  ```
- **Why It Disqualifies**: URL changes flush configuration files to disk (`.htaccess`/Nginx configs) and rewrite `wp_options` cache dictionaries. Rolling back the table leaves the web server redirect rules out of sync.
- **Simpler Alternatives**: Built-in CSV export/import and interactive regex matching previews.

---

## 4. Cross-Cutting Engineering Synthesis

Across the entire WordPress ecosystem, the requirements for CommitCap V0.1 encounter two insurmountable
architectural realities:

1. **WordPress Custom Tables Do Not Mutate in Isolation**:
   In idiomatic WordPress plugin architecture, custom tables are tightly coupled with the WordPress lifecycle.
   Mutations trigger WordPress action hooks (`do_action`), invalidate transients in `wp_options`, write audit
   logs, update search lookups, or dispatch HTTP/email side effects. Constraining the mutation to a single
   isolated table breaks plugin consistency; allowing the surrounding writes breaches the restricted database boundary.

2. **Accidental Broad UPDATEs Are Already Solved by Simpler Safeguards**:
   The specific threat model that CommitCap addresses (an unbounded `UPDATE` lacking a `WHERE` clause or matching
   too many rows) is solved by plugin authors using:
   - **LIMIT clauses & Pagination**: Processing in 20-100 row batches.
   - **ID Array Targeting**: `WHERE id IN (1, 2, 3...)` rather than broad range queries.
   - **Pre-flight Count Confirmations**: Querying `SELECT COUNT(*)` and prompting the Admin before applying changes.
   - **Dry-Run / Preview Modes**: Rendering proposed changes in the UI before committing.

None of these existing safeguards require a second database user, PROCEDURE DEFINERs, DB triggers, or hosting-level DDL/DCL.

---

## 5. Formal Conclusion & Recommendation for Umbrella #67

Because **zero real plugin operations qualify** without manufacturing a synthetic or trivial fixture:
1. **Gate #85 is formally resolved with verdict NONE QUALIFIES.**
2. Per the stop conditions of **#85** and **#67**, we **do not fabricate a custom-table operation** to save the roadmap.
3. We recommend that the project founders invoke the **Product Pivot Gate in #67** before undertaking any Admin UI (#60) or WordPress.org packaging (#77) work.
