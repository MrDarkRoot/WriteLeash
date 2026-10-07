# WriteLeash for WooCommerce

**Bulk price changes without blindly overwriting newer edits.**

WriteLeash for WooCommerce is the first commercial product from the broader
WriteLeash database-safety research project.

WriteLeash helps WooCommerce store owners, Shop Managers, catalog operators and
agencies change Regular Price or Sale Price across simple products and
variations. Select the products you want, Preview the exact before/after prices,
then Apply the plan you reviewed. If a relevant price or checked product setting
changes before WriteLeash reaches it, the newer edit is preserved as a conflict
instead of being overwritten.

![A conflict shows expected $100, current $120 and planned $80; the newer $120 price is preserved and the item is marked Not changed](../assets/screenshot-5.png)

**A newer edit wins.** The planned change here was $100 → $80, but the price had
already been changed to $120. WriteLeash leaves the $120 value alone and explains
why that product was not changed.

![Sale Price Preview showing before/after prices, variation names and a skipped product](../assets/screenshot-2.png)

**Review exactly what will change.** Preview lists each product and variation
with its current price, planned price and the size of the change, so you approve
the plan you actually saw.

![History listing completed price-change jobs and Undo availability](../assets/screenshot-6.png)

**Review results and recover.** History keeps each job, its outcome and which
changes are still eligible for Undo, so a bulk update is not a one-way door.

## Why merchants use it

- **Review before you apply** — Preview shows the exact before/after price for every selected product and variation.
- **Regular Price and Sale Price** — set a price, add or subtract an amount, or increase or decrease by a percentage.
- **Simple products and variations** — select a variable product to include its variations, or choose individual variations.
- **Select by name, SKU or category** — build the list the way your catalog is organized.
- **Up to 1,000 products per job** — in the tested configuration.
- **Runs in the background** — close the browser and come back; progress is saved.
- **Resume** — continue an interrupted job without starting over or repeating completed work.
- **History** — see what changed, what was skipped and what needs attention.
- **Eligible Undo** — restore prices WriteLeash changed while the current state still allows it; newer edits are preserved as conflicts.
- **Explained skips** — invalid or unsupported items show a reason and are skipped while clean work continues where safe.
- **Newer edits preserved** — if a relevant price or setting changed after Preview, WriteLeash shows a conflict rather than overwriting it.

## Select → Preview → Apply

1. **Select** products by name, SKU or category and choose Regular Price or Sale Price.
2. **Preview** the exact before/after values, warnings and skipped items.
3. **Apply** the reviewed plan, then follow progress, History or eligible Undo.

## Install and use

1. Install and activate a supported WooCommerce version.
2. Install and activate WriteLeash.
3. Open **Products → Bulk Prices**.

Requirements, supported versions and the full capability details are in
[readme.txt](readme.txt), the WordPress.org listing source. No SSH access,
custom database account or manual SQL setup is required.

- Plugin entry point: [`writeleash.php`](writeleash.php)
- Supported scope and limitations: [readme.txt](readme.txt)
- License: [GPL-2.0-or-later](LICENSE)

The listing is being prepared for submission; this repository does not announce
a published download.

## Repository research

The advanced WordPress Guard/Doctor/Redirection work is a separate research
substrate in this repository. It is not part of the WooCommerce plugin or its
package. The full technical narrative and contract links live in
[RESEARCH.md](RESEARCH.md).

Repository contribution and security policy:
[CONTRIBUTING.md](../../CONTRIBUTING.md) and [SECURITY.md](../../SECURITY.md).

## About the broader WriteLeash research

WriteLeash Research is the parent database-safety research project. Its core
thesis is **Writes consume authority.** WriteLeash for WooCommerce is its first
commercial product.

The PostgreSQL/native mutation-budget work is a separate research track. This
plugin applies related design principles — reviewed intent, bounded targets,
re-checking before mutation, preserving newer state, conflict instead of blind
overwrite, and recoverability — but it does **not** implement the
PostgreSQL/native mutation-budget mechanism and is not a database security
control.

- [Root project README](../../README.md)
- [PostgreSQL research overview](../../docs/postgresql-research.md)
- [Research support matrix](../../docs/support-matrix.md)
