# #282 manual accessibility and responsive QA protocol

Repeatable manual pass for the supported Free admin workflow:

Selection → Preview → exclusions/CSV where available → Approve → Apply/Resume → Results/History → eligible Undo.

This document is the procedure. Results go in [EVIDENCE.md](EVIDENCE.md). Filling that file is a separate run. This commit does not execute it.

## Non-claims

This protocol does not certify accessibility, WCAG conformance, or universal compatibility. A filled row is one environment, one artifact, one checkpoint. Automated axe or keyboard assertions from #170 or Admin CI are not this pass and must not be copied in as PASS.

Do not write "accessibility certified", "WCAG compliant", "works with all screen readers", or "works at all sizes".

## #281 blocks the final keyboard and screen-reader run

Issue #281 changes partial-outcome copy on Results and History (merchant explanations, counts, and safe next steps for applied, unchanged, skipped, conflict, failed, and needs-review). Those strings are what keyboard focus order, notices, status text, and screen-reader announcements expose.

The final keyboard-only run and the required screen-reader run **must be executed on the branch that contains #281**, after this work is rebased onto that commit. They must **not** be executed against this pre-#281 SHA, and they must **not** be marked PASS here.

This protocol was written on `test/282-a11y` at `867c04bb065a31a6718cafeb9431501231279013`, which does not contain #281. In EVIDENCE.md those checkpoints stay `NOT_RUN` / blocked on #281 until that rebase. Leave `#281 commit` blank until then.

Responsive layout and the no-JS Selection/Preview path are specified below. They are not signed off by this commit. Results/History wrapping that depends on #281 copy must be rechecked on the #281 branch before any PASS on those views.

## What to record before any checkpoint

Record these in the EVIDENCE.md environment block. Leave a field blank rather than guessing.

| Field | Rule |
| --- | --- |
| Date | UTC date of the run |
| OS | Name and version |
| Browser + version | Exact binary, not "latest" |
| AT name + version | Required for the screen-reader slot; blank on non-AT rows is not a pass |
| Plugin ZIP path | The ZIP actually installed, not a source tree |
| ZIP SHA-256 | `sha256sum` of that ZIP |
| Source commit | Commit that produced the ZIP, after the #281 rebase |
| #281 commit | The merged or contained #281 SHA. Blank until rebase |

Also record the tested flow (which selector, price field, whether exclusions and Preview CSV were offered, whether Resume or only Apply was available, whether Undo was eligible). Untested combinations are listed, not implied green.

A finding is a FAIL. Every FAIL needs a defect issue URL in the defect column. `none` is allowed only when no defect was filed. `none` on a `NOT_RUN` or `NOT_TESTED` row means "not executed", not "no defect". A FAIL with `none` is an invalid record.

## Fixture

Use a disposable store with a backup. Do not use production.

Minimum catalog for one pass:

- At least two selectable products so one can be removed.
- A selection that reaches Preview, including a variation if the store has one.
- Exclusions offered on that Preview (refinement view). If the control is absent, mark exclusion rows `NOT_TESTED` and say why. Do not invent a pass.
- Preview CSV offered (`Download Preview CSV`). Same rule if absent.
- An approved job that shows Apply progress, and a paused or waiting job that shows `Resume remaining products` if one can be produced without inventing a failure. If Resume never appears, mark Resume rows `NOT_TESTED`.
- One job whose Undo control is actually eligible (`Restore eligible prices (Undo)` or `Continue Undo`). Confirm an ineligible job does not present Undo as available.

Presets, Repeat, and recovery are adjacent. Cover them only when they appear on the path you took. Do not treat them as the core flow.

Operator: keyboard and screen reader use a physical keyboard. Do not click to recover focus. Pointer is allowed only for the responsive/zoom pass, and only to set viewport or zoom.

## Result vocabulary

| Result | Meaning |
| --- | --- |
| PASS | Executed on the recorded artifact and met the checkpoint. Forbidden for keyboard and screen reader until the #281 branch is what was installed. |
| FAIL | Executed and missed the checkpoint. Defect issue URL required. |
| NOT_RUN | Required for the eventual record, not executed. Keyboard and screen reader on this SHA. |
| NOT_TESTED | Outside the minimum pass, or the control was not offered. Must be listed. Not a pass. |

## Keyboard-only

Blocked on #281. Do not run this section against `867c04bb`. When run, use only Tab, Shift+Tab, Enter, Space, arrows, Escape, and Home/End where the control is a listbox or scroll region. One browser is enough for the minimum pass; name it. Other browsers stay `NOT_TESTED`.

