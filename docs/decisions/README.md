# Decision Records

This directory is for reviewed decisions that materially change WriteLeash's:

- invariant semantics;
- supported PostgreSQL behavior;
- trust model;
- support envelope;
- enforcement architecture.

`docs/spec.md` defines current intended semantics. A decision record must
identify the affected specification sections, canonical tests from
`docs/test-plan.md`, threat-model assumptions, and limitations. Historical
identifiers must be labeled `legacy experiment ID`. A decision does not
establish implementation support without regression evidence.

Do not add records for routine implementation details, speculative future
architecture, or decisions that have not been made. Project-wide ADRs currently
live in [../decisions.md](../decisions.md); no per-record files exist here yet.
