# #120 public payload boundary (repository evidence only)

Audited before changing the runtime, against merged main
`1a5c2fcb4caa9f4f49785cf65eee1fec71b8d615`. This document is not distributed.
Classification applies to each row's named elements, not to a future product
promise. Every named element has exactly one classification. No existing
element is classified OPTIONAL_ADVANCED_FUTURE: a future Advanced product needs
its own reviewed entrypoint and authority contract.

## Call tracing

At the base, `writeleash.php` literally requires all the classes below, then
calls `Plugin::boot`. That boot registers lifecycle, Free Admin, resume/Undo
REST and scheduler callbacks **and** Redirection REST, Advanced Admin and CLI.
The legacy REST filter claims Redirection's own bulk route; it does not register
a separate route. Its path is dispatch → Certified_Operation/Operation_Config
→ Certified_Operation_Status → Compatibility_Grants/Compatibility_Doctor →
restricted wpdb → Redirection_Bulk_Disable → Guard → Guard_Transaction,
Guard_Monitor/Guard_Sql and Update_Engine. Last_Outcome is informational only.
Advanced Admin and Product_CLI → Product_Status → the same readiness graph and
Disposable_Demo. Trusted provisioning → Provisioning_Plan → Update_Engine,
Compatibility_Doctor/Grants; Disposable_Demo_Setup adds the demo target.

In contrast, Free_Admin → Woo_Price_Planner/Change_Plan → Job_Repository →
Job_Worker → Woo_Price_Mutator/Price_Apply_Journal; Undo_Rest/Free_Admin →
Undo_Repository → Undo_Worker → Woo_Undo_Mutator. Job/Undo transaction fences
implement Price_Apply_Transaction_Guard, not legacy Guard. Runner_Authority
owns the lifecycle row; Action Scheduler owns wake-ups only. All these classes
use the ordinary WordPress connection and Woo CRUD. Their only external PHP
include is WordPress's `ABSPATH . 'wp-admin/includes/upgrade.php'` (dbDelta).
`Environment` is needed by Lifecycle, not just by historical Doctor.

## PHP files/classes and original manifest entries

Paths in this table are relative to `wordpress/writeleash`. Each was an entry
in the base public manifest. Historical files remain byte-for-byte in source;
they are omitted from the public allowlist and loaded only by repository tests.

