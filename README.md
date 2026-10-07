# WriteLeash

**A seatbelt for database writes.**

WriteLeash is a research and product project exploring how dangerous data
mutations can be reviewed, bounded and recovered instead of giving automation
effectively unlimited write authority.

The core research thesis is simple:

> **Writes consume authority.**

**WriteLeash for WooCommerce** is the first shipping commercial product built
from these principles.

## Research

Database write permission is usually binary: a credential that may change one
row may also change every row. WriteLeash research asks:

> If an actor has database WRITE permission, how much mutation authority should
> it be allowed to consume?

The research explores making dangerous database mutation authority finite,
measurable, reviewable, bounded, and resistant to accidental or automated blast
radius. Motivation includes dangerous writes caused by automation, repair
scripts, operators, workflow engines, AI agents, and other semi-trusted database
writers.

The strategic principle remains:

> **Invariant > Correctness > Bypass Resistance > Features.**

This track covers mutation budgets, bounded mutation authority, PostgreSQL/native
experiments, database safety research, state-transition and numeric-delta
research, and advanced Guard mechanisms.

**Status: RESEARCH — NOT A PRODUCTION-READY DATABASE SECURITY CONTROL AND NOT A
RELEASED POSTGRESQL SECURITY PRODUCT.** The PostgreSQL/native work remains
research: its evidence is limited to demonstrated and tested environments. See
the [support matrix](docs/support-matrix.md) for the exact tested envelope and
open limitations.

Research locations:

- [docs/spec.md](docs/spec.md) — intended PostgreSQL research semantics
- [docs/product.md](docs/product.md) — the PostgreSQL research thesis
- [docs/postgresql-research.md](docs/postgresql-research.md) — research overview and local demos
- [docs/support-matrix.md](docs/support-matrix.md) — tested envelope, limitations and status
- [docs/mutation-budget.md](docs/mutation-budget.md) — mutation-budget design notes
- [experiments/](experiments/) — experiment record, including the native transaction-state work
- [sql/](sql/) — research SQL
- [./writeleash](writeleash) — local research CLI (`doctor`, `demo`, `protect-update`)

## Product #1 — WriteLeash for WooCommerce

**Bulk price changes without blindly overwriting newer edits.**

WriteLeash for WooCommerce is the first commercial product derived from the
WriteLeash research program. It is a merchant product for store owners, Shop
Managers, catalog operators and agencies/freelancers:

WriteLeash helps store owners, Shop Managers, catalog operators and agencies
update Regular Price or Sale Price across simple products and variable-product
variations. Select by product name, SKU or category, then Preview the exact
before/after prices for up to **1,000 products in one job**.

Apply the Preview you reviewed. Work runs in the background; if a relevant
price or checked product setting changed after Preview, WriteLeash preserves
the newer edit and marks that item as a conflict. Items with invalid prices or
unsupported settings show a reason and are skipped while clean work continues
where safe. Resume interrupted work, review results in History, and Undo
eligible changes when the current product state still allows restoration.

The product applies principles shared with the research — reviewed intent
before mutation, a bounded target population, re-checking before mutation,
preserving newer state, conflict instead of blind overwrite, and
recoverability — but it does **not** implement the PostgreSQL/native
mutation-budget mechanism. It is not a database security control.

### Install and use

1. Install and activate WooCommerce within the [supported versions](wordpress/writeleash/readme.txt).
2. Install and activate the WriteLeash plugin ZIP.
3. Open **Products → Bulk Prices**.
4. Select products, choose Regular Price or Sale Price, and Preview the change.
5. Review exact prices and warnings, then approve and Apply. Use Resume if work
   is interrupted; review History and eligible Undo afterward.

For installation requirements, supported price operations and limitations, see
[the plugin listing](wordpress/writeleash/readme.txt). This repository is
preparing the public plugin submission; it does not announce a WordPress.org
listing or a published download. Plugin source is in
[`wordpress/writeleash/`](wordpress/writeleash/).

WriteLeash changes prices, not stock, orders or sale dates. Selecting a variable
parent targets its variations; you can also select individual variations.
Undo is conditional: a later relevant edit is preserved rather than overwritten.
Multisite is unsupported. Managed and shared hosting have not been tested.

### See the workflow

These static screenshots show real product screens with sample products.

**Preview exact before/after prices before approving.**

![Preview showing exact before and after Sale Price changes, including variations and skipped products](wordpress/assets/screenshot-2.png)

**Preserve a newer edit and see why that item was skipped.**

![A conflict shows the expected, current and planned regular price; the newer current price is preserved](wordpress/assets/screenshot-5.png)

**Review History and which changes are eligible for Undo.**

![History lists a completed job with a conflict and shows Undo eligibility](wordpress/assets/screenshot-6.png)

These examples show Regular Price and Sale Price changes. Sale Price changes and variations
are supported as described in [the plugin listing](wordpress/writeleash/readme.txt).

## Contributing and security

For source changes and existing checks, read [CONTRIBUTING.md](CONTRIBUTING.md).
Report suspected vulnerabilities privately as described in
[SECURITY.md](SECURITY.md). Use [GitHub issues](https://github.com/MrDarkRoot/WriteLeash/issues)
for non-sensitive questions and bug reports, without customer data or secrets.

The WordPress plugin is **GPL-2.0-or-later**; see its
[license](wordpress/writeleash/LICENSE). The rest of the repository has no
selected repository-wide open-source license; see
[repository license status](LICENSE-TODO.md).
