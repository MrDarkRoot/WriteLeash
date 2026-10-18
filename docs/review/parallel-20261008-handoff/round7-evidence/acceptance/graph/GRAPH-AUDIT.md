# Round 7 aligned graph snapshot

Stopped at the user's request through the parent auditor. No further graph calls or tests were run after that instruction. This is the completed evidence available at stop time, not a full taint/dependence verdict.

## Exact index

Checkout `/tmp/writeleash-round7-browser-rebuild`, commit `ad6377eaf6b68548221d3e4fae0b522b0e19f7d3`.

Command: `node /home/vanta/Projects/WriteLeash/.gitnexus/run.cjs analyze --pdg --index-only` from that checkout. Actual exit 0; 85.2 seconds; GitNexus 1.6.12; indexed at `2026-10-08T12:08:17.488Z`; 369 files, 17,413 nodes, 48,400 edges, 293 clusters and 478 flows. `index-meta.json` preserves exact commit and analyzer/runtime identity, file hashes and PDG metadata. Index output/error/exit are `index.stdout`, `index.stderr`, `index.exit`. Checkout `git status --short` remained empty after index generation.

## Complete change detection

Comparison base: `c48e307efe7750d0fb36015c39d50a34675f9579`. Exact worktree comparison returned 47 changed files, 410 changed symbols and 85 affected processes. Full MCP arrays contain all 410 symbols and all 85 processes. Neither `partial:true` nor `truncated:true` was returned. Overall `risk_level: critical` is a substantive warning, not an approval or proof of defect.

The complete result is `detect-mcp.json`. The CLI ran with `--scope compare --base-ref c48e307efe7750d0fb36015c39d50a34675f9579 --limit 10000 --repo /tmp/writeleash-round7-browser-rebuild`, exit 0, but its human display summarizes listings; `detect-compare.stdout` is not substituted for the complete MCP payload.

## Completed symbol context and upstream impact

Each completed target is disambiguated to its production file; raw `context-*.json` and `impact-*.json` retain results.

| Symbol | Risk | Direct dependents | Processes affected | Epistemic status |
|---|---|---:|---:|---|
| `Product_Price_Selector.resolve` | HIGH | 8 | 2 | lower-bound; 6 receiver-typing call sites unresolved |
| `Product_Price_Selector.bounded_discovery_query` | HIGH | 1 | 1 | exact within indexed relation model |
| `Product_Price_Snapshot.fresh_product` | CRITICAL | 12 | 11 | exact within indexed relation model |
| `Price_Cache_Verifier.observe` | LOW | 2 | 1 | exact within indexed relation model |
| `Free_Admin.progress_snapshot` | LOW | 4 | 1 | exact within indexed relation model |
| `Free_Admin.handle_progress` | LOW | 1 | 0 | exact within indexed relation model |

Actual AJAX entry is `handle_progress`, not the requested alias `ajax_progress`. The latter is not claimed to have been resolved. The client `action` query, detailed direct-dependent source reconciliation, explain calls and PDG guard/data-flow queries were incomplete when execution was stopped. No taint or PDG PASS is claimed merely because the index includes a PDG layer.

## Graph coverage limitations

- Index initialization respawned two workers after startup crashes; the final indexing command completed successfully. Original warnings are retained.
- Process enumeration explicitly reported missing flows: 308 of 508 candidate entry points dropped before ranking, 1,782 callees skipped by branching bounds and 36 walks cut by per-entry budgets. The reported 478 flows are not the complete universe of execution paths.
- 44 property read/write sites have definition anchors only in another language; per-language inference declined to link them. Empty property results do not mean unused.
- Index metadata records 999 unresolved receiver sites and 2,091 name-fallback PHP resolutions. Context for the production progress method resolves some same-named outgoing repository calls to test-double classes. That is a graph attribution limitation, not evidence that production polling actually invokes a test double.
- `resolve` impact is explicitly a lower bound due to 6 unresolved receiver-typing sites. An absent caller, zero graph result or missing process must not be treated as safety.
- Completion of change listing is distinct from completeness of graph extraction, process enumeration, trust analysis or runtime acceptance. This snapshot closes the earlier baseline-index identity mismatch for `ad6377`; it does not apply automatically to a later source correction.

No production or test code edit, commit or push occurred in this graph lens. Required HIGH/CRITICAL warnings are preserved above. Final acceptance remains HOLD pending the independent audit's incomplete runtime/browser checks and any authentic focus defect reproduction/correction.
