# Gate #85 Research: Real WordPress Plugin Workloads (Runtime Evidence)

This document records the **executed** research for Gate #85 under umbrella #67.
It replaces the earlier inferred write-graph claims in this file, which were not
sufficient evidence (they described plausible graphs without installing or
running the plugins).

> **Historical selection context:** this candidate study belongs to the earlier
> #67 V0.1 product-development thesis. #106 now supersedes #67 as authority for
> the intended public Free product: safe WooCommerce bulk price changes. The
> old candidate-selection conclusion (including prior WooCommerce research)
> does not reject or define the new #106 workflow, which remains planned and
> unimplemented pending #107–#112. Public release is deferred. Preserve this
> runtime evidence as the record of what that earlier study actually tested.

## 0. Evidence labels

Every claim below carries one of these labels:

- **OBSERVED AT RUNTIME** — produced by executing the real installed plugin in a
  disposable WordPress 6.8.3 / MySQL 8.0.44 fixture with the MySQL general log
  enabled. Raw transcripts are committed under
  `wordpress/tests/research/transcripts/`.
- **SOURCE-CODE VERIFIED** — read from the exact plugin source ZIP (sha256
  pinned) but not executed.
- **DOCUMENTED BY PLUGIN** — stated by the plugin's own readme/UI text.
- **INFERRED — NOT SUFFICIENT** — reasoning that is not executable evidence and
  cannot by itself support a selection.

Reproduction: `bash wordpress/tests/research/run.sh` (downloads the pinned,
sha256-verified ZIPs from wordpress.org, boots the pinned MySQL fixture, installs
each plugin through WP-CLI, runs the operation, and prints the captured SQL,
hooks and mail/HTTP attempts). `wordpress/tests/research/checksums.txt` pins the
artifacts.

Instrumentation (all candidates):

- `mysql.general_log` (TABLE output) filtered to the WordPress connection's
  `thread_id` and bounded by sentinel queries, so only the operation's own SQL
  is reported.
- `add_action('all', ...)` records every WordPress hook fired during the
  operation window.
- `pre_wp_mail` and `pre_http_request` filters count/record attempted email and
  HTTP side effects (HTTP is blocked to keep the fixture disposable).
- **INFERRED — NOT SUFFICIENT:** the harness does not observe filesystem writes;
  it makes no runtime filesystem-inactivity claim.
- A fresh `root` observer connection verifies durable rows after each run.

## 1. Redirection 5.5.2 — SELECTED

**Operation:** Redirects page → *select all matching* → Bulk Actions → **Disable**,
i.e. `POST /wp-json/redirection/v1/bulk/redirect/disable`
with `global=true`. The Admin UI sets `global=true` only when the "select all"
checkbox is used (`SOURCE-CODE VERIFIED`: `redirection.js` builds
`d.global = !0` when `selectAll`).

**Installed:** `redirection.5.5.2.zip` (sha256
`2c562a256797828ec3a0ddfd19425f0c1bc126554bb4baaa95434b35f361a648`), installed and
activated via WP-CLI into a disposable fixture.

**OBSERVED AT RUNTIME** (transcript `transcripts/redirection.txt`):

```text
SQL: UPDATE wp_redirection_items SET status='disabled'
SQL: SELECT * FROM wp_redirection_items ORDER BY id DESC LIMIT 0,25
SQL: SELECT COUNT(*) FROM wp_redirection_items
SQL: SELECT COUNT(*) FROM wp_redirection_items WHERE status='disabled'
HOOKS: {"redirection_capability_check":1, ... WordPress REST and option hooks ...}
MAIL: 0
HTTP: []
TABLE VERBS (redirection_items): {"UPDATE":1,"SELECT":3}
```

- Tables and verbs: exactly one plugin-owned custom InnoDB table,
  `wp_redirection_items`, mutated by **one unbounded `UPDATE`** (no `WHERE`
  clause when the Admin selects all with no filter). No `INSERT`/`DELETE`/
  `REPLACE`. No `START TRANSACTION`/`COMMIT` is issued by the plugin.
