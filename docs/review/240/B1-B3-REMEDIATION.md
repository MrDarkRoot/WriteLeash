# PR #253 — B1/B2/B3 Remediation

**Verdict: READY_FOR_INDEPENDENT_REREVIEW**, subject to the final evidence-only
HEAD's exact-SHA CI result in the PR handoff. No merge or publication is authorized.

## Identity and scope

- Previous reviewed HEAD: `5073f20e0b37af6696418082be935543c4693bc3`.
- Builder fix commit: `35e1d14544bdc69ed5b6d08cbd87de63c6025830`.
- Final code/test freeze: `28ae986d72975c25b1ca14923f1e4549449a24b2`.
- Final evidence-only HEAD and its CI URL are recorded in the PR #253 remediation
  comment after that commit is created; a Git commit cannot embed its own hash.
- Selected main/base remains `566a91f3d41e2f7d030e1154478e2f023b2409bf`.
- Production runtime, public metadata, public manifest, eleven assets, historical
  v0.1 builder/evidence and Woo Marketplace tooling are unchanged.

Changed implementation: v0.2 builder; the parent/scale repository-only fixtures;
`test-wordpress-org-v02-regressions.py`; its isolated `fixture-controls-v02.php`
fault doubles; one PR_FAST integration line. Remaining changes are #240 evidence.
No other agent's checkout, site or database was modified. Work was committed in
an isolated detached worktree and pushed as ordinary fast-forwards to the existing
`work/240-wporg-v02-candidate` branch.

## B1 — replacement-independent Git boundary

The builder's private imported historical module now routes **every Git call**
through the v0.2 `git()` boundary, including inherited `ORG.blob` tree-mode/blob
reads. Both `git --no-replace-objects` and a per-subprocess
`GIT_NO_REPLACE_OBJECTS=1` environment disable replacement resolution. Directory,
index and object-store environment overrides are removed so `-C` selects the
specified repository. Raw repository-root, commit-object type, exact HEAD, clean
tree and committed/local bytes remain required, with final HEAD/clean checks.
The historical on-disk builder is unmodified.

`test_b1_replace_tree_cannot_change_reviewed_bytes` invokes the actual builder,
first proves a clean build, then installs the reviewer's replacement tree in a
disposable independent clone. Ordinary Git sees changed bootstrap bytes under the
unchanged SHA and clean status. The vulnerable builder accepts those bytes; the
corrected builder rejects the raw-object dirty checkout and leaves no artifact.
The control would also accept only a byte-identical genuine build, never altered
replacement bytes. All packaging reads use the same controlled Git boundary.

## B2 — same-variation observer authority

Each field's fixture now uses the **same variation ID** for successful worker
Apply, successful worker Undo, draft-parent Apply refusal and reparented Undo
refusal. Successful worker calls require strictly positive variation-specific
counter deltas; seed, parent, unrelated-product and external reparenting saves
cannot satisfy these checks. Both positive controls precede the zero-write
refusals. Durable outcomes, fresh Regular/Sale values, preserved parent identity
and the successful parent lookup/range control are asserted.

`test_b2_variation_blind_observer_is_rejected` executes the actual fixture against
fault doubles that suppress variation events while preserving parent events and
issuing redundant saves on refused operations. The previous fixture prints PASS;
the corrected fixture fails at `variation Apply save observer`. A fault-free
positive-control invocation must separately pass.

## B3 — values and exact eligible population

The scale fixture checks actual initial 110/110 values, post-Apply 100/100 and
post-Undo 110/110; all 998 unchanged selected prices remain 100. A 120-priced
1,001st sentinel is explicitly outside the frozen 1,000-target selection and
remains 120 with no product saves. It would change if accidentally included.
Exactly the first 1,000 IDs must appear in frozen selection; exactly the two
changing IDs must appear in Apply journal and Undo membership. Journal values and
states, eligible UNDONE states and positive save deltas are checked. No other ID
may appear in the observed Apply/Undo writes. The 1,001-selection refusal must
preserve both prices and save counts. Unchanged/unselected targets are ineligible
for mutation/Undo here; this fixture does not claim a new unsupported-product
type or explicit UI row-exclusion matrix.

`test_b3_false_undone_outcomes_are_rejected` uses an Undo double that reports two
UNDONE outcomes while leaving both prices at 100. The previous fixture prints
PASS; the corrected fixture fails its actual restored-price assertion, despite
`undone === 2`. A genuine-restoration positive control separately passes.

