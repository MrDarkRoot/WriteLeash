# Merchant UI localization (#210)

The `writeleash` domain covers shipped merchant PHP/JavaScript, accessibility names,
status announcements and recovery guidance. Machine codes, IDs, enums, operations,
prices, plan/journal bytes and CSV column identifiers stay untranslated. Human CSV
task/outcome/field labels are localized at export time; historic stored evidence is
never rewritten. Reason-copy tables are evaluated when displayed, not cached in a plan.

PHP uses literal gettext templates, positional placeholders and plural functions
chosen from numeric facts. Plain translated output is HTML/attribute escaped at its
output boundary. The conflict next-action sentence uses escaped translated text with
two documented, safely constructed anchor placeholders. Product/user names remain
store content. Version ranges are presentation; version comparisons stay unchanged.

Both local scripts depend on `wp-i18n` and register `wp_set_script_translations()` for
`writeleash-free-selection` and `writeleash-free-progress`, using the plugin's
`languages` directory. The public WordPress text-domain registry registers the local PHP lookup path without
loading a catalog early; core loads it just in time. This also supports first
activation after `init` and inactive-plugin uninstall. Installed WordPress language
packs remain supported. SelectWoo's workflow messages use the same domain.
See [the supported registry method](https://developer.wordpress.org/reference/classes/wp_textdomain_registry/set_custom_path/).
No runtime build system, translation service or CDN was added.

## Rebuild the source catalog

Use the official [WP-CLI 2.12.0 release phar](https://github.com/wp-cli/wp-cli/releases/tag/v2.12.0)
(SHA256 `ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c`):

```sh
WP_CLI_PHAR=/absolute/path/wp-cli-2.12.0.phar bash wordpress/release/make-pot.sh
```

The script checks the pinned artifact, extracts only public allowlisted source with
WordPress's official `i18n make-pot`, fixes creation metadata and uses a stable file
comment. Two identical-source runs must compare byte-for-byte. The POT is explicitly
allowlisted; tooling and test locale assets are outside the public distribution.
The earlier inventory remains a discovery aid, not an extractor or coverage verdict.

Translators can produce a PO from `languages/writeleash.pot`, compile PHP translations
with `wp i18n make-mo`, and generate JS JSON with `wp i18n make-json --no-purge`.
Keep reference paths unchanged: each JSON filename is
`writeleash-LOCALE-MD5.json`, where MD5 hashes `includes/free/free-selection.js` or
`includes/free/free-progress.js`. Put local MO/JSON catalogs in the plugin's
`languages` directory, or use WordPress language packs. The handle/domain/path
relationship follows [WordPress script localization](https://developer.wordpress.org/reference/functions/wp_set_script_translations/).

## Focused tests

```sh
php wordpress/tests/release/i18n-inventory-cases.php
php wordpress/tests/admin/i18n-catalog.php
php wordpress/tests/admin/i18n-unit.php
php wordpress/tests/admin/i18n-plan-unit.php
node wordpress/tests/admin/progress-client.cjs
```

`wordpress/tests/admin/run.sh` owns these checks and the existing browser lab. In its
MySQL/default profile, `i18n-browser.cjs` adds one Firefox JS/no-JS journey with a separate
actor. `i18n-fixture-catalog.php` uses WordPress POMO to compile the real POT into an
effective test-only `de_DE` pseudo MO and per-script Jed JSON. It marks messages with
`[Ü]`, preserves placeholders and supplies a long German Preview label. It also
creates an empty disposable core locale catalog so locale switching is available
without downloading language packs. None of these generated test binaries is committed
or shipped. The fixture proves actual MO/Jed loading, not merely a locale setting.

The browser exercises selection, zero/one/many, descendants, a variation, validation
failure, Preview, approval, Apply, read-only polling and a controlled HTTP 403,
History, Undo, pre-locale saved plans, CSV contracts and fresh conflict recovery.
It compares saved source job/item/journal rows before/after browsing and recovery.
The locale journey uses the already installed Firefox engine; #170 continues to run
Chromium and Firefox separately. No JavaScript exception assertion is filtered.
The existing #170 English Chromium/Firefox keyboard checks remain mandatory and
separate; their historical captures stay unchanged, while candidate fingerprints
require the current-head owning CI browser execution. No certification claim follows.

The two small `i18n-baseline-*.json` fixtures were captured from pre-implementation
main `fd7a0dcd588bdfe0537a64f90f5417471950a8c0` by running its existing #178/#179
model harnesses and exporting `$sale_plan->json()` and `$variation_plan->json()`.
They retain genuine old schema/hash/selection/canonical values and are hydrated
byte-for-byte under the translated presentation unit. They are test-only, not shipped.
