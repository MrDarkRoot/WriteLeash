# Cost-bounded CI and public-fork policy (#133)

## Three layers

1. **PR_FAST** (`pr-fast.yml` → job/check `PR_FAST`): every PR, one 5-minute-limit
   job; actual runtime is measured separately. Network-free package/public-runtime
   closure, inventory, negative audit cases, readme, conditional claim matrix,
   staged source audit, all source/fixture PHP lint, tracked directory image
   format/size/filename dimensions (if present), dependency and ownership
   policy audit. A separate step verifies/downloads pinned actionlint for full
   workflow syntax validation. No database, Redis, 10k fixture or Docker build.
2. **CODE_INTEGRATION**: the same PR workflow calls only the changed paths' owning
   reusable suites after PR_FAST succeeds. `CI_COVERAGE` is a second cheap stable
   required check which propagates *every selected owner's* failure/cancellation/
    skip, including the direct acceptance owner. Same-repository PRs execute selected owners; fork code never schedules
   expensive suites automatically. Fork runtime PRs fail CI_COVERAGE until the
   maintainer promotes the audited exact SHA to a same-repository PR branch.
3. **RELEASE_FULL** (`release-full.yml`): explicit dispatch only. Input `sha` must
   be 40 lowercase hex characters **and equal github.sha for the selected ref**.
   Branch motion before dispatch causes rejection before any heavy suite starts.
   All 13 original workflows are called from the same commit, and every checkout
   uses that exact SHA. Preflight + 20 expanded original suite jobs = **21 jobs**.
   Acknowledging the unpinned research APK exception is a separate explicit
   boolean, false by default. No dispatch happens merely because docs/assets change.

There are **13 classified original suite files + 2 orchestration files = 15
workflow files**. The original 13 test bodies, timeout bounds and matrices remain.
`wordpress-woo-acceptance.yml` is reusable for intentional release evidence and
approved direct #112 harness/workflow changes: all seven profiles on
both engines, PHP 7.4/8.0/8.1/8.2, WP 7.0.1/7.1.2, unsupported-Woo negative,
Redis and size/recovery/lifecycle evidence remain intact.

Pure readme/claim/listing-test paths run exactly **2 executed cheap checks:
PR_FAST and CI_COVERAGE (zero selected integrations)**. The current #121 seven-file
diff also changes production `Requires at least`: it selects **foundation,
adapter and historical** compatibility owners, not native/Redis/10k acceptance.
GitHub can also show skipped reusable call statuses; these are not executed
matrix runners. The initial `bfd93f6` run selected only native, but its classifier
was incomplete. Correct self-ownership now selects all 13 suite definitions in
#135's cumulative diff, including the direct acceptance workflow change. That
PR is blocked until its selected suites actually run with a reviewed budget.
No automatic main-push
workflow duplicates the PR matrix. Exact-main release confidence is established
by intentional RELEASE_FULL, required again for #123–#125 authorization.

## Ownership is executable policy

Authority: [path-ownership.json](path-ownership.json), interpreted by
[ownership.py](ownership.py). PR comparison uses the exact base/head Git diff,
including deletions and both sides of renames, without GitHub's paths-filter
300-file truncation. Production PHP is a finite inventory, not a wildcard:
**a new unmapped production PHP file fails PR_FAST**. Test harness paths must
also be mapped. Broad `wordpress/**` triggers do not remain.

