# #282 evidence template

Template only. No checkpoint in this file was executed. No PASS is recorded.

This branch contains #281 (`4df31051950bcf8e8738fd2df52d17344f7c37fe`, PR #285). On 2026-10-14 the final keyboard and screen-reader pass was not executed: no disposable WordPress/Woo site (`docker info` failed) and no assistive-technology session. K1–K8 and S1–S8 stay `NOT_RUN`. That is not a pass. Do not mark them pass against pre-#281 SHA `867c04bb065a31a6718cafeb9431501231279013` either.

This file does not certify accessibility, WCAG conformance, or universal compatibility.

Procedure: [PROTOCOL.md](PROTOCOL.md).

## Environment

Leave blank until a real run. Do not copy #170 or CI versions into these fields.

| Field | Value |
| --- | --- |
| Date | 2026-10-14 attempt only; no run |
| OS | Linux (host). Not a tested admin session. |
| Browser + version | not launched against the plugin |
| AT name + version | not launched |
| Plugin ZIP path | not built for this pass |
| ZIP SHA-256 | not recorded; no tested artifact |
| Source commit | branch contains `4df31051950bcf8e8738fd2df52d17344f7c37fe` |
| #281 commit | `4df31051950bcf8e8738fd2df52d17344f7c37fe` |

Required screen-reader slot (named, run unfilled):

| Slot field | Value |
| --- | --- |
| Browser | Firefox |
| OS | Linux |
| AT name | Orca |
| AT version | |
| Run | NOT_RUN after #281 landed; no disposable site and no AT session |

Tested flow (fill on the run): selector, price field, exclusions offered yes/no, Preview CSV offered yes/no, Apply/Resume which, Undo eligible yes/no.

## Checkpoints

`none` in the defect column means no defect issue was filed. On a `NOT_RUN` or `NOT_TESTED` row that is not a pass and not a clean bill of health.

| checkpoint | method | viewport or AT | result | evidence pointer | defect issue | notes |
| --- | --- | --- | --- | --- | --- | --- |
| K1 logical focus order | keyboard-only | | NOT_RUN | — | none | NOT_RUN after #281; no disposable site. Do not run on pre-#281 SHA 867c04bb. |
| K2 visible focus | keyboard-only | | NOT_RUN | — | none | NOT_RUN after #281; no disposable site. Not a pass. |
| K3 no traps | keyboard-only | | NOT_RUN | — | none | NOT_RUN after #281; no disposable site. Not a pass. |
| K4 focus after removing a selected product | keyboard-only | | NOT_RUN | — | none | NOT_RUN after #281; no disposable site. Not a pass. |
| K5 disclosure controls | keyboard-only | | NOT_RUN | — | none | NOT_RUN after #281; no disposable site. Not a pass. |
| K6 table scroll region | keyboard-only | | NOT_RUN | — | none | NOT_RUN after #281; no disposable site. Not a pass. |
| K7 notices and primary actions | keyboard-only | | NOT_RUN | — | none | NOT_RUN after #281; no disposable site. Not a pass. |
| K8 remove and Undo names | keyboard-only | | NOT_RUN | — | none | NOT_RUN after #281; no disposable site. Not a pass. |
| S1 page heading and landmarks | screen reader | Firefox / Linux / Orca (version blank) | NOT_RUN | — | none | Slot named. Run unfilled. NOT_RUN after #281; no disposable site. |
| S2 form labels and descriptions | screen reader | Firefox / Linux / Orca (version blank) | NOT_RUN | — | none | Slot named. Run unfilled. NOT_RUN after #281; no disposable site. |
| S3 selection count announcements | screen reader | Firefox / Linux / Orca (version blank) | NOT_RUN | — | none | Slot named. Run unfilled. NOT_RUN after #281; no disposable site. |
| S4 table headers | screen reader | Firefox / Linux / Orca (version blank) | NOT_RUN | — | none | Slot named. Run unfilled. NOT_RUN after #281; no disposable site. |
| S5 status changes | screen reader | Firefox / Linux / Orca (version blank) | NOT_RUN | — | none | Must hear #281 partial-outcome copy. Not a pass. |
| S6 progress updates | screen reader | Firefox / Linux / Orca (version blank) | NOT_RUN | — | none | Slot named. Run unfilled. NOT_RUN after #281; no disposable site. |
| S7 notices | screen reader | Firefox / Linux / Orca (version blank) | NOT_RUN | — | none | Slot named. Run unfilled. NOT_RUN after #281; no disposable site. |
| S8 remove control names | screen reader | Firefox / Linux / Orca (version blank) | NOT_RUN | — | none | Slot named. Run unfilled. NOT_RUN after #281; no disposable site. |
| R1 layout 1440 | responsive | 1440 CSS px | NOT_RUN | — | none | NOT_RUN after #281; no disposable site. Not a pass. |
| R2 layout 1024 | responsive | 1024 CSS px | NOT_RUN | — | none | NOT_RUN after #281; no disposable site. Not a pass. |
| R3 layout 782 | responsive | 782 CSS px | NOT_RUN | — | none | NOT_RUN after #281; no disposable site. Not a pass. |
| R4 layout 375 | responsive | 375 CSS px | NOT_RUN | — | none | NOT_RUN after #281; no disposable site. Not a pass. |
| R5 zoom 200% | zoom | 200% browser zoom | NOT_RUN | — | none | Not executed. Not a 375 zoom pass. See Z375. |
| R6 no page-level horizontal overflow | responsive | R1–R5 | NOT_RUN | — | none | Contained table scrolling is the only allowed overflow. Not executed. |
| N1 selection without JS | no-JS | JS disabled | NOT_RUN | — | none | Promised path. Not executed in this commit. |
| N2 Preview without JS | no-JS | JS disabled | NOT_RUN | — | none | Promised path. Not executed in this commit. |
| N3 controls do not advertise unavailable behavior | no-JS | JS disabled | NOT_RUN | — | none | Not executed. Polling must not be advertised as available. |
| N4 exclusions and Preview CSV without JS | no-JS | JS disabled | NOT_RUN | — | none | Only where those controls are shown. Not executed. |

