# Read-only focus boundary review

Exact reviewed source: PR #221 `fd0dc73e8a1598da1c894a6be24be28f860c286d`, `wordpress/writeleash/includes/free/free-progress.js:17-38`.

The independent browser lane reports a native Chromium probe: a focused button has activeElement BUTTON, then assigning disabled=true synchronously changes activeElement to BODY. That neutral probe is a browser platform observation, not yet an actual WriteLeash terminal-transition receipt. Actual app reproduction remains necessary before this review labels a production defect.

The shipped action path assigns `button.disabled = !available` at line 29 before checking `wrapper.contains(document.activeElement)` at line 33. With Chromium's observed native blur, an availability transition can lose focus and then hide the wrapper. Capturing the pre-assignment focused state would avoid premature hiding but would not restore the lost activeElement; it does not solve the focus preservation requirement.

The deterministic Element test double has no native disabled setter behavior. Its assertion at `progress-client.cjs:111` simultaneously expects activeElement to remain the button and disabled to be true. That assertion is inconsistent with the observed browser behavior and cannot certify the live focus contract.

If the actual app transition confirms the mechanism, the narrow correction should keep the focused unavailable button in place with native disabled=false and aria-disabled=true. Guard its owning form submission while unavailable, including keyboard Enter, ordinary click and requestSubmit. When focus leaves the wrapper, disable and hide the unavailable action; unfocused unavailable actions can be disabled/hidden immediately. Bind the guard once and read the current availability on blur, so a later available observation cancels deferred hiding. Preserve server state, nonce and authority checks.

Backend safety stays authoritative: Resume checks the current job state and hydrated plan/rights before running; Undo uses current durable operation eligibility and terminal refusal. Client UI blocking adds honest action availability; it must not grant new execution authority or create a substitute worker path.

Required regression if confirmed: preserve original authentic app failure; emulate observed native blur in the deterministic DOM fixture; test unavailable focused Resume and Continue Undo; assert same active node, aria-disabled=true, no navigation/request on keyboard/click submit attempts, blur cleanup, ordinary unfocused native disable, availability recovery before blur, and stable repeated observations. An authentic Chromium/Firefox app rerun should verify focus and rejected UI submissions. Replacing the incorrect disabled=true focus assertion with these checks strengthens the actual contract; do not merely delete that assertion.

No code or test edits were made by this lens. Root and browser lane own the reproduction and any authorized correction.
