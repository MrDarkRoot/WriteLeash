# #232 Repeat a previous price job as a new Preview — implementation

Feature branch `feat/free-232-repeat-job-preview` for issue #232, from
`origin/main` (`778af88`, post-#234/#233/#208).

## Merchant workflow

1. History or job detail shows **Repeat as new Preview** for jobs whose
   stored plan verifies and whose configuration maps onto currently
   supported editable values (plain GET navigation, no mutation).
2. The repeat view shows **Based on previous job …**, the original
   operation description, and explicit not-Undo/Resume/retry copy, with
   every historical setting as an editable suggestion in the established
   Preview form (native, no-JS, keyboard-ordered).
3. Submitting takes the normal Preview pipeline against **current**
   WooCommerce data, freezes a fresh immutable plan with bounded
   `source_job` provenance, and requires a separate approval before Apply.

## Historical-to-editable mapping (`Free_Admin::repeat_form_values`)

Allowed suggestions: requested selection (IDS as editable advanced manual
IDs; SKU exact; category term + nested scope), target price field, operation
type + input amount, optional price ending, enabled price-range state with
inclusive min/max and Regular/Sale basis, safety limits and warning
threshold. Legacy plans without `field`/`ending` keys default exactly like
hydration (regular / default).

Forbidden carry-over (never suggested, never executed): snapshot prices,
planned absolute targets, old approval nonces, plan hashes/bindings, job
state, journal/Undo/resume state, and any standing permission. The frozen
IDS population is the previously *reviewed* product list; the new Preview
revalidates every ID (catalog, rights, types, 1,000 ceiling). Category
selections resolve *current* membership; the view says so explicitly.

Refusals: `repeat_unavailable` (missing/unauthorized/tampered source, no
disclosure), `repeat_unsupported` (unknown selection shape or key —
including any future exclusion metadata this version does not understand —
legacy/unknown operation, field, ending, range or policy shape; never a
silent mutating fallback).

## Authorization boundary

- View and submission both require `manage_woocommerce` + `edit_products`
  and pass through job authorization (`authorized_for_job`: creator,
  approver, or administrator). IDs are identifiers, never tokens.
- Submission is POST-only with the Preview action nonce; the `repeat`
  marker only routes to the repeat branch, where the source is re-verified
  after planning and the reference is provenance only.
- Fresh Preview re-checks the complete current population (rights, types,
  ceiling) via `Woo_Price_Planner::preview`; approval re-verifies binding
  and product rights unchanged from the established flow.
- Original job/plan/journal/Undo records are never written by open, cancel,
  preview, or apply of the new job (asserted byte-identical in tests).

## Coordination with #241 (presets) and #231 (exclusions)

- #241 (`Price_Preset_Configuration`) still open at branch time: #232 keeps
  an independent bounded adapter over verified *plan* material, never preset
  CRUD, and works with zero presets (asserted: no `writeleash_price_preset_*`
  options created). Intended shared contract is `configuration <-> editable
  form`; converge onto #241's shipped mapping when it merges rather than
  keeping a duplicate abstraction. No preset class code was copied.
- #231 merged during review (`464cfd0`; branch rebased): plans may now carry
  `requested_selection` plus `selection_refinement` metadata
  (`candidate_ids` / `excluded_ids` / `refused_ids`). Repeat prefills the
  frozen *included* `selection` only — excluded products stay out by
  construction and can never silently re-enter — and renders an explicit
  notice with the excluded count plus how to add products back deliberately.
  `requested_selection` is never used as a repeat source (re-resolving it
  would broaden beyond the reviewed population). Malformed refinement
  metadata refuses as `repeat_unsupported`. Covered by unit cases and a
  real-Woo refined-source integration case (exclusion frozen, noticed,
  not reintroduced, source preserved across repeat preview and Apply).
  Repeat-created Previews carry `source_job` provenance, so like #205
  recovery Previews they are outside the refine-view scope; narrowing stays
  available directly in the editable repeat form.
- Shared-file overlap (`class-free-admin.php`, `writeleash.pot`,
  `docs/review/170/proof.json` hash-only refresh): no other agent's branch
  touched; rebase coordination via issue #232 comments.

## Files

- `wordpress/writeleash/includes/free/class-free-admin.php`: repeat source
  gate, prefill adapter, repeat view + entry links, repeat preview branch,
  refusal copy, repeat-vs-recovery preview provenance.
- `wordpress/tests/admin/repeat-unit.php` + `run.sh` wiring.
- `wordpress/tests/admin/repeat-integration.php` + `in-container.sh`
  wiring (both cache profiles via the existing loop).
- `wordpress/tests/admin/i18n-catalog.php`: new copy asserted present.
- `wordpress/writeleash/languages/writeleash.pot`: regenerated with the
  pinned WP-CLI 2.12.0 extractor (`ce34dd…5c0a` verified).
- `docs/review/170/proof.json`: `class-free-admin.php` source-hash refresh
  only (actual new bytes); screenshots and capture evidence untouched.

## Verification

- `repeat-unit.php`: adapter mapping + 17 unsupported-shape refusals.
- `repeat-integration.php`: 16 acceptance areas (authorized prefill without
  mutation, cross-actor/anonymous refusal without disclosure, tamper/missing
  refusal, revoked capability, product-rights drift, category drift,
  missing/deleted products, malformed edits with retained form, double +5%
  from current prices with distinct identities, post-preview CONFLICT,
  1,000/1,001 ceiling, no-JS/keyboard form parity, source preservation
  across open/preview/apply, SKU/sale/ending/range round-trip, variation
  identity, presets independence, Undo eligibility preservation, refined
  exclusion-source preservation).
- Full CI (PR_FAST-selected suites incl. admin/acceptance + browser) runs on
  the PR head; Chromium journeys remain the hosted-CI authority.