## Untested combinations

These are `NOT_TESTED`, not green. Omitting a row is not coverage.

| checkpoint | method | viewport or AT | result | evidence pointer | defect issue | notes |
| --- | --- | --- | --- | --- | --- | --- |
| U-NVDA | screen reader | Firefox + NVDA, Windows | NOT_TESTED | — | none | Outside the one required Orca slot. |
| U-VO | screen reader | Safari + VoiceOver, macOS | NOT_TESTED | — | none | Outside the one required Orca slot. |
| U-CR-NVDA | screen reader | Chromium or Chrome + NVDA | NOT_TESTED | — | none | |
| U-CR-VO | screen reader | Chromium or Chrome + VoiceOver | NOT_TESTED | — | none | |
| U-CR-ORCA | screen reader | Chromium or Chrome + Orca | NOT_TESTED | — | none | Named slot is Firefox + Orca, still NOT_RUN. |
| U-JAWS | screen reader | any browser + JAWS | NOT_TESTED | — | none | |
| U-MOBILE | screen reader | TalkBack or iOS VoiceOver | NOT_TESTED | — | none | |
| Z375 | zoom | 200% at 375 CSS px | NOT_TESTED | — | none | Not covered by R4 or R5. |
| NJ-RESUME | no-JS | JS disabled, Resume | NOT_TESTED | — | none | Not part of the minimum promised no-JS path. Native POST is not a test. |
| NJ-UNDO | no-JS | JS disabled, eligible Undo | NOT_TESTED | — | none | |
| NJ-POLL | no-JS | automatic progress polling | NOT_TESTED | — | none | Not promised. Refresh is the no-JS progress path (N3). |
| U-FF-ZOOM | zoom | Firefox browser zoom | NOT_TESTED | — | none | Fill only if R5 was not Firefox. |
| U-SAFARI-LAYOUT | responsive | Safari | NOT_TESTED | — | none | |
| U-RTL | locale | right-to-left | NOT_TESTED | — | none | |
| U-CONTRAST | contrast | forced colors or high contrast | NOT_TESTED | — | none | |
| U-MOTION | motion | reduced motion | NOT_TESTED | — | none | |

## Findings

None recorded. A finding requires a defect issue URL. Do not add a "no defects" or certification statement.
