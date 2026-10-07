# WriteLeash v0.1.0 submission handoff

READY TO SUBMIT. No unresolved real packaging or compliance blocker remains.
Nothing has been submitted to WordPress.org or pushed to SVN.

## Exact candidate

- Reviewed main: `e4f2859c63ab30217c734485ae32d24b00a5edc8` (merged PR #202).
- Submission runtime source: `6f8ae7de58afd7f33cd5889738f7b79cdc35b0e6`.
- Public source correction: normalize the product discovery AJAX nonce with
  `wp_unslash()` then `sanitize_text_field()` before `wp_verify_nonce()`.
- ZIP: `/home/vanta/Projects/WriteLeash-submission-194/candidate/writeleash-0.1.0.zip`.
- Size: **1,878,448 bytes**, below the 10 MB submission limit.
- SHA256: `b8bd5f406667f6f686348ea67453ec25b90e09607cd9a13b542e6054c9f8a3e8`.
- Files: **42**, sorted, unique, all under `writeleash/`, exactly matching the
  unchanged canonical public manifest. Includes `includes/free/admin-logo.png`
  once; excludes research, tests, credentials, logs and directory artwork.

Run the corrected builder from this PR against a **separate clean checkout**
of the submission runtime source. The builder is outside the public payload;
its source pin intentionally identifies the runtime commit, not its own commit.
It continues to reject another SHA, wrong HEAD, dirty/ignored/untracked inputs,
metadata drift and archive inventory/byte drift.

```sh
git clone https://github.com/MrDarkRoot/WriteLeash.git /tmp/writeleash-194-source
git -C /tmp/writeleash-194-source checkout --detach 6f8ae7de58afd7f33cd5889738f7b79cdc35b0e6
python3 wordpress/release/build-wordpress-org.py \
  --source /tmp/writeleash-194-source \
  --sha 6f8ae7de58afd7f33cd5889738f7b79cdc35b0e6 \
  --output /tmp/writeleash-194-candidate
```

[artifact-194-evidence.json](artifact-194-evidence.json) contains every packaged
file's size and SHA256. [submission-194-results.json](submission-194-results.json)
records the final checks and every Plugin Check finding without filtering.

## Verification

- Initial merchant page review completed before packaging edits. Regular and
  Sale Price, simple products/variations, name/SKU/category selection, exact
  Preview, the tested 1,000-product job boundary, background jobs, Resume,
  relevant newer-edit protection, History and eligible Undo remain coherent.
  No product copy, UI or feature was changed.
- Canonical builder and its package, inventory, source and readme gates: PASS.
- Normal wp-admin Plugins → Add Plugin → Upload Plugin → Install → Activate:
  PASS on WordPress 7.1.2, WooCommerce 11.1.2, PHP 8.2.34 and MySQL 8.0.44.
- Products → Bulk Prices: PASS; approved logo loads and renders at 64×64 px.
- Installed tree equals all 42 exact ZIP files and pinned Git blobs: PASS.
- Current official Plugin Check 2.1.0, all stable checks with runtime bootstrap,
  no check/category/code exclusions: eight findings reviewed below.
- [Official readme validator](https://wordpress.org/plugins/developers/readme-validator/):
  PASS; the returned readme was byte-verified against the ZIP. No errors or
  warnings. INFORMATIONAL: no Upgrade Notice section and no donation link.
- Metadata: WriteLeash, version/stable tag 0.1.0, Duy Tran, contributor
  `duyytrann`, WooCommerce dependency, WordPress 7.0 minimum, PHP 7.4 minimum,
  `writeleash` text domain, GPL v2 or later, included verbatim GPLv2 LICENSE.
- Compliance scan: PASS. Human-readable source; no trial, license-key gate,
  paywall, unsolicited tracking, remote executable code/updater, forced public
  credit, dashboard advertising, review nag, signup or referral requests. The
  1,000-product boundary is the declared tested capacity, with no paid unlock
  or cumulative usage quota. JS/CSS/logo are local; existing WordPress/Woo
  libraries are enqueued rather than bundled.
- Nonce regression: fails before the correction; passes afterward and on the
  final installed candidate, **146 assertions** with real WordPress functions,
  invalid/nonstring input, method, capability and cross-actor controls, and
  unchanged product/job data. HTTP: valid and normalized nonces succeed;
  invalid nonce returns 403. No pricing-engine behavior was changed.
- Artifact tests: **10 PASS**, including independent byte-identical builds,
  reviewed checksum/inventory, dirty/wrong source and archive/tree corruption
  controls. The test now binds current evidence; historical #124 evidence and
  its four-nonce helper remain frozen.
- `.github/ci/pr-fast.sh`: PASS. No unrelated full release matrix was invoked.
- GitNexus: pre-edit impacts performed. UNKNOWN source/test references were
  confirmed by text search. The complete change analysis covers the expected
  builder/audit and discovery flows; aggregate HIGH risk was surfaced and
  accounted for. No partial/truncated change result was accepted.

The first disposable-site upload failed because CLI setup had created a
root-owned uploads directory. After correcting that site's ownership, the
unchanged archive installed normally. The initial main-source archive was
rejected when manual review found the raw discovery nonce; only the corrected
checksum above is the submission candidate. The historical nonce source helper
was also inspected: its fixed four-call assumption no longer describes the
current Admin; the real current nonce regression supplies the relevant proof.
Full local outputs and screenshots are in the adjacent submission directory's
`evidence/` folder and are excluded from the plugin ZIP.

## Every Plugin Check finding

All eight are in `includes/free/class-price-apply-journal.php` and classified
**REVIEWED FALSE POSITIVE / justified low-level implementation**.

| Line:column | Type | Code | Review |
| --- | --- | --- | --- |
| 121:25 | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery | Required live journal-table introspection; table name is `%s` bound. |
| 121:25 | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching | Schema introspection must use current DB structure, not object cache. |
| 123:24 | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery | Required SHOW COLUMNS; validated table identifier is `%i` bound. |
| 123:24 | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching | Column existence must reflect the live schema during upgrade. |
| 127:26 | ERROR | PluginCheck.Security.DirectDB.UnescapedDBParameter | `$changes` contains only three hardcoded DDL fragments at lines 124–126. No request/DB value enters those fragments; the table identifier is validated and `%i` bound. |
| 127:28 | WARNING | WordPress.DB.DirectDatabaseQuery.DirectQuery | Required ALTER of the plugin-owned journal during upgrade. |
| 127:28 | WARNING | WordPress.DB.DirectDatabaseQuery.NoCaching | DDL must execute, not be cached. |
| 127:58 | WARNING | WordPress.DB.DirectDatabaseQuery.SchemaChange | Required owned-table schema migration. |

These match the existing exact-line journal exceptions in
`wordpress/tests/release/plugin-check-results.php`. No new suppression was
added. Plugin Check exit code was 0; findings were reviewed independently of
that exit code. The real manual nonce issue was repaired rather than waived.
Review follows the current [Plugin Check guidance](https://wordpress.org/plugins/plugin-check/),
[common review issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/)
and [directory guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).

## Additional Information

WriteLeash is a WooCommerce bulk price editor. Merchants Preview exact
before-and-after price changes before Apply. It supports Regular Price and
Sale Price for simple products and variations. If a relevant price or checked
setting changed after Preview, WriteLeash preserves the newer edit instead of
blindly overwriting it. Background jobs can be resumed, and History and
eligible Undo are included.

Stop. The next action belongs to the founder: verify private WordPress.org
account/credentials and upload the exact candidate ZIP.
