# SEO content for issue #323

Revised 10 November 2026. The five guides describe WordPress.org WriteLeash **0.3.0**. They are not the plugin listing, and they are not product docs under `docs/` outside this directory.

## What is here

| File | Role |
| --- | --- |
| [keyword-evidence.md](keyword-evidence.md) | Queries, sources, dates. Volume is not measured |
| [claims-audit.md](claims-audit.md) | What the articles may say about WriteLeash and WooCommerce |
| [measurement.md](measurement.md) | Attribution plan. Missing meters stay `NOT_AVAILABLE` |
| [publication-manifest.json](publication-manifest.json) | Slugs, metadata, live-URL status |
| [articles/](articles/) | Five articles. Each one stands alone |
| [build_site.py](build_site.py) | Stdlib generator for the static site |
| [site/](site/) | Generated HTML, CSS, sitemap, and robots. Do not edit by hand |

Regenerate with `python3 docs/marketing/323-seo-content/build_site.py` from the repository root. The script checks unique titles and descriptions, one visible CTA, canonicals, Article JSON-LD, and relative links.

## Claim boundary

The installable plugin is WordPress.org WriteLeash 0.3.0, listing opened 10 November 2026. Preview, conflict preservation, History, and eligible price Undo are in that download. Ordinary price jobs do not change stock. Stock Undo is not supported. Opt-in grants for bounded price and stock jobs stay off until an operator issues one. A restricted WordPress user is denied on documented monitored WooCommerce write paths outside a matching Execution Frame.

That is not a firewall, not a sandbox, and not a universal agent-safe guarantee. Direct SQL, hostile plugins, and unmonitored writes are not covered. There is no guarantee when the grant store is unavailable. Undo does not reverse orders, emails, webhooks, or other plugins.

`wordpress/tests/release/claim-matrix-audit.php` reads `readme.txt`, `CLAIM-MATRIX.md`, and the distribution manifest. It does not scan this directory. It was not weakened.

## Publication

Live host, checked HTTP 200 on 10 November 2026: `https://mrdarkroot.github.io/WriteLeash/`.

GitHub Pages is enabled as a legacy build from branch `docs/323-seo-pages` at path `/`. That branch contains only the generated site (commit `3445680abd944e5260e5d3ade7cd5bceb29147cf`). It does not contain the repository `docs/` product documentation.

No workflow file was added. An unmapped file under `.github/workflows/` fails `.github/ci/ownership.py` (`Unmapped test harness`), and `path-ownership.json` is outside this change. Publishing the default branch `/` or `/docs` folder would publish product docs, which this set does not do.

Live URL status is in [publication-manifest.json](publication-manifest.json).
