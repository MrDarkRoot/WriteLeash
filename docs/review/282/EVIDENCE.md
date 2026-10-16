# #282 evidence

Follow-up lab run on 2026-10-15. This is not an accessibility certification, WCAG conformance claim, or universal compatibility claim.

Lab: `http://127.0.0.1:18092` (Docker project `writeleash_woo112`, Server 29.5.2). Spoken Orca pass and 200% zoom used the GitHub `main` admin (`df8d804`). K4 was rerun after copying `free-selection.js` from `9402b03` (#288) into that lab.

## Environment

| Field | Value |
| --- | --- |
| Date | 2026-10-15 follow-up; first keyboard pass 2026-10-14 |
| OS | Linux 6.18.7-76061807-generic |
| Browser + version | Firefox 151.0 for Orca. Google Chrome 148.0.7778.178 for keyboard, overflow, no-JS, and 200% zoom. |
| AT name + version | Orca 49.4, AT-SPI2 2.58.1. `orca -l` listed Firefox. Speech log `/tmp/opencode/orca-speak.log`. |
| Plugin ZIP path | `/tmp/opencode/writeleash-df8d804.zip` |
| ZIP SHA-256 | `b026d7bac6638011d86230ce38f4155a22044f9b898b288680c9c8fb8396bb55` |
| Source commit | `9402b030f86b50cb7b8b5e8029c8e5ba23c9f91e` for K4; `df8d804842425bdcf92c4b8eb5df8004314420c6` for the spoken page |
| #281 commit | `4df31051950bcf8e8738fd2df52d17344f7c37fe` |

Flow exercised: name search, manual product IDs, sale and regular Preview, Approve, Resume on a queued job, History, eligible Undo on the completed cap job, conflict row with `Newer price kept`. Preview CSV was present. Individual exclusions were not opened, so that row is `NOT_TESTED`.

Keyboard events were Playwright `Tab` / `Enter` in Chrome, not a separate physical-keyboard session. That is the keyboard pass recorded here.

## Checkpoints

| checkpoint | method | viewport or AT | result | evidence pointer | defect issue | notes |
| --- | --- | --- | --- | --- | --- | --- |
| K1 logical focus order | keyboard-only | Chrome 148, 1440 | PASS | lab session | none | Tab reached selection method, amount, and preset name. |
| K2 visible focus | keyboard-only | Chrome 148 | PASS | 24/25 sampled stops had outline or box-shadow | none | One unnamed stop had neither. Not filed; element was not identified. |
| K3 no traps | keyboard-only | Chrome 148 | PASS | Shift+Tab left the selection method | none | |
| K4 focus after removing a selected product | keyboard-only | Chrome 148, JS from `9402b03` | PASS | lab session after #288 | none | Remaining chip focused `button.writeleash-chip__remove`. Last chip focused `#writeleash-free-product_search`. `activeElement` was not `body`. Scroll did not jump on the click. #288 remains the defect record for the old behavior. |
| K5 disclosure controls | keyboard-only | Chrome 148 | PASS | How it works, search fallback, safety limits | none | Enter opened each `details`. |
| K6 table scroll region | keyboard-only | Chrome 148 | PASS | History `aria-label=Job history` tabindex 0 | none | Region accepted focus. |
| K7 notices and primary actions | keyboard-only | Chrome 148 | PASS | Resume remaining products focused | none | Queued job `dcbd3329-a8d2-4f80-b2ad-75682af142b5`. |
| K8 remove and Undo names | keyboard-only | Chrome 148 | PASS | `Remove Harbor Canvas Cap · SKU: WL-CAP · ID: 11` | none | Ineligible Undo on the conflict job is text, not a silent button. |
| S1 page heading and landmarks | screen reader | Firefox 151 / Orca 49.4 | PASS | `/tmp/opencode/orca-speak.log` 09:30:00 | none | Spoken: `WriteLeash Bulk Prices heading 1.` Also `main content` and `Page has 5 landmarks, 5 headings`. |
| S2 form labels and descriptions | screen reader | Firefox 151 / Orca 49.4 | PASS | same log 09:26:53 | none | Spoken: `Selection method combo box Choose products by name or SKU.` The selector description was spoken in the same utterance. |
| S3 selection count announcements | screen reader | Firefox 151 / Orca 49.4 | PASS | same log 09:30:01 | none | Spoken the live status: `Selection changed. Check selection count again; Preview resolves and freezes the products independently.` Not spoken as an applied price change. |
| S4 table headers | screen reader | Firefox 151 / Orca 49.4 | PASS | same log 09:22:18 and 09:30:04 | none | Spoken: `Job history landmark`, `Task / operator / time column header.`, `Product column header.`, `Apply column header.` |
| S5 status changes | screen reader | Firefox 151 / Orca 49.4 | PASS | same log 09:30:23 and 09:30:25 | none | Spoken: `Finished with products needing attention` and `Newer price kept`. |
| S6 progress updates | screen reader | Firefox 151 / Orca 49.4 | PASS | same log 09:30:24 | none | Spoken: `Apply progress 100 percent.` on a finished 1-of-1 job. Text status was `Finished with products needing attention`. |
| S7 notices | screen reader | Firefox 151 / Orca 49.4 | PASS | same log 09:30:26 and 09:22:20 | none | Spoken the alert: `The price change finished with products needing attention.` Also `Background processing stopped making progress. Use Resume remaining products`. |
| S8 remove control names | screen reader | Firefox 151 / Orca 49.4 | PASS | same log 09:29:44 | none | Spoken: `Remove Harbor Canvas Cap · SKU: WL-CAP · ID: 11 button.` |
| R1 layout 1440 | responsive | 1440 CSS px | PASS | history page scrollWidth delta 0 | none | |
| R2 layout 1024 | responsive | 1024 CSS px | PASS | history page scrollWidth delta 0 | none | |
| R3 layout 782 | responsive | 782 CSS px | PASS | history page scrollWidth delta 0 | none | |
| R4 layout 375 | responsive | 375 CSS px | PASS | history page scrollWidth delta 0 | none | |
| R5 zoom 200% | zoom | Chrome 148, Ctrl+0 then Ctrl+= five times, X11 window | PASS | history page at dpr 2, innerWidth 640, page and table overflow 0 | none | Not CSS zoom. Window was Google Chrome on X11; keys went only after WM_NAME confirmed Chrome. |
| R6 no page-level horizontal overflow | responsive | R1–R5 and Z375 | PASS | deltas above | none | |
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
| U-CR-ORCA | screen reader | Chrome + Orca | NOT_TESTED | — | none | Named slot was Firefox + Orca and was spoken. Chrome+Orca was not run. |
| U-JAWS | screen reader | any browser + JAWS | NOT_TESTED | — | none | |
| U-MOBILE | screen reader | TalkBack or iOS VoiceOver | NOT_TESTED | — | none | |
| Z375 | zoom | 200% at 375 CSS px | PASS | Bulk Prices page, dpr 2, innerWidth 375, page overflow 0 | none | Same Chrome zoom session, window resized to 750px. |
| NJ-RESUME | no-JS | JS disabled, Resume | NOT_TESTED | — | none | |
| NJ-UNDO | no-JS | JS disabled, eligible Undo | NOT_TESTED | — | none | |
| NJ-EXCLUSION | no-JS | individual exclusions panel | NOT_TESTED | — | none | CSV was present. Exclusion checkboxes were not opened. |
| U-FF-ZOOM | zoom | Firefox browser zoom | NOT_TESTED | — | none | |
| U-SAFARI-LAYOUT | responsive | Safari | NOT_TESTED | — | none | |
| U-RTL | locale | right-to-left | NOT_TESTED | — | none | |
| U-CONTRAST | contrast | forced colors or high contrast | NOT_TESTED | — | none | |
| U-MOTION | motion | reduced motion | NOT_TESTED | — | none | |

## Findings

- Earlier K4 fail is fixed on `9402b03` and rerun PASS. Record remains https://github.com/MrDarkRoot/WriteLeash/issues/288 until that PR merges.
