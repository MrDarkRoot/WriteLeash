# Issue #325 launch pack

Status on 10 November 2026: **public v0.3.0 is on WordPress.org. External posts have not started.**

This directory is the 30-day pack for
[issue #325](https://github.com/MrDarkRoot/WriteLeash/issues/325).
It does not publish a post, send an email, ask for a review, or enable
GitHub Discussions.

## What a merchant can install

The plugin information API on 10 November 2026 returns version **0.3.0**
(`active_installs` 0, `num_ratings` 0, `last_updated` 2026-10-28 2:58pm GMT).
The numbers are in [metrics/2026-11-10-baseline.json](metrics/2026-11-10-baseline.json).

WriteLeash 0.3.0 is a seatbelt for WooCommerce prices and, only after an
opt-in grant, stock. Grants stay off until an operator issues one. It is not
a firewall and not universal. Direct SQL and hostile plugins are not covered.
Stock Undo is not supported. Eligible Undo restores prices WriteLeash applied
when the saved details still match. It does not rewind a products-list bulk
edit or a CSV import that already ran.

The full boundary is in [launch-truth.md](launch-truth.md).

## What is here

| File | Role |
| --- | --- |
| [launch-truth.md](launch-truth.md) | Public 0.3.0 versus the repository recording |
| [targets.md](targets.md) | Short list of real threads and submission pages |
| [channel-matrix.md](channel-matrix.md) | Where a reply is allowed, and where it is blocked |
| [asset-manifest.md](asset-manifest.md) | Which drafts are ready and which stay blocked |
| [measurement.md](measurement.md) | Scorecard. Missing meters stay `NOT_AVAILABLE` |
| [utm.md](utm.md) | Campaign parameters, and the fact WordPress.org will not report them |
| [calendar.md](calendar.md) | Distribution day 2026-10-28, and the days still not posted |
| [published-actions.md](published-actions.md) | What was actually published |
| [metrics/](metrics/) | Baseline JSON and a local refresh script |
| [assets/](assets/) | Drafts. None are cleared to post |

## How to review

1. Read [launch-truth.md](launch-truth.md) before editing a draft.
2. A community draft must still help if the plugin name is removed.
3. Founder approval is required before any external post, email, or review ask.
4. Do not add traffic, audience-size, conversion, or search-volume numbers.
   A figure appears only when the cited page or the metrics file states it.
5. Do not invent testimonials, scarcity, or reviews.