- **OBSERVED AT RUNTIME:** The captured mutation window includes
  `redirection_capability_check`, zero mail attempts and zero HTTP attempts;
  filesystem activity was not instrumented. `redirection_redirect_updated`
  is absent from the captured hooks. **SOURCE-CODE VERIFIED:** this cache-clearing
  hook is **not** fired by this bulk path because
  `Red_Item::set_status_all()` is a single `$wpdb->query`.

**Simpler-control comparison (OBSERVED AT RUNTIME, same transcript):** the
item-scoped variant (`items=[1,2]`) issues one `UPDATE ... WHERE id='1'` per
selected redirect:

```text
SQL: UPDATE `wp_redirection_items` SET `status` = 'enabled' WHERE `id` = '1'
SQL: UPDATE `wp_redirection_items` SET `status` = 'enabled' WHERE `id` = '2'
```

That scoped path already has an explicit ID list, so a batch/ID cap is the
natural safeguard there. The *global* path is the operation that lacks any row
bound: a wrong filter or an unintended "select all" disables every redirect, and
chunking the update in batches does not prevent that. A logical mutation budget
`L` (refuse and roll back above `L`, before COMMIT) is the control that matches
this failure mode.

**Cooperative-contract fit:** the mutation is one statement on one plugin-owned
table; `Red_Item::set_status_all()` uses `global $wpdb`, so a version-pinned
adapter can substitute the restricted runtime `$wpdb` inside a Guard-owned
transaction and call the plugin's own method. Required runtime grants:
`SELECT, UPDATE` on `wp_redirection_items` only (plus the WriteLeash evidence
grants). This is exactly the envelope proven by #82/#83/#84.

**Verdict: SELECTED — Redirection 5.5.2, "Bulk Actions → Disable
(select all matching)" via `Red_Item::set_status_all()`.** Enable and Reset
require independent write-graph and side-effect evidence before certification.

Adapter issue: created as `#87` (linked from #67 by the Maintainer when accepted;
the implementation issue body is reproduced in the PR evidence report).

## 2. Fluent Forms 5.2.9 — does not qualify

**Operation:** Entries page → select entries → Bulk Actions → **Mark as Read**
(`SubmissionService::handleBulkActions(['action_type' => 'read'])`, the exact
method invoked by `SubmissionController@handleBulkActions` on the plugin's REST
route `POST /fluentform/v1/submissions/bulk-actions`; `SOURCE-CODE VERIFIED`).

**Installed:** `fluentform.5.2.9.zip` (sha256
`e630a71d0db40dbe084588fd5e8ea0d1ba91e9a31f9f3f7e4ce8ec24c32abc4e`).

**OBSERVED AT RUNTIME** (transcript `transcripts/fluentform.txt`):

```text
SQL: update `wp_fluentform_submissions` set `status` = 'read', `updated_at` = '...'
     where `form_id` = 1 and `id` in (1, 2, 3, 4, 5, 6)
HOOKS: {"fluentform/after_submission_status_update":6, ...}
MAIL: 0
HTTP: []
TABLE VERBS (fluentform_submissions): {"UPDATE":1,"SELECT":1}
```

- Single plugin-owned custom table, pure `UPDATE`, no mail/HTTP observed.
- But the mutation is already **id-scoped to the explicitly selected entries**;
  the operation cannot become a broad accidental update through the UI. A
  batch/ID cap is plainly better than a mutation budget here, and the plugin
  fires `fluentform/after_submission_status_update` per entry (six action hooks
  for six entries) which any rollback semantics would have to account for.
- **Disqualifier:** no accidental-broad-update failure mode; simpler control
  (explicit IDs) already wins.

## 3. Relevanssi 4.22.1 — does not qualify

**Operation:** Relevanssi → Index → **Build index** (`relevanssi_build_index()`,
the real rebuild entry point).

