---
title: "Stop staff and AI tools from changing WooCommerce prices"
slug: restrict-woocommerce-price-changes
meta_description: "WooCommerce has no price-only role. Remove product-edit access and revoke write keys. An opt-in grant is a bounded job, not a lock on every account."
h1: "Stop staff and AI tools from changing WooCommerce prices"
card: "Take away product-edit access, and revoke REST keys and application passwords you do not trust. Core has no price-only role."
intent: "Reduce who can write product prices, using WordPress roles, REST keys, and application passwords, and state the opt-in grant boundary without calling it a lock."
failure_family: "Staff or AI-tool price changes"
affiliation: "WriteLeash publishes this guide. It is not an independent review."
canonical_status: INTENDED
date_revised: 2026-11-10
---

# Stop staff and AI tools from changing WooCommerce prices

Remove product editing from anyone who should not change prices, then revoke REST keys and application passwords you do not recognize. WooCommerce does not ship a permission called "edit prices." A person who can edit a product can change its regular price and its sale price. An AI tool is the same problem when it holds that user's password, an application password, a Write key, or a plugin that saves products as that user.

**WriteLeash publishes this guide.** It is not an independent review. The controls below are WordPress and WooCommerce. You can apply them without WriteLeash. An opt-in grant, described later, is a bounded job for one WordPress user. It is not a lock on the whole store.

## What to change first