| File | Classes / traced role | Classification |
|---|---|---|
| writeleash.php | Literal Woo bootstrap / Plugin::boot | PUBLIC_FREE_REQUIRED |
| uninstall.php | Runner shutdown, owned wake-up cancellation, exact local cleanup | PUBLIC_FREE_REQUIRED |
| includes/class-environment.php | Environment; Lifecycle raw facts | PUBLIC_FREE_REQUIRED |
| includes/class-lifecycle.php | Lifecycle; runner gate/reconcile, no installer | PUBLIC_FREE_REQUIRED |
| includes/class-plugin.php | Plugin; Woo hook registration | PUBLIC_FREE_REQUIRED |
| includes/class-update-engine.php | Update_Engine; helper/routine/trigger accounting and attestation | REPOSITORY_ONLY_HISTORICAL |
| includes/class-guard-error.php | Guard_Error; legacy failure base | REPOSITORY_ONLY_HISTORICAL |
| includes/class-unsupported-transaction-state.php | Unsupported_Transaction_State; legacy transaction refusal | REPOSITORY_ONLY_HISTORICAL |
| includes/class-budget-denied.php | Budget_Denied; legacy denial evidence | REPOSITORY_ONLY_HISTORICAL |
| includes/class-guard-transaction.php | Guard_Transaction; legacy transaction state | REPOSITORY_ONLY_HISTORICAL |
| includes/class-guard-sql.php | Guard_Sql; query classifier/reserved variables | REPOSITORY_ONLY_HISTORICAL |
| includes/class-guard-monitor.php | Guard_Monitor; temporary query filter during Guard | REPOSITORY_ONLY_HISTORICAL |
| includes/class-guard.php | Guard; restricted transaction mutation authority | REPOSITORY_ONLY_HISTORICAL |
| includes/class-compatibility-grants.php | Compatibility_Grants; trusted/runtime grants parser | REPOSITORY_ONLY_HISTORICAL |
| includes/class-compatibility-doctor.php | Compatibility_Doctor; installer/runtime attestation | REPOSITORY_ONLY_HISTORICAL |
| includes/class-provisioning-plan.php | Provisioning_Plan; trusted SQL lifecycle | REPOSITORY_ONLY_HISTORICAL |
| includes/class-redirection-bulk-disable.php | Redirection_Bulk_Disable; restricted adapter | REPOSITORY_ONLY_HISTORICAL |
| includes/class-certified-operation.php | Certified_Operation; pinned Redirection descriptor | REPOSITORY_ONLY_HISTORICAL |
| includes/class-operation-config.php | Operation_Config; legacy enable/budget authority | REPOSITORY_ONLY_HISTORICAL |
| includes/class-certified-operation-status.php | Certified_Operation_Status; restricted connection/readiness | REPOSITORY_ONLY_HISTORICAL |
| includes/class-last-outcome.php | Last_Outcome; informational legacy evidence | REPOSITORY_ONLY_HISTORICAL |
| includes/class-disposable-demo.php | Disposable_Demo; Guard on owned demo table | REPOSITORY_ONLY_HISTORICAL |
| includes/class-disposable-demo-setup.php | Disposable_Demo_Setup; trusted demo install/cleanup | REPOSITORY_ONLY_HISTORICAL |
| includes/class-product-status.php | Product_Status; legacy Admin/CLI snapshot | REPOSITORY_ONLY_HISTORICAL |
| includes/class-admin-page.php | Admin_Page; Tools Advanced and legacy action handler | REPOSITORY_ONLY_HISTORICAL |
| includes/class-product-cli.php | Product_CLI; doctor/status/demo | REPOSITORY_ONLY_HISTORICAL |
| includes/class-redirection-bulk-disable-rest.php | Redirection_Bulk_Disable_Rest; stock route interception | REPOSITORY_ONLY_HISTORICAL |
| operator-setup.txt | Trusted operator script/instructions, retained for research tests | REMOVE_FROM_PUBLIC_PACKAGE |
| LICENSE | Reviewed GPLv2 text | PUBLIC_FREE_REQUIRED |
| readme.txt | Minimum Woo-only user instructions; full copy belongs to #121 | PUBLIC_FREE_REQUIRED |

All 32 `includes/free/*.php` entries are PUBLIC_FREE_REQUIRED. Explicit class
inventory (multi-class files included):

