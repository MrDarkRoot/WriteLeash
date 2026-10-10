# #230 — Price operation presets (Free v0.2.0)

Status: Draft implementation; CI pending. Founder retains GO/NO-GO.
Base: `da84e64886773a07694448a3d156e64f18489f3b` (merged #238).
Branch: `work/230-price-presets`. References #230; no automatic issue closure.

## Behavior and security boundary

Native Save as preset, list/load, rename and delete use the existing editable
Woo Admin form. Loading never creates a job, imports a plan, approves or writes
prices. Loaded values can be edited; the existing Preview path independently
resolves current products, eligibility, permissions, arithmetic and the
1,000-target ceiling, then requires an action-bound independent approval.

The version-1 envelope stores only requested selection, operation and safety.
It retains direct/descendant category scope, exact chosen parent/variation IDs
or exact SKU, Regular/Sale field, operation/amount, supported ending and all
safety settings. Clear Sale canonically keeps blank amount/default ending,
consistent with the existing operation validator. Picker IDs reload as visible
editable exact IDs, so missing references cannot silently disappear in discovery.
Deleted category IDs remain visibly selected as unavailable. Missing/unavailable
references get an explanatory notice; current unsupported rows and permission
refusals remain owned by Preview.

`Price_Preset_Configuration` maps to/from the existing server validators; it is
reusable for future configuration prefill. Its nested, versioned selection
allows a future schema extension for exclusions. Schema 1 rejects unknown
metadata, including exclusions. #231 workflow and #232 History Repeat are not
implemented or imported. No new pricing engine or worker changes.

Per-blog non-autoloaded options provide 20 atomic slots per store, shared across
actors. Configuration is bounded to 20,000 bytes, names to 100 UTF-8 bytes with
markup/control characters rejected. Creator IDs come from the authenticated
actor. Creators and administrators (`manage_options` override, matching existing
actor policy) may access records; both Woo mutation capabilities remain mandatory.
Each native action uses its own verb/UUID-bound nonce and POST, with early
`admin_init` PRG before headers. Form/notice recovery is existing session-scoped
transient state. GET and possession of identifiers grant no authority.

Stored records have a blog-bound HMAC using the WP auth salt. Every load validates
integrity, exact envelope/types and configuration through the same validators.
Salt rotation or copying options to a differently salted/blog-ID store invalidates
presets; it never preserves approval. Invalid slots remain counted and are hidden
from listing; explicit loads fail closed. Administrators can remove corrupt option
slots through trusted WordPress maintenance if needed.

Conditional INSERT IGNORE (not the potentially upserting add_option API)
reserves each slot and enforces the store cap under concurrent saves. A real
competing save injected before reservation verifies that neither creator is
overwritten. Conditional
SQL rename/delete compares the complete old serialized record, preventing stale
requests from replacing/deleting a reused slot. Options cache invalidation covers
default and Redis caches. These writes touch only preset options. No prior plan
hash, nonce, snapshot, target price, job state, approval or permission grant is
persisted. Existing jobs, History and Undo retain their current immutable records.

## Scope inventory

- Runtime: `class-price-presets.php`; narrow `class-free-admin.php` integration.
- Localization: official checksum-pinned WP-CLI 2.12.0 generated POT.
- Tests: preset configuration unit matrix, real Admin persistence/security suite,
  existing Chromium enhanced/no-JS merchant journey additions, and preset smoke
  checks in every acceptance profile (including unsupported Woo refusal).
- Wiring: Admin unit/integration runners and acceptance runner; production file
  ownership and both source distribution allowlists/PUBLIC-PAYLOAD inventory.
- #170 source identity refreshed for new Admin controls; historical screenshots
  and result files unchanged; original browser assertions remain enabled.

No pricing/selection engine, job/History/Undo implementation, version/readme,
Marketplace materials, release/candidate ZIPs or checksum receipts are changed.

## Validation

Local PHP 8.3.6: configuration matrix PASS (616 additional assertions; 3,223
including existing Plan unit suite); Preview CSV unit and localized presentation
unit PASS. New runtime and integration PHP lint and browser JS syntax PASS.
PR_FAST, Admin entry audit and worker/Undo no-replan audits PASS locally.
Docker is unavailable locally; no local real-DB/browser PASS is claimed.

Required exact-HEAD hosted gate: PR_FAST, Plan, Journal, Jobs, Undo, Admin,
CI_COVERAGE, and eight acceptance profiles: current-normal-default,
current-constrained-redis, previous-wordpress, previous-woo, php74, php80, php81,
unsupported-woo. Both MySQL and MariaDB run within each acceptance profile;
Admin covers both engines/default/Redis and original browser assertions.

Report PASS, FAIL, SKIPPED and CANCELLED separately in the PR after the final
HEAD completes. The exact-SHA `ci112:<HEAD>` label authorizes this user-requested
matrix under existing CI policy. No READY claim until required jobs succeed and
mergeability is clean.