1. Open **Users > All Users**. List every Administrator and Shop Manager. Remove the Shop Manager or Administrator role from anyone who only needs to view orders or pack shipments.
2. Open **WooCommerce > Settings > Advanced > REST API**. The [REST API guide](https://woocommerce.com/document/woocommerce-rest-api/), opened on 10 November 2026, says a key is Read, Write, or Read/Write, and that each key is tied to a WordPress user. Revoke Write and Read/Write keys you do not recognize. A reporting tool can use Read. The legacy REST API is no longer in WooCommerce core.
3. On each user's profile, revoke application passwords for a contractor, a script, or an assistant that is finished. WordPress documents them in [Application Passwords](https://developer.wordpress.org/advanced-administration/security/application-passwords/), last modified 14 October 2026 and opened on 10 November 2026. An application password authenticates as that user for API access. It is not a wp-admin password. If the user is a Shop Manager, the client can do what a Shop Manager can do, including product edits.
4. Before a person or a tool runs a bulk price change, export ID, SKU, Regular price, Sale price, and both sale dates. Name the file with the date. [That export is the undo file](undo-woocommerce-bulk-price-edit.md).
5. After the run, open two products and compare them with the export.

Hiding the price box with CSS is not a test. Test the path you are worried about: Quick Edit, products-list bulk edit, the variation **Bulk actions** menu, **Products > Import** with **Update existing products**, and a REST client using that user's credentials.

## Why a copied role still edits prices

The [roles and capabilities guide](https://woocommerce.com/document/roles-capabilities/), opened on 10 November 2026, says a Shop Manager can manage products: create, edit, delete, and publish products, including categories and tags. Customers are not price writers. The page does not list a warehouse role that can edit stock and cannot edit prices. It points at third-party role plugins and says WooCommerce does not endorse any specific one. WooCommerce support does not cover those plugins.

In May 2023, the author of User Role Editor answered [Restricting Shop Manager Role](https://wordpress.org/support/topic/restricting-shop-manager-role/). The store wanted to block CSV import and export and stop product deletion. The reply says `edit_products` is the capability checked both for adding a product and for editing one, and that WooCommerce may reinitialize the `shop_manager` role on a later activation. The suggested approach there is a copied role, not a one-time edit of the built-in role. That thread is about import and delete, not a dedicated price capability. Removing `import` and `export` on a copied role can hide buttons. It does not block REST or a direct import URL, and it does not block prices while `edit_products` remains. Retest after WooCommerce is activated again.

A 2020 thread, [Restrict shop admin from editing orders prices](https://wordpress.org/support/topic/restrict-shop-admin-from-editing-orders-prices/), is about order line prices, not catalog prices. Support said there was no built-in feature to change the Shop Manager role. Do not read that reply as a catalog-price procedure.

There is no measured count in this research for how often an assistant overwrites a price. Search volume for that task was not measured. What is public is that plugins advertise price writes. [AddSynapse](https://wordpress.org/plugins/addsynapse-store-assistant/) was named in this set on 27 October 2026 as a listing that says approved writes can include prices, sales, and stock. Those sentences are the other plugin's listing, not a security review and not a recommendation. If you connect one, read which WordPress user or key it uses, disconnect it when you are finished, and [export prices first](woocommerce-price-history.md). An approval card in a chat is only as good as that plugin's own enforcement.

Custom code can refuse a price change for a role. That is a development job, and it has to cover CSV, REST, Quick Edit, and bulk edit or it is only a visual block. This guide does not include that code.

## What an opt-in grant does, and what it does not

WriteLeash 0.3.0 is the directory version opened on 10 November 2026. The active-installation bucket was fewer than 10. The listing was last modified on 28 October 2026.

Grants are opt-in jobs for bounded price and stock changes. They stay off until an operator issues one. Upgrade from 0.2.2 does not create a grant. No WordPress user is restricted until that grant exists. Ordinary price jobs do not change stock. Stock changes only on a grant, inside its scope, budget, and expiry. Stock Undo is not supported.

A restricted WordPress user is denied on documented monitored WooCommerce write paths outside a matching Execution Frame. Those paths, in the plugin's grant contract, are WooCommerce REST product writes, product save, stock helpers, direct price and stock meta, and trash or delete. People who are not that restricted user keep normal WooCommerce behavior. WriteLeash does not intercept them. A Shop Manager who is not the grant actor can still edit prices in the product editor, in bulk edit, and in the importer.

It is not a firewall, not a sandbox, and not a universal agent-safe guarantee. Direct SQL, hostile plugins, and unmonitored writes are not covered. There is no guarantee when the grant store is unavailable. The boundary does not cover administrators who are not the grant actor, third-party hook side effects, orders, refunds, coupons, tax, options, arbitrary meta, or a system cron running as user 0. The restricted account cannot remove its own restriction. This guide does not walk through issuing a grant.

Human Preview, separate approval, Apply, and eligible price Undo are unchanged. Preview writes nothing. If the stored price WriteLeash is changing, or another checked setting, no longer matches the Preview, the newer value stays and the row is a conflict. Product name, SKU, and category alone do not conflict. Eligible Undo restores prices WriteLeash applied, within 30 days on the listing screenshot, when the saved details and the product still allow it. Undo does not reverse orders, emails, webhooks, or other plugins. WriteLeash creates no backup. It does not undo a price someone already changed in WooCommerce itself.

Requirements on the listing: WooCommerce 10.0.0 or newer and below 12.0.0 on WordPress 7.0 up to but not including 8.0, or WordPress 6.7 below 7.0 with WooCommerce 9.0.0 below 10.0.0. Exercised pairs named there: WordPress 7.0.1 and 7.1.2 on the modern line, WordPress 6.7.2 with WooCommerce 9.0.4, and WordPress 6.8.3 with WooCommerce 9.9.7. Other pairs are refused before a job is created. The "Tested up to" field read 7.1.4 and is not that exercised list. Scope is published core simple products and published variations of published core variable products, base currency, up to 1,000 selected products per new job in the tested configuration. Multisite is unsupported. Real managed or shared hosting has not been tested.

## Related guides

- [Undo a bulk price edit from the pre-change CSV](undo-woocommerce-bulk-price-edit.md)
- [Restore sale prices a CSV import overwrote](restore-sale-prices-overwritten-by-import.md)
- [Keep a dated export before the next price change](woocommerce-price-history.md)

## FAQ

### Can I let a Shop Manager edit stock but not prices?

Not with a core capability. The roles guide ties product editing to one manage-products grant. A role editor that only hides the price field still leaves Quick Edit, bulk edit, CSV, and REST to test.

### Does removing the Import button stop price changes?

No. It can hide one screen. Bulk edit, Quick Edit, and a write-capable REST key remain.

### Does WriteLeash 0.3.0 lock every AI tool out of prices?

No. A grant restricts one WordPress user, and only after an operator issues it. It is not a firewall. Accounts that are not that user can still edit products. Use roles, REST key permissions, and application passwords either way.

### If someone may bulk-edit prices, what limits the damage?

Export first. On the products screen, Update is the write, and the bulk editing guide has no undo. The repair is in the bulk-edit guide linked above. WriteLeash helps only when the change is made through WriteLeash. Stock Undo is not supported. Undo does not reverse orders, emails, webhooks, or other plugins.

WriteLeash 0.3.0 is a preview and an eligible price Undo for changes it applies, plus an opt-in grant that stays off until you issue it. It is not a role editor. The directory listing is [WriteLeash on WordPress.org](https://wordpress.org/plugins/writeleash/). The source is the [WriteLeash repository](https://github.com/MrDarkRoot/WriteLeash).