| ID | Checkpoint | Pass condition |
| --- | --- | --- |
| K1 | Logical focus order | From the WriteLeash page, Tab reaches selection method, product or category choice, price change, `Preview price changes`, then on Preview the summary, exclusion entry if present, `Download Preview CSV` if present, both `Approve and apply` controls (top and bottom; either submits the same plan), then progress/`Refresh saved progress`, `Resume remaining products` when shown, History (`Job history` region and row actions), then eligible Undo. Order follows the visual workflow, not a random jump into the admin menu and back. |
| K2 | Visible focus | Every stop in K1 shows a visible focus indicator against the admin background, including chips, disclosures, table regions, notices' actions, and primary buttons. |
| K3 | No traps | Shift+Tab leaves every widget. Select2/listbox closes with Escape and does not trap. `details`/`summary` does not trap. A focused table region scrolls but is not a cage. No control requires a pointer to escape. |
| K4 | Focus after removing a selected product | Activate `Remove {product}` on a selected chip. Focus lands on a logical control (search field or a remaining chip), not `body` and not lost. Repeat for the no-JS chip submit if that control is the one in the tab order with JS off (that half is N3, not a second keyboard pass). |
| K5 | Disclosure controls | `How it works`, `Search products without live suggestions`, `Search categories without live suggestions`, `Exact SKU or manual product IDs`, `Safety limits (optional)`, and support `Details` open and close from the keyboard and expose their contents next in tab order. |
| K6 | Table scroll region | Preview and results region named `Product prices and outcomes`, and History region named `Job history`, are in tab order (`tabindex="0"`). Arrow/keyboard scroll stays inside the region. The page does not gain a horizontal scrollbar from the table. |
| K7 | Notices and primary actions | `role="alert"` notices are readable without a trap. Primary actions stay in tab order and activate with Enter or Space: `Preview price changes`, `Approve and apply`, `Resume remaining products` when shown, eligible Undo. Disabled pager buttons expose `aria-disabled="true"` and do not activate. |
| K8 | Remove and Undo names | Keyboard-reachable remove controls expose a name that includes the product, not an unlabeled icon. Eligible Undo's accessible name matches the visible label. Ineligible Undo is not a silent button. |

## Screen reader

One screen-reader pass is required eventually. It is not this commit.