| File stem (`includes/free/class-<stem>.php`) | Classes / interfaces / traits | Classification |
|---|---|---|
| free-support-contract | Free_Support_Contract, Free_Job_Limit_Error | PUBLIC_FREE_REQUIRED |
| price-decimal | Price_Validation_Error, Immutable_Price_Value, Price_Decimal | PUBLIC_FREE_REQUIRED |
| price-operation | Price_Operation, Price_Calculator | PUBLIC_FREE_REQUIRED |
| price-range | Price_Range_Filter; optional inclusive selection filter, never a mutation path | PUBLIC_FREE_REQUIRED |
| safety-policy | Safety_Policy, Policy_Result, Policy_Evaluator | PUBLIC_FREE_REQUIRED |
| product-snapshot | Price_Store_Context, Product_Price_Snapshot, Eligibility_Result, Product_Price_Eligibility, Price_Reason_Messages | PUBLIC_FREE_REQUIRED |
| product-selector | Price_Selection_Spec, Product_Price_Selector | PUBLIC_FREE_REQUIRED |
| change-plan | Change_Plan_Item, Plan_Hasher, Change_Plan, Woo_Price_Planner | PUBLIC_FREE_REQUIRED |
| durable-charset | Durable_Charset; lossless physical UTF-8 setup/upgrade verification | PUBLIC_FREE_REQUIRED |
| price-apply-connection | Price_Apply_Error, Price_Apply_Connection (ordinary WP connection assertion) | PUBLIC_FREE_REQUIRED |
| runner-authority | Runner_Authority | PUBLIC_FREE_REQUIRED |
| price-apply-journal | Price_Apply_Journal | PUBLIC_FREE_REQUIRED |
| price-cache-verifier | Price_Cache_Verifier | PUBLIC_FREE_REQUIRED |
| price-apply-transaction-guard | Price_Apply_Transaction_Guard (interface) | PUBLIC_FREE_REQUIRED |
| woo-price-mutator | Woo_Price_Mutator | PUBLIC_FREE_REQUIRED |
| job-state | Job_Error, Job_State, Job_Item_State, Job_Reason | PUBLIC_FREE_REQUIRED |
| job-schema | Job_Schema | PUBLIC_FREE_REQUIRED |
| job-transaction-fence | Job_Transaction_Fence | PUBLIC_FREE_REQUIRED |
| job-repository | Job_Repository | PUBLIC_FREE_REQUIRED |
| job-scheduler | Job_Scheduler | PUBLIC_FREE_REQUIRED |
| job-worker | Job_Worker | PUBLIC_FREE_REQUIRED |
| job-resume-rest | Job_Resume_Rest | PUBLIC_FREE_REQUIRED |
| undo-state | Undo_Error, Undo_State, Undo_Item_State, Undo_Reason | PUBLIC_FREE_REQUIRED |
| undo-schema | Undo_Schema | PUBLIC_FREE_REQUIRED |
| undo-fingerprint | Undo_Fingerprint | PUBLIC_FREE_REQUIRED |
| undo-transaction-fence | Undo_Transaction_Fence | PUBLIC_FREE_REQUIRED |
| woo-undo-mutator | Woo_Undo_Mutator | PUBLIC_FREE_REQUIRED |
| undo-repository | Undo_Repository | PUBLIC_FREE_REQUIRED |
| undo-scheduler | Undo_Scheduler | PUBLIC_FREE_REQUIRED |
| undo-worker | Undo_Worker | PUBLIC_FREE_REQUIRED |
| undo-rest | Undo_Rest | PUBLIC_FREE_REQUIRED |
| free-admin | Free_Admin | PUBLIC_FREE_REQUIRED |
| product-discovery | Product_Discovery; bounded authenticated read-only name/SKU/category discovery | PUBLIC_FREE_REQUIRED |

## Hooks, routes, UI, command and state inventory