**Installed:** `relevanssi.4.22.1.zip` (sha256
`5c60bf8daf999611ee2fc88c703f87d6c68a15df4aca3283a413ce5c66fb847a`).

**OBSERVED AT RUNTIME** (transcript `transcripts/relevanssi.txt`, large INSERT
statements truncated in the committed transcript):

```text
SQL: TRUNCATE TABLE wp_relevanssi
SQL: INSERT INTO `wp_options` ... 'relevanssi_index' ...
SQL: INSERT IGNORE INTO wp_relevanssi (...) VALUES (...)
SQL: UPDATE `wp_options` SET `option_value` = 'done' WHERE `option_name` = 'relevanssi_indexed'
SQL: ANALYZE TABLE wp_relevanssi
HOOKS: {"relevanssi_indexing_query":1, "relevanssi_do_not_index":8, ...}
HTTP: ["http://research.test/wp-admin/admin-ajax.php"]
TABLE VERBS (relevanssi): {"TRUNCATE":1,"SELECT":10,"INSERT":9,"UPDATE":1}
```

- Verb mismatch: the plugin-owned table is rebuilt with `TRUNCATE` +
  bulk `INSERT IGNORE`; the only `UPDATE` observed is a `wp_options` progress
  marker, not a row-event budget on the plugin table.
- It also performs an HTTP request during the run and fires many indexing
  filters/hooks; a rollback cannot undo those.
- **Disqualifier:** not an UPDATE reconciliation; multiple verbs and non-DB side
  effects.

## 4. WPForms Lite 1.9.2.2 — does not qualify (not executed)

**SOURCE-CODE VERIFIED:** WPForms Lite ships the Entries *list* and single-entry
view, but the entry read/unread and bulk entry status mutations are gated behind
the Pro add-on. The Lite template renders "Mark as Unread" as a disabled element
(`lite/templates/admin/entries/single/entry.php`), and no Lite bulk entry-status
UPDATE handler exists in `src/`. There is therefore no Admin-executable
entry-status operation in the free plugin to run; this candidate is recorded as
not executable rather than fabricated. The operation was not executed.

## 5. Previously claimed candidates not re-executed

The earlier version of this file claimed runtime write graphs for WooCommerce
10.7.0, WP All Import Pro 4.9.x, FluentCRM/Groundhogg, Independent Analytics and
Redirection without installing or running them. Those claims were
**INFERRED — NOT SUFFICIENT** and are withdrawn.

- WooCommerce core operations were previously researched and rejected in
  #67/#79 (`DOCUMENTED BY PLUGIN` / prior disposable runs); no new execution was
  performed here and no WooCommerce-core selection is made.
- WP All Import **Pro** is a commercial plugin that cannot be installed from
  wordpress.org in this disposable fixture; no claim is made about it.
- Independent Analytics and FluentCRM were not executed in this round and are
  not part of the verdict.

## 6. Verdict

```text
SELECTED: Redirection 5.5.2 — Bulk Actions "Disable (select all matching)"
→ Red_Item::set_status_all() → one unbounded UPDATE on
wp_redirection_items
```

Exactly one adapter implementation issue is required by #85. It is created as
issue #87 with this scope:

```text
Installed plugin/version: Redirection 5.5.2 (wordpress.org ZIP, sha256 pinned)
Restricted connection: SELECT, UPDATE on wp_redirection_items only
Guard: Guard::update('wp_redirection_items', L, ...) calling the plugin's own
       Red_Item::set_status_all() through a version-pinned adapter
Proof: N row events COMMIT, N+1 typed Budget_Denied with full rollback verified
       by a fresh observer, Admin-readable evidence, physical ceiling P installed
       by the trusted operator
```

The selection is justified by runtime evidence (Section 1): the only executed
candidate with a genuine unbounded-UPDATE failure mode, a single plugin-owned
table, pure UPDATE verb, no observed external side effects, and a cooperative
method that can be driven through the restricted connection.
