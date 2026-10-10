# #231 — Individual exclusions before approval

## Baseline and review boundary

- Fetched initial `origin/main`: **da84e64886773a07694448a3d156e64f18489f3b**.
- Expected baseline `3e90043a4225f1f413721e94c4f6cbf78a370c64` was superseded by the merged #233 Preview CSV PR (#238).
- Branch: `work/231-preview-exclusions`; isolated worktree `/tmp/opencode/writeleash-231`.
- Exact final HEAD is the Draft PR's `headRefOid` and the linked final CI run's `headSha`; use `git rev-parse HEAD` in this checkout. A commit cannot contain its own eventual SHA. The final handoff records the literal SHA and CI conclusions.
- Reviewed issue #231, selector and current tests, immutable Plan construction/hydration/hashing, job import/approval, Free Admin forms, category scope (#206), conflict recovery (#205), sale operations (#207), Price Endings (#208), and variation Apply/Undo parent protections (#229).
- No Agent A worktree was edited. #233 already merged into the fetched baseline; its unchanged export reads this feature's final included Plan.

## Reused functionality

`Product_Price_Selector::resolve()` remains the bounded population authority, including core-variable expansion, deduplication, category descendants, fresh Woo snapshots and 1,000/1,001 refusal. `Woo_Price_Planner::preview()`, `Change_Plan::create()`, `Change_Plan_Item::compute()`, policy evaluation, repository persistence/approval, Journal, Jobs, Apply and guarded Undo remain the pricing pipeline.

The new `Selection_Refinement` is selection confirmation, not a mutation engine. It resolves the **whole source selection before applying exclusions**, verifies exact candidate membership and identity against the original reviewed candidate Plan, reads fresh prices/eligibility/permissions, then constructs the new Plan using only supported included snapshots. A second bounded resolution detects drift during confirmation. Unsupported/refused candidates remain provenance, never executable items.

## State and immutable approval

No temporary-state database, transient or client-side membership store was added. Existing authenticated saved Preview jobs are sufficient: every Exclude, Restore or Confirm submission creates a separate immutable unapproved Plan/job. Links across pages carry the current saved review ID, preserving previously confirmed exclusions. Reload reads the same saved membership. Unsubmitted checkboxes are action intent; the UI explicitly requires submission before navigating.

Parent-expanded initial new Previews record optional hashed `requested_selection` so the selected parent context is not lost. Ordinary IDS/SKU/category selections already retain their source context and keep their established material/fingerprint without redundant metadata. Final new Plans record optional hashed `selection_refinement` version 1:

- original authorized candidate job reference;
- exact bounded candidate IDs;
- explicit deduplicated excluded IDs;
- refused IDs, disjoint from excluded and included IDs;
- original source selection including descendant scope.

Final `resolved_product_ids` and `items` contain exactly the included supported targets, with normal frozen expected/planned prices, operation, policy, store and product/parent identity. Hydration verifies the candidate/included/excluded/refused partition. Old material is never augmented on hydration: old serialized bytes and hashes remain valid.

Refinement only accepts unapproved authorized Preview sources, excludes #205 recovery Plans, and never carries approval authority. The final Plan has a new identity and approval nonce. Apply/Undo never read exclusion POST data or rerun source selection.

All-excluded/no-supported-target submissions are explicitly refused (`no_included_targets`), with zero new Plan and zero price writes. The last reviewed state remains visible, with Restore and Cancel actions. This avoids introducing an executable empty Plan contract.

## UX and counts

Preview → **Exclude individual products** → native paginated rows → **Exclude selected rows** / **Restore selected rows** / **Confirm selection with no additional changes** → **Review updated Preview** → separate **Approve and apply**.

Confirmed excluded rows remain checked and visibly labelled across reload/pagination. Unchecking alone does not restore; use the explicit Restore action. Cancel discards unsubmitted checkbox intent and returns to the saved review. Counts come from immutable Plan/provenance: resolved candidates, included, excluded, changing included, unchanged included and unsupported/refused. Hidden pagination rows remain included unless explicitly excluded.

## Validation and evidence

### Local focused checks — PASS

