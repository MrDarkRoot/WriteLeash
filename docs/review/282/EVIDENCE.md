# #282 evidence

One lab run on 2026-10-14. This is not an accessibility certification, WCAG conformance claim, or universal compatibility claim.

Lab: `http://127.0.0.1:18092` (Docker project `writeleash_woo112`, Server 29.5.2). Source build `df8d804842425bdcf92c4b8eb5df8004314420c6`, which contains merged #281.

## Environment

| Field | Value |
| --- | --- |
| Date | 2026-10-14T16:05:00Z |
| OS | Linux 6.18.7-76061807-generic |
| Browser + version | Google Chrome 148.0.7778.178 for keyboard, overflow, and no-JS. Firefox 151.0 was launched for the Orca attempt and did not attach. |
| AT name + version | Orca 49.4, AT-SPI2 2.58.1. Speech dispatcher initialized. The WriteLeash page was not spoken. |
| Plugin ZIP path | `/tmp/opencode/writeleash-df8d804.zip` (source tree copied into the lab image) |
| ZIP SHA-256 | `b026d7bac6638011d86230ce38f4155a22044f9b898b288680c9c8fb8396bb55` |
| Source commit | `df8d804842425bdcf92c4b8eb5df8004314420c6` |
| #281 commit | `4df31051950bcf8e8738fd2df52d17344f7c37fe` (merged in `df8d804`) |

Flow exercised: name search, manual product IDs, sale and regular Preview, Approve, Resume on a queued job, History, eligible Undo on the completed cap job, conflict row with `Newer price kept`. Preview CSV was present. Individual exclusions were not opened, so that row is `NOT_TESTED`.

Keyboard events were Playwright `Tab` / `Enter` in Chrome, not a separate physical-keyboard session. That is the keyboard pass recorded here.

## Checkpoints

| checkpoint | method | viewport or AT | result | evidence pointer | defect issue | notes |
| --- | --- | --- | --- | --- | --- | --- |
| K1 logical focus order | keyboard-only | Chrome 148, 1440 | PASS | lab session | none | Tab reached selection method, amount, and preset name. |
| K2 visible focus | keyboard-only | Chrome 148 | PASS | 24/25 sampled stops had outline or box-shadow | none | One unnamed stop had neither. Not filed; element was not identified. |
| K3 no traps | keyboard-only | Chrome 148 | PASS | Shift+Tab left the selection method | none | |
| K4 focus after removing a selected product | keyboard-only | Chrome 148 | FAIL | Harbor Canvas Cap chip | https://github.com/MrDarkRoot/WriteLeash/issues/288 | Focus landed on `body`. |
| K5 disclosure controls | keyboard-only | Chrome 148 | PASS | How it works, search fallback, safety limits | none | Enter opened each `details`. |
| K6 table scroll region | keyboard-only | Chrome 148 | PASS | History `aria-label=Job history` tabindex 0 | none | Region accepted focus. |
| K7 notices and primary actions | keyboard-only | Chrome 148 | PASS | Resume remaining products focused | none | Queued job `dcbd3329-a8d2-4f80-b2ad-75682af142b5`. |
| K8 remove and Undo names | keyboard-only | Chrome 148 | PASS | `Remove Harbor Canvas Cap · SKU: WL-CAP · ID: 11` | none | Ineligible Undo on the conflict job is text, not a silent button. |
| S1 page heading and landmarks | screen reader | Firefox 151 / Orca 49.4 | NOT_TESTED | Orca debug log | none | Orca started and speech dispatcher initialized. `orca -l` did not list Firefox. AT-SPI socket `/run/user/1000/at-spi2-12KZW3/socket` was missing. Page was not spoken. Not a pass. |
| S2 form labels and descriptions | screen reader | Firefox 151 / Orca 49.4 | NOT_TESTED | same attempt | none | Not spoken. |
| S3 selection count announcements | screen reader | Firefox 151 / Orca 49.4 | NOT_TESTED | same attempt | none | Not spoken. |
| S4 table headers | screen reader | Firefox 151 / Orca 49.4 | NOT_TESTED | same attempt | none | Not spoken. |
| S5 status changes | screen reader | Firefox 151 / Orca 49.4 | NOT_TESTED | same attempt | none | #281 copy was visible in Chrome. It was not heard. |
| S6 progress updates | screen reader | Firefox 151 / Orca 49.4 | NOT_TESTED | same attempt | none | Not spoken. |
| S7 notices | screen reader | Firefox 151 / Orca 49.4 | NOT_TESTED | same attempt | none | Not spoken. |
| S8 remove control names | screen reader | Firefox 151 / Orca 49.4 | NOT_TESTED | same attempt | none | Keyboard name was checked in K8. Orca did not speak it. |
| R1 layout 1440 | responsive | 1440 CSS px | PASS | history page scrollWidth delta 0 | none | |
| R2 layout 1024 | responsive | 1024 CSS px | PASS | history page scrollWidth delta 0 | none | |
| R3 layout 782 | responsive | 782 CSS px | PASS | history page scrollWidth delta 0 | none | |
| R4 layout 375 | responsive | 375 CSS px | PASS | history page scrollWidth delta 0 | none | |
| R5 zoom 200% | zoom | browser zoom | NOT_TESTED | — | none | CSS zoom was not used as a substitute. Browser 200% was not driven. |
| R6 no page-level horizontal overflow | responsive | R1–R4 | PASS | deltas above | none | R5 not included. |
| N1 selection without JS | no-JS | JS disabled | PASS | manual IDs form submitted | none | |
| N2 Preview without JS | no-JS | JS disabled | PASS | Preview ready / Review price change | none | |
| N3 controls do not advertise unavailable behavior | no-JS | JS disabled | PASS | no "Automatic updates active" | none | |
| N4 exclusions and Preview CSV without JS | no-JS | JS disabled | PASS | Download Preview CSV present | none | Individual exclusion panel was not opened. That half is NOT_TESTED below. |

