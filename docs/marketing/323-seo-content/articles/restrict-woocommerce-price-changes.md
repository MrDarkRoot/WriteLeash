---
title: "How to stop staff or an AI agent from changing WooCommerce prices"
slug: restrict-woocommerce-price-changes
meta_description: "WooCommerce has no separate edit-price capability. Remove product-edit access, write-capable API keys, and application passwords you do not trust."
h1: "How to stop staff or an AI agent from changing WooCommerce prices"
intent: "Reduce who can write product prices, using WordPress roles, REST keys, and application passwords, without pretending a hidden field is a lock."
failure_family: "Staff or AI-agent price changes"
affiliation: "WriteLeash is our product. This guide is not an independent review."
canonical_status: UNPUBLISHED
date_revised: 2026-10-27
---

# How to stop staff or an AI agent from changing WooCommerce prices

**WriteLeash makes this guide.** We publish the [WriteLeash](https://wordpress.org/plugins/writeleash/) plugin. The controls below are WordPress and WooCommerce. You can apply them without WriteLeash. The plugin section says, plainly, that the version on WordPress.org today does not lock staff or agents out of price fields.

WooCommerce does not ship a permission called "edit prices." The [roles and capabilities guide](https://woocommerce.com/document/roles-capabilities/) says a Shop Manager can manage products: create, edit, delete, and publish products, including categories and tags. A person who can edit a product can change its regular price and its sale price, in the editor, in Quick Edit, in bulk edit, and in any tool that acts as that user.

An AI agent is the same problem when it holds that user's password, an application password, a WooCommerce REST key with write access, or a plugin that writes products on the agent's behalf.

## What core actually gives you

**Users > All Users** shows the role. Administrators and Shop Managers are the usual price writers. Customers are not.

The roles guide does not offer a warehouse role that can edit stock and cannot edit prices. It points at third-party role plugins and says WooCommerce does not endorse any specific one. The same page says WooCommerce support does not cover third-party plugins. Hiding the price box with CSS, or unchecking a checkbox that only hides a panel, is not a test. Test the path you are worried about:

- Quick Edit on **Products > All Products**
- Bulk actions > Edit, and the variation **Bulk actions** menu inside a variable product
- **Products > Import** with **Update existing products**
- A REST client using that user's credentials

In May 2023, the author of User Role Editor answered [Restricting Shop Manager Role](https://wordpress.org/support/topic/restricting-shop-manager-role/). The store wanted to block CSV import and export and stop product deletion. The reply says `edit_products` is the capability checked both for adding a product and for editing one, and that WooCommerce may reinitialize the `shop_manager` role on a later activation. The suggested approach there is a copied role, not a one-time edit of the built-in role. That thread is about import and delete, not about a dedicated price capability. It still describes the constraint you are working inside.

A 2020 thread, [Restrict shop admin from editing orders prices](https://wordpress.org/support/topic/restrict-shop-admin-from-editing-orders-prices/), is about order line prices, not catalog prices. WooCommerce support said there was no built-in feature to change the Shop Manager role and pointed at a role plugin. Do not read that reply as a catalog-price procedure.

## Keys and passwords that write prices

**WooCommerce > Settings > Advanced > REST API.** The merchant documentation is [WooCommerce REST API](https://woocommerce.com/document/woocommerce-rest-api/). A key's permission is Read, Write, or Read/Write. A Write or Read/Write key can change products, including prices, for whatever that integration does. Revoke keys you do not recognize. Reporting tools can use Read. The developer overview is [Getting started with the WooCommerce REST API](https://developer.woocommerce.com/docs/getting-started-with-the-woocommerce-rest-api/).

**Application passwords.** WordPress documents them in [Application Passwords](https://developer.wordpress.org/advanced-administration/security/application-passwords/). An application password authenticates as that WordPress user. If the user is a Shop Manager, the client can do what a Shop Manager can do, including product edits. Revoke application passwords from each user's profile when a contractor, a script, or an assistant is done. The integration notes from when the feature shipped are in the [core post from 5 November 2020](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/).

There is no directory stat in this research for how often an agent overwrites a price. What is public, on 27 October 2026, is that several plugins advertise that an assistant can write prices:

- [StoreEdit AI for WooCommerce](https://wordpress.org/plugins/storeedit-ai/) says you can ask an assistant to raise regular prices or put a category on sale, and that a connected assistant acts as its own account.
- [AddSynapse](https://wordpress.org/plugins/addsynapse-store-assistant/) says approved writes can include prices, sales, and stock.
- [AI Agent by SiteGround](https://wordpress.org/plugins/sg-ai-studio/) lists creating and updating WooCommerce products, including price and stock, among the agent's tasks.
- [Wpbingo AI Commander](https://wordpress.org/plugins/wpbingo-ai-commander-for-woocommerce/) lists bulk regular and sale price commands.

Those sentences are their listings, not a security review and not a recommendation. If you connect one, read which WordPress user or key it uses, disconnect it when you are finished, and export prices first. An approval card inside a chat is only as good as the plugin's own enforcement. Keep a [price export](woocommerce-price-history.md) either way.

## A practical cut-down

1. List every Administrator and Shop Manager. Remove the role from anyone who only needs to view orders or pack shipments.
2. If someone must edit products but must not see the importer, the User Role Editor reply removed `import` and `export` on a copied role so those buttons disappear. That reply also said WooCommerce may rebuild `shop_manager` the next time WooCommerce is activated, so retest after that. A hidden button is not a block on REST or on a direct import URL.
3. Do not expect that copied role to block prices while `edit_products` remains. Price is part of editing the product.
4. Revoke unused REST keys and application passwords.
5. Before a person or an assistant runs a bulk price change, export ID, SKU, Regular price, Sale price, and the sale dates. Name the file with the date. Prefer staging for the first run.
6. After the run, open two products and compare them with the export. The [bulk editing guide](https://woocommerce.com/document/bulk-editing-products/) has no undo. The repair is in [how to undo an accidental bulk price edit](undo-woocommerce-bulk-price-edit.md).

Custom code can block `set_regular_price` for a role. That is a development job, and it has to cover CSV, REST, Quick Edit, and bulk edit or it is only a visual block. This guide does not include that code.

## What WriteLeash does here, and what it does not

**The installable plugin does not restrict staff or agents.** Checked 27 October 2026, [WriteLeash 0.2.2](https://wordpress.org/plugins/writeleash/) is a preview, conflict check, history, and eligible undo tool for price changes made through WriteLeash. A Shop Manager can still use the normal product editor, bulk edit, and importer. A REST key with write access can still change prices. The 0.2.2 changelog says the release added disabled-by-default foundations for bounded delegated price authority. The public listing does not document a grant screen or a staff lock. Do not turn those foundations on from this article. They are not the control described below.

If someone uses WriteLeash for a price change, they get a before-and-after Preview, a separate approval, and a conflict when the stored price changed after the Preview. Eligible Undo, 30 days by default on the listing, restores prices WriteLeash applied when the product still matches. It does not undo a price that person changed in WooCommerce itself. Requirements on the listing: WordPress 7.0 or newer, WooCommerce 10.0 through 11.x. Exercised WordPress points are 7.0.1 and 7.1.2. Stock, sale dates, and orders are not changed by those price operations.

**Repository work that is not the directory download.** [Issue #312](https://github.com/MrDarkRoot/WriteLeash/issues/312) and the readme on the default branch after [pull request #330](https://github.com/MrDarkRoot/WriteLeash/pull/330) describe an opt-in restriction for one WordPress user: monitored WooCommerce write paths are denied outside a matching execution frame, and price or stock jobs for that user go through a grant with a frozen scope, a budget, and an expiry. The same text says this is not a firewall, not a sandbox, and not a universal agent-safe guarantee. It does not cover administrators who are not that grant actor, direct SQL, hostile plugins, unmonitored write paths, orders, refunds, coupons, or user-0 system cron. Stock undo is not supported. That behavior is not what the Install button served on 27 October 2026. This guide does not walk through issuing a grant.

Compatibility on that unreleased branch is a pair: WordPress 7.0 up to but not including 8.0 with WooCommerce 10.0 up to but not including 12.0, or WordPress 6.7 up to but not including 7.0 with WooCommerce 9.0 up to but not including 10.0. Do not mix those bands, and do not expect 0.2.2 to implement the legacy pair.

## FAQ

### Can I let a Shop Manager edit stock but not prices?

Not with a core capability. The [roles guide](https://woocommerce.com/document/roles-capabilities/) ties product editing to one manage-products grant. A role editor that only hides the price field still leaves Quick Edit, bulk edit, CSV, and REST to test.

### Does removing the Import button stop price changes?

No. It can stop one screen. Bulk edit, Quick Edit, and a write-capable REST key remain.

### Is WriteLeash the way to lock an AI agent out of prices today?

No. The published 0.2.2 listing does not provide that lock. Use roles, REST key permissions, and application passwords. Treat the grant work in issue #312 as unpublished relative to the directory.

### If I do let someone bulk-edit prices, what limits the damage?

Export first. On the products screen, Update is the write, and there is no documented undo. WriteLeash helps only when the change is made through WriteLeash: preview, a separate approval, conflict retention for a price that moved after preview, and eligible undo of prices it applied.

```json
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Article",
      "headline": "How to stop staff or an AI agent from changing WooCommerce prices",
      "dateModified": "2026-10-27",
      "description": "WooCommerce has no separate edit-price capability. Remove product-edit access, write-capable API keys, and application passwords you do not trust.",
      "author": { "@type": "Organization", "name": "WriteLeash", "url": "https://wordpress.org/plugins/writeleash/" },
      "publisher": { "@type": "Organization", "name": "WriteLeash", "url": "https://wordpress.org/plugins/writeleash/" }
    },
    {
      "@type": "FAQPage",
      "mainEntity": [
        {
          "@type": "Question",
          "name": "Can I let a Shop Manager edit stock but not prices?",
          "acceptedAnswer": { "@type": "Answer", "text": "Not with a core capability. Product editing is one grant. Hiding the price field still leaves Quick Edit, bulk edit, CSV, and REST to test." }
        },
        {
          "@type": "Question",
          "name": "Does removing the Import button stop price changes?",
          "acceptedAnswer": { "@type": "Answer", "text": "No. Bulk edit, Quick Edit, and a write-capable REST key can still change prices." }
        },
        {
          "@type": "Question",
          "name": "Is WriteLeash the way to lock an AI agent out of prices today?",
          "acceptedAnswer": { "@type": "Answer", "text": "No. WriteLeash 0.2.2 on WordPress.org does not provide that lock. Use roles, REST key permissions, and application passwords." }
        },
        {
          "@type": "Question",
          "name": "If I do let someone bulk-edit prices, what limits the damage?",
          "acceptedAnswer": { "@type": "Answer", "text": "Export first. WooCommerce bulk edit has no documented undo. WriteLeash helps when the change is made through WriteLeash, with a preview, separate approval, conflict retention, and eligible undo of prices it applied." }
        }
      ]
    }
  ]
}
```

Structured data template only. Do not emit it until this article has a public canonical URL. Add `datePublished` on that day. The affiliation above is required on the page.

[WriteLeash on WordPress.org](https://wordpress.org/plugins/writeleash/) is useful when the people who may change prices do that work through a preview. It is not a role editor, and it is not the agent lock described in unpublished repository work.