Required slot (name only; version and run stay blank until the #281 branch):

| Slot field | Named value |
| --- | --- |
| Browser | Firefox |
| OS | Linux |
| AT name | Orca |
| AT version | blank until the run |
| Run | NOT_RUN / blocked on #281 |

If the operator substitutes another AT for that one required pass, replace the slot in EVIDENCE.md and keep every other combination in the untested list. Do not mark the named slot PASS by substitution.

Speak the whole core flow. Listen; do not grade the accessibility tree alone.

| ID | Checkpoint | Listen for |
| --- | --- | --- |
| S1 | Page heading and landmarks | Heading "WriteLeash Bulk Prices" is the page heading. WordPress admin landmarks (banner, navigation, main) still wrap the page. Record extra plugin headings (`New price change`, `Review price change`, `Exclude individual products`, `Progress and results`, `Undo`). A missing plugin-owned landmark is a note, not an automatic fail, if the admin main landmark contains the workflow. |
| S2 | Form labels and descriptions | Selection method, products, category, price field, ending, range, and safety fields announce their labels. Descriptions tied with `aria-describedby` are read (selector help, product help, range help, ending help). |
| S3 | Selection count announcements | After `Check selection count`, `#writeleash-free-selection-count` (`role="status"`, `aria-live="polite"`) is announced. The count is informational; it must not be announced as an applied price change. |
| S4 | Table headers | Preview, refinement, results, and history cells are announced with their column headers (`scope="col"`). Refinement checkboxes include `Select product #{id}`. |
| S5 | Status changes | Job status text and item outcomes are announced when the view updates after Approve, Resume, Refresh, or Undo. Partial outcomes use the #281 copy. Do not accept pre-#281 wording as the final listen. |
| S6 | Progress updates | Text counters are the source of truth. The polite live region (`data-progress-announcement`) announces progress. The progressbar name is `Apply progress` (or the Undo bar's own label) with min/now/max. A silent bar is not a fail if the live region spoke the same update. A bar that speaks a different number than the text is a fail. |
| S7 | Notices | Error, warning, and success notices are announced or are reachable immediately after the action that created them, including Preview-ready, approval, scheduler-unavailable, and stalled-resume notices. |
| S8 | Remove control names | Each remove control's accessible name is `Remove {product description}`, including the product, not "button" alone. |

## Responsive and zoom

Viewports are CSS pixels. Set them in the browser device toolbar or by resizing the content area, and record which. 200% is browser zoom, not a narrow window pretending to be zoom.

| ID | Viewport or zoom | Checkpoint |
| --- | --- | --- |
| R1 | 1440 | Full core flow fits. No page-level horizontal overflow. Tables that overflow scroll inside their region. |
| R2 | 1024 | Same. |
| R3 | 782 | Same. Admin menu collapse is WordPress, not a WriteLeash fail, unless it hides a WriteLeash primary action with no way to reach it. |
| R4 | 375 | Same. Chips, disclosures, and primary buttons remain operable. Table scroll stays in the region. |
| R5 | 200% zoom | At a desktop window (record outer width). No page-level horizontal overflow except the contained table region. Text does not clip primary actions. |
| R6 | overflow rule | On every R1–R5 stop, the document scrolls vertically only, unless the focused table region is the scroller. WP admin bar overflow that exists with WriteLeash absent is a note, not a product fail. |

Results/History at these sizes must be repeated after #281 if the partial-outcome copy changes wrapping. Do not mark R1–R6 PASS in this pre-#281 template.

200% zoom at 375 is not R5. It is an untested combination (`Z375`).

## No-JS

Disable JavaScript in the browser, reload, and confirm the network panel or the browser setting. Do not use a user-agent trick.

Promised path: native Selection and Preview. The form posts `selection_action` and `Preview price changes` without a script. Fallbacks `Search products without live suggestions` and `Search categories without live suggestions` are the path when live suggestions are absent. Range and Clear remain ordinary fields.

| ID | Checkpoint | Pass condition |
| --- | --- | --- |
| N1 | Selection without JS | Method, native product or category controls, SKU or manual IDs, safety limits, and `Check selection count` submit and redisplay. Live-suggestion widgets must not be the only way to select. |
| N2 | Preview without JS | `Preview price changes` reaches `Review price change`. No prices change. Notices do not claim a script ran. |
| N3 | No false availability | Progress text still says automatic updates require JavaScript and that Refresh reads saved progress. No control claims live suggestions, polling, or automatic Apply without JS. Undo is not labeled as a universal rollback. |
| N4 | Exclusions and Preview CSV without JS | If those controls are shown, they are native forms and work, or they are absent. Do not show a control that only works with JS without saying so. |

`no-JS Resume` is **not** part of the minimum promised path for this issue. Resume is a native POST, but this protocol does not treat that as a test. Record it as `NOT_TESTED` (`NJ-RESUME`) unless a later row is filled from a real disabled-JS Resume. Same for no-JS eligible Undo (`NJ-UNDO`) and no-JS progress polling (`NJ-POLL`). Polling is explicitly not promised; Refresh is the no-JS progress path and belongs to N3, not to a polling pass.

## Untested combinations

List these in EVIDENCE.md as `NOT_TESTED` until a row is filled from a real run. Do not omit them.

| ID | Combination |
| --- | --- |
| U-NVDA | Firefox + NVDA on Windows |
| U-VO | Safari + VoiceOver on macOS |
| U-CR-NVDA | Chromium or Chrome + NVDA |
| U-CR-VO | Chromium or Chrome + VoiceOver |
| U-CR-ORCA | Chromium or Chrome + Orca |
| U-JAWS | Any browser + JAWS |
| U-MOBILE | TalkBack or iOS VoiceOver |
| Z375 | 200% zoom at a 375 CSS px viewport |
| NJ-RESUME | No-JS Resume |
| NJ-UNDO | No-JS eligible Undo |
| NJ-POLL | No-JS automatic progress polling |
| U-FF-ZOOM | Firefox actual browser zoom, if the zoom pass was Chromium-only |
| U-SAFARI-LAYOUT | Safari responsive layout |
| U-RTL | Right-to-left locale |
| U-CONTRAST | Forced colors or Windows high contrast |
| U-MOTION | Reduced motion |

The named Orca slot is `NOT_RUN`, not `NOT_TESTED`. It is required later. The rows above are outside that one pass.

## Evidence pointer

Point each executed row at a note, screenshot path, or recording under `docs/review/282/`. Do not link a #170 capture as evidence for this pass. `NOT_RUN` and `NOT_TESTED` pointers stay `—`.

## Order of operations when #281 is on the branch

1. Rebase or otherwise contain the #281 commit. Fill `#281 commit` and `source commit`. Install that ZIP. Record SHA-256.
2. Run keyboard K1–K8 in the named browser.
3. Run the one screen-reader pass in the named slot (Firefox, Linux, Orca, version filled).
4. Run R1–R6 and N1–N4 on that same artifact if they were not already run on a post-#281 build. Recheck Results/History after #281 even if an earlier layout pass existed.
5. Leave the untested table as `NOT_TESTED` unless that combination was actually run.
6. File a defect issue for every FAIL before recording it.