| Production owner group | Mandatory downstream evidence owners |
| --- | --- |
| immutable plan, selector, snapshot, decimal, operation, support/safety/authority | #107, #108, #109, #110, #111; #112 at release |
| journal/apply connection/transaction guard/cache/mutator | #108, #109, #110, #111; #112 at release |
| job repository/schema/state/scheduler/worker/fence/REST | #109, #110, #111; #112 at release |
| Undo fingerprint/repository/schema/state/scheduler/worker/fence/REST/mutator | #110, #111; #112 at release |
| Free Admin | #111; #112 at release |
| shared public bootstrap/environment/lifecycle/plugin/uninstall | all Woo owners plus historical/foundation/engine/research/adapter |
| only Requires at least / Requires PHP header change, otherwise identical body | foundation + adapter + historical: public activation refusal/dependencies and historical staged Core activation paths |
| repository-only historical PHP | engine/foundation/research/adapter/historical matrix |
| readme, listing/claim docs, claim/readme/package audit PHP, directory assets | PR_FAST only; no integration |
| individual runtime fixture directories | their exact consumers (e.g. jobs/no-replan audit also consumed by Admin) |
| native/SQL/demo/CLI or MySQL experiment paths | native or feasibility/engine/historical respectively |
| each of the 13 reusable suite workflow definitions | that exact suite, including acceptance |
| wordpress/tests/acceptance/** (including evidence documents) | direct acceptance, consumed by CI_COVERAGE; never silently deferred |

The JSON contains exact shared staging, Dockerfile, root fixture, legacy overlay,
and debug-audit consumers. Generic static release audit PHP is cheap-only;
release/run.sh and Plugin Check parser belong to the historical matrix.
There is **no generic .github/workflows/** cheap exemption. Unknown workflow
definitions fail classification. Every reusable suite definition owns itself.
Only these orchestration/tooling exceptions stay static, with maintainer review:

- `pr-fast.yml`: PR_FAST audits every suite call, output, budget/fork condition
  and coverage dependency; actionlint validates the executable job graph.
- `release-full.yml`: PR_FAST audits all 13 calls and the exact-SHA guard;
  actionlint validates the graph. This does not exempt its called suite definitions.
- `.github/ci/**`: these static policy/audit tools run inside PR_FAST, including
  adversarial classifier, budget and coverage cases. `assert-security-log.sh`
  retains its explicit additional native owner. Changes require maintainer review.

Reproduce listing classification and local gates:

```sh
python3 .github/ci/ownership.py --audit
python3 .github/ci/ownership.py wordpress/writeleash/readme.txt wordpress/release/CLAIM-MATRIX.md wordpress/tests/release/claim-matrix-audit.php
bash .github/ci/pr-fast.sh
bash .github/ci/workflow-lint.sh
```

The deterministic ownership audit verifies listing-only cases, downstream plan/
job/Undo/Admin coverage, new-PHP refusal, all original reusable suite calls and
the dispatch SHA guard. A first-comment-only change to the finite listing header
fields (**Plugin Name, Description, Tested up to only**) stays cheap: the PHP
body must be byte-identical. Comment-termination/code-injection negative cases
are enforced. Requires Plugins, Version and executable changes are not exempt.
`Requires at least` and `Requires PHP` are **runtime compatibility requirements**,
never cheap listing fields. Header-only changes select foundation, Redirection
adapter and historical release matrix, which exercise Core activation and
historical staged plugin activation. Engine/research fixtures do not normally
activate WriteLeash through Core; prose does not justify running them or PG/Redis.
Other header/body changes retain the full production bootstrap ownership.
An exact allowlisted claim-audit invocation added to release/run.sh also stays
cheap when every other byte is identical; modifying/removing runtime test
commands still select the historical owner. The actual seven-path #121 diff
from `22a89ec` to `56e90cc` selects **adapter, foundation, historical**. The prior
zero-owner statement at `bfd93f6` was incorrect and is superseded by this repair.
Claim audit is conditional **only while absent on main**;
once #121 introduces it, PR_FAST runs it with the repository-root argument.

### Direct #112 ownership and budget approval (conditional execution)

Runtime production owners retain #112 in `RELEASE_FULL_OWNERS`, explicitly shown
as `DEFERRED_RELEASE_OWNERS` on ordinary runtime PRs. Only **direct** changes to
`.github/workflows/wordpress-woo-acceptance.yml` or `wordpress/tests/acceptance/**`
select automatic-graph `acceptance`. PR_FAST exports that owner, calls its real
seven-profile reusable suite, and CI_COVERAGE consumes its result. Missing owner
outputs/results, skipped, failed or cancelled acceptance never yield green.

Before spending #112 minutes, a maintainer reviews the exact head and budget,
then applies label **`ci112:<40-character-head-sha>`** to the same-repository PR.
Label addition/removal re-evaluates the PR graph. Approval for another SHA or a
fork never authorizes expensive execution. Direct #112 PRs pause **all selected
expensive suites** while approval is absent, preventing a partial costly wave
that still cannot merge. CI_COVERAGE **fails**, rather than treating those
selected-but-skipped owners as successful. Once approved, every selected suite
runs and CI_COVERAGE requires all of them to succeed. Ordinary non-#112 code PRs
continue to run their selected integrations automatically.

No approval label is supplied by this repair and no RELEASE_FULL is dispatched
to manufacture green. In particular, #135's prior 13 workflow edits now own all
13 suites; its final CI can finish **blocked for missing exact-SHA budget/evidence**.
That is an enforced release requirement, not a claim that the skipped suite passed.

An intentional release dispatch after this workflow is on default main:

```sh
gh workflow run release-full.yml --ref <reviewed-ref> -f sha=<exact-40-character-sha>
```

By default the documented unpinned research APK toolchain blocks preflight.
Either lock it first or explicitly add
`-f acknowledge_research_toolchain=true` after reviewing that exception.
Record run ID, attempt, actual SHA, all 21 results and measured versions.
The full hosted matrix was **not executed for #133** because quota is near its
limit. Composition is statically checked; run the full exact-SHA matrix at the
next budgeted release gate. This is not a green full-matrix claim.

## Cancellation and retention

Normal PR workflow group: `pr-checks-<PR number or ref>`, cancel-in-progress true.
The cancellation covers PR_FAST, selected reusable integrations and coverage;
superseded PR commits stop spending minutes. RELEASE_FULL has **no concurrency
cancellation**, so separate exact-SHA attempts cannot replace one another.

Ordinary integration uploads: 7d → **3d** on PRs, **30d** on intentional release.
Acceptance: **30d**, release or approved direct #112 change. Adapter/historical matrix: **30d** in both
layers, preserving #61 attempts. Feasibility: no artifacts. PR_FAST: no artifact
upload; ordinary logs remain under repository Actions settings. Changing YAML
does not purge or shorten existing uploads. Do not delete runs/artifacts until a
reviewed sanitized durable replacement preserves the release decision.

#61's trusted barrier, two restricted sessions, actual concurrent UPDATE overlap,
durable accounting and no-stale-success assertions are unchanged. A failed
intentional run remains failed-attempt evidence; a budgeted retry must use the
**unchanged exact SHA**, never assertion/timing relaxation.

## Public fork policy and exact settings review

All jobs have `contents: read`; no release secret, PAT, SVN credential, OIDC token
or `secrets: inherit`. No pull_request_target, workflow_run privileged consumer,
untrusted self-hosted runner or writable cache. Third-party Actions use immutable
SHAs; checkout never persists credentials. Ordinary PRs do not publish/release.

**MANUAL SETTINGS REVIEW REQUIRED before public visibility:**

1. Settings → Actions → General → Fork pull request workflows: require approval
   for **all outside collaborators**. Keep fork write tokens/secrets disabled;
   never grant approval to a moving, unaudited expensive ref. Verify how the
   setting applies after visibility changes.
2. Workflow permissions: **Read repository contents**; leave "Allow GitHub
   Actions to create and approve pull requests" unchecked. Current API confirms
   read permissions and review approval disabled.
3. Allowed Actions: enable immutable SHA requirement if available; committed
   policy already rejects mutable Actions. Review allowed organizations/actions.
4. Settings → Secrets and variables → Actions: confirm recorded zero repository
   names; Settings → Environments: confirm zero, and review any later environment
   names/secrets/protection. Inventory **names/usage only**, not values.
5. Settings → Deploy keys / Webhooks: confirm recorded zero. Settings →
   Integrations → GitHub Apps (and account installed/authorized Apps): check
   every repository selection, permissions and external release integration;
   the API audit could not establish these.
6. Privately review account PAT inventory and external release scripts/password
   managers for release PAT/SVN credentials; no such consumer exists in workflow
   source. Before #126, put required publication credentials in a separately
   protected maintainer environment/process, never PR or reusable test inputs.
   Remove unused keys; rotate/revoke any potentially active exposed credential
   before doing anything public.
7. Privately confirm publication consent for the **two non-noreply Git identity
   emails**. Their values are deliberately omitted from this audit.
8. Review Dependabot/dependency graph, secret scanning/push protection and code
   scanning availability before public; do not assume currently enabled.

For untrusted runtime contributions, maintainers inspect the diff, SHA and cost,
then promote that reviewed commit to a same-repository PR branch for its owners.
Do not execute privileged fork code through pull_request_target. Documentation
forks can pass both cheap checks after the configured GitHub approval.

## Main required checks

Current API: main protected, enforce_admins true, force pushes/deletions disabled,
required checks unset and no rulesets. After the new PR's checks are observed:

Settings → Branches → existing main rule → "Require status checks to pass before
merging": select **PR_FAST** and **CI_COVERAGE**, both from **GitHub Actions**.
Require the branch to be up to date. Preserve enforce_admins/no force pushes/no
deletions. Require pull requests and maintainer review for CI/ownership changes.
Record the final rule and verify it before public visibility. These exact names
are stable; do not require the flaky #61 adapter as an every-commit check.
CI_COVERAGE makes all selected runtime owners mandatory without requiring them
on documentation-only PRs. No repository protection setting is overwritten by
this implementation; configure these checks after observed stability.

After any later authorized visibility change, re-read visibility, main protection/
rulesets/required checks, fork approvals, workflow token settings, secret/App
inventory, Actions retention and public security features. This issue performs
**no visibility change**, merge or WordPress.org publication.
