# PR #219 round-3 fix receipts (issue #206)

- Clone: `/tmp/writeleash-approved-20261008/agent-A`, branch `work/206-selection`
- Base: `c48e307efe7750d0fb36015c39d50a34675f9579`
- Previous head: `eadd0c9505e31ee3de7197936bc617ebf5eb2d2d`
- Fix commit: `5cf39c1027c41a8d02a7725deffc34d2e93f63c2` — `fix(selection): guarantee complete bounded discovery or fail closed (#206)`
- Docs commit / new head: `eaad5dd0b1703997667474edb55f928fd2e75d0d` — `docs(review): record #206 fail-closed completeness receipts`
- Push: `eadd0c9..eaad5dd work/206-selection -> work/206-selection` (fast-forward, no force)
- PR #219: OPEN, Draft, head `eaad5dd`, description replaced (round-3 body, 5609 chars)

## Fail-closed completeness matrix (selection-count.php stub, all PASS)

| Window | Duplicates | Tamper | Outcome | Assertion |
|---|---|---|---|---|
| short | yes | no | accept, dedupe exact, one read per distinct product | `short duplicate raw page ...`, `injected duplicate rows on a short page ...` |
| full | yes | no | refuse `selection_limit_exceeded` before any Woo read (`reads = 0`) | `2,501 duplicate raw rows ...`, `stripped DISTINCT full duplicate window ...`, `injected full-window duplicate rows ...` |
| short | no | no | accept (existing 2,000-row and small-population cases) | unchanged suite |
| full | no | no | raw sentinel: accept at 2,000 distinct, refuse at 2,001 distinct, reads = 0 | `2,000 distinct raw rows ...`, `2,001 distinct raw rows refuse ...` |
| any | any | yes (`posts_per_page != 2001`) | refuse before any Woo read | `rewritten bounded window refuses before any Woo read` |

Plus: token scoping (nested query receives no DISTINCT, discovery query does), guard removed after
normal/short/refused/exceptional exits, completeness refusal before any Woo read, 1,000/1,001 final
targets, overlapping membership, count/Preview divergence, old-plan hydration.

## Results

- `selection-count.php` PASS 46 -> 63 assertions (`fix3-selection-count.log`)
- Negative control vs round-2 bytes (`eadd0c95`): FAIL as expected at the 2,501 full-window duplicate expectation (`fix3-negative-prev.log`)
- `unit.php` PASS 2,607; `sale-price.php` PASS 105; `variations.php` PASS 73 (stub gained `query_vars`/`get()`)
- admin-audit, no-replan, ownership `--audit`, package-preflight, inventory/public/readme/historical/claim-matrix PASS
- lint + `git diff --check` PASS
- GitNexus: pre-edit impacts LOW (`bounded_discovery_query` exact; `resolve` lower-bound, receiverTyping 3, confirmed by text search); `detect-changes --scope all` 4 files/16 symbols/6 processes/high and `--scope staged` both complete, no partial/truncated
- `pr-fast.sh` local: FAIL only at unchanged #170 `asset-audit.php` (`UI source differs from reviewed capture`)
- CI run 37732527033 at `eaad5dd`: PR_FAST FAIL only at #170; CI_COVERAGE FAIL only downstream (`AssertionError: PR_FAST failed or was cancelled`); all other jobs skipping
- Docker/browser: NOT_TESTED (no `/var/run/docker.sock`); runtime fixtures unrun