- `php wordpress/tests/free/exclusions.php`: exact 350 → 343 IDs, duplicate/zero/all exclusions, forged IDs, permissions, removed identities, fresh prices, ordinary post-Preview conflict, 1,000/1,001 refusal, parent-expanded/direct variations, invalid/reparented parent, supported-only population, all accepted operations and sale blank/zero semantics.
- `php wordpress/tests/free/exclusions-category.php`: existing #206 bounded discovery harness plus both descendant scopes and retained provenance.
- Existing unit/calculation, #207/#208 operations, #205 recovery provenance, #233 frozen CSV and #204 progress/security harnesses passed.
- Existing Admin static boundary audit passed: no Admin price writes/arithmetic/catalog query, existing engine authority retained.
- PR_FAST and immutable workflow syntax validation passed locally. The #170 source fingerprint was refreshed using its established documented contract; capture/observer assertions were not changed. Official WP-CLI 2.12.0 regenerated the POT; catalog/localized presentation/old-Plan checks passed.
- New real integration fixture and browser script syntax checked.

These PHP stub tests are domain evidence, **not actual Woo/MySQL/browser execution evidence**.

### Hosted validation — pending

Local Docker is unavailable (`/var/run/docker.sock` absent). Live database/cache/browser claims depend on hosted final-HEAD CI.

The Admin matrix runs `exclusions-integration.php` on MySQL 8.0.44 and MariaDB 10.11.15 with default and Redis cache. It asserts actual 350-category/descendant candidates → 343 final targets → 343 successful Apply outcomes → 343 normal Undo outcomes. For each excluded ID, independent Woo save hooks, price reads and Journal SQL assert **zero Woo saves and zero Apply/Undo journal records**. It also exercises exact variations, parent publication/reparenting, removed products, real 1,000/1,001 category bounds, all pricing operations and separate approval.

The existing disposable browser runner adds an independent JavaScript-disabled Chromium/Firefox keyboard journey using exact product IDs and semantic operation identifiers. It checks pagination/reload, explicit exclusion membership, Cancel, zero saves before approval, no refinement-page Apply button and separate final approval. Prior browser assertions are preserved.

Required gates selected by the existing owner of `class-change-plan.php`: PR_FAST, Plan, Journal, Jobs, Undo, Admin, Acceptance and CI_COVERAGE. SHA-bound acceptance budget authorization must match exact final HEAD; skipped/cancelled jobs are not passes. Final CI URLs/conclusions will be recorded in the PR handoff.

Initial hosted run [38035975128](https://github.com/MrDarkRoot/WriteLeash/actions/runs/38035975128) at implementation SHA `0aac03d8fcb04ff92705286a1766e94081befb92` exposed the classifier's intentional deferred acceptance rule (`ownership.py` requires direct acceptance-fixture changes). A focused native-HTTP acceptance fixture was therefore wired into the existing journey to validate final membership on every selected platform profile. No CI condition or assertion was weakened. The superseded opening run was cancelled by normal concurrency and is not counted as a pass.

That run also failed Journal's existing concurrent same-material fingerprint assertion because initial provenance was attached unnecessarily to ordinary selections. The planner was corrected to attach requested-selection context only when resolution changed it (parent expansion); the original Journal assertion remains unchanged. A focused domain regression now compares the ordinary planner fingerprint with its established factory material. This failed run is not acceptance evidence.

## File inventory

- `wordpress/writeleash/includes/free/class-change-plan.php`: optional provenance, hydration partition checks, selection confirmation.
- `wordpress/writeleash/includes/free/class-free-admin.php`: authenticated native refinement action/views/counts.
- `wordpress/tests/free/exclusions.php`, `exclusions-category.php`, `in-container.sh`: focused domain and category coverage/wiring.
- `wordpress/tests/admin/exclusions-integration.php`, `integration.php`: live matrix and existing harness wiring.
- `wordpress/tests/admin/exclusions-browser.cjs`, `selection-browser-run.sh`: independent no-JS Chromium/Firefox journey.
- `wordpress/tests/acceptance/exclusions.php`, `journey.php`: exact included population/no-write confirmation over native authenticated HTTP across selected platform profiles.
- `docs/review/231/IMPLEMENTATION.md`: this evidence.
- `docs/review/170/proof.json`, `README.md`: established current-source fingerprint refresh, historical screenshots/observer assertions unchanged.
- `wordpress/writeleash/languages/writeleash.pot`: generated catalog for new native UI messages.

## Limitations and review status

- Selection drift refuses confirmation and asks for a new initial Preview; it never silently adds/removes unseen targets.
- Refinement requires the original candidate Preview to remain available and unapproved. This uses normal job retention rather than a permanent cross-job exclusion database.
- All-excluded selections are refused rather than saved as empty executable Plans.
- Real MySQL/MariaDB, Redis, browser and final-HEAD CI conclusions are pending. **HOLD** until required hosted evidence passes.
- No merge, release, issue closure, Marketplace submission or candidate ZIP changes. Founder retains GO/NO-GO.
