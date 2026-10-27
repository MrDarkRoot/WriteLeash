# SEO content for issue #323

Status of this directory on 27 October 2026: **publication BLOCKED**.

These files are the reviewable content set for
[issue #323](https://github.com/MrDarkRoot/WriteLeash/issues/323).
They are original merchant guides for accidental WooCommerce price overwrites.
They are not a live site, and they are not the WordPress.org listing.

## What is here

| File | Role |
| --- | --- |
| [keyword-evidence.md](keyword-evidence.md) | Queries, sources, dates, volume, confidence |
| [claims-audit.md](claims-audit.md) | What the articles may say about WriteLeash and WooCommerce |
| [measurement.md](measurement.md) | Attribution plan. Missing meters are `NOT_AVAILABLE` |
| [publication-manifest.json](publication-manifest.json) | Slugs, metadata, live-URL status |
| [articles/](articles/) | Five articles, each able to stand alone |

## Publication

No approved destination exists in this repository.

- GitHub Pages for `MrDarkRoot/WriteLeash` returned HTTP 404 on 27 October 2026.
- The repository has no blog, MDX site, Hugo, Astro, or other content pipeline.
- [docs/launch-v0.2.md](../../launch-v0.2.md) leaves the publish decision with the founder.
- This change does not create an external account, does not commit to WordPress.org SVN, and does not edit plugin runtime code.

A reviewer can read the Markdown locally. Do not emit the JSON-LD templates until a page has a real canonical URL and a real `datePublished`.

## Claim boundary used by every article

The installable plugin is WordPress.org WriteLeash **0.2.2** (listing checked 27 October 2026). It is a price Preview / conflict / History / eligible Undo tool. It does not change stock. It does not document a staff or AI lock.

[Pull request #330](https://github.com/MrDarkRoot/WriteLeash/pull/330) is merged on the GitHub default branch and is not the directory download. That branch certifies compatibility as pairs: WordPress 7.0 up to but not including 8.0 with WooCommerce 10.0 up to but not including 12.0, or WordPress 6.7 up to but not including 7.0 with WooCommerce 9.0 up to but not including 10.0. Cross pairs are refused. The articles say this so nobody installs 0.2.2 on the unreleased legacy pair.

Restricted-agent and grant-scoped stock behavior is [issue #312](https://github.com/MrDarkRoot/WriteLeash/issues/312). It is not advertised here as something a merchant can turn on from the directory plugin.

## Review

1. Read [claims-audit.md](claims-audit.md) before editing an article.
2. Check that a recovery step still works without WriteLeash.
3. Keep the affiliation disclosure in the first screen of each article.
4. Do not add search-volume numbers. The evidence table marks volume `UNKNOWN` unless a dated source states a number.