| Element | Trace / boundary | Classification |
|---|---|---|
| activation/deactivation callbacks | Plugin → Lifecycle; runner/reconcile only | PUBLIC_FREE_REQUIRED |
| admin_menu → Free_Admin::menu | Products → Bulk Prices (dependency notice fallback only) | PUBLIC_FREE_REQUIRED |
| admin_post_writeleash_free_preview/approve/resume/undo | Free_Admin authenticated action-bound handlers | PUBLIC_FREE_REQUIRED |
| rest_api_init → Job_Resume_Rest / Undo_Rest | POST writeleash/v1/jobs/{UUID}/resume, undo/{UUID}/start | PUBLIC_FREE_REQUIRED |
| writeleash_process_job, writeleash_process_undo, writeleash_retention_purge | Job/Undo workers and bounded retention; owned AS groups | PUBLIC_FREE_REQUIRED |
| writeleash_job_limits, writeleash_undo_limits, writeleash_history_retention_days | Free bounded worker/retention filters, not legacy interception | PUBLIC_FREE_REQUIRED |
| writeleash_job_checkpoint, writeleash_price_apply_checkpoint, writeleash_undo_checkpoint, writeleash_undo_initiate_checkpoint, writeleash_purge_checkpoint | Free observational/race-test checkpoints; no historical handler registered | PUBLIC_FREE_REQUIRED |
| rest_dispatch_request → Redirection_Bulk_Disable_Rest::dispatch | Claims POST /redirection/v1/bulk/redirect/disable global unfiltered Disable, even when legacy readiness refuses | REPOSITORY_ONLY_HISTORICAL |
| query → Guard_Monitor::observe | Registered temporarily by Guard; no general Free interception | REPOSITORY_ONLY_HISTORICAL |
| admin_menu → Admin_Page::menu | Tools → WriteLeash Advanced, slug writeleash | REPOSITORY_ONLY_HISTORICAL |
| admin_post_writeleash_action | Admin_Page budget/enable/disable/demo handler; not just menu visibility | REPOSITORY_ONLY_HISTORICAL |
| wp writeleash status/doctor/demo | Plugin base registered Product_CLI; absent entirely in public boot | REPOSITORY_ONLY_HISTORICAL |
| writeleash_certified_operation_state | Operation_Config enable and logical budget; never read in Free | REPOSITORY_ONLY_HISTORICAL |
| writeleash_last_certified_outcome | Last_Outcome information only; never read in Free | REPOSITORY_ONLY_HISTORICAL |
| writeleash_operation_budget_redirection_5_5_2_bulk_disable | Stale budget, no authority since #78 | REPOSITORY_ONLY_HISTORICAL |
| writeleash_notice_* transients | Legacy Admin feedback only | REPOSITORY_ONLY_HISTORICAL |
| writeleash_version, writeleash_runner_state | Free lifecycle metadata and durable fail-closed runner row | PUBLIC_FREE_REQUIRED |
| writeleash_job_schema/setup, writeleash_undo_schema/setup | Free owned schema/setup metadata | PUBLIC_FREE_REQUIRED |
| Free preview/action notice transients | Free_Admin bounded ephemeral UI, not mutation truth | PUBLIC_FREE_REQUIRED |
| WRITELEASH_VERSION, WRITELEASH_PLUGIN_FILE | Public bootstrap identity/lifecycle hooks | PUBLIC_FREE_REQUIRED |
| WRITELEASH_DB_USER/PASSWORD/NAME/HOST | Historical config snippet / restricted account; HOST is account host, runtime uses normal DB_HOST connection host | REPOSITORY_ONLY_HISTORICAL |
| WRITELEASH_PROVISION_MODE, WRITELEASH_INSTALLER_DB_USER/PASSWORD/CONNECT_HOST, WRITELEASH_RUNTIME_DB_USER/ACCOUNT_HOST/PASSWORD | Operator script environment, not public configuration | REPOSITORY_ONLY_HISTORICAL |
| Redirection 5.5.2, Red_Item::set_status_all, redirection_items, Redirection_Api_Redirect::route_bulk | Legacy exact descriptor/adapter and route callback certification | REPOSITORY_ONLY_HISTORICAL |
| restricted wpdb, SELECT/UPDATE + reviewed EXECUTE grants, trusted DEFINER, writeleash_v01_state, writeleash_v01_open/close/count/policy/attest routines, writeleash_v01_{policy-hash-prefix} trigger, P=2000 | Update_Engine and Doctor attestation; independent of ordinary Woo CRUD and Free tables | REPOSITORY_ONLY_HISTORICAL |
| Doctor READY / exact legacy WP matrix / grants and drain checks | Certified_Operation_Status enable/mutation gating; no Free activation/use dependency | REPOSITORY_ONLY_HISTORICAL |
| Provisioning_Plan install/add_target/remove_target/rotate_credential/uninstall/drain | Explicit trusted installer, can create/drop helper/account/trigger/routines; no WordPress public entrypoint | REPOSITORY_ONLY_HISTORICAL |
| Disposable_Demo_Setup install/cleanup | Trusted owned demo schema and policy; not Free setup | REPOSITORY_ONLY_HISTORICAL |
| Free first-use dbDelta schema installation | Normal WP account; durable job/journal/Undo tables, no privileged helper SQL | PUBLIC_FREE_REQUIRED |
| uninstall exact deletion of three legacy writeleash option names above and four commitcap_* names | Backward local cleanup only: literals deleted, never read/migrated; no legacy class/include/callback/SQL authority | PUBLIC_FREE_REQUIRED |
| uninstall runner-first shutdown, in-flight fence, owned AS group cancellation, retained journal/job/Undo evidence | #109/#110 implementation/assertions preserved; no price write or privileged DDL | PUBLIC_FREE_REQUIRED |