## Discrimination and reproducible commands

The three negative controls **fail against the vulnerable implementation**, while
both fault-free controls pass. Against the final implementation all five tests
pass: negative-test PASS means the manipulated subject was rejected.

```sh
# Execute from the final code checkout; paths/outputs must be new/disposable.
git worktree add --detach /tmp/wl253-old 5073f20e0b37af6696418082be935543c4693bc3
PYTHONDONTWRITEBYTECODE=1 python3 wordpress/release/test-wordpress-org-v02-regressions.py --subject /tmp/wl253-old
# Expected nonzero: exactly the B1, B2 and B3 regression assertions fail.
PYTHONDONTWRITEBYTECODE=1 python3 wordpress/release/test-wordpress-org-v02-regressions.py
# Expected zero: 3 rejection controls + 2 fault-free controls.
PYTHONDONTWRITEBYTECODE=1 bash .github/ci/pr-fast.sh
```

Durable logs: [final vulnerable control](logs/b1-b3-code-vulnerable-control.txt),
[final corrected controls](logs/b1-b3-code-regressions.txt),
[complete local PR_FAST](logs/b1-b3-pr-fast-complete.txt). The initial before/after
receipts remain as separate files. PR_FAST now executes the new five-test suite,
alongside the historical ten-test artifact regression and existing public,
metadata, asset and adversarial package gates.

## Deterministic artifact and SVN closure

[ARTIFACT-B1-B3.json](ARTIFACT-B1-B3.json) records both clean independent builds
at the final code freeze, full per-file/asset inventories, builder digest, tool
versions and exact commands. Both ZIPs and the previous candidate are identical:

```text
A SHA-256: 50901f2c034c705dff355427fe496090d1e4968f0c299853b1b9a4da46655757
B SHA-256: 50901f2c034c705dff355427fe496090d1e4968f0c299853b1b9a4da46655757
Previous:  50901f2c034c705dff355427fe496090d1e4968f0c299853b1b9a4da46655757
ZIP size: 2,151,312 bytes; 46 members under one writeleash/ root
Manifest: 5155ca3121772cac6397dcd4f8b90b5cb726beb14f29b0cde74ae89ab321bca3
Runtime/trunk/tag: a1459fe64ebc0d808bddfe2cd335e7d957fb4f771a34a38a62f9799e34db8fbc
```

The normal builder retains sorted ZIP_STORED members, fixed timestamp/permissions
and exact source/local equality. Both builds run public closure, inventory,
source, readme and asset audits. Source selection = public manifest = ZIP = local
SVN trunk = tag byte-for-byte; eleven top-level assets remain separate. All eleven
existing corruption controls pass again ([log](logs/b1-b3-code-corruption.txt)).
No SVN network write or publication operation is part of these commands.

```sh
git worktree add --detach /tmp/wl253-a 28ae986d72975c25b1ca14923f1e4549449a24b2
git worktree add --detach /tmp/wl253-b 28ae986d72975c25b1ca14923f1e4549449a24b2
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wl253-a/wordpress/release/build-wordpress-org-v02.py --source /tmp/wl253-a --sha 28ae986d72975c25b1ca14923f1e4549449a24b2 --output /tmp/wl253-build-a
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wl253-b/wordpress/release/build-wordpress-org-v02.py --source /tmp/wl253-b --sha 28ae986d72975c25b1ca14923f1e4549449a24b2 --output /tmp/wl253-build-b
cmp /tmp/wl253-build-a/writeleash-0.2.0.zip /tmp/wl253-build-b/writeleash-0.2.0.zip
PYTHONDONTWRITEBYTECODE=1 python3 /tmp/wl253-a/wordpress/release/test-wordpress-org-v02.py /tmp/wl253-build-a
```

## Fresh installed-artifact targeted execution

New task-owned MySQL 8.0.44 and MariaDB 10.11.15 data directories/sites ran
WordPress 7.1.2, WooCommerce 11.1.2 and PHP 8.2.32. Each ran both fixtures under
default cache and Redis 7.4.2 / Redis Object Cache 2.7.0 / Predis. **Eight focused
fixture executions passed**. Docker daemon was unavailable; retained binaries and
pinned archives were reused read-only with fresh task-owned data directories and
ports. No browser or broad unrelated compatibility matrix was rerun.

