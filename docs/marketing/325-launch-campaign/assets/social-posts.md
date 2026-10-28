# Five social drafts

Status: **NOT CLEARED TO POST.** No account authorization was verified. None of
these were published. See [../channel-matrix.md](../channel-matrix.md) for the
rule each one has to satisfy. UTM is omitted on purpose. See [../utm.md](../utm.md).

WriteLeash is named only with an affiliation, and only as public 0.2.2.

## 1. GitHub project note

Channel: this repository. Permission: `PROJECT_DOCS_ONLY`. Discussions are off.
Do not open a GitHub Release to hold this text.

I maintain WriteLeash. The version on WordPress.org today is 0.2.2.

It previews a WooCommerce regular or sale price, asks for a separate approval,
and leaves a newer stored price alone when that price no longer matches the
preview. History and eligible Undo apply to prices WriteLeash itself wrote.

It does not change stock, sale dates, or orders. It does not undo a products-list
bulk edit or a CSV import. It does not lock staff or an assistant in this
public version.

https://wordpress.org/plugins/writeleash/

## 2. WooCommerce Community Slack, `#self-promotion` only

Channel rule, fetched 28 October 2026: promotion of a plugin you built goes in
`#self-promotion`, with "I built this," no affiliate link, and no copy of the
same post in other channels. Participation has to outweigh promotion. Account
access is `UNKNOWN`. Do not send this as a direct message.

I built WriteLeash, a free WordPress.org plugin.

The job I wanted was a price change I could read before it was stored. Preview
shows the before and after. Approval is a second step. If someone edits that
price after the preview, the apply leaves the newer value and records a
conflict.

Limits, so this is not a bigger promise than the zip: no stock edits, no undo
of a native bulk edit or CSV, no staff or AI lock in 0.2.2. WordPress 7.0+ and
WooCommerce 10.0 through 11.x.

https://wordpress.org/plugins/writeleash/

## 3. r/woocommerce comment, only under a matching question

Not a post. Rule extract: promotional posts are never allowed. A comment may
recommend a relevant plugin, including your own, when it answers the question.
Affiliate links are not allowed. Links may be held for moderation.

Use this only when the question is "how do I avoid overwriting a price I just
fixed" or "how do I undo a percentage bulk edit." Delete the plugin paragraph
when the question is an import that already happened. WriteLeash does not undo
that.

The percentage you used to lower the price will not put the old price back.
Ten percent off 25.00 is 22.50. Ten percent on 22.50 is 24.75. The copy that
works is the export you took before the edit: ID, regular price, and sale
price.

If the bad edit has already been saved, restore those columns from that export
and map only those columns. A sale price of 0 is a real price. People can buy
the product for nothing.

I maintain WriteLeash, a free plugin. It can undo an eligible price it applied
itself. It cannot undo a products-list bulk edit or a CSV you already ran.
https://wordpress.org/plugins/writeleash/

## 4. r/Wordpress, founder-written `[PROMO]` post

Hold. The rules payload allows a useful free WordPress.org or GitHub project
and rejects promotion that does not fit that exception. A 16 June 2026
moderator post says the title must start with `[PROMO]`. This AI draft is not
the post. The founder writes a shorter version in their own words, once, or
skips the channel.

Title, if the founder uses the exception:

`[PROMO] WriteLeash 0.2.2, a free preview before a WooCommerce price apply`

Body shape, not a paste:

- One sentence on the separate preview and the conflict.
- Three sentences on what 0.2.2 does not do: stock, native undo, staff or AI lock.
- One plain WordPress.org link. No shortened URL. No request to DM.

## 5. WP Content submission blurb

Blocked until a #323 article has a live URL. The submit page says not to link
a plugin page directly, and that a release post is acceptable. Login is
required. First submissions are reviewed. There is no article URL today, so
this blurb has nowhere to go.

When an article is actually published, submit that article, not this file and
not the plugin URL. Suggested note to the editor:

A merchant guide on why a reverse percentage does not restore a WooCommerce
bulk price edit, and how to put the prices back from the pre-edit export.
The author maintains a free preview plugin and says so in the first screen.
The article's recovery steps do not require installing it.