## Documentation and regression evidence

| Element | Boundary | Classification |
|---|---|---|
| README.md (product-facing), RESEARCH.md and ADMIN-CLI.md, CANDIDATES.md, DEMO.md, DOCTOR.md, ENGINE.md, GUARD.md, OPERATION.md, PROVISIONING.md, REDIRECTION.md, RELEASE-MATRIX.md, THREAT-MODEL.md, LICENSE-AUDIT.md under plugin source | Repository product/research documentation; excluded from allowlist | REPOSITORY_ONLY_HISTORICAL |
| FREE-PRICE-CONTRACT.md, FREE-PRICE-APPLY-CONTRACT.md, FREE-JOB-ENGINE-CONTRACT.md, FREE-UNDO-HISTORY-CONTRACT.md under plugin source | Repository engineering contracts/evidence for the public Woo domain; not user package files | REPOSITORY_ONLY_HISTORICAL |
| Root docs/, experiments/, sql/, tests/ and historical plugin research source/tests | Engineering evidence only; no public manifest path | REPOSITORY_ONLY_HISTORICAL |
| Historical Redirection/operator/Doctor instructions formerly in readme.txt | Removed from packaged readme; original remains in git history and research docs/operator-setup.txt | REMOVE_FROM_PUBLIC_PACKAGE |
| wordpress/tests/legacy/ loader and staging mechanism | Explicit repository-only lab, never public staging or installed product authority | REPOSITORY_ONLY_HISTORICAL |
| PUBLIC-PAYLOAD.md (this inventory) | Repository-only boundary evidence | REPOSITORY_ONLY_HISTORICAL |

## Gates and preserved contracts

The public manifest is a sorted explicit allowlist. Preflight computes the
literal bootstrap/include closure plus standalone uninstall, checks all entries
exist and audits code identifiers and same-namespace class dependencies. New
source PHP is neither auto-published nor allowed to hide a missing dependency.
Staging validates the gate before copying and checks exact staged membership.
Public smoke uses only this staging path. Historical lab staging separately
adds the explicitly inventoried legacy classes and test-only loader; it cannot
be selected through any public plugin option/constant/REST/Admin request.

Support stays #112: NEW job max 100, selector max 1000, Woo 11.1.2 exactly,
grandfathered >100 recovery, multisite unsupported; no journal redesign.
Metadata mismatch/full public copy belong to #121. No ZIP/SVN or publication.
#61 barrier, overlap and durability assertions are unchanged; failed attempts
must be retained and rerun at the same unchanged SHA.

The public selection assets are `includes/free/free-selection.js`, a scoped Woo selectWoo progressive enhancement, and `includes/free/free-selection.css`, confined to the WriteLeash wrapper for form grouping, spacing, table scrolling, long-name wrapping and keyboard focus. `includes/free/admin-logo.png` is the supplied WriteLeash logo displayed beside the Admin heading. Native selection works without JavaScript; no bundled libraries/build output are shipped.

| includes/free/free-progress.js | Read-only saved Apply and Undo polling client (#204) | PUBLIC_FREE_REQUIRED |