[RUN-LEDGER-B1-B3.json](RUN-LEDGER-B1-B3.json) retains executable commands,
timestamps, exit codes, raw-log hashes and exact fixture hashes. Tests ran during
preparation of the final code commit; the recorded fixture hashes match that
commit. [INSTALLED-HASHES-B1-B3.json](INSTALLED-HASHES-B1-B3.json) retains actual
46-path installed hash sets for both sites, independently equal to the old and
new ZIP payload. Normal WP-CLI ZIP replacement and Core activation were used;
source staging did not replace the installed plugin. The installed archive was
the first corrected-builder output at `35e1d14…`; subsequent code-freeze builds
are proven byte-identical, rather than relabeling the earlier install command.

On a disposable WordPress/Woo site with the exact ZIP installed:

```sh
wp --path=<disposable-site> plugin install <exact-zip> --force
wp --path=<disposable-site> eval 'require_once ABSPATH."wp-admin/includes/plugin.php"; $r=activate_plugin("writeleash/writeleash.php","",false); if(is_wp_error($r)){throw new RuntimeException($r->get_error_message());}'
wp --path=<disposable-site> eval-file wordpress/tests/release/parent-state-v02.php
wp --path=<disposable-site> eval-file wordpress/tests/release/scale-v02.php
# Repeat with a separately configured persistent object cache; destructive fixtures.
```

Two intermediate fixture failures are retained, not suppressed:

1. Parent fixture used literal CONFLICT instead of durable Undo_Item_State::CONFLICT
   (UNDO_CONFLICT). The plugin correctly refused with PRODUCT_TYPE_CHANGED; the
   fixture/double were corrected to the actual state contract.
2. The stronger journal assertion compared textual target 100 to canonical
   100.00. It now validates through the existing Price_Decimal parser; no numeric
   expectation was weakened. The changed sentinel and journal/value assertions
   then passed on all four profiles.

An incorrectly transcribed non-existent source SHA was also safely rejected
before creating output ([receipt](logs/b1-b3-unresolvable-sha-attempt.txt)).

## CI, graph review and remaining publication checks

Final code-SHA workflow: [38203522929](https://github.com/MrDarkRoot/WriteLeash/actions/runs/38203522929),
HEAD `28ae986d72975c25b1ca14923f1e4549449a24b2`. See
[CI-B1-B3-CODE.json](CI-B1-B3-CODE.json) for exact per-job receipts. PR_FAST and
CI_COVERAGE succeeded; all thirteen downstream families were SKIPPED. Coverage
means **zero selected integrations**, not full integration execution. The eight
local installed-fixture receipts above are distinct evidence. Exact final
evidence-only HEAD CI is linked in the subsequent PR handoff, not guessed here.

GitNexus was refreshed with PDG using runner identity schema 4, current status and
empty incompleteReasons. Impact before edits reported LOW for the builder;
dynamic fixture/file edges were UNKNOWN and confirmed by source/text invocation
inspection. Compare change analysis reported CRITICAL aggregate risk with 23
affected flows after a clean rebuild, with partial=false/truncated=false. These
reported links include name-fallback edges from production-named test doubles
(parse/read/is_terminal/items_table/etc.) into apparent runtime flows. The
actual harness is invoked only in an isolated PHP test process; no public include
references it, literal public closure excludes it, production diff is empty, and
the installed exact-ZIP fixtures use real classes. The warning was investigated,
not used as a claim of zero impact. Process extraction still has bounded PHP
resolution/tracing coverage. Original historical builder regression passed.

**Inherited solely through proven identical artifact bytes:** prior browser,
official installed Plugin Check/readme, historical installed upgrade, dependency
and lifecycle results remain original reported receipts. They were not rerun or
promoted to newly executed PASS. Weak old parent/scale assertions are superseded
by the new eight real-DB checks, not rescued by payload identity. The existing
nonblocking review follow-ups for other old run commands/receipts remain open.

**Remaining NOT_TESTED/publication gates:** advertised additional WP/PHP profiles,
new full process-kill/connection-loss matrix, manual screen reader, public update
API/distribution path, private #133 readiness and explicit Founder GO on an
accepted resulting SHA/artifact. No production defect requiring runtime changes
was demonstrated. No release credentials, public metadata or Woo package changed;
remote SVN writes NONE; #240 remains open; WordPress.org publication NOT AUTHORIZED.
