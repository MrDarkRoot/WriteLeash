# PR #220 / issue #209 — round-3 evidence index

- Clone: `/tmp/writeleash-approved-20261008/agent-B`, branch `work/209-public-cache`
- Base: `c48e307efe7750d0fb36015c39d50a34675f9579`
- Round-2 head: `7496874eb0b48989814234afb703d17a5563ace0`
- Round-3 head (pushed fast-forward): `029a08040da0fab06bfea2a8d174aeba3d72f2d4` — `docs(review): record #209 recovery-semantics evidence`
- PR: https://github.com/MrDarkRoot/WriteLeash/pull/220 (Draft; head `029a080`; description updated via REST PATCH — `gh pr edit` fails on Projects-classic GraphQL deprecation)

## Verdict
- **No production code changed in round 3** (docs + durable tests only).
- **No defect found**: none of the stop conditions (blind overwrite, duplicate completed work, uncertain→success, Undo destroying later edits) is violated.
- The transient `unreadable_product_data` → `FAILED` → durable `PENDING` retry **remains a divergence** from the pre-#209 terminal baseline and **still requires explicit founder approval** (matrix section D in `docs/review/209/IMPLEMENTATION.md`).

## Files in this evidence dir
| File | Content |
|---|---|
| `B3-gitnexus-analyze.log` | clone-local `analyze --index-only --force` (adopts foreign storage), runner identity verified |
| `B3-status.log` | status fresh at `7496874` (pre-commit), owned storage |
| `B3-detect-all.json` | detect-changes all: 4 files / 10 symbols / 0 processes / risk low |
| `B3-detect-staged.json` / `B3-detect-staged-final.json` | staged: empty before add; 4 files / 10 symbols after add |
| `B3-detect-postcommit.json` | post-commit all: clean |
| `B3-internal-grep.log` | no `ProductCache`/`Internal`/`wc_get_container`, no `new $`, no global flush, no direct Woo price SQL |
| `B3-docker-probe.log` | `docker info` exit 1, no `/var/run/docker.sock` |
| `B3-lint-new.log` | `php -l` on all touched/related test files |
| `B3-*-baseline.log`, `B3-*-post.log` | battery before/after the round-3 edits (all exit 0) |
| `B3-pr-fast-baseline.log`, `B3-pr-fast-post.log` | PR_FAST fails only at the unchanged #170 UI capture fingerprint (21 PASS) |

## Test deltas
- `wordpress/tests/durable/cache-public-integration.php` — R3 block (+~38 assertions): transient read refusal ⇒ zero saves + durable `PENDING`; retry ⇒ one save/`APPLIED`; concurrent edit between attempts ⇒ `CONFLICT`/zero overwrite.
- `wordpress/tests/jobs/integration.php` — killed worker between checkpoints (`AFTER_WOO_SAVE_BEFORE_JOURNAL`, `AFTER_JOURNAL_BEFORE_COMMIT`) ⇒ durable `PENDING` + one net price change on recovery; transient job-level refusal ⇒ `PENDING`/`FAILED`/`attempt_count=1`/`RETRY_BACKOFF`, retry re-runs precondition (newer edit ⇒ `CONFLICT`) and succeeds once otherwise.
- `wordpress/tests/jobs/worker.php` — test-only public `woocommerce_product_class` fault knob for the transient fixtures.
- Docs: `docs/review/209/IMPLEMENTATION.md` round-3 section (matrix + proofs + receipts + divergence statement).

## NOT_TESTED
Docker daemon unavailable: all `durable/`, `jobs/`, `undo/` runtime assertions (including the new fixtures) are structurally lint-checked only — NOT_TESTED, not PASS.