## Untested combinations

These are `NOT_TESTED`, not green.

| checkpoint | method | viewport or AT | result | evidence pointer | defect issue | notes |
| --- | --- | --- | --- | --- | --- | --- |
| U-NVDA | screen reader | Firefox + NVDA, Windows | NOT_TESTED | — | none | Outside the Orca attempt. |
| U-VO | screen reader | Safari + VoiceOver, macOS | NOT_TESTED | — | none | |
| U-CR-NVDA | screen reader | Chrome + NVDA | NOT_TESTED | — | none | |
| U-CR-VO | screen reader | Chrome + VoiceOver | NOT_TESTED | — | none | |
| U-CR-ORCA | screen reader | Chrome + Orca | NOT_TESTED | — | none | Named slot was Firefox + Orca and was not spoken. |
| U-JAWS | screen reader | any browser + JAWS | NOT_TESTED | — | none | |
| U-MOBILE | screen reader | TalkBack or iOS VoiceOver | NOT_TESTED | — | none | |
| Z375 | zoom | 200% at 375 CSS px | NOT_TESTED | — | none | |
| NJ-RESUME | no-JS | JS disabled, Resume | NOT_TESTED | — | none | |
| NJ-UNDO | no-JS | JS disabled, eligible Undo | NOT_TESTED | — | none | |
| NJ-EXCLUSION | no-JS | individual exclusions panel | NOT_TESTED | — | none | CSV was present. Exclusion checkboxes were not opened. |
| U-FF-ZOOM | zoom | Firefox browser zoom | NOT_TESTED | — | none | |
| U-SAFARI-LAYOUT | responsive | Safari | NOT_TESTED | — | none | |
| U-RTL | locale | right-to-left | NOT_TESTED | — | none | |
| U-CONTRAST | contrast | forced colors or high contrast | NOT_TESTED | — | none | |
| U-MOTION | motion | reduced motion | NOT_TESTED | — | none | |

## Findings

- K4: https://github.com/MrDarkRoot/WriteLeash/issues/288
